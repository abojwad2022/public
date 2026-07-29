<?php
/**
 * Yazan Core — wp-admin backup & restore screen.
 *
 * Lives under **Tools → Yazan Backup** and is the wp-admin counterpart to the dashboard's Backup tab.
 * It shares the exact same {@see Yazan_Backup_Engine}, so a backup made in either place restores in
 * either place. The screen can:
 *
 *   - create a full or database-only backup,
 *   - download / delete a stored archive,
 *   - restore from a stored archive (any size — no upload involved),
 *   - restore from an uploaded archive (bounded by PHP's `upload_max_filesize`, ~300 MB here).
 *
 * Every action is a POST to `admin-post.php`, guarded by a per-action nonce and a `manage_options`
 * capability check — restore overwrites the whole site, so it is administrator-only by design.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Tools → Yazan Backup admin page and its form handlers.
 */
class Yazan_Backup_Admin {

	/** Page slug (also the query arg on tools.php). */
	const SLUG = 'yazan-backup';

	/** Capability required for the whole screen. */
	const CAP = 'manage_options';

	/**
	 * Register the menu + form handlers. Call once at plugin load.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_yazan_backup_create', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_yazan_backup_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_yazan_backup_download', array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_yazan_backup_restore', array( __CLASS__, 'handle_restore' ) );
		add_action( 'admin_post_yazan_backup_restore_upload', array( __CLASS__, 'handle_restore_upload' ) );
	}

	/**
	 * Add the Tools submenu.
	 *
	 * @return void
	 */
	public static function add_page() {
		add_management_page(
			__( 'Yazan Backup', 'yazan' ),
			__( 'Yazan Backup', 'yazan' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/* --------------------------------------------------------------------- */
	/* Handlers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Create a backup.
	 *
	 * @return void
	 */
	public static function handle_create() {
		self::guard( 'yazan_backup_create' );

		$scope  = isset( $_POST['scope'] ) && 'db' === sanitize_key( wp_unslash( $_POST['scope'] ) ) ? 'db' : 'full';
		$result = Yazan_Backup_Engine::create( $scope );

		if ( is_wp_error( $result ) ) {
			self::redirect( 'error', $result->get_error_message() );
		}
		if ( class_exists( 'Yazan_Dashboard_Audit' ) ) {
			Yazan_Dashboard_Audit::log( 'backup.create', 'settings', 0, array( 'scope' => $scope, 'id' => $result['id'], 'via' => 'wp-admin' ) );
		}
		self::redirect( 'created', $result['filename'] );
	}

	/**
	 * Delete a stored backup.
	 *
	 * @return void
	 */
	public static function handle_delete() {
		self::guard( 'yazan_backup_delete' );

		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		if ( Yazan_Backup_Engine::path_for( $id ) ) {
			Yazan_Backup_Engine::delete( $id );
			if ( class_exists( 'Yazan_Dashboard_Audit' ) ) {
				Yazan_Dashboard_Audit::log( 'backup.delete', 'settings', 0, array( 'id' => $id, 'via' => 'wp-admin' ) );
			}
			self::redirect( 'deleted' );
		}
		self::redirect( 'error', __( 'That backup could not be found.', 'yazan' ) );
	}

	/**
	 * Stream a stored backup as a download.
	 *
	 * @return void
	 */
	public static function handle_download() {
		self::guard( 'yazan_backup_download' );

		$id   = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$path = Yazan_Backup_Engine::path_for( $id );
		if ( ! $path ) {
			self::redirect( 'error', __( 'That backup could not be found.', 'yazan' ) );
		}
		Yazan_REST_Backup::stream_file( $path ); // Exits.
	}

	/**
	 * Restore from a stored backup.
	 *
	 * @return void
	 */
	public static function handle_restore() {
		self::guard( 'yazan_backup_restore' );

		$id      = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$confirm = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';

		if ( 'RESTORE' !== $confirm ) {
			self::redirect( 'error', __( 'Type RESTORE to confirm — this overwrites your live site.', 'yazan' ) );
		}
		if ( ! Yazan_Backup_Engine::path_for( $id ) ) {
			self::redirect( 'error', __( 'That backup could not be found.', 'yazan' ) );
		}

		if ( class_exists( 'Yazan_Dashboard_Audit' ) ) {
			Yazan_Dashboard_Audit::log( 'backup.restore', 'settings', 0, array( 'id' => $id, 'via' => 'wp-admin' ) );
		}
		$result = Yazan_Backup_Engine::restore( $id, true );
		if ( is_wp_error( $result ) ) {
			self::redirect( 'error', $result->get_error_message() );
		}
		self::redirect( 'restored', self::summarise( $result ) );
	}

	/**
	 * Restore from an uploaded archive.
	 *
	 * @return void
	 */
	public static function handle_restore_upload() {
		self::guard( 'yazan_backup_restore_upload' );

		$confirm = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';
		if ( 'RESTORE' !== $confirm ) {
			self::redirect( 'error', __( 'Type RESTORE to confirm — this overwrites your live site.', 'yazan' ) );
		}

		if ( empty( $_FILES['archive'] ) || ! isset( $_FILES['archive']['tmp_name'] ) || UPLOAD_ERR_OK !== ( $_FILES['archive']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			self::redirect( 'error', __( 'No file was uploaded, or it exceeded the server upload limit. For large backups, restore from the list above instead.', 'yazan' ) );
		}

		$tmp  = sanitize_text_field( wp_unslash( $_FILES['archive']['tmp_name'] ) );
		$name = sanitize_file_name( wp_unslash( $_FILES['archive']['name'] ?? 'upload.zip' ) );

		if ( ! is_uploaded_file( $tmp ) ) {
			self::redirect( 'error', __( 'The uploaded file could not be verified.', 'yazan' ) );
		}
		if ( 'zip' !== strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			self::redirect( 'error', __( 'Please upload a .zip backup archive.', 'yazan' ) );
		}

		if ( class_exists( 'Yazan_Dashboard_Audit' ) ) {
			Yazan_Dashboard_Audit::log( 'backup.restore_upload', 'settings', 0, array( 'file' => $name, 'via' => 'wp-admin' ) );
		}
		$result = Yazan_Backup_Engine::restore_from_file( $tmp, true );
		if ( is_wp_error( $result ) ) {
			self::redirect( 'error', $result->get_error_message() );
		}
		self::redirect( 'restored', self::summarise( $result ) );
	}

	/* --------------------------------------------------------------------- */
	/* Rendering                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Render the admin screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage backups.', 'yazan' ) );
		}

		$backups   = Yazan_Backup_Engine::all();
		$supported = Yazan_Backup_Engine::is_supported();
		$max_upload = size_format( wp_max_upload_size() );
		$action_url = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Yazan Backup', 'yazan' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Create a compressed backup of the whole site (database + wp-content), download it, or restore the site from one. Shares the same archives as the dashboard Backup tab.', 'yazan' ); ?>
			</p>

			<?php self::render_notice(); ?>

			<?php if ( ! $supported ) : ?>
				<div class="notice notice-error"><p>
					<?php esc_html_e( 'The PHP ZipArchive extension is not available, so backups cannot be created or restored on this server.', 'yazan' ); ?>
				</p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Create a backup', 'yazan' ); ?></h2>
			<form method="post" action="<?php echo $action_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" style="margin-bottom:2em">
				<input type="hidden" name="action" value="yazan_backup_create">
				<?php wp_nonce_field( 'yazan_backup_create' ); ?>
				<label>
					<select name="scope">
						<option value="full"><?php esc_html_e( 'Full site — database + wp-content', 'yazan' ); ?></option>
						<option value="db"><?php esc_html_e( 'Database only', 'yazan' ); ?></option>
					</select>
				</label>
				<button type="submit" class="button button-primary" <?php disabled( ! $supported ); ?>>
					<?php esc_html_e( 'Create backup now', 'yazan' ); ?>
				</button>
				<span class="description"><?php esc_html_e( 'A large site may take a minute or two — please wait for the page to reload.', 'yazan' ); ?></span>
			</form>

			<h2><?php esc_html_e( 'Stored backups', 'yazan' ); ?></h2>
			<?php if ( empty( $backups ) ) : ?>
				<p><?php esc_html_e( 'No backups yet.', 'yazan' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Created (UTC)', 'yazan' ); ?></th>
							<th><?php esc_html_e( 'Scope', 'yazan' ); ?></th>
							<th><?php esc_html_e( 'Size', 'yazan' ); ?></th>
							<th><?php esc_html_e( 'Contents', 'yazan' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'yazan' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $backups as $b ) : ?>
							<tr>
								<td>
									<?php echo esc_html( $b['created_at'] ); ?>
									<?php if ( ! empty( $b['note'] ) ) : ?>
										<br><em class="description"><?php echo esc_html( $b['note'] ); ?></em>
									<?php endif; ?>
								</td>
								<td><?php echo 'db' === $b['scope'] ? esc_html__( 'Database', 'yazan' ) : esc_html__( 'Full site', 'yazan' ); ?></td>
								<td><?php echo esc_html( size_format( $b['size'] ) ); ?></td>
								<td>
									<?php
									printf(
										/* translators: 1: table count, 2: file count. */
										esc_html__( '%1$s tables, %2$s files', 'yazan' ),
										esc_html( null === $b['tables'] ? '—' : number_format_i18n( $b['tables'] ) ),
										esc_html( null === $b['files'] ? '—' : number_format_i18n( $b['files'] ) )
									);
									?>
								</td>
								<td>
									<form method="post" action="<?php echo $action_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" style="display:inline">
										<input type="hidden" name="action" value="yazan_backup_download">
										<input type="hidden" name="id" value="<?php echo esc_attr( $b['id'] ); ?>">
										<?php wp_nonce_field( 'yazan_backup_download' ); ?>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Download', 'yazan' ); ?></button>
									</form>

									<form method="post" action="<?php echo $action_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" style="display:inline"
										onsubmit="return confirm('<?php echo esc_js( __( 'Delete this backup permanently?', 'yazan' ) ); ?>');">
										<input type="hidden" name="action" value="yazan_backup_delete">
										<input type="hidden" name="id" value="<?php echo esc_attr( $b['id'] ); ?>">
										<?php wp_nonce_field( 'yazan_backup_delete' ); ?>
										<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'yazan' ); ?></button>
									</form>

									<form method="post" action="<?php echo $action_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" style="display:inline"
										onsubmit="return confirm('<?php echo esc_js( __( 'This OVERWRITES your live site (database + files) from this backup and cannot be undone. Continue?', 'yazan' ) ); ?>');">
										<input type="hidden" name="action" value="yazan_backup_restore">
										<input type="hidden" name="id" value="<?php echo esc_attr( $b['id'] ); ?>">
										<input type="hidden" name="confirm" value="RESTORE">
										<?php wp_nonce_field( 'yazan_backup_restore' ); ?>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Restore', 'yazan' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php esc_html_e( 'Restoring automatically saves a database-only safety snapshot first, so a mistaken restore can itself be rolled back.', 'yazan' ); ?>
				</p>
			<?php endif; ?>

			<hr style="margin:2.5em 0">

			<h2><?php esc_html_e( 'Restore from an uploaded file', 'yazan' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: max upload size. */
					esc_html__( 'For a Yazan backup .zip from another machine. Uploads are limited to %s on this server — for anything larger, copy the file into wp-content/uploads/yazan-backups and it will appear in the list above.', 'yazan' ),
					esc_html( $max_upload )
				);
				?>
			</p>
			<form method="post" enctype="multipart/form-data" action="<?php echo $action_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
				onsubmit="return confirm('<?php echo esc_js( __( 'This OVERWRITES your live site from the uploaded archive and cannot be undone. Continue?', 'yazan' ) ); ?>');">
				<input type="hidden" name="action" value="yazan_backup_restore_upload">
				<?php wp_nonce_field( 'yazan_backup_restore_upload' ); ?>
				<p><input type="file" name="archive" accept=".zip" required></p>
				<p>
					<label>
						<?php esc_html_e( 'Type RESTORE to confirm:', 'yazan' ); ?>
						<input type="text" name="confirm" value="" autocomplete="off" placeholder="RESTORE" required>
					</label>
				</p>
				<button type="submit" class="button" <?php disabled( ! $supported ); ?>><?php esc_html_e( 'Upload & restore', 'yazan' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the result notice from the last action (passed via redirect query args).
	 *
	 * @return void
	 */
	private static function render_notice() {
		if ( ! isset( $_GET['yz_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$msg    = sanitize_key( wp_unslash( $_GET['yz_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$detail = isset( $_GET['yz_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['yz_detail'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$map = array(
			'created'  => array( 'success', __( 'Backup created:', 'yazan' ) ),
			'deleted'  => array( 'success', __( 'Backup deleted.', 'yazan' ) ),
			'restored' => array( 'success', __( 'Restore complete:', 'yazan' ) ),
			'error'    => array( 'error', __( 'Backup error:', 'yazan' ) ),
		);
		if ( ! isset( $map[ $msg ] ) ) {
			return;
		}
		list( $type, $label ) = $map[ $msg ];
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p><strong>%2$s</strong> %3$s</p></div>',
			esc_attr( $type ),
			esc_html( $label ),
			esc_html( $detail )
		);
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Verify nonce + capability for a POST action, or die.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage backups.', 'yazan' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the page with a status message.
	 *
	 * @param string $msg    Message key.
	 * @param string $detail Optional detail.
	 * @return void
	 */
	private static function redirect( $msg, $detail = '' ) {
		$args = array(
			'page'   => self::SLUG,
			'yz_msg' => $msg,
		);
		if ( '' !== $detail ) {
			$args['yz_detail'] = rawurlencode( $detail );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Human summary of a restore result.
	 *
	 * @param array $result Restore summary.
	 * @return string
	 */
	private static function summarise( array $result ) {
		$parts = array();
		if ( ! empty( $result['db_restored'] ) ) {
			$parts[] = __( 'database restored', 'yazan' );
		}
		if ( ! empty( $result['files_restored'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: file count. */
				__( '%s files restored', 'yazan' ),
				number_format_i18n( (int) $result['files_restored'] )
			);
		}
		if ( ! empty( $result['safety_backup'] ) ) {
			$parts[] = __( 'safety snapshot saved', 'yazan' );
		}
		return implode( ', ', $parts );
	}
}
