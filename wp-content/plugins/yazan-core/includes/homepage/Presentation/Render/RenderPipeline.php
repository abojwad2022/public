<?php
/**
 * Decides which sections render, in what order.
 *
 * Reads the PUBLISHED payload only. Draft changes are invisible here by construction, which is
 * what makes "save" safe to press on a live store.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Presentation\Render;

use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Port\ClockPort;
use Yazan\Homepage\Domain\Port\HomepageRepositoryPort;
use Yazan\Homepage\Domain\Port\TemplateRepositoryPort;
use Yazan\Homepage\Domain\Section\Section;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the render plan for one request.
 */
final class RenderPipeline {

	/** @var HomepageRepositoryPort */
	private $documents;

	/** @var ComponentRegistry */
	private $registry;

	/** @var ClockPort */
	private $clock;

	/** @var TemplateRepositoryPort|null */
	private $templates;

	/**
	 * @param HomepageRepositoryPort $documents Repository.
	 * @param ComponentRegistry      $registry  Registry.
	 * @param ClockPort                   $clock     Clock.
	 * @param TemplateRepositoryPort|null $templates Shared-section store; null disables references.
	 */
	public function __construct( HomepageRepositoryPort $documents, ComponentRegistry $registry, ClockPort $clock, TemplateRepositoryPort $templates = null ) {
		$this->documents = $documents;
		$this->registry  = $registry;
		$this->clock     = $clock;
		$this->templates = $templates;
	}

	/**
	 * The ordered, filtered render plan.
	 *
	 * @param DocumentKey $key      Document key.
	 * @param array|null  $override Sections payload to use instead of the published one (preview).
	 * @return array<int,array{section:Section,definition:\Yazan\Homepage\Domain\Component\ComponentDefinition}>
	 */
	public function plan( DocumentKey $key, array $override = null ) {
		$payload = null === $override ? $this->documents->live_payload( $key ) : $override;

		if ( ! $payload ) {
			return array();
		}

		$now  = $this->clock->now();
		$plan = array();

		foreach ( $payload as $raw ) {
			try {
				$section = Section::from_array( (array) $raw );
			} catch ( \InvalidArgumentException $e ) {
				// A malformed row must not take the homepage down with it.
				continue;
			}

			if ( 'global' === $section->type()->value() ) {
				$resolved = $this->resolve_reference( $section );

				if ( ! $resolved ) {
					// A shared section that was deleted renders nothing rather than an error. The
					// reference stays in the document so the editor can see and fix it.
					continue;
				}

				// Visibility and schedule are the REFERENCE's, not the shared section's: the same
				// band may run on one page and be paused on another.
				$section = $resolved
					->with_state( $section->state() )
					->with_visibility( $section->visibility() )
					->with_schedule( $section->schedule() );
			}

			$definition = $this->registry->get( $section->type()->value() );

			if ( ! $definition ) {
				/*
				 * A component that is no longer registered renders NOTHING but is not an error:
				 * unregistering must never blank the site, and the content survives in the
				 * document for the day it comes back.
				 */
				continue;
			}

			if ( ! $section->is_enabled() ) {
				continue;
			}

			if ( $section->visibility()->is_hidden_everywhere() ) {
				continue;
			}

			/*
			 * Per-device hiding is deliberately NOT applied server-side.
			 *
			 * This page is public and fully page-cached; deciding by user agent would let one
			 * visitor's variant be served to everyone who follows. Only "hidden on every device"
			 * is safe to decide here. True per-device visibility arrives with the responsive
			 * system in phase 4, as CSS.
			 */

			if ( ! $section->schedule()->is_active_at( $now ) ) {
				continue;
			}

			$plan[] = array(
				'section'    => $section,
				'definition' => $definition,
			);
		}

		return $plan;
	}

	/**
	 * Swap a reference for the section it points at.
	 *
	 * @param Section $section The reference.
	 * @return Section|null
	 */
	private function resolve_reference( Section $section ) {
		if ( ! $this->templates ) {
			return null;
		}

		$content = $section->content();
		$ref     = isset( $content['ref'] ) ? (int) $content['ref'] : 0;

		if ( $ref <= 0 ) {
			return null;
		}

		$row = $this->templates->find( $ref );

		if ( ! $row || 'global' !== ( $row['kind'] ?? '' ) ) {
			return null;
		}

		$payload = (array) ( $row['payload'][0] ?? array() );
		$type    = (string) ( $payload['type'] ?? '' );

		// A shared section pointing at another shared section would be a loop waiting for someone
		// to close it. One level, refused at the source and again here.
		if ( '' === $type || 'global' === $type ) {
			return null;
		}

		try {
			return Section::from_array( $payload );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * The next moment at which a scheduled section starts or stops.
	 *
	 * The page cache has no idea a section is due to appear at 09:00, so the bridge uses this to
	 * book a single cache purge for that instant. Without it a scheduled banner appears whenever
	 * the cache happens to expire, which is not a schedule.
	 *
	 * @param DocumentKey $key Document key.
	 * @return int|null UTC timestamp, or null when nothing is scheduled.
	 */
	public function next_schedule_boundary( DocumentKey $key ) {
		$payload = $this->documents->live_payload( $key );
		$now     = $this->clock->now();
		$next    = null;

		foreach ( (array) $payload as $raw ) {
			$raw = (array) $raw;

			$windows = array( isset( $raw['schedule'] ) ? (array) $raw['schedule'] : array() );

			$definition = $this->registry->get( (string) ( $raw['type'] ?? '' ) );

			if ( $definition ) {
				foreach ( $definition->schedule_paths() as $path ) {
					$windows = array_merge( $windows, self::collect_windows( (array) ( $raw['content'] ?? array() ), $path ) );
				}
			}

			foreach ( $windows as $window ) {
				foreach ( array( 'from', 'to' ) as $edge ) {
					$time = isset( $window[ $edge ] ) ? (int) $window[ $edge ] : 0;

					if ( $time > $now && ( null === $next || $time < $next ) ) {
						$next = $time;
					}
				}
			}
		}

		return $next;
	}

	/**
	 * Every `{from,to}` window a dotted path resolves to. `*` walks each element of a list.
	 *
	 * @param mixed  $value Current node.
	 * @param string $path  Remaining path.
	 * @return array<int,array>
	 */
	private static function collect_windows( $value, $path ) {
		if ( '' === $path ) {
			return is_array( $value ) && ( isset( $value['from'] ) || isset( $value['to'] ) ) ? array( $value ) : array();
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$parts = explode( '.', $path, 2 );
		$head  = $parts[0];
		$rest  = $parts[1] ?? '';

		if ( '*' === $head ) {
			$out = array();
			foreach ( $value as $item ) {
				$out = array_merge( $out, self::collect_windows( $item, $rest ) );
			}
			return $out;
		}

		return array_key_exists( $head, $value ) ? self::collect_windows( $value[ $head ], $rest ) : array();
	}
}
