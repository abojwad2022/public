<?php
/**
 * Base class for every Homepage Manager domain failure.
 *
 * REST controllers map these onto status codes in one place, so a new failure mode never needs a
 * new try/catch in every controller.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base domain exception.
 */
class HomepageException extends \RuntimeException {

	/** @var array Extra context returned to the client. */
	protected $context = array();

	/**
	 * @param array $context Context.
	 * @return $this
	 */
	public function with_context( array $context ) {
		$this->context = $context;
		return $this;
	}

	/** @return array */
	public function context() {
		return $this->context;
	}

	/**
	 * The REST error code clients switch on.
	 *
	 * @return string
	 */
	public function error_code() {
		return 'yazan_homepage_error';
	}

	/**
	 * HTTP status this failure should produce.
	 *
	 * @return int
	 */
	public function status() {
		return 400;
	}
}
