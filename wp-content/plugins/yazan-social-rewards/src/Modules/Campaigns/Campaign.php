<?php
/**
 * Marketing campaign value object.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Campaigns;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A workflow-based marketing campaign. Immutable snapshot of a
 * `marketing_campaigns` row.
 */
final class Campaign {

	public const STATUS_DRAFT     = 'draft';
	public const STATUS_SCHEDULED = 'scheduled';
	public const STATUS_ACTIVE    = 'active';
	public const STATUS_PAUSED    = 'paused';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_ARCHIVED  = 'archived';

	/**
	 * @param int                 $id            Row id.
	 * @param string              $title         Title.
	 * @param string              $description   Description.
	 * @param array<string,mixed> $media         { images:[], videos:[] }.
	 * @param array<string,mixed> $target        { type: all|tier|role|users, values:[] }.
	 * @param int                 $reward_amount Completion bonus points.
	 * @param string              $status        One of STATUS_*.
	 * @param string|null         $starts_at     Y-m-d H:i:s or null.
	 * @param string|null         $ends_at       Y-m-d H:i:s or null.
	 */
	public function __construct(
		public int $id,
		public string $title,
		public string $description,
		public array $media,
		public array $target,
		public int $reward_amount,
		public string $status,
		public ?string $starts_at = null,
		public ?string $ends_at = null
	) {}

	/**
	 * Build from a DB row.
	 *
	 * @param object $row Row.
	 * @return Campaign
	 */
	public static function from_row( object $row ): Campaign {
		$media  = json_decode( (string) ( $row->media ?? '' ), true );
		$target = json_decode( (string) ( $row->target ?? '' ), true );
		$zero   = '0000-00-00 00:00:00';
		$start  = ( isset( $row->starts_at ) && $zero !== $row->starts_at ) ? (string) $row->starts_at : null;
		$end    = ( isset( $row->ends_at ) && $zero !== $row->ends_at ) ? (string) $row->ends_at : null;

		return new self(
			(int) $row->id,
			(string) $row->title,
			(string) $row->description,
			is_array( $media ) ? $media : array(),
			is_array( $target ) ? $target : array( 'type' => 'all', 'values' => array() ),
			(int) ( $row->reward_amount ?? 0 ),
			(string) $row->status,
			$start,
			$end
		);
	}

	/** Whether the campaign currently accepts submissions. */
	public function is_active(): bool {
		return self::STATUS_ACTIVE === $this->status;
	}

	/**
	 * Public-safe array.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'            => $this->id,
			'title'         => $this->title,
			'description'   => $this->description,
			'media'         => $this->media,
			'target'        => $this->target,
			'reward_amount' => $this->reward_amount,
			'status'        => $this->status,
			'starts_at'     => $this->starts_at,
			'ends_at'       => $this->ends_at,
		);
	}
}
