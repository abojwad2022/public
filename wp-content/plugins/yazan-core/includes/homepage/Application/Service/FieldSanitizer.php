<?php
/**
 * Turns a raw request payload into a value that is safe to store, using only what the component
 * schema declares.
 *
 * The rule that carries the security weight: **a key the schema does not declare is dropped.**
 * There is no passthrough, no "extra" bucket, no merge with the incoming array. That is why an
 * unknown field can never reach the database, whatever the client sends.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Service;

use Yazan\Homepage\Domain\Component\ComponentSchema;
use Yazan\Homepage\Domain\Component\FieldDefinition;
use Yazan\Homepage\Domain\Component\FieldType;
use Yazan\Homepage\Domain\Port\MediaPort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema-driven sanitisation.
 */
final class FieldSanitizer {

	/** Link protocols a homepage field may point at. */
	const ALLOWED_PROTOCOLS = array( 'http', 'https', 'mailto', 'tel' );

	/** @var MediaPort */
	private $media;

	/**
	 * @param MediaPort $media Media port.
	 */
	public function __construct( MediaPort $media ) {
		$this->media = $media;
	}

	/**
	 * Sanitise a payload against a schema.
	 *
	 * Absent keys fall back to the field default, so the result is always a complete, valid
	 * payload — partial updates cannot leave a section half-defined.
	 *
	 * @param ComponentSchema $schema  Schema.
	 * @param array           $values  Raw values.
	 * @param array           $current Current stored values, used for keys the request omits.
	 * @return array
	 */
	public function sanitize( ComponentSchema $schema, array $values, array $current = array() ) {
		$out = array();

		foreach ( $schema->fields() as $key => $field ) {
			if ( array_key_exists( $key, $values ) ) {
				$out[ $key ] = $this->sanitize_field( $field, $values[ $key ] );
			} elseif ( array_key_exists( $key, $current ) ) {
				$out[ $key ] = $current[ $key ];
			} else {
				$out[ $key ] = $field->default_value();
			}
		}

		return $out;
	}

	/**
	 * Sanitise one field, honouring its responsive wrapper.
	 *
	 * @param FieldDefinition $field Field.
	 * @param mixed           $value Raw value.
	 * @return mixed
	 */
	private function sanitize_field( FieldDefinition $field, $value ) {
		if ( $field->is_responsive() ) {
			$value = is_array( $value ) ? $value : array( 'desktop' => $value );
			$out   = array();

			foreach ( array( 'desktop', 'tablet', 'mobile' ) as $device ) {
				// A null device value means "inherit from the wider breakpoint" and is preserved
				// as null — replacing it with a default would silently break inheritance.
				$out[ $device ] = array_key_exists( $device, $value ) && null !== $value[ $device ]
					? $this->sanitize_value( $field, $value[ $device ] )
					: null;
			}

			if ( null === $out['desktop'] ) {
				$out['desktop'] = $field->default_value();
			}

			return $out;
		}

		return $this->sanitize_value( $field, $value );
	}

