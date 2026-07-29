<?php
/**
 * Yazan AI — customer concierge chat (Phase 2).
 *
 * The public-facing sales assistant. It is deliberately RETRIEVAL-GROUNDED: the model never invents
 * products, prices, or stock. Instead it proposes search filters, PHP runs a real WooCommerce query,
 * and the model then writes a recommendation using only the products it was handed. Flow per turn:
 *
 *   1. Decide — one JSON call: either reply/ask a question, or emit search filters.
 *   2. Search — if searching, run a real product query (stone/color/price), in-stock only.
 *   3. Ground — a second call writes the recommendation from the actual products found.
 *
 * Bilingual by construction (the model answers in the shopper's language). Cost is bounded to at most
 * two provider calls per turn.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Grounded concierge chat.
 */
class Yazan_AI_Chat {

	/** Max products returned to the model / shown to the shopper. */
	const MAX_PRODUCTS = 6;

	/** Max prior turns kept for context. */
	const MAX_HISTORY = 8;

	/**
	 * Max characters accepted from a single shopper message.
	 *
	 * Matches the per-turn cap already applied to history in clean_history().
	 * /ai/chat is the only unauthenticated route that spends money, so an
	 * unbounded message is both a token-burn lever (the per-IP throttle allows
	 * 20 requests per 10 minutes — ample room to be expensive) and the widest
	 * surface for prompt injection against the decide step.
	 */
	const MAX_MESSAGE = 1000;

	/** Max characters of assembled customer-context injected into the prompts. */
	const MAX_CONTEXT = 600;

	/**
	 * Answer one shopper message.
	 *
	 * @param array $input message (required), history (array of {role,content}), session_id, customer_id.
	 * @return array{ok:bool,reply:string,products:array,meta:array,error:?array}
	 */
	public static function reply( array $input ) {
		// Same treatment history already gets: strip control characters/markup,
		// then hard-cap the length before it can reach a provider payload.
		$message = trim( sanitize_textarea_field( mb_substr( (string) ( $input['message'] ?? '' ), 0, self::MAX_MESSAGE ) ) );
		if ( '' === $message ) {
			return self::fail( 'empty', __( 'Please type a message.', 'yazan' ) );
		}
		$history = self::clean_history( $input['history'] ?? array() );

		// Personalisation: a compact profile for a signed-in shopper (their OWN taste only). The id is
		// server-derived (get_current_user_id) — never client-supplied. Empty string when logged out.
		$context = self::customer_context( absint( $input['customer_id'] ?? 0 ) );

		// --- 1. Decide ----------------------------------------------------
		$decision = Yazan_AI_Gateway::run(
			array(
				'system'     => self::decide_system() . $context,
				'messages'   => array_merge( $history, array( array( 'role' => 'user', 'content' => $message ) ) ),
				'json'       => true,
				'max_tokens' => 1024,
			),
			array( 'task' => 'chat.decide', 'module' => 'chat', 'capability' => 'text' )
		);

		if ( empty( $decision['ok'] ) ) {
			// Throttled/unavailable before we could extract filters — degrade to a best-effort catalogue
			// search from the raw message so the shopper still sees real pieces instead of a dead "busy".
			return self::degrade( $message, self::search( array( 'keywords' => $message ) ), $decision );
		}
		$plan   = is_array( $decision['json'] ) ? $decision['json'] : array();
		$action = ( 'search' === ( $plan['action'] ?? '' ) ) ? 'search' : 'reply';

		// A plain reply / clarifying question — no retrieval needed.
		if ( 'search' !== $action ) {
			return array(
				'ok'       => true,
				'reply'    => (string) ( $plan['message'] ?? '' ),
				'products' => array(),
				'meta'     => Yazan_AI_Product::meta( $decision ),
				'error'    => null,
			);
		}

		// --- 2. Search ----------------------------------------------------
		$products = self::search( (array) ( $plan['filters'] ?? array() ) );

		// --- 3. Ground ----------------------------------------------------
		$ground = Yazan_AI_Gateway::run(
			array(
				'system'     => self::ground_system() . $context,
				'messages'   => array_merge(
					$history,
					array(
						array( 'role' => 'user', 'content' => $message ),
						array( 'role' => 'user', 'content' => self::products_brief( $products ) ),
					)
				),
				'json'       => true,
				'max_tokens' => 1200,
			),
			array( 'task' => 'chat.ground', 'module' => 'chat', 'capability' => 'text' )
		);

		if ( empty( $ground['ok'] ) ) {
			// The search already ran (no LLM needed) — show those real pieces rather than dead-ending.
			return self::degrade( $message, $products, $ground );
		}
		$result = is_array( $ground['json'] ) ? $ground['json'] : array();

		// Only surface products the model actually recommended (fall back to all found).
		$recommend = array_filter( array_map( 'absint', (array) ( $result['recommend'] ?? array() ) ) );
		$cards     = self::cards( $products, $recommend );

		return array(
			'ok'       => true,
			'reply'    => (string) ( $result['message'] ?? '' ),
			'products' => $cards,
			'meta'     => Yazan_AI_Product::meta( $ground ),
			'error'    => null,
		);
	}

