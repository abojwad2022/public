<?php
/**
 * Rule value object.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An immutable earning rule loaded from the `rules` table. Describes, for a given
 * event, how many points to award and under what conditions.
 */
final class Rule {

	/**
	 * @param int                 $id         Row id.
	 * @param string              $name       Human name.
	 * @param string              $event      Event key this rule reacts to.
	 * @param array<string,mixed> $conditions Condition map (ANDed).
	 * @param string              $award_type fixed|per_currency.
	 * @param float               $award_value Points (fixed) or points-per-currency-unit.
	 * @param int                 $priority   Lower runs first.
	 * @param int                 $per_user_cap 0 = uncapped.
	 * @param bool                $active     Enabled.
	 * @param string|null         $starts_at  Y-m-d H:i:s or null.
	 * @param string|null         $ends_at    Y-m-d H:i:s or null.
	 * @param array<int,array>    $actions    Ordered action list (EVENT→CONDITIONS→ACTIONS engine).
	 *                                        Empty for legacy points-only base rules.
	 */
	public function __construct(
		public int $id,
		public string $name,
		public string $event,
		public array $conditions,
		public string $award_type,
		public float $award_value,
		public int $priority = 10,
		public int $per_user_cap = 0,
		public bool $active = true,
		public ?string $starts_at = null,
		public ?string $ends_at = null,
		public array $actions = array()
	) {}

	/**
	 * Build from a DB row.
	 *
	 * @param object $row Row.
	 * @return Rule
	 */
	public static function from_row( object $row ): Rule {
		$conditions = json_decode( (string) ( $row->conditions ?? '' ), true );
		$actions    = json_decode( (string) ( $row->actions ?? '' ), true );

		// The DB stores an unset window as the zero date — normalise to null so
		// the time-window check treats it as "no bound".
		$zero  = '0000-00-00 00:00:00';
		$start = ( isset( $row->starts_at ) && $zero !== $row->starts_at ) ? (string) $row->starts_at : null;
		$end   = ( isset( $row->ends_at ) && $zero !== $row->ends_at ) ? (string) $row->ends_at : null;

		return new self(
			(int) $row->id,
			(string) $row->name,
			(string) $row->event,
			is_array( $conditions ) ? $conditions : array(),
			(string) $row->award_type,
			(float) $row->award_value,
			(int) ( $row->priority ?? 10 ),
			(int) ( $row->per_user_cap ?? 0 ),
			(bool) ( (int) ( $row->active ?? 1 ) ),
			$start,
			$end,
			is_array( $actions ) ? $actions : array()
		);
	}

	/**
	 * Whether this rule carries an explicit action list (EVENT→CONDITIONS→ACTIONS).
	 * Such rules are executed by the RuleEngine; actionless rules stay on the
	 * legacy points-only `evaluate()` path.
	 *
	 * @return bool
	 */
	public function has_actions(): bool {
		return ! empty( $this->actions );
	}

	/**
	 * Public-safe array for the admin API.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'           => $this->id,
			'name'         => $this->name,
			'event'        => $this->event,
			'conditions'   => $this->conditions,
			'actions'      => $this->actions,
			'award_type'   => $this->award_type,
			'award_value'  => $this->award_value,
			'priority'     => $this->priority,
			'per_user_cap' => $this->per_user_cap,
			'active'       => $this->active,
			'starts_at'    => $this->starts_at,
			'ends_at'      => $this->ends_at,
		);
	}

	/**
	 * Whether the rule is within its active time window right now.
	 *
	 * @return bool
	 */
	public function is_live(): bool {
		if ( ! $this->active ) {
			return false;
		}
		$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		if ( $this->starts_at && strtotime( $this->starts_at ) > $now ) {
			return false;
		}
		if ( $this->ends_at && strtotime( $this->ends_at ) < $now ) {
			return false;
		}
		return true;
	}
}
