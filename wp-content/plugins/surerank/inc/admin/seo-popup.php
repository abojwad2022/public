<?php
/**
 * Post Popup
 *
 * @since 1.0.0
 * @package surerank
 */

namespace SureRank\Inc\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureRank\Inc\Frontend\Crawl_Optimization;
use SureRank\Inc\Frontend\Image_Seo;
use SureRank\Inc\Functions\Get;
use SureRank\Inc\Functions\Update;
use SureRank\Inc\GoogleSearchConsole\Auth as GoogleSearchConsoleAuth;
use SureRank\Inc\GoogleSearchConsole\Controller as GoogleSearchConsoleController;
use SureRank\Inc\GoogleSearchConsole\Url_Inspection;
use SureRank\Inc\Traits\Enqueue;
use SureRank\Inc\Traits\Get_Instance;

/**
 * Post Popup
 *
 * @method void wp_enqueue_scripts()
 * @since 1.0.0
 */
class Seo_Popup {

	use Enqueue;
	use Get_Instance;

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function __construct() {
		if ( ! apply_filters( 'surerank_content_setting_access', current_user_can( 'manage_options' ) ) ) {
			return;
		}

		$this->enqueue_scripts_admin();
		add_action( 'current_screen', [ $this, 'register_term_edit_trigger' ] );
		add_action( 'show_user_profile', [ $this, 'add_user_meta_box_trigger' ] );
		add_action( 'edit_user_profile', [ $this, 'add_user_meta_box_trigger' ] );
		add_action( 'add_meta_boxes', [ $this, 'register_classic_sidebar_meta_box' ], 20, 2 );
		add_action( 'created_category', [ $this, 'update_category_seo_values' ] );
		add_action( 'edited_category', [ $this, 'update_category_seo_values' ] );
		// For enqueue scripts on the frontend.
		add_action( 'wp_enqueue_scripts', [ $this, 'frontend_enqueue_scripts' ] );
	}

