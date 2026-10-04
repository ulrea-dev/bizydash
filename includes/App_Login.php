<?php
namespace BizyDash;

defined( 'ABSPATH' ) || exit;

/**
 * The branded signed-out pages of the app: sign in, "forgot password" and "choose a new password".
 *
 * Signing in posts to WordPress's own wp-login.php, so password checks, lockout and two-factor plugins and "keep me
 * signed in" behave as everywhere else. Password reset uses WordPress's own functions (retrieve_password,
 * check_password_reset_key, reset_password) — only the pages and the link in the email are ours — so reset keys, expiry
 * and other plugins' password rules (validate_password_reset) still apply.
 */
class App_Login {

	/** True while we send the reset email ourselves, so its link points at the app and not at wp-login.php. */
	private static $from_app = false;

	/** When set, the pages belong to the client portal instead of the team app. */
	private static $portal = false;
	private static $base   = '';

	/** Where these pages live: the app, or the client portal when serving it. */
	public static function base() {
		return self::$base ? self::$base : App_Page::url();
	}

	/** Email a client a password-reset link that opens on the portal (used by "Send invite" and the account page). */
	public static function send_portal_reset( $login ) {
		self::$portal   = true;
		self::$base     = Portal::url();
		self::$from_app = true;
		$r              = retrieve_password( $login );
		self::$from_app = false;
		self::$portal   = false;
		self::$base     = '';
		return $r;
	}

	/** Serve the sign-in / reset pages for the client portal (called by Portal). */
	public static function route_for_portal( $portal_url ) {
		self::$portal = true;
		self::$base   = $portal_url;
		self::route();
	}

	public static function init() {
		if ( ( ! Settings::get( 'app_frontend' ) || ! Settings::get( 'app_login' ) ) && ! Settings::get( 'portal_enabled' ) ) {
			return;
		}
		add_action( 'wp_login_failed', array( __CLASS__, 'failed' ) );
		add_filter( 'retrieve_password_message', array( __CLASS__, 'reset_message' ), 10, 4 );
	}

