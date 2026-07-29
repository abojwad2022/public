<?php
/**
 * Social connector registry.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social;

use Yazan\Rewards\Core\Contracts\ConnectorInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keyed collection of the loaded network connectors. Networks are supplied at
 * construction (from config/connectors.php, filterable) — nothing here enumerates a
 * hardcoded list. Resolves a connector by id or by a submitted post URL's host.
 */
final class ConnectorRegistry {

	/** @var array<string,ConnectorInterface> network id → connector. */
	private array $connectors = array();

	/**
	 * @param ConnectorInterface[] $connectors Connectors to register.
	 */
	public function __construct( array $connectors = array() ) {
		foreach ( $connectors as $connector ) {
			if ( $connector instanceof ConnectorInterface ) {
				$this->connectors[ $connector->network() ] = $connector;
			}
		}
	}

	/**
	 * A connector by network id, or null.
	 *
	 * @param string $network Network id.
	 * @return ConnectorInterface|null
	 */
	public function get( string $network ): ?ConnectorInterface {
		return $this->connectors[ sanitize_key( $network ) ] ?? null;
	}

	/**
	 * Whether a network is known.
	 *
	 * @param string $network Network id.
	 * @return bool
	 */
	public function has( string $network ): bool {
		return isset( $this->connectors[ sanitize_key( $network ) ] );
	}

	/**
	 * All connectors.
	 *
	 * @return array<string,ConnectorInterface>
	 */
	public function all(): array {
		return $this->connectors;
	}

	/**
	 * Just the network ids.
	 *
	 * @return string[]
	 */
	public function networks(): array {
		return array_keys( $this->connectors );
	}

	/**
	 * Connectors whose OAuth app keys are configured (API path live).
	 *
	 * @return array<string,ConnectorInterface>
	 */
	public function configured(): array {
		return array_filter( $this->connectors, static fn( ConnectorInterface $c ) => $c->is_configured() );
	}

	/**
	 * The connector that owns a submitted post URL, or null.
	 *
	 * @param string $url Post URL.
	 * @return ConnectorInterface|null
	 */
	public function for_url( string $url ): ?ConnectorInterface {
		foreach ( $this->connectors as $connector ) {
			if ( $connector->url_belongs( $url ) ) {
				return $connector;
			}
		}
		return null;
	}
}
