<?php
/**
 * Audit adapter → Yazan_Dashboard_Audit.
 *
 * Every event lands in wp_yazan_audit_log, so the module needs no audit UI of its own: the
 * existing Activity log screen already renders it, with actor, IP and browser attached by the
 * platform.
 *
 * Two fields the request asked for have no column in that table — the role used and a correlation
 * id. Both travel inside `changes` rather than migrating a table other subsystems write to.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Infrastructure\Adapter;

use Yazan\Homepage\Domain\Event\DomainEvent;
use Yazan\Homepage\Domain\Port\AuditPort;
use Yazan\Homepage\Domain\Port\AuthorizationPort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes domain events to the platform audit log.
 */
final class YazanAuditAdapter implements AuditPort {

	/** @var AuthorizationPort */
	private $auth;

	/** @var string One id per request, linking the events of a single operation. */
	private static $correlation_id = '';

	/**
	 * @param AuthorizationPort $auth Authorization (for the actor's roles).
	 */
	public function __construct( AuthorizationPort $auth ) {
		$this->auth = $auth;
	}

	/**
	 * The correlation id for this request, minted once.
	 *
	 * @return string
	 */
	public static function correlation_id() {
		if ( '' === self::$correlation_id ) {
			self::$correlation_id = substr( md5( uniqid( 'yzhp', true ) ), 0, 16 );
		}
		return self::$correlation_id;
	}

	/**
	 * @param DomainEvent $event Event.
	 * @return void
	 */
	public function record( DomainEvent $event ) {
		if ( ! class_exists( 'Yazan_Dashboard_Audit' ) ) {
			return;
		}

		$changes = $event->changes();

		$changes['actor_roles']    = $this->auth->actor_roles();
		$changes['correlation_id'] = self::correlation_id();

		\Yazan_Dashboard_Audit::log(
			$event->name(),
			$event->object_type(),
			is_numeric( $event->object_id() ) ? (int) $event->object_id() : 0,
			array_merge( $changes, array( 'object_ref' => $event->object_id() ) )
		);
	}
}
