<?php
/**
 * Portable homepage packages.
 *
 * The package is JSON: the document plus a manifest of the media it references. Media travel by
 * REFERENCE, never as embedded binary — a homepage with a dozen photographs would otherwise be a
 * base64 file nobody can open, diff or store sensibly.
 *
 * What import does with those references is deliberately conservative: an attachment id that
 * exists locally with a matching mime type is kept, anything else is set to 0 and REPORTED.
 * Fetching an image from a URL inside an uploaded file would be a server-side request forgery
 * primitive handed to whoever can import — sideloading belongs behind its own decision, not
 * smuggled in as a convenience.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Service;

use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Domain\Component\ComponentSchema;
use Yazan\Homepage\Domain\Component\FieldType;
use Yazan\Homepage\Domain\Document\HomepageDocument;
use Yazan\Homepage\Domain\Exception\ValidationFailed;
use Yazan\Homepage\Domain\Port\MediaPort;
use Yazan\Homepage\Infrastructure\Persistence\DocumentSerializer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and reads homepage packages.
 */
final class ImportExportService {

	/** Package marker. Anything else is refused outright. */
	const FORMAT = 'yazan-homepage';

	/** Package structure version — bumped only when the envelope changes. */
	const FORMAT_VERSION = 1;

	/** @var ComponentRegistry */
	private $registry;

	/** @var MediaPort */
	private $media;

	/**
	 * @param ComponentRegistry $registry Registry.
	 * @param MediaPort         $media    Media port.
	 */
	public function __construct( ComponentRegistry $registry, MediaPort $media ) {
		$this->registry = $registry;
		$this->media    = $media;
	}

	/**
	 * Build a package from a document.
	 *
	 * @param HomepageDocument $document Document.
	 * @param bool             $live     Export the published sections rather than the draft.
	 * @return array
	 */
	public function export( HomepageDocument $document, $live = false ) {
		$sections = $live ? $document->live_sections() : $document->sections()->to_array();

		return array(
			'format'         => self::FORMAT,
			'format_version' => self::FORMAT_VERSION,
			'schema_version' => DocumentSerializer::PAYLOAD_VERSION,
			'exported_at'    => gmdate( 'c' ),
			'site'           => home_url( '/' ),
			'document'       => array(
				'key'      => $document->key()->value(),
				'title'    => $document->title(),
				'sections' => $sections,
			),
			// Enough to identify each image on the other side without shipping it. No user ids,
			// no timestamps: a design package is not a place for people's data.
			'media'          => $this->media_manifest( $sections ),
		);
	}

	/**
	 * Validate a package and report exactly what an import would do.
	 *
	 * Always run before a real import — the same rule Yazan_REST_Porting enforces for CSV.
	 *
	 * @param array $package Decoded package.
	 * @return array{sections:int,types:array,media_kept:int,media_dropped:array,warnings:array}
	 * @throws ValidationFailed When the package cannot be imported at all.
	 */
	public function inspect( array $package ) {
		if ( ( $package['format'] ?? '' ) !== self::FORMAT ) {
			throw new ValidationFailed( 'This file is not a YAZAN homepage package.' );
		}

		if ( (int) ( $package['format_version'] ?? 0 ) > self::FORMAT_VERSION ) {
			throw ( new ValidationFailed( 'This package was made by a newer version of the Homepage Manager.' ) )
				->with_context( array( 'format_version' => (int) $package['format_version'] ) );
		}

		$sections = (array) ( $package['document']['sections'] ?? array() );

		if ( ! $sections ) {
			throw new ValidationFailed( 'This package contains no sections.' );
		}

		$types   = array();
		$unknown = array();

		foreach ( $sections as $section ) {
			$type          = (string) ( ( (array) $section )['type'] ?? '' );
			$types[$type]  = ( $types[ $type ] ?? 0 ) + 1;

			if ( ! $this->registry->get( $type ) ) {
				$unknown[ $type ] = true;
			}
		}

		if ( $unknown ) {
			/*
			 * The WHOLE package is refused, not the offending section.
			 *
			 * Importing "most of" a homepage produces a page that looks finished and is not, and
			 * the person who notices is a customer. A refusal names what is missing and can be
			 * acted on.
			 */
			throw ( new ValidationFailed( 'This package needs components that are not installed here.' ) )
				->with_context( array( 'missing_components' => array_keys( $unknown ) ) );
		}

		$kept    = 0;
		$dropped = array();

		foreach ( $this->media_ids( $sections ) as $id ) {
			if ( $this->media->is_allowed( $id, array_merge( \Yazan\Homepage\Infrastructure\Adapter\WpMediaAdapter::IMAGE_MIMES, \Yazan\Homepage\Infrastructure\Adapter\WpMediaAdapter::VIDEO_MIMES ) ) ) {
				++$kept;
				continue;
			}

			$dropped[] = $id;
		}

		return array(
			'sections'      => count( $sections ),
			'types'         => $types,
			'media_kept'    => $kept,
			'media_dropped' => $dropped,
			'warnings'      => $dropped
				? array( sprintf( '%d image reference(s) do not exist on this site and will be cleared.', count( $dropped ) ) )
				: array(),
		);
	}

