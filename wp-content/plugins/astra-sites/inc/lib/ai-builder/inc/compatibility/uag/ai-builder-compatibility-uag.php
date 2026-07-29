<?php
/**
 * AI Builder Compatibility for 'UAG'
 *
 * @see  https://wordpress.org/plugins/ultimate-addons-for-gutenberg/
 *
 * @package AI Builder
 * @since 3.0.15
 */

/**
 * UAG compatibility for Starter Templates.
 */
class Ai_Builder_Compatibility_UAG {
	/**
	 * Instance
	 *
	 * @access private
	 * @var object Class object.
	 * @since 3.0.15
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'astra_sites_after_plugin_activation', array( $this, 'uag_activation' ), 10 );
		// Remove the filter and function once Spectra Blocks is available on wordpress.org.
		add_filter( 'plugins_api', array( $this, 'override_spectra_blocks_download' ), 10, 3 );
	}

	/**
	 * Initiator
	 *
	 * @since 3.0.15
	 * @return object initialized object of class.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Override the download link for spectra-blocks plugin during import.
	 *
	 * Spectra Blocks is not yet available on wordpress.org, so we redirect
	 * the download to a temporary hosted URL during the import process.
	 *
	 * @since 1.2.81
	 * @param false|object|array<string, mixed> $result The result object or array. Default false.
	 * @param string                            $action The type of information being requested from the Plugin Installation API.
	 * @param object                            $args   Plugin API arguments.
	 * @return false|object|array<string, mixed> Modified result.
	 */
	public function override_spectra_blocks_download( $result, $action, $args ) {
		if ( true !== astra_sites_has_import_started() || 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || 'spectra-blocks' !== $args->slug ) {
			return $result;
		}

		if ( ! is_object( $result ) ) {
			$result = new \stdClass();
		}

		$result->name          = 'Spectra Blocks'; // @phpstan-ignore-line -- Dynamic properties on stdClass for WP plugins_api response.
		$result->slug          = 'spectra-blocks'; // @phpstan-ignore-line
		$result->version       = '0.0.8'; // @phpstan-ignore-line
		$result->download_link = 'https://wpspectra.com/wp-content/uploads/2026/06/spectra-blocks.0.0.8.zip'; // @phpstan-ignore-line

		return $result;
	}

	/**
	 * Disable redirect after installing and activating UAG or Spectra Blocks.
	 *
	 * @since 3.0.15
	 * @param string $plugin_init The path to the plugin file that was just activated.
	 * @return void
	 */
	public function uag_activation( $plugin_init ) {
		if ( 'ultimate-addons-for-gutenberg/ultimate-addons-for-gutenberg.php' === $plugin_init
			|| 'spectra-blocks/spectra-blocks.php' === $plugin_init ) {
			update_option( '__uagb_do_redirect', false );
			update_option( 'spectra_onboarding', array( 'status' => 'completed' ) );
		}
	}
}

/**
 * Kicking this off by calling 'get_instance()' method
 */
Ai_Builder_Compatibility_UAG::get_instance();
