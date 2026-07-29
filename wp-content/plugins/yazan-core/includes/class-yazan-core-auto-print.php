<?php
/**
 * Yazan Core — auto-print the invoice when an order is marked Completed.
 *
 * A web server can't push to a physical printer on its own, so this uses the
 * store dashboard as the print bridge: when an order first becomes Completed it
 * is queued; any open admin tab picks the job up over the WP Heartbeat API and
 * prints the invoice from a hidden iframe. With Chrome launched using
 * `--kiosk-printing`, that print is silent and goes straight to the default
 * printer (no dialog).
 *
 * Duplicate protection: an authoritative per-order meta flag `_yazan_autoprinted`
 * (set once the browser confirms it printed) plus a client-side localStorage guard.
 *
 * HPOS-safe: all order access via wc_get_order().
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

class Yazan_Core_Auto_Print {

	const ACTION         = 'yz_autoprint';
	const ENABLED_OPTION = 'yazan_autoprint_enabled';
	const FORMAT_OPTION  = 'yazan_autoprint_format';
	const QUEUE_OPTION   = 'yazan_autoprint_queue';
	const DONE_META      = '_yazan_autoprinted';

	/**
	 * Jobs older than this (seconds) are considered stale and are never printed.
	 * Auto-print is a real-time convenience: an order that completed long before a
	 * dashboard tab was opened should not suddenly spew print dialogs. Print those
	 * from the Orders list "Print" button instead. Default: 15 minutes — long enough
	 * to survive a browser restart, short enough not to resurrect old orders.
	 */
	const MAX_AGE = 15 * MINUTE_IN_SECONDS;

	/**
	 * Hard cap on how many jobs the queue may hold. Prevents unbounded growth if a
	 * dashboard tab is never open (or kiosk printing is off). Oldest jobs drop first.
	 */
	const MAX_QUEUE = 50;

	/**
	 * Allowed paper / printer formats.
	 *
	 * @return string[]
	 */
	private static function formats() {
		return array( 'a4', 'a5', 'receipt80' );
	}

	/**
	 * Register hooks. Called once on `init`.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_completed' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'heartbeat_received', array( __CLASS__, 'heartbeat_received' ), 10, 2 );
		add_action( 'admin_action_' . self::ACTION, array( __CLASS__, 'render' ) );
	}

	/**
	 * Is auto-print switched on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return 'yes' === get_option( self::ENABLED_OPTION, 'no' );
	}

	/* ---------------------------------------------------------------------
	 * Settings — dedicated page under the WooCommerce menu
	 * ------------------------------------------------------------------- */

	/**
	 * Add "Auto-Print" under the WooCommerce admin menu.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Auto-Print Invoices', 'yazan' ),
			__( 'Auto-Print', 'yazan' ),
			'manage_woocommerce',
			'yazan-autoprint',
			array( __CLASS__, 'settings_page' )
		);
	}

	/**
	 * Register the single enable option with the Settings API.
	 *
	 * @return void
	 */
	public static function register_setting() {
		register_setting(
			'yazan_autoprint_group',
			self::ENABLED_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_enabled' ),
				'default'           => 'no',
			)
		);
		register_setting(
			'yazan_autoprint_group',
			self::FORMAT_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_format' ),
				'default'           => 'a4',
			)
		);
	}

	/**
	 * @param string $value Posted value.
	 * @return string 'yes' or 'no'.
	 */
	public static function sanitize_enabled( $value ) {
		return 'yes' === $value ? 'yes' : 'no';
	}

	/**
	 * @param string $value Posted value.
	 * @return string A whitelisted format.
	 */
	public static function sanitize_format( $value ) {
		return in_array( $value, self::formats(), true ) ? $value : 'a4';
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Auto-Print Invoices', 'yazan' ); ?></h1>
			<p class="description" style="max-width:720px;font-size:14px;">
				<?php esc_html_e( 'When enabled, the invoice is sent to the printer automatically as soon as an order becomes Completed.', 'yazan' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'yazan_autoprint_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-print on Completed', 'yazan' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( self::ENABLED_OPTION ); ?>" value="no" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::ENABLED_OPTION ); ?>" value="yes" <?php checked( self::enabled() ); ?> />
								<?php esc_html_e( 'Print the invoice automatically when an order is marked Completed', 'yazan' ); ?>
							</label>
							<p class="description">
								<?php echo wp_kses_post( __( 'Status: prints from any open store dashboard tab.', 'yazan' ) ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Paper / printer type', 'yazan' ); ?></th>
						<td>
							<?php $fmt = get_option( self::FORMAT_OPTION, 'a4' ); ?>
							<select name="<?php echo esc_attr( self::FORMAT_OPTION ); ?>">
								<option value="a4" <?php selected( $fmt, 'a4' ); ?>><?php esc_html_e( 'A4 — full invoice (standard printer)', 'yazan' ); ?></option>
								<option value="a5" <?php selected( $fmt, 'a5' ); ?>><?php esc_html_e( 'A5 — compact invoice', 'yazan' ); ?></option>
								<option value="receipt80" <?php selected( $fmt, 'receipt80' ); ?>><?php esc_html_e( '80mm — thermal receipt printer', 'yazan' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Sets the invoice page size to match your printer. Choose 80mm for a thermal receipt printer.', 'yazan' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Which printer?', 'yazan' ); ?></th>
						<td>
							<p class="description" style="max-width:640px;">
								<?php echo wp_kses_post( __( 'Silent browser printing always uses the <strong>Windows default printer</strong> — set your target printer as the default. Picking a specific printer from this page is not possible with the free browser method; that would require a print service such as PrintNode (paid). Ask if you want that.', 'yazan' ) ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<div class="card" style="max-width:720px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Silent printing (no dialog)', 'yazan' ); ?></h2>
				<p><?php esc_html_e( 'A website cannot reach a physical printer on its own, so the store dashboard acts as the bridge: keep a dashboard tab open on the computer connected to the printer, and completed orders print from there.', 'yazan' ); ?></p>
				<ol>
					<li><?php esc_html_e( 'Set the target printer as the default printer in Windows.', 'yazan' ); ?></li>
					<li><?php echo wp_kses_post( __( 'Launch Chrome with the <code>--kiosk-printing</code> flag (add it to the end of the Chrome shortcut Target). This makes printing silent — straight to the default printer, no dialog.', 'yazan' ) ); ?></li>
					<li><?php esc_html_e( 'Open the store dashboard (Orders screen) in that Chrome and leave it open.', 'yazan' ); ?></li>
					<li><?php esc_html_e( 'Enable the option above. Completed orders now print automatically within a few seconds.', 'yazan' ); ?></li>
				</ol>
				<p class="description"><?php esc_html_e( 'Without the --kiosk-printing flag the invoice still prints, but the normal print dialog appears each time. If the dashboard is closed when an order completes, it prints as soon as a dashboard tab is opened — but only within 15 minutes of completion. Older orders are skipped to avoid a pile-up of print dialogs; print those from the Orders list "Print" button.', 'yazan' ); ?></p>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Queue
	 * ------------------------------------------------------------------- */

	/**
	 * The pending queue as an ordered map of order_id => enqueued Unix timestamp.
	 * Reading self-heals the stored value: stale jobs (older than MAX_AGE) are
	 * dropped, the size is capped at MAX_QUEUE (oldest first), and the legacy flat
	 * list format ([ 0 => id, ... ]) is migrated on the fly.
	 *
	 * @return array<int,int> order_id => timestamp
	 */
	private static function get_queue() {
		$raw   = (array) get_option( self::QUEUE_OPTION, array() );
		$now   = time();
		$clean = array();

		foreach ( $raw as $key => $val ) {
			// New format is [ id => ts ] where ts is a full Unix timestamp; legacy
			// format is a plain list [ 0 => id ]. A big value means it's a timestamp.
			if ( is_numeric( $val ) && (int) $val > 1000000000 ) {
				$id = (int) $key;
				$ts = (int) $val;
			} else {
				$id = (int) $val; // Legacy entry: value is the id, age unknown → treat as now.
				$ts = $now;
			}
			if ( $id <= 0 || $now - $ts > self::MAX_AGE ) {
				continue; // Invalid or stale — skip.
			}
			$clean[ $id ] = $ts;
		}

		// Hard cap: keep the newest MAX_QUEUE, drop the oldest.
		if ( count( $clean ) > self::MAX_QUEUE ) {
			asort( $clean ); // Oldest first.
			$clean = array_slice( $clean, -self::MAX_QUEUE, null, true );
		}

		return $clean;
	}

	/**
	 * @param array<int,int> $queue order_id => timestamp map.
	 * @return void
	 */
	private static function set_queue( $queue ) {
		$clean = array();
		foreach ( (array) $queue as $id => $ts ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$clean[ $id ] = (int) $ts;
			}
		}
		update_option( self::QUEUE_OPTION, $clean, false );
	}

	/**
	 * Queue an order for printing when it first becomes Completed.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function on_completed( $order_id ) {
		if ( ! self::enabled() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || 'yes' === $order->get_meta( self::DONE_META ) ) {
			return; // Already printed once.
		}
		$queue = self::get_queue();
		if ( ! isset( $queue[ (int) $order_id ] ) ) {
			$queue[ (int) $order_id ] = time(); // Stamp so it can expire if no tab picks it up.
			self::set_queue( $queue );
		}
	}

	/* ---------------------------------------------------------------------
	 * Heartbeat — hand jobs to the browser, receive print acknowledgements
	 * ------------------------------------------------------------------- */

	/**
	 * @param array $response Outgoing heartbeat response.
	 * @param array $data     Incoming heartbeat data.
	 * @return array
	 */
	public static function heartbeat_received( $response, $data ) {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return $response;
		}

		// 1. Acknowledgements: the browser confirms it printed these — mark done + dequeue.
		if ( ! empty( $data['yazan_printed'] ) && is_array( $data['yazan_printed'] ) ) {
			$queue = self::get_queue();
			foreach ( $data['yazan_printed'] as $pid ) {
				$pid   = (int) $pid;
				$order = wc_get_order( $pid );
				if ( $order ) {
					$order->update_meta_data( self::DONE_META, 'yes' );
					$order->save();
				}
				unset( $queue[ $pid ] );
			}
			self::set_queue( $queue );
		}

		// 2. Jobs: hand out any still-queued orders (only while enabled).
		if ( self::enabled() ) {
			$jobs = array();
			foreach ( array_slice( array_keys( self::get_queue() ), 0, 10 ) as $pid ) {
				$jobs[] = array(
					'id'  => (int) $pid,
					'url' => self::print_url( $pid ),
				);
			}
			if ( $jobs ) {
				$response['yazan_autoprint'] = $jobs;
			}
		}

		return $response;
	}

	/**
	 * Nonce-signed print URL for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	private static function print_url( $order_id ) {
		return esc_url_raw(
			add_query_arg(
				array(
					'action'   => self::ACTION,
					'order_id' => (int) $order_id,
					'_wpnonce' => wp_create_nonce( self::ACTION . '_' . $order_id ),
				),
				admin_url( 'admin.php' )
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Client script
	 * ------------------------------------------------------------------- */

	/**
	 * Load the pickup/print script on all admin pages (so it runs wherever the
	 * owner keeps the dashboard open). Only when enabled + capable.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::enabled() || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		wp_enqueue_script( 'heartbeat' );
		wp_enqueue_script(
			'yazan-auto-print',
			YAZAN_CORE_URL . 'assets/js/admin-auto-print.js',
			array( 'jquery', 'heartbeat' ),
			YAZAN_CORE_VERSION,
			true
		);
	}

	/* ---------------------------------------------------------------------
	 * Invoice document (loaded in the hidden iframe)
	 * ------------------------------------------------------------------- */

	/**
	 * Output the printable invoice, then stop. No auto-print / toolbar — the
	 * parent page triggers printing from the iframe.
	 *
	 * @return void
	 */
	public static function render() {
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $order_id || ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to print this order.', 'yazan' ) );
		}
		check_admin_referer( self::ACTION . '_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'yazan' ) );
		}
		self::print_invoice_markup( $order );
		exit;
	}

	/**
	 * Echo the standalone printable invoice document for an order. Shared by the
	 * auto-print endpoint and the orders-list "Print" button. Contains no auto-print
	 * script — the caller's hidden iframe triggers printing.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public static function print_invoice_markup( $order ) {
		$format     = self::sanitize_format( get_option( self::FORMAT_OPTION, 'a4' ) );
		$body_class = 'yz-fmt-' . $format;
		$currency   = $order->get_currency();
		$number   = $order->get_order_number();
		$date     = $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '';
		$billing  = $order->get_formatted_billing_address();
		$shipping = $order->get_formatted_shipping_address();
		$note     = $order->get_customer_note();
		$totals   = $order->get_order_item_totals();

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="utf-8">
	<title><?php printf( esc_html__( 'Order %s', 'yazan' ), esc_html( $number ) ); ?></title>
	<style>
		* { box-sizing: border-box; }
		body { font-family: Georgia, "Times New Roman", serif; color: #1a1714; margin: 0; padding: 28px; }
		.doc { max-width: 720px; margin: 0 auto; }
		.head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #1a1714; padding-bottom: 16px; margin-bottom: 22px; }
		.brand { font-size: 26px; letter-spacing: .02em; font-weight: 700; }
		.brand small { display: block; font-family: Arial, sans-serif; font-size: 10px; letter-spacing: .18em; text-transform: uppercase; color: #6e6a63; margin-top: 4px; font-weight: 400; }
		.meta { text-align: right; font-family: Arial, sans-serif; font-size: 12px; color: #333; line-height: 1.6; }
		.meta strong { font-size: 15px; color: #1a1714; }
		.cols { display: flex; gap: 28px; margin-bottom: 22px; }
		.col { flex: 1; }
		.col h3 { font-family: Arial, sans-serif; font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: #6e6a63; margin: 0 0 6px; }
		.col address { font-style: normal; font-size: 13px; line-height: 1.6; }
		table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
		th { font-family: Arial, sans-serif; font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: #6e6a63; text-align: left; border-bottom: 1px solid #cfcac2; padding: 8px 6px; }
		td { font-size: 13px; padding: 9px 6px; border-bottom: 1px solid #ece8e1; vertical-align: top; }
		th.amt, td.amt { text-align: right; white-space: nowrap; }
		.sku { font-family: Arial, sans-serif; font-size: 11px; color: #8a857c; }
		.totals { width: 46%; margin-left: auto; }
		.totals td { border: 0; padding: 5px 6px; font-family: Arial, sans-serif; font-size: 13px; }
		.totals tr:last-child td { border-top: 2px solid #1a1714; font-weight: 700; font-size: 15px; padding-top: 9px; }
		.note { border: 1px solid #ece8e1; padding: 12px 14px; font-size: 13px; margin-bottom: 18px; }
		.foot { text-align: center; font-family: Arial, sans-serif; font-size: 11px; color: #8a857c; margin-top: 26px; }
		@media print { body { padding: 0; } }
		<?php echo self::format_css( $format ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, whitelisted CSS. ?>
	</style>
</head>
<body class="<?php echo esc_attr( $body_class ); ?>">
	<div class="doc">
		<div class="head">
			<div class="brand">
				<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
				<small><?php esc_html_e( 'Order receipt', 'yazan' ); ?></small>
			</div>
			<div class="meta">
				<strong><?php printf( esc_html__( 'Order #%s', 'yazan' ), esc_html( $number ) ); ?></strong><br>
				<?php echo esc_html( $date ); ?><br>
				<?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?><br>
				<?php echo esc_html( $order->get_payment_method_title() ); ?>
			</div>
		</div>

		<div class="cols">
			<div class="col">
				<h3><?php esc_html_e( 'Billing', 'yazan' ); ?></h3>
				<address><?php echo wp_kses_post( $billing ? $billing : esc_html__( 'N/A', 'yazan' ) ); ?></address>
				<?php if ( $order->get_billing_phone() ) : ?>
					<address><?php echo esc_html( $order->get_billing_phone() ); ?></address>
				<?php endif; ?>
				<?php if ( $order->get_billing_email() ) : ?>
					<address><?php echo esc_html( $order->get_billing_email() ); ?></address>
				<?php endif; ?>
			</div>
			<div class="col">
				<h3><?php esc_html_e( 'Shipping', 'yazan' ); ?></h3>
				<address><?php echo wp_kses_post( $shipping ? $shipping : esc_html__( 'Same as billing', 'yazan' ) ); ?></address>
			</div>
		</div>

		<table>
			<thead>
				<tr>
					<th><?php esc_html_e( 'Item', 'yazan' ); ?></th>
					<th class="amt"><?php esc_html_e( 'Qty', 'yazan' ); ?></th>
					<th class="amt"><?php esc_html_e( 'Total', 'yazan' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $order->get_items() as $item ) : ?>
					<?php
					$product = $item->get_product();
					$sku     = $product ? $product->get_sku() : '';
					?>
					<tr>
						<td>
							<?php echo esc_html( $item->get_name() ); ?>
							<?php if ( $sku ) : ?>
								<span class="sku"><?php printf( esc_html__( 'SKU: %s', 'yazan' ), esc_html( $sku ) ); ?></span>
							<?php endif; ?>
						</td>
						<td class="amt"><?php echo esc_html( $item->get_quantity() ); ?></td>
						<td class="amt"><?php echo wp_kses_post( wc_price( $item->get_total(), array( 'currency' => $currency ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $totals ) : ?>
			<table class="totals">
				<?php foreach ( $totals as $total ) : ?>
					<tr>
						<td><?php echo esc_html( wp_strip_all_tags( $total['label'] ) ); ?></td>
						<td class="amt"><?php echo wp_kses_post( $total['value'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		<?php endif; ?>

		<?php if ( $note ) : ?>
			<div class="note">
				<strong><?php esc_html_e( 'Customer note:', 'yazan' ); ?></strong>
				<?php echo esc_html( $note ); ?>
			</div>
		<?php endif; ?>

		<div class="foot"><?php echo esc_html( sprintf( __( 'Thank you — %s', 'yazan' ), get_bloginfo( 'name' ) ) ); ?></div>
	</div>
</body>
</html>
		<?php
	}

	/**
	 * Page-size / layout CSS for the chosen paper format. Static, whitelisted.
	 *
	 * @param string $format One of a4 | a5 | receipt80.
	 * @return string
	 */
	private static function format_css( $format ) {
		switch ( $format ) {
			case 'receipt80':
				return '@page { size: 80mm auto; margin: 3mm; }'
					. 'body.yz-fmt-receipt80 { padding: 0; font-size: 12px; }'
					. '.yz-fmt-receipt80 .doc { max-width: 74mm; }'
					. '.yz-fmt-receipt80 .head { flex-direction: column; align-items: flex-start; gap: 6px; padding-bottom: 10px; margin-bottom: 12px; }'
					. '.yz-fmt-receipt80 .brand { font-size: 17px; }'
					. '.yz-fmt-receipt80 .meta { text-align: left; }'
					. '.yz-fmt-receipt80 .cols { flex-direction: column; gap: 8px; margin-bottom: 12px; }'
					. '.yz-fmt-receipt80 table { margin-bottom: 10px; }'
					. '.yz-fmt-receipt80 th, .yz-fmt-receipt80 td { padding: 4px 2px; font-size: 12px; }'
					. '.yz-fmt-receipt80 .totals { width: 100%; }';
			case 'a5':
				return '@page { size: A5; margin: 10mm; }';
			case 'a4':
			default:
				return '@page { size: A4; margin: 12mm; }';
		}
	}
}
