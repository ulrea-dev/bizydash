<?php
namespace BizyDash\Services;

use BizyDash\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Work → Analytics: how tasks and projects are moving over a period, filtered by department, person and client.
 * A task's department is its own, else its phase's, else its project's (so a project split across departments reports correctly).
 * Money panels (project value, team budget, pay) are finance-only and stay per currency.
 */
class WorkAnalytics {

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
		$assignee = ! empty( $f['assignee_id'] ) ? (int) $f['assignee_id'] : 0;
		$client   = ! empty( $f['client_id'] ) ? (int) $f['client_id'] : 0;
		$fin      = current_user_can( 'bizydash_finance' );
		$gran     = FinanceAnalytics::granularity( $from, $to );

		$tk = Schema::table( 'tasks' );
		$ph = Schema::table( 'project_phases' );
		$pj = Schema::table( 'projects' );
		$where = '1=1' . ( $assignee ? $wpdb->prepare( ' AND t.assignee_id=%d', $assignee ) : '' ) . ( $client ? $wpdb->prepare( ' AND t.client_id=%d', $client ) : '' );
		$dexp  = 'COALESCE(NULLIF(t.department_id,0), NULLIF(ph.department_id,0), NULLIF(p.department_id,0), 0)';
		if ( null !== $dept ) {
			$where .= $wpdb->prepare( " AND $dexp=%d", $dept ); // phpcs:ignore WordPress.DB
		}
		$tasks = (array) $wpdb->get_results( "SELECT t.id, t.status, t.priority, t.due_date, t.assignee_id, t.project_id, t.phase_id, t.created_at, t.completed_at, $dexp AS dept FROM $tk t LEFT JOIN $ph ph ON ph.id=t.phase_id LEFT JOIN $pj p ON p.id=t.project_id WHERE $where", ARRAY_A ); // phpcs:ignore WordPress.DB

		$day = function ( $dt ) {
			return $dt ? substr( $dt, 0, 10 ) : '';
		};
		$bl  = FinanceAnalytics::buckets( $from, $to, $gran );
		$idx = array();
		foreach ( $bl as $i => $b ) {
			$idx[ $b['key'] ] = $i;
		}
		$created   = array_fill( 0, count( $bl ), 0 );
		$completed = array_fill( 0, count( $bl ), 0 );
		$stat      = array( 'open' => 0, 'overdue' => 0, 'created' => 0, 'completed' => 0, 'on_time' => 0, 'with_due' => 0 );
		$status    = array( 'todo' => 0, 'doing' => 0 );
		$priority  = array( 'normal' => 0, 'high' => 0 );
		$cycles    = array();
		$people    = array();
		$bydept    = array();
		$touch = function ( &$arr, $key ) {
			if ( ! isset( $arr[ $key ] ) ) {
				$arr[ $key ] = array( 'open' => 0, 'overdue' => 0, 'done' => 0, 'cycle' => array() );
			}
		};
		foreach ( $tasks as $t ) {
			$open = 'done' !== $t['status'];
			$cd   = $day( $t['created_at'] );
			$dd   = $day( $t['completed_at'] );
			$a    = (int) $t['assignee_id'];
			$d    = (int) $t['dept'];
			$touch( $people, $a );
			$touch( $bydept, $d );
			if ( $open ) {
				++$stat['open'];
				isset( $status[ $t['status'] ] ) && ++$status[ $t['status'] ];
				isset( $priority[ $t['priority'] ] ) && ++$priority[ $t['priority'] ];
				++$people[ $a ]['open'];
				++$bydept[ $d ]['open'];
				if ( $t['due_date'] && $t['due_date'] < $today ) {
					++$stat['overdue'];
					++$people[ $a ]['overdue'];
					++$bydept[ $d ]['overdue'];
				}
			}
			if ( $cd && $cd >= $from && $cd <= $to ) {
				++$stat['created'];
				$k = FinanceAnalytics::bucket( $cd, $gran );
				isset( $idx[ $k ] ) && ++$created[ $idx[ $k ] ];
			}
			if ( ! $open && $dd && $dd >= $from && $dd <= $to ) {
				++$stat['completed'];
				++$people[ $a ]['done'];
				++$bydept[ $d ]['done'];
				$k = FinanceAnalytics::bucket( $dd, $gran );
				isset( $idx[ $k ] ) && ++$completed[ $idx[ $k ] ];
				if ( $cd ) {
					$days = max( 0, ( strtotime( $t['completed_at'] ) - strtotime( $t['created_at'] ) ) / DAY_IN_SECONDS );
					$cycles[]                  = $days;
					$people[ $a ]['cycle'][]   = $days;
				}
				if ( $t['due_date'] ) {
					++$stat['with_due'];
					$dd <= $t['due_date'] && ++$stat['on_time'];
				}
			}
		}
		sort( $cycles );
		$n   = count( $cycles );
		$avg = $n ? round( array_sum( $cycles ) / $n, 1 ) : null;
		$med = $n ? round( $n % 2 ? $cycles[ ( $n - 1 ) / 2 ] : ( $cycles[ $n / 2 - 1 ] + $cycles[ $n / 2 ] ) / 2, 1 ) : null;
		$stat['avg_cycle_days']    = $avg;
		$stat['median_cycle_days'] = $med;
		$stat['on_time_pct']       = $stat['with_due'] ? (int) round( 100 * $stat['on_time'] / $stat['with_due'] ) : null;

