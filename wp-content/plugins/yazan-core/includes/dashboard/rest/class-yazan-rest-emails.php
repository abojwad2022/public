<?php
/**
 * Yazan Dashboard — WooCommerce transactional emails.
 *
 * Two layers: the GLOBAL sender/branding options (from name/address, header image, footer text,
 * colours) and the PER-EMAIL settings (enabled, recipient, subject, heading, extra content, format).
 *
 * Per-email keys are filtered against each email's own `form_fields`, so a caller can only write
 * settings that email actually declares — the same allow-list discipline used for settings and
 * shipping methods.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /emails endpoints.
 */
class Yazan_REST_Emails {

	/** Email configuration is a store-owner level action. */
	const CAP = 'manage_woocommerce';

	/** Per-email settings that may be written. */
	const EDITABLE_KEYS = array( 'enabled', 'recipient', 'subject', 'heading', 'additional_content', 'email_type' );

	/** Global email options that may be written. */
	private static function global_schema() {
		return array(
			'woocommerce_email_from_name'         => array( 'type' => 'text',  'label' => __( '“From” name', 'yazan' ) ),
			'woocommerce_email_from_address'      => array( 'type' => 'email', 'label' => __( '“From” address', 'yazan' ) ),
			'woocommerce_email_header_image'      => array( 'type' => 'url',   'label' => __( 'Header image URL', 'yazan' ) ),
			'woocommerce_email_footer_text'       => array( 'type' => 'textarea', 'label' => __( 'Footer text', 'yazan' ) ),
			'woocommerce_email_base_color'        => array( 'type' => 'color', 'label' => __( 'Base colour', 'yazan' ) ),
			'woocommerce_email_background_color'  => array( 'type' => 'color', 'label' => __( 'Background colour', 'yazan' ) ),
			'woocommerce_email_body_background_color' => array( 'type' => 'color', 'label' => __( 'Body background', 'yazan' ) ),
			'woocommerce_email_text_color'        => array( 'type' => 'color', 'label' => __( 'Body text colour', 'yazan' ) ),
		);
	}

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = Yazan_Dashboard_Auth::NS;

