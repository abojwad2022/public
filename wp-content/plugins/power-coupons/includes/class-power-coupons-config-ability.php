<?php
/**
 * Ability Config Class
 *
 * Defines all ability configurations for the WordPress Abilities API.
 *
 * @package    Power_Coupons
 * @subpackage Power_Coupons/Includes
 * @since      1.1.0
 */

namespace Power_Coupons\Includes;

use Power_Coupons\Includes\Power_Coupons_Ability;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Power_Coupons_Config_Ability
 *
 * Static configuration class defining all ability schemas, permissions,
 * and metadata for the WordPress Abilities API integration.
 */
class Power_Coupons_Config_Ability {

	/**
	 * Cached abilities
	 *
	 * @var array<string, mixed>|false
	 */
	public static $abilities = false;

	/**
	 * Get all ability configurations.
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> Ability definitions.
	 */
	public static function get_abilities() {

		if ( false !== self::$abilities ) {
			return self::$abilities;
		}

		$pc_ability = new Power_Coupons_Ability();

		$abilities = array(

			// ============================================
			// Coupon Management
			// ============================================

			// List coupons.
			POWER_COUPONS_ABILITY_API_NAMESPACE . 'list-coupons' => array(
				'label'               => __( 'List coupons', 'power-coupons' ),
				'description'         => __( 'Returns a paginated list of WooCommerce coupons with Power Coupons metadata (auto-apply, start date, rules status). Use this to discover available coupons.', 'power-coupons' ),
				'category'            => 'power-coupons',
				'permission_callback' => function () use ( $pc_ability ) {
					return $pc_ability->permission_callback( 'manage_woocommerce' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array(
							'type'        => 'string',
							'description' => 'Filter by coupon post status.',
							'enum'        => array( 'publish', 'draft', 'trash', 'any' ),
							'default'     => 'publish',
						),
						'search'   => array(
							'type'        => 'string',
							'description' => 'Search coupons by code.',
						),
						'order_by' => array(
							'type'        => 'string',
							'description' => 'Column to sort by.',
							'enum'        => array( 'ID', 'title', 'date' ),
							'default'     => 'date',
						),
						'order'    => array(
							'type'        => 'string',
							'description' => 'Sort direction.',
							'enum'        => array( 'ASC', 'DESC' ),
							'default'     => 'DESC',
						),
						'page'     => array(
							'type'        => 'integer',
							'description' => 'Page number (1-based).',
							'default'     => 1,
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => 'Results per page (max 100).',
							'default'     => 20,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'coupons'     => array(
							'type'        => 'array',
							'description' => 'The coupons.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'id'            => array(
										'type'        => 'integer',
										'description' => 'Coupon ID.',
									),
									'code'          => array(
										'type'        => 'string',
										'description' => 'Coupon code.',
									),
									'description'   => array(
										'type'        => 'string',
										'description' => 'Coupon description.',
									),
									'discount_type' => array(
										'type'        => 'string',
										'description' => 'Discount type (percent, fixed_cart, fixed_product).',
									),
									'amount'        => array(
										'type'        => 'number',
										'description' => 'Discount amount.',
									),
									'status'        => array(
										'type'        => 'string',
										'description' => 'Post status.',
									),
									'auto_apply'    => array(
										'type'        => 'boolean',
										'description' => 'Whether auto-apply is enabled.',
									),
									'start_date'    => array(
										'type'        => 'string',
										'description' => 'Start date (YYYY-MM-DD) or empty.',
									),
									'expiry_date'   => array(
										'type'        => 'string',
										'description' => 'Expiry date (YYYY-MM-DD) or empty.',
									),
									'rules_enabled' => array(
										'type'        => 'boolean',
										'description' => 'Whether conditional rules are enabled.',
									),
								),
							),
						),
						'total'       => array(
							'type'        => 'integer',
							'description' => 'Total matching coupons.',
						),
						'total_pages' => array(
							'type'        => 'integer',
							'description' => 'Total pages.',
						),
					),
				),
				'execute_callback'    => function ( $input ) use ( $pc_ability ) {
					return $pc_ability->list_coupons( $input );
				},
				'meta'                => array(
					'annotations' => array(
						'priority'        => 1.0,
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			),

			// Get single coupon.
			POWER_COUPONS_ABILITY_API_NAMESPACE . 'get-coupon' => array(
				'label'               => __( 'Get coupon', 'power-coupons' ),
				'description'         => __( 'Returns a single coupon by ID with all WooCommerce and Power Coupons data including discount type, amounts, usage limits, restrictions, auto-apply, start date, and rules.', 'power-coupons' ),
				'category'            => 'power-coupons',
				'permission_callback' => function () use ( $pc_ability ) {
					return $pc_ability->permission_callback( 'manage_woocommerce' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'The coupon ID.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'                          => array(
							'type'        => 'integer',
							'description' => 'Coupon ID.',
						),
						'code'                        => array(
							'type'        => 'string',
							'description' => 'Coupon code.',
						),
						'description'                 => array(
							'type'        => 'string',
							'description' => 'Coupon description.',
						),
						'discount_type'               => array(
							'type'        => 'string',
							'description' => 'Discount type.',
						),
						'amount'                      => array(
							'type'        => 'number',
							'description' => 'Discount amount.',
						),
						'status'                      => array(
							'type'        => 'string',
							'description' => 'Post status.',
						),
						'usage_count'                 => array(
							'type'        => 'integer',
							'description' => 'Number of times used.',
						),
						'usage_limit'                 => array(
							'type'        => 'integer',
							'description' => 'Usage limit (0 = unlimited).',
						),
						'usage_limit_per_user'        => array(
							'type'        => 'integer',
							'description' => 'Per-user usage limit (0 = unlimited).',
						),
						'free_shipping'               => array(
							'type'        => 'boolean',
							'description' => 'Grants free shipping.',
						),
						'minimum_amount'              => array(
							'type'        => 'number',
							'description' => 'Minimum spend.',
						),
						'maximum_amount'              => array(
							'type'        => 'number',
							'description' => 'Maximum spend.',
						),
						'individual_use'              => array(
							'type'        => 'boolean',
							'description' => 'Individual use only.',
						),
						'exclude_sale_items'          => array(
							'type'        => 'boolean',
							'description' => 'Exclude sale items.',
						),
						'product_ids'                 => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Allowed product IDs.',
						),
						'excluded_product_ids'        => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Excluded product IDs.',
						),
						'product_categories'          => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Allowed category IDs.',
						),
						'excluded_product_categories' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Excluded category IDs.',
						),
						'email_restrictions'          => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Allowed emails.',
						),
						'auto_apply'                  => array(
							'type'        => 'boolean',
							'description' => 'Auto-apply enabled.',
						),
						'start_date'                  => array(
							'type'        => 'string',
							'description' => 'Start date (YYYY-MM-DD) or empty.',
						),
						'expiry_date'                 => array(
							'type'        => 'string',
							'description' => 'Expiry date (YYYY-MM-DD) or empty.',
						),
						'rules_enabled'               => array(
							'type'        => 'boolean',
							'description' => 'Conditional rules enabled.',
						),
						'rule_groups'                 => array(
							'type'        => 'array',
							'description' => 'Conditional rule groups.',
						),
						'url_edit'                    => array(
							'type'        => 'string',
							'format'      => 'uri',
							'description' => 'Admin edit URL.',
						),
					),
				),
				'execute_callback'    => function ( $input ) use ( $pc_ability ) {
					return $pc_ability->get_coupon( $input );
				},
				'meta'                => array(
					'annotations' => array(
						'priority'        => 1.0,
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			),

			// Create coupon.
			POWER_COUPONS_ABILITY_API_NAMESPACE . 'create-coupon' => array(
				'label'               => __( 'Create coupon', 'power-coupons' ),
				'description'         => __( 'Creates a new WooCommerce coupon with optional Power Coupons extensions (auto-apply, start date). Returns the new coupon ID and edit URL.', 'power-coupons' ),
				'category'            => 'power-coupons',
				'permission_callback' => function () use ( $pc_ability ) {
					return (
						$pc_ability->permission_callback( 'manage_woocommerce' )
					);
				},
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'code' ),
					'properties' => array(
						'code'                        => array(
							'type'        => 'string',
							'description' => 'Coupon code (unique, lowercase).',
						),
						'description'                 => array(
							'type'        => 'string',
							'description' => 'Coupon description.',
						),
						'discount_type'               => array(
							'type'        => 'string',
							'description' => 'Discount type.',
							'enum'        => array( 'percent', 'fixed_cart', 'fixed_product' ),
							'default'     => 'fixed_cart',
						),
						'amount'                      => array(
							'type'        => 'number',
							'description' => 'Discount amount.',
							'default'     => 0,
						),
						'free_shipping'               => array(
							'type'        => 'boolean',
							'description' => 'Grant free shipping.',
							'default'     => false,
						),
						'individual_use'              => array(
							'type'        => 'boolean',
							'description' => 'Individual use only.',
							'default'     => false,
						),
						'exclude_sale_items'          => array(
							'type'        => 'boolean',
							'description' => 'Exclude sale items.',
							'default'     => false,
						),
						'minimum_amount'              => array(
							'type'        => 'number',
							'description' => 'Minimum spend.',
							'default'     => 0,
						),
						'maximum_amount'              => array(
							'type'        => 'number',
							'description' => 'Maximum spend (0 = no limit).',
							'default'     => 0,
						),
						'usage_limit'                 => array(
							'type'        => 'integer',
							'description' => 'Usage limit (0 = unlimited).',
							'default'     => 0,
						),
						'usage_limit_per_user'        => array(
							'type'        => 'integer',
							'description' => 'Per-user usage limit (0 = unlimited).',
							'default'     => 0,
						),
						'product_ids'                 => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Restrict to product IDs.',
						),
						'excluded_product_ids'        => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Exclude product IDs.',
						),
						'product_categories'          => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Restrict to category IDs.',
						),
						'excluded_product_categories' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Exclude category IDs.',
						),
						'email_restrictions'          => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Restrict to emails.',
						),
						'expiry_date'                 => array(
							'type'        => 'string',
							'format'      => 'date',
							'description' => 'Expiry date (YYYY-MM-DD).',
						),
						'auto_apply'                  => array(
							'type'        => 'boolean',
							'description' => 'Enable auto-apply.',
							'default'     => false,
						),
						'start_date'                  => array(
							'type'        => 'string',
							'format'      => 'date',
							'description' => 'Start date (YYYY-MM-DD).',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array(
							'type'        => 'integer',
							'description' => 'New coupon ID.',
						),
						'code'     => array(
							'type'        => 'string',
							'description' => 'Coupon code.',
						),
						'url_edit' => array(
							'type'        => 'string',
							'format'      => 'uri',
							'description' => 'Admin edit URL.',
						),
					),
				),
				'execute_callback'    => function ( $input ) use ( $pc_ability ) {
					return $pc_ability->create_coupon( $input );
				},
				'meta'                => array(
					'annotations' => array(
						'priority'        => 2.0,
						'readOnlyHint'    => false,
						'destructiveHint' => false,
						'idempotentHint'  => false,
						'openWorldHint'   => false,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			),

			// Update coupon.
			POWER_COUPONS_ABILITY_API_NAMESPACE . 'update-coupon' => array(
				'label'               => __( 'Update coupon', 'power-coupons' ),
				'description'         => __( 'Updates an existing coupon. Only provided fields are changed. Returns the updated coupon data.', 'power-coupons' ),
				'category'            => 'power-coupons',
				'permission_callback' => function () use ( $pc_ability ) {
					return (
						$pc_ability->permission_callback( 'manage_woocommerce' )
					);
				},
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id'                          => array(
							'type'        => 'integer',
							'description' => 'Coupon ID to update.',
						),
						'code'                        => array(
							'type'        => 'string',
							'description' => 'New coupon code.',
						),
						'description'                 => array(
							'type'        => 'string',
							'description' => 'Coupon description.',
						),
						'discount_type'               => array(
							'type'        => 'string',
							'description' => 'Discount type.',
							'enum'        => array( 'percent', 'fixed_cart', 'fixed_product' ),
						),
						'amount'                      => array(
							'type'        => 'number',
							'description' => 'Discount amount.',
						),
						'free_shipping'               => array(
							'type'        => 'boolean',
							'description' => 'Grant free shipping.',
						),
						'individual_use'              => array(
							'type'        => 'boolean',
							'description' => 'Individual use only.',
						),
						'exclude_sale_items'          => array(
							'type'        => 'boolean',
							'description' => 'Exclude sale items.',
						),
						'minimum_amount'              => array(
							'type'        => 'number',
							'description' => 'Minimum spend.',
						),
						'maximum_amount'              => array(
							'type'        => 'number',
							'description' => 'Maximum spend (0 = no limit).',
						),
						'usage_limit'                 => array(
							'type'        => 'integer',
							'description' => 'Usage limit (0 = unlimited).',
						),
						'usage_limit_per_user'        => array(
							'type'        => 'integer',
							'description' => 'Per-user usage limit (0 = unlimited).',
						),
						'product_ids'                 => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Restrict to product IDs.',
						),
						'excluded_product_ids'        => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Exclude product IDs.',
						),
						'product_categories'          => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Restrict to category IDs.',
						),
						'excluded_product_categories' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Exclude category IDs.',
						),
						'email_restrictions'          => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Restrict to emails.',
						),
						'expiry_date'                 => array(
							'type'        => 'string',
							'format'      => 'date',
							'description' => 'Expiry date (YYYY-MM-DD). Empty to clear.',
						),
						'auto_apply'                  => array(
							'type'        => 'boolean',
							'description' => 'Enable/disable auto-apply.',
						),
						'start_date'                  => array(
							'type'        => 'string',
							'format'      => 'date',
							'description' => 'Start date (YYYY-MM-DD). Empty to clear.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array(
							'type'        => 'integer',
							'description' => 'Coupon ID.',
						),
						'code'     => array(
							'type'        => 'string',
							'description' => 'Coupon code.',
						),
						'url_edit' => array(
							'type'        => 'string',
							'format'      => 'uri',
							'description' => 'Admin edit URL.',
						),
						'message'  => array(
							'type'        => 'string',
							'description' => 'Result message.',
						),
					),
				),
				'execute_callback'    => function ( $input ) use ( $pc_ability ) {
					return $pc_ability->update_coupon( $input );
				},
				'meta'                => array(
					'annotations' => array(
						'priority'        => 2.0,
						'readOnlyHint'    => false,
						'destructiveHint' => false,
						'idempotentHint'  => false,
						'openWorldHint'   => false,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			),

			// Delete coupon.
			POWER_COUPONS_ABILITY_API_NAMESPACE . 'delete-coupon' => array(
				'label'               => __( 'Delete coupon', 'power-coupons' ),
				'description'         => __( 'Moves a coupon to trash or permanently deletes it by ID.', 'power-coupons' ),
				'category'            => 'power-coupons',
				'permission_callback' => function () use ( $pc_ability ) {
					return (
						$pc_ability->permission_callback( 'manage_woocommerce' )
					);
				},
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id'        => array(
							'type'        => 'integer',
							'description' => 'The coupon ID.',
						),
						'permanent' => array(
							'type'        => 'boolean',
							'description' => 'Permanently delete if true, trash if false.',
							'default'     => false,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'        => array(
							'type'        => 'integer',
							'description' => 'Deleted coupon ID.',
						),
						'permanent' => array(
							'type'        => 'boolean',
							'description' => 'Whether permanently deleted.',
						),
						'message'   => array(
							'type'        => 'string',
							'description' => 'Result message.',
						),
					),
				),
				'execute_callback'    => function ( $input ) use ( $pc_ability ) {
					return $pc_ability->delete_coupon( $input );
				},
				'meta'                => array(
					'annotations' => array(
						'priority'        => 3.0,
						'readOnlyHint'    => false,
						'destructiveHint' => true,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			),

			// Toggle auto-apply.
			POWER_COUPONS_ABILITY_API_NAMESPACE . 'toggle-auto-apply' => array(
				'label'               => __( 'Toggle auto-apply', 'power-coupons' ),
				'description'         => __( 'Enables or disables auto-apply on a coupon. When enabled, the coupon is automatically applied to qualifying carts.', 'power-coupons' ),
				'category'            => 'power-coupons',
				'permission_callback' => function () use ( $pc_ability ) {
					return (
						$pc_ability->permission_callback( 'manage_woocommerce' )
					);
				},
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'id', 'enabled' ),
					'properties' => array(
						'id'      => array(
							'type'        => 'integer',
							'description' => 'The coupon ID.',
						),
						'enabled' => array(
							'type'        => 'boolean',
							'description' => 'Whether to enable auto-apply.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array(
							'type'        => 'integer',
							'description' => 'Coupon ID.',
						),
						'enabled' => array(
							'type'        => 'boolean',
							'description' => 'New auto-apply state.',
						),
						'message' => array(
							'type'        => 'string',
							'description' => 'Result message.',
						),
					),
				),
				'execute_callback'    => function ( $input ) use ( $pc_ability ) {
					return $pc_ability->toggle_auto_apply( $input );
				},
				'meta'                => array(
					'annotations' => array(
						'priority'        => 2.0,
						'readOnlyHint'    => false,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			),

			// Get coupon shortcode.
			POWER_COUPONS_ABILITY_API_NAMESPACE . 'get-coupon-shortcode' => array(
				'label'               => __( 'Get coupon shortcode', 'power-coupons' ),
				'description'         => __( 'Returns the shortcode string for embedding a specific coupon display on a page or post.', 'power-coupons' ),
				'category'            => 'power-coupons',
				'permission_callback' => function () use ( $pc_ability ) {
					return $pc_ability->permission_callback( 'manage_woocommerce' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'The coupon ID.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'shortcode' => array(
							'type'        => 'string',
							'description' => 'The shortcode string.',
						),
					),
				),
				'execute_callback'    => function ( $input ) use ( $pc_ability ) {
					return $pc_ability->get_coupon_shortcode( $input );
				},
				'meta'                => array(
					'annotations' => array(
						'priority'        => 1.0,
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			),

			// ============================================
			// Conditional Rules
			// ============================================

			// Get coupon rules.
			POWER_COUPONS_ABILITY_API_NAMESPACE . 'get-coupon-rules' => array(
				'label'               => __( 'Get coupon rules', 'power-coupons' ),
				'description'         => __( 'Returns the conditional rule groups for a coupon. Rules use OR logic between groups and AND logic within each group. Rule types: cart_total, cart_items, products, product_categories.', 'power-coupons' ),
				'category'            => 'power-coupons',
				'permission_callback' => function () use ( $pc_ability ) {
					return $pc_ability->permission_callback( 'manage_woocommerce' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'The coupon ID.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'            => array(
							'type'        => 'integer',
							'description' => 'Coupon ID.',
						),
						'rules_enabled' => array(
							'type'        => 'boolean',
							'description' => 'Whether conditional rules are enabled.',
						),
						'groups'        => array(
							'type'        => 'array',
							'description' => 'Rule groups (OR between groups).',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'group_id' => array(
										'type'        => 'string',
										'description' => 'Group identifier.',
									),
									'rules'    => array(
										'type'  => 'array',
										'items' => array(
											'type'       => 'object',
											'properties' => array(
												'rule_id'  => array(
													'type' => 'string',
													'description' => 'Rule identifier.',
												),
												'type'     => array(
													'type' => 'string',
													'description' => 'Rule type (cart_total, cart_items, products, product_categories).',
												),
												'operator' => array(
													'type' => 'string',
													'description' => 'Comparison operator.',
												),
												'value'    => array(
													'type' => 'number',
													'description' => 'Comparison value.',
												),
											),
										),
									),
								),
							),
						),
					),
				),
				'execute_callback'    => function ( $input ) use ( $pc_ability ) {
					return $pc_ability->get_coupon_rules( $input );
				},
				'meta'                => array(
					'annotations' => array(
						'priority'        => 1.0,
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			),

			// Set coupon rules.
			POWER_COUPONS_ABILITY_API_NAMESPACE . 'set-coupon-rules' => array(
				'label'               => __( 'Set coupon rules', 'power-coupons' ),
				'description'         => __( 'Sets or replaces all conditional rule groups on a coupon. Groups use OR logic between them, AND logic within. Automatically enables rules. Valid types: cart_total, cart_items, products, product_categories.', 'power-coupons' ),
				'category'            => 'power-coupons',
				'permission_callback' => function () use ( $pc_ability ) {
					return (
						$pc_ability->permission_callback( 'manage_woocommerce' )
					);
				},
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'id', 'groups' ),
					'properties' => array(
						'id'     => array(
							'type'        => 'integer',
							'description' => 'The coupon ID.',
						),
						'groups' => array(
							'type'        => 'array',
							'description' => 'Rule groups. Each group has rules evaluated with AND logic. Groups are evaluated with OR logic.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'rules' => array(
										'type'  => 'array',
										'items' => array(
											'type'       => 'object',
											'required'   => array( 'type', 'operator', 'value' ),
											'properties' => array(
												'type'     => array(
													'type' => 'string',
													'description' => 'Rule type.',
													'enum' => array( 'cart_total', 'cart_items', 'products', 'product_categories' ),
												),
												'operator' => array(
													'type' => 'string',
													'description' => 'Comparison operator. For cart_total/cart_items: equal_to, not_equal_to, less_than, less_than_or_equal, greater_than, greater_than_or_equal. For products/product_categories: in_list, not_in_list.',
												),
												'value'    => array(
													'type' => 'number',
													'description' => 'Comparison value (amount for cart_total, count for cart_items, ID for products/product_categories).',
												),
											),
										),
									),
								),
							),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array(
							'type'        => 'integer',
							'description' => 'Coupon ID.',
						),
						'groups'  => array(
							'type'        => 'integer',
							'description' => 'Number of groups saved.',
						),
						'message' => array(
							'type'        => 'string',
							'description' => 'Result message.',
						),
					),
				),
				'execute_callback'    => function ( $input ) use ( $pc_ability ) {
					return $pc_ability->set_coupon_rules( $input );
				},
				'meta'                => array(
					'annotations' => array(
						'priority'        => 2.0,
						'readOnlyHint'    => false,
						'destructiveHint' => false,
						'idempotentHint'  => false,
						'openWorldHint'   => false,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			),

			// Toggle coupon rules.
			POWER_COUPONS_ABILITY_API_NAMESPACE . 'toggle-coupon-rules' => array(
				'label'               => __( 'Toggle coupon rules', 'power-coupons' ),
				'description'         => __( 'Enables or disables conditional rules on a coupon without modifying the rules themselves.', 'power-coupons' ),
				'category'            => 'power-coupons',
				'permission_callback' => function () use ( $pc_ability ) {
					return (
						$pc_ability->permission_callback( 'manage_woocommerce' )
					);
				},
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'id', 'enabled' ),
					'properties' => array(
						'id'      => array(
							'type'        => 'integer',
							'description' => 'The coupon ID.',
						),
						'enabled' => array(
							'type'        => 'boolean',
							'description' => 'Whether to enable conditional rules.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array(
							'type'        => 'integer',
							'description' => 'Coupon ID.',
						),
						'enabled' => array(
							'type'        => 'boolean',
							'description' => 'New rules state.',
						),
						'message' => array(
							'type'        => 'string',
							'description' => 'Result message.',
						),
					),
				),
				'execute_callback'    => function ( $input ) use ( $pc_ability ) {
					return $pc_ability->toggle_coupon_rules( $input );
				},
				'meta'                => array(
					'annotations' => array(
						'priority'        => 2.0,
						'readOnlyHint'    => false,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			),

			// Validate coupon rules.
			POWER_COUPONS_ABILITY_API_NAMESPACE . 'validate-coupon-rules' => array(
				'label'               => __( 'Validate coupon rules', 'power-coupons' ),
				'description'         => __( 'Checks whether a coupon\'s conditional rules are satisfied for the current cart state. Returns pass/fail for each rule group and individual rule. Requires an active WooCommerce cart session.', 'power-coupons' ),
				'category'            => 'power-coupons',
				'permission_callback' => function () use ( $pc_ability ) {
					return $pc_ability->permission_callback( 'manage_woocommerce' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'The coupon ID.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'            => array(
							'type'        => 'integer',
							'description' => 'Coupon ID.',
						),
						'rules_enabled' => array(
							'type'        => 'boolean',
							'description' => 'Whether rules are enabled.',
						),
						'valid'         => array(
							'type'        => 'boolean',
							'description' => 'Whether all rules pass (true if rules disabled).',
						),
						'groups'        => array(
							'type'        => 'array',
							'description' => 'Per-group validation results.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'group_id' => array(
										'type'        => 'string',
										'description' => 'Group identifier.',
									),
									'passed'   => array(
										'type'        => 'boolean',
										'description' => 'Whether this group passed (all rules AND).',
									),
									'rules'    => array(
										'type'  => 'array',
										'items' => array(
											'type'       => 'object',
											'properties' => array(
												'type'     => array(
													'type' => 'string',
													'description' => 'Rule type.',
												),
												'operator' => array(
													'type' => 'string',
													'description' => 'Operator.',
												),
												'expected' => array(
													'type' => 'number',
													'description' => 'Expected value.',
												),
												'actual'   => array(
													'type' => 'number',
													'description' => 'Actual cart value.',
												),
												'passed'   => array(
													'type' => 'boolean',
													'description' => 'Whether this rule passed.',
												),
											),
										),
									),
								),
							),
						),
					),
				),
				'execute_callback'    => function ( $input ) use ( $pc_ability ) {
					return $pc_ability->validate_coupon_rules( $input );
				},
				'meta'                => array(
					'annotations' => array(
						'priority'        => 1.0,
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			),

			// ============================================
			// Settings & Configuration
			// ============================================

			// Get settings.
			POWER_COUPONS_ABILITY_API_NAMESPACE . 'get-settings' => array(
				'label'               => __( 'Get settings', 'power-coupons' ),
				'description'         => __( 'Returns all Power Coupons plugin settings organized by section: general, coupon_styling, and text.', 'power-coupons' ),
				'category'            => 'power-coupons',
				'permission_callback' => function () use ( $pc_ability ) {
					return $pc_ability->permission_callback( 'manage_woocommerce' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'section' => array(
							'type'        => 'string',
							'description' => 'Return only a specific section. Leave empty for all.',
							'enum'        => array( 'general', 'coupon_styling', 'text', 'all' ),
							'default'     => 'all',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'general'        => array(
							'type'        => 'object',
							'description' => 'General settings (enable_plugin, show_on_cart, show_on_checkout, etc.).',
						),
						'coupon_styling' => array(
							'type'        => 'object',
							'description' => 'Coupon styling settings (coupon_style).',
						),
						'text'           => array(
							'type'        => 'object',
							'description' => 'Text/label settings (drawer_heading, trigger_button_label, etc.).',
						),
					),
				),
				'execute_callback'    => function ( $input ) use ( $pc_ability ) {
					return $pc_ability->get_settings( $input );
				},
				'meta'                => array(
					'annotations' => array(
						'priority'        => 1.0,
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			),

			// Update settings.
			POWER_COUPONS_ABILITY_API_NAMESPACE . 'update-settings' => array(
				'label'               => __( 'Update settings', 'power-coupons' ),
				'description'         => __( 'Updates Power Coupons plugin settings. Supports partial updates — only provided fields are changed. Settings are grouped by section: general, coupon_styling, text.', 'power-coupons' ),
				'category'            => 'power-coupons',
				'permission_callback' => function () use ( $pc_ability ) {
					return (
						$pc_ability->permission_callback( 'manage_woocommerce' )
					);
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'general'        => array(
							'type'        => 'object',
							'description' => 'General settings to update.',
							'properties'  => array(
								'enable_plugin'        => array(
									'type'        => 'boolean',
									'description' => 'Enable/disable the plugin.',
								),
								'show_on_cart'         => array(
									'type'        => 'boolean',
									'description' => 'Show coupons on cart page.',
								),
								'show_on_checkout'     => array(
									'type'        => 'boolean',
									'description' => 'Show coupons on checkout page.',
								),
								'enable_for_guests'    => array(
									'type'        => 'boolean',
									'description' => 'Enable for guest users.',
								),
								'show_applied_coupons' => array(
									'type'        => 'boolean',
									'description' => 'Show already-applied coupons.',
								),
								'show_expiry_info'     => array(
									'type'        => 'boolean',
									'description' => 'Show expiry date on coupons.',
								),
							),
						),
						'coupon_styling' => array(
							'type'        => 'object',
							'description' => 'Coupon styling settings to update.',
							'properties'  => array(
								'coupon_style' => array(
									'type'        => 'string',
									'description' => 'Coupon card style.',
									'enum'        => array( 'style-1', 'style-2' ),
								),
							),
						),
						'text'           => array(
							'type'        => 'object',
							'description' => 'Text/label settings to update.',
							'properties'  => array(
								'drawer_heading'       => array(
									'type'        => 'string',
									'description' => 'Drawer heading text.',
								),
								'trigger_button_label' => array(
									'type'        => 'string',
									'description' => 'Trigger button label.',
								),
								'coupon_applying_text' => array(
									'type'        => 'string',
									'description' => 'Text shown while applying.',
								),
								'coupon_applied_text'  => array(
									'type'        => 'string',
									'description' => 'Text shown after applied.',
								),
								'no_coupons_text'      => array(
									'type'        => 'string',
									'description' => 'No coupons available text.',
								),
								'coupons_loading_text' => array(
									'type'        => 'string',
									'description' => 'Loading coupons text.',
								),
							),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'message'  => array(
							'type'        => 'string',
							'description' => 'Result message.',
						),
						'settings' => array(
							'type'        => 'object',
							'description' => 'Updated settings.',
						),
					),
				),
				'execute_callback'    => function ( $input ) use ( $pc_ability ) {
					return $pc_ability->update_settings( $input );
				},
				'meta'                => array(
					'annotations' => array(
						'priority'        => 2.0,
						'readOnlyHint'    => false,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			),

			// ============================================
			// Coupon Display & Application
			// ============================================

			// List available coupons.
			POWER_COUPONS_ABILITY_API_NAMESPACE . 'list-available-coupons' => array(
				'label'               => __( 'List available coupons', 'power-coupons' ),
				'description'         => __( 'Returns coupons currently valid for the active WooCommerce cart. Filters by validity, expiry, start date, and conditional rules. Requires an active cart session.', 'power-coupons' ),
				'category'            => 'power-coupons',
				'permission_callback' => function () use ( $pc_ability ) {
					return $pc_ability->permission_callback( 'manage_woocommerce' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'coupons' => array(
							'type'        => 'array',
							'description' => 'Available coupons.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'id'          => array(
										'type'        => 'integer',
										'description' => 'Coupon ID.',
									),
									'code'        => array(
										'type'        => 'string',
										'description' => 'Coupon code.',
									),
									'description' => array(
										'type'        => 'string',
										'description' => 'Coupon description.',
									),
									'amount'      => array(
										'type'        => 'number',
										'description' => 'Discount amount.',
									),
									'type'        => array(
										'type'        => 'string',
										'description' => 'Discount type.',
									),
									'type_text'   => array(
										'type'        => 'string',
										'description' => 'Formatted discount (e.g., "10%" or "$5.00").',
									),
									'auto_apply'  => array(
										'type'        => 'boolean',
										'description' => 'Whether auto-apply is enabled.',
									),
									'is_applied'  => array(
										'type'        => 'boolean',
										'description' => 'Whether currently applied to cart.',
									),
									'expiry_date' => array(
										'type'        => 'string',
										'description' => 'Expiry date or empty.',
									),
								),
							),
						),
						'total'   => array(
							'type'        => 'integer',
							'description' => 'Total available coupons.',
						),
					),
				),
				'execute_callback'    => function ( $input ) use ( $pc_ability ) {
					return $pc_ability->list_available_coupons( $input );
				},
				'meta'                => array(
					'annotations' => array(
						'priority'        => 1.0,
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			),

			// Get applied coupons.
			POWER_COUPONS_ABILITY_API_NAMESPACE . 'get-applied-coupons' => array(
				'label'               => __( 'Get applied coupons', 'power-coupons' ),
				'description'         => __( 'Returns coupons currently applied to the WooCommerce cart with discount amounts. Requires an active cart session.', 'power-coupons' ),
				'category'            => 'power-coupons',
				'permission_callback' => function () use ( $pc_ability ) {
					return $pc_ability->permission_callback( 'manage_woocommerce' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'coupons'    => array(
							'type'        => 'array',
							'description' => 'Applied coupons.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'code'            => array(
										'type'        => 'string',
										'description' => 'Coupon code.',
									),
									'discount_amount' => array(
										'type'        => 'number',
										'description' => 'Discount amount applied.',
									),
									'discount_type'   => array(
										'type'        => 'string',
										'description' => 'Discount type.',
									),
								),
							),
						),
						'total'      => array(
							'type'        => 'integer',
							'description' => 'Number of applied coupons.',
						),
						'cart_total' => array(
							'type'        => 'number',
							'description' => 'Current cart total.',
						),
					),
				),
				'execute_callback'    => function ( $input ) use ( $pc_ability ) {
					return $pc_ability->get_applied_coupons( $input );
				},
				'meta'                => array(
					'annotations' => array(
						'priority'        => 1.0,
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			),

			// Apply coupon.
			POWER_COUPONS_ABILITY_API_NAMESPACE . 'apply-coupon' => array(
				'label'               => __( 'Apply coupon', 'power-coupons' ),
				'description'         => __( 'Applies a coupon code to the current WooCommerce cart. Returns the discount amount and updated cart total. Requires an active cart session.', 'power-coupons' ),
				'category'            => 'power-coupons',
				'permission_callback' => function () use ( $pc_ability ) {
					return (
						$pc_ability->permission_callback( 'manage_woocommerce' )
					);
				},
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'code' ),
					'properties' => array(
						'code' => array(
							'type'        => 'string',
							'description' => 'The coupon code to apply.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'code'       => array(
							'type'        => 'string',
							'description' => 'Applied coupon code.',
						),
						'applied'    => array(
							'type'        => 'boolean',
							'description' => 'Whether the coupon was applied.',
						),
						'message'    => array(
							'type'        => 'string',
							'description' => 'Result or error message.',
						),
						'cart_total' => array(
							'type'        => 'number',
							'description' => 'Updated cart total.',
						),
					),
				),
				'execute_callback'    => function ( $input ) use ( $pc_ability ) {
					return $pc_ability->apply_coupon( $input );
				},
				'meta'                => array(
					'annotations' => array(
						'priority'        => 2.0,
						'readOnlyHint'    => false,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			),

			// Remove coupon.
			POWER_COUPONS_ABILITY_API_NAMESPACE . 'remove-coupon' => array(
				'label'               => __( 'Remove coupon', 'power-coupons' ),
				'description'         => __( 'Removes an applied coupon from the WooCommerce cart. Cannot remove auto-applied coupons. Requires an active cart session.', 'power-coupons' ),
				'category'            => 'power-coupons',
				'permission_callback' => function () use ( $pc_ability ) {
					return (
						$pc_ability->permission_callback( 'manage_woocommerce' )
					);
				},
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'code' ),
					'properties' => array(
						'code' => array(
							'type'        => 'string',
							'description' => 'The coupon code to remove.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'code'       => array(
							'type'        => 'string',
							'description' => 'Removed coupon code.',
						),
						'removed'    => array(
							'type'        => 'boolean',
							'description' => 'Whether the coupon was removed.',
						),
						'message'    => array(
							'type'        => 'string',
							'description' => 'Result message.',
						),
						'cart_total' => array(
							'type'        => 'number',
							'description' => 'Updated cart total.',
						),
					),
				),
				'execute_callback'    => function ( $input ) use ( $pc_ability ) {
					return $pc_ability->remove_coupon( $input );
				},
				'meta'                => array(
					'annotations' => array(
						'priority'        => 2.0,
						'readOnlyHint'    => false,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			),
		);

		/**
		 * Filter ability configurations.
		 *
		 * @since 1.1.0
		 * @param array $abilities Ability definitions.
		 */
		$abilities = apply_filters( 'power_coupons_config_abilities', $abilities );

		if ( ! is_array( $abilities ) ) {
			$abilities = array();
		}

		self::$abilities = $abilities;

		return $abilities;
	}

	/**
	 * Get a single ability config by name.
	 *
	 * @since 1.1.0
	 * @param string $ability_name Ability identifier (e.g., 'power-coupons/list-coupons').
	 * @return array<string, mixed>|false Ability config or false if not found.
	 */
	public static function get_ability( $ability_name ) {
		if ( false === self::$abilities ) {
			self::$abilities = self::get_abilities();
		}
		if ( ! isset( self::$abilities[ $ability_name ] ) ) {
			return false;
		}
		/**
		 * Return type assertion.
		 *
		 * @var array<string, mixed>
		 */
		return self::$abilities[ $ability_name ];
	}

	/**
	 * Get ability input schema.
	 *
	 * @since 1.1.0
	 * @param string $ability_name Ability identifier.
	 * @return array<string, mixed>|false Input schema or false if not found.
	 */
	public static function get_ability_input_schema( $ability_name ) {
		$ability = self::get_ability( $ability_name );
		if ( false === $ability ) {
			return false;
		}
		if ( ! isset( $ability['input_schema'] ) || ! is_array( $ability['input_schema'] ) ) {
			return false;
		}
		return $ability['input_schema'];
	}

	/**
	 * Get ability output schema.
	 *
	 * @since 1.1.0
	 * @param string $ability_name Ability identifier.
	 * @return array<string, mixed>|false Output schema or false if not found.
	 */
	public static function get_ability_output_schema( $ability_name ) {
		$ability = self::get_ability( $ability_name );
		if ( false === $ability ) {
			return false;
		}
		if ( ! isset( $ability['output_schema'] ) || ! is_array( $ability['output_schema'] ) ) {
			return false;
		}
		return $ability['output_schema'];
	}
}
