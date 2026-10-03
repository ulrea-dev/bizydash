<?php
namespace BizyDash\Services;

use BizyDash\Database\Schema;
use BizyDash\Modules;
use BizyDash\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Team → Analytics: headcount, who is carrying what, and (finance only) where pay goes.
 * The department filter picks people by their own department; the pay panel "by department earned for" is never narrowed.
 */
class TeamAnalytics {

	public static function query( array $f ) {
		global $wpdb;
		$today = Billing::today();
		$to    = FinanceAnalytics::date_or( isset( $f['to'] ) ? $f['to'] : '', $today );
		$from  = FinanceAnalytics::date_or( isset( $f['from'] ) ? $f['from'] : '', gmdate( 'Y-m-d', strtotime( "$to -89 days" ) ) );
		if ( $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}
		$dept = null;
		if ( isset( $f['department_id'] ) && 'none' === $f['department_id'] ) {
			$dept = 0;
		} elseif ( ! empty( $f['department_id'] ) && (int) $f['department_id'] > 0 ) {
			$dept = (int) $f['department_id'];
		}
		$gran = FinanceAnalytics::granularity( $from, $to );
		$tm   = Schema::table( 'team_members' );
		$tk   = Schema::table( 'tasks' );
		$pi   = Schema::table( 'pay_items' );

		$where   = null !== $dept ? $wpdb->prepare( 'WHERE department_id=%d', $dept ) : '';
		$members = (array) $wpdb->get_results( "SELECT id, name, kind, status, department_id, salary_amount, currency, start_date, created_at FROM $tm $where", ARRAY_A ); // phpcs:ignore WordPress.DB
		$ids     = array_map( 'intval', wp_list_pluck( $members, 'id' ) );
		$in      = $ids ? implode( ',', $ids ) : '0';

		$dn = array( 0 => 'No department' );
		foreach ( (array) $wpdb->get_results( 'SELECT id, name FROM ' . Schema::table( 'brands' ) . ' ORDER BY name', ARRAY_A ) as $b ) { // phpcs:ignore WordPress.DB
			$dn[ (int) $b['id'] ] = $b['name'];
		}

		// Headcount.
		$active = array();
		$kinds  = array();
		$depts  = array();
		$joined = 0;
		foreach ( $members as $m ) {
			if ( 'active' === $m['status'] ) {
				$active[]                = $m;
				$kinds[ $m['kind'] ]     = ( isset( $kinds[ $m['kind'] ] ) ? $kinds[ $m['kind'] ] : 0 ) + 1;
				$d                       = (int) $m['department_id'];
				$depts[ $d ]             = ( isset( $depts[ $d ] ) ? $depts[ $d ] : 0 ) + 1;
			}
			$j = $m['start_date'] ? $m['start_date'] : substr( (string) $m['created_at'], 0, 10 );
			$j && $j >= $from && $j <= $to && ++$joined;
		}
		$dept_rows = array();
		foreach ( $depts as $id => $n ) {
			$dept_rows[] = array( 'id' => $id, 'name' => isset( $dn[ $id ] ) ? $dn[ $id ] : 'Unknown', 'people' => $n );
		}
		usort( $dept_rows, function ( $a, $b ) {
			return $b['people'] <=> $a['people'];
		} );

		// Tasks per person.
		$open = array();
		foreach ( (array) $wpdb->get_results( "SELECT assignee_id a, COUNT(*) n, SUM(due_date IS NOT NULL AND due_date < '$today') late, COUNT(DISTINCT NULLIF(project_id,0)) pj FROM $tk WHERE status <> 'done' AND assignee_id IN ($in) GROUP BY assignee_id", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$open[ (int) $r['a'] ] = $r;
		}
		$done = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT assignee_id a, COUNT(*) n, AVG(TIMESTAMPDIFF(HOUR, created_at, completed_at)) / 24 cyc FROM $tk WHERE status='done' AND assignee_id IN ($in) AND DATE(completed_at) BETWEEN %s AND %s GROUP BY assignee_id", $from, $to ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$done[ (int) $r['a'] ] = $r;
		}

		// Money (finance only).
		$fin    = current_user_can( 'bizydash_finance' ) && Modules::enabled( 'finance' );
		$earned = array();
		$owed   = array();
		$paid   = array();
		$kind_t = array();
		$series = array();
		$bl     = FinanceAnalytics::buckets( $from, $to, $gran );
		$idx    = array();
		foreach ( $bl as $i => $b ) {
			$idx[ $b['key'] ] = $i;
		}
		$by_dept_earned = array();
		$salary         = array();
		if ( $fin ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT team_member_id m, kind, currency c, earned_on d, amount a, department_id dp FROM $pi WHERE status IN ('payable','paid') AND earned_on BETWEEN %s AND %s", $from, $to ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				$m = (int) $r['m'];
				$a = (float) $r['a'];
				// Pay by kind and over time follows the people in scope; "by department earned for" always shows everyone.
				$by_dept_earned[ (int) $r['dp'] ][ $r['c'] ] = ( isset( $by_dept_earned[ (int) $r['dp'] ][ $r['c'] ] ) ? $by_dept_earned[ (int) $r['dp'] ][ $r['c'] ] : 0 ) + $a;
				if ( ! in_array( $m, $ids, true ) ) {
					continue;
				}
				$earned[ $m ][ $r['c'] ]       = ( isset( $earned[ $m ][ $r['c'] ] ) ? $earned[ $m ][ $r['c'] ] : 0 ) + $a;
				$kind_t[ $r['c'] ][ $r['kind'] ] = ( isset( $kind_t[ $r['c'] ][ $r['kind'] ] ) ? $kind_t[ $r['c'] ][ $r['kind'] ] : 0 ) + $a;
				$k = FinanceAnalytics::bucket( $r['d'], $gran );
				if ( isset( $idx[ $k ] ) ) {
					if ( ! isset( $series[ $r['c'] ] ) ) {
						$series[ $r['c'] ] = array_fill( 0, count( $bl ), 0.0 );
					}
					$series[ $r['c'] ][ $idx[ $k ] ] += $a;
				}
			}
			foreach ( (array) $wpdb->get_results( "SELECT team_member_id m, currency c, SUM(amount) a FROM $pi WHERE status='payable' AND team_member_id IN ($in) GROUP BY team_member_id, currency", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				$owed[ (int) $r['m'] ][ $r['c'] ] = (float) $r['a'];
			}
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT i.team_member_id m, i.currency c, SUM(i.amount) a FROM ' . $pi . ' i JOIN ' . Schema::table( 'payouts' ) . " po ON po.id=i.payout_id WHERE po.status='paid' AND i.status='paid' AND po.paid_on BETWEEN %s AND %s AND i.team_member_id IN ($in) GROUP BY i.team_member_id, i.currency", $from, $to ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				$paid[ (int) $r['m'] ][ $r['c'] ] = (float) $r['a'];
			}
			foreach ( $active as $m ) {
				if ( (float) $m['salary_amount'] > 0 ) {
					$c            = $m['currency'] ? $m['currency'] : Settings::get( 'default_currency' );
					$salary[ $c ] = ( isset( $salary[ $c ] ) ? $salary[ $c ] : 0 ) + (float) $m['salary_amount'];
				}
			}
		}

