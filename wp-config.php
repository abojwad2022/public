<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * Localized language
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'local' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', 'root' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'B?6F|`RjV2W;;Y@aun0e;j:1LD;#Kx 4xJ;X2pJKJzv![DI;i!D3h!n.^>yu&a@6' );
define( 'SECURE_AUTH_KEY',  'Ce5:dRTr1h4feJS?UxqqqTj3 /o-!jd`X8X]:_l}_/y/z*6wABe+DNu5o#Qj@* z' );
define( 'LOGGED_IN_KEY',    'kQpl;-cHaqz#S`%/Gd~HvkS.}9CGV&(/[1H856:vn!zk--e~-=E.J[,`GG>]YG8v' );
define( 'NONCE_KEY',        'm{u#abP|m4Q}UIXvqY6~*_xf9VoGa{yO<yN`29.<oQuFe76k(K#`Mtq8akPYv}[]' );
define( 'AUTH_SALT',        '?dJ~D;iBW@:_Ek?gD%(7]2Di7?@YHa80~/s>P5=@cp3{4F^z;Y!^2I(Y^=q<oocJ' );
define( 'SECURE_AUTH_SALT', 'qr!&T:3fuxi5:T^?G9%8vj1E-w%SDy1 k4]9g!G|n`;X[V2ghZl?kf`D4uP$D7s=' );
define( 'LOGGED_IN_SALT',   '?=jtIQzf.2D@Q6,x$-S0:p0CC>x%1upw?0#rKuu-.|-4$R#B>Ri(E>3oTl-><Z=n' );
define( 'NONCE_SALT',       'ur.Q-E3x.r53j11<:ksTJd2iz?/%ME1_{y~iE#iLi.QO~?PWW/Yz R8%Cm*kS+%0' );
define( 'WP_CACHE_KEY_SALT','?,VPUEt &fpRRsjCB;I3Qf&qp10`h@WEeRIG=5}yiT98mpHd}529qd&,hH*XHQr/' );


/**#@-*/

/**
 * Encryption key for customer OAuth tokens (yazan-social-rewards).
 *
 * PINNED during the 2026-07-27 secret rotation. Crypto::key() otherwise
 * derives this from wp_salt('secure_auth').wp_salt('auth'); rotating the
 * salts without pinning would have made every stored token permanently
 * undecryptable. The value below IS the pre-rotation derived material, so
 * existing ciphertext keeps working.
 *
 * Do not change this unless you intend to invalidate all linked social
 * accounts and require every customer to reconnect.
 */
define( 'YZRW_TOKEN_KEY', '/sJMq+lw9AF]X#WEEL:c*(c|%uM[1UKPmAKMKP?f59kGQsMh)c*RN!Pv%jb<65^y#9]jOeOnY^0yGnD,uw8F<1KJDId w6jo@._-usy;k$z sX5J)cwy@FIw[3( Y]^s{5P{H!$Yx@t&@D$(]|CmoPwnLn@f-nf^[Jng7+.qY/iQ6PW=M|+kr?2bCa:j11D1+uc.w<2q&b|c7tQ=:ST7-k/8d2/<!yHup?<d!^Oy5v4RbAw,)[Pk(Jt|S=3TT0>3' );


/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';


/* Add any custom values between this line and the "stop editing" line. */

/**
 * Yazan AI Core — shared HMAC secret for the local Node "AI Core" service.
 *
 * Defined here (a file that is NOT web-served and NOT in version control) rather than left in the
 * plugin's database option or the service's committed .env, so the secret never travels through the
 * repo. This value MUST match YAZAN_CORE_SECRET in ai-core-service/.env. Rotate both together.
 */
if ( ! defined( 'YAZAN_AI_CORE_SECRET' ) ) {
	define( 'YAZAN_AI_CORE_SECRET', 'f9ef62d9effe7f3e61344e0da12bf9b6439fd66fb647c11b' );
}



/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

define( 'WP_ENVIRONMENT_TYPE', 'local' );

/* -----------------------------------------------------------------------------
 * Google sign-in testing — CURRENTLY OFF (site runs on http://yazan.local).
 * Added 2026-08-01, switched off 2026-08-01 at the owner's request.
 *
 * Google refuses any redirect URI that is not HTTPS, with ONE exception: a
 * loopback host.  http://localhost:10029 is this site's own nginx listener, so
 * pointing WordPress at it lets the whole OAuth round trip run locally with no
 * tunnel.  Both constants are required together — with only one of them, links
 * and redirects still resolve to yazan.local and the login cookie (set on
 * localhost) would not travel with the shopper.
 *
 * The cost of leaving them on is /dashboard/: its bundle is a
 * <script type="module">, and a module served from a different origin than the
 * page is blocked by CORS, so opening the dashboard on yazan.local while these
 * point at localhost gives a blank screen with no error on the page.  Browse the
 * SAME host these name, or leave them commented out.
 *
 * TO RESUME OAUTH TESTING: uncomment both lines, re-derive the port (Local
 * reassigns it on restart), and match it in the Google console:
 *     grep -rh "listen" ~/AppData/Roaming/Local/run/<id>/conf/nginx/site.conf
 * -------------------------------------------------------------------------- */
// define( 'WP_HOME', 'http://localhost:10029' );
// define( 'WP_SITEURL', 'http://localhost:10029' );

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