	/** A sign-in that started on our page and failed returns to our page. */
	public static function failed() {
		if ( empty( $_POST['bd_login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$to = ! empty( $_POST['bd_portal'] ) ? Portal::url() : App_Page::url(); // phpcs:ignore WordPress.Security.NonceVerification
		wp_safe_redirect( add_query_arg( 'bd_error', '1', $to ) );
		exit;
	}

	/** Where to send people after they sign out of the app. */
	public static function logout_url() {
		return wp_specialchars_decode( wp_logout_url( add_query_arg( 'bd_msg', 'out', App_Page::url() ) ), ENT_QUOTES ); // plain URL for JavaScript, not HTML-escaped
	}

	/* ───────────────────────── Routing ───────────────────────── */

	public static function route() {
		$post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$view = isset( $_GET['bd_view'] ) ? sanitize_key( wp_unslash( $_GET['bd_view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( $post ) {
			$action = isset( $_POST['bd_action'] ) ? sanitize_key( wp_unslash( $_POST['bd_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( 'forgot' === $action ) {
				self::handle_forgot();
			} elseif ( 'reset' === $action ) {
				self::handle_reset();
			}
			wp_safe_redirect( self::base() );
			exit;
		}

		// The link in the email: check the key, park it in a cookie, and clean the URL (keys must not linger in the address bar).
		if ( isset( $_GET['key'], $_GET['login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$login = sanitize_user( wp_unslash( $_GET['login'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$key   = preg_replace( '/[^a-zA-Z0-9]/', '', wp_unslash( $_GET['key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! is_wp_error( check_password_reset_key( $key, $login ) ) ) {
				self::set_reset_cookie( $login, $key );
				wp_safe_redirect( add_query_arg( 'bd_view', 'reset', self::base() ) );
			} else {
				wp_safe_redirect( add_query_arg( array( 'bd_view' => 'forgot', 'bd_error' => 'expired' ), self::base() ) );
			}
			exit;
		}

		if ( 'forgot' === $view ) {
			self::render( 'forgot' );
		} elseif ( 'reset' === $view ) {
			$user = self::reset_user();
			if ( $user ) {
				self::render( 'reset' );
			} else {
				wp_safe_redirect( add_query_arg( array( 'bd_view' => 'forgot', 'bd_error' => 'expired' ), self::base() ) );
				exit;
			}
		} else {
			self::render( 'login' );
		}
	}

	/* ───────────────────────── Password reset ───────────────────────── */

	private static function cookie_name() {
		return 'bd-rp-' . COOKIEHASH;
	}

	private static function cookie_path() {
		$p = wp_parse_url( self::base(), PHP_URL_PATH );
		return $p ? $p : '/';
	}

	private static function set_reset_cookie( $login, $key ) {
		setcookie( self::cookie_name(), rawurlencode( $login ) . ':' . $key, array( 'expires' => 0, 'path' => self::cookie_path(), 'domain' => COOKIE_DOMAIN, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
	}

	private static function clear_reset_cookie() {
		setcookie( self::cookie_name(), ' ', array( 'expires' => time() - YEAR_IN_SECONDS, 'path' => self::cookie_path(), 'domain' => COOKIE_DOMAIN, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
	}

	/** The user whose reset key is parked in the cookie, if it is still valid. */
	private static function reset_user() {
		$c = isset( $_COOKIE[ self::cookie_name() ] ) ? wp_unslash( $_COOKIE[ self::cookie_name() ] ) : '';
		if ( false === strpos( $c, ':' ) ) {
			return null;
		}
		list( $login, $key ) = array_pad( explode( ':', $c, 2 ), 2, '' );
		$user = check_password_reset_key( preg_replace( '/[^a-zA-Z0-9]/', '', $key ), sanitize_user( rawurldecode( $login ) ) );
		return is_wp_error( $user ) ? null : $user;
	}

	private static function handle_forgot() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'bd_forgot' ) ) {
			wp_safe_redirect( add_query_arg( array( 'bd_view' => 'forgot', 'bd_error' => 'nonce' ), self::base() ) );
			exit;
		}
		$login = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';
		// A few requests per address per quarter hour: enough for a typo, not enough to flood someone's inbox.
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'x'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$key = 'bd_fp_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( '' !== $login && $n < 5 && function_exists( 'retrieve_password' ) ) {
			set_transient( $key, $n + 1, 15 * MINUTE_IN_SECONDS );
			self::$from_app = true;
			retrieve_password( $login ); // errors are deliberately ignored: the answer never reveals whether an account exists
			self::$from_app = false;
		}
		wp_safe_redirect( add_query_arg( array( 'bd_view' => 'forgot', 'bd_sent' => '1' ), self::base() ) );
		exit;
	}

	/** The reset email, with a link to the app's own page. */
	public static function reset_message( $message, $key, $user_login, $user_data ) {
		if ( ! self::$from_app ) {
			return $message;
		}
		$name = (string) Settings::get( 'business_name' );
		$name = $name ? $name : wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$what = self::$portal ? 'client portal account' : 'account';
		$url  = add_query_arg( array( 'key' => $key, 'login' => rawurlencode( $user_login ) ), self::base() );
		return "Hi,\r\n\r\nSomeone asked to reset the password for your {$name} {$what}:\r\n\r\n{$user_login}\r\n\r\nIf that wasn't you, you can ignore this email — nothing will change.\r\n\r\nTo choose a new password, open this link:\r\n\r\n{$url}\r\n\r\nThe link works for a limited time.\r\n";
	}

	private static function handle_reset() {
		$back = add_query_arg( 'bd_view', 'reset', self::base() );
		$user = self::reset_user();
		if ( ! $user ) {
			wp_safe_redirect( add_query_arg( array( 'bd_view' => 'forgot', 'bd_error' => 'expired' ), self::base() ) );
			exit;
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'bd_reset' ) ) {
			wp_safe_redirect( add_query_arg( 'bd_error', 'nonce', $back ) );
			exit;
		}
		$p1 = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$p2 = isset( $_POST['pass2'] ) ? (string) wp_unslash( $_POST['pass2'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$errors = new \WP_Error();
		if ( strlen( $p1 ) < 8 ) {
			$errors->add( 'short', 'short' );
		} elseif ( $p1 !== $p2 ) {
			$errors->add( 'mismatch', 'mismatch' );
		}
		do_action( 'validate_password_reset', $errors, $user ); // other plugins' password rules
		if ( $errors->has_errors() ) {
			$code = $errors->get_error_code();
			wp_safe_redirect( add_query_arg( 'bd_error', in_array( $code, array( 'short', 'mismatch' ), true ) ? $code : 'rule', $back ) );
			exit;
		}
		reset_password( $user, $p1 );
		self::clear_reset_cookie();
		wp_safe_redirect( add_query_arg( 'bd_msg', 'reset', self::base() ) );
		exit;
	}

	/* ───────────────────────── The page ───────────────────────── */

	public static function render( $view = 'login' ) {
		$name  = (string) Settings::get( 'business_name' );
		$logo  = (string) Settings::get( 'business_logo_url' );
		$base  = BIZYDASH_URL . 'assets/fonts/';
		$app   = self::base();
		$err   = isset( $_GET['bd_error'] ) ? sanitize_key( wp_unslash( $_GET['bd_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$msg   = isset( $_GET['bd_msg'] ) ? sanitize_key( wp_unslash( $_GET['bd_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$sent  = isset( $_GET['bd_sent'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$label = $name ? $name : 'BizyDash';
		$titles = array( 'login' => 'Sign in', 'forgot' => 'Reset your password', 'reset' => 'Choose a new password' );
		$ERR = array(
			'1'       => 'Those details didn’t match. Check them and try again.',
			'expired' => 'That reset link has expired or was already used. Ask for a new one below.',
			'nonce'   => 'That page was open for too long. Please try again.',
			'short'   => 'Use at least 8 characters.',
			'mismatch' => 'The two passwords don’t match.',
			'rule'    => 'That password isn’t allowed. Try a longer or less common one.',
		);
		$OK = array( 'out' => 'You’ve been signed out.', 'reset' => 'Password updated. Sign in with your new one.' );
		header( 'X-Frame-Options: DENY' );
		header( 'Referrer-Policy: same-origin' );
		status_header( 200 );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title><?php echo esc_html( $titles[ $view ] . ' — ' . $label ); ?></title>
<link rel="preload" href="<?php echo esc_url( $base . 'google-sans-latin.woff2' ); ?>" as="font" type="font/woff2" crossorigin>
<script>try{var t=localStorage.getItem('bd-theme');if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}</script>
<style>
@font-face{font-family:'Google Sans';font-style:normal;font-weight:400 700;font-display:swap;src:url('<?php echo esc_url( $base . 'google-sans-latin.woff2' ); ?>') format('woff2');}
:root{--bg:#f6f7f9;--card:#fff;--ink:#101828;--muted:#667085;--ring:#e4e7ec;--ring-strong:#cfd4dc;--accent:#14161d;--on-accent:#fff;--danger:#b42318;--danger-bg:#fef3f2;--ok:#067647;--ok-bg:#ecfdf3;--focus:rgba(20,22,29,.16);--logo-bg:transparent;--logo-pad:0}
@media (prefers-color-scheme:dark){:root:not([data-theme=light]){--bg:#0e1014;--card:#16181d;--ink:#f2f4f7;--muted:#98a2b3;--ring:#262a31;--ring-strong:#363b44;--accent:#f2f4f7;--on-accent:#101828;--danger:#fda29b;--danger-bg:#2a1513;--ok:#75e0a7;--ok-bg:#0f2a1c;--focus:rgba(242,244,247,.2);--logo-bg:#fff;--logo-pad:8px 14px}}
:root[data-theme=dark]{--bg:#0e1014;--card:#16181d;--ink:#f2f4f7;--muted:#98a2b3;--ring:#262a31;--ring-strong:#363b44;--accent:#f2f4f7;--on-accent:#101828;--danger:#fda29b;--danger-bg:#2a1513;--ok:#75e0a7;--ok-bg:#0f2a1c;--focus:rgba(242,244,247,.2);--logo-bg:#fff;--logo-pad:8px 14px}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%}
body{background:var(--bg);color:var(--ink);font:400 14px/1.5 'Google Sans',Inter,-apple-system,'Segoe UI',Roboto,sans-serif;display:grid;place-items:center;min-height:100vh;padding:24px 16px}
main{width:100%;max-width:400px}
.brand{display:flex;align-items:center;gap:10px;justify-content:center;margin-bottom:28px;font-weight:500;font-size:18px;letter-spacing:-.01em}
.brand img{max-height:40px;max-width:180px;width:auto;background:var(--logo-bg);padding:var(--logo-pad);border-radius:10px}
.card{background:var(--card);border:1px solid var(--ring);border-radius:12px;padding:28px}
h1{margin:0 0 4px;font-size:20px;line-height:28px;font-weight:500;letter-spacing:-.012em}
.sub{margin:0 0 22px;color:var(--muted)}
label{display:block;margin:0 0 6px;font-weight:500;font-size:13px}
.field{margin-bottom:16px}
input[type=text],input[type=password]{width:100%;height:42px;padding:0 12px;border:1px solid var(--ring-strong);border-radius:8px;background:var(--card);color:var(--ink);font:inherit;outline:0;transition:border-color .12s,box-shadow .12s}
input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--focus)}
.hint{margin:6px 0 0;color:var(--muted);font-size:12.5px}
.pw{position:relative}
.pw input{padding-right:64px}
.pw button{position:absolute;right:6px;top:6px;height:30px;padding:0 10px;border:0;border-radius:6px;background:transparent;color:var(--muted);font:inherit;font-size:12.5px;cursor:pointer}
.pw button:hover{color:var(--ink);background:var(--bg)}
.row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:2px 0 20px;font-size:13px}
.row label{display:inline-flex;align-items:center;gap:8px;margin:0;font-weight:400;cursor:pointer}
.row input[type=checkbox]{width:16px;height:16px;margin:0;accent-color:var(--accent)}
a{color:var(--ink);text-underline-offset:3px}
a:hover{color:var(--muted)}
.btn{width:100%;height:44px;border:0;border-radius:8px;background:var(--accent);color:var(--on-accent);font:inherit;font-weight:500;cursor:pointer;transition:opacity .12s}
.btn:hover{opacity:.9}
.btn[disabled]{opacity:.55;cursor:default}
.msg{margin:0 0 18px;padding:10px 12px;border-radius:8px;font-size:13px}
.msg--err{background:var(--danger-bg);color:var(--danger)}
.msg--ok{background:var(--ok-bg);color:var(--ok)}
.foot{margin-top:20px;text-align:center;color:var(--muted);font-size:12.5px}
.back{display:block;margin-top:18px;text-align:center;font-size:13px}
.theme{position:fixed;top:14px;right:14px;width:36px;height:36px;border:1px solid var(--ring);border-radius:8px;background:var(--card);color:var(--muted);display:grid;place-items:center;cursor:pointer}
.theme:hover{color:var(--ink)}
.theme svg{width:18px;height:18px}
.theme .sun{display:none}
:root[data-theme=dark] .theme .sun{display:block}
:root[data-theme=dark] .theme .moon{display:none}
@media (prefers-color-scheme:dark){:root:not([data-theme=light]) .theme .sun{display:block}:root:not([data-theme=light]) .theme .moon{display:none}}
</style>
</head>
<body>
<button type="button" class="theme" id="bd-theme" aria-label="Switch between light and dark">
	<svg class="moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9z"/></svg>
	<svg class="sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
</button>
<main>
	<div class="brand">
		<?php if ( $logo ) : ?>
			<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $name ); ?>">
		<?php else : ?>
			<svg viewBox="0 0 32 32" width="32" height="32" aria-hidden="true"><rect width="32" height="32" rx="9" fill="#14161d"/><path d="M9.5 22V10h6.6a3.9 3.9 0 0 1 1.5 7.5A3.9 3.9 0 0 1 16 22H9.5zm2.9-9.6v2.5h3.2a1.25 1.25 0 0 0 0-2.5h-3.2zm0 5.2v2.5h3.6a1.25 1.25 0 0 0 0-2.5h-3.6z" fill="#fff"/></svg>
			<span><?php echo esc_html( $label ); ?></span>
		<?php endif; ?>
	</div>
	<div class="card">
		<h1><?php echo esc_html( $titles[ $view ] ); ?></h1>
		<?php if ( 'login' === $view ) : ?>
			<p class="sub"><?php echo esc_html( self::$portal ? ( $name ? 'to your ' . $name . ' client portal' : 'to your client portal' ) : ( $name ? 'to ' . $name : 'to your workspace' ) ); ?></p>
		<?php elseif ( 'forgot' === $view ) : ?>
			<p class="sub">Enter your email or username and we’ll send you a link.</p>
		<?php else : ?>
			<p class="sub">Pick something you don’t use anywhere else.</p>
		<?php endif; ?>

		<?php if ( $err && isset( $ERR[ $err ] ) ) : ?>
			<p class="msg msg--err" role="alert"><?php echo esc_html( $ERR[ $err ] ); ?></p>
		<?php elseif ( 'login' === $view && $msg && isset( $OK[ $msg ] ) ) : ?>
			<p class="msg msg--ok" role="status"><?php echo esc_html( $OK[ $msg ] ); ?></p>
		<?php endif; ?>

		<?php if ( 'login' === $view ) : ?>
		<form method="post" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>" id="bd-login">
			<div class="field">
				<label for="log">Email or username</label>
				<input type="text" name="log" id="log" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
			</div>
			<div class="field">
				<label for="pwd">Password</label>
				<div class="pw">
					<input type="password" name="pwd" id="pwd" autocomplete="current-password" required>
					<button type="button" class="bd-eye" data-for="pwd" aria-label="Show password" aria-pressed="false">Show</button>
				</div>
			</div>
			<div class="row">
				<label><input type="checkbox" name="rememberme" value="forever"> Keep me signed in</label>
				<a href="<?php echo esc_url( add_query_arg( 'bd_view', 'forgot', $app ) ); ?>">Forgot password?</a>
			</div>
			<input type="hidden" name="redirect_to" id="bd-redirect" value="<?php echo esc_url( $app ); ?>">
			<input type="hidden" name="bd_login" value="1">
			<?php if ( self::$portal ) : ?><input type="hidden" name="bd_portal" value="1"><?php endif; ?>
			<button class="btn" type="submit" name="wp-submit" data-busy="Signing in…">Sign in</button>
		</form>

		<?php elseif ( 'forgot' === $view && $sent ) : ?>
			<p class="msg msg--ok" role="status">If there’s an account for that email or username, a reset link is on its way. Check your inbox (and spam).</p>
			<a class="back" href="<?php echo esc_url( $app ); ?>">Back to sign in</a>

		<?php elseif ( 'forgot' === $view ) : ?>
		<form method="post" action="<?php echo esc_url( $app ); ?>">
			<div class="field">
				<label for="user_login">Email or username</label>
				<input type="text" name="user_login" id="user_login" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
			</div>
			<?php wp_nonce_field( 'bd_forgot' ); ?>
			<input type="hidden" name="bd_action" value="forgot">
			<button class="btn" type="submit" data-busy="Sending…">Send reset link</button>
		</form>
		<a class="back" href="<?php echo esc_url( $app ); ?>">Back to sign in</a>

		<?php else : ?>
		<form method="post" action="<?php echo esc_url( $app ); ?>" autocomplete="off">
			<div class="field">
				<label for="pass1">New password</label>
				<div class="pw">
					<input type="password" name="pass1" id="pass1" autocomplete="new-password" minlength="8" required autofocus>
					<button type="button" class="bd-eye" data-for="pass1" aria-label="Show password" aria-pressed="false">Show</button>
				</div>
				<p class="hint">At least 8 characters.</p>
			</div>
			<div class="field">
				<label for="pass2">Type it again</label>
				<input type="password" name="pass2" id="pass2" autocomplete="new-password" minlength="8" required>
			</div>
			<?php wp_nonce_field( 'bd_reset' ); ?>
			<input type="hidden" name="bd_action" value="reset">
			<button class="btn" type="submit" data-busy="Saving…">Save new password</button>
		</form>
		<?php endif; ?>
	</div>
	<p class="foot"><?php echo esc_html( $label ); ?> · <?php echo self::$portal ? 'client portal' : 'team workspace'; ?></p>
</main>
<script>
(function () {
	var root = document.documentElement;
	var redirect = document.getElementById('bd-redirect');
	// Land on the page that was asked for (the part after # never reaches the server).
	if (redirect && location.hash.length > 1) { redirect.value = <?php echo wp_json_encode( $app ); ?> + location.hash; }
	Array.prototype.forEach.call(document.querySelectorAll('.bd-eye'), function (eye) {
		var input = document.getElementById(eye.getAttribute('data-for'));
		eye.addEventListener('click', function () {
			var show = input.type === 'password';
			input.type = show ? 'text' : 'password';
			eye.textContent = show ? 'Hide' : 'Show';
			eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
			eye.setAttribute('aria-pressed', show ? 'true' : 'false');
		});
	});
	Array.prototype.forEach.call(document.querySelectorAll('form'), function (f) {
		f.addEventListener('submit', function () {
			var b = f.querySelector('.btn');
			if (b) { b.setAttribute('data-label', b.textContent); b.disabled = true; b.textContent = b.getAttribute('data-busy') || 'Please wait…'; }
		});
	});
	window.addEventListener('pageshow', function (e) {
		if (e.persisted) { Array.prototype.forEach.call(document.querySelectorAll('.btn'), function (b) { b.disabled = false; if (b.getAttribute('data-label')) { b.textContent = b.getAttribute('data-label'); } }); }
	});
	// Light / dark: follows the device until someone picks; the app remembers the same choice.
	document.getElementById('bd-theme').addEventListener('click', function () {
		var cur = root.getAttribute('data-theme') || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
		var next = cur === 'dark' ? 'light' : 'dark';
		root.setAttribute('data-theme', next);
		try { localStorage.setItem('bd-theme', next); } catch (e) {}
	});
})();
</script>
</body>
</html>
		<?php
	}
}
