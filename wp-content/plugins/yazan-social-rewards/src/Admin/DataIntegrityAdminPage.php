<?php
/**
 * Data integrity / orphaned-row admin page.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Admin;

use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Privacy\OrphanScanner;
use Yazan\Rewards\Core\Privacy\UserDataRegistry;
use Yazan\Rewards\Core\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Yazan Rewards → Data Integrity".
 *
 * None of the plugin's tables carry a real FOREIGN KEY, so a user deleted
 * before the `deleted_user` cleanup hook existed left their rows behind. This
 * page surfaces those rows and offers a one-off cleanup.
 *
 * The page itself is READ-ONLY. Purging requires a POST carrying a nonce, the
 * manage capability, and the word DELETE typed by hand — the same
 * triple-confirmation shape the backup engine uses for a restore, because both
 * are irreversible.
 */
final class DataIntegrityAdminPage implements Hookable {

	/** Submenu slug. */
	public const SLUG = 'yazan-rewards-integrity';

	/** admin-post action for the purge. */
	private const ACTION = 'yzrw_purge_orphans';

	/** Parent menu (created by the Rules page). */
	private const PARENT = 'yazan-rewards-rules';

	/**
	 * @param OrphanScanner $scanner Orphan scanner.
	 */
	public function __construct( private OrphanScanner $scanner ) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array(
				'type'     => 'action',
				'hook'     => 'admin_menu',
				'method'   => 'register_menu',
				'priority' => 60,
			),
			array(
				'type'   => 'action',
				'hook'   => 'admin_post_' . self::ACTION,
				'method' => 'handle_purge',
			),
		);
	}

	/**
	 * Register the submenu (or a top-level menu if the parent is absent).
	 *
	 * @return void
	 */
	public function register_menu(): void {
		global $admin_page_hooks;

		if ( isset( $admin_page_hooks[ self::PARENT ] ) ) {
			add_submenu_page(
				self::PARENT,
				__( 'Data Integrity', 'yazan-rewards' ),
				__( 'Data Integrity', 'yazan-rewards' ),
				Capabilities::MANAGE,
				self::SLUG,
				array( $this, 'render' )
			);
		} else {
			add_menu_page(
				__( 'Yazan Rewards', 'yazan-rewards' ),
				__( 'Yazan Rewards', 'yazan-rewards' ),
				Capabilities::MANAGE,
				self::SLUG,
				array( $this, 'render' ),
				'dashicons-database',
				59
			);
		}
	}

	/**
	 * Render the report.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to manage rewards.', 'yazan-rewards' ) );
		}

		$scan = $this->scanner->scan();

		echo '<div class="wrap yzrw-admin-wrap">';
		echo '<h1>' . esc_html__( 'Data Integrity', 'yazan-rewards' ) . '</h1>';

		$this->render_notice();

		echo '<p class="description">' . esc_html__(
			'Rows in the rewards tables whose WordPress user no longer exists. New orphans are prevented automatically when a user is deleted; anything listed here predates that safeguard.',
			'yazan-rewards'
		) . '</p>';

		if ( 0 === $scan['total'] ) {
			echo '<div class="notice notice-success inline"><p>' .
				esc_html__( 'No orphaned rows found. Referential integrity is clean.', 'yazan-rewards' ) .
				'</p></div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:820px;margin-top:1em">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Table', 'yazan-rewards' ) . '</th>';
		echo '<th>' . esc_html__( 'Column', 'yazan-rewards' ) . '</th>';
		echo '<th>' . esc_html__( 'Action on cleanup', 'yazan-rewards' ) . '</th>';
		echo '<th style="text-align:end">' . esc_html__( 'Rows', 'yazan-rewards' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $scan['tables'] as $row ) {
			$is_delete = UserDataRegistry::POLICY_DELETE === $row['policy'];

			echo '<tr>';
			echo '<td><code>' . esc_html( $row['table'] ) . '</code></td>';
			echo '<td><code>' . esc_html( $row['column'] ) . '</code></td>';
			echo '<td>' . (
				$is_delete
					? esc_html__( 'Delete row', 'yazan-rewards' )
					: '<strong>' . esc_html__( 'Keep row, anonymise', 'yazan-rewards' ) . '</strong>'
			) . '</td>';
			echo '<td style="text-align:end">' . esc_html( (string) $row['count'] ) . '</td>';
			echo '</tr>';
		}

		echo '<tr><th colspan="3" style="text-align:end">' . esc_html__( 'Total', 'yazan-rewards' ) . '</th>';
		echo '<th style="text-align:end">' . esc_html( (string) $scan['total'] ) . '</th></tr>';
		echo '</tbody></table>';

		echo '<h2 style="margin-top:2em">' . esc_html__( 'Clean up', 'yazan-rewards' ) . '</h2>';
		echo '<p>' . esc_html__(
			'Financial records (points, store credit, redemptions, referral earnings) are never deleted — they are kept and unlinked from the missing user so accounting totals stay correct. Everything else is removed. This cannot be undone; take a backup first.',
			'yazan-rewards'
		) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<p><label for="yzrw-confirm">' . esc_html__( 'Type DELETE to confirm:', 'yazan-rewards' ) . '</label> ';
		echo '<input type="text" id="yzrw-confirm" name="confirm" class="regular-text" autocomplete="off" required></p>';
		submit_button( __( 'Clean up orphaned rows', 'yazan-rewards' ), 'delete' );
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Handle the purge POST.
	 *
	 * @return void
	 */
	public function handle_purge(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to manage rewards.', 'yazan-rewards' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		$confirm = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';

		if ( 'DELETE' !== $confirm ) {
			$this->redirect_back( 'notconfirmed', 0 );
			return;
		}

		$result = $this->scanner->purge( true );
		$rows   = array_sum( $result['affected'] );

		$this->redirect_back( 'purged', (int) $rows );
	}

	/**
	 * Redirect back to the page with a result flag.
	 *
	 * @param string $status Result key.
	 * @param int    $rows   Rows affected.
	 * @return void
	 */
	private function redirect_back( string $status, int $rows ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::SLUG,
					'yzrw_status' => $status,
					'yzrw_rows'   => $rows,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the post-action notice.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// Read-only display of our own redirect flag; the state-changing step
		// was already nonce- and capability-checked in handle_purge().
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['yzrw_status'] ) ? sanitize_key( wp_unslash( $_GET['yzrw_status'] ) ) : '';

		if ( '' === $status ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows = isset( $_GET['yzrw_rows'] ) ? absint( wp_unslash( $_GET['yzrw_rows'] ) ) : 0;

		if ( 'purged' === $status ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %d: number of rows. */
					_n( '%d orphaned row cleaned up.', '%d orphaned rows cleaned up.', $rows, 'yazan-rewards' ),
					$rows
				)
			) . '</p></div>';
			return;
		}

		if ( 'notconfirmed' === $status ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' .
				esc_html__( 'Cleanup cancelled — the confirmation word did not match.', 'yazan-rewards' ) .
				'</p></div>';
		}
	}
}
