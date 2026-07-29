<?php
/**
 * Settings page (Settings API).
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Admin;

use Yazan\PaymentBridge\Contracts\Hookable;
use Yazan\PaymentBridge\Security\Capabilities;
use Yazan\PaymentBridge\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the Bridge's settings through the WordPress Settings
 * API, which supplies the nonce and the sanitize callback (H5).
 */
final class SettingsPage implements Hookable {

	/** Menu slug. */
	public const SLUG = 'yazan-payment-bridge-settings';

	/** Settings section id. */
	private const SECTION = 'yazan_pb_main';

	/**
	 * @param Settings $settings Settings reader/sanitiser.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array(
				'type'   => 'action',
				'hook'   => 'admin_init',
				'method' => 'register',
				'args'   => 0,
			),
		);
	}

	/**
	 * Register the option, section and fields.
	 *
	 * @return void
	 */
	public function register(): void {
		register_setting(
			Settings::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			self::SECTION,
			__( 'Integrations', 'yazan-payment-bridge' ),
			static function () {
				echo '<p>' . esc_html__( 'The Bridge announces verified payments to the YAZAN ownership and warranty seams. It stores no ownership data itself.', 'yazan-payment-bridge' ) . '</p>';
			},
			Settings::GROUP
		);

		$this->add_checkbox( 'enable_ownership', __( 'Enable ownership integration', 'yazan-payment-bridge' ), __( 'Announce completed payments and full refunds to the ownership seam.', 'yazan-payment-bridge' ) );
		$this->add_checkbox( 'enable_warranty', __( 'Enable warranty integration', 'yazan-payment-bridge' ), __( 'Announce completed payments and full refunds to the warranty seam.', 'yazan-payment-bridge' ) );

		add_settings_field(
			'sku_pattern',
			__( 'Eligible SKU pattern', 'yazan-payment-bridge' ),
			array( $this, 'render_sku_pattern' ),
			Settings::GROUP,
			self::SECTION
		);

		$this->add_checkbox( 'debug_logging', __( 'Enable debug logging', 'yazan-payment-bridge' ), __( 'Adds debug-level entries to the WooCommerce log (source: yazan-payment-bridge). Never records personal data. Off by default.', 'yazan-payment-bridge' ) );
		$this->add_checkbox( 'delete_data_on_uninstall', __( 'Delete all data on uninstall', 'yazan-payment-bridge' ), __( 'Off by default: the payment-event ledger is kept for accounting and legal retention even if the plugin is removed.', 'yazan-payment-bridge' ) );
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'yazan-payment-bridge' ), 403 );
		}

		echo '<div class="wrap yazan-pb-wrap">';
		echo '<h1>' . esc_html__( 'Payment Bridge Settings', 'yazan-payment-bridge' ) . '</h1>';

		settings_errors( Settings::OPTION );

		echo '<form method="post" action="options.php">';
		settings_fields( Settings::GROUP );
		do_settings_sections( Settings::GROUP );
		submit_button();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render the SKU pattern field.
	 *
	 * @return void
	 */
	public function render_sku_pattern(): void {
		$value = (string) $this->settings->get( 'sku_pattern', '' );

		printf(
			'<input type="text" class="regular-text code" name="%1$s[sku_pattern]" value="%2$s" />',
			esc_attr( Settings::OPTION ),
			esc_attr( $value )
		);

		echo '<p class="description">';
		esc_html_e( 'A regular expression, anchored with ^ and $, matched against the uppercased product SKU. A product also qualifies if it carries a YAZAN serial (_yz_serial), so this may be left empty.', 'yazan-payment-bridge' );
		echo '</p>';
	}

	/**
	 * Register a checkbox field.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Field label.
	 * @param string $description Help text.
	 * @return void
	 */
	private function add_checkbox( string $key, string $label, string $description ): void {
		add_settings_field(
			$key,
			$label,
			function () use ( $key, $description ) {
				printf(
					'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
					esc_attr( Settings::OPTION ),
					esc_attr( $key ),
					checked( $this->settings->is_enabled( $key ), true, false ),
					esc_html( $description )
				);
			},
			Settings::GROUP,
			self::SECTION
		);
	}
}
