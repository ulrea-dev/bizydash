<?php
namespace BizyDash\Services;

use BizyDash\Database\Schema;
use BizyDash\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * One place to see everything that belongs to a department: its clients and people, work, contracts, billing,
 * money in and out. Core numbers are open to everyone; work, contracts and finance sections appear only when that
 * module is on, and money only for people with the finance permission.
 */
class DepartmentOverview {

	private static function names() {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT id, name, type, description FROM ' . Schema::table( 'brands' ) . ' ORDER BY name', ARRAY_A ) as $b ) { // phpcs:ignore WordPress.DB
			$out[ (int) $b['id'] ] = $b;
		}
		return $out;
	}

	private static function flags() {
		return array(
			'work'      => Modules::enabled( 'work' ),
			'contracts' => Modules::enabled( 'contracts' ),
			'finance'   => Modules::enabled( 'finance' ) && current_user_can( 'bizydash_finance' ),
			'billing'   => Modules::enabled( 'finance' ),
		);
	}

	private static function dates( array $f ) {
		$today = Billing::today();
		$to    = FinanceAnalytics::date_or( isset( $f['to'] ) ? $f['to'] : '', $today );
		$from  = FinanceAnalytics::date_or( isset( $f['from'] ) ? $f['from'] : '', gmdate( 'Y-m-01', strtotime( $to ) ) );
		return array( $from, $to );
	}

	/** Count rows by department id from a "SELECT dept, COUNT(*) n" query. */
	private static function counts( $sql ) {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ (int) $r['d'] ] = (int) $r['n'];
		}
		return $out;
	}

	/** Unpaid invoice balance per department (an invoice with no department is split across its lines' departments). */
	private static function receivables() {
		global $wpdb;
		$iv  = Schema::table( 'invoices' );
		$it  = Schema::table( 'invoice_items' );
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, department_id, currency, (amount - amount_paid) bal FROM $iv WHERE status IN ('sent','due','partially_paid','overdue')", ARRAY_A ) as $i ) { // phpcs:ignore WordPress.DB
			$bal = (float) $i['bal'];
			if ( $bal <= 0 ) {
				continue;
			}
			if ( (int) $i['department_id'] > 0 ) {
				$parts = array( (int) $i['department_id'] => 1.0 );
			} else {
				$lines = $wpdb->get_results( $wpdb->prepare( "SELECT department_id d, SUM(amount) a FROM $it WHERE invoice_id=%d GROUP BY department_id", (int) $i['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
				$sum   = array_sum( wp_list_pluck( $lines, 'a' ) );
				$parts = array();
				foreach ( $lines as $l ) {
					$parts[ (int) $l['d'] ] = $sum > 0 ? (float) $l['a'] / $sum : 1.0;
				}
				if ( ! $parts ) {
					$parts = array( 0 => 1.0 );
				}
			}
			foreach ( $parts as $d => $share ) {
				$out[ $d ][ $i['currency'] ] = ( isset( $out[ $d ][ $i['currency'] ] ) ? $out[ $d ][ $i['currency'] ] : 0 ) + $bal * $share;
			}
		}
		return $out;
	}

	/** Monthly recurring income per department. */
	private static function mrr() {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'client_services' ) . " WHERE status='active' AND billing_period NOT IN ('','none')", ARRAY_A ) as $cs ) { // phpcs:ignore WordPress.DB
			$v = Subscriptions::monthly_value( $cs );
			if ( $v > 0 ) {
				$out[ (int) $cs['department_id'] ][ $cs['currency'] ] = ( isset( $out[ (int) $cs['department_id'] ][ $cs['currency'] ] ) ? $out[ (int) $cs['department_id'] ][ $cs['currency'] ] : 0 ) + $v;
			}
		}
		return $out;
	}

	private static function round_map( $m ) {
		return array_map( function ( $v ) {
			return round( $v, 2 );
		}, (array) $m );
	}

	/** Every department side by side, with the numbers that matter most. */
	public static function all( array $f = array() ) {
		global $wpdb;
		list( $from, $to ) = self::dates( $f );
		$fl    = self::flags();
		$names = self::names();
		$names[0] = array( 'id' => 0, 'name' => 'No department', 'type' => '', 'description' => 'Things not attached to a department yet.' );
		$cb    = Schema::table( 'client_brands' );
		$cl    = Schema::table( 'clients' );

		$clients = self::counts( "SELECT brand_id d, COUNT(*) n FROM $cb GROUP BY brand_id" );
		$clients[0] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $cl c WHERE NOT EXISTS (SELECT 1 FROM $cb x WHERE x.client_id=c.id)" ); // phpcs:ignore WordPress.DB
		$people  = self::counts( 'SELECT department_id d, COUNT(*) n FROM ' . Schema::table( 'team_members' ) . " WHERE status='active' GROUP BY department_id" );
		$tasks = $projects = $contracts = array();
		if ( $fl['work'] ) {
			$tk = Schema::table( 'tasks' );
			$tasks    = self::counts( "SELECT COALESCE(NULLIF(t.department_id,0), NULLIF(ph.department_id,0), NULLIF(p.department_id,0), 0) d, COUNT(*) n FROM $tk t LEFT JOIN " . Schema::table( 'project_phases' ) . " ph ON ph.id=t.phase_id LEFT JOIN " . Schema::table( 'projects' ) . " p ON p.id=t.project_id WHERE t.status <> 'done' GROUP BY d" );
			$projects = self::counts( 'SELECT department_id d, COUNT(*) n FROM ' . Schema::table( 'projects' ) . " WHERE status IN ('active','planned') GROUP BY department_id" );
		}
		if ( $fl['contracts'] ) {
			$contracts = self::counts( 'SELECT department_id d, COUNT(*) n FROM ' . Schema::table( 'contracts' ) . " WHERE status IN ('sent','partially_signed') GROUP BY department_id" );
		}
		$money = $mrr = $recv = array();
		if ( $fl['finance'] ) {
			$inc  = Profit::income_rows( $from, $to, 'invoiced' );
			$team = Profit::team_rows( $from, $to, 'earned' );
			$exp  = Profit::expense_rows( $from, $to );
			foreach ( array_keys( $names ) as $id ) {
				$money[ $id ] = Profit::sum( $inc, $team, $exp, $from, $to, $id );
			}
			$mrr  = self::mrr();
			$recv = self::receivables();
		}

		$rows = array();
		foreach ( $names as $id => $b ) {
			$row = array(
				'id'          => $id,
				'name'        => $b['name'],
				'description' => $b['description'],
				'clients'     => isset( $clients[ $id ] ) ? $clients[ $id ] : 0,
				'people'      => isset( $people[ $id ] ) ? $people[ $id ] : 0,
			);
			if ( $fl['work'] ) {
				$row['open_tasks']      = isset( $tasks[ $id ] ) ? $tasks[ $id ] : 0;
				$row['active_projects'] = isset( $projects[ $id ] ) ? $projects[ $id ] : 0;
			}
			if ( $fl['contracts'] ) {
				$row['contracts_waiting'] = isset( $contracts[ $id ] ) ? $contracts[ $id ] : 0;
			}
			if ( $fl['finance'] ) {
				$row['income']     = array();
				$row['profit']     = array();
				foreach ( $money[ $id ] as $cur => $v ) {
					$row['income'][ $cur ] = $v['income'];
					$row['profit'][ $cur ] = $v['profit'];
				}
				$row['mrr']         = self::round_map( isset( $mrr[ $id ] ) ? $mrr[ $id ] : array() );
				$row['receivable']  = self::round_map( isset( $recv[ $id ] ) ? $recv[ $id ] : array() );
			}
			// Hide "No department" when nothing sits in it.
			if ( 0 === $id && ! ( $row['clients'] || $row['people'] || ! empty( $row['open_tasks'] ) || ! empty( $row['active_projects'] ) || ! empty( $row['contracts_waiting'] ) || ! empty( $row['income'] ) || ! empty( $row['mrr'] ) || ! empty( $row['receivable'] ) ) ) {
				continue;
			}
			$rows[] = $row;
		}
		return array( 'range' => array( 'from' => $from, 'to' => $to ), 'sections' => $fl, 'departments' => $rows );
	}

	/** Everything under one department (id 0 = things with no department). */
	public static function one( $id, array $f = array() ) {
		global $wpdb;
		$id = (int) $id;
		list( $from, $to ) = self::dates( $f );
		$fl    = self::flags();
		$names = self::names();
		if ( $id > 0 && ! isset( $names[ $id ] ) ) {
			return null;
		}
		$b   = $id > 0 ? $names[ $id ] : array( 'id' => 0, 'name' => 'No department', 'type' => '', 'description' => 'Things not attached to a department yet.' );
		$cb  = Schema::table( 'client_brands' );
		$cl  = Schema::table( 'clients' );
		$tm  = Schema::table( 'team_members' );
		$out = array(
			'id'          => $id,
			'name'        => $b['name'],
			'description' => $b['description'],
			'range'       => array( 'from' => $from, 'to' => $to ),
			'sections'    => $fl,
		);

		// Clients and people.
		$cwhere = $id > 0 ? $wpdb->prepare( "EXISTS (SELECT 1 FROM $cb x WHERE x.client_id=c.id AND x.brand_id=%d)", $id ) : "NOT EXISTS (SELECT 1 FROM $cb x WHERE x.client_id=c.id)"; // phpcs:ignore WordPress.DB
		$out['clients'] = array_map( function ( $r ) {
			return array( 'id' => (int) $r['id'], 'name' => $r['company_name'], 'status' => $r['status'], 'type' => $r['client_type'] );
		}, (array) $wpdb->get_results( "SELECT c.id, c.company_name, c.status, c.client_type FROM $cl c WHERE $cwhere ORDER BY c.company_name LIMIT 100", ARRAY_A ) ); // phpcs:ignore WordPress.DB
		$out['people'] = array_map( function ( $r ) {
			return array( 'id' => (int) $r['id'], 'name' => $r['name'], 'role' => $r['role'], 'kind' => $r['kind'] );
		}, (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, name, role, kind FROM $tm WHERE status='active' AND department_id=%d ORDER BY name LIMIT 100", $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB

		if ( $fl['work'] ) {
			$tk = Schema::table( 'tasks' );
			$ph = Schema::table( 'project_phases' );
			$pj = Schema::table( 'projects' );
			$dexp = 'COALESCE(NULLIF(t.department_id,0), NULLIF(ph.department_id,0), NULLIF(p.department_id,0), 0)';
			$base = "FROM $tk t LEFT JOIN $ph ph ON ph.id=t.phase_id LEFT JOIN $pj p ON p.id=t.project_id WHERE $dexp = %d";
			$today = Billing::today();
			$out['work'] = array(
				'open'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) $base AND t.status <> 'done'", $id ) ), // phpcs:ignore WordPress.DB
				'overdue' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) $base AND t.status <> 'done' AND t.due_date < %s", $id, $today ) ), // phpcs:ignore WordPress.DB
				'done'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) $base AND t.status = 'done' AND DATE(t.completed_at) BETWEEN %s AND %s", $id, $from, $to ) ), // phpcs:ignore WordPress.DB
			);
			$out['projects'] = array_map( function ( $r ) {
				$tot = (int) $r['total'];
				return array( 'id' => (int) $r['id'], 'name' => $r['name'], 'client_name' => $r['company_name'], 'status' => $r['status'], 'due_date' => $r['due_date'], 'total' => $tot, 'done' => (int) $r['done'], 'progress' => $tot ? (int) round( 100 * $r['done'] / $tot ) : 0 );
			}, (array) $wpdb->get_results( $wpdb->prepare( "SELECT p.id, p.name, p.status, p.due_date, c.company_name, (SELECT COUNT(*) FROM $tk x WHERE x.project_id=p.id) total, (SELECT COUNT(*) FROM $tk y WHERE y.project_id=p.id AND y.status='done') done FROM $pj p LEFT JOIN $cl c ON c.id=p.client_id WHERE p.status IN ('active','planned') AND (p.department_id=%d OR EXISTS (SELECT 1 FROM $ph z WHERE z.project_id=p.id AND z.department_id=%d)) ORDER BY p.due_date IS NULL, p.due_date LIMIT 20", $id, $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB
		}

		if ( $fl['contracts'] ) {
			$ct = Schema::table( 'contracts' );
			$by = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) n FROM $ct WHERE department_id=%d GROUP BY status", $id ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				$by[ $r['status'] ] = (int) $r['n'];
			}
			$out['contracts'] = array(
				'by_status' => $by,
				'recent'    => array_map( function ( $r ) {
					return array( 'id' => (int) $r['id'], 'number' => $r['number'], 'title' => $r['title'], 'client_name' => $r['company_name'], 'status' => $r['status'], 'sent_at' => $r['sent_at'] );
				}, (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.id, t.number, t.title, t.status, t.sent_at, c.company_name FROM $ct t LEFT JOIN $cl c ON c.id=t.client_id WHERE t.department_id=%d ORDER BY t.id DESC LIMIT 8", $id ), ARRAY_A ) ), // phpcs:ignore WordPress.DB
			);
		}

		if ( $fl['billing'] ) {
			$to_invoice = BillingList::query( array( 'department_id' => $id, 'status' => 'not_invoiced', 'per_page' => 8 ) );
			$out['billing'] = array(
				'to_invoice' => $to_invoice['money']['to_invoice'],
				'items'      => array_map( function ( $r ) {
					return array( 'key' => $r['key'], 'title' => $r['title'], 'client_name' => $r['client_name'], 'type' => $r['type'], 'price' => $r['price'], 'currency' => $r['currency'], 'date' => $r['date'] );
				}, $to_invoice['items'] ),
			);
			$iv = Schema::table( 'invoices' );
			$it = Schema::table( 'invoice_items' );
			$out['invoices'] = array_map( function ( $r ) {
				return array( 'id' => (int) $r['id'], 'number' => $r['invoice_number'], 'title' => $r['title'], 'client_name' => $r['company_name'], 'amount' => (float) $r['amount'], 'currency' => $r['currency'], 'status' => $r['status'], 'issue_date' => $r['issue_date'] );
			}, (array) $wpdb->get_results( $wpdb->prepare( "SELECT i.id, i.invoice_number, i.title, i.amount, i.currency, i.status, i.issue_date, c.company_name FROM $iv i LEFT JOIN $cl c ON c.id=i.client_id WHERE i.status NOT IN ('draft','cancelled') AND (i.department_id=%d OR (i.department_id=0 AND EXISTS (SELECT 1 FROM $it l WHERE l.invoice_id=i.id AND l.department_id=%d))) ORDER BY i.issue_date DESC, i.id DESC LIMIT 8", $id, $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB
		}

		if ( $fl['finance'] ) {
			$inc  = Profit::income_rows( $from, $to, 'invoiced' );
			$team = Profit::team_rows( $from, $to, 'earned' );
			$exp  = Profit::expense_rows( $from, $to );
			$out['money']  = Profit::sum( $inc, $team, $exp, $from, $to, $id );
			$mrr  = self::mrr();
			$recv = self::receivables();
			$out['mrr']        = self::round_map( isset( $mrr[ $id ] ) ? $mrr[ $id ] : array() );
			$out['receivable'] = self::round_map( isset( $recv[ $id ] ) ? $recv[ $id ] : array() );
			// Cash actually received and paid out in the period, and what the team is still owed.
			$col = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT currency c, SUM(amount) a FROM ' . Schema::table( 'payments' ) . ' WHERE department_id=%d AND paid_on BETWEEN %s AND %s GROUP BY currency', $id, $from, $to ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				$col[ $r['c'] ] = round( (float) $r['a'], 2 );
			}
			$po = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT currency c, SUM(amount) a FROM " . Schema::table( 'payouts' ) . " WHERE department_id=%d AND status='paid' AND paid_on BETWEEN %s AND %s GROUP BY currency", $id, $from, $to ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				$po[ $r['c'] ] = round( (float) $r['a'], 2 );
			}
			$owed = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT currency c, SUM(amount) a FROM " . Schema::table( 'pay_items' ) . " WHERE department_id=%d AND status='payable' GROUP BY currency", $id ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				$owed[ $r['c'] ] = round( (float) $r['a'], 2 );
			}
			$cats = array();
			foreach ( $exp as $r ) {
				if ( (int) $r['dept'] === $id ) {
					$cats[ $r['category'] ][ $r['cur'] ] = ( isset( $cats[ $r['category'] ][ $r['cur'] ] ) ? $cats[ $r['category'] ][ $r['cur'] ] : 0 ) + $r['amount'];
				}
			}
			$cat_rows = array();
			foreach ( $cats as $k => $v ) {
				$cat_rows[] = array( 'category' => $k, 'values' => self::round_map( $v ), 'sort' => array_sum( $v ) );
			}
			usort( $cat_rows, function ( $a, $b ) {
				return $b['sort'] <=> $a['sort'];
			} );
			$out['cash'] = array( 'received' => $col, 'paid_out' => $po, 'team_owed' => $owed );
			$out['expense_categories'] = array_slice( $cat_rows, 0, 6 );
		}
		return $out;
	}
}
