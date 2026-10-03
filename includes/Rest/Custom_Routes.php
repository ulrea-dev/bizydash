<?php
namespace BizyDash\Rest;

use BizyDash\Database\Schema;
use BizyDash\Lookups;
use BizyDash\Resources;
use BizyDash\Services\Activity;
use BizyDash\Services\Billing;
use BizyDash\Services\BillingQueue;
use BizyDash\Services\BillSummary;
use BizyDash\Services\Subscriptions;
use BizyDash\Services\Ledger;
use BizyDash\Services\Dashboard;
use BizyDash\Services\Invoice_Mail;
use BizyDash\Services\Invoices;
use BizyDash\Services\Email_Templates;
use BizyDash\Services\Mailer;
use BizyDash\Mail_Settings;
use BizyDash\Services\Renewals;
use BizyDash\Settings;

defined( 'ABSPATH' ) || exit;

class Custom_Routes {

	const NS = 'bizydash/v1';

	/** Registers a route only when the module it belongs to is switched on. */
	private static function reg( $route, $args ) {
		static $map = array(
			'/contracts' => 'contracts', '/sign/' => 'contracts',
			'/invoices' => 'finance', '/expenses' => 'finance', '/payroll' => 'finance', '/payouts' => 'finance', '/finance' => 'finance', '/billing' => 'finance',
			'/client-services' => 'finance', '/renewals' => 'finance', '/charges' => 'finance', '/work/' => 'work',
		);
		$module = 'core';
		if ( preg_match( '#^/clients/\(\?P<id>\\d+\)/(unbilled|ledger)$#', $route ) ) {
			$module = 'finance';
		} else {
			foreach ( $map as $prefix => $m ) {
				if ( 0 === strpos( $route, $prefix ) ) {
					$module = $m;
					break;
				}
			}
		}
		if ( preg_match( '#^/(clients|team)/analytics$#', $route, $core ) ) {
			if ( ! \BizyDash\Modules::analytics_on( $core[1] ) ) {
				return; // this Analytics page is switched off
			}
		} elseif ( preg_match( '#/analytics$#', $route ) && ! \BizyDash\Modules::analytics_on( $module ) ) {
			return; // the module's Analytics page is switched off
		}
		if ( \BizyDash\Modules::enabled( $module ) ) {
			register_rest_route( self::NS, $route, $args );
		}
	}