		$people = array();
		foreach ( $active as $m ) {
			$id = (int) $m['id'];
			$o  = isset( $open[ $id ] ) ? $open[ $id ] : null;
			$d  = isset( $done[ $id ] ) ? $done[ $id ] : null;
			$row = array(
				'id'             => $id,
				'name'           => $m['name'],
				'kind'           => $m['kind'],
				'department'     => isset( $dn[ (int) $m['department_id'] ] ) ? $dn[ (int) $m['department_id'] ] : '',
				'open'           => $o ? (int) $o['n'] : 0,
				'overdue'        => $o ? (int) $o['late'] : 0,
				'projects'       => $o ? (int) $o['pj'] : 0,
				'done'           => $d ? (int) $d['n'] : 0,
				'avg_cycle_days' => $d && null !== $d['cyc'] ? round( (float) $d['cyc'], 1 ) : null,
			);
			if ( $fin ) {
				$row['earned'] = isset( $earned[ $id ] ) ? array_map( function ( $v ) {
					return round( $v, 2 );
				}, $earned[ $id ] ) : array();
				$row['owed']   = isset( $owed[ $id ] ) ? array_map( function ( $v ) {
					return round( $v, 2 );
				}, $owed[ $id ] ) : array();
				$row['paid']   = isset( $paid[ $id ] ) ? array_map( function ( $v ) {
					return round( $v, 2 );
				}, $paid[ $id ] ) : array();
			}
			$people[] = $row;
		}
		usort( $people, function ( $a, $b ) {
			return $b['done'] <=> $a['done'] ?: $b['open'] <=> $a['open'];
		} );

		$out = array(
			'range'       => array( 'from' => $from, 'to' => $to ),
			'granularity' => $gran,
			'department'  => $dept,
			'stats'       => array( 'active' => count( $active ), 'joined' => $joined, 'employees' => isset( $kinds['employee'] ) ? $kinds['employee'] : 0, 'contractors' => isset( $kinds['contractor'] ) ? $kinds['contractor'] : 0, 'open' => array_sum( wp_list_pluck( $people, 'open' ) ), 'done' => array_sum( wp_list_pluck( $people, 'done' ) ) ),
			'kinds'       => $kinds,
			'departments' => $dept_rows,
			'people'      => $people,
			'buckets'     => wp_list_pluck( $bl, 'label' ),
			'money'       => null,
		);
		if ( $fin ) {
			$dep = array();
			foreach ( $by_dept_earned as $d => $vals ) {
				$dep[] = array( 'id' => $d, 'name' => isset( $dn[ $d ] ) ? $dn[ $d ] : 'Unknown', 'values' => array_map( function ( $v ) {
					return round( $v, 2 );
				}, $vals ) );
			}
			$round = function ( $a ) {
				return array_map( function ( $v ) {
					return round( $v, 2 );
				}, $a );
			};
			$out['money'] = array(
				'salary_monthly' => $round( $salary ),
				'by_kind'        => array_map( $round, $kind_t ),
				'series'         => array_map( $round, $series ),
				'by_department'  => $dep,
			);
		}
		return $out;
	}
}
