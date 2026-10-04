<?php
namespace BizyDash;

use BizyDash\Services\Portal_Data;
use BizyDash\Services\Portal_Users;

defined( 'ABSPATH' ) || exit;

/**
 * The client portal: https://site/portal/ (the word is configurable).
 *
 * A theme-less, server-rendered page set where a client signs in and sees only their own organisation: projects and
 * tasks, invoices and bills still to come, and contracts. Every query is scoped to the client ids linked to the
 * signed-in user (user meta), staff can open the same pages for any client with ?as=<client id> to see what the client
 * sees, and nothing internal (costs, team pay, notes) is ever selected.
 */
class Portal {

	public static function slug() {
		$s = sanitize_title( (string) Settings::get( 'portal_slug' ) );
		return '' !== $s ? $s : 'portal';
	}

	/** Address of a portal page. $path e.g. 'projects/12', $args extra query args. */
	public static function url( $path = '', array $args = array() ) {
		$u = home_url( '/' . self::slug() . '/' . ( '' !== $path ? trim( $path, '/' ) . '/' : '' ) );
		return $args ? add_query_arg( $args, $u ) : $u;
	}

	public static function enabled() {
		return (bool) Settings::get( 'portal_enabled' );
	}

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );
		add_action( 'wp_login', array( __CLASS__, 'note_login' ), 10, 2 );
	}

	public static function note_login( $login, $user ) {
		if ( $user instanceof \WP_User && user_can( $user, 'bizydash_portal' ) ) {
			update_user_meta( $user->ID, 'bizydash_last_login', time() );
		}
	}

	private static function requested() {
		$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return trim( substr( (string) $path, strlen( $home ) ), '/' );
	}

	/* ───────────────────────── Request handling ───────────────────────── */

	private static $ctx = array();

	public static function maybe_render() {
		$path = self::requested();
		$slug = self::slug();
		if ( $path !== $slug && 0 !== strpos( $path, $slug . '/' ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'bizydash portal' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: same-origin' );

		$rest = array_values( array_filter( explode( '/', substr( $path, strlen( $slug ) ) ), 'strlen' ) );

		if ( ! is_user_logged_in() ) {
			if ( ! self::enabled() ) {
				self::message_page( 'The client portal is not available', 'Please contact us if you need access.', 404 );
			}
			App_Login::route_for_portal( self::url() );
			exit;
		}
		$user = wp_get_current_user();
		$as   = isset( $_GET['as'] ) ? absint( $_GET['as'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$preview = false;
		if ( $as && current_user_can( 'manage_bizydash' ) && current_user_can( 'bizydash_clients' ) ) {
			$ids     = array( $as );
			$preview = true;
		} elseif ( user_can( $user, 'bizydash_portal' ) || Portal_Users::client_ids( $user->ID ) ) {
			if ( current_user_can( 'manage_bizydash' ) ) { // staff who are also somebody's contact use the team app
				wp_safe_redirect( App_Page::url() );
				exit;
			}
			$ids = Portal_Users::client_ids( $user->ID );
		} else {
			self::message_page( 'This is the client portal', 'Your account is not set up for the portal.' . ( current_user_can( 'manage_bizydash' ) ? ' Team members: open a client in BizyDash and choose “View portal as client”.' : '' ), 403, true );
		}
		if ( ! self::enabled() && ! $preview ) {
			self::message_page( 'The client portal is not available', 'Please contact us if you need access.', 404, true );
		}
		if ( ! $ids ) {
			self::message_page( 'No organisation linked yet', 'Your login is not linked to a client yet. Please contact us and we will sort it out.', 200, true );
		}
		// Which organisation is being looked at.
		$cur = (int) $ids[0];
		if ( count( $ids ) > 1 ) {
			$want = isset( $_GET['org'] ) ? absint( $_GET['org'] ) : (int) get_user_meta( $user->ID, 'bizydash_portal_org', true ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( $want && in_array( $want, $ids, true ) ) {
				$cur = $want;
				if ( ! $preview && (int) get_user_meta( $user->ID, 'bizydash_portal_org', true ) !== $want ) {
					update_user_meta( $user->ID, 'bizydash_portal_org', $want );
				}
			}
		}
		$client = Services\Portal_Data::client( $cur );
		if ( ! $client ) {
			self::message_page( 'Not found', 'That organisation could not be found.', 404, true );
		}
		self::$ctx = array( 'user' => $user, 'ids' => $ids, 'client' => $client, 'preview' => $preview, 'sec' => Portal_Data::sections() );

		$page = $rest[0] ?? '';
		$id   = isset( $rest[1] ) && ctype_digit( $rest[1] ) ? (int) $rest[1] : 0;
		$sub  = $rest[2] ?? '';
		$post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$sec  = self::$ctx['sec'];

		if ( 'account' === $page ) {
			$post ? self::account_post() : self::page_account();
		} elseif ( 'projects' === $page && $sec['projects'] ) {
			$id ? self::page_project( $id ) : self::page_projects();
		} elseif ( 'billing' === $page && ( $sec['invoices'] || $sec['billing'] ) ) {
			$id ? self::page_invoice( $id ) : self::page_billing();
		} elseif ( 'contracts' === $page && $sec['contracts'] ) {
			if ( $id && 'pdf' === $sub ) {
				self::stream_pdf( $id );
			}
			self::page_contracts();
		} elseif ( '' === $page ) {
			self::page_overview();
		}
		self::message_page( 'Page not found', 'We could not find that page.', 404, true );
	}

	/* ───────────────────────── Helpers ───────────────────────── */

	private static function e( $s ) {
		return esc_html( (string) $s );
	}

	/** A portal link that keeps staff in preview mode and the chosen organisation. */
	private static function link( $path = '', array $args = array() ) {
		if ( self::$ctx['preview'] ) {
			$args['as'] = (int) self::$ctx['client']['id'];
		}
		return self::url( $path, $args );
	}

	private static function money( $n, $cur ) {
		$sym = $cur;
		foreach ( (array) Settings::get( 'currencies' ) as $c ) {
			if ( isset( $c['code'] ) && $c['code'] === $cur ) {
				$sym = $c['symbol'] ? $c['symbol'] : $cur;
			}
		}
		$n = (float) $n;
		return $sym . number_format( $n, abs( $n - round( $n ) ) > 0.004 ? 2 : 0 );
	}

	private static function money_map( array $map ) {
		if ( ! $map ) {
			return '—';
		}
		return implode( ' · ', array_map( function ( $c, $v ) {
			return self::money( $v, $c );
		}, array_keys( $map ), $map ) );
	}

	private static function date( $d ) {
		return $d && '0000-00-00' !== substr( (string) $d, 0, 10 ) ? wp_date( get_option( 'date_format' ), strtotime( (string) $d ) ) : '—';
	}

	private static function badge( $label, $tone = 'neutral' ) {
		return '<span class="badge badge--' . esc_attr( $tone ) . '">' . self::e( $label ) . '</span>';
	}

	private static function invoice_badge( $status ) {
		$m = array( 'sent' => array( 'Awaiting payment', 'info' ), 'due' => array( 'Due', 'warning' ), 'overdue' => array( 'Overdue', 'danger' ), 'partially_paid' => array( 'Part paid', 'warning' ), 'paid' => array( 'Paid', 'success' ) );
		$x = $m[ $status ] ?? array( ucfirst( $status ), 'neutral' );
		return self::badge( $x[0], $x[1] );
	}

	private static function project_badge( $s ) {
		$m = array( 'planned' => array( 'Planned', 'neutral' ), 'active' => array( 'In progress', 'info' ), 'on_hold' => array( 'On hold', 'warning' ), 'done' => array( 'Completed', 'success' ) );
		$x = $m[ $s ] ?? array( ucfirst( $s ), 'neutral' );
		return self::badge( $x[0], $x[1] );
	}

	private static function task_badge( $s ) {
		$m = array( 'todo' => array( 'To do', 'neutral' ), 'doing' => array( 'In progress', 'info' ), 'done' => array( 'Done', 'success' ) );
		$x = $m[ $s ] ?? array( ucfirst( $s ), 'neutral' );
		return self::badge( $x[0], $x[1] );
	}

	private static function contract_badge( $s ) {
		$m = array( 'sent' => array( 'Awaiting signatures', 'info' ), 'partially_signed' => array( 'Partly signed', 'warning' ), 'signed' => array( 'Signed', 'success' ), 'declined' => array( 'Declined', 'danger' ), 'expired' => array( 'Expired', 'neutral' ) );
		$x = $m[ $s ] ?? array( ucfirst( str_replace( '_', ' ', $s ) ), 'neutral' );
		return self::badge( $x[0], $x[1] );
	}

	private static function progress( $pct ) {
		return '<div class="bar" role="progressbar" aria-valuenow="' . (int) $pct . '" aria-valuemin="0" aria-valuemax="100"><span style="width:' . (int) $pct . '%"></span></div>';
	}

	private static function empty_state( $title, $text ) {
		return '<div class="empty"><strong>' . self::e( $title ) . '</strong><p>' . self::e( $text ) . '</p></div>';
	}

	/* ───────────────────────── Pages ───────────────────────── */

	private static function page_overview() {
		$c    = self::$ctx['client'];
		$sec  = self::$ctx['sec'];
		$user = self::$ctx['user'];
		$name = self::$ctx['preview'] ? $c['contact_person'] : $user->display_name;
		$h    = '<h1>Hello' . ( $name ? ', ' . self::e( explode( ' ', trim( $name ) )[0] ) : '' ) . '</h1>';
		$welcome = (string) Settings::get( 'portal_welcome' );
		$h   .= '<p class="lead">' . ( '' !== $welcome ? nl2br( self::e( $welcome ) ) : 'Here is everything we are doing for ' . self::e( $c['company_name'] ) . '.' ) . '</p>';
		$cards = '';
		$attention = '';
		if ( $sec['invoices'] ) {
			$o      = Portal_Data::owed( $c['id'] );
			$cards .= '<a class="stat" href="' . esc_url( self::link( 'billing', array( 'show' => 'open' ) ) ) . '"><span class="stat__l">Outstanding</span><span class="stat__v">' . self::e( self::money_map( $o['by_currency'] ) ) . '</span><span class="stat__s">' . ( $o['overdue'] ? self::e( $o['overdue'] . ' overdue' ) : self::e( $o['open'] . ' open invoice' . ( 1 === $o['open'] ? '' : 's' ) ) ) . '</span></a>';
			$late = array_values( array_filter( Portal_Data::invoices( $c['id'], 'open' ), function ( $x ) {
				return 'overdue' === $x['status'];
			} ) );
			foreach ( array_slice( $late, 0, 5 ) as $i ) {
				if ( true ) {
					$attention .= '<li><a href="' . esc_url( self::link( 'billing/' . $i['id'] ) ) . '"><strong>Invoice ' . self::e( $i['invoice_number'] ) . '</strong> is overdue</a><span>' . self::e( self::money( $i['balance'], $i['currency'] ) ) . ' · due ' . self::e( self::date( $i['due_date'] ) ) . '</span></li>';
				}
			}
			if ( count( $late ) > 5 ) {
				$attention .= '<li><a href="' . esc_url( self::link( 'billing', array( 'show' => 'open' ) ) ) . '">And ' . ( count( $late ) - 5 ) . ' more overdue invoices</a><span></span></li>';
			}
		}
		if ( $sec['projects'] ) {
			$pr     = Portal_Data::projects( $c['id'] );
			$active = array_filter( $pr, function ( $p ) {
				return 'active' === $p['status'];
			} );
			$cards .= '<a class="stat" href="' . esc_url( self::link( 'projects' ) ) . '"><span class="stat__l">Projects</span><span class="stat__v">' . count( $active ) . ' in progress</span><span class="stat__s">' . count( $pr ) . ' in total</span></a>';
		}
		if ( $sec['billing'] ) {
			$up  = Portal_Data::upcoming( $c['id'] );
			$sum = array();
			foreach ( $up as $u ) {
				$sum[ $u['currency'] ] = ( $sum[ $u['currency'] ] ?? 0 ) + $u['amount'];
			}
			$cards .= '<a class="stat" href="' . esc_url( self::link( 'billing', array( 'show' => 'upcoming' ) ) ) . '"><span class="stat__l">Coming up to bill</span><span class="stat__v">' . self::e( self::money_map( $sum ) ) . '</span><span class="stat__s">' . count( $up ) . ' item' . ( 1 === count( $up ) ? '' : 's' ) . ' not invoiced yet</span></a>';
		}
		if ( $sec['contracts'] ) {
			$ct   = Portal_Data::contracts( $c['id'], self::$ctx['preview'] ? '' : $user->user_email );
			$wait = array_filter( $ct, function ( $x ) {
				return '' !== $x['sign_url'];
			} );
			$cards .= '<a class="stat" href="' . esc_url( self::link( 'contracts' ) ) . '"><span class="stat__l">Contracts</span><span class="stat__v">' . count( $ct ) . '</span><span class="stat__s">' . ( $wait ? self::e( count( $wait ) . ' waiting for your signature' ) : 'nothing waiting for you' ) . '</span></a>';
			foreach ( $wait as $w ) {
				$attention .= '<li><a href="' . esc_url( $w['sign_url'] ) . '"><strong>' . self::e( $w['title'] ) . '</strong> needs your signature</a><span>' . self::e( $w['number'] ) . '</span></li>';
			}
		}
		$h .= '<div class="stats">' . $cards . '</div>';
		if ( $attention ) {
			$h .= '<section class="panel"><h2>Needs your attention</h2><ul class="list">' . $attention . '</ul></section>';
		}
		if ( $sec['projects'] && ! empty( $active ) ) {
			$h .= '<section class="panel"><h2>Projects in progress</h2><ul class="list">';
			foreach ( array_slice( $active, 0, 5 ) as $p ) {
				$h .= '<li class="list__proj"><a href="' . esc_url( self::link( 'projects/' . $p['id'] ) ) . '"><strong>' . self::e( $p['name'] ) . '</strong></a>' . self::progress( $p['percent'] ) . '<span>' . (int) $p['percent'] . '% · ' . ( $p['due_date'] ? 'due ' . self::e( self::date( $p['due_date'] ) ) : 'no due date' ) . '</span></li>';
			}
			$h .= '</ul></section>';
		}
		if ( $sec['invoices'] ) {
			$inv = array_slice( Portal_Data::invoices( $c['id'] ), 0, 5 );
			if ( $inv ) {
				$h .= '<section class="panel"><h2>Recent invoices</h2>' . self::invoice_table( $inv ) . '</section>';
			}
		}
		self::layout( 'Overview', $h, '' );
	}

	private static function invoice_table( array $rows ) {
		$h = '<div class="table-wrap"><table><thead><tr><th>Invoice</th><th>Issued</th><th>Due</th><th class="num">Amount</th><th class="num">Balance</th><th>Status</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$h .= '<tr><td><a href="' . esc_url( self::link( 'billing/' . $r['id'] ) ) . '"><strong>' . self::e( $r['invoice_number'] ) . '</strong></a><div class="muted">' . self::e( $r['title'] ) . '</div></td><td>' . self::e( self::date( $r['issue_date'] ) ) . '</td><td>' . self::e( self::date( $r['due_date'] ) ) . '</td><td class="num">' . self::e( self::money( $r['amount'], $r['currency'] ) ) . '</td><td class="num">' . self::e( self::money( $r['balance'], $r['currency'] ) ) . '</td><td>' . self::invoice_badge( $r['status'] ) . '</td></tr>';
		}
		return $h . '</tbody></table></div>';
	}

	private static function page_projects() {
		$c  = self::$ctx['client'];
		$pr = Portal_Data::projects( $c['id'] );
		$h  = '<h1>Projects</h1><p class="lead">What we are working on for you.</p>';
		if ( ! $pr ) {
			$h .= self::empty_state( 'No projects yet', 'When we start a project for you it will appear here with its progress.' );
		} else {
			$h .= '<div class="cards">';
			foreach ( $pr as $p ) {
				$h .= '<a class="card" href="' . esc_url( self::link( 'projects/' . $p['id'] ) ) . '"><div class="card__top"><strong>' . self::e( $p['name'] ) . '</strong>' . self::project_badge( $p['status'] ) . '</div>' . ( $p['description'] ? '<p class="muted clamp">' . self::e( wp_strip_all_tags( $p['description'] ) ) . '</p>' : '' ) . self::progress( $p['percent'] ) . '<div class="card__meta"><span>' . (int) $p['percent'] . '% complete' . ( self::$ctx['sec']['tasks'] ? ' · ' . (int) $p['tasks_done'] . ' of ' . (int) $p['tasks_total'] . ' tasks' : '' ) . '</span><span>' . ( $p['due_date'] ? 'Due ' . self::e( self::date( $p['due_date'] ) ) : '' ) . '</span></div></a>';
			}
			$h .= '</div>';
		}
		self::layout( 'Projects', $h, 'projects' );
	}

	private static function task_list( array $tasks, $assignee ) {
		if ( ! $tasks ) {
			return '<p class="muted">No tasks yet.</p>';
		}
		$h = '<ul class="tasks">';
		foreach ( $tasks as $t ) {
			$h .= '<li class="task' . ( 'done' === $t['status'] ? ' is-done' : '' ) . '"><span class="task__t">' . self::e( $t['title'] ) . ( 'high' === ( $t['priority'] ?? '' ) ? ' ' . self::badge( 'High priority', 'warning' ) : '' ) . '</span><span class="task__m">' . self::e( implode( ' · ', array_filter( array( $t['due_date'] && 'done' !== $t['status'] ? 'Due ' . self::date( $t['due_date'] ) : '', $assignee && ! empty( $t['assignee'] ) ? $t['assignee'] : '' ) ) ) ) . '</span>' . self::task_badge( $t['status'] ) . '</li>';
		}
		return $h . '</ul>';
	}

	private static function page_project( $id ) {
		$sec = self::$ctx['sec'];
		$p   = Portal_Data::project( self::$ctx['client']['id'], $id, $sec['assignee'] );
		if ( ! $p ) {
			self::message_page( 'Page not found', 'We could not find that project.', 404, true );
		}
		$h  = '<p class="crumb"><a href="' . esc_url( self::link( 'projects' ) ) . '">← All projects</a></p><div class="head"><h1>' . self::e( $p['name'] ) . '</h1>' . self::project_badge( $p['status'] ) . '</div>';
		$h .= '<div class="meta"><span>Started ' . self::e( self::date( $p['start_date'] ) ) . '</span><span>Due ' . self::e( self::date( $p['due_date'] ) ) . '</span></div>';
		$h .= self::progress( $p['percent'] ) . '<p class="muted">' . (int) $p['percent'] . '% complete' . ( $sec['tasks'] ? ' · ' . (int) $p['tasks_done'] . ' of ' . (int) $p['tasks_total'] . ' tasks done' : '' ) . '</p>';
		if ( $p['description'] ) {
			$h .= '<section class="panel"><h2>About this project</h2><div class="prose">' . wp_kses_post( wpautop( $p['description'] ) ) . '</div></section>';
		}
		if ( $p['phases'] ) {
			foreach ( $p['phases'] as $ph ) {
				$pb = array( 'todo' => array( 'Not started', 'neutral' ), 'doing' => array( 'In progress', 'info' ), 'done' => array( 'Done', 'success' ) )[ $ph['status'] ] ?? array( ucfirst( $ph['status'] ), 'neutral' );
				$h .= '<section class="panel"><div class="head"><h2>' . self::e( $ph['name'] ) . '</h2>' . self::badge( $pb[0], $pb[1] ) . '</div>' . ( $ph['due_date'] ? '<p class="muted">Due ' . self::e( self::date( $ph['due_date'] ) ) . '</p>' : '' ) . ( $sec['tasks'] ? self::task_list( $ph['tasks'], $sec['assignee'] ) : '' ) . '</section>';
			}
		}
		if ( $sec['tasks'] && $p['other'] ) {
			$h .= '<section class="panel"><h2>' . ( $p['phases'] ? 'Other tasks' : 'Tasks' ) . '</h2>' . self::task_list( $p['other'], $sec['assignee'] ) . '</section>';
		}
		if ( ! $p['phases'] && ! $p['other'] ) {
			$h .= self::empty_state( 'Nothing planned yet', 'Phases and tasks will show here as the work is planned.' );
		}
		self::layout( $p['name'], $h, 'projects' );
	}

	private static function page_billing() {
		$c    = self::$ctx['client'];
		$sec  = self::$ctx['sec'];
		$show = isset( $_GET['show'] ) ? sanitize_key( wp_unslash( $_GET['show'] ) ) : ( $sec['invoices'] ? 'all' : 'upcoming' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'upcoming' === $show && ! $sec['billing'] ) {
			$show = 'all';
		}
		if ( 'upcoming' !== $show && ! $sec['invoices'] ) {
			$show = 'upcoming';
		}
		$h = '<h1>Billing</h1><p class="lead">Invoices we have sent you, and what is coming up.</p><div class="tabs">';
		if ( $sec['invoices'] ) {
			foreach ( array( 'all' => 'All invoices', 'open' => 'Unpaid', 'paid' => 'Paid' ) as $k => $l ) {
				$h .= '<a class="tab' . ( $show === $k ? ' is-on' : '' ) . '" href="' . esc_url( self::link( 'billing', array( 'show' => $k ) ) ) . '">' . self::e( $l ) . '</a>';
			}
		}
		if ( $sec['billing'] ) {
			$h .= '<a class="tab' . ( 'upcoming' === $show ? ' is-on' : '' ) . '" href="' . esc_url( self::link( 'billing', array( 'show' => 'upcoming' ) ) ) . '">Coming up</a>';
		}
		$h .= '</div>';
		if ( 'upcoming' === $show ) {
			$up = Portal_Data::upcoming( $c['id'] );
			$h .= '<p class="muted">These are charges we have lined up for you that have not been put on an invoice yet. You will receive an invoice for each one.</p>';
			if ( ! $up ) {
				$h .= self::empty_state( 'Nothing coming up', 'There is nothing waiting to be invoiced right now.' );
			} else {
				$sum = array();
				$h  .= '<div class="table-wrap"><table><thead><tr><th>What</th><th>Type</th><th>Expected</th><th class="num">Amount</th></tr></thead><tbody>';
				foreach ( $up as $u ) {
					$sum[ $u['currency'] ] = ( $sum[ $u['currency'] ] ?? 0 ) + $u['amount'];
					$h .= '<tr><td><strong>' . self::e( $u['title'] ) . '</strong></td><td>' . self::badge( $u['kind'] ) . '</td><td>' . self::e( self::date( $u['date'] ) ) . '</td><td class="num">' . self::e( self::money( $u['amount'], $u['currency'] ) ) . '</td></tr>';
				}
				$h .= '</tbody><tfoot><tr><td colspan="3">Total</td><td class="num">' . self::e( self::money_map( $sum ) ) . '</td></tr></tfoot></table></div>';
			}
		} else {
			$o = Portal_Data::owed( $c['id'] );
			if ( $o['open'] ) {
				$h .= '<div class="notice">Outstanding: <strong>' . self::e( self::money_map( $o['by_currency'] ) ) . '</strong> across ' . (int) $o['open'] . ' invoice' . ( 1 === $o['open'] ? '' : 's' ) . ( $o['overdue'] ? ' (' . (int) $o['overdue'] . ' overdue)' : '' ) . '.</div>';
			}
			$rows  = Portal_Data::invoices( $c['id'], $show );
			$per   = 25;
			$pages = max( 1, (int) ceil( count( $rows ) / $per ) );
			$pg    = min( $pages, max( 1, isset( $_GET['pg'] ) ? absint( $_GET['pg'] ) : 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$h    .= $rows ? self::invoice_table( array_slice( $rows, ( $pg - 1 ) * $per, $per ) ) : self::empty_state( 'No invoices', 'Invoices we send you will be listed here.' );
			if ( $pages > 1 ) {
				$h .= '<div class="tabs" style="margin-top:14px">' . ( $pg > 1 ? '<a class="tab" href="' . esc_url( self::link( 'billing', array( 'show' => $show, 'pg' => $pg - 1 ) ) ) . '">← Newer</a>' : '' ) . '<span class="tab">Page ' . $pg . ' of ' . $pages . '</span>' . ( $pg < $pages ? '<a class="tab" href="' . esc_url( self::link( 'billing', array( 'show' => $show, 'pg' => $pg + 1 ) ) ) . '">Older →</a>' : '' ) . '</div>';
			}
		}
		self::layout( 'Billing', $h, 'billing' );
	}

	private static function page_invoice( $id ) {
		$i = Portal_Data::invoice( self::$ctx['client']['id'], $id );
		if ( ! $i ) {
			self::message_page( 'Page not found', 'We could not find that invoice.', 404, true );
		}
		$cur = $i['currency'];
		$h   = '<p class="crumb"><a href="' . esc_url( self::link( 'billing' ) ) . '">← Billing</a></p><div class="head"><h1>Invoice ' . self::e( $i['invoice_number'] ) . '</h1>' . self::invoice_badge( $i['status'] ) . '</div>';
		$h  .= '<p class="lead">' . self::e( $i['title'] ) . '</p>';
		$h  .= '<div class="stats"><div class="stat"><span class="stat__l">Amount</span><span class="stat__v">' . self::e( self::money( $i['amount'], $cur ) ) . '</span></div><div class="stat"><span class="stat__l">Paid</span><span class="stat__v">' . self::e( self::money( $i['amount_paid'], $cur ) ) . '</span></div><div class="stat"><span class="stat__l">Balance</span><span class="stat__v">' . self::e( self::money( $i['balance'], $cur ) ) . '</span><span class="stat__s">Due ' . self::e( self::date( $i['due_date'] ) ) . '</span></div></div>';
		if ( $i['balance'] > 0 && $i['public_token'] ) {
			$h .= '<p><a class="btn" href="' . esc_url( Services\Invoices::public_url( $i['public_token'] ) ) . '">View, print or pay this invoice</a></p>';
		} elseif ( $i['public_token'] ) {
			$h .= '<p><a class="btn btn--ghost" href="' . esc_url( Services\Invoices::public_url( $i['public_token'] ) ) . '">View or print this invoice</a></p>';
		}
		$h .= '<section class="panel"><h2>What it covers</h2><div class="table-wrap"><table><thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Amount</th></tr></thead><tbody>';
		foreach ( $i['items'] as $it ) {
			$h .= '<tr><td>' . self::e( $it['description'] ) . '</td><td class="num">' . self::e( rtrim( rtrim( number_format( (float) $it['quantity'], 2 ), '0' ), '.' ) ) . '</td><td class="num">' . self::e( self::money( $it['unit_price'], $cur ) ) . '</td><td class="num">' . self::e( self::money( $it['amount'], $cur ) ) . '</td></tr>';
		}
		$h .= '</tbody><tfoot>';
		if ( (float) $i['discount_amount'] > 0 || (float) $i['tax_amount'] > 0 ) {
			$h .= '<tr><td colspan="3">Subtotal</td><td class="num">' . self::e( self::money( $i['subtotal'], $cur ) ) . '</td></tr>';
			if ( (float) $i['discount_amount'] > 0 ) {
				$h .= '<tr><td colspan="3">Discount</td><td class="num">−' . self::e( self::money( $i['discount_amount'], $cur ) ) . '</td></tr>';
			}
			if ( (float) $i['tax_amount'] > 0 ) {
				$h .= '<tr><td colspan="3">' . self::e( Settings::get( 'tax_label' ) ?: 'Tax' ) . ' (' . self::e( rtrim( rtrim( number_format( (float) $i['tax_rate'], 2 ), '0' ), '.' ) ) . '%)</td><td class="num">' . self::e( self::money( $i['tax_amount'], $cur ) ) . '</td></tr>';
			}
		}
		$h .= '<tr><td colspan="3"><strong>Total</strong></td><td class="num"><strong>' . self::e( self::money( $i['amount'], $cur ) ) . '</strong></td></tr></tfoot></table></div></section>';
		$h .= '<section class="panel"><h2>Payments received</h2>';
		if ( $i['payments'] ) {
			$h .= '<div class="table-wrap"><table><thead><tr><th>Date</th><th>How</th><th>Reference</th><th class="num">Amount</th></tr></thead><tbody>';
			foreach ( $i['payments'] as $p ) {
				$h .= '<tr><td>' . self::e( self::date( $p['paid_on'] ) ) . '</td><td>' . self::e( $p['method'] ?: '—' ) . '</td><td>' . self::e( $p['reference'] ?: '—' ) . '</td><td class="num">' . self::e( self::money( $p['amount'], $p['currency'] ?: $cur ) ) . '</td></tr>';
			}
			$h .= '</tbody></table></div>';
		} else {
			$h .= '<p class="muted">No payment has been recorded yet.</p>';
		}
		$h .= '</section>';
		self::layout( 'Invoice ' . $i['invoice_number'], $h, 'billing' );
	}

	private static function page_contracts() {
		$c  = self::$ctx['client'];
		$ct = Portal_Data::contracts( $c['id'], self::$ctx['preview'] ? '' : self::$ctx['user']->user_email );
		$h  = '<h1>Contracts</h1><p class="lead">Agreements between us, and their signing status.</p>';
		if ( ! $ct ) {
			$h .= self::empty_state( 'No contracts yet', 'Contracts we send you to sign will appear here, and signed copies can be downloaded.' );
		}
		foreach ( $ct as $x ) {
			$h .= '<section class="panel"><div class="head"><div><strong>' . self::e( $x['title'] ) . '</strong><div class="muted">' . self::e( $x['number'] ) . ( $x['sent_at'] ? ' · sent ' . self::e( self::date( $x['sent_at'] ) ) : '' ) . ( $x['completed_at'] ? ' · signed ' . self::e( self::date( $x['completed_at'] ) ) : '' ) . '</div></div>' . self::contract_badge( $x['status'] ) . '</div>';
			if ( $x['signers'] ) {
				$h .= '<ul class="signers">';
				foreach ( $x['signers'] as $s ) {
					$h .= '<li><span>' . self::e( $s['name'] ) . ( $s['role'] ? ' <span class="muted">· ' . self::e( $s['role'] ) . '</span>' : '' ) . '</span><span>' . ( 'signed' === $s['status'] ? self::badge( 'Signed ' . self::date( $s['signed_at'] ), 'success' ) : ( 'declined' === $s['status'] ? self::badge( 'Declined', 'danger' ) : self::badge( 'Waiting', 'neutral' ) ) ) . '</span></li>';
				}
				$h .= '</ul>';
			}
			$h .= '<div class="actions">';
			if ( $x['sign_url'] ) {
				$h .= '<a class="btn" href="' . esc_url( $x['sign_url'] ) . '">Review and sign</a>';
			}
			if ( $x['has_pdf'] ) {
				$h .= '<a class="btn btn--ghost" href="' . esc_url( self::link( 'contracts/' . $x['id'] . '/pdf' ) ) . '">Download signed PDF</a>';
			}
			if ( $x['cert_url'] ) {
				$h .= '<a class="btn btn--ghost" href="' . esc_url( $x['cert_url'] ) . '">Verify authenticity</a>';
			}
			$h .= '</div></section>';
		}
		self::layout( 'Contracts', $h, 'contracts' );
	}

	private static function stream_pdf( $id ) {
		$f = Portal_Data::contract_pdf( self::$ctx['client']['id'], $id );
		if ( ! $f || ! is_readable( $f[0] ) ) {
			self::message_page( 'Not available', 'That document is not available for download.', 404, true );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $f[1] ) . '"' );
		header( 'Content-Length: ' . filesize( $f[0] ) );
		readfile( $f[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	private static function page_account() {
		$u   = self::$ctx['user'];
		$msg = isset( $_GET['bd_msg'] ) ? sanitize_key( wp_unslash( $_GET['bd_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$h   = '<h1>Your account</h1>';
		if ( 'reset' === $msg ) {
			$h .= '<div class="notice notice--ok">If your email is registered, a link to choose a new password is on its way.</div>';
		}
		$h .= '<section class="panel"><h2>Details</h2><dl class="dl"><dt>Name</dt><dd>' . self::e( self::$ctx['preview'] ? ( self::$ctx['client']['contact_person'] ?: '—' ) : $u->display_name ) . '</dd><dt>Email</dt><dd>' . self::e( self::$ctx['preview'] ? '—' : $u->user_email ) . '</dd><dt>Organisation</dt><dd>' . self::e( self::$ctx['client']['company_name'] ) . '</dd></dl></section>';
		if ( ! self::$ctx['preview'] ) {
			$h .= '<section class="panel"><h2>Password</h2><p class="muted">We will email you a link to choose a new password.</p><form method="post" action="' . esc_url( self::link( 'account' ) ) . '">' . wp_nonce_field( 'bd_portal_pw', '_wpnonce', true, false ) . '<button class="btn" type="submit">Email me a password link</button></form></section>';
		}
		self::layout( 'Account', $h, 'account' );
	}

	private static function account_post() {
		if ( self::$ctx['preview'] || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'bd_portal_pw' ) ) {
			wp_safe_redirect( self::link( 'account' ) );
			exit;
		}
		App_Login::send_portal_reset( self::$ctx['user']->user_login );
		wp_safe_redirect( self::link( 'account', array( 'bd_msg' => 'reset' ) ) );
		exit;
	}

	/* ───────────────────────── Layout ───────────────────────── */

	private static function brand() {
		$name = (string) Settings::get( 'business_name' );
		$logo = (string) Settings::get( 'business_logo_url' );
		return $logo ? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $name ) . '">' : '<span class="brand__name">' . self::e( $name ?: 'Client portal' ) . '</span>';
	}

	private static function css() {
		return <<<'CSS'
:root{--bg:#f6f7f9;--card:#fff;--ink:#101828;--muted:#667085;--ring:#e4e7ec;--ring2:#cfd4dc;--accent:#14161d;--on:#fff;--link:#2f54e0;--ok:#067647;--ok-bg:#ecfdf3;--warn:#b54708;--warn-bg:#fffaeb;--info:#175cd3;--info-bg:#eff8ff;--bad:#b42318;--bad-bg:#fef3f2;--neu:#344054;--neu-bg:#f2f4f7;--logo-bg:transparent}
@media (prefers-color-scheme:dark){:root:not([data-theme=light]){--bg:#0e1014;--card:#16181d;--ink:#f2f4f7;--muted:#98a2b3;--ring:#262a31;--ring2:#363b44;--accent:#f2f4f7;--on:#101828;--link:#8ea5ff;--ok:#75e0a7;--ok-bg:#0f2a1c;--warn:#fec84b;--warn-bg:#2b2108;--info:#84caff;--info-bg:#0b2236;--bad:#fda29b;--bad-bg:#2a1513;--neu:#d0d5dd;--neu-bg:#222630;--logo-bg:#fff}}
:root[data-theme=dark]{--bg:#0e1014;--card:#16181d;--ink:#f2f4f7;--muted:#98a2b3;--ring:#262a31;--ring2:#363b44;--accent:#f2f4f7;--on:#101828;--link:#8ea5ff;--ok:#75e0a7;--ok-bg:#0f2a1c;--warn:#fec84b;--warn-bg:#2b2108;--info:#84caff;--info-bg:#0b2236;--bad:#fda29b;--bad-bg:#2a1513;--neu:#d0d5dd;--neu-bg:#222630;--logo-bg:#fff}
*{box-sizing:border-box}html,body{margin:0}body{background:var(--bg);color:var(--ink);font:400 15px/1.55 'Google Sans',Inter,-apple-system,'Segoe UI',Roboto,sans-serif}
a{color:var(--link);text-underline-offset:3px}h1{margin:0 0 6px;font-size:26px;line-height:1.2;font-weight:500;letter-spacing:-.015em}h2{margin:0 0 12px;font-size:16px;font-weight:500}
header.top{background:var(--card);border-bottom:1px solid var(--ring);position:sticky;top:0;z-index:5}
.bar-in{max-width:1080px;margin:0 auto;padding:0 20px;display:flex;align-items:center;gap:20px;min-height:60px;flex-wrap:wrap}
.brand{display:flex;align-items:center;color:var(--ink);text-decoration:none;font-weight:500;font-size:17px}.brand img{height:34px;width:auto;min-width:60px;max-width:180px;object-fit:contain;background:var(--logo-bg);padding:4px 8px;border-radius:8px}
nav.main{display:flex;gap:2px;overflow-x:auto;flex:1}nav.main a{padding:8px 12px;border-radius:8px;color:var(--muted);text-decoration:none;white-space:nowrap;font-weight:500}nav.main a:hover{background:var(--bg);color:var(--ink)}nav.main a.is-on{background:var(--bg);color:var(--ink)}
.who{display:flex;align-items:center;gap:10px;font-size:13px;color:var(--muted)}.who select{height:34px;border:1px solid var(--ring2);border-radius:8px;background:var(--card);color:var(--ink);padding:0 8px;font:inherit;font-size:13px}.who a{color:var(--muted)}
main.wrap{max-width:1080px;margin:0 auto;padding:28px 20px 60px}
.lead{margin:0 0 22px;color:var(--muted)}.muted{color:var(--muted);font-size:13.5px}.crumb{margin:0 0 10px;font-size:13.5px}
.preview{background:var(--warn-bg);color:var(--warn);text-align:center;padding:8px 12px;font-size:13px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:0 0 22px}
.stat{display:flex;flex-direction:column;gap:2px;background:var(--card);border:1px solid var(--ring);border-radius:12px;padding:16px;text-decoration:none;color:var(--ink)}a.stat:hover{border-color:var(--ring2)}.stat__l{color:var(--muted);font-size:13px}.stat__v{font-size:21px;font-weight:500;letter-spacing:-.01em}.stat__s{color:var(--muted);font-size:13px}
.panel{background:var(--card);border:1px solid var(--ring);border-radius:12px;padding:20px;margin:0 0 16px}
.head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.head h1,.head h2{margin:0}
.list{list-style:none;margin:0;padding:0}.list li{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid var(--ring);flex-wrap:wrap}.list li:first-child{border-top:0}.list li span{color:var(--muted);font-size:13.5px}.list a{color:var(--ink)}
.list__proj{display:grid!important;grid-template-columns:1fr 160px auto;align-items:center}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px}.card{display:block;background:var(--card);border:1px solid var(--ring);border-radius:12px;padding:18px;text-decoration:none;color:var(--ink)}.card:hover{border-color:var(--ring2)}.card__top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin-bottom:8px}.card__meta{display:flex;justify-content:space-between;gap:8px;color:var(--muted);font-size:13px;margin-top:8px}.clamp{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin:0 0 10px}
.bar{height:8px;background:var(--neu-bg);border-radius:999px;overflow:hidden}.bar span{display:block;height:100%;background:var(--accent);border-radius:999px}
.badge{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:500;white-space:nowrap}.badge--success{background:var(--ok-bg);color:var(--ok)}.badge--warning{background:var(--warn-bg);color:var(--warn)}.badge--info{background:var(--info-bg);color:var(--info)}.badge--danger{background:var(--bad-bg);color:var(--bad)}.badge--neutral{background:var(--neu-bg);color:var(--neu)}
.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:14px}th{text-align:left;color:var(--muted);font-weight:500;font-size:12.5px;padding:8px 10px;border-bottom:1px solid var(--ring);white-space:nowrap}td{padding:11px 10px;border-bottom:1px solid var(--ring);vertical-align:top}tfoot td{border-bottom:0;font-weight:500}.num{text-align:right;white-space:nowrap}td a{color:var(--ink)}
.tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 16px}.tab{padding:7px 14px;border:1px solid var(--ring);border-radius:999px;color:var(--muted);text-decoration:none;font-size:13.5px;background:var(--card)}.tab.is-on{background:var(--accent);color:var(--on);border-color:var(--accent)}
.btn{display:inline-block;padding:10px 18px;border-radius:8px;background:var(--accent);color:var(--on);text-decoration:none;font:inherit;font-weight:500;border:1px solid var(--accent);cursor:pointer}.btn--ghost{background:transparent;color:var(--ink);border-color:var(--ring2)}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.notice{background:var(--info-bg);color:var(--info);border-radius:10px;padding:12px 14px;margin:0 0 16px}.notice--ok{background:var(--ok-bg);color:var(--ok)}
.empty{background:var(--card);border:1px dashed var(--ring2);border-radius:12px;padding:34px 20px;text-align:center}.empty p{margin:4px 0 0;color:var(--muted)}
.tasks{list-style:none;margin:8px 0 0;padding:0}.task{display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px solid var(--ring)}.task:first-child{border-top:0}.task__t{flex:1}.task__m{color:var(--muted);font-size:13px}.task.is-done .task__t{color:var(--muted);text-decoration:line-through}
.meta{display:flex;gap:18px;color:var(--muted);font-size:13.5px;margin:6px 0 14px;flex-wrap:wrap}.signers{list-style:none;margin:12px 0 0;padding:0}.signers li{display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-top:1px solid var(--ring)}
.dl{display:grid;grid-template-columns:140px 1fr;gap:8px 14px;margin:0}.dl dt{color:var(--muted)}.dl dd{margin:0}.prose{color:var(--ink)}
footer.foot{text-align:center;color:var(--muted);font-size:12.5px;padding:0 0 30px}
@media (max-width:640px){main.wrap{padding:20px 14px 50px}h1{font-size:22px}.list__proj{grid-template-columns:1fr}.dl{grid-template-columns:1fr}.bar-in{gap:10px;padding:8px 14px}}
@media print{header.top,.tabs,.actions,.preview{display:none}body{background:#fff}}
CSS;
	}

	private static function layout( $title, $body, $active ) {
		$c    = self::$ctx['client'];
		$sec  = self::$ctx['sec'];
		$nav  = array( '' => 'Overview' );
		if ( $sec['projects'] ) {
			$nav['projects'] = 'Projects';
		}
		if ( $sec['invoices'] || $sec['billing'] ) {
			$nav['billing'] = 'Billing';
		}
		if ( $sec['contracts'] ) {
			$nav['contracts'] = 'Contracts';
		}
		$label = (string) Settings::get( 'portal_title' );
		$label = '' !== $label ? $label : 'Client portal';
		status_header( 200 );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title><?php echo esc_html( $title . ' — ' . $label ); ?></title>
<style>@font-face{font-family:'Google Sans';font-style:normal;font-weight:400 700;font-display:swap;src:url('<?php echo esc_url( BIZYDASH_URL . 'assets/fonts/google-sans-latin.woff2' ); ?>') format('woff2');}<?php echo self::css(); // phpcs:ignore WordPress.Security.EscapeOutput ?></style>
</head>
<body>
		<?php if ( self::$ctx['preview'] ) : ?>
<div class="preview">Preview: this is what <?php echo esc_html( $c['company_name'] ); ?> sees. <a href="<?php echo esc_url( App_Page::url() . '#/clients/' . (int) $c['id'] ); ?>">Back to BizyDash</a></div>
		<?php endif; ?>
<header class="top"><div class="bar-in">
	<a class="brand" href="<?php echo esc_url( self::link() ); ?>"><?php echo self::brand(); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
	<nav class="main" aria-label="Portal">
		<?php foreach ( $nav as $k => $l ) : ?>
		<a href="<?php echo esc_url( self::link( $k ) ); ?>" class="<?php echo $active === $k ? 'is-on' : ''; ?>"><?php echo esc_html( $l ); ?></a>
		<?php endforeach; ?>
	</nav>
	<div class="who">
		<?php if ( count( self::$ctx['ids'] ) > 1 && ! self::$ctx['preview'] ) : ?>
		<form method="get" action="<?php echo esc_url( self::url() ); ?>"><select name="org" onchange="this.form.submit()" aria-label="Organisation">
			<?php foreach ( self::$ctx['ids'] as $oid ) : $o = Portal_Data::client( $oid ); if ( ! $o ) { continue; } ?>
			<option value="<?php echo (int) $oid; ?>" <?php selected( (int) $oid, (int) $c['id'] ); ?>><?php echo esc_html( $o['company_name'] ); ?></option>
			<?php endforeach; ?>
		</select></form>
		<?php else : ?>
		<span><?php echo esc_html( $c['company_name'] ); ?></span>
		<?php endif; ?>
		<?php if ( ! self::$ctx['preview'] ) : ?>
		<a href="<?php echo esc_url( self::link( 'account' ) ); ?>">Account</a>
		<a href="<?php echo esc_url( wp_logout_url( self::url( '', array( 'bd_msg' => 'out' ) ) ) ); ?>">Sign out</a>
		<?php endif; ?>
	</div>
</div></header>
<main class="wrap"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput ?></main>
<footer class="foot"><?php echo esc_html( Settings::get( 'business_name' ) ); ?></footer>
</body>
</html>
		<?php
		exit;
	}

	/** A plain page for "not available / not found / not linked" (with sign-out for signed-in people). */
	private static function message_page( $title, $text, $status = 200, $signed_in = false ) {
		status_header( $status );
		$name = (string) Settings::get( 'business_name' );
		?>
<!doctype html>
<html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><meta name="color-scheme" content="light dark"><title><?php echo esc_html( $title ); ?></title>
<style><?php echo self::css(); // phpcs:ignore WordPress.Security.EscapeOutput ?>body{display:grid;place-items:center;min-height:100vh;padding:20px}.msg{max-width:440px;text-align:center}</style></head>
<body><div class="msg"><h1><?php echo esc_html( $title ); ?></h1><p class="lead"><?php echo esc_html( $text ); ?></p>
<?php if ( $signed_in ) : ?><p><a class="btn btn--ghost" href="<?php echo esc_url( wp_logout_url( self::url() ) ); ?>">Sign out</a></p><?php endif; ?>
<p class="muted"><?php echo esc_html( $name ); ?></p></div></body></html>
		<?php
		exit;
	}

	/* ───────────────────────── Invites ───────────────────────── */

	/** Email a client contact their portal address with a link to choose a password. @return true|\WP_Error */
	public static function invite( $user_id ) {
		$u = get_userdata( (int) $user_id );
		if ( ! $u || ! user_can( $u, 'bizydash_portal' ) ) {
			return new \WP_Error( 'bd_invite', 'That person does not have a client login.', array( 'status' => 400 ) );
		}
		$name = (string) Settings::get( 'business_name' );
		$name = $name ? $name : wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$key  = get_password_reset_key( $u );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$link = add_query_arg( array( 'key' => $key, 'login' => rawurlencode( $u->user_login ) ), self::url() );
		$html = '<p>Hi ' . esc_html( $u->first_name ?: $u->display_name ) . ',</p><p>' . esc_html( $name ) . ' has set up a client portal for you. There you can follow your projects and tasks, see your invoices and what is coming up, and download signed contracts.</p><p><a href="' . esc_url( $link ) . '">Choose your password and sign in</a></p><p>Your username is <strong>' . esc_html( $u->user_login ) . '</strong>, or you can use your email address. This link works for a limited time; after that you can always use “Forgot password” on the sign-in page: ' . esc_url( self::url() ) . '</p>';
		$r = Services\Mailer::send( array( 'to' => array( $u->user_email ), 'subject' => 'Your ' . $name . ' client portal', 'html' => $html, 'text' => "Hi,\n\n{$name} has set up a client portal for you.\nChoose your password and sign in: {$link}\n" ) );
		if ( empty( $r['ok'] ) ) {
			return new \WP_Error( 'bd_mail', $r['error'] ?? 'The email could not be sent.', array( 'status' => 500 ) );
		}
		update_user_meta( $u->ID, 'bizydash_portal_invited', time() );
		return true;
	}
}
