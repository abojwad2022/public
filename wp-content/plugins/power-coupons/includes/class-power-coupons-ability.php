<?php
/**
 * Ability Runtime Class
 *
 * Contains execute callbacks, permission checks, and helpers
 * for the WordPress Abilities API integration.
 *
 * @package    Power_Coupons
 * @subpackage Power_Coupons/Includes
 * @since      1.1.0
 */

namespace Power_Coupons\Includes;

use Power_Coupons\Includes\Power_Coupons_Config_Ability;
use Power_Coupons\Includes\Power_Coupons_Rules_Registry;
use Power_Coupons\Includes\Power_Coupons_Settings_Helper;
use Power_Coupons\Includes\Power_Coupons_Utilities;
use Power_Coupons\Public_Folder\Power_Coupons_Frontend_Rules;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Power_Coupons_Ability
 *
 * Handles ability registration, execution callbacks, permission checks,
 * and input parsing for all Power Coupons abilities.
 *
 * @since 1.1.0
 */
class Power_Coupons_Ability {

	/**
	 * Parsed input data.
	 *
	 * @since 1.1.0
	 * @var array<string, mixed>|false
	 */
	protected $input = false;

	/**
	 * Register ability categories.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function register_categories() {
		wp_register_ability_category(
			'power-coupons',
			array(
				'label'       => __( 'Power Coupons', 'power-coupons' ),
				'description' => __( 'Abilities for Power Coupons for WooCommerce — manage coupons, rules, settings, and cart operations.', 'power-coupons' ),
			)
		);
	}

	/**
	 * Register all abilities.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function register() {
		$abilities = Power_Coupons_Config_Ability::get_abilities();

		foreach ( $abilities as $ability_name => $ability ) {
			/**
			 * Ability configuration array.
			 *
			 * @var array{label: string, description: string, category: string, input_schema: array<string, mixed>, output_schema: array<string, mixed>, execute_callback: callable, permission_callback: callable, meta: array<string, mixed>} $ability
			 */
			wp_register_ability(
				$ability_name,
				array(
					'label'               => $ability['label'],
					'description'         => $ability['description'],
					'category'            => $ability['category'],
					'input_schema'        => $ability['input_schema'],
					'output_schema'       => $ability['output_schema'],
					'execute_callback'    => $ability['execute_callback'],
					'permission_callback' => $ability['permission_callback'],
					'meta'                => $ability['meta'],
				)
			);
		}
	}

	// ============================================
	// Coupon Management
	// ============================================

	/**
	 * List coupons with pagination and filtering.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $input Input data.
	 * @throws \Exception If input parsing fails.
	 * @return array<string, mixed>
	 */
	public function list_coupons( $input ) {
		try {
			$this->init( $input, POWER_COUPONS_ABILITY_API_NAMESPACE . 'list-coupons' );

			$status   = $this->input_get( 'status' );
			$search   = $this->input_get( 'search', '' );
			$order_by = $this->input_get( 'order_by' );
			$order    = $this->input_get( 'order' );
			$page     = $this->clamp_page( $this->input_get( 'page' ) );
			$per_page = $this->clamp_per_page( $this->input_get( 'per_page' ) );

			$args = array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'any' === $status ? array( 'publish', 'draft', 'pending', 'private' ) : $status,
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => $order_by,
				'order'          => $order,
			);

			if ( ! empty( $search ) ) {
				$args['s'] = $search;
			}

			$query   = new \WP_Query( $args );
			$coupons = array();

			foreach ( $query->posts as $post ) {
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}
				$coupon    = new \WC_Coupon( $post->ID );
				$coupons[] = array(
					'id'            => $post->ID,
					'code'          => $coupon->get_code(),
					'description'   => $coupon->get_description(),
					'discount_type' => $coupon->get_discount_type(),
					'amount'        => (float) $coupon->get_amount(),
					'status'        => $post->post_status,
					'auto_apply'    => 'yes' === get_post_meta( $post->ID, '_power_coupon_auto_apply', true ),
					'start_date'    => get_post_meta( $post->ID, '_power_coupon_start_date', true ) ? get_post_meta( $post->ID, '_power_coupon_start_date', true ) : '',
					'expiry_date'   => $coupon->get_date_expires() ? $coupon->get_date_expires()->date( 'Y-m-d' ) : '',
					'rules_enabled' => Power_Coupons_Rules_Registry::are_rules_enabled( $post->ID ),
				);
			}

			$total = $query->found_posts;

			return array(
				'coupons'     => $coupons,
				'total'       => $total,
				'total_pages' => (int) ceil( $total / $per_page ),
			);
		} catch ( \Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get a single coupon by ID.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $input Input data.
	 * @throws \Exception If coupon ID is invalid or not found.
	 * @return array<string, mixed>
	 */
	public function get_coupon( $input ) {
		try {
			$this->init( $input, POWER_COUPONS_ABILITY_API_NAMESPACE . 'get-coupon' );

			$id = $this->input_get_int( 'id' );
			if ( 0 === $id ) {
				throw new \Exception( esc_html__( 'Invalid coupon ID.', 'power-coupons' ) );
			}

			$post = get_post( $id );
			if ( ! $post || 'shop_coupon' !== $post->post_type ) {
				throw new \Exception( esc_html__( 'Coupon not found.', 'power-coupons' ) );
			}

			$coupon      = new \WC_Coupon( $id );
			$rule_groups = Power_Coupons_Rules_Registry::get_rule_groups( $id );

			return array(
				'id'                          => $id,
				'code'                        => $coupon->get_code(),
				'description'                 => $coupon->get_description(),
				'discount_type'               => $coupon->get_discount_type(),
				'amount'                      => (float) $coupon->get_amount(),
				'status'                      => $post->post_status,
				'usage_count'                 => $coupon->get_usage_count(),
				'usage_limit'                 => $coupon->get_usage_limit() ? $coupon->get_usage_limit() : 0,
				'usage_limit_per_user'        => $coupon->get_usage_limit_per_user() ? $coupon->get_usage_limit_per_user() : 0,
				'free_shipping'               => $coupon->get_free_shipping(),
				'minimum_amount'              => (float) $coupon->get_minimum_amount(),
				'maximum_amount'              => (float) $coupon->get_maximum_amount(),
				'individual_use'              => $coupon->get_individual_use(),
				'exclude_sale_items'          => $coupon->get_exclude_sale_items(),
				'product_ids'                 => $coupon->get_product_ids(),
				'excluded_product_ids'        => $coupon->get_excluded_product_ids(),
				'product_categories'          => $coupon->get_product_categories(),
				'excluded_product_categories' => $coupon->get_excluded_product_categories(),
				'email_restrictions'          => $coupon->get_email_restrictions(),
				'auto_apply'                  => 'yes' === get_post_meta( $id, '_power_coupon_auto_apply', true ),
				'start_date'                  => get_post_meta( $id, '_power_coupon_start_date', true ) ? get_post_meta( $id, '_power_coupon_start_date', true ) : '',
				'expiry_date'                 => $coupon->get_date_expires() ? $coupon->get_date_expires()->date( 'Y-m-d' ) : '',
				'rules_enabled'               => Power_Coupons_Rules_Registry::are_rules_enabled( $id ),
				'rule_groups'                 => $rule_groups,
				'url_edit'                    => admin_url( 'post.php?post=' . $id . '&action=edit' ),
			);
		} catch ( \Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Create a new coupon.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $input Input data.
	 * @throws \Exception If coupon creation fails.
	 * @return array<string, mixed>
	 */
	public function create_coupon( $input ) {
		try {
			$this->init( $input, POWER_COUPONS_ABILITY_API_NAMESPACE . 'create-coupon' );

			$code = strtolower( $this->input_get_string( 'code' ) );

			// Check for duplicate code.
			$existing = wc_get_coupon_id_by_code( $code );
			if ( $existing ) {
				throw new \Exception( esc_html__( 'A coupon with this code already exists.', 'power-coupons' ) );
			}

			$coupon = new \WC_Coupon();
			$coupon->set_code( $code );
			$coupon->set_description( $this->input_get_string( 'description', '' ) );
			$coupon->set_discount_type( $this->input_get_string( 'discount_type' ) );
			$coupon->set_amount( $this->input_get_float( 'amount' ) );
			$coupon->set_free_shipping( ! empty( $this->input_get( 'free_shipping' ) ) );
			$coupon->set_individual_use( ! empty( $this->input_get( 'individual_use' ) ) );
			$coupon->set_exclude_sale_items( ! empty( $this->input_get( 'exclude_sale_items' ) ) );
			$coupon->set_minimum_amount( $this->input_get_float( 'minimum_amount' ) );
			$coupon->set_maximum_amount( $this->input_get_float( 'maximum_amount' ) );
			$coupon->set_usage_limit( $this->input_get_int( 'usage_limit' ) );
			$coupon->set_usage_limit_per_user( $this->input_get_int( 'usage_limit_per_user' ) );

			/**
			 * Typed product IDs.
			 *
			 * @var array<int> $product_ids
			 */
			$product_ids = $this->input_get( 'product_ids', array() );
			if ( ! empty( $product_ids ) ) {
				$coupon->set_product_ids( (array) $product_ids );
			}

			/**
			 * Typed excluded product IDs.
			 *
			 * @var array<int> $excluded_product_ids
			 */
			$excluded_product_ids = $this->input_get( 'excluded_product_ids', array() );
			if ( ! empty( $excluded_product_ids ) ) {
				$coupon->set_excluded_product_ids( (array) $excluded_product_ids );
			}

			/**
			 * Typed product category IDs.
			 *
			 * @var array<int> $product_categories
			 */
			$product_categories = $this->input_get( 'product_categories', array() );
			if ( ! empty( $product_categories ) ) {
				$coupon->set_product_categories( (array) $product_categories );
			}

			/**
			 * Typed excluded product category IDs.
			 *
			 * @var array<int> $excluded_product_categories
			 */
			$excluded_product_categories = $this->input_get( 'excluded_product_categories', array() );
			if ( ! empty( $excluded_product_categories ) ) {
				$coupon->set_excluded_product_categories( (array) $excluded_product_categories );
			}

			/**
			 * Typed email restrictions.
			 *
			 * @var array<string> $email_restrictions
			 */
			$email_restrictions = $this->input_get( 'email_restrictions', array() );
			if ( ! empty( $email_restrictions ) ) {
				$coupon->set_email_restrictions( (array) $email_restrictions );
			}

			$expiry_date = $this->input_get_string( 'expiry_date', '' );
			if ( ! empty( $expiry_date ) ) {
				$coupon->set_date_expires( $expiry_date );
			}

			$coupon_id = $coupon->save();

			// Save Power Coupons meta.
			$auto_apply = $this->input_get( 'auto_apply' );
			update_post_meta( $coupon_id, '_power_coupon_auto_apply', $auto_apply ? 'yes' : 'no' );

			$start_date = $this->input_get_string( 'start_date', '' );
			if ( ! empty( $start_date ) ) {
				update_post_meta( $coupon_id, '_power_coupon_start_date', sanitize_text_field( $start_date ) );
			}

			return array(
				'id'       => $coupon_id,
				'code'     => $code,
				'url_edit' => admin_url( 'post.php?post=' . $coupon_id . '&action=edit' ),
			);
		} catch ( \Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Update an existing coupon.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $input Input data.
	 * @throws \Exception If coupon ID is invalid or update fails.
	 * @return array<string, mixed>
	 */
	public function update_coupon( $input ) {
		try {
			$this->init( $input, POWER_COUPONS_ABILITY_API_NAMESPACE . 'update-coupon' );

			$id = $this->input_get_int( 'id' );
			if ( 0 === $id ) {
				throw new \Exception( esc_html__( 'Invalid coupon ID.', 'power-coupons' ) );
			}

			$post = get_post( $id );
			if ( ! $post || 'shop_coupon' !== $post->post_type ) {
				throw new \Exception( esc_html__( 'Coupon not found.', 'power-coupons' ) );
			}

			$coupon = new \WC_Coupon( $id );

			// Only update fields that were provided in the raw input.
			$raw = $input;

			$wc_setters = array(
				'code'                        => 'set_code',
				'description'                 => 'set_description',
				'discount_type'               => 'set_discount_type',
				'amount'                      => 'set_amount',
				'free_shipping'               => 'set_free_shipping',
				'individual_use'              => 'set_individual_use',
				'exclude_sale_items'          => 'set_exclude_sale_items',
				'minimum_amount'              => 'set_minimum_amount',
				'maximum_amount'              => 'set_maximum_amount',
				'usage_limit'                 => 'set_usage_limit',
				'usage_limit_per_user'        => 'set_usage_limit_per_user',
				'product_ids'                 => 'set_product_ids',
				'excluded_product_ids'        => 'set_excluded_product_ids',
				'product_categories'          => 'set_product_categories',
				'excluded_product_categories' => 'set_excluded_product_categories',
				'email_restrictions'          => 'set_email_restrictions',
			);

			foreach ( $wc_setters as $field => $setter ) {
				if ( array_key_exists( $field, $raw ) ) {
					$coupon->$setter( $this->input_get( $field ) );
				}
			}

			if ( array_key_exists( 'expiry_date', $raw ) ) {
				$expiry = $this->input_get_string( 'expiry_date', '' );
				$coupon->set_date_expires( ! empty( $expiry ) ? $expiry : null );
			}

			$coupon->save();

			// Power Coupons meta.
			if ( array_key_exists( 'auto_apply', $raw ) ) {
				$auto_apply = $this->input_get( 'auto_apply' );
				update_post_meta( $id, '_power_coupon_auto_apply', $auto_apply ? 'yes' : 'no' );
			}

			if ( array_key_exists( 'start_date', $raw ) ) {
				$start_date = $this->input_get_string( 'start_date', '' );
				if ( ! empty( $start_date ) ) {
					update_post_meta( $id, '_power_coupon_start_date', sanitize_text_field( $start_date ) );
				} else {
					delete_post_meta( $id, '_power_coupon_start_date' );
				}
			}

			return array(
				'id'       => $id,
				'code'     => $coupon->get_code(),
				'url_edit' => admin_url( 'post.php?post=' . $id . '&action=edit' ),
				'message'  => esc_html__( 'Coupon updated.', 'power-coupons' ),
			);
		} catch ( \Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Delete a coupon.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $input Input data.
	 * @throws \Exception If coupon ID is invalid or not found.
	 * @return array<string, mixed>
	 */
	public function delete_coupon( $input ) {
		try {
			$this->init( $input, POWER_COUPONS_ABILITY_API_NAMESPACE . 'delete-coupon' );

			$id        = $this->input_get_int( 'id' );
			$permanent = $this->input_get( 'permanent', false );

			if ( 0 === $id ) {
				throw new \Exception( esc_html__( 'Invalid coupon ID.', 'power-coupons' ) );
			}

			$post = get_post( $id );
			if ( ! $post || 'shop_coupon' !== $post->post_type ) {
				throw new \Exception( esc_html__( 'Coupon not found.', 'power-coupons' ) );
			}

			if ( $permanent ) {
				wp_delete_post( $id, true );
			} else {
				wp_trash_post( $id );
			}

			return array(
				'id'        => $id,
				'permanent' => $permanent,
				'message'   => esc_html(
					$permanent
						? __( 'Coupon permanently deleted.', 'power-coupons' )
						: __( 'Coupon moved to trash.', 'power-coupons' )
				),
			);
		} catch ( \Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Toggle auto-apply on a coupon.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $input Input data.
	 * @throws \Exception If coupon ID is invalid or not found.
	 * @return array<string, mixed>
	 */
	public function toggle_auto_apply( $input ) {
		try {
			$this->init( $input, POWER_COUPONS_ABILITY_API_NAMESPACE . 'toggle-auto-apply' );

			$id      = $this->input_get_int( 'id' );
			$enabled = $this->input_get( 'enabled' );

			if ( 0 === $id ) {
				throw new \Exception( esc_html__( 'Invalid coupon ID.', 'power-coupons' ) );
			}

			$post = get_post( $id );
			if ( ! $post || 'shop_coupon' !== $post->post_type ) {
				throw new \Exception( esc_html__( 'Coupon not found.', 'power-coupons' ) );
			}

			update_post_meta( $id, '_power_coupon_auto_apply', $enabled ? 'yes' : 'no' );

			return array(
				'id'      => $id,
				'enabled' => $enabled,
				'message' => esc_html(
					$enabled
						? __( 'Auto-apply enabled.', 'power-coupons' )
						: __( 'Auto-apply disabled.', 'power-coupons' )
				),
			);
		} catch ( \Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get coupon shortcode.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $input Input data.
	 * @throws \Exception If coupon ID is invalid or not found.
	 * @return array<string, mixed>
	 */
	public function get_coupon_shortcode( $input ) {
		try {
			$this->init( $input, POWER_COUPONS_ABILITY_API_NAMESPACE . 'get-coupon-shortcode' );

			$id = $this->input_get_int( 'id' );
			if ( 0 === $id ) {
				throw new \Exception( esc_html__( 'Invalid coupon ID.', 'power-coupons' ) );
			}

			$post = get_post( $id );
			if ( ! $post || 'shop_coupon' !== $post->post_type ) {
				throw new \Exception( esc_html__( 'Coupon not found.', 'power-coupons' ) );
			}

			return array(
				'shortcode' => '[power_coupons id=' . $id . ']',
			);
		} catch ( \Exception $e ) {
			return $this->error( $e );
		}
	}

	// ============================================
	// Conditional Rules
	// ============================================

	/**
	 * Get conditional rules for a coupon.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $input Input data.
	 * @throws \Exception If coupon ID is invalid or not found.
	 * @return array<string, mixed>
	 */
	public function get_coupon_rules( $input ) {
		try {
			$this->init( $input, POWER_COUPONS_ABILITY_API_NAMESPACE . 'get-coupon-rules' );

			$id = $this->input_get_int( 'id' );
			if ( 0 === $id ) {
				throw new \Exception( esc_html__( 'Invalid coupon ID.', 'power-coupons' ) );
			}

			$post = get_post( $id );
			if ( ! $post || 'shop_coupon' !== $post->post_type ) {
				throw new \Exception( esc_html__( 'Coupon not found.', 'power-coupons' ) );
			}

			$meta = Power_Coupons_Rules_Registry::get_all_meta( $id );

			return array(
				'id'            => $id,
				'rules_enabled' => (bool) $meta['enabled'],
				'groups'        => $meta['groups'],
			);
		} catch ( \Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Set conditional rules on a coupon.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $input Input data.
	 * @throws \Exception If coupon ID is invalid or rules are malformed.
	 * @return array<string, mixed>
	 */
	public function set_coupon_rules( $input ) {
		try {
			$this->init( $input, POWER_COUPONS_ABILITY_API_NAMESPACE . 'set-coupon-rules' );

			$id     = $this->input_get_int( 'id' );
			$groups = $this->input_get( 'groups' );

			if ( 0 === $id ) {
				throw new \Exception( esc_html__( 'Invalid coupon ID.', 'power-coupons' ) );
			}

			$post = get_post( $id );
			if ( ! $post || 'shop_coupon' !== $post->post_type ) {
				throw new \Exception( esc_html__( 'Coupon not found.', 'power-coupons' ) );
			}

			// Build groups in the format Power_Coupons_Rules_Registry expects.
			$formatted_groups = array();
			if ( is_array( $groups ) ) {
				foreach ( $groups as $index => $group ) {
					$group_id = 'group_' . ( $index + 1 );
					$rules    = array();

					if ( isset( $group['rules'] ) && is_array( $group['rules'] ) ) {
						foreach ( $group['rules'] as $rule_index => $rule ) {
							$rule_type     = isset( $rule['type'] ) ? sanitize_text_field( strval( $rule['type'] ) ) : '';
							$rule_operator = isset( $rule['operator'] ) ? sanitize_text_field( strval( $rule['operator'] ) ) : '';

							// Validate type and operator.
							$valid_types = array( 'cart_total', 'cart_items', 'products', 'product_categories' );
							if ( ! in_array( $rule_type, $valid_types, true ) ) {
								throw new \Exception(
									sprintf(
										/* translators: %s: rule type */
										esc_html__( 'Invalid rule type: %s', 'power-coupons' ),
										esc_html( $rule_type )
									)
								);
							}

							if ( ! Power_Coupons_Rules_Registry::is_valid_operator( $rule_type, $rule_operator ) ) {
								throw new \Exception(
									sprintf(
										/* translators: 1: operator, 2: rule type */
										esc_html__( 'Invalid operator "%1$s" for rule type "%2$s".', 'power-coupons' ),
										esc_html( $rule_operator ),
										esc_html( $rule_type )
									)
								);
							}

							$rules[] = array(
								'rule_id'  => 'rule_' . ( $rule_index + 1 ),
								'type'     => $rule_type,
								'operator' => $rule_operator,
								'value'    => isset( $rule['value'] ) ? $rule['value'] : 0,
							);
						}
					}

					$formatted_groups[] = array(
						'group_id' => $group_id,
						'rules'    => $rules,
					);
				}
			}

			// Enable rules and save.
			$data = array(
				'_pc_rule_enable_conditions' => 'yes',
				'_pc_rule_groups'            => $formatted_groups,
			);

			Power_Coupons_Rules_Registry::save_meta( $id, $data );

			return array(
				'id'      => $id,
				'groups'  => count( $formatted_groups ),
				'message' => esc_html__( 'Coupon rules saved.', 'power-coupons' ),
			);
		} catch ( \Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Toggle conditional rules on a coupon.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $input Input data.
	 * @throws \Exception If coupon ID is invalid or not found.
	 * @return array<string, mixed>
	 */
	public function toggle_coupon_rules( $input ) {
		try {
			$this->init( $input, POWER_COUPONS_ABILITY_API_NAMESPACE . 'toggle-coupon-rules' );

			$id      = $this->input_get_int( 'id' );
			$enabled = $this->input_get( 'enabled' );

			if ( 0 === $id ) {
				throw new \Exception( esc_html__( 'Invalid coupon ID.', 'power-coupons' ) );
			}

			$post = get_post( $id );
			if ( ! $post || 'shop_coupon' !== $post->post_type ) {
				throw new \Exception( esc_html__( 'Coupon not found.', 'power-coupons' ) );
			}

			update_post_meta( $id, '_pc_rule_enable_conditions', $enabled ? 'yes' : 'no' );

			return array(
				'id'      => $id,
				'enabled' => $enabled,
				'message' => esc_html(
					$enabled
						? __( 'Conditional rules enabled.', 'power-coupons' )
						: __( 'Conditional rules disabled.', 'power-coupons' )
				),
			);
		} catch ( \Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Validate coupon rules against current cart.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $input Input data.
	 * @throws \Exception If coupon ID is invalid or not found.
	 * @return array<string, mixed>
	 */
	public function validate_coupon_rules( $input ) {
		try {
			$this->init( $input, POWER_COUPONS_ABILITY_API_NAMESPACE . 'validate-coupon-rules' );

			$id = $this->input_get_int( 'id' );
			if ( 0 === $id ) {
				throw new \Exception( esc_html__( 'Invalid coupon ID.', 'power-coupons' ) );
			}

			$post = get_post( $id );
			if ( ! $post || 'shop_coupon' !== $post->post_type ) {
				throw new \Exception( esc_html__( 'Coupon not found.', 'power-coupons' ) );
			}

			$rules_enabled = Power_Coupons_Rules_Registry::are_rules_enabled( $id );

			if ( ! $rules_enabled ) {
				return array(
					'id'            => $id,
					'rules_enabled' => false,
					'valid'         => true,
					'groups'        => array(),
				);
			}

			$groups         = Power_Coupons_Rules_Registry::get_rule_groups( $id );
			$wc_instance    = function_exists( 'WC' ) ? WC() : null;
			$cart_available = null !== $wc_instance && null !== $wc_instance->cart;
			$group_results  = array();
			$any_passed     = false;

			foreach ( $groups as $group ) {
				$group_passed = true;
				$rule_results = array();

				if ( ! isset( $group['rules'] ) || ! is_array( $group['rules'] ) ) {
					continue;
				}

				foreach ( $group['rules'] as $rule ) {
					$type     = isset( $rule['type'] ) ? $rule['type'] : '';
					$operator = isset( $rule['operator'] ) ? $rule['operator'] : '';
					$expected = isset( $rule['value'] ) ? $rule['value'] : 0;
					$actual   = 0;
					$passed   = false;

					if ( $cart_available && null !== $wc_instance && null !== $wc_instance->cart ) {
						switch ( $type ) {
							case 'cart_total':
								$actual = (float) $wc_instance->cart->get_subtotal();
								$passed = Power_Coupons_Utilities::compare_numeric( $actual, $operator, floatval( $expected ) );
								break;

							case 'cart_items':
								$actual = count( $wc_instance->cart->get_cart() );
								$passed = Power_Coupons_Utilities::compare_numeric( $actual, $operator, intval( $expected ) );
								break;

							case 'products':
								$cart_product_ids = array();
								foreach ( $wc_instance->cart->get_cart() as $item ) {
									$cart_product_ids[] = $item['product_id'];
									if ( ! empty( $item['variation_id'] ) ) {
										$cart_product_ids[] = $item['variation_id'];
									}
								}
								if ( 'in_list' === $operator ) {
									$passed = in_array( intval( $expected ), $cart_product_ids, true );
								} else {
									$passed = ! in_array( intval( $expected ), $cart_product_ids, true );
								}
								$actual = count( $cart_product_ids );
								break;

							case 'product_categories':
								$cart_cat_ids = array();
								foreach ( $wc_instance->cart->get_cart() as $item ) {
									$terms = get_the_terms( $item['product_id'], 'product_cat' );
									if ( is_array( $terms ) ) {
										foreach ( $terms as $term ) {
											$cart_cat_ids[] = $term->term_id;
										}
									}
								}
								$cart_cat_ids = array_unique( $cart_cat_ids );
								if ( 'in_list' === $operator ) {
									$passed = in_array( intval( $expected ), $cart_cat_ids, true );
								} else {
									$passed = ! in_array( intval( $expected ), $cart_cat_ids, true );
								}
								$actual = count( $cart_cat_ids );
								break;
						}
					}

					if ( ! $passed ) {
						$group_passed = false;
					}

					$rule_results[] = array(
						'type'     => $type,
						'operator' => $operator,
						'expected' => $expected,
						'actual'   => $actual,
						'passed'   => $passed,
					);
				}

				if ( $group_passed ) {
					$any_passed = true;
				}

				$group_results[] = array(
					'group_id' => isset( $group['group_id'] ) ? $group['group_id'] : '',
					'passed'   => $group_passed,
					'rules'    => $rule_results,
				);
			}

			return array(
				'id'            => $id,
				'rules_enabled' => true,
				'valid'         => $any_passed || empty( $groups ),
				'groups'        => $group_results,
			);
		} catch ( \Exception $e ) {
			return $this->error( $e );
		}
	}

	// ============================================
	// Settings & Configuration
	// ============================================

	/**
	 * Get plugin settings.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $input Input data.
	 * @return array<string, mixed>
	 */
	public function get_settings( $input ) {
		try {
			$this->init( $input, POWER_COUPONS_ABILITY_API_NAMESPACE . 'get-settings' );

			$section  = $this->input_get_string( 'section' );
			$helper   = Power_Coupons_Settings_Helper::get_instance();
			$settings = $helper->get_all_settings();

			if ( 'all' !== $section && isset( $settings[ $section ] ) ) {
				return array( $section => $settings[ $section ] );
			}

			return $settings;
		} catch ( \Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Update plugin settings.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $input Input data.
	 * @return array<string, mixed>
	 */
	public function update_settings( $input ) {
		try {
			$this->init( $input, POWER_COUPONS_ABILITY_API_NAMESPACE . 'update-settings' );

			$helper  = Power_Coupons_Settings_Helper::get_instance();
			$current = $helper->get_all_settings();
			$raw     = $input;

			// Merge provided sections into current settings.
			$sections = array( 'general', 'coupon_styling', 'text' );
			foreach ( $sections as $section ) {
				if ( array_key_exists( $section, $raw ) && is_array( $raw[ $section ] ) ) {
					$section_data = $this->input_get( $section, array() );
					if ( is_array( $section_data ) ) {
						$current_section     = isset( $current[ $section ] ) && is_array( $current[ $section ] ) ? $current[ $section ] : array();
						$current[ $section ] = array_merge(
							$current_section,
							$section_data
						);
					}
				}
			}

			update_option( 'power_coupons_settings', $current );
			$helper->clear_cache();

			return array(
				'message'  => esc_html__( 'Settings updated.', 'power-coupons' ),
				'settings' => $current,
			);
		} catch ( \Exception $e ) {
			return $this->error( $e );
		}
	}

	// ============================================
	// Coupon Display & Application
	// ============================================

	/**
	 * List coupons available for the current cart.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $input Input data.
	 * @throws \Exception If WooCommerce cart is not available.
	 * @return array<string, mixed>
	 */
	public function list_available_coupons( $input ) {
		try {
			$this->init( $input, POWER_COUPONS_ABILITY_API_NAMESPACE . 'list-available-coupons' );

			if ( ! function_exists( 'WC' ) ) {
				throw new \Exception( esc_html__( 'WooCommerce cart is not available.', 'power-coupons' ) );
			}

			$wc_instance = WC();
			if ( null === $wc_instance->cart ) {
				throw new \Exception( esc_html__( 'WooCommerce cart is not available.', 'power-coupons' ) );
			}

			$applied_coupons = $wc_instance->cart->get_applied_coupons();

			$args = array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
			);

			$query   = new \WP_Query( $args );
			$coupons = array();

			if ( $query->posts ) {
				update_meta_cache( 'post', wp_list_pluck( $query->posts, 'ID' ) );
			}

			foreach ( $query->posts as $post ) {
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}

				$coupon = new \WC_Coupon( $post->ID );

				// Build coupon data array for utility checks.
				$coupon_data = array(
					'expiry_date' => $coupon->get_date_expires() ? $coupon->get_date_expires()->date( 'Y-m-d' ) : '',
					'start_date'  => get_post_meta( $post->ID, '_power_coupon_start_date', true ) ? get_post_meta( $post->ID, '_power_coupon_start_date', true ) : '',
				);

				// Skip expired.
				if ( Power_Coupons_Utilities::is_coupon_expired( $coupon_data ) ) {
					continue;
				}

				// Skip not started.
				if ( Power_Coupons_Utilities::is_coupon_not_started( $coupon_data ) ) {
					continue;
				}

				// Check conditional rules.
				if ( class_exists( 'Power_Coupons\Public_Folder\Power_Coupons_Frontend_Rules' ) ) {
					$rules_instance = Power_Coupons_Frontend_Rules::get_instance();
					if ( ! $rules_instance->is_coupon_valid( $post->ID ) ) {
						continue;
					}
				}

				$is_applied = in_array( strtolower( $coupon->get_code() ), array_map( 'strtolower', $applied_coupons ), true );

				$coupons[] = array(
					'id'          => $post->ID,
					'code'        => $coupon->get_code(),
					'description' => $coupon->get_description(),
					'amount'      => (float) $coupon->get_amount(),
					'type'        => $coupon->get_discount_type(),
					'type_text'   => Power_Coupons_Utilities::format_discount_amount( (float) $coupon->get_amount(), $coupon->get_discount_type() ),
					'auto_apply'  => 'yes' === get_post_meta( $post->ID, '_power_coupon_auto_apply', true ),
					'is_applied'  => $is_applied,
					'expiry_date' => $coupon_data['expiry_date'],
				);
			}

			return array(
				'coupons' => $coupons,
				'total'   => count( $coupons ),
			);
		} catch ( \Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get applied coupons on the cart.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $input Input data.
	 * @throws \Exception If WooCommerce cart is not available.
	 * @return array<string, mixed>
	 */
	public function get_applied_coupons( $input ) {
		try {
			$this->init( $input, POWER_COUPONS_ABILITY_API_NAMESPACE . 'get-applied-coupons' );

			if ( ! function_exists( 'WC' ) ) {
				throw new \Exception( esc_html__( 'WooCommerce cart is not available.', 'power-coupons' ) );
			}

			$wc_instance = WC();
			if ( null === $wc_instance->cart ) {
				throw new \Exception( esc_html__( 'WooCommerce cart is not available.', 'power-coupons' ) );
			}

			$applied = $wc_instance->cart->get_applied_coupons();
			$coupons = array();

			foreach ( $applied as $code ) {
				$coupon    = new \WC_Coupon( $code );
				$coupons[] = array(
					'code'            => $code,
					'discount_amount' => (float) $wc_instance->cart->get_coupon_discount_amount( $code ),
					'discount_type'   => $coupon->get_discount_type(),
				);
			}

			return array(
				'coupons'    => $coupons,
				'total'      => count( $coupons ),
				'cart_total' => (float) $wc_instance->cart->get_total( 'edit' ),
			);
		} catch ( \Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Apply a coupon to the cart.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $input Input data.
	 * @throws \Exception If WooCommerce cart is not available.
	 * @return array<string, mixed>
	 */
	public function apply_coupon( $input ) {
		try {
			$this->init( $input, POWER_COUPONS_ABILITY_API_NAMESPACE . 'apply-coupon' );

			if ( ! function_exists( 'WC' ) ) {
				throw new \Exception( esc_html__( 'WooCommerce cart is not available.', 'power-coupons' ) );
			}

			$wc_instance = WC();
			if ( null === $wc_instance->cart ) {
				throw new \Exception( esc_html__( 'WooCommerce cart is not available.', 'power-coupons' ) );
			}

			$code = strtolower( $this->input_get_string( 'code' ) );

			if ( $wc_instance->cart->has_discount( $code ) ) {
				return array(
					'code'       => $code,
					'applied'    => true,
					'message'    => esc_html__( 'Coupon is already applied.', 'power-coupons' ),
					'cart_total' => (float) $wc_instance->cart->get_total( 'edit' ),
				);
			}

			$result = $wc_instance->cart->apply_coupon( $code );

			if ( $result ) {
				$wc_instance->cart->calculate_totals();
				return array(
					'code'       => $code,
					'applied'    => true,
					'message'    => esc_html__( 'Coupon applied successfully.', 'power-coupons' ),
					'cart_total' => (float) $wc_instance->cart->get_total( 'edit' ),
				);
			}

			// Collect WC notices for error message.
			$notices = wc_get_notices( 'error' );
			wc_clear_notices();
			$error_msg = ! empty( $notices ) ? wp_strip_all_tags( $notices[0]['notice'] ) : __( 'Coupon could not be applied.', 'power-coupons' );

			return array(
				'code'       => $code,
				'applied'    => false,
				'message'    => esc_html( $error_msg ),
				'cart_total' => (float) $wc_instance->cart->get_total( 'edit' ),
			);
		} catch ( \Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Remove a coupon from the cart.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $input Input data.
	 * @throws \Exception If WooCommerce cart is not available or coupon is auto-applied.
	 * @return array<string, mixed>
	 */
	public function remove_coupon( $input ) {
		try {
			$this->init( $input, POWER_COUPONS_ABILITY_API_NAMESPACE . 'remove-coupon' );

			if ( ! function_exists( 'WC' ) ) {
				throw new \Exception( esc_html__( 'WooCommerce cart is not available.', 'power-coupons' ) );
			}

			$wc_instance = WC();
			if ( null === $wc_instance->cart ) {
				throw new \Exception( esc_html__( 'WooCommerce cart is not available.', 'power-coupons' ) );
			}

			$code = strtolower( $this->input_get_string( 'code' ) );

			// Check if it's an auto-applied coupon.
			$coupon_id = wc_get_coupon_id_by_code( $code );
			if ( $coupon_id && 'yes' === get_post_meta( $coupon_id, '_power_coupon_auto_apply', true ) ) {
				throw new \Exception( esc_html__( 'Cannot remove auto-applied coupons.', 'power-coupons' ) );
			}

			if ( ! $wc_instance->cart->has_discount( $code ) ) {
				throw new \Exception( esc_html__( 'Coupon is not applied to the cart.', 'power-coupons' ) );
			}

			$result = $wc_instance->cart->remove_coupon( $code );
			$wc_instance->cart->calculate_totals();

			return array(
				'code'       => $code,
				'removed'    => (bool) $result,
				'message'    => $result
					? esc_html__( 'Coupon removed.', 'power-coupons' )
					: esc_html__( 'Could not remove coupon.', 'power-coupons' ),
				'cart_total' => (float) $wc_instance->cart->get_total( 'edit' ),
			);
		} catch ( \Exception $e ) {
			return $this->error( $e );
		}
	}

	// ============================================
	// Helper Methods
	// ============================================

	/**
	 * Initialize input parsing.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed>|\WP_REST_Request<array<string, mixed>> $input        Raw input.
	 * @param string                                                      $ability_name Ability identifier.
	 * @return void
	 */
	public function init( $input, $ability_name ) {
		$this->input_parse( $input, $ability_name );
	}

	/**
	 * Check user capabilities.
	 *
	 * @since 1.1.0
	 * @param string|array<int, string> $caps Single capability or array of capabilities (AND logic).
	 * @return bool
	 */
	public function permission_callback( $caps ) {
		if ( empty( $caps ) ) {
			return false;
		}

		$user = wp_get_current_user();
		if ( 0 === $user->ID ) {
			return false;
		}

		if ( is_string( $caps ) ) {
			return $user->has_cap( $caps );
		}

		foreach ( $caps as $cap ) {
			if ( ! $user->has_cap( $cap ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Parse and validate input against schema.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed>|\WP_REST_Request<array<string, mixed>> $input        Raw input.
	 * @param string                                                      $ability_name Ability identifier.
	 * @throws \Exception If required fields are missing or values are invalid.
	 * @return array<string, mixed> Parsed input.
	 */
	public function input_parse( $input, $ability_name ) {
		$this->input = array();

		if ( $input instanceof \WP_REST_Request ) {
			$input = $input->get_json_params();
		}

		$input_schema = Power_Coupons_Config_Ability::get_ability_input_schema( $ability_name );
		if ( ! is_array( $input_schema ) || empty( $input_schema ) ) {
			return array();
		}

		if ( ! isset( $input_schema['properties'] ) || ! is_array( $input_schema['properties'] ) ) {
			return array();
		}

		$required_fields = isset( $input_schema['required'] ) && is_array( $input_schema['required'] )
			? $input_schema['required']
			: array();

		/**
		 * Typed schema properties.
		 *
		 * @var array<string, array{type?: string, default?: mixed, enum?: array<mixed>}> $properties
		 */
		$properties = $input_schema['properties'];

		foreach ( $properties as $name => $prop ) {
			$type      = isset( $prop['type'] ) ? strtolower( $prop['type'] ) : 'string';
			$raw_value = array_key_exists( $name, $input ) ? $input[ $name ] : null;

			$is_required = in_array( $name, $required_fields, true );
			if ( $is_required && ( null === $raw_value || '' === $raw_value ) ) {
				throw new \Exception(
					sprintf(
						/* translators: %s: field name */
						esc_html__( 'Required field %s is missing.', 'power-coupons' ),
						esc_html( strval( $name ) )
					)
				);
			}

			if ( null === $raw_value && isset( $prop['default'] ) ) {
				$raw_value = $prop['default'];
			}

			if ( null === $raw_value ) {
				switch ( $type ) {
					case 'integer':
						$raw_value = 0;
						break;
					case 'number':
						$raw_value = 0.0;
						break;
					case 'boolean':
						$raw_value = false;
						break;
					case 'array':
						$raw_value = array();
						break;
					case 'object':
						$raw_value = array();
						break;
					default:
						$raw_value = '';
						break;
				}
			}

			$value = $raw_value;

			switch ( $type ) {
				case 'integer':
					$value = intval( $value );
					break;
				case 'number':
					$value = floatval( $value );
					break;
				case 'boolean':
					$value = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
					break;
				case 'string':
					$value = is_string( $value ) ? sanitize_text_field( $value ) : sanitize_text_field( strval( $value ) );
					break;
				case 'array':
					if ( ! is_array( $value ) ) {
						$value = array();
					}
					$value = $this->sanitize_recursive( $value );
					break;
				case 'object':
					if ( ! is_array( $value ) && ! is_object( $value ) ) {
						$value = array();
					}
					if ( is_object( $value ) ) {
						$value = (array) $value;
					}
					$value = $this->sanitize_recursive( $value );
					break;
			}

			if ( isset( $prop['enum'] ) ) {
				if ( ! in_array( $value, $prop['enum'], true ) ) {
					throw new \Exception(
						sprintf(
							/* translators: %s: field name */
							esc_html__( 'Invalid value for %s.', 'power-coupons' ),
							esc_html( strval( $name ) )
						)
					);
				}
			}

			$this->input[ $name ] = $value;
		}

		return $this->input;
	}

	/**
	 * Get a parsed input value.
	 *
	 * @since 1.1.0
	 * @param string $name    Property name.
	 * @param mixed  $default Default value if not found.
	 * @throws \Exception If inputs not parsed or property not found.
	 * @return mixed
	 */
	public function input_get( $name, $default = '{__NO_DEFAULT__}' ) {
		if ( false === $this->input ) {
			throw new \Exception( esc_html__( 'Inputs not parsed.', 'power-coupons' ) );
		}

		if ( ! array_key_exists( $name, $this->input ) ) {
			if ( '{__NO_DEFAULT__}' !== $default ) {
				return $default;
			}
			throw new \Exception(
				sprintf(
					/* translators: %s: property name */
					esc_html__( 'Property %s not found.', 'power-coupons' ),
					esc_html( $name )
				)
			);
		}

		return $this->input[ $name ];
	}

	/**
	 * Get a parsed input value as string.
	 *
	 * @since 1.1.0
	 * @param string $name    Property name.
	 * @param string $default Default value if not found.
	 * @return string
	 */
	public function input_get_string( $name, $default = '' ) {
		$value = $this->input_get( $name, $default );
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) || is_bool( $value ) ) {
			return (string) $value;
		}
		return $default;
	}

	/**
	 * Get a parsed input value as integer.
	 *
	 * @since 1.1.0
	 * @param string $name    Property name.
	 * @param int    $default Default value if not found.
	 * @return int
	 */
	public function input_get_int( $name, $default = 0 ) {
		$value = $this->input_get( $name, $default );
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		return $default;
	}

	/**
	 * Get a parsed input value as float.
	 *
	 * @since 1.1.0
	 * @param string $name    Property name.
	 * @param float  $default Default value if not found.
	 * @return float
	 */
	public function input_get_float( $name, $default = 0.0 ) {
		$value = $this->input_get( $name, $default );
		if ( is_float( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return (float) $value;
		}
		return $default;
	}

	/**
	 * Recursively sanitize array/object values.
	 *
	 * @since 1.1.0
	 * @param array<string|int, mixed> $data Data to sanitize.
	 * @return array<string|int, mixed>
	 */
	protected function sanitize_recursive( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$sanitized = array();
		foreach ( $data as $key => $value ) {
			$key = sanitize_text_field( strval( $key ) );
			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_recursive( $value );
			} elseif ( is_string( $value ) ) {
				$sanitized[ $key ] = sanitize_text_field( $value );
			} elseif ( is_int( $value ) ) {
				$sanitized[ $key ] = intval( $value );
			} elseif ( is_float( $value ) ) {
				$sanitized[ $key ] = floatval( $value );
			} elseif ( is_bool( $value ) ) {
				$sanitized[ $key ] = (bool) $value;
			} else {
				$sanitized[ $key ] = $value;
			}
		}
		return $sanitized;
	}

	/**
	 * Format error response.
	 *
	 * @since 1.1.0
	 * @param \Exception $e The exception.
	 * @return array<string, mixed>
	 */
	public function error( $e ) {
		$error = array(
			'error' => array(
				'code'    => 'power_coupons_error',
				'message' => esc_html( $e->getMessage() ),
			),
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$error['error']['debug'] = array(
				'file' => $e->getFile(),
				'line' => $e->getLine(),
			);
		}

		return $error;
	}

	/**
	 * Clamp per_page to safe bounds.
	 *
	 * @since 1.1.0
	 * @param mixed $per_page Raw value.
	 * @param int   $max      Maximum allowed value.
	 * @return int
	 */
	protected function clamp_per_page( $per_page, $max = 100 ) {
		$per_page = is_numeric( $per_page ) ? (int) $per_page : 10;
		return max( 1, min( $per_page, $max ) );
	}

	/**
	 * Clamp page to minimum 1.
	 *
	 * @since 1.1.0
	 * @param mixed $page Raw value.
	 * @return int
	 */
	protected function clamp_page( $page ) {
		$page = is_numeric( $page ) ? (int) $page : 1;
		return max( 1, $page );
	}
}