		// Names for people and departments.
		$pn = array( 0 => 'Unassigned' );
		foreach ( (array) $wpdb->get_results( 'SELECT id, name FROM ' . Schema::table( 'team_members' ), ARRAY_A ) as $m ) { // phpcs:ignore WordPress.DB
			$pn[ (int) $m['id'] ] = $m['name'];
		}
		$dn = array( 0 => 'General / no department' );
		foreach ( (array) $wpdb->get_results( 'SELECT id, name FROM ' . Schema::table( 'brands' ) . ' ORDER BY name', ARRAY_A ) as $b ) { // phpcs:ignore WordPress.DB
			$dn[ (int) $b['id'] ] = $b['name'];
		}
		$avgc = function ( $list ) {
			return $list ? round( array_sum( $list ) / count( $list ), 1 ) : null;
		};
		$people_rows = array();
		foreach ( $people as $id => $r ) {
			if ( $r['open'] || $r['done'] ) {
				$people_rows[] = array( 'id' => $id, 'name' => isset( $pn[ $id ] ) ? $pn[ $id ] : 'Unknown', 'open' => $r['open'], 'overdue' => $r['overdue'], 'done' => $r['done'], 'avg_cycle_days' => $avgc( $r['cycle'] ) );
			}
		}
		usort( $people_rows, function ( $a, $b ) {
			return $b['open'] <=> $a['open'];
		} );
		$dept_rows = array();
		foreach ( $bydept as $id => $r ) {
			if ( $r['open'] || $r['done'] ) {
				$dept_rows[] = array( 'id' => $id, 'name' => isset( $dn[ $id ] ) ? $dn[ $id ] : 'Unknown', 'open' => $r['open'], 'overdue' => $r['overdue'], 'done' => $r['done'] );
			}
		}

