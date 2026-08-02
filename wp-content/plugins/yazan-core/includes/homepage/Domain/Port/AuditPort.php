<?php
/**
 * Audit boundary.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Port;

use Yazan\Homepage\Domain\Event\DomainEvent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records domain events in the platform audit trail.
 */
interface AuditPort {

	/**
	 * @param DomainEvent $event Event.
	 * @return void
	 */
	public function record( DomainEvent $event );
}
