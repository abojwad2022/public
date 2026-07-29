<?php
/**
 * Payment events list.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Admin;

use Yazan\PaymentBridge\Events\Event;
use Yazan\PaymentBridge\Events\EventRepository;
use Yazan\PaymentBridge\Events\EventTypes;
use Yazan\PaymentBridge\Events\IntegrationStatus;
use Yazan\PaymentBridge\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-rendered event table with search, filters, pagination and a
 * nonce-protected retry action.
 *
 * Every request value is unslashed and sanitised here; every stored value is
 * escaped on output. Gateway names, transaction ids and error messages all
 * originate outside this plugin and are treated as untrusted (H5).
 */
final class EventsPage {

	/** Menu slug. */
	public const SLUG = 'yazan-payment-bridge-events';

	/** Rows per page. */
	private const PER_PAGE = 20;

	/**
	 * @param EventRepository $repository Event store.
	 */
	public function __construct( private EventRepository $repository ) {}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to view payment events.', 'yazan-payment-bridge' ), 403 );
		}

		$args = $this->read_request();

		$rows  = $this->repository->query( $args );
		$total = $this->repository->count_matching( $args );

		echo '<div class="wrap yazan-pb-wrap">';
		echo '<h1>' . esc_html__( 'Payment Events', 'yazan-payment-bridge' ) . '</h1>';

		$this->render_notice();
		$this->render_filters( $args );
		$this->render_table( $rows );
		$this->render_pagination( $args, $total );

		echo '</div>';
	}

	/**
	 * Read, unslash and sanitise the request parameters.
	 *
	 * This is a read-only listing, so no nonce is required to view it; the
	 * mutating action (retry) verifies its own nonce.
	 *
	 * @return array<string,mixed>
	 */
	private function read_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$type   = isset( $_GET['event_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['event_type'] ) ) : '';
		$status = isset( $_GET['integration_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['integration_status'] ) ) : '';
		$page   = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array(
			'search'             => mb_substr( $search, 0, 191 ),
			'event_type'         => EventTypes::is_valid( $type ) ? $type : '',
			'integration_status' => IntegrationStatus::is_valid( $status ) ? $status : '',
			'page'               => max( 1, $page ),
			'per_page'           => self::PER_PAGE,
		);
	}

	/**
	 * Show the result of a retry redirect.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['yazan_pb_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['yazan_pb_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'completed' => array( 'success', __( 'The integration ran successfully.', 'yazan-payment-bridge' ) ),
			'skipped'   => array( 'info', __( 'The integration was skipped — no downstream system claimed the event.', 'yazan-payment-bridge' ) ),
			'review'    => array( 'warning', __( 'The event is flagged for manual review.', 'yazan-payment-bridge' ) ),
			'failed'    => array( 'error', __( 'The integration failed again. See the event error and the WooCommerce logs.', 'yazan-payment-bridge' ) ),
			'locked'    => array( 'info', __( 'Nothing to retry: the event is already being processed or has completed.', 'yazan-payment-bridge' ) ),
			'denied'    => array( 'error', __( 'You do not have permission to retry integrations.', 'yazan-payment-bridge' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}

	/**
	 * Search + filter form.
	 *
	 * @param array<string,mixed> $args Current filter state.
	 * @return void
	 */
	private function render_filters( array $args ): void {
		echo '<form method="get" class="yazan-pb-filters">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );

		printf(
			'<label class="screen-reader-text" for="yazan-pb-search">%s</label><input type="search" id="yazan-pb-search" name="s" value="%s" placeholder="%s" />',
			esc_html__( 'Search payment events', 'yazan-payment-bridge' ),
			esc_attr( (string) $args['search'] ),
			esc_attr__( 'Order ID, transaction or UUID', 'yazan-payment-bridge' )
		);

		echo '<select name="event_type">';
		printf( '<option value="">%s</option>', esc_html__( 'All event types', 'yazan-payment-bridge' ) );
		foreach ( EventTypes::all() as $type ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $type ),
				selected( $args['event_type'], $type, false ),
				esc_html( EventTypes::label( $type ) )
			);
		}
		echo '</select>';

		echo '<select name="integration_status">';
		printf( '<option value="">%s</option>', esc_html__( 'All integration statuses', 'yazan-payment-bridge' ) );
		foreach ( IntegrationStatus::all() as $status ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $status ),
				selected( $args['integration_status'], $status, false ),
				esc_html( IntegrationStatus::label( $status ) )
			);
		}
		echo '</select>';

		submit_button( __( 'Filter', 'yazan-payment-bridge' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * The events table.
	 *
	 * @param object[] $rows Event rows.
	 * @return void
	 */
	private function render_table( array $rows ): void {
		echo '<table class="wp-list-table widefat fixed striped yazan-pb-table">';
		echo '<thead><tr>';
		$columns = array(
			__( 'Event', 'yazan-payment-bridge' ),
			__( 'Order', 'yazan-payment-bridge' ),
			__( 'Customer', 'yazan-payment-bridge' ),
			__( 'Gateway', 'yazan-payment-bridge' ),
			__( 'Source', 'yazan-payment-bridge' ),
			__( 'Amount', 'yazan-payment-bridge' ),
			__( 'Event type', 'yazan-payment-bridge' ),
			__( 'Integration', 'yazan-payment-bridge' ),
			__( 'Date (UTC)', 'yazan-payment-bridge' ),
			__( 'Actions', 'yazan-payment-bridge' ),
		);
		foreach ( $columns as $column ) {
			echo '<th scope="col">' . esc_html( $column ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $rows ) {
			printf(
				'<tr><td colspan="%1$d">%2$s</td></tr>',
				count( $columns ),
				esc_html__( 'No payment events recorded yet.', 'yazan-payment-bridge' )
			);
		}

		foreach ( $rows as $row ) {
			$this->render_row( $row );
		}

		echo '</tbody></table>';
	}

	/**
	 * One table row.
	 *
	 * @param object $row Event row.
	 * @return void
	 */
	private function render_row( object $row ): void {
		$event    = Event::from_row( $row );
		$order_id = $event->order_id;

		echo '<tr>';

		printf(
			'<td><strong>#%1$s</strong><br /><code class="yazan-pb-uuid">%2$s</code></td>',
			esc_html( (string) $event->id ),
			esc_html( $event->event_uuid )
		);

		$order_url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
		printf(
			'<td><a href="%1$s">#%2$s</a></td>',
			esc_url( $order_url ),
			esc_html( (string) $order_id )
		);

		printf( '<td>%s</td>', $event->customer_id > 0 ? esc_html( '#' . $event->customer_id ) : esc_html__( 'Guest', 'yazan-payment-bridge' ) );
		printf( '<td>%s</td>', esc_html( '' !== $event->gateway ? $event->gateway : '—' ) );
		printf( '<td><span class="yazan-pb-pill yazan-pb-pill--%1$s">%2$s</span></td>', esc_attr( $event->source ), esc_html( $event->source_label() ) );

		$amount = function_exists( 'wc_price' )
			? wp_strip_all_tags( wc_price( (float) $event->amount, array( 'currency' => $event->currency ) ) )
			: $event->amount . ' ' . $event->currency;
		printf( '<td>%s</td>', esc_html( $amount ) );

		printf( '<td>%s</td>', esc_html( EventTypes::label( $event->event_type ) ) );

		printf(
			'<td><span class="yazan-pb-pill yazan-pb-pill--%1$s">%2$s</span>%3$s</td>',
			esc_attr( $event->integration_status ),
			esc_html( IntegrationStatus::label( $event->integration_status ) ),
			isset( $row->error_message ) && $row->error_message
				? '<br /><span class="yazan-pb-error">' . esc_html( (string) $row->error_message ) . '</span>'
				: ''
		);

		printf( '<td>%s</td>', esc_html( (string) ( $row->created_at ?? '' ) ) );

		echo '<td>' . $this->retry_link( $event ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in retry_link().

		echo '</tr>';
	}

	/**
	 * Nonce-protected retry link, shown only for retryable statuses and only to
	 * users holding the retry capability.
	 *
	 * @param Event $event Event.
	 * @return string Escaped HTML.
	 */
	private function retry_link( Event $event ): string {
		if ( ! current_user_can( Capabilities::RETRY ) ) {
			return '—';
		}

		if ( ! in_array( $event->integration_status, IntegrationStatus::claimable(), true ) ) {
			return '—';
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . RetryController::ACTION . '&event_id=' . $event->id ),
			RetryController::NONCE_PREFIX . $event->id
		);

		return sprintf(
			'<a class="button button-small" href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'Retry', 'yazan-payment-bridge' )
		);
	}

	/**
	 * Pagination links.
	 *
	 * @param array<string,mixed> $args  Current filter state.
	 * @param int                 $total Total matching rows.
	 * @return void
	 */
	private function render_pagination( array $args, int $total ): void {
		$per_page = (int) $args['per_page'];
		$pages    = (int) ceil( $total / max( 1, $per_page ) );

		if ( $pages <= 1 ) {
			return;
		}

		$base = add_query_arg(
			array(
				'page'               => self::SLUG,
				's'                  => $args['search'],
				'event_type'         => $args['event_type'],
				'integration_status' => $args['integration_status'],
				'paged'              => '%#%',
			),
			admin_url( 'admin.php' )
		);

		$links = paginate_links(
			array(
				'base'      => $base,
				'format'    => '',
				'current'   => (int) $args['page'],
				'total'     => $pages,
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
			)
		);

		if ( $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post( is_array( $links ) ? implode( ' ', $links ) : $links );
			echo '</div></div>';
		}
	}
}