		// Projects: on schedule or late (open projects), with progress from their tasks.
		$proj = array();
		$sched = array( 'on_track' => 0, 'late' => 0, 'no_date' => 0 );
		foreach ( (array) $wpdb->get_results( 'SELECT p.id, p.name, p.status, p.due_date, p.department_id, p.client_id, c.company_name FROM ' . $pj . ' p LEFT JOIN ' . Schema::table( 'clients' ) . " c ON c.id=p.client_id WHERE p.status IN ('active','planned') ORDER BY p.due_date IS NULL, p.due_date ASC", ARRAY_A ) as $p ) { // phpcs:ignore WordPress.DB
			if ( $client && (int) $p['client_id'] !== $client ) {
				continue;
			}
			if ( null !== $dept && (int) $p['department_id'] !== $dept && ! (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $ph WHERE project_id=%d AND department_id=%d", $p['id'], $dept ) ) ) { // phpcs:ignore WordPress.DB
				continue;
			}
			$tot  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tk WHERE project_id=%d", $p['id'] ) ); // phpcs:ignore WordPress.DB
			$done = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tk WHERE project_id=%d AND status='done'", $p['id'] ) ); // phpcs:ignore WordPress.DB
			$late = $p['due_date'] && $p['due_date'] < $today;
			$st   = ! $p['due_date'] ? 'no_date' : ( $late ? 'late' : 'on_track' );
			++$sched[ $st ];
			$proj[] = array( 'id' => (int) $p['id'], 'name' => $p['name'], 'client_name' => $p['company_name'], 'due_date' => $p['due_date'], 'total' => $tot, 'done' => $done, 'progress' => $tot ? (int) round( 100 * $done / $tot ) : 0, 'state' => $st, 'days_late' => $late ? (int) round( ( strtotime( $today ) - strtotime( $p['due_date'] ) ) / DAY_IN_SECONDS ) : 0 );
		}

		$out = array(
			'range'       => array( 'from' => $from, 'to' => $to ),
			'granularity' => $gran,
			'department'  => $dept,
			'stats'       => $stat,
			'buckets'     => wp_list_pluck( $bl, 'label' ),
			'flow'        => array( 'created' => $created, 'completed' => $completed ),
			'open_status' => $status,
			'open_priority' => $priority,
			'people'      => $people_rows,
			'departments' => $dept_rows,
			'schedule'    => $sched,
			'projects'    => array_slice( $proj, 0, 15 ),
			'money'       => null,
		);

		if ( $fin ) {
			// Project value, team budget and team pay by department (a phase's department decides, else its project's), per currency.
			$money = array();
			$pa    = Schema::table( 'pay_allocations' );
			$pi    = Schema::table( 'pay_items' );
			$cur0  = \BizyDash\Settings::get( 'default_currency' );
			foreach ( (array) $wpdb->get_results( "SELECT ph.*, p.currency AS pcur, p.department_id AS pdept, p.client_id AS pclient FROM $ph ph JOIN $pj p ON p.id=ph.project_id", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
				$d = (int) $row['department_id'] ? (int) $row['department_id'] : (int) $row['pdept'];
				if ( null !== $dept && $d !== $dept ) {
					continue;
				}
				if ( $client && (int) $row['pclient'] !== $client ) {
					continue;
				}
				$cur = $row['pcur'] ? $row['pcur'] : $cur0;
				$al  = 0.0;
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $pa WHERE scope='phase' AND scope_id=%d", $row['id'] ), ARRAY_A ) as $a ) { // phpcs:ignore WordPress.DB
					$al += Pay::allocation_amount( $a, $row );
				}
				$m = &$money[ $d ][ $cur ];
				$m = isset( $m ) ? $m : array( 'price' => 0.0, 'budget' => 0.0, 'allocated' => 0.0, 'earned' => 0.0, 'paid' => 0.0 );
				$m['price']     += (float) $row['price'];
				$m['budget']    += (float) $row['team_budget'];
				$m['allocated'] += $al;
				unset( $m );
			}
			// What the team actually earned in the period (any pay item, by its stamped department).
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT department_id d, currency c, status s, SUM(amount) a FROM $pi WHERE status IN ('payable','paid') AND earned_on BETWEEN %s AND %s" . ( null !== $dept ? $wpdb->prepare( ' AND department_id=%d', $dept ) : '' ) . ' GROUP BY department_id, currency, status', $from, $to ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				$m = &$money[ (int) $r['d'] ][ $r['c'] ];
				$m = isset( $m ) ? $m : array( 'price' => 0.0, 'budget' => 0.0, 'allocated' => 0.0, 'earned' => 0.0, 'paid' => 0.0 );
				$m['earned'] += (float) $r['a'];
				'paid' === $r['s'] && ( $m['paid'] += (float) $r['a'] );
				unset( $m );
			}
			$rows = array();
			foreach ( $money as $d => $vals ) {
				foreach ( $vals as $c => $v ) {
					$vals[ $c ] = array_map( function ( $x ) {
						return round( $x, 2 );
					}, $v );
				}
				$rows[] = array( 'id' => $d, 'name' => isset( $dn[ $d ] ) ? $dn[ $d ] : 'Unknown', 'values' => $vals );
			}
			$out['money'] = $rows;
		}
		return $out;
	}
}