	/* --------------------------------------------------------------------- */
	/* Prompts                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * System prompt for the decision step.
	 *
	 * @return string
	 */
	private static function decide_system() {
		$stones = self::term_names( 'pa_stone' );
		$colors = self::term_names( 'pa_color' );
		$verify = home_url( '/' . Yazan_Core_Verify::PAGE_SLUG . '/' );

		$base = Yazan_AI_Prompts::brand_voice() . "\n\n"
			. "You are the YAZAN Concierge — a premium bilingual jewelry advisor for a luxury house of "
			. "Yemeni agate (aqeeq / عقيق يمني) and sterling-silver rings. Understand and reply naturally in "
			. "the SAME language the shopper uses (Arabic or English). Be elegant, precise, and quietly "
			. "persuasive — never use generic chatbot phrasing, never pressure. Your aim is to build "
			. "confidence and help the shopper discover the right piece.\n\n"
			. "DECIDE what this turn needs:\n"
			. "- If something essential is missing (stone/colour, budget, occasion), ask ONE smart, graceful "
			. "question — never a list, never an interrogation.\n"
			. "- GIFT ADVISOR: if the shopper is buying a gift, gently learn the occasion and budget, then "
			. "SEARCH with filters.gift=true (our gift-ready pieces) within their budget.\n"
			. "- RING SIZE ADVISOR: for sizing help, reply directly with brief, reassuring guidance — measure "
			. "the inner diameter (in mm) of a ring they already wear, or wrap a strip of paper around the "
			. "finger and measure its length (in mm); then we match it to a size. Offer to note their size.\n"
			. "- If you have enough to look, SEARCH the catalogue.\n"
			. "- For POST-SALE or authenticity questions, reply directly: to verify a ring's authenticity, "
			. "invite the customer to enter its serial on the certificate page at " . $verify . " (stone, "
			. "silver, origin and craft are certified there). Answer care, shipping and returns questions "
			. "elegantly from general knowledge.\n\n"
			. "STRICT RULES: Never invent products, prices, stock, or certificates. Never reveal order numbers "
			. "or any other customer's data, and never ask the shopper for passwords or payment details. When a "
			. "SIGNED-IN CUSTOMER block appears below, you MAY warmly reference THAT shopper's own name and past "
			. "taste; otherwise do not claim to see any account or orders. Only ever recommend real, in-stock "
			. "catalogue pieces (the search step guarantees this).\n\n"
			. 'Available stone types (use these EXACT names in filters.stone): ' . ( $stones ? implode( ', ', $stones ) : '(none)' ) . "\n"
			. 'Available colours (use these EXACT names in filters.color): ' . ( $colors ? implode( ', ', $colors ) : '(none)' ) . "\n\n"
			. "Return ONLY JSON:\n"
			. '{ "action": "reply" | "search", '
			. '"message": "your message to the shopper, in their language (empty is fine when action=search)", '
			. '"filters": { "stone": "one of the stone types or empty", "color": "one of the colours or empty", '
			. '"max_price": number or null, "gift": true or false, "keywords": "free-text search or empty" } }'
			. self::owner_overrides();

		return $base;
	}

