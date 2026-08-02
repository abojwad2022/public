<?php
/**
 * Drops the fields the actor may not write, and reports what it dropped.
 *
 * Reporting is the point. A silently reverted edit is the single most frustrating thing a
 * permission system can do to someone: they save, nothing changes, and nobody can explain why.
 * The blocked list travels back in the response and the UI names the missing permission.
 *
 * This mirrors the pattern products.change_price / products.change_stock already use.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Service;

use Yazan\Homepage\Domain\Component\ComponentSchema;
use Yazan\Homepage\Domain\Port\AuthorizationPort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field-level authorization.
 */
final class PermissionFilter {

	/** @var AuthorizationPort */
	private $auth;

	/** @var array<string,bool> Memo so one save does not re-resolve the same slug per field. */
	private $memo = array();

	/**
	 * @param AuthorizationPort $auth Authorization.
	 */
	public function __construct( AuthorizationPort $auth ) {
		$this->auth = $auth;
	}

	/**
	 * Split a payload into what may be written and what may not.
	 *
	 * @param ComponentSchema $schema   Schema.
	 * @param array           $incoming Sanitised incoming values.
	 * @param array           $current  Currently stored values.
	 * @return array{0:array,1:array} [ allowed payload, blocked descriptors ]
	 */
	public function apply( ComponentSchema $schema, array $incoming, array $current = array() ) {
		$allowed = array();
		$blocked = array();

		foreach ( $schema->fields() as $key => $field ) {
			$permission = $field->permission();
			$value      = array_key_exists( $key, $incoming ) ? $incoming[ $key ] : $field->default_value();

			if ( ! $permission || $this->can_write( $permission ) ) {
				$allowed[ $key ] = $value;
				continue;
			}

			// Keep the stored value; never fall back to the default, which would wipe a field the
			// actor merely lacks permission to change.
			$kept = array_key_exists( $key, $current ) ? $current[ $key ] : $field->default_value();

			$allowed[ $key ] = $kept;

			// Only report a block when the actor actually tried to change something.
			if ( array_key_exists( $key, $incoming ) && wp_json_encode( $value ) !== wp_json_encode( $kept ) ) {
				$blocked[] = array(
					'field'      => $key,
					'label'      => $field->label(),
					// Report the first slug: it is the specific one, and "ask for colors.edit" is
					// more actionable than a list of alternatives.
					'permission' => is_array( $permission ) ? reset( $permission ) : $permission,
				);
			}
		}

		return array( $allowed, $blocked );
	}

	/**
	 * Which of a schema's field permissions the actor holds — sent to the builder so the inspector
	 * can hide controls that would be refused.
	 *
	 * @param ComponentSchema $schema Schema.
	 * @return array<string,bool>
	 */
	public function capability_map( ComponentSchema $schema ) {
		$map = array();

		foreach ( $schema->permissions() as $permission ) {
			$map[ $permission ] = $this->can( $permission );
		}

		return $map;
	}

	/**
	 * A field's requirement is met when ANY of its slugs is held.
	 *
	 * @param string|string[] $permission Requirement.
	 * @return bool
	 */
	private function can_write( $permission ) {
		foreach ( (array) $permission as $slug ) {
			if ( $this->can( $slug ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $permission Permission slug.
	 * @return bool
	 */
	private function can( $permission ) {
		if ( ! isset( $this->memo[ $permission ] ) ) {
			$this->memo[ $permission ] = $this->auth->can( $permission );
		}

		return $this->memo[ $permission ];
	}
}
