<?php
/**
 * Base for the public `yazan/v1` REST controllers.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\PublicV1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Plugin;
use Yazan\Rewards\Core\Rest\AbstractController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The stable, documented public API surface for third-party consumers. Same auth
 * model as the internal controllers (logged-in cookie + `wp_rest` nonce, and —
 * automatically — WordPress Application Passwords for server-to-server), just under
 * the `yazan/v1` namespace. These controllers are thin: they validate input and
 * delegate to the same container-bound services the first-party UI uses.
 */
abstract class AbstractPublicController extends AbstractController {

	/**
	 * @param Container   $container Service container.
	 * @param string|null $namespace Override the namespace. Used only to register the legacy
	 *                               `yazan/v1` aliases alongside the real routes during the
	 *                               deprecation window; everything else takes the default.
	 */
	public function __construct( Container $container, ?string $namespace = null ) {
		parent::__construct( $container );
		$this->namespace = $namespace ?? Plugin::PUBLIC_REST_NS;
	}
}