	/**
	 * System prompt for the grounding step.
	 *
	 * @return string
	 */
	private static function ground_system() {
		return Yazan_AI_Prompts::brand_voice() . "\n\n"
			. "You are the YAZAN Concierge. Below is the shopper's need and the REAL products found in the "
			. "catalogue (ids, names, prices, stones). Write ONE elegant reply, in the shopper's language, "
			. "that naturally moves through: (1) a brief show of understanding of what they want; (2) your "
			. "recommendation — 1 to 3 pieces, RANKED best-fit first by relevance and luxury fit; (3) a "
			. "short, refined explanation of their value grounded in heritage, authenticity and "
			. "craftsmanship; (4) a natural reference to the pieces by name (the interface shows them as "
			. "linked cards, so do not paste URLs); (5) one optional, gentle next question. Weave these "
			. "together as flowing prose — never a numbered list, never generic phrasing.\n\n"
			. "STRICT: recommend ONLY from the products given, using ONLY the details given — never invent "
			. "prices, stock, specs, or pieces. If nothing fits, say so graciously and offer to broaden the "
			. "search.\n\n"
			. "Return ONLY JSON:\n"
			. '{ "message": "your recommendation, in the shopper\'s language", '
			. '"recommend": [product_id, ...]  // best-fit first }'
			. self::owner_overrides();
	}

	/**
	 * Owner-authored prompt guidance (from Provider Settings) appended to the concierge prompts, so the
	 * chat honors the same tuning as the admin content pipelines. `voice` applies everywhere; `chat` is
	 * concierge-specific.
	 *
	 * @return string
	 */
	private static function owner_overrides() {
		$p   = (array) Yazan_AI_Settings::get( 'prompts', array() );
		$out = '';
		if ( ! empty( $p['voice'] ) ) {
			$out .= "\n\nAdditional brand guidance from the store owner (follow it): " . $p['voice'];
		}
		if ( ! empty( $p['chat'] ) ) {
			$out .= "\n\nConcierge instructions from the store owner (follow it): " . $p['chat'];
		}
		return $out;
	}

	/**
	 * Compact brief of the found products for the grounding call.
	 *
	 * @param array $products Product objects.
	 * @return string
	 */
	private static function products_brief( array $products ) {
		if ( empty( $products ) ) {
			return 'PRODUCTS FOUND: none matched the filters.';
		}
		$lines = array( 'PRODUCTS FOUND (recommend only from these, by id):' );
		foreach ( $products as $p ) {
			$stone = wc_get_product_terms( $p->get_id(), 'pa_stone', array( 'fields' => 'names' ) );
			$stone = ( ! is_wp_error( $stone ) && $stone ) ? $stone[0] : '';
			$lines[] = sprintf(
				'- id=%d | %s | %s | %s',
				$p->get_id(),
				wp_strip_all_tags( $p->get_name() ),
				html_entity_decode( wp_strip_all_tags( wc_price( $p->get_price() ) ), ENT_QUOTES ),
				$stone
			);
		}
		return implode( "\n", $lines );
	}

	/**
	 * Compact, privacy-safe personalisation block for a signed-in shopper. Reads ONLY this customer's own
	 * recent completed/processing orders to derive a greeting name + stone/colour taste — never order
	 * numbers, addresses, or any other PII, and never another customer's data. Empty for guests.
	 *
	 * @param int $user_id Server-derived current user id (0 = guest).
	 * @return string Leading double-newline so it appends cleanly to a system prompt.
	 */
	private static function customer_context( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! function_exists( 'wc_get_orders' ) ) {
			return '';
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '';
		}

		$name = trim( (string) $user->first_name );
		if ( '' === $name ) {
			$name = trim( (string) $user->display_name );
		}
		$order_count = (int) wc_get_customer_order_count( $user_id );

		if ( '' === $name && ! $order_count ) {
			return '';
		}

		$stones = array();
		$colors = array();
		if ( $order_count ) {
			$orders = wc_get_orders(
				array(
					'customer_id' => $user_id,
					'status'      => array( 'wc-completed', 'wc-processing' ),
					'limit'       => 10,
					'type'        => 'shop_order',
					'orderby'     => 'date',
					'order'       => 'DESC',
				)
			);
			foreach ( (array) $orders as $order ) {
				if ( ! $order instanceof WC_Order ) {
					continue;
				}
				foreach ( $order->get_items() as $item ) {
					$pid = $item->get_product_id();
					if ( ! $pid ) {
						continue;
					}
					$snames = wc_get_product_terms( $pid, 'pa_stone', array( 'fields' => 'names' ) );
					if ( ! is_wp_error( $snames ) ) {
						foreach ( $snames as $n ) {
							$stones[ $n ] = isset( $stones[ $n ] ) ? $stones[ $n ] + 1 : 1;
						}
					}
					$cnames = wc_get_product_terms( $pid, 'pa_color', array( 'fields' => 'names' ) );
					if ( ! is_wp_error( $cnames ) ) {
						foreach ( $cnames as $n ) {
							$colors[ $n ] = isset( $colors[ $n ] ) ? $colors[ $n ] + 1 : 1;
						}
					}
				}
			}
		}

