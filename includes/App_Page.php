<?php
namespace BizyDash;

defined( 'ABSPATH' ) || exit;

/**
 * The BizyDash app on the front end: https://site/app/ (the word is configurable).
 * A theme-less page with no WordPress admin around it — the same app, drawing its own navigation.
 */
class App_Page {

	public static function slug() {
		$s = sanitize_title( (string) Settings::get( 'app_slug' ) );
		return '' !== $s ? $s : 'app';
	}

	/** The app's address: the site's home page when chosen in Settings, otherwise /app/. */
	public static function url() {
		return Settings::get( 'app_home' ) ? home_url( '/' ) : home_url( '/' . self::slug() . '/' );
	}

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );
		App_Login::init();
	}

	private static function requested() {
		$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return trim( substr( (string) $path, strlen( $home ) ), '/' );
	}

	/** Parameters other BizyDash pages use on the home URL (payslips, certificates…) — never hijack those. */
	private static function foreign_request() {
		$own = array( 'bd_error', 'bd_msg', 'bd_view', 'bd_sent', 'key', 'login' );
		foreach ( array_keys( $_GET ) as $k ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( 0 === strpos( (string) $k, 'bd_' ) && ! in_array( $k, $own, true ) ) {
				return true; // includes ?bd_site=1, the way back to the normal home page
			}
		}
		return false;
	}

	/** Is this request for the app — /app/, or the home page when the app is the home page? */
	private static function is_app_request() {
		if ( ! Settings::get( 'app_frontend' ) ) {
			return false;
		}
		$path = self::requested();
		if ( $path === self::slug() ) {
			return true;
		}
		if ( Settings::get( 'app_home' ) && '' === $path ) {
			global $wp;
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( ! in_array( $method, array( 'GET', 'HEAD', 'POST' ), true ) || ! empty( $wp->query_vars ) || self::foreign_request() ) {
				return false;
			}
			// A signed-in person who is not on the team (a customer, say) still gets the ordinary home page.
			return ! is_user_logged_in() || current_user_can( 'manage_bizydash' );
		}
		return false;
	}

	public static function maybe_render() {
		if ( ! self::is_app_request() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'bizydash app' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );

		if ( ! is_user_logged_in() ) {
			if ( Settings::get( 'app_login' ) ) {
				App_Login::route(); // our own branded sign-in and password reset
				exit;
			}
			wp_safe_redirect( wp_login_url( self::url() ) );
			exit;
		}
		if ( ! current_user_can( 'manage_bizydash' ) ) {
			wp_die( 'You do not have access to BizyDash.', 'Not allowed', array( 'response' => 403 ) );
		}

		add_filter( 'show_admin_bar', '__return_false' );
		remove_action( 'wp_head', '_admin_bar_bump_cb' ); // WordPress reserves space for an admin bar we are not showing
		// Only the app's own assets (plus the media library for uploads) — no theme styles or scripts.
		remove_all_actions( 'wp_enqueue_scripts' );
		add_action(
			'wp_enqueue_scripts',
			function () {
				Plugin::enqueue_app( '/', true );
			}
		);
		status_header( 200 );
		?>
<!doctype html>
<html <?php language_attributes(); ?> class="bd-frontend">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>BizyDash — <?php echo esc_html( Settings::get( 'business_name' ) ); ?></title>
		<?php wp_head(); ?>
</head>
<body class="bizydash-screen bizydash-frontend bizydash-fullscreen">
<div id="bizydash-root" class="bizydash-root"><p style="padding:24px;font-family:sans-serif">Loading BizyDash…</p></div>
		<?php wp_footer(); ?>
</body>
</html>
		<?php
		exit;
	}
}
