<?php
/**
 * Branded HTML email template.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps notification copy in a luxury, on-brand, RTL-aware HTML shell (table-based
 * + fully inline-styled for email-client compatibility). Content passed in is
 * treated as TRUSTED HTML — callers escape their own dynamic values (see
 * {@see EmailChannel} and {@see DigestService}). The palette follows the Yazan
 * identity: obsidian header, warm surface, agate-carnelian accents, restrained gold.
 */
final class EmailTemplate {

	private const OBSIDIAN = '#14110f';
	private const SURFACE  = '#f5f1ea';
	private const CARD     = '#ffffff';
	private const INK      = '#2b2622';
	private const MUTED    = '#8a8178';
	private const AGATE    = '#9a3b2e';
	private const GOLD     = '#c9a24b';

	/**
	 * Wrap content in the full branded document.
	 *
	 * @param string               $subject Subject / headline.
	 * @param string               $content Trusted inner HTML.
	 * @param array<string,string> $opts    cta_url, cta_label, preheader, footer_note.
	 * @return string
	 */
	public function wrap( string $subject, string $content, array $opts = array() ): string {
		$rtl   = is_rtl();
		$dir   = $rtl ? 'rtl' : 'ltr';
		$align = $rtl ? 'right' : 'left';
		$brand = esc_html( get_bloginfo( 'name' ) );

		$preheader = isset( $opts['preheader'] ) ? esc_html( (string) $opts['preheader'] ) : '';
		$cta       = '';
		if ( ! empty( $opts['cta_url'] ) ) {
			$cta = sprintf(
				'<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;"><tr><td style="border-radius:6px;background:%1$s;">'
				. '<a href="%2$s" style="display:inline-block;padding:12px 26px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:6px;">%3$s</a>'
				. '</td></tr></table>',
				self::AGATE,
				esc_url( (string) $opts['cta_url'] ),
				esc_html( (string) ( $opts['cta_label'] ?? __( 'View details', 'yazan-rewards' ) ) )
			);
		}

		$footer_note = isset( $opts['footer_note'] )
			? esc_html( (string) $opts['footer_note'] )
			: __( 'You are receiving this because you are a member of our rewards programme.', 'yazan-rewards' );

		$prefs_url   = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'rewards' ) : home_url( '/my-account/' );
		$prefs_label = esc_html__( 'Manage your notification preferences', 'yazan-rewards' );

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>" dir="<?php echo esc_attr( $dir ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title><?php echo esc_html( $subject ); ?></title>
</head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( self::SURFACE ); ?>;">
<span style="display:none!important;visibility:hidden;opacity:0;height:0;width:0;font-size:1px;color:<?php echo esc_attr( self::SURFACE ); ?>;"><?php echo $preheader; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:<?php echo esc_attr( self::SURFACE ); ?>;padding:24px 12px;">
	<tr>
		<td align="center">
			<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background:<?php echo esc_attr( self::CARD ); ?>;border-radius:10px;overflow:hidden;border:1px solid #e7e0d6;">
				<tr>
					<td style="background:<?php echo esc_attr( self::OBSIDIAN ); ?>;padding:26px 32px;border-bottom:3px solid <?php echo esc_attr( self::GOLD ); ?>;" align="<?php echo esc_attr( $align ); ?>">
						<span style="font-family:Georgia,'Times New Roman',serif;font-size:22px;letter-spacing:2px;color:<?php echo esc_attr( self::GOLD ); ?>;text-transform:uppercase;"><?php echo $brand; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</td>
				</tr>
				<tr>
					<td style="padding:32px;" align="<?php echo esc_attr( $align ); ?>">
						<h1 style="margin:0 0 16px;font-family:Georgia,'Times New Roman',serif;font-size:20px;font-weight:normal;color:<?php echo esc_attr( self::INK ); ?>;"><?php echo esc_html( $subject ); ?></h1>
						<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.65;color:<?php echo esc_attr( self::INK ); ?>;">
							<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<?php echo $cta; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</td>
				</tr>
				<tr>
					<td style="padding:22px 32px;background:<?php echo esc_attr( self::SURFACE ); ?>;border-top:1px solid #e7e0d6;" align="<?php echo esc_attr( $align ); ?>">
						<p style="margin:0 0 8px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:<?php echo esc_attr( self::MUTED ); ?>;"><?php echo esc_html( $footer_note ); ?></p>
						<p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;">
							<a href="<?php echo esc_url( $prefs_url ); ?>" style="color:<?php echo esc_attr( self::AGATE ); ?>;text-decoration:underline;"><?php echo $prefs_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
						</p>
					</td>
				</tr>
			</table>
			<p style="margin:16px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;color:<?php echo esc_attr( self::MUTED ); ?>;">&copy; <?php echo esc_html( gmdate( 'Y' ) . ' ' . $brand ); ?></p>
		</td>
	</tr>
</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * A single escaped paragraph of body copy.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	public function paragraph( string $text ): string {
		return '<p style="margin:0 0 14px;">' . esc_html( $text ) . '</p>';
	}

	/**
	 * A digest list — one row per queued notification.
	 *
	 * @param array<int,array{subject:string,body:string}> $items Items.
	 * @return string
	 */
	public function items_list( array $items ): string {
		$rows = '';
		foreach ( $items as $item ) {
			$rows .= sprintf(
				'<tr><td style="padding:14px 0;border-bottom:1px solid #ece5db;">'
				. '<strong style="display:block;font-size:15px;color:%1$s;margin-bottom:4px;">%2$s</strong>'
				. '<span style="font-size:14px;color:%3$s;line-height:1.55;">%4$s</span>'
				. '</td></tr>',
				esc_attr( self::INK ),
				esc_html( (string) ( $item['subject'] ?? '' ) ),
				esc_attr( self::MUTED ),
				esc_html( (string) ( $item['body'] ?? '' ) )
			);
		}
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table>';
	}
}
