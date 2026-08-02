<?php
/**
 * Turns every domain event into an audit row.
 *
 * A listener rather than a call inside each handler: the handlers stay about their own job, and a
 * new operation is audited the moment it raises an event — there is no "we forgot to log that one".
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Listener;

use Yazan\Homepage\Domain\Event\DomainEvent;
use Yazan\Homepage\Domain\Port\AuditPort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audit listener.
 */
final class AuditRecorder {

	/** @var AuditPort */
	private $audit;

	/**
	 * @param AuditPort $audit Audit port.
	 */
	public function __construct( AuditPort $audit ) {
		$this->audit = $audit;
	}

	/**
	 * @param DomainEvent $event Event.
	 * @return void
	 */
	public function __invoke( $event ) {
		if ( $event instanceof DomainEvent ) {
			$this->audit->record( $event );
		}
	}
}