	/**
	 * Sanitise a single scalar/structured value by field type.
	 *
	 * @param FieldDefinition $field Field.
	 * @param mixed           $value Raw value.
	 * @return mixed
	 */
	private function sanitize_value( FieldDefinition $field, $value ) {
		switch ( $field->type() ) {
			case FieldType::TEXT:
				$text = sanitize_text_field( (string) $value );
				$max  = (int) $field->constraint( 'max_length', 0 );
				return $max > 0 ? mb_substr( $text, 0, $max ) : $text;

			case FieldType::TEXTAREA:
				return sanitize_textarea_field( (string) $value );

			case FieldType::RICHTEXT:
				return 'inline' === $field->constraint( 'policy', 'inline' )
					? $this->kses_inline( (string) $value )
					: wp_kses_post( (string) $value );

			case FieldType::NUMBER:
			case FieldType::RANGE:
				$number = is_numeric( $value ) ? $value + 0 : 0;
				$min    = $field->constraint( 'min', null );
				$max    = $field->constraint( 'max', null );
				if ( null !== $min ) {
					$number = max( $min + 0, $number );
				}
				if ( null !== $max ) {
					$number = min( $max + 0, $number );
				}
				return $number;

			case FieldType::TOGGLE:
				return (bool) $value;

			case FieldType::DATETIME:
				// Stored as a UTC timestamp whatever the client sent — a string date is parsed
				// here rather than in three different templates later.
				if ( is_numeric( $value ) ) {
					return max( 0, (int) $value );
				}

				$value = trim( (string) $value );

				// An empty date means UNSET, and 0 is how unset is stored. Without this line the
				// string ' UTC' reached strtotime(), which reads a bare timezone as "now" — so a
				// hero slide saved with no schedule got from == to == the instant it was saved and
				// was then correctly judged out of its own window and removed from the page. An
				// empty field must never become a moment in time.
				if ( '' === $value ) {
					return 0;
				}

				$parsed = strtotime( $value . ' UTC' );
				return $parsed ? (int) $parsed : 0;

			case FieldType::SELECT:
				$choices = (array) $field->constraint( 'choices', array() );
				$value   = sanitize_key( (string) $value );
				return in_array( $value, $choices, true ) ? $value : (string) $field->default_value();

			case FieldType::COLOR:
				return $this->sanitize_color( (string) $value );

			case FieldType::ICON:
				$icons = (array) $field->constraint( 'choices', array() );
				$value = sanitize_key( (string) $value );
				return in_array( $value, $icons, true ) ? $value : '';

			case FieldType::MEDIA:
				return $this->sanitize_attachment( $value, $field->constraint( 'mime', array() ) );

			case FieldType::VIDEO:
				$mimes = (array) $field->constraint( 'mime', array( 'video/mp4', 'video/webm' ) );
				return $this->sanitize_attachment( $value, $mimes );

			case FieldType::GALLERY:
				return $this->sanitize_attachment_list( $value, $field );

			case FieldType::URL:
				// A bare URL string, for the theme keys that store one (story_cta_url, cstory_*_url).
				return esc_url_raw( (string) $value, self::ALLOWED_PROTOCOLS );

			case FieldType::LINK:
				return $this->sanitize_link( (array) $value );

			case FieldType::BUTTON:
				return $this->sanitize_button( (array) $value );

			case FieldType::PRODUCT_IDS:
				return $this->sanitize_id_list( $value, (int) $field->constraint( 'max_items', 48 ) );

			case FieldType::TERM_IDS:
				return $this->sanitize_id_list( $value, (int) $field->constraint( 'max_items', 24 ) );

			case FieldType::PRODUCT_QUERY:
				return $this->sanitize_product_query( (array) $value, $field );

			case FieldType::GROUP:
				$children = $field->children();
				return $children ? $this->sanitize( $children, (array) $value ) : array();

			case FieldType::REPEATER:
				return $this->sanitize_repeater( $field, $value );

			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Inline rich text: emphasis and links only.
	 *
	 * A separate, much smaller allow-list than wp_kses_post because a headline has no business
	 * containing an iframe, and "post content rules" is far more than a subtitle needs.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	private function kses_inline( $html ) {
		return wp_kses(
			$html,
			array(
				'a'      => array(
					'href'   => array(),
					'title'  => array(),
					'rel'    => array(),
					'target' => array(),
				),
				'strong' => array(),
				'b'      => array(),
				'em'     => array(),
				'i'      => array(),
				'br'     => array(),
				'span'   => array( 'class' => array() ),
			),
			self::ALLOWED_PROTOCOLS
		);
	}

	/**
	 * A theme token name or a hex colour — nothing else.
	 *
	 * Tokens are preferred: a section storing `--yz-ink` keeps following the Obsidian/Maroon
	 * switcher, while a hard-coded hex quietly opts out of it.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function sanitize_color( $value ) {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^--[a-z0-9-]{1,60}$/i', $value ) ) {
			return strtolower( $value );
		}

		$hex = sanitize_hex_color( $value );

		return $hex ? $hex : '';
	}

	/**
	 * @param mixed $value Attachment id.
	 * @param array $mimes Allowed mimes.
	 * @return int
	 */
	private function sanitize_attachment( $value, $mimes ) {
		$id = is_array( $value ) && isset( $value['id'] ) ? (int) $value['id'] : (int) $value;

		if ( $id <= 0 ) {
			return 0;
		}

		return $this->media->is_allowed( $id, (array) $mimes ) ? $id : 0;
	}

	/**
	 * @param mixed           $value Ids.
	 * @param FieldDefinition $field Field.
	 * @return int[]
	 */
	private function sanitize_attachment_list( $value, FieldDefinition $field ) {
		$max   = (int) $field->constraint( 'max_items', 24 );
		$mimes = (array) $field->constraint( 'mime', array() );
		$out   = array();

		foreach ( (array) $value as $item ) {
			$id = $this->sanitize_attachment( $item, $mimes );
			if ( $id ) {
				$out[] = $id;
			}
			if ( count( $out ) >= $max ) {
				break;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * @param mixed $value Ids.
	 * @param int   $max   Max items.
	 * @return int[]
	 */
	private function sanitize_id_list( $value, $max ) {
		$out = array();

		foreach ( (array) $value as $item ) {
			$id = absint( is_array( $item ) && isset( $item['id'] ) ? $item['id'] : $item );
			if ( $id ) {
				$out[] = $id;
			}
			if ( count( $out ) >= max( 1, $max ) ) {
				break;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * @param array $value Link parts.
	 * @return array
	 */
	private function sanitize_link( array $value ) {
		$url    = isset( $value['url'] ) ? esc_url_raw( (string) $value['url'], self::ALLOWED_PROTOCOLS ) : '';
		$label  = isset( $value['label'] ) ? sanitize_text_field( (string) $value['label'] ) : '';
		$is_new = ! empty( $value['new_tab'] );

		return array(
			'url'     => $url,
			'label'   => $label,
			'new_tab' => $is_new,
			// A new tab without noopener hands the opener a window reference. Set here, not in the
			// template, so every renderer inherits it and none can forget.
			'rel'     => $is_new ? 'noopener noreferrer' : '',
		);
	}

	/**
	 * @param array $value Button parts.
	 * @return array
	 */
	private function sanitize_button( array $value ) {
		$link = $this->sanitize_link( $value );

		$styles = array( 'primary', 'ghost', 'link' );
		$style  = isset( $value['style'] ) ? sanitize_key( (string) $value['style'] ) : 'primary';

		$link['style'] = in_array( $style, $styles, true ) ? $style : 'primary';

		return $link;
	}

	/**
	 * @param array           $value Query spec.
	 * @param FieldDefinition $field Field.
	 * @return array
	 */
	private function sanitize_product_query( array $value, FieldDefinition $field ) {
		$sources = (array) $field->constraint(
			'sources',
			array( 'featured', 'latest', 'best_sellers', 'top_rated', 'on_sale', 'category', 'tag', 'attribute', 'manual' )
		);

		$source = isset( $value['source'] ) ? sanitize_key( (string) $value['source'] ) : 'latest';
		$source = in_array( $source, $sources, true ) ? $source : 'latest';

		$orderby = isset( $value['orderby'] ) ? sanitize_key( (string) $value['orderby'] ) : 'date';
		$orderby = in_array( $orderby, array( 'date', 'title', 'price', 'popularity', 'rating', 'menu_order', 'rand' ), true ) ? $orderby : 'date';

		return array(
			'source'    => $source,
			'limit'     => max( 1, min( 48, isset( $value['limit'] ) ? (int) $value['limit'] : 8 ) ),
			'orderby'   => $orderby,
			'order'     => isset( $value['order'] ) && 'ASC' === strtoupper( (string) $value['order'] ) ? 'ASC' : 'DESC',
			'terms'     => $this->sanitize_id_list( isset( $value['terms'] ) ? $value['terms'] : array(), 12 ),
			'ids'       => $this->sanitize_id_list( isset( $value['ids'] ) ? $value['ids'] : array(), 48 ),
			'attribute' => isset( $value['attribute'] ) ? sanitize_key( (string) $value['attribute'] ) : '',
		);
	}

	/**
	 * @param FieldDefinition $field Field.
	 * @param mixed           $value Rows.
	 * @return array
	 */
	private function sanitize_repeater( FieldDefinition $field, $value ) {
		$children = $field->children();

		if ( ! $children ) {
			return array();
		}

		$max  = (int) $field->constraint( 'max_items', 20 );
		$rows = array();

		foreach ( (array) $value as $row ) {
			$rows[] = $this->sanitize( $children, (array) $row );
			if ( count( $rows ) >= max( 1, $max ) ) {
				break;
			}
		}

		return $rows;
	}
}
