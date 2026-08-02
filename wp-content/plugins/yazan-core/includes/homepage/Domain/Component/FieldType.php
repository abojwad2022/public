<?php
/**
 * The closed set of field types a component schema may declare.
 *
 * Closed on purpose: every type needs a server-side sanitizer AND a React renderer. A free-form
 * type means a sanitizer nobody wrote, which is a stored-XSS hole with a deadline.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Component;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field type constants and their metadata.
 */
final class FieldType {

	const TEXT          = 'text';
	const TEXTAREA      = 'textarea';
	const RICHTEXT      = 'richtext';
	const NUMBER        = 'number';
	const RANGE         = 'range';
	const TOGGLE        = 'toggle';
	const DATETIME      = 'datetime';
	const SELECT        = 'select';
	const COLOR         = 'color';
	const MEDIA         = 'media';
	const GALLERY       = 'gallery';
	const VIDEO         = 'video';
	const URL           = 'url';
	const LINK          = 'link';
	const BUTTON        = 'button';
	const ICON          = 'icon';
	const PRODUCT_QUERY = 'product_query';
	const PRODUCT_IDS   = 'product_ids';
	const TERM_IDS      = 'term_ids';
	const REPEATER      = 'repeater';
	const GROUP         = 'group';

	/**
	 * Every valid type.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array(
			self::TEXT,
			self::TEXTAREA,
			self::RICHTEXT,
			self::NUMBER,
			self::RANGE,
			self::TOGGLE,
			self::DATETIME,
			self::SELECT,
			self::COLOR,
			self::MEDIA,
			self::GALLERY,
			self::VIDEO,
			self::URL,
			self::LINK,
			self::BUTTON,
			self::ICON,
			self::PRODUCT_QUERY,
			self::PRODUCT_IDS,
			self::TERM_IDS,
			self::REPEATER,
			self::GROUP,
		);
	}

	/**
	 * Is this a container type whose value is built from child fields?
	 *
	 * @param string $type Field type.
	 * @return bool
	 */
	public static function is_container( $type ) {
		return self::REPEATER === $type || self::GROUP === $type;
	}

	/**
	 * The empty value for a type, used when no explicit default is declared.
	 *
	 * @param string $type Field type.
	 * @return mixed
	 */
	public static function empty_value( $type ) {
		switch ( $type ) {
			case self::NUMBER:
			case self::RANGE:
				return 0;
			case self::TOGGLE:
				return false;
			case self::DATETIME:
				// 0, not null: "no date" has to survive a JSON round trip as a value, not as an
				// absence that later reads as "the epoch".
				return 0;
			case self::MEDIA:
			case self::VIDEO:
				return 0;
			case self::GALLERY:
			case self::PRODUCT_IDS:
			case self::TERM_IDS:
			case self::REPEATER:
				return array();
			case self::LINK:
			case self::BUTTON:
			case self::GROUP:
			case self::PRODUCT_QUERY:
				return array();
			default:
				return '';
		}
	}
}