	/**
	 * The sections a package would produce, with unresolvable media cleared.
	 *
	 * @param array $package Decoded package.
	 * @return array
	 */
	public function sections_from( array $package ) {
		$this->inspect( $package );

		$allowed = array_merge(
			\Yazan\Homepage\Infrastructure\Adapter\WpMediaAdapter::IMAGE_MIMES,
			\Yazan\Homepage\Infrastructure\Adapter\WpMediaAdapter::VIDEO_MIMES
		);

		$out = array();

		foreach ( (array) $package['document']['sections'] as $section ) {
			$section = (array) $section;

			$definition = $this->registry->get( (string) $section['type'] );

			$section['content'] = $this->clear_missing_media(
				$definition->schema(),
				(array) ( $section['content'] ?? array() ),
				$allowed
			);

			$out[] = $section;
		}

		return $out;
	}

	/* ------------------------------------------------------------------ media */

	/**
	 * @param array $sections Sections.
	 * @return array<int,array>
	 */
	private function media_manifest( array $sections ) {
		$manifest = array();

		foreach ( $this->media_ids( $sections ) as $id ) {
			$image = $this->media->image( $id, 'full' );

			$manifest[] = array(
				'ref' => $id,
				'url' => $image['url'],
				'alt' => $image['alt'],
			);
		}

		return $manifest;
	}

	/**
	 * Every attachment id a section payload references, found through the schema rather than by
	 * guessing which numbers look like ids.
	 *
	 * @param array $sections Sections.
	 * @return int[]
	 */
	private function media_ids( array $sections ) {
		$ids = array();

		foreach ( $sections as $section ) {
			$section    = (array) $section;
			$definition = $this->registry->get( (string) ( $section['type'] ?? '' ) );

			if ( ! $definition ) {
				continue;
			}

			$this->walk_media(
				$definition->schema(),
				(array) ( $section['content'] ?? array() ),
				static function ( $id ) use ( &$ids ) {
					if ( $id > 0 ) {
						$ids[ $id ] = true;
					}
				}
			);
		}

		return array_map( 'intval', array_keys( $ids ) );
	}

	/**
	 * Visit every media value in a payload.
	 *
	 * @param ComponentSchema $schema  Schema.
	 * @param array           $content Content.
	 * @param callable        $visit   Receives each id.
	 * @return void
	 */
	private function walk_media( ComponentSchema $schema, array $content, callable $visit ) {
		foreach ( $schema->fields() as $key => $field ) {
			$value = $content[ $key ] ?? null;

			if ( FieldType::is_container( $field->type() ) && $field->children() ) {
				if ( FieldType::REPEATER === $field->type() ) {
					foreach ( (array) $value as $row ) {
						$this->walk_media( $field->children(), (array) $row, $visit );
					}
				} else {
					$this->walk_media( $field->children(), (array) $value, $visit );
				}
				continue;
			}

			if ( FieldType::MEDIA === $field->type() || FieldType::VIDEO === $field->type() ) {
				$visit( (int) ( is_array( $value ) ? ( $value['desktop'] ?? 0 ) : $value ) );
				continue;
			}

			if ( FieldType::GALLERY === $field->type() ) {
				foreach ( (array) $value as $id ) {
					$visit( (int) $id );
				}
			}
		}
	}

	/**
	 * Zero out media ids that do not resolve here.
	 *
	 * @param ComponentSchema $schema  Schema.
	 * @param array           $content Content.
	 * @param string[]        $allowed Allowed mimes.
	 * @return array
	 */
	private function clear_missing_media( ComponentSchema $schema, array $content, array $allowed ) {
		foreach ( $schema->fields() as $key => $field ) {
			if ( ! array_key_exists( $key, $content ) ) {
				continue;
			}

			$value = $content[ $key ];

			if ( FieldType::is_container( $field->type() ) && $field->children() ) {
				if ( FieldType::REPEATER === $field->type() ) {
					$rows = array();
					foreach ( (array) $value as $row ) {
						$rows[] = $this->clear_missing_media( $field->children(), (array) $row, $allowed );
					}
					$content[ $key ] = $rows;
				} else {
					$content[ $key ] = $this->clear_missing_media( $field->children(), (array) $value, $allowed );
				}
				continue;
			}

			if ( FieldType::MEDIA === $field->type() || FieldType::VIDEO === $field->type() ) {
				$id              = (int) ( is_array( $value ) ? ( $value['desktop'] ?? 0 ) : $value );
				$content[ $key ] = $this->media->is_allowed( $id, $allowed ) ? $id : 0;
				continue;
			}

			if ( FieldType::GALLERY === $field->type() ) {
				$kept = array();
				foreach ( (array) $value as $id ) {
					if ( $this->media->is_allowed( (int) $id, $allowed ) ) {
						$kept[] = (int) $id;
					}
				}
				$content[ $key ] = $kept;
			}
		}

		return $content;
	}
}
