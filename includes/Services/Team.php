<?php
namespace BizyDash\Services;

use BizyDash\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Per-person summaries for the Team list and detail page (work for everyone; money for finance users only). */
class Team {

	const PAY_FIELDS = array( 'salary_amount', 'salary_period', 'bank_name', 'account_name', 'account_number' );

	public static function decorate( array $rows ) {
		global $wpdb;
		$fin = current_user_can( 'bizydash_finance' );
		$ids = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$in  = $ids ? implode( ',', $ids ) : '0';
		$tk  = Schema::table( 'tasks' );
		$pr  = Schema::table( 'projects' );
		$today = Billing::today();

		$open = array();
		foreach ( (array) $wpdb->get_results( "SELECT assignee_id, COUNT(*) n, SUM(due_date IS NOT NULL AND due_date < '$today') late FROM $tk WHERE status <> 'done' AND assignee_id IN ($in) GROUP BY assignee_id", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$open[ (int) $r['assignee_id'] ] = array( (int) $r['n'], (int) $r['late'] );
		}
		$proj = array();
		foreach ( (array) $wpdb->get_results( "SELECT t.assignee_id, COUNT(DISTINCT t.project_id) n FROM $tk t JOIN $pr p ON p.id=t.project_id WHERE t.status <> 'done' AND p.status IN ('active','planned') AND t.assignee_id IN ($in) GROUP BY t.assignee_id", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$proj[ (int) $r['assignee_id'] ] = (int) $r['n'];
		}

		$owed = array();
		$paid_year = array();
		$paid_all = array();
		if ( $fin ) {
			$pi = Schema::table( 'pay_items' );
			$po = Schema::table( 'payouts' );
			foreach ( (array) $wpdb->get_results( "SELECT team_member_id m, currency c, SUM(amount) total, COUNT(*) n FROM $pi WHERE status='payable' AND team_member_id IN ($in) GROUP BY team_member_id, currency", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				$owed[ (int) $r['m'] ][ $r['c'] ] = array( 'amount' => round( (float) $r['total'], 2 ), 'count' => (int) $r['n'] );
			}
			$y0 = gmdate( 'Y-01-01', strtotime( $today ) );
			foreach ( (array) $wpdb->get_results( "SELECT team_member_id m, currency c, SUM(amount) total, SUM(paid_on >= '$y0') ytd_flag, SUM(IF(paid_on >= '$y0', amount, 0)) ytd FROM $po WHERE status='paid' AND team_member_id IN ($in) GROUP BY team_member_id, currency", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				$paid_all[ (int) $r['m'] ][ $r['c'] ]  = array( 'amount' => round( (float) $r['total'], 2 ), 'count' => 1 );
				$paid_year[ (int) $r['m'] ][ $r['c'] ] = array( 'amount' => round( (float) $r['ytd'], 2 ), 'count' => 1 );
			}
		}

		foreach ( $rows as &$r ) {
			$id                 = (int) $r['id'];
			$r['is_me']         = (int) $r['wp_user_id'] > 0 && (int) $r['wp_user_id'] === get_current_user_id();
			$r['open_tasks']    = isset( $open[ $id ] ) ? $open[ $id ][0] : 0;
			$r['overdue_tasks'] = isset( $open[ $id ] ) ? $open[ $id ][1] : 0;
			$r['active_projects'] = isset( $proj[ $id ] ) ? $proj[ $id ] : 0;
			if ( $fin ) {
				$r['owed']      = isset( $owed[ $id ] ) ? $owed[ $id ] : array();
				$r['paid_year'] = isset( $paid_year[ $id ] ) ? $paid_year[ $id ] : array();
				$r['paid_all']  = isset( $paid_all[ $id ] ) ? $paid_all[ $id ] : array();
			} else {
				foreach ( self::PAY_FIELDS as $k ) {
					unset( $r[ $k ] );
				}
			}
		}
		return $rows;
	}
}
