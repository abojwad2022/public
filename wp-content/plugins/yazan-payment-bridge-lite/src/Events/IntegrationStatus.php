<?php
/**
 * Integration lifecycle vocabulary.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * pending → processing → completed | failed | skipped, plus review for partial
 * refunds awaiting a human decision (H3).
 *
 * SKIPPED is deliberately distinct from FAILED. The Ownership and Warranty
 * connectors are seams: until a downstream YAZAN system subscribes, every event
 * would otherwise be recorded as a failure and the admin dashboard would be
 * uniformly red, hiding real faults. "No subscriber" is not an error.
 */
final class IntegrationStatus {

	/** Recorded, integrations not yet attempted. */
	public const PENDING = 'pending';

	/** Claimed by a worker; downstream calls in flight. */
	public const PROCESSING = 'processing';

	/** A downstream subscriber handled the event cleanly. */
	public const COMPLETED = 'completed';

	/** A downstream subscriber threw. Retryable from the admin. */
	public const FAILED = 'failed';

	/** No subscriber, or the integration is switched off in settings. */
	public const SKIPPED = 'skipped';

	/** Partial refund flagged for manual review; never auto-revoked. */
	public const REVIEW = 'review';

	/**
	 * Every known status.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::PENDING,
			self::PROCESSING,
			self::COMPLETED,
			self::FAILED,
			self::SKIPPED,
			self::REVIEW,
		);
	}

	/**
	 * Whether a string is a known status.
	 *
	 * @param string $status Candidate.
	 * @return bool
	 */
	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}

	/**
	 * Statuses a claim() may take ownership of.
	 *
	 * @return string[]
	 */
	public static function claimable(): array {
		return array( self::PENDING, self::FAILED );
	}

	/**
	 * Human-readable label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function label( string $status ): string {
		$labels = array(
			self::PENDING    => __( 'Pending', 'yazan-payment-bridge' ),
			self::PROCESSING => __( 'Processing', 'yazan-payment-bridge' ),
			self::COMPLETED  => __( 'Completed', 'yazan-payment-bridge' ),
			self::FAILED     => __( 'Failed', 'yazan-payment-bridge' ),
			self::SKIPPED    => __( 'Skipped', 'yazan-payment-bridge' ),
			self::REVIEW     => __( 'Needs review', 'yazan-payment-bridge' ),
		);

		return $labels[ $status ] ?? $status;
	}
}
