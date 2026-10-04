<?php
namespace BizyDash;

use BizyDash\Rest\Custom_Routes;
use BizyDash\Rest\Resource_Controller;

defined( 'ABSPATH' ) || exit;

class Plugin {

	const PAGE = 'bizydash';

	/** WordPress admin page slug => the in-app route it opens (core plus each enabled module). */
	public static function pages() {
		$pages = array( self::PAGE => '/' );
		foreach ( Modules::defs() as $id => $d ) {
			if ( empty( $d['core'] ) && Modules::enabled( $id ) ) {
				$pages[ self::PAGE . '-' . $id ] = $d['route'];
			}
		}
		return $pages;
	}

	/** A brand-new, still-empty install, shown to administrators only: Home offers to restore a backup. */
	public static function is_fresh_install() {
		global $wpdb;
		if ( ! get_option( 'bizydash_fresh_install' ) || ! Roles::can_admin() ) {
			return false;
		}
		return 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database\Schema::table( 'clients' ) ); // phpcs:ignore WordPress.DB
	}

	public static function boot() {
		Install::maybe_upgrade();
		Cron::init();
		Backup\Backup_Manager::init();
		Backup\Backup_Download::init();
		Backup\CLI::register();
		Portal::init();
		add_action( 'bizydash_sync_portal_users', array( Services\Portal_Users::class, 'cron_sync' ) );

		if ( Modules::enabled( 'finance' ) ) {
			Public_Invoice::init();
			Payslip::init();
		}
		if ( Modules::enabled( 'contracts' ) ) {
			Public_Contract::init();
		}
		App_Page::init();

		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'in_admin_header', array( __CLASS__, 'quiet_admin' ), 1000 );

		// Team members who only work in BizyDash never see WordPress.
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ) );
		add_action( 'admin_init', array( __CLASS__, 'confine_staff' ) );
		add_action( 'admin_init', array( __CLASS__, 'confine_clients' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
	}

	public static function register_rest() {
		foreach ( Resources::names() as $name ) {
			( new Resource_Controller( $name ) )->register();
		}
		Custom_Routes::register();
	}

	/* ───────────────────────── WordPress admin menus ───────────────────────── */

	public static function admin_menu() {
		// Core: Home, Clients, Team, Activity, Settings.
		add_menu_page( 'BizyDash', 'BizyDash', 'manage_bizydash', self::PAGE, array( __CLASS__, 'render' ), 'dashicons-chart-area', 26 );
		$core = array( 'Home' => '/', 'Clients' => '/clients', 'Team' => '/team', 'Departments' => '/departments', 'Activity' => '/activity', 'Settings' => '/settings' );
		$first = true;
		foreach ( $core as $label => $route ) {
			// Submenu items are in-app hash routes; the first one replaces the auto-generated duplicate parent entry.
			$slug = $first ? self::PAGE : self::PAGE . '#' . $route;
			add_submenu_page( self::PAGE, $label, $label, 'manage_bizydash', $slug, $first ? array( __CLASS__, 'render' ) : '__return_null' );
			$first = false;
		}
		// Each enabled module is its own top-level menu with its own sub-menu.
		foreach ( Modules::defs() as $id => $d ) {
			if ( ! empty( $d['core'] ) || ! Modules::enabled( $id ) ) {
				continue;
			}
			$parent = self::PAGE . '-' . $id;
			add_menu_page( 'BizyDash ' . $d['label'], 'BD ' . $d['label'], 'manage_bizydash', $parent, array( __CLASS__, 'render' ), $d['wp'][0], $d['wp'][1] );
			$first = true;
			foreach ( $d['menu'] as $it ) {
				if ( ! empty( $it['analytics'] ) && ! Modules::analytics_on( $id ) ) {
					continue;
				}
				if ( ! empty( $it['url'] ) ) {
					continue; // external tools (e.g. the public verify page) are not WordPress admin pages
				}
				// The first entry registers the module's page for everyone who can use BizyDash; later ones may need more.
				$cap  = ( ! $first && ! empty( $it['cap'] ) ) ? $it['cap'] : 'manage_bizydash';
				$slug = $first ? $parent : $parent . '#' . $it['route'];
				add_submenu_page( $parent, $it['label'], $it['label'], $cap, $slug, $first ? array( __CLASS__, 'render' ) : '__return_null' );
				$first = false;
			}
		}
	}

	public static function render() {
		echo '<div id="bizydash-root" class="bizydash-root"><p style="padding:24px">Loading BizyDash…</p></div>';
	}

	private static function current_page() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return array_key_exists( $page, self::pages() ) ? $page : '';
	}

	private static function is_screen() {
		return '' !== self::current_page();
	}

	public static function body_class( $classes ) {
		if ( ! self::is_screen() ) {
			return $classes;
		}
		return $classes . ' bizydash-screen' . ( Settings::get( 'app_fullscreen' ) ? ' bizydash-fullscreen' : '' );
	}

	/** In full-screen mode no stray WordPress notices appear over the app. */
	public static function quiet_admin() {
		if ( self::is_screen() && Settings::get( 'app_fullscreen' ) ) {
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
			remove_all_actions( 'user_admin_notices' );
			remove_all_actions( 'network_admin_notices' );
		}
	}

	/* ───────────────────────── Keeping team members out of wp-admin ───────────────────────── */

	/** A user who works in BizyDash but is not a WordPress administrator. */
	public static function is_staff_only() {
		return is_user_logged_in() && current_user_can( 'manage_bizydash' ) && ! current_user_can( 'manage_options' );
	}

	/** Where staff-only users land: the front-end app if enabled, else the BizyDash admin screen. */
	public static function home_for_staff() {
		return Settings::get( 'app_frontend' ) ? App_Page::url() : admin_url( 'admin.php?page=' . self::PAGE );
	}

	/** A client login (BD Client): portal only, never WordPress admin or the internal app. */
	public static function is_client_only() {
		return is_user_logged_in() && current_user_can( 'bizydash_portal' ) && ! current_user_can( 'manage_bizydash' ) && ! current_user_can( 'manage_options' );
	}

	public static function hide_admin_bar( $show ) {
		if ( self::is_client_only() ) {
			return false;
		}
		return ( Settings::get( 'app_keep_staff' ) && self::is_staff_only() ) ? false : $show;
	}

	public static function confine_clients() {
		if ( ! self::is_client_only() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		global $pagenow;
		if ( in_array( $pagenow, array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
			return;
		}
		wp_safe_redirect( Portal::enabled() ? Portal::url() : home_url( '/' ) );
		exit;
	}

	public static function confine_staff() {
		if ( ! Settings::get( 'app_keep_staff' ) || ! self::is_staff_only() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		global $pagenow;
		if ( in_array( $pagenow, array( 'admin-post.php', 'async-upload.php', 'media-upload.php', 'admin-ajax.php' ), true ) ) {
			return;
		}
		if ( self::is_screen() && ! Settings::get( 'app_frontend' ) ) {
			return; // no front-end app: the BizyDash admin screen is where they work
		}
		wp_safe_redirect( self::home_for_staff() );
		exit;
	}

	public static function login_redirect( $to, $requested, $user ) {
		if ( $user instanceof \WP_User && user_can( $user, 'bizydash_portal' ) && ! user_can( $user, 'manage_bizydash' ) && Portal::enabled() ) {
			return Portal::url();
		}
		if ( $user instanceof \WP_User && Settings::get( 'app_keep_staff' ) && user_can( $user, 'manage_bizydash' ) && ! user_can( $user, 'manage_options' ) ) {
			return self::home_for_staff();
		}
		return $to;
	}

	/* ───────────────────────── Assets (admin screen and front-end app) ───────────────────────── */

	public static function enqueue( $hook ) {
		$page = self::current_page();
		if ( '' === $page || 0 !== strpos( (string) $hook, 'toplevel_page_' ) ) {
			return;
		}
		self::enqueue_app( self::pages()[ $page ], false );
	}

	/** The JavaScript boot object shared by the admin screen and the front-end app page. */
	public static function boot_data( $initial_route, $frontend ) {
		$u = wp_get_current_user();
		return array(
			'restUrl'      => rest_url( 'bizydash/v1' ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'meta'         => Custom_Routes::meta_payload(),
			'nav'          => Modules::nav(),
			'modules'      => Modules::catalog(),
			'coreAnalytics' => array( 'clients' => Modules::analytics_on( 'clients' ), 'team' => Modules::analytics_on( 'team' ) ),
			'initialRoute' => $initial_route,
			'frontend'     => (bool) $frontend,
			'fullscreen'   => (bool) Settings::get( 'app_fullscreen' ) || $frontend,
			'adminUrl'     => admin_url(),
			'appUrl'       => App_Page::url(),
			'logoutUrl'    => ( $frontend && Settings::get( 'app_login' ) ) ? App_Login::logout_url() : wp_specialchars_decode( wp_logout_url(), ENT_QUOTES ),
			'canManage'    => Roles::can_admin(),
			'caps'         => Roles::ui_caps(),
			'freshInstall' => self::is_fresh_install(),
			'demoLoaded'   => Roles::can_admin() && Services\Demo_Data::status()['loaded'],
			'backupAlert'  => ( Roles::can_admin() && 'failed' === Backup\Backup_Manager::settings()['last_status'] ) ? (string) Backup\Backup_Manager::settings()['last_error'] : '',
			'staffOnly'    => self::is_staff_only(),
			'user'         => $u->display_name,
			'userEmail'    => $u->user_email,
			'userId'       => get_current_user_id(),
			'canFinance'   => current_user_can( 'bizydash_finance' ),
			'homeUrl'      => home_url( '/' ),
			'theme'        => Custom_Routes::theme(), // light | dark | auto
		);
	}

	public static function enqueue_app( $initial_route, $frontend ) {
		$js = BIZYDASH_DIR . 'build/bizydash.js';
		if ( ! is_readable( $js ) ) {
			return;
		}
		$ver = (string) filemtime( $js );

		wp_enqueue_media();
		wp_enqueue_script( 'bizydash-app', BIZYDASH_URL . 'build/bizydash.js', array(), $ver, true );
		wp_add_inline_script( 'bizydash-app', 'window.bizydashBoot=' . wp_json_encode( self::boot_data( $initial_route, $frontend ) ) . ';', 'before' );

		if ( is_readable( BIZYDASH_DIR . 'build/bizydash.css' ) ) {
			wp_enqueue_style( 'bizydash-app', BIZYDASH_URL . 'build/bizydash.css', array(), $ver );
			wp_add_inline_style( 'bizydash-app', self::font_face_css() );
		}
		// Start fetching the main font early so text never flashes in a fallback face.
		add_action( $frontend ? 'wp_head' : 'admin_head', array( __CLASS__, 'preload_font' ), 1 );
	}

	public static function preload_font() {
		echo '<link rel="preload" href="' . esc_url( BIZYDASH_URL . 'assets/fonts/google-sans-latin.woff2' ) . '" as="font" type="font/woff2" crossorigin>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Self-hosted Inter (variable). unicode-range lets the browser fetch only the subsets a page uses (₦ lives in latin-ext). */
	private static function font_face_css() {
		$base = BIZYDASH_URL . 'assets/fonts/';
		$face = "@font-face{font-family:'Inter';font-style:normal;font-weight:100 900;font-display:swap;src:url('%s') format('woff2');unicode-range:%s;}";
		$gs   = "@font-face{font-family:'Google Sans';font-style:normal;font-weight:400 700;font-display:swap;src:url('%s') format('woff2');unicode-range:%s;}";
		$gs_css = sprintf( $gs, $base . 'google-sans-latin.woff2', 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD' )
			. sprintf( $gs, $base . 'google-sans-latin-ext.woff2', 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF' );
		// Google Sans has no ₦ glyph, so Inter (already loaded) supplies that one character.
		return $gs_css . sprintf( $face, $base . 'inter-latin.woff2', 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD' )
			. sprintf( $face, $base . 'inter-latin-ext.woff2', 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF' );
	}
}