		// Their saved pieces (wishlist) deepen the taste signal and let the concierge reference favourites.
		$saved_names = array();
		if ( class_exists( 'Yazan_REST_Wishlist' ) ) {
			foreach ( Yazan_REST_Wishlist::ids( $user_id ) as $wid ) {
				$wp = wc_get_product( $wid );
				if ( ! $wp instanceof WC_Product ) {
					continue;
				}
				$saved_names[] = wp_strip_all_tags( $wp->get_name() );
				$snames        = wc_get_product_terms( $wid, 'pa_stone', array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $snames ) ) {
					foreach ( $snames as $n ) {
						$stones[ $n ] = isset( $stones[ $n ] ) ? $stones[ $n ] + 1 : 1;
					}
				}
			}
		}

		arsort( $stones );
		arsort( $colors );

		$lines = array( "\n\nSIGNED-IN CUSTOMER (personalise warmly; refer only to THEIR own history, never invent it, never reveal order numbers or any other customer's data):" );
		if ( '' !== $name ) {
			$lines[] = 'First name: ' . wp_strip_all_tags( $name );
		}
		if ( $order_count ) {
			$lines[] = 'Returning client.';
			if ( $stones ) {
				$lines[] = 'Stones they favour: ' . wp_strip_all_tags( implode( ', ', array_slice( array_keys( $stones ), 0, 3 ) ) ) . '.';
			}
			if ( $colors ) {
				$lines[] = 'Colours they favour: ' . wp_strip_all_tags( implode( ', ', array_slice( array_keys( $colors ), 0, 3 ) ) ) . '.';
			}
		} else {
			$lines[] = 'New client — no prior purchases.';
		}
		if ( $saved_names ) {
			$lines[] = 'Pieces they have saved to their wishlist: ' . wp_strip_all_tags( implode( ', ', array_slice( $saved_names, 0, 4 ) ) ) . '.';
		}

		return mb_substr( implode( "\n", $lines ), 0, self::MAX_CONTEXT );
	}

	/* --------------------------------------------------------------------- */
	/* Retrieval                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Run a real product query from the model's filters, with progressive relaxation.
	 *
	 * The catalogue encodes colour inside the stone name (e.g. "Red Aqeeq"), so few products carry a
	 * separate `pa_color` term — a naive stone-AND-colour query would zero out. So we try the tightest
	 * filter set first and, if nothing matches, drop the most restrictive constraint and retry, the way
	 * a concierge broadens a search. We never fabricate; we just widen until the real catalogue answers.
	 *
	 * @param array $filters stone, color, max_price, keywords.
	 * @return array<int,WC_Product>
	 */
	private static function search( array $filters ) {
		$stone    = self::resolve_term( 'pa_stone', (string) ( $filters['stone'] ?? '' ) );
		$color    = self::resolve_term( 'pa_color', (string) ( $filters['color'] ?? '' ) );
		$price    = isset( $filters['max_price'] ) && is_numeric( $filters['max_price'] ) ? (float) $filters['max_price'] : 0;
		$keywords = sanitize_text_field( (string) ( $filters['keywords'] ?? '' ) );
		$gift     = ! empty( $filters['gift'] );

		// Relaxation ladder: most specific → broad. First non-empty wins. The gift tag is preferred while
		// the shopper is gifting, but the final rung drops it so a search always returns real pieces.
		$ladder = array(
			array( 'stone' => $stone, 'color' => $color, 'price' => $price, 'gift' => $gift ),
			array( 'stone' => $stone, 'color' => 0, 'price' => $price, 'gift' => $gift ), // colour rarely set → drop it
			array( 'stone' => $stone, 'color' => 0, 'price' => 0, 'gift' => $gift ),      // drop the price ceiling
			array( 'stone' => 0, 'color' => $color, 'price' => $price, 'gift' => $gift ), // maybe colour alone
			array( 'stone' => 0, 'color' => 0, 'price' => 0, 'gift' => $gift ),           // gift-tagged / keywords / newest
			array( 'stone' => 0, 'color' => 0, 'price' => 0, 'gift' => false ),           // last resort: any newest in-stock
		);

		$seen = array();
		foreach ( $ladder as $step ) {
			$sig = $step['stone'] . '|' . $step['color'] . '|' . $step['price'] . '|' . ( $step['gift'] ? 'g' : '' );
			if ( isset( $seen[ $sig ] ) ) {
				continue;
			}
			$seen[ $sig ] = true;

			$products = self::run_query( $step['stone'], $step['color'], $step['price'], $keywords, $step['gift'] );
			if ( ! empty( $products ) ) {
				return $products;
			}
		}
		return array();
	}

	/**
	 * One product query. In-stock, published, not hidden.
	 *
	 * @param int    $stone    pa_stone term id (0 = ignore).
	 * @param int    $color    pa_color term id (0 = ignore).
	 * @param float  $price    Max price (0 = ignore).
	 * @param string $keywords Free-text search ('' = ignore).
	 * @return array<int,WC_Product>
	 */
	private static function run_query( $stone, $color, $price, $keywords, $gift = false ) {
		$tax_query = array(
			'relation' => 'AND',
			array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => array( 'exclude-from-catalog', 'outofstock' ), 'operator' => 'NOT IN' ),
		);
		if ( $stone ) {
			$tax_query[] = array( 'taxonomy' => 'pa_stone', 'field' => 'term_id', 'terms' => (int) $stone );
		}
		if ( $color ) {
			$tax_query[] = array( 'taxonomy' => 'pa_color', 'field' => 'term_id', 'terms' => (int) $color );
		}
		if ( $gift ) {
			$tax_query[] = array( 'taxonomy' => 'product_tag', 'field' => 'slug', 'terms' => 'gift' );
		}

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => self::MAX_PRODUCTS,
			'tax_query'      => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			'fields'         => 'ids',
		);
		if ( $price > 0 ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array( 'key' => '_price', 'value' => $price, 'compare' => '<=', 'type' => 'NUMERIC' ),
			);
		}
		if ( '' !== $keywords ) {
			$args['s'] = $keywords;
		}

		$query    = new WP_Query( $args );
		$products = array();
		foreach ( $query->posts as $pid ) {
			$product = wc_get_product( $pid );
			if ( $product instanceof WC_Product && $product->is_in_stock() ) {
				$products[] = $product;
			}
		}
		return $products;
	}

	/**
	 * Build shopper-facing product cards, ordered by the model's recommendation.
	 *
	 * @param array $products  Found products.
	 * @param array $recommend Recommended ids (may be empty).
	 * @return array<int,array>
	 */
	private static function cards( array $products, array $recommend ) {
		$by_id = array();
		foreach ( $products as $p ) {
			$by_id[ $p->get_id() ] = $p;
		}

		$ordered = array();
		foreach ( $recommend as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$ordered[ $id ] = $by_id[ $id ];
			}
		}
		// If the model named none, show what we found.
		if ( empty( $ordered ) ) {
			$ordered = $by_id;
		}

		$cards = array();
		foreach ( $ordered as $p ) {
			$img_id  = $p->get_image_id();
			$cards[] = array(
				'id'    => $p->get_id(),
				'name'  => wp_strip_all_tags( $p->get_name() ),
				'price' => html_entity_decode( wp_strip_all_tags( $p->get_price_html() ), ENT_QUOTES ),
				'url'   => get_permalink( $p->get_id() ),
				'thumb' => $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '',
			);
		}
		return array_slice( $cards, 0, self::MAX_PRODUCTS );
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Resolve a model-supplied label to a term id in a taxonomy (slug, name, then loose).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $value    Label.
	 * @return int Term id or 0.
	 */
	private static function resolve_term( $taxonomy, $value ) {
		$value = trim( $value );
		if ( '' === $value || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}
		$term = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );
		if ( ! $term ) {
			$term = get_term_by( 'name', $value, $taxonomy );
		}
		if ( $term instanceof WP_Term ) {
			return (int) $term->term_id;
		}

		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return 0;
		}

		// Token-aware match: the model may say "Red Yemeni Aqeeq" when the term is "Red Aqeeq". Match
		// when every word of a term appears among the value's words (so "Blue Aqeeq" won't match, but
		// "Red Aqeeq" will). Falls back to a plain substring compare.
		$value_words = self::words( $value );
		$compact     = str_replace( ' ', '', implode( ' ', $value_words ) );

		foreach ( $terms as $t ) {
			$term_words = self::words( $t->name );
			if ( empty( $term_words ) ) {
				continue;
			}
			if ( empty( array_diff( $term_words, $value_words ) ) ) {
				return (int) $t->term_id; // Every word of the term is present in the value.
			}
			$hay = str_replace( ' ', '', implode( ' ', $term_words ) );
			if ( '' !== $compact && ( false !== strpos( $hay, $compact ) || false !== strpos( $compact, $hay ) ) ) {
				return (int) $t->term_id;
			}
		}
		return 0;
	}

	/**
	 * Lowercase word tokens (length ≥ 2) from a label.
	 *
	 * @param string $s Input.
	 * @return array<int,string>
	 */
	private static function words( $s ) {
		$parts = preg_split( '/[^a-z0-9]+/i', strtolower( (string) $s ), -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_filter( (array) $parts, static function ( $w ) {
			return strlen( $w ) >= 2;
		} ) );
	}

	/**
	 * Term names for a taxonomy (for the prompt's allowed lists).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array<int,string>
	 */
	private static function term_names( $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		return is_wp_error( $terms ) ? array() : wp_list_pluck( $terms, 'name' );
	}

	/**
	 * Sanitize and cap the incoming history.
	 *
	 * @param mixed $raw Client-supplied history.
	 * @return array<int,array{role:string,content:string}>
	 */
	private static function clean_history( $raw ) {
		$out = array();
		foreach ( (array) $raw as $turn ) {
			if ( ! is_array( $turn ) ) {
				continue;
			}
			$role    = ( 'assistant' === ( $turn['role'] ?? '' ) ) ? 'assistant' : 'user';
			$content = trim( (string) ( $turn['content'] ?? '' ) );
			if ( '' !== $content ) {
				$out[] = array( 'role' => $role, 'content' => sanitize_textarea_field( mb_substr( $content, 0, 1000 ) ) );
			}
		}
		return array_slice( $out, -self::MAX_HISTORY );
	}

	/**
	 * Failure result.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return array
	 */
	private static function fail( $code, $message ) {
		return array(
			'ok'       => false,
			'reply'    => '',
			'products' => array(),
			'meta'     => array(),
			'error'    => array( 'code' => sanitize_key( $code ), 'message' => (string) $message ),
		);
	}

	/**
	 * Translate a gateway error envelope into a chat failure.
	 *
	 * @param array $envelope Envelope.
	 * @return array
	 */
	private static function fail_from_envelope( array $envelope ) {
		$err = $envelope['error'] ?? array();
		return self::fail( $err['code'] ?? 'error', $err['message'] ?? __( 'The assistant is unavailable right now.', 'yazan' ) );
	}

	/**
	 * Graceful degradation when an LLM step is throttled/unavailable: still surface REAL matching catalogue
	 * products (retrieval needs no provider) with a tasteful, language-matched message — so the concierge
	 * never dead-ends on a bare "busy". Falls back to the honest failure only when nothing can be shown.
	 *
	 * @param string $message  The shopper's raw message (for language detection).
	 * @param array  $products Products already retrieved (may be empty).
	 * @param array  $envelope The failing gateway envelope (used when there's nothing to show).
	 * @return array
	 */
	private static function degrade( $message, array $products, array $envelope ) {
		if ( empty( $products ) ) {
			// Last resort: newest in-stock pieces (no filters, no LLM). A raw-sentence keyword search can
			// over-constrain (English titles vs an Arabic query), so fall back to simply showing the range.
			$products = self::search( array() );
		}
		if ( empty( $products ) ) {
			return self::fail_from_envelope( $envelope ); // truly nothing (empty catalogue) → honest message
		}

		$is_ar = (bool) preg_match( '/\p{Arabic}/u', (string) $message );
		$text  = $is_ar
			? 'كونسيرجنا يلتقط أنفاسه لحظةً — إليك قطعًا مختارة من مجموعتنا قد تنال إعجابك. اسألني بعد قليل لمشورةٍ مفصّلة.'
			: 'Our concierge is taking a brief pause — meanwhile, here are a few pieces from our collection you may love. Ask again in a moment for tailored guidance.';

		return array(
			'ok'       => true,
			'reply'    => $text,
			'products' => self::cards( $products, array() ), // real, in-stock cards — no fabrication
			'meta'     => array( 'degraded' => true ),
			'error'    => null,
		);
	}
}
