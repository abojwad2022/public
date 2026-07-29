<?php
/**
 * Payment Bridge dashboard.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Admin;

use Yazan\PaymentBridge\Events\EventRepository;
use Yazan\PaymentBridge\Events\IntegrationStatus;
use Yazan\PaymentBridge\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only summary of the payment-event ledger.
 */
final class DashboardPage {

	/** Menu slug. */
	public const SLUG = 'yazan-payment-bridge';

	/**
	 * @param EventRepository $repository Event store.
	 */
	public function __construct( private EventRepository $repository ) {}

	/**
	 * Render the dashboard.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to view payment events.', 'yazan-payment-bridge' ), 403 );
		}

		$counts = $this->repository->dashboard_counts();

		$cards = array(
			array(
				'label' => __( 'Total payment events', 'yazan-payment-bridge' ),
				'value' => (int) ( $counts['total'] ?? 0 ),
				'tone'  => 'neutral',
				'link'  => '',
			),
			array(
				'label' => __( 'Successful payments', 'yazan-payment-bridge' ),
				'value' => (int) ( $counts['successful_payments'] ?? 0 ),
				'tone'  => 'good',
				'link'  => 'payment_completed',
			),
			array(
				'label' => __( 'Pending integrations', 'yazan-payment-bridge' ),
				'value' => (int) ( $counts[ IntegrationStatus::PENDING ] ?? 0 ) + (int) ( $counts[ IntegrationStatus::PROCESSING ] ?? 0 ),
				'tone'  => 'warn',
				'link'  => '',
			),
			array(
				'label' => __( 'Failed integrations', 'yazan-payment-bridge' ),
				'value' => (int) ( $counts[ IntegrationStatus::FAILED ] ?? 0 ),
				'tone'  => 'bad',
				'link'  => '',
			),
			array(
				'label' => __( 'Refunds awaiting review', 'yazan-payment-bridge' ),
				'value' => (int) ( $counts[ IntegrationStatus::REVIEW ] ?? 0 ),
				'tone'  => 'warn',
				'link'  => '',
			),
		);

		$events_url = admin_url( 'admin.php?page=' . EventsPage::SLUG );

		echo '<div class="wrap yazan-pb-wrap">';
		echo '<h1>' . esc_html__( 'YAZAN Payment Bridge', 'yazan-payment-bridge' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'A record of WooCommerce-verified payment states and the YAZAN integrations they triggered. This plugin never processes payments.', 'yazan-payment-bridge' ) . '</p>';

		echo '<div class="yazan-pb-cards">';
		foreach ( $cards as $card ) {
			printf(
				'<div class="yazan-pb-card yazan-pb-card--%1$s"><span class="yazan-pb-card__value">%2$s</span><span class="yazan-pb-card__label">%3$s</span></div>',
				esc_attr( (string) $card['tone'] ),
				esc_html( number_format_i18n( (int) $card['value'] ) ),
				esc_html( (string) $card['label'] )
			);
		}
		echo '</div>';

		printf(
			'<p><a class="button button-primary" href="%1$s">%2$s</a> <a class="button" href="%3$s">%4$s</a></p>',
			esc_url( $events_url ),
			esc_html__( 'View payment events', 'yazan-payment-bridge' ),
			esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ),
			esc_html__( 'Settings', 'yazan-payment-bridge' )
		);

		$skipped = (int) ( $counts[ IntegrationStatus::SKIPPED ] ?? 0 );
		if ( $skipped > 0 ) {
			echo '<p class="description">';
			printf(
				/* translators: %s: number of skipped events. */
				esc_html__( '%s events were skipped — no downstream YAZAN ownership or warranty system claimed them. This is expected until one is installed, and is not a failure.', 'yazan-payment-bridge' ),
				esc_html( number_format_i18n( $skipped ) )
			);
			echo '</p>';
		}

		echo '</div>';
	}
}