	/**
	 * Add tags
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function add_meta_box_trigger() {
		echo '<span id="seo-popup" class="surerank-root"></span>';
	}

	/**
	 * Add SEO popup trigger on user profile edit screens.
	 *
	 * Fires inside the profile form via show_user_profile/edit_user_profile;
	 * the popup JS relocates the trigger next to the page heading.
	 *
	 * @param \WP_User $user The user being edited.
	 * @since 1.9.0
	 * @return void
	 */
	public function add_user_meta_box_trigger( $user ) {
		if ( ! $user instanceof \WP_User || ! Seo_Bar::display_metabox( '', 'wp_users' ) ) {
			return;
		}

		// profile.php is reachable by every role, so gate the trigger on the
		// same capability chain the REST routes use plus edit_user on the
		// target, otherwise subscribers see a button whose API calls 403.
		if ( ! apply_filters( 'surerank_content_setting_access', current_user_can( 'manage_options' ) )
			|| ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		echo '<span id="seo-popup" class="surerank-root"></span>';
	}

	/**
	 * Register the term edit form trigger for the current taxonomy screen.
	 *
	 * @param \WP_Screen $screen Current screen object.
	 * @return void
	 */
	public function register_term_edit_trigger( $screen ): void {
		if ( ! $screen instanceof \WP_Screen || 'term' !== $screen->base || empty( $screen->taxonomy ) ) {
			return;
		}

		if ( ! Seo_Bar::display_metabox( $screen->taxonomy, 'wp_terms' ) ) {
			return;
		}

		add_action( "{$screen->taxonomy}_term_edit_form_top", [ $this, 'add_meta_box_trigger' ] );
	}

	/**
	 * Register the Classic Editor sidebar meta box for opening the SEO popup.
	 *
	 * @param string   $post_type Current post type.
	 * @param \WP_Post $post      Current post object.
	 * @return void
	 */
	public function register_classic_sidebar_meta_box( string $post_type, $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$screen = $this->get_current_screen_safe();
		if ( $screen && ! empty( $screen->is_block_editor ) ) {
			return;
		}

		if ( ! Seo_Bar::display_metabox( $post_type, 'wp_posts' ) ) {
			return;
		}

		$priority = apply_filters( 'surerank_seo_sidebar_box_priority', 'core', $post_type, $post );
		if ( ! in_array( $priority, [ 'high', 'core', 'default', 'low' ], true ) ) {
			$priority = 'core';
		}

		add_meta_box(
			'surerank_classic_seo_box',
			esc_html__( 'SureRank', 'surerank' ),
			[ $this, 'render_classic_sidebar_meta_box' ],
			$post_type,
			'side',
			$priority
		);
	}

	/**
	 * Render the Classic Editor sidebar meta box content.
	 *
	 * @param \WP_Post $post Current post object.
	 * @return void
	 */
	public function render_classic_sidebar_meta_box( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$box_title = apply_filters( 'surerank_seo_sidebar_box_title', __( 'Manage your SEO', 'surerank' ), $post );
		$cta_label = apply_filters( 'surerank_seo_sidebar_cta_label', __( 'Click here', 'surerank' ), $post );
		?>
		<div class="surerank-classic-sidebar-box">
			<p class="surerank-classic-sidebar-box-title"><?php echo esc_html( $box_title ); ?></p>
			<div
				id="surerank-classic-seo-popup-trigger"
				class="surerank-root"
				data-surerank-variant="sidebar"
				data-surerank-cta-label="<?php echo esc_attr( $cta_label ); ?>"
			></div>
		</div>
		<?php
	}

	/**
	 * Enqueue SEO metabox front-end scripts
	 *
	 * @since 1.6.2
	 * @return void
	 */
	public function frontend_enqueue_scripts() {
		// Restrict to singular posts and taxonomy term archives — the only page
		// types where SureRank manages SEO metadata.
		if ( ! is_singular() && ! is_tax() && ! is_tag() && ! is_category() ) {
			return;
		}

		// Skip shared-URL contexts that fire wp_enqueue_scripts but should not render UI.
		if ( wp_doing_ajax()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| is_feed()
			|| is_embed()
			|| is_customize_preview()
			|| is_preview() ) {
			return;
		}

		// Skip third-party visual-builder previews that render on a public URL.
		if ( null !== filter_input( INPUT_GET, 'elementor-preview', FILTER_VALIDATE_INT )
			|| ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() )
			|| ( function_exists( 'et_fb_is_enabled' ) && et_fb_is_enabled() ) ) {
			return;
		}

		// Same gate as the editor metabox and seo-bar: manage_options by
		// default, Pro Role Manager grants surerank_content_setting via
		// the surerank_content_setting_access filter.
		$can_edit = apply_filters( 'surerank_content_setting_access', current_user_can( 'manage_options' ) );
		$post_id  = is_singular() ? (int) get_queried_object_id() : 0;

		/**
		 * Filters whether the current user can open the frontend SEO metabox.
		 *
		 * Pro can hook here to apply license or custom-role restrictions on top
		 * of the default capability check. On singular pages $post_id is the post
		 * ID; on taxonomy archives it is 0.
		 *
		 * @since 1.9.0
		 * @param bool $can_edit Whether the user passes the default capability check.
		 * @param int  $post_id  Post ID for singular views; 0 for taxonomy archives.
		 */
		if ( ! is_user_logged_in() || ! apply_filters( 'surerank_frontend_metabox_access', $can_edit, $post_id ) ) {
			return;
		}

		if ( ! is_admin_bar_showing() ) {
			return;
		}

		add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_menu' ], 100 );

		do_action( 'surerank_seo_popup_frontend_enqueue_scripts' );

		wp_enqueue_media();
		Dashboard::get_instance()->site_seo_check_enqueue_scripts();

		$context_data = $this->get_frontend_context_data();

		if ( ! $context_data ) {
			return;
		}

		$this->enqueue_assets( 'elementor', $context_data );

		$this->build_assets_operations(
			'front-end-meta-box',
			[
				'hook'        => 'front-end-meta-box',
				'object_name' => 'front_end_meta_box',
				'data'        => [],
			]
		);
	}

