<?php
/**
 * The Store entity.
 *
 * IMMUTABLE, ON PURPOSE. Every "change" returns a new instance (with_status(), with_theme(), …)
 * rather than mutating in place. Two reasons, both concrete rather than stylistic:
 *
 *   1. A store is read on nearly every request and is cached in more than one place. A mutable
 *      object handed to two consumers means one of them can change what the other holds, and the
 *      symptom of that is a store rendering with another store's settings — the exact class of bug
 *      this whole migration exists to prevent.
 *   2. Validation can live in the constructor and then be TRUE FOR THE LIFETIME of the object. A
 *      setter would reopen every invariant on every call.
 *
 * The entity knows nothing about wpdb, options, or WordPress. It is constructible from an array
 * and convertible back to one; the repository owns the translation.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Domain\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A store.
 */
final class Store {

	/** Slug rules: what can safely appear in a hostname label AND in a URL path segment. */
	private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9\-]{0,61}[a-z0-9]$|^[a-z0-9]$/';

	/**
	 * @param int    $id              0 for a store not yet persisted.
	 * @param string $slug            Machine name. Unique.
	 * @param string $name            Human label.
	 * @param string $status          One of StoreStatus.
	 * @param string $type            One of StoreType.
	 * @param int    $parent_store_id Reserved for the vendor model; 0 for a top-level store.
	 * @param string $currency        ISO 4217, or '' to inherit the platform currency.
	 * @param string $locale          WordPress locale, or '' to inherit.
	 * @param string $theme           Theme identity slug (see the theme registry), or '' for default.
	 * @param int    $owner_user_id   Owning WordPress user, 0 if unassigned.
	 * @param string $created_at      MySQL datetime.
	 * @param string $updated_at      MySQL datetime.
	 *
	 * @throws \InvalidArgumentException When an invariant is violated.
	 */
	public function __construct(
		private int $id,
		private string $slug,
		private string $name,
		private string $status = StoreStatus::ACTIVE,
		private string $type = StoreType::OWNED,
		private int $parent_store_id = 0,
		private string $currency = '',
		private string $locale = '',
		private string $theme = '',
		private string $languages = '',
		private string $timezone = '',
		private int $logo_attachment_id = 0,
		private int $owner_user_id = 0,
		private string $created_at = '',
		private string $updated_at = '',
		/*
		 * APPENDED, NOT INSERTED. Every positional call site — including the tests written against
		 * the previous signature — keeps working untouched. This is the same convention
		 * Yazan_Permissions::can() documents for its own store argument, and inserting a parameter
		 * mid-list is exactly the breaking change it exists to avoid.
		 */
		private string $uuid = ''
	) {
		/*
		 * Mint a uuid when the caller has none. `to_array()` used to omit the field entirely, so
		 * every store created through the service was written with `uuid = ''` and only a later
		 * activation-time backfill repaired it. The uuid is the identity that survives a restore
		 * into another environment, where the numeric id may not — it must exist from birth.
		 */
		if ( '' === trim( $this->uuid ) ) {
			$this->uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : '';
		}

		$this->slug = strtolower( trim( $this->slug ) );

		if ( ! preg_match( self::SLUG_PATTERN, $this->slug ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Store slug "%s" is invalid. Use lowercase letters, digits and hyphens; it must start and end with an alphanumeric and be at most 63 characters, because it has to be legal as both a hostname label and a URL path segment.',
					$this->slug
				)
			);
		}

		if ( '' === trim( $this->name ) ) {
			throw new \InvalidArgumentException( 'Store name cannot be empty.' );
		}

		if ( ! StoreStatus::is_valid( $this->status ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown store status "%s".', $this->status ) );
		}

		if ( ! StoreType::is_valid( $this->type ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown store type "%s".', $this->type ) );
		}

		$this->currency = strtoupper( trim( $this->currency ) );

		if ( '' !== $this->currency && ! preg_match( '/^[A-Z]{3}$/', $this->currency ) ) {
			throw new \InvalidArgumentException( sprintf( 'Currency "%s" is not a 3-letter ISO 4217 code.', $this->currency ) );
		}

		/*
		 * A store cannot be its own parent. Cheap to check, and the alternative is an infinite loop
		 * in any future code that walks the parent chain.
		 */
		if ( $this->parent_store_id > 0 && $this->parent_store_id === $this->id ) {
			throw new \InvalidArgumentException( 'A store cannot be its own parent.' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Readers                                                             */
	/* ------------------------------------------------------------------ */

	public function id(): int {
		return $this->id;
	}

	public function slug(): string {
		return $this->slug;
	}

	public function name(): string {
		return $this->name;
	}

	public function status(): string {
		return $this->status;
	}

	public function type(): string {
		return $this->type;
	}

	public function parent_store_id(): int {
		return $this->parent_store_id;
	}

	public function currency(): string {
		return $this->currency;
	}

	public function locale(): string {
		return $this->locale;
	}

	public function theme(): string {
		return $this->theme;
	}

	public function uuid(): string {
		return $this->uuid;
	}

	public function languages(): string {
		return $this->languages;
	}

	public function timezone(): string {
		return $this->timezone;
	}

	public function logo_attachment_id(): int {
		return $this->logo_attachment_id;
	}

	public function owner_user_id(): int {
		return $this->owner_user_id;
	}

	public function created_at(): string {
		return $this->created_at;
	}

	public function updated_at(): string {
		return $this->updated_at;
	}

	/** Has this store been persisted? */
	public function exists(): bool {
		return $this->id > 0;
	}

	/**
	 * May this store serve requests?
	 *
	 * The distinction that matters: a store can EXIST without being reachable. Only an active
	 * store is projected into the hostmap, so suspending a store takes it off the web without
	 * deleting anything.
	 */
	public function is_active(): bool {
		return StoreStatus::ACTIVE === $this->status;
	}

	/* ------------------------------------------------------------------ */
	/* Transformers — each returns a NEW instance                          */
	/* ------------------------------------------------------------------ */

	public function with_id( int $id ): self {
		return $this->derive( array( 'id' => $id ) );
	}

	public function with_status( string $status ): self {
		return $this->derive( array( 'status' => $status ) );
	}

	public function with_theme( string $theme ): self {
		return $this->derive( array( 'theme' => $theme ) );
	}

	public function with_name( string $name ): self {
		return $this->derive( array( 'name' => $name ) );
	}

	public function with_currency( string $currency ): self {
		return $this->derive( array( 'currency' => $currency ) );
	}

	public function with_locale( string $locale ): self {
		return $this->derive( array( 'locale' => $locale ) );
	}

	public function with_owner( int $user_id ): self {
		return $this->derive( array( 'owner_user_id' => $user_id ) );
	}

	/**
	 * Build a copy with some fields replaced.
	 *
	 * Routed through the constructor so every derived instance is re-validated — a with_*() that
	 * bypassed validation would let an invalid store exist as long as it was reached by the right
	 * path.
	 *
	 * @param array<string,mixed> $changes Field overrides.
	 * @return self
	 */
	private function derive( array $changes ): self {
		$data = array_merge( $this->to_array(), $changes );

		return self::from_array( $data );
	}

	/* ------------------------------------------------------------------ */
	/* Translation                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'uuid'            => $this->uuid,
			'slug'            => $this->slug,
			'name'            => $this->name,
			'status'          => $this->status,
			'type'            => $this->type,
			'parent_store_id' => $this->parent_store_id,
			'currency'        => $this->currency,
			'locale'          => $this->locale,
			'theme'           => $this->theme,
			'languages'       => $this->languages,
			'timezone'        => $this->timezone,
			'logo_attachment_id' => $this->logo_attachment_id,
			'owner_user_id'   => $this->owner_user_id,
			'created_at'      => $this->created_at,
			'updated_at'      => $this->updated_at,
		);
	}

	/**
	 * Build from a database row or an API payload.
	 *
	 * @param array<string,mixed>|object $data Row.
	 * @return self
	 * @throws \InvalidArgumentException When an invariant is violated.
	 */
	public static function from_array( $data ): self {
		$row = (array) $data;

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(string) ( $row['slug'] ?? '' ),
			(string) ( $row['name'] ?? '' ),
			(string) ( $row['status'] ?? StoreStatus::ACTIVE ),
			(string) ( $row['type'] ?? StoreType::OWNED ),
			(int) ( $row['parent_store_id'] ?? 0 ),
			(string) ( $row['currency'] ?? '' ),
			(string) ( $row['locale'] ?? '' ),
			(string) ( $row['theme'] ?? '' ),
			(string) ( $row['languages'] ?? '' ),
			(string) ( $row['timezone'] ?? '' ),
			(int) ( $row['logo_attachment_id'] ?? 0 ),
			(int) ( $row['owner_user_id'] ?? 0 ),
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' ),
			(string) ( $row['uuid'] ?? '' )
		);
	}
}