		register_rest_route(
			$ns,
			'/emails',
			array(
				Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'index' ), 'emails.view' ),
				Yazan_REST_Guard::args( WP_REST_Server::EDITABLE, array( __CLASS__, 'update_global' ), 'emails.edit' ),
			)
		);

		register_rest_route(
			$ns,
			'/emails/(?P<id>[a-z0-9_\-]+)',
			Yazan_REST_Guard::args( WP_REST_Server::EDITABLE, array( __CLASS__, 'update_email' ), 'emails.edit' )
		);
	}

	/**
	 * GET /emails.
	 *
	 * @return WP_REST_Response
	 */
	public static function index() {
		$items = array();

		foreach ( WC()->mailer()->get_emails() as $email ) {
			$fields    = $email->get_form_fields();
			$available = array_values( array_intersect( self::EDITABLE_KEYS, array_keys( (array) $fields ) ) );

			$items[] = array(
				'id'          => $email->id,
				'title'       => $email->get_title(),
				'description' => wp_strip_all_tags( (string) $email->get_description() ),
				'enabled'     => $email->is_enabled(),
				'recipient'   => $email->is_customer_email() ? __( 'The customer', 'yazan' ) : $email->get_recipient(),
				'customer_email' => $email->is_customer_email(),
				'subject'     => $email->get_option( 'subject', '' ),
				'heading'     => $email->get_option( 'heading', '' ),
				'additional_content' => $email->get_option( 'additional_content', '' ),
				'email_type'  => $email->get_option( 'email_type', 'html' ),
				'default_subject' => method_exists( $email, 'get_default_subject' ) ? $email->get_default_subject() : '',
				'default_heading' => method_exists( $email, 'get_default_heading' ) ? $email->get_default_heading() : '',
				'editable'    => $available,
			);
		}

		$globals = array();
		foreach ( self::global_schema() as $option => $spec ) {
			$globals[] = array(
				'option' => $option,
				'type'   => $spec['type'],
				'label'  => $spec['label'],
				'value'  => (string) get_option( $option, '' ),
			);
		}

		return new WP_REST_Response( array( 'items' => $items, 'globals' => $globals ), 200 );
	}

	/**
	 * PUT /emails — global sender/branding options.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_global( WP_REST_Request $request ) {
		$incoming = (array) $request->get_param( 'settings' );
		if ( empty( $incoming ) ) {
			return new WP_Error( 'yazan_invalid', __( 'Nothing to save.', 'yazan' ), array( 'status' => 400 ) );
		}
		$schema = self::global_schema();
		$saved  = array();

		foreach ( $incoming as $option => $value ) {
			if ( ! isset( $schema[ $option ] ) ) {
				continue;
			}
			switch ( $schema[ $option ]['type'] ) {
				case 'email':
					$clean = sanitize_email( (string) $value );
					if ( '' !== trim( (string) $value ) && ! is_email( $clean ) ) {
						return new WP_Error( 'yazan_invalid', __( 'That “From” address is not a valid email.', 'yazan' ), array( 'status' => 400 ) );
					}
					break;
				case 'url':
					$clean = esc_url_raw( (string) $value );
					break;
				case 'color':
					$clean = sanitize_hex_color( (string) $value );
					if ( null === $clean || '' === $clean ) {
						return new WP_Error(
							'yazan_invalid',
							sprintf( /* translators: %s: field label */ __( '%s must be a hex colour like #8C2F24.', 'yazan' ), $schema[ $option ]['label'] ),
							array( 'status' => 400 )
						);
					}
					break;
				case 'textarea':
					$clean = wp_kses_post( (string) $value );
					break;
				default:
					$clean = sanitize_text_field( (string) $value );
			}
			update_option( $option, $clean );
			$saved[] = $option;
		}

		if ( empty( $saved ) ) {
			return new WP_Error( 'yazan_invalid', __( 'None of those email settings can be changed here.', 'yazan' ), array( 'status' => 400 ) );
		}

		Yazan_Dashboard_Audit::log( 'email.globals', 'settings', 0, array( 'changed' => $saved ) );

		return self::index();
	}

	/**
	 * PUT /emails/{id} — one email's own settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_email( WP_REST_Request $request ) {
		$id    = sanitize_key( (string) $request['id'] );
		$email = null;
		foreach ( WC()->mailer()->get_emails() as $candidate ) {
			if ( $candidate->id === $id ) {
				$email = $candidate;
			}
		}
		if ( ! $email ) {
			return new WP_Error( 'yazan_not_found', __( 'Email not found.', 'yazan' ), array( 'status' => 404 ) );
		}

		$fields   = (array) $email->get_form_fields();
		$settings = (array) get_option( $email->get_option_key(), array() );
		$changed  = array();

		foreach ( self::EDITABLE_KEYS as $key ) {
			if ( null === $request->get_param( $key ) || ! array_key_exists( $key, $fields ) ) {
				continue;
			}
			$value = $request->get_param( $key );

			if ( 'enabled' === $key ) {
				$clean = wc_string_to_bool( $value ) ? 'yes' : 'no';
			} elseif ( 'recipient' === $key ) {
				// May be a comma-separated list; every entry must be a real address.
				$emails = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
				foreach ( $emails as $one ) {
					if ( ! is_email( $one ) ) {
						return new WP_Error(
							'yazan_invalid',
							sprintf( /* translators: %s: the invalid address */ __( '“%s” is not a valid email address.', 'yazan' ), $one ),
							array( 'status' => 400 )
						);
					}
				}
				$clean = implode( ', ', array_map( 'sanitize_email', $emails ) );
			} elseif ( 'email_type' === $key ) {
				$allowed = array_keys( (array) ( $fields['email_type']['options'] ?? array( 'html' => 'HTML' ) ) );
				$clean   = in_array( (string) $value, $allowed, true ) ? (string) $value : 'html';
			} elseif ( 'additional_content' === $key ) {
				$clean = wp_kses_post( (string) $value );
			} else {
				$clean = sanitize_text_field( (string) $value );
			}

			$settings[ $key ] = $clean;
			$changed[]        = $key;
		}

		if ( empty( $changed ) ) {
			return new WP_Error( 'yazan_invalid', __( 'Nothing to change on this email.', 'yazan' ), array( 'status' => 400 ) );
		}

		update_option( $email->get_option_key(), $settings );

		Yazan_Dashboard_Audit::log( 'email.update', 'settings', 0, array( 'email' => $id, 'changed' => $changed ) );

		return self::index();
	}
}