	/**
	 * Enqueue admin scripts
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function admin_enqueue_scripts() {
		wp_enqueue_media();

		$screen      = $this->get_current_screen_safe();
		$editor_type = self::detect_editor_type( $screen );

		if ( ! self::should_enqueue_scripts( $editor_type, $screen ) ) {
			return;
		}

		$context_data = $this->get_context_data( $editor_type, $screen );
		$this->enqueue_assets( $editor_type, $context_data );
	}

	/**
	 * Add admin bar menu
	 *
	 * @since 1.6.2
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar WP_Admin_Bar instance.
	 *
	 * @return void
	 */
	public function add_admin_bar_menu( $wp_admin_bar ) {
		if ( ! wp_script_is( $this->enqueue_prefix . '-seo-popup', 'enqueued' ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			[
				'id'    => 'surerank-meta-box',
				'title' => '<span class="ab-icon" style="margin-top: 2px;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M13.5537 1.5C17.8453 1.5 21.3251 4.97895 21.3252 9.27051C21.3252 12.347 19.5368 15.0056 16.9434 16.2646H21.3252V22.5H18.0889C14.9086 22.5 12.2861 20.1186 11.9033 17.042H11.9014L11.9033 13.7852C14.8283 13.7661 17.0342 11.3894 17.0342 8.45996V6.0293C14.137 6.02947 11.6948 7.97682 10.9443 10.6338C10.1605 9.53345 8.87383 8.8165 7.41992 8.81641H6.38086V9.85352H6.38379C6.44515 12.0356 8.23375 13.786 10.4307 13.7861H10.7061L10.6934 17.042H10.6865C10.2943 20.1082 7.67678 22.4785 4.50391 22.4785H2.6748V1.5H13.5537Z" fill="currentColor"/></svg></span><span class="ab-label">' . esc_html__( 'SureRank Meta Box', 'surerank' ) . '</span>',
				'href'  => '#',
				'meta'  => [
					'class'   => 'surerank-meta-box-trigger',
					'title'   => esc_html__( 'Open SureRank Meta Box', 'surerank' ),
					'onclick' => 'return false;',
				],
			]
		);
	}

	/**
	 * Update seo values
	 *
	 * @param int $term_id Post ID.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function update_category_seo_values( $term_id ) {
		// Validate post ID.
		if ( empty( $term_id ) || ! is_int( $term_id ) ) {
			return;
		}

		// Update post seo values.
		$result = Update::term_meta( $term_id, [], [] );

		if ( is_wp_error( $result ) ) {
			return;
		}

		do_action( 'surerank_after_update_category_seo_values', $term_id );
	}

	/**
	 * Get keyword checks configuration
	 *
	 * @since 1.0.0
	 * @return array<string>
	 */
	public function keyword_checks() {
		return [
			'keyword_in_title',
			'keyword_in_description',
			'keyword_in_url',
			'keyword_in_content',
		];
	}

	/**
	 * Get page checks configuration
	 *
	 * @since 1.0.0
	 * @return array<string>
	 */
	public function page_checks() {
		return [
			'h2_subheadings',
			'image_alt_text',
			'media_present',
			'links_present',
			'url_length',
			'search_engine_title',
			'search_engine_description',
			'canonical_url',
			'all_links',
			'open_graph_tags',
			'broken_links',
		];
	}

	/**
	 * Detect the current editor type.
	 *
	 * @param \WP_Screen|null $screen Current screen object.
	 * @return string Editor type.
	 */
	public static function detect_editor_type( $screen ): string {
		if ( class_exists( \Elementor\Plugin::class ) && \Elementor\Plugin::instance()->editor->is_edit_mode() ) {
			return 'elementor';
		}

		if ( function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main() ) {
			return 'bricks';
		}

		// Listing pages (post/taxonomy/user list tables) use a dedicated context.
		if ( $screen && in_array( $screen->base, [ 'edit', 'edit-tags', 'users' ], true ) ) {
			return 'listing';
		}

		// User profile edit screens use a dedicated context.
		if ( $screen && in_array( $screen->base, [ 'profile', 'user-edit' ], true ) ) {
			return 'user';
		}

		// Allow integrations (e.g. Divi BFB) to override before the block-editor check.
		$filtered = apply_filters( 'surerank_detect_editor_type', 'classic', $screen );
		if ( 'classic' !== $filtered ) {
			return $filtered;
		}

		if ( $screen && $screen->is_block_editor ) {
			return 'block';
		}

		return 'classic';
	}

	/**
	 * Check if scripts should be enqueued.
	 *
	 * @param string          $editor_type Editor type.
	 * @param \WP_Screen|null $screen Current screen object.
	 * @return bool True if scripts should be enqueued.
	 */
	public static function should_enqueue_scripts( string $editor_type, $screen ): bool {
		if ( $editor_type === 'bricks' ) {
			return true;
		}

		if ( ! $screen || empty( $screen->base ) || ! in_array( $screen->base, [ 'post', 'term', 'edit', 'edit-tags', 'profile', 'user-edit', 'users' ], true ) ) {
			return false;
		}

		if ( in_array( $screen->base, [ 'profile', 'user-edit', 'users' ], true ) ) {
			if ( ! Seo_Bar::display_metabox( '', 'wp_users' ) ) {
				return false;
			}

			// Don't ship the popup bundle to roles that can't use it (e.g.
			// subscribers on their own profile.php). Per-user edit_user is
			// still enforced per row in the users-list column and per request
			// by the REST object guard.
			return (bool) apply_filters( 'surerank_content_setting_access', current_user_can( 'manage_options' ) );
		}

		if ( 'post' === $screen->base && ! empty( $screen->post_type ) ) {
			if ( ! Seo_Bar::display_metabox( $screen->post_type, 'wp_posts' ) ) {
				return false;
			}
		}

		if ( 'term' === $screen->base && ! empty( $screen->taxonomy ) ) {
			if ( ! Seo_Bar::display_metabox( $screen->taxonomy, 'wp_terms' ) ) {
				return false;
			}
		}

		if ( 'edit' === $screen->base && ! empty( $screen->post_type ) ) {
			if ( ! Seo_Bar::display_metabox( $screen->post_type, 'wp_posts' ) ) {
				return false;
			}
		}

		if ( 'edit-tags' === $screen->base && ! empty( $screen->taxonomy ) ) {
			if ( ! Seo_Bar::display_metabox( $screen->taxonomy, 'wp_terms' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get current screen safely.
	 *
	 * @return \WP_Screen|null
	 */
	private function get_current_screen_safe() {
		return function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	}

	/**
	 * Get context data for the current page.
	 *
	 * @param string          $editor_type Editor type.
	 * @param \WP_Screen|null $screen Current screen object.
	 * @return array{post_data: array<string, mixed>, term_data: array<string, mixed>, user_data: array<string, mixed>, post_type: string, is_taxonomy: bool, is_user: bool, is_frontend?: bool} Context data.
	 */
	private function get_context_data( string $editor_type, $screen ): array {
		$post_data = $this->get_post_data( $editor_type, $screen );
		$term_data = $this->get_term_data( $screen );
		$user_data = $this->get_user_data( $screen );

		return [
			'post_data'   => $post_data,
			'term_data'   => $term_data,
			'user_data'   => $user_data,
			'post_type'   => $this->get_post_type( $editor_type, $screen ),
			'is_taxonomy' => $this->is_taxonomy( $editor_type, $screen ),
			'is_user'     => $this->is_user( $screen ),
		];
	}

	/**
	 * Get post data if on post edit screen.
	 *
	 * @param string          $editor_type Editor type.
	 * @param \WP_Screen|null $screen Current screen object.
	 * @return array<string, mixed> Post data.
	 */
	private function get_post_data( string $editor_type, $screen ): array {
		if ( ( $screen && 'post' === $screen->base ) || $editor_type === 'bricks' ) {
			$post_id = get_the_ID();
			if ( ! $post_id ) {
				return [];
			}
			return [
				'post_id'     => $post_id,
				'editor_type' => $editor_type,
				'link'        => get_the_permalink( $post_id ),
			];
		}

		return [];
	}

	/**
	 * Get term data if on term edit screen.
	 *
	 * @param \WP_Screen|null $screen Current screen object.
	 * @return array<string, mixed> Term data.
	 */
	private function get_term_data( $screen ): array {
		if ( ! $screen || 'term' !== $screen->base ) {
			return [];
		}

		global $tag_ID;

		$final_link = get_term_link( (int) $tag_ID );
		if ( is_wp_error( $final_link ) ) {
			return [];
		}

		$final_link = $this->process_category_link( $final_link, $tag_ID, $screen );

		return [
			'term_id' => $tag_ID,
			'link'    => $final_link,
		];
	}

	/**
	 * Get user data if on a user profile edit screen.
	 *
	 * @param \WP_Screen|null $screen Current screen object.
	 * @since 1.9.0
	 * @return array<string, mixed> User data.
	 */
	private function get_user_data( $screen ): array {
		if ( ! $screen || ! in_array( $screen->base, [ 'profile', 'user-edit' ], true ) ) {
			return [];
		}

		if ( 'user-edit' === $screen->base ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only context detection; capability enforced below and on save.
			$user_id = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0;
		} else {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
			return [];
		}

		return [
			'user_id' => $user_id,
			'link'    => get_author_posts_url( $user_id ),
		];
	}

	/**
	 * Check if current context is a single-user edit screen (profile or user-edit).
	 *
	 * Intentionally excludes the users.php listing: no user_id exists there at
	 * load time — the seo-bar badge click handler sets is_user/user_id on the
	 * JS side when a specific user is selected.
	 *
	 * @param \WP_Screen|null $screen Current screen object.
	 * @since 1.9.0
	 * @return bool True if user context.
	 */
	private function is_user( $screen ): bool {
		return $screen && in_array( $screen->base, [ 'profile', 'user-edit' ], true );
	}

	/**
	 * Process category link if needed.
	 *
	 * @param string          $link Term link.
	 * @param int             $tag_ID Term ID.
	 * @param \WP_Screen|null $screen Current screen object.
	 * @return string Processed link.
	 */
	private function process_category_link( string $link, int $tag_ID, $screen ): string {
		if ( $screen && 'category' === $screen->taxonomy && apply_filters( 'surerank_remove_category_base', false ) ) {
			$term = get_term( $tag_ID );
			if ( $term && ! is_wp_error( $term ) ) {
				return Crawl_Optimization::get_instance()->remove_category_base_from_links( $link, $term, $screen->taxonomy );
			}
		}

		return $link;
	}

	/**
	 * Get post type for current context.
	 *
	 * @param string          $editor_type Editor type.
	 * @param \WP_Screen|null $screen Current screen object.
	 * @return string Post type.
	 */
	private function get_post_type( string $editor_type, $screen ): string {
		if ( $editor_type === 'bricks' ) {
			$post_id   = get_the_ID();
			$post_type = $post_id ? get_post_type( $post_id ) : false;
			return $post_type !== false ? $post_type : '';
		}

		if ( ! $screen ) {
			return '';
		}

		return ! empty( $screen->taxonomy ) ? $screen->taxonomy : $screen->post_type;
	}

	/**
	 * Check if current context is taxonomy.
	 *
	 * @param string          $editor_type Editor type.
	 * @param \WP_Screen|null $screen Current screen object.
	 * @return bool True if taxonomy context.
	 */
	private function is_taxonomy( string $editor_type, $screen ): bool {
		if ( $editor_type === 'bricks' ) {
			return false;
		}

		return $screen && ! empty( $screen->taxonomy );
	}

	/**
	 * Enqueue assets for SEO popup.
	 *
	 * @param string                                                                                                                                                                              $editor_type Editor type.
	 * @param array{post_data: array<string, mixed>, term_data: array<string, mixed>, user_data?: array<string, mixed>, post_type: string, is_taxonomy: bool, is_user?: bool, is_frontend?: bool} $context_data Context data.
	 * @return void
	 */
	private function enqueue_assets( string $editor_type, array $context_data ): void {
		$this->enqueue_vendor_and_common_assets();

		$this->build_assets_operations(
			'seo-popup',
			[
				'hook'        => 'seo-popup',
				'object_name' => 'seo_popup',
				'data'        => array_merge(
					[
						'admin_assets_url'         => SURERANK_URL . 'inc/admin/assets',
						'site_icon_url'            => get_site_icon_url( 16 ),
						'editor_type'              => $editor_type,
						'post_type'                => $context_data['post_type'],
						'is_taxonomy'              => $context_data['is_taxonomy'],
						'is_user'                  => $context_data['is_user'] ?? false,
						'description_length'       => Get::description_length(),
						'title_length'             => Get::title_length(),
						'keyword_checks'           => $this->keyword_checks(),
						'page_checks'              => $this->page_checks(),
						'image_seo'                => Image_Seo::get_instance()->status(),
						'is_frontend'              => $context_data['is_frontend'] ?? false,
						'broken_link_ignored_urls' => Get::option( 'surerank_broken_link_ignored_urls', [] ),
					],
					$context_data['post_data'],
					$context_data['term_data'],
					$context_data['user_data'] ?? [],
					$this->get_indexing_status_localization( $context_data )
				),
			]
		);
	}

	/**
	 * Get frontend context data.
	 *
	 * @return array{post_data: array<string, mixed>, term_data: array<string, mixed>, post_type: string, is_taxonomy: bool, is_frontend: bool}|false Context data or false if invalid.
	 */
	private function get_frontend_context_data() {
		$post_data   = [];
		$term_data   = [];
		$post_type   = '';
		$is_taxonomy = false;

		if ( is_singular() ) {
			$post_id = get_the_ID();
			if ( ! $post_id ) {
				return false;
			}
			$post_type = get_post_type( $post_id );
			if ( ! $post_type ) {
				return false;
			}
			$post_data = [
				'post_id'     => $post_id,
				'editor_type' => apply_filters( 'surerank_frontend_editor_type', 'classic' ),
				'link'        => get_the_permalink( $post_id ),
			];
		} elseif ( is_tax() || is_tag() || is_category() ) {
			$object = get_queried_object();
			if ( ! $object instanceof \WP_Term ) {
				return false;
			}
			$term_link = get_term_link( $object );
			if ( is_wp_error( $term_link ) ) {
				return false;
			}
			$term_data   = [
				'term_id' => $object->term_id,
				'link'    => $term_link,
			];
			$post_type   = $object->taxonomy;
			$is_taxonomy = true;
		} else {
			return false;
		}

		return [
			'post_data'   => $post_data,
			'term_data'   => $term_data,
			'post_type'   => $post_type,
			'is_taxonomy' => $is_taxonomy,
			'is_frontend' => true,
		];
	}

	/**
	 * Build the indexing-status slice of the localized SEO popup data.
	 *
	 * The pill mounts inside the popup header and needs four things at
	 * boot: whether GSC is connected, whether a site property is selected,
	 * whether that selected property matches the current WordPress site,
	 * and the last cached inspection result for the current post or term.
	 * Returning the cached value lets the pill paint on first frame
	 * without a REST round-trip. The cache is suppressed when the GSC
	 * property doesn't match this site so a previous property's result
	 * can't bleed through.
	 *
	 * @param array<string, mixed> $context_data Result of get_context_data().
	 * @return array<string, mixed>
	 * @since 1.7.5
	 */
	private function get_indexing_status_localization( array $context_data ): array {
		$is_connected   = (bool) GoogleSearchConsoleController::get_instance()->get_auth_status();
		$selected_site  = (string) GoogleSearchConsoleAuth::get_instance()->get_credentials( 'site_url' );
		$has_site       = '' !== $selected_site;
		$is_matching    = $is_connected && $has_site && Url_Inspection::selected_site_matches_current();
		$indexing_cache = null;

		if ( $is_matching ) {
			$post_id = isset( $context_data['post_data']['post_id'] )
				? absint( $context_data['post_data']['post_id'] )
				: 0;
			$term_id = isset( $context_data['term_data']['term_id'] )
				? absint( $context_data['term_data']['term_id'] )
				: 0;

			if ( $term_id ) {
				$cached = get_term_meta( $term_id, Url_Inspection::META_KEY, true );
			} elseif ( $post_id ) {
				$cached = get_post_meta( $post_id, Url_Inspection::META_KEY, true );
			} else {
				$cached = null;
			}

			$indexing_cache = is_array( $cached ) && ! empty( $cached ) ? $cached : null;
		}

		return [
			'is_gsc_connected'      => $is_connected,
			'has_gsc_site_selected' => $has_site,
			'is_gsc_site_matching'  => $is_matching,
			'indexing_status'       => $indexing_cache,
			'indexing_fresh_ttl'    => Url_Inspection::FRESH_TTL,
		];
	}

}
