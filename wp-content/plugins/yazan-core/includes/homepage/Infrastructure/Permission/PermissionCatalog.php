<?php
/**
 * The Homepage Manager's contribution to the platform permission catalog.
 *
 * Registered through the `yazan_permission_modules` filter rather than by editing
 * Yazan_Permission_Registry, so this module — and every module after it — declares its own
 * permissions without the core catalog growing a hard-coded block per feature.
 *
 * Per-component slugs (`homepage.section.hero.edit`) are derived from the component registry, so
 * registering a new section automatically produces its permission. Nothing to remember, nothing to
 * keep in sync.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Infrastructure\Permission;

use Yazan\Homepage\Domain\Component\ComponentRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the `homepage` permission module.
 */
final class PermissionCatalog {

	/** Where the module sits in the Role Editor's module list. */
	const SORT = 440;

	/**
	 * Base actions — the module-wide switches, independent of which components exist.
	 *
	 * Note the three-segment keys (`sections.view`). Yazan_Permission_Registry::all() builds a slug
	 * as `module . '.' . action`, so an action key containing a dot produces `homepage.sections.view`
	 * exactly as specified — verified against that method, not assumed.
	 *
	 * @return array<string,?string>
	 */
	public static function actions() {
		return array(
			'view'                => __( 'Open the Homepage Manager.', 'yazan' ),
			'edit'                => __( 'Change homepage content. Does not include publishing.', 'yazan' ),
			'preview'             => __( 'Open the live preview of unpublished changes.', 'yazan' ),
			'publish'             => __( 'Make the current draft the live homepage. This is what visitors see.', 'yazan' ),
			'rollback'            => __( 'Restore an earlier revision into the draft.', 'yazan' ),
			'import'              => __( 'Import a homepage package. Replaces the whole draft.', 'yazan' ),
			'export'              => __( 'Export the homepage as a portable package.', 'yazan' ),
			'settings'            => null,

			'sections.view'       => null,
			'sections.create'     => null,
			'sections.edit'       => __( 'Edit any section. A role can instead be given only specific sections.', 'yazan' ),
			'sections.delete'     => __( 'Remove a section. Its content is kept only in the audit log until the next revision.', 'yazan' ),
			'sections.sort'       => __( 'Reorder sections by dragging.', 'yazan' ),
			'sections.duplicate'  => null,

			'templates.view'      => null,
			'templates.create'    => __( 'Save a section or a whole homepage as a reusable template.', 'yazan' ),
			'templates.delete'    => null,

			'layout.edit'         => __( 'Change widths, columns, spacing and alignment.', 'yazan' ),
			'design.edit'         => __( 'Change any visual property of a section — the umbrella design permission.', 'yazan' ),
			'colors.edit'         => null,
			'typography.edit'     => null,
			'animations.edit'     => null,
			'theme.edit'          => null,
		);
	}

	/**
	 * The module definition handed to the permission registry.
	 *
	 * @param ComponentRegistry $registry Component registry.
	 * @return array
	 */
	public static function module( ComponentRegistry $registry ) {
		$actions = self::actions();

		foreach ( $registry->all() as $component ) {
			$slug = $component->section_permission();

			if ( ! $slug ) {
				continue;
			}

			// 'homepage.section.hero.edit' → action key 'section.hero.edit'.
			$action = substr( $slug, strlen( 'homepage.' ) );

			$actions[ $action ] = sprintf(
				/* translators: %s: section label. */
				__( 'Edit only the "%s" section, without full homepage edit rights.', 'yazan' ),
				$component->label()
			);
		}

		return array(
			'label'   => __( 'Homepage', 'yazan' ),
			'sort'    => self::SORT,
			'actions' => $actions,
		);
	}

	/**
	 * A signature of everything this module contributes.
	 *
	 * The installer re-syncs the catalog when this changes, so adding a component does not require
	 * anyone to remember to bump Yazan_Permission_Registry::REGISTRY_VERSION.
	 *
	 * @param ComponentRegistry $registry Component registry.
	 * @return string
	 */
	public static function signature( ComponentRegistry $registry ) {
		$module = self::module( $registry );

		return md5( (string) wp_json_encode( array_keys( $module['actions'] ) ) );
	}
}