	public static function register() {
		$perm = array( Resource_Controller::class, 'can' );

		self::reg( '/dashboard', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'dashboard' ), 'permission_callback' => $perm ) );
		self::reg( '/meta', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'meta' ), 'permission_callback' => $perm ) );
		self::reg( '/invoices/(?P<id>\d+)/share', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'invoice_share' ), 'permission_callback' => $perm ) );
		self::reg( '/invoices/(?P<id>\d+)/mark-sent', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'invoice_mark_sent' ), 'permission_callback' => $perm ) );
		self::reg( '/clients/(?P<id>\d+)/unbilled', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'client_unbilled' ), 'permission_callback' => $perm ) );
		self::reg( '/invoices/(?P<id>\d+)/email-defaults', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'email_defaults' ), 'permission_callback' => $perm ) );
		self::reg( '/invoices/(?P<id>\d+)/email-preview', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'email_preview' ), 'permission_callback' => $perm ) );
		self::reg( '/invoices/(?P<id>\d+)/send', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'email_send' ), 'permission_callback' => $perm ) );
		self::reg( '/invoices/(?P<id>\d+)/emails', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'email_history' ), 'permission_callback' => $perm ) );
		self::reg( '/contracts/(?P<id>\d+)/send', array( 'methods' => 'POST', 'callback' => function ( $r ) { $x = \BizyDash\Services\Contracts::send( (int) $r['id'] ); return is_wp_error( $x ) ? $x : rest_ensure_response( $x ); }, 'permission_callback' => $perm ) );
		self::reg( '/contracts/(?P<id>\d+)/remind', array( 'methods' => 'POST', 'callback' => function ( $r ) { $x = \BizyDash\Services\Contracts::remind( (int) $r['id'] ); return is_wp_error( $x ) ? $x : rest_ensure_response( $x ); }, 'permission_callback' => $perm ) );
		self::reg( '/contracts/(?P<id>\d+)/void', array( 'methods' => 'POST', 'callback' => function ( $r ) { $p = (array) $r->get_json_params(); $x = \BizyDash\Services\Contracts::void( (int) $r['id'], isset( $p['reason'] ) ? (string) $p['reason'] : '' ); return is_wp_error( $x ) ? $x : rest_ensure_response( array( 'ok' => true ) ); }, 'permission_callback' => $perm ) );
		self::reg( '/contracts/(?P<id>\d+)/audit', array( 'methods' => 'GET', 'callback' => function ( $r ) { $id = (int) $r['id']; return rest_ensure_response( array( 'events' => \BizyDash\Services\Contracts::events( $id ), 'otps' => \BizyDash\Services\Contracts::otps( $id ), 'chain' => \BizyDash\Services\Contracts::verify_chain( $id ), 'integrity' => \BizyDash\Services\Contracts::integrity( $id ), 'verify_url' => \BizyDash\Services\Contracts::verify_url( \BizyDash\Services\Contracts::cert_id( $id ) ), 'certificate_url' => home_url( '/?bd_certificate=' . $id ) ) ); }, 'permission_callback' => $perm ) );
		self::reg( '/contracts/tokens', array( 'methods' => 'GET', 'callback' => function () { return rest_ensure_response( \BizyDash\Services\Contracts::tokens_help() ); }, 'permission_callback' => $perm ) );
		self::reg( '/contracts/(?P<id>\d+)/pdf', array( 'methods' => 'POST', 'callback' => function ( $r ) { $x = \BizyDash\Services\Contract_Pdf::generate( (int) $r['id'] ); return is_wp_error( $x ) ? $x : rest_ensure_response( array( 'sha256' => $x['sha256'], 'size' => $x['size'] ) ); }, 'permission_callback' => $perm ) );
		self::reg( '/contracts/(?P<id>\d+)/preview', array( 'methods' => 'POST', 'callback' => function ( $r ) { $id = (int) $r['id']; if ( ! \BizyDash\Services\Contracts::get( $id ) ) { return new \WP_Error( 'bizydash_not_found', 'Not found.', array( 'status' => 404 ) ); } $p = (array) $r->get_json_params(); if ( isset( $p['access'] ) ) { \BizyDash\Services\Contracts::set_view_access( $id, (string) $p['access'] ); } $slug = \BizyDash\Services\Contracts::ensure_preview( $id, ! empty( $p['regenerate'] ) ); $c = \BizyDash\Services\Contracts::get( $id ); return rest_ensure_response( array( 'url' => \BizyDash\Services\Contracts::preview_url( $slug ), 'access' => $c['view_access'] ) ); }, 'permission_callback' => $perm ) );
		self::reg( '/contracts/import', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'contract_import' ), 'permission_callback' => $perm ) );
		$open = '__return_true'; // signers are not logged in; the unguessable link plus an emailed one-time code authorise them.
		self::reg( '/sign/(?P<token>[a-z0-9]{10,64})/otp', array( 'methods' => 'POST', 'callback' => function ( $r ) { $x = \BizyDash\Services\Contracts::request_otp( $r['token'], (array) $r->get_json_params() ); return is_wp_error( $x ) ? $x : rest_ensure_response( $x ); }, 'permission_callback' => $open ) );
		self::reg( '/sign/(?P<token>[a-z0-9]{10,64})/submit', array( 'methods' => 'POST', 'callback' => function ( $r ) { $x = \BizyDash\Services\Contracts::sign( $r['token'], (array) $r->get_json_params() ); return is_wp_error( $x ) ? $x : rest_ensure_response( $x ); }, 'permission_callback' => $open ) );
		self::reg( '/sign/(?P<token>[a-z0-9]{10,64})/decline', array( 'methods' => 'POST', 'callback' => function ( $r ) { $p = (array) $r->get_json_params(); $x = \BizyDash\Services\Contracts::decline( $r['token'], isset( $p['reason'] ) ? (string) $p['reason'] : '' ); return is_wp_error( $x ) ? $x : rest_ensure_response( array( 'ok' => true ) ); }, 'permission_callback' => $open ) );
		$fin = array( Resource_Controller::class, 'can_finance' );
		self::reg( '/expenses/summary', array( 'methods' => 'GET', 'callback' => function () { return rest_ensure_response( \BizyDash\Services\Expenses::summary() ); }, 'permission_callback' => $fin ) );
		self::reg( '/expenses/post-due', array( 'methods' => 'POST', 'callback' => function () { return rest_ensure_response( array( 'posted' => \BizyDash\Services\Expenses::post_due() ) ); }, 'permission_callback' => $fin ) );
		self::reg( '/payroll', array( 'methods' => 'GET', 'callback' => function ( \WP_REST_Request $req ) {
			return rest_ensure_response( \BizyDash\Services\Payroll::overview( (string) $req->get_param( 'period' ) ) );
		}, 'permission_callback' => $fin ) );
		self::reg( '/payroll/run', array( 'methods' => 'POST', 'callback' => function ( \WP_REST_Request $req ) {
			$p = (array) $req->get_json_params();
			return rest_ensure_response( \BizyDash\Services\Payroll::run( isset( $p['period'] ) ? $p['period'] : '' ) );
		}, 'permission_callback' => $fin ) );
		self::reg( '/payroll/pay', array( 'methods' => 'POST', 'callback' => function ( \WP_REST_Request $req ) {
			$p = (array) $req->get_json_params();
			return rest_ensure_response( \BizyDash\Services\Payroll::pay_members( isset( $p['member_ids'] ) ? (array) $p['member_ids'] : array(), isset( $p['paid_on'] ) ? $p['paid_on'] : '', isset( $p['method'] ) ? $p['method'] : '', isset( $p['reference'] ) ? $p['reference'] : '' ) );
		}, 'permission_callback' => $fin ) );
		self::reg( '/finance/overview', array( 'methods' => 'GET', 'callback' => function ( \WP_REST_Request $req ) {
			return rest_ensure_response( \BizyDash\Services\Profit::overview( $req->get_param( 'from' ), $req->get_param( 'to' ), (string) $req->get_param( 'income_basis' ), (string) $req->get_param( 'pay_basis' ), $req->get_param( 'department_id' ) ) );
		}, 'permission_callback' => $fin ) );
		self::reg( '/finance/analytics', array( 'methods' => 'GET', 'callback' => function ( \WP_REST_Request $req ) {
			return rest_ensure_response( \BizyDash\Services\FinanceAnalytics::query( array(
				'from'          => (string) $req->get_param( 'from' ),
				'to'            => (string) $req->get_param( 'to' ),
				'department_id' => (string) $req->get_param( 'department_id' ),
				'currency'      => strtoupper( sanitize_text_field( (string) $req->get_param( 'currency' ) ) ) ?: 'all',
				'income_basis'  => (string) $req->get_param( 'income_basis' ),
				'pay_basis'     => (string) $req->get_param( 'pay_basis' ),
			) ) );
		}, 'permission_callback' => $fin ) );
		self::reg( '/finance/export', array( 'methods' => 'GET', 'callback' => function ( \WP_REST_Request $req ) {
			$type = in_array( $req->get_param( 'type' ), array( 'income', 'expenses', 'team' ), true ) ? $req->get_param( 'type' ) : 'income';
			$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $req->get_param( 'from' ) ) ? $req->get_param( 'from' ) : gmdate( 'Y-01-01' );
			$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $req->get_param( 'to' ) ) ? $req->get_param( 'to' ) : gmdate( 'Y-12-31' );
			$dep = (string) $req->get_param( 'department_id' );
			$cur = strtoupper( sanitize_text_field( (string) $req->get_param( 'currency' ) ) );
			return rest_ensure_response( \BizyDash\Services\Profit::export( $type, $from, $to, 'none' === $dep ? 0 : absint( $dep ), $cur ) );
		}, 'permission_callback' => $fin ) );
		self::reg( '/payouts/create', array( 'methods' => 'POST', 'callback' => function ( \WP_REST_Request $req ) {
			$p   = (array) $req->get_json_params();
			$res = \BizyDash\Services\Pay::create_payout( isset( $p['team_member_id'] ) ? $p['team_member_id'] : 0, isset( $p['item_ids'] ) ? (array) $p['item_ids'] : array(), isset( $p['paid_on'] ) ? $p['paid_on'] : '', isset( $p['method'] ) ? $p['method'] : '', isset( $p['reference'] ) ? $p['reference'] : '', isset( $p['notes'] ) ? $p['notes'] : '' );
			return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
		}, 'permission_callback' => $fin ) );
		self::reg( '/payouts/(?P<id>\d+)/void', array( 'methods' => 'POST', 'callback' => function ( \WP_REST_Request $req ) {
			$res = \BizyDash\Services\Pay::void_payout( (int) $req['id'] );
			return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
		}, 'permission_callback' => $fin ) );
		self::reg( '/mail-settings', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'mail_get' ), 'permission_callback' => $perm ),
			array( 'methods' => 'POST,PUT', 'callback' => array( __CLASS__, 'mail_save' ), 'permission_callback' => $perm ),
		) );
		self::reg( '/mail-settings/test', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'mail_test' ), 'permission_callback' => $perm ) );
		self::reg( '/billing/summary', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'billing_summary' ), 'permission_callback' => $perm ) );
		self::reg( '/billing/queue', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'billing_queue' ), 'permission_callback' => $perm ) );
		self::reg( '/clients/(?P<id>\d+)/ledger', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'client_ledger' ), 'permission_callback' => $perm ) );
		self::reg( '/client-services/(?P<id>\d+)/ledger', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'service_ledger' ), 'permission_callback' => $perm ) );
		self::reg( '/search', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'search' ), 'permission_callback' => $perm ) );
		self::reg( '/billing/list', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'billing_list' ), 'permission_callback' => $perm ) );
		self::reg( '/currencies/usage', array( 'methods' => 'GET', 'callback' => function () { return rest_ensure_response( \BizyDash\Services\Currency::usage() ); }, 'permission_callback' => $perm ) );
		self::reg( '/modules', array(
			array( 'methods' => 'GET', 'callback' => function () { return rest_ensure_response( \BizyDash\Modules::catalog() ); }, 'permission_callback' => $perm ),
			array( 'methods' => 'POST,PUT', 'callback' => array( __CLASS__, 'modules_save' ), 'permission_callback' => function () { return current_user_can( 'manage_options' ); } ),
		) );
		self::reg( '/departments/overview', array( 'methods' => 'GET', 'callback' => function ( \WP_REST_Request $req ) {
			return rest_ensure_response( \BizyDash\Services\DepartmentOverview::all( array( 'from' => (string) $req->get_param( 'from' ), 'to' => (string) $req->get_param( 'to' ) ) ) );
		}, 'permission_callback' => $perm ) );
		self::reg( '/departments/(?P<id>\d+)/overview', array( 'methods' => 'GET', 'callback' => function ( \WP_REST_Request $req ) {
			$r = \BizyDash\Services\DepartmentOverview::one( (int) $req['id'], array( 'from' => (string) $req->get_param( 'from' ), 'to' => (string) $req->get_param( 'to' ) ) );
			return $r ? rest_ensure_response( $r ) : new \WP_Error( 'bizydash_not_found', 'Department not found.', array( 'status' => 404 ) );
		}, 'permission_callback' => $perm ) );
		self::reg( '/bulk/department', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'bulk_department' ), 'permission_callback' => $perm ) );
		self::reg( '/clients/analytics', array( 'methods' => 'GET', 'callback' => function ( \WP_REST_Request $req ) {
			return rest_ensure_response( \BizyDash\Services\ClientAnalytics::query( array( 'from' => (string) $req->get_param( 'from' ), 'to' => (string) $req->get_param( 'to' ), 'department_id' => (string) $req->get_param( 'department_id' ) ) ) );
		}, 'permission_callback' => $perm ) );
		self::reg( '/team/analytics', array( 'methods' => 'GET', 'callback' => function ( \WP_REST_Request $req ) {
			return rest_ensure_response( \BizyDash\Services\TeamAnalytics::query( array( 'from' => (string) $req->get_param( 'from' ), 'to' => (string) $req->get_param( 'to' ), 'department_id' => (string) $req->get_param( 'department_id' ) ) ) );
		}, 'permission_callback' => $perm ) );
		self::reg( '/work/analytics', array( 'methods' => 'GET', 'callback' => function ( \WP_REST_Request $req ) {
			return rest_ensure_response( \BizyDash\Services\WorkAnalytics::query( array(
				'from'          => (string) $req->get_param( 'from' ),
				'to'            => (string) $req->get_param( 'to' ),
				'department_id' => (string) $req->get_param( 'department_id' ),
				'assignee_id'   => absint( $req->get_param( 'assignee_id' ) ),
				'client_id'     => absint( $req->get_param( 'client_id' ) ),
			) ) );
		}, 'permission_callback' => $perm ) );
		self::reg( '/work/overview', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'work_overview' ), 'permission_callback' => $perm ) );
		self::reg( '/contracts/analytics', array( 'methods' => 'GET', 'callback' => function ( \WP_REST_Request $req ) {
			return rest_ensure_response( \BizyDash\Services\ContractAnalytics::query( array( 'from' => (string) $req->get_param( 'from' ), 'to' => (string) $req->get_param( 'to' ), 'department_id' => (string) $req->get_param( 'department_id' ) ) ) );
		}, 'permission_callback' => $perm ) );
		self::reg( '/contracts/overview', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'contracts_overview' ), 'permission_callback' => $perm ) );
		self::reg( '/dashboard/chart', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'chart' ), 'permission_callback' => $perm ) );
		self::reg( '/preferences',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'prefs_get' ), 'permission_callback' => $perm ),
				array( 'methods' => 'POST,PUT', 'callback' => array( __CLASS__, 'prefs_save' ), 'permission_callback' => $perm ),
			)
		);
		self::reg( '/settings',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'settings_get' ), 'permission_callback' => $perm ),
				array( 'methods' => 'POST,PUT', 'callback' => array( __CLASS__, 'settings_save' ), 'permission_callback' => $perm ),
			)
		);
		self::reg( '/renewals/(?P<id>\d+)/mark-renewed', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'mark_renewed' ), 'permission_callback' => $perm ) );
		self::reg( '/charges/(?P<id>\d+)/waive', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'waive_charge' ), 'permission_callback' => $perm ) );
		self::reg( '/invoices/from-sources', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'invoice_from_sources' ), 'permission_callback' => $perm ) );
	}

	/** Put several records in one department (or take them out of any). Each goes through its own resource rules. */
	public static function bulk_department( \WP_REST_Request $req ) {
		global $wpdb;
		$p     = (array) $req->get_json_params();
		$dept  = isset( $p['department_id'] ) ? absint( $p['department_id'] ) : 0;
		$items = isset( $p['items'] ) && is_array( $p['items'] ) ? array_slice( $p['items'], 0, 300 ) : array();
		if ( ! $items ) {
			return new \WP_Error( 'bizydash_invalid', 'Nothing selected.', array( 'status' => 400 ) );
		}
		if ( $dept && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'brands' ) . ' WHERE id=%d', $dept ) ) ) { // phpcs:ignore WordPress.DB
			return new \WP_Error( 'bizydash_invalid', 'That department does not exist.', array( 'status' => 400 ) );
		}
		$mode    = isset( $p['mode'] ) ? sanitize_key( $p['mode'] ) : ( $dept ? 'set' : 'clear' ); // clients can hold several departments: add | remove | set | clear
		if ( ! in_array( $mode, array( 'add', 'remove', 'set', 'clear' ), true ) ) {
			$mode = $dept ? 'set' : 'clear';
		}
		if ( 'clear' !== $mode && ! $dept ) {
			return new \WP_Error( 'bizydash_invalid', 'Choose a department.', array( 'status' => 400 ) );
		}
		$allowed = array( 'invoices', 'charges', 'client-services', 'contracts', 'contract-templates', 'tasks', 'projects', 'project-phases', 'expenses', 'recurring-expenses', 'team', 'services', 'clients' );
		$enabled = Resources::names();
		$done    = 0;
		$failed  = array();
		foreach ( $items as $it ) {
			$res = isset( $it['resource'] ) ? sanitize_key( $it['resource'] ) : '';
			$id  = isset( $it['id'] ) ? absint( $it['id'] ) : 0;
			if ( ! in_array( $res, $allowed, true ) || ! in_array( $res, $enabled, true ) || ! $id ) {
				$failed[] = array( 'resource' => $res, 'id' => $id, 'error' => 'Not available.' );
				continue;
			}
			$cfg = Resources::all();
			if ( ! empty( $cfg[ $res ]['finance'] ) && ! current_user_can( 'bizydash_finance' ) ) {
				$failed[] = array( 'resource' => $res, 'id' => $id, 'error' => 'You do not have access to finance records.' );
				continue;
			}
			$r = new \WP_REST_Request( 'PUT', '/bizydash/v1/' . $res . '/' . $id );
			$r->set_param( 'id', $id );
			if ( 'clients' === $res ) {
				// A client can belong to several departments, so work from the ones it has now.
				$cur = $wpdb->get_results( $wpdb->prepare( 'SELECT brand_id, is_primary FROM ' . Schema::table( 'client_brands' ) . ' WHERE client_id=%d ORDER BY is_primary DESC, id ASC', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
				$ids = array_map( 'intval', wp_list_pluck( $cur, 'brand_id' ) );
				$primary = $cur && (int) $cur[0]['is_primary'] ? (int) $cur[0]['brand_id'] : 0;
				if ( 'add' === $mode ) {
					$ids = in_array( $dept, $ids, true ) ? $ids : array_merge( $ids, array( $dept ) );
				} elseif ( 'remove' === $mode ) {
					$ids = array_values( array_diff( $ids, array( $dept ) ) );
				} elseif ( 'set' === $mode ) {
					$ids = array( $dept );
				} else {
					$ids = array();
				}
				$r->set_param( 'brand_ids', $ids );
				$r->set_param( 'primary_brand_id', in_array( $primary, $ids, true ) ? $primary : 0 );
			} else {
				$r->set_param( 'department_id', 'clear' === $mode ? 0 : $dept );
			}
			$ans = ( new Resource_Controller( $res ) )->update( $r );
			if ( is_wp_error( $ans ) ) {
				$failed[] = array( 'resource' => $res, 'id' => $id, 'error' => $ans->get_error_message() );
			} else {
				++$done;
			}
		}
		Activity::log( 'departments', $dept, 0, 'assigned', sprintf( '%d record%s %s', $done, 1 === $done ? '' : 's', $dept ? 'moved to a department' : 'taken out of their department' ) );
		return rest_ensure_response( array( 'updated' => $done, 'failed' => $failed ) );
	}

	/** Billing: everything we charge (retainers, subscriptions, one-off) in one list. */
	public static function billing_list( \WP_REST_Request $req ) {
		return rest_ensure_response(
			\BizyDash\Services\BillingList::query(
				array(
					'type'      => sanitize_key( (string) $req->get_param( 'type' ) ),
					'status'    => sanitize_key( (string) $req->get_param( 'status' ) ),
					'client_id' => absint( $req->get_param( 'client_id' ) ),
					'department_id' => absint( $req->get_param( 'department_id' ) ),
					'currency'  => sanitize_text_field( (string) $req->get_param( 'currency' ) ),
					'q'         => sanitize_text_field( (string) $req->get_param( 'q' ) ),
					'page'      => absint( $req->get_param( 'page' ) ),
					'per_page'  => absint( $req->get_param( 'per_page' ) ),
				)
			)
		);
	}

	public static function modules_save( \WP_REST_Request $req ) {
		\BizyDash\Modules::save( (array) $req->get_json_params() );
		return rest_ensure_response( \BizyDash\Modules::catalog() );
	}

	/** Work → Overview: what is due, who is busy, which projects are moving. */
	public static function work_overview() {
		global $wpdb;
		$tk    = Schema::table( 'tasks' );
		$pj    = Schema::table( 'projects' );
		$tm    = Schema::table( 'team_members' );
		$cl    = Schema::table( 'clients' );
		$today = Billing::today();
		$week  = gmdate( 'Y-m-d', strtotime( $today . ' +7 days' ) );
		$me    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tm WHERE wp_user_id=%d LIMIT 1", get_current_user_id() ) ); // phpcs:ignore WordPress.DB
		$cnt   = function ( $sql, $args = array() ) use ( $wpdb ) {
			return (int) $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore WordPress.DB
		};
		$stats = array(
			'open'        => $cnt( "SELECT COUNT(*) FROM $tk WHERE status <> 'done'" ),
			'overdue'     => $cnt( "SELECT COUNT(*) FROM $tk WHERE status <> 'done' AND due_date IS NOT NULL AND due_date < %s", array( $today ) ),
			'due_week'    => $cnt( "SELECT COUNT(*) FROM $tk WHERE status <> 'done' AND due_date BETWEEN %s AND %s", array( $today, $week ) ),
			'done_week'   => $cnt( "SELECT COUNT(*) FROM $tk WHERE status = 'done' AND updated_at >= %s", array( gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days' ) ) ) ),
			'mine'        => $me ? $cnt( "SELECT COUNT(*) FROM $tk WHERE status <> 'done' AND assignee_id=%d", array( $me ) ) : 0,
			'projects'    => $cnt( "SELECT COUNT(*) FROM $pj WHERE status IN ('active','planned')" ),
			'projects_late' => $cnt( "SELECT COUNT(*) FROM $pj WHERE status IN ('active','planned') AND due_date IS NOT NULL AND due_date < %s", array( $today ) ),
		);
		$sel = "t.id, t.title, t.due_date, t.priority, t.status, t.project_id, c.company_name AS client_name, a.name AS assignee_name";
		$jn  = "FROM $tk t LEFT JOIN $cl c ON c.id=t.client_id LEFT JOIN $tm a ON a.id=t.assignee_id";
		$overdue = $wpdb->get_results( $wpdb->prepare( "SELECT $sel $jn WHERE t.status <> 'done' AND t.due_date IS NOT NULL AND t.due_date < %s ORDER BY t.due_date ASC LIMIT 8", $today ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$soon    = $wpdb->get_results( $wpdb->prepare( "SELECT $sel $jn WHERE t.status <> 'done' AND t.due_date BETWEEN %s AND %s ORDER BY t.due_date ASC LIMIT 8", $today, $week ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$mine    = $me ? $wpdb->get_results( $wpdb->prepare( "SELECT $sel $jn WHERE t.status <> 'done' AND t.assignee_id=%d ORDER BY t.due_date IS NULL, t.due_date ASC LIMIT 8", $me ), ARRAY_A ) : array(); // phpcs:ignore WordPress.DB
		$projects = $wpdb->get_results( "SELECT p.id, p.name, p.due_date, p.status, c.company_name AS client_name, (SELECT COUNT(*) FROM $tk x WHERE x.project_id=p.id) AS total, (SELECT COUNT(*) FROM $tk y WHERE y.project_id=p.id AND y.status='done') AS done FROM $pj p LEFT JOIN $cl c ON c.id=p.client_id WHERE p.status IN ('active','planned') ORDER BY p.due_date IS NULL, p.due_date ASC LIMIT 6", ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $projects as &$p ) {
			$p['total']    = (int) $p['total'];
			$p['done']     = (int) $p['done'];
			$p['progress'] = $p['total'] ? (int) round( 100 * $p['done'] / $p['total'] ) : 0;
			$p['is_overdue'] = ! empty( $p['due_date'] ) && $p['due_date'] < $today;
		}
		$load = $wpdb->get_results( "SELECT a.id, a.name, COUNT(t.id) AS open_tasks, SUM(t.due_date IS NOT NULL AND t.due_date < '$today') AS late FROM $tm a JOIN $tk t ON t.assignee_id=a.id AND t.status <> 'done' GROUP BY a.id, a.name ORDER BY open_tasks DESC LIMIT 6", ARRAY_A ); // phpcs:ignore WordPress.DB
		return rest_ensure_response( array( 'stats' => $stats, 'overdue' => $overdue, 'soon' => $soon, 'mine' => $mine, 'projects' => $projects, 'load' => $load, 'has_me' => (bool) $me ) );
	}

	/** Contracts → Overview: what is waiting on whom, what just got signed. */
	public static function contracts_overview() {
		global $wpdb;
		$ct = Schema::table( 'contracts' );
		$sg = Schema::table( 'contract_signers' );
		$cl = Schema::table( 'clients' );
		$by = array();
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) n FROM $ct GROUP BY status", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$by[ $r['status'] ] = (int) $r['n'];
		}
		$month = gmdate( 'Y-m-01 00:00:00' );
		$stats = array(
			'drafts'    => isset( $by['draft'] ) ? $by['draft'] : 0,
			'awaiting'  => ( isset( $by['sent'] ) ? $by['sent'] : 0 ) + ( isset( $by['partially_signed'] ) ? $by['partially_signed'] : 0 ),
			'signed'    => isset( $by['signed'] ) ? $by['signed'] : 0,
			'signed_month' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $ct WHERE status='signed' AND completed_at >= %s", $month ) ), // phpcs:ignore WordPress.DB
			'declined'  => ( isset( $by['declined'] ) ? $by['declined'] : 0 ) + ( isset( $by['void'] ) ? $by['void'] : 0 ),
			'total'     => array_sum( $by ),
		);
		$sel = "t.id, t.number, t.title, t.status, t.sent_at, t.completed_at, c.company_name AS client_name, (SELECT COUNT(*) FROM $sg s WHERE s.contract_id=t.id) AS signer_total, (SELECT COUNT(*) FROM $sg s2 WHERE s2.contract_id=t.id AND s2.status='signed') AS signer_done, (SELECT GROUP_CONCAT(s3.name SEPARATOR ', ') FROM $sg s3 WHERE s3.contract_id=t.id AND s3.status IN ('pending','viewed')) AS waiting_on";
		$fmt = function ( $rows ) {
			foreach ( $rows as &$r ) {
				$r['signer_total'] = (int) $r['signer_total'];
				$r['signer_done']  = (int) $r['signer_done'];
			}
			return $rows;
		};
		$awaiting = $fmt( (array) $wpdb->get_results( "SELECT $sel FROM $ct t LEFT JOIN $cl c ON c.id=t.client_id WHERE t.status IN ('sent','partially_signed') ORDER BY t.sent_at ASC LIMIT 8", ARRAY_A ) ); // phpcs:ignore WordPress.DB
		$signed   = $fmt( (array) $wpdb->get_results( "SELECT $sel FROM $ct t LEFT JOIN $cl c ON c.id=t.client_id WHERE t.status='signed' ORDER BY t.completed_at DESC LIMIT 6", ARRAY_A ) ); // phpcs:ignore WordPress.DB
		$drafts   = $fmt( (array) $wpdb->get_results( "SELECT $sel FROM $ct t LEFT JOIN $cl c ON c.id=t.client_id WHERE t.status='draft' ORDER BY t.id DESC LIMIT 6", ARRAY_A ) ); // phpcs:ignore WordPress.DB
		return rest_ensure_response( array( 'stats' => $stats, 'awaiting' => $awaiting, 'signed' => $signed, 'drafts' => $drafts ) );
	}

	public static function meta_payload() {
		return array(
			'settings' => Settings::get(),
			'lookups'  => Lookups::grouped(),
			'enums'    => array(
				'charge_statuses'  => Resources::CHARGE_STATUSES,
				'renewal_statuses' => Resources::RENEWAL_STATUSES,
				'invoice_statuses' => Resources::INVOICE_STATUSES,
				'timings'          => Resources::TIMINGS,
			),
		);
	}

	public static function meta() {
		return rest_ensure_response( self::meta_payload() );
	}

	public static function dashboard( \WP_REST_Request $req ) {
		return rest_ensure_response(
			Dashboard::data(
				array(
					'client_type' => sanitize_text_field( (string) $req->get_param( 'client_type' ) ),
					'brand_id'    => absint( $req->get_param( 'brand_id' ) ),
					'brand_type'  => sanitize_text_field( (string) $req->get_param( 'brand_type' ) ),
					'due_soon'    => (bool) $req->get_param( 'due_soon' ),
					'overdue'     => (bool) $req->get_param( 'overdue' ),
					'unpaid'      => (bool) $req->get_param( 'unpaid' ),
				)
			)
		);
	}

	public static function email_defaults( \WP_REST_Request $req ) {
		$d = Invoice_Mail::defaults( (int) $req['id'] );
		return $d ? rest_ensure_response( $d ) : new \WP_Error( 'bizydash_not_found', 'Not found.', array( 'status' => 404 ) );
	}

	public static function email_preview( \WP_REST_Request $req ) {
		$p     = (array) $req->get_json_params();
		$to    = Invoice_Mail::clean_list( isset( $p['to'] ) ? $p['to'] : array() );
		$names = is_wp_error( $to ) ? array() : wp_list_pluck( $to, 'name' );
		$r     = Invoice_Mail::render( (int) $req['id'], isset( $p['subject'] ) ? (string) $p['subject'] : '', isset( $p['message'] ) ? (string) $p['message'] : '', $names );
		return $r ? rest_ensure_response( array( 'html' => $r['html'], 'subject' => $r['subject'] ) ) : new \WP_Error( 'bizydash_not_found', 'Not found.', array( 'status' => 404 ) );
	}

	public static function email_send( \WP_REST_Request $req ) {
		$p   = (array) $req->get_json_params();
		$res = Invoice_Mail::send( (int) $req['id'], $p, isset( $p['test_to'] ) ? sanitize_text_field( (string) $p['test_to'] ) : '' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['ok'] ) ) {
			return new \WP_Error( 'bizydash_mail_failed', $res['error'], array( 'status' => 502 ) );
		}
		return rest_ensure_response( $res );
	}

	public static function email_history( \WP_REST_Request $req ) {
		return rest_ensure_response( Invoice_Mail::history( (int) $req['id'] ) );
	}

	public static function mail_get() {
		return rest_ensure_response( Mail_Settings::get_public() );
	}

	public static function mail_save( \WP_REST_Request $req ) {
		$out = Mail_Settings::update( (array) $req->get_json_params() );
		Activity::log( 'settings', 0, 0, 'updated', 'Email settings updated' );
		return rest_ensure_response( $out );
	}

	public static function mail_test( \WP_REST_Request $req ) {
		$p  = (array) $req->get_json_params();
		$to = Invoice_Mail::clean_list( array( isset( $p['to'] ) ? $p['to'] : '' ) );
		if ( is_wp_error( $to ) ) {
			return $to;
		}
		$m   = Mail_Settings::get_full();
		$via = 'smtp' === $m['method'] ? 'BizyDash SMTP (' . $m['smtp_host'] . ':' . $m['smtp_port'] . ')' : 'the site mailer (wp_mail)';
		$msg = Email_Templates::smtp_test( Settings::get(), $via );
		$res = Mailer::send( array( 'to' => wp_list_pluck( $to, 'email' ), 'subject' => 'BizyDash test email', 'html' => $msg['html'], 'text' => $msg['text'] ) );
		if ( empty( $res['ok'] ) ) {
			return new \WP_Error( 'bizydash_mail_failed', $res['error'], array( 'status' => 502 ) );
		}
		Activity::log( 'settings', 0, 0, 'test_email', 'Email test sent to ' . $to[0]['email'] );
		return rest_ensure_response( array( 'ok' => true, 'via' => $via, 'to' => $to[0]['email'] ) );
	}

	public static function billing_summary( \WP_REST_Request $req ) {
		$kind = 'recurring' === $req->get_param( 'kind' ) ? 'recurring' : 'one_off';
		$cid  = absint( $req->get_param( 'client_id' ) );
		$out  = array( 'kind' => $kind, 'statuses' => BillSummary::for_kind( $kind, $cid ) );
		if ( 'recurring' === $kind ) {
			$out['mrr'] = Subscriptions::mrr_totals( null, $cid );
		}
		return rest_ensure_response( $out );
	}

	public static function billing_queue( \WP_REST_Request $req ) {
		$base = array(
			'client_id' => absint( $req->get_param( 'client_id' ) ),
			'type'      => sanitize_key( (string) $req->get_param( 'type' ) ),
			'currency'  => sanitize_text_field( (string) $req->get_param( 'currency' ) ),
			'search'    => sanitize_text_field( (string) $req->get_param( 'search' ) ),
		);
		$all     = BillingQueue::items( $base );
		$summary = BillingQueue::summarise( $all );

		$states = array_filter( array_map( 'trim', explode( ',', (string) $req->get_param( 'state' ) ) ) );
		$rows   = $states ? array_values( array_filter( $all, function ( $i ) use ( $states ) {
			return in_array( $i['state'], $states, true );
		} ) ) : $all;

		$per   = max( 1, min( 200, (int) ( $req->get_param( 'per_page' ) ? $req->get_param( 'per_page' ) : 25 ) ) );
		$page  = max( 1, (int) ( $req->get_param( 'page' ) ? $req->get_param( 'page' ) : 1 ) );
		$total = count( $rows );

		return rest_ensure_response(
			array(
				'items'   => array_slice( $rows, ( $page - 1 ) * $per, $per ),
				'total'   => $total,
				'pages'   => (int) ceil( $total / $per ),
				'summary' => $summary,
			)
		);
	}

	public static function client_unbilled( \WP_REST_Request $req ) {
		return rest_ensure_response( BillingQueue::items( array( 'client_id' => (int) $req['id'] ) ) );
	}

	/** Word file upload or Google Docs link → clean contract HTML. */
	public static function contract_import( \WP_REST_Request $req ) {
		$files = $req->get_file_params();
		$res   = null;
		if ( ! empty( $files['file']['tmp_name'] ) && is_uploaded_file( $files['file']['tmp_name'] ) ) {
			$name = isset( $files['file']['name'] ) ? sanitize_file_name( $files['file']['name'] ) : 'document.docx';
			if ( ! preg_match( '/\.docx?$/i', $name ) ) {
				return new \WP_Error( 'bizydash_import', 'Please choose a Word file (.doc or .docx).', array( 'status' => 400 ) );
			}
			$res = \BizyDash\Services\Docx_Import::from_file( $files['file']['tmp_name'], trim( str_replace( array( '-', '_' ), ' ', preg_replace( '/\.docx?$/i', '', $name ) ) ) );
		} elseif ( $req->get_param( 'url' ) ) {
			$res = \BizyDash\Services\Docx_Import::from_google( (string) $req->get_param( 'url' ) );
		} else {
			return new \WP_Error( 'bizydash_import', 'Choose a Word file or paste a Google Docs link.', array( 'status' => 400 ) );
		}
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public static function invoice_share( \WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! Resources::repo( 'invoices' )->find( $id ) ) {
			return new \WP_Error( 'bizydash_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		$p     = (array) $req->get_json_params();
		if ( isset( $p['slug'] ) && '' !== trim( (string) $p['slug'] ) ) {
			$set = Invoices::set_slug( $id, (string) $p['slug'] );
			if ( is_wp_error( $set ) ) {
				return $set;
			}
			Activity::log( 'invoices', $id, 0, 'link', 'Invoice link changed to ' . $set );
			return rest_ensure_response( array( 'url' => Invoices::public_url( $set ), 'slug' => $set ) );
		}
		$token = Invoices::ensure_token( $id, ! empty( $p['regenerate'] ) );
		if ( ! empty( $p['regenerate'] ) ) {
			Activity::log( 'invoices', $id, 0, 'link', 'Invoice share link regenerated' );
		}
		return rest_ensure_response( array( 'url' => Invoices::public_url( $token ), 'slug' => $token ) );
	}

	public static function invoice_mark_sent( \WP_REST_Request $req ) {
		$row = Invoices::mark_sent( (int) $req['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'bizydash_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		$rows = Resources::repo( 'invoices' )->find( (int) $req['id'] );
		return rest_ensure_response( array( 'status' => $rows['status'], 'url' => Invoices::public_url( Invoices::ensure_token( (int) $req['id'] ) ) ) );
	}

	public static function client_ledger( \WP_REST_Request $req ) {
		return rest_ensure_response( Ledger::for_client( (int) $req['id'] ) );
	}

	public static function service_ledger( \WP_REST_Request $req ) {
		return rest_ensure_response( Ledger::for_service( (int) $req['id'] ) );
	}

	public static function search( \WP_REST_Request $req ) {
		return rest_ensure_response( Dashboard::search( (string) $req->get_param( 'q' ) ) );
	}

	public static function chart( \WP_REST_Request $req ) {
		return rest_ensure_response( Dashboard::chart( (int) ( $req->get_param( 'months' ) ? $req->get_param( 'months' ) : 6 ) ) );
	}

	public static function theme() {
		$t = get_user_meta( get_current_user_id(), 'bizydash_theme', true );
		return in_array( $t, array( 'dark', 'auto' ), true ) ? $t : 'light'; // 'auto' follows the device's light/dark setting
	}

	public static function prefs_get() {
		return rest_ensure_response( array( 'theme' => self::theme() ) );
	}

	public static function prefs_save( \WP_REST_Request $req ) {
		$p = (array) $req->get_json_params();
		if ( isset( $p['theme'] ) ) {
			update_user_meta( get_current_user_id(), 'bizydash_theme', in_array( $p['theme'], array( 'dark', 'auto' ), true ) ? $p['theme'] : 'light' );
		}
		return rest_ensure_response( array( 'theme' => self::theme() ) );
	}

	public static function settings_get() {
		return rest_ensure_response( Settings::get() );
	}

	public static function settings_save( \WP_REST_Request $req ) {
		$out = Settings::update( (array) $req->get_json_params() );
		Activity::log( 'settings', 0, 0, 'updated', 'Settings updated' );
		return rest_ensure_response( $out );
	}

	public static function mark_renewed( \WP_REST_Request $req ) {
		$repo = Resources::repo( 'renewals' );
		$r    = $repo->find( (int) $req['id'] );
		if ( ! $r ) {
			return new \WP_Error( 'bizydash_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		if ( ! in_array( $r['status'], array( 'upcoming', 'due' ), true ) ) {
			return new \WP_Error( 'bizydash_invalid', 'Only open renewals can be marked renewed.', array( 'status' => 400 ) );
		}
		$next = Renewals::mark_renewed( $r );
		Activity::log( 'renewals', $r['id'], $r['client_id'], 'renewed', sprintf( 'Renewal marked renewed: %s — %s (%s)', $r['service_name'], $r['client_name'], $r['due_date'] ) . ( $next ? ' · next due ' . $next['due_date'] : '' ) );

		$rows = Renewals::decorate( array( $repo->find( $r['id'] ) ) );
		return rest_ensure_response( array( 'renewal' => $rows[0], 'next' => $next ) );
	}

	public static function waive_charge( \WP_REST_Request $req ) {
		$repo = Resources::repo( 'charges' );
		$c    = $repo->find( (int) $req['id'] );
		if ( ! $c ) {
			return new \WP_Error( 'bizydash_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		if ( 'pending' !== $c['status'] ) {
			return new \WP_Error( 'bizydash_invalid', 'Only pending charges can be waived.', array( 'status' => 400 ) );
		}
		$repo->update( $c['id'], array( 'status' => 'waived' ) );
		Activity::log( 'charges', $c['id'], $c['client_id'], 'waived', sprintf( 'Charge waived: %s — %s', $c['description'], $c['client_name'] ) );
		return rest_ensure_response( $repo->find( $c['id'] ) );
	}

	public static function invoice_from_sources( \WP_REST_Request $req ) {
		$p   = (array) $req->get_json_params();
		$inv = Invoices::create_from_sources(
			isset( $p['client_id'] ) ? (int) $p['client_id'] : 0,
			isset( $p['charge_ids'] ) ? (array) $p['charge_ids'] : array(),
			isset( $p['renewal_ids'] ) ? (array) $p['renewal_ids'] : array(),
			$p
		);
		if ( is_wp_error( $inv ) ) {
			return $inv;
		}
		$resp = rest_ensure_response( Resources::repo( 'invoices' )->find( (int) $inv['id'] ) );
		$resp->set_status( 201 );
		return $resp;
	}
}
