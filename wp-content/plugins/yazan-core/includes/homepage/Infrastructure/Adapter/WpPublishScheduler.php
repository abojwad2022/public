<?php
/**
 * Books the WP-Cron event that performs a scheduled publish.
 *
 * A listener, not a call inside the handler: scheduling is a consequence of the fact that a
 * document was scheduled, and the domain has no business knowing WP-Cron exists.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Infrastructure\Adapter;

use Yazan\Homepage\Domain\Event\DomainEvent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules the due-publish run.
 */
final class WpPublishScheduler {

	/** Cron hook that publishes documents whose moment has arrived. */
	const HOOK = 'yazan_homepage_publish_due';

	/**
	 * @param DomainEvent $event Event.
	 * @return void
	 */
	public function __invoke( $event ) {
		if ( ! $event instanceof DomainEvent ) {
			return;
		}

		if ( DomainEvent::DOCUMENT_SCHEDULED !== $event->name() ) {
			return;
		}

		$changes = $event->changes();
		$when    = isset( $changes['scheduled_at'] ) ? (int) $changes['scheduled_at'] : 0;

		if ( $when <= time() ) {
			return;
		}

		if ( wp_next_scheduled( self::HOOK ) === $when ) {
			return;
		}

		/*
		 * One event, replaced when the time moves. WP-Cron only fires on a request, so a very
		 * quiet site publishes at its first visit after the moment rather than exactly on it —
		 * accepted, and worth knowing before someone schedules a launch for 3am.
		 */
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( $when, self::HOOK );
	}
}
