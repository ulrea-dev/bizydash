<?php
namespace BizyDash\Services;

use BizyDash\Database\Schema;
use BizyDash\Modules;
use BizyDash\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Clients → Analytics: who the clients are, how the book is growing, where the money comes from and who has gone quiet.
 * Counts are open to everyone; income, recurring income and what clients owe are finance-only and stay per currency.
 */
class ClientAnalytics {

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
		$cl   = Schema::table( 'clients' );
		$cb   = Schema::table( 'client_brands' );

		// The clients in scope (a client can belong to several departments).
		$where = '1=1';
		if ( null !== $dept ) {
			$where = $dept > 0 ? $wpdb->prepare( "EXISTS (SELECT 1 FROM $cb x WHERE x.client_id=c.id AND x.brand_id=%d)", $dept ) : "NOT EXISTS (SELECT 1 FROM $cb x WHERE x.client_id=c.id)"; // phpcs:ignore WordPress.DB
		}
		$clients = (array) $wpdb->get_results( "SELECT c.id, c.company_name, c.client_type, c.status, c.created_at FROM $cl c WHERE $where", ARRAY_A ); // phpcs:ignore WordPress.DB
		$in_scope = array();
		foreach ( $clients as $c ) {
			$in_scope[ (int) $c['id'] ] = $c['company_name'];
		}

		$bl  = FinanceAnalytics::buckets( $from, $to, $gran );
		$idx = array();
		foreach ( $bl as $i => $b ) {
			$idx[ $b['key'] ] = $i;
		}
		$new_s   = array_fill( 0, count( $bl ), 0 );
		$by_type = array();
		$by_stat = array();
		$new_n   = 0;
		foreach ( $clients as $c ) {
			$by_type[ $c['client_type'] ] = ( isset( $by_type[ $c['client_type'] ] ) ? $by_type[ $c['client_type'] ] : 0 ) + 1;
			$by_stat[ $c['status'] ]      = ( isset( $by_stat[ $c['status'] ] ) ? $by_stat[ $c['status'] ] : 0 ) + 1;
			$d = $c['created_at'] ? substr( $c['created_at'], 0, 10 ) : '';
			if ( $d && $d >= $from && $d <= $to ) {
				++$new_n;
				$k = FinanceAnalytics::bucket( $d, $gran );
				isset( $idx[ $k ] ) && ++$new_s[ $idx[ $k ] ];
			}
		}

		// By department.
		$dn = array();
		foreach ( (array) $wpdb->get_results( 'SELECT id, name FROM ' . Schema::table( 'brands' ) . ' ORDER BY name', ARRAY_A ) as $b ) { // phpcs:ignore WordPress.DB
			$dn[ (int) $b['id'] ] = $b['name'];
		}
		$dept_counts = array();
		$linked      = array();
		foreach ( (array) $wpdb->get_results( "SELECT client_id, brand_id FROM $cb", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			if ( isset( $in_scope[ (int) $r['client_id'] ] ) ) {
				$dept_counts[ (int) $r['brand_id'] ] = ( isset( $dept_counts[ (int) $r['brand_id'] ] ) ? $dept_counts[ (int) $r['brand_id'] ] : 0 ) + 1;
				$linked[ (int) $r['client_id'] ]     = true;
			}
		}
		$dept_rows = array();
		foreach ( $dept_counts as $id => $n ) {
			$dept_rows[] = array( 'id' => $id, 'name' => isset( $dn[ $id ] ) ? $dn[ $id ] : 'Unknown', 'clients' => $n );
		}
		$none = count( $in_scope ) - count( $linked );
		$none > 0 && ( $dept_rows[] = array( 'id' => 0, 'name' => 'No department', 'clients' => $none ) );
		usort( $dept_rows, function ( $a, $b ) {
			return $b['clients'] <=> $a['clients'];
		} );

		// Quiet clients: active, no open work and no invoice in the last 180 days.
		$tk     = Schema::table( 'tasks' );
		$pj     = Schema::table( 'projects' );
		$iv     = Schema::table( 'invoices' );
		$cut    = gmdate( 'Y-m-d', strtotime( "$today -180 days" ) );
		$last   = array();
		foreach ( (array) $wpdb->get_results( "SELECT client_id, MAX(issue_date) d FROM $iv WHERE status NOT IN ('draft','cancelled') GROUP BY client_id", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$last[ (int) $r['client_id'] ] = $r['d'];
		}
		$busy = array();
		foreach ( (array) $wpdb->get_col( "SELECT DISTINCT client_id FROM $tk WHERE status <> 'done' AND client_id > 0" ) as $id ) { // phpcs:ignore WordPress.DB
			$busy[ (int) $id ] = true;
		}
		foreach ( (array) $wpdb->get_col( "SELECT DISTINCT client_id FROM $pj WHERE status IN ('active','planned') AND client_id > 0" ) as $id ) { // phpcs:ignore WordPress.DB
			$busy[ (int) $id ] = true;
		}
		$quiet = array();
		foreach ( $clients as $c ) {
			$id = (int) $c['id'];
			if ( 'active' === $c['status'] && empty( $busy[ $id ] ) && ( empty( $last[ $id ] ) || $last[ $id ] < $cut ) ) {
				$quiet[] = array( 'id' => $id, 'name' => $c['company_name'], 'last_invoice' => isset( $last[ $id ] ) ? $last[ $id ] : null );
			}
		}

		$active = isset( $by_stat['active'] ) ? $by_stat['active'] : 0;
		$out    = array(
			'range'       => array( 'from' => $from, 'to' => $to ),
			'granularity' => $gran,
			'department'  => $dept,
			'stats'       => array( 'total' => count( $clients ), 'active' => $active, 'new' => $new_n, 'quiet' => count( $quiet ) ),
			'buckets'     => wp_list_pluck( $bl, 'label' ),
			'new_series'  => $new_s,
			'by_type'     => $by_type,
			'by_status'   => $by_stat,
			'departments' => $dept_rows,
			'quiet'       => array_slice( $quiet, 0, 8 ),
			'money'       => null,
		);

		if ( current_user_can( 'bizydash_finance' ) && Modules::enabled( 'finance' ) ) {
			// Income by client and currency in the period (invoiced basis, net of discount), and what concentrates where.
			$inc = array();
			foreach ( Profit::income_rows( $from, $to, 'invoiced' ) as $r ) {
				if ( ! isset( $in_scope[ $r['client'] ] ) ) {
					continue;
				}
				$inc[ $r['cur'] ][ $r['client'] ] = ( isset( $inc[ $r['cur'] ][ $r['client'] ] ) ? $inc[ $r['cur'] ][ $r['client'] ] : 0 ) + $r['income'];
			}
			$top     = array();
			$conc    = array();
			$avg     = array();
			foreach ( $inc as $cur => $by ) {
				arsort( $by );
				$total = array_sum( $by );
				$i     = 0;
				foreach ( $by as $cid => $v ) {
					if ( $i++ < 10 ) {
						$top[] = array( 'id' => $cid, 'name' => $in_scope[ $cid ], 'currency' => $cur, 'income' => round( $v, 2 ), 'share_pct' => $total > 0 ? round( 100 * $v / $total, 1 ) : 0 );
					}
				}
				$vals          = array_values( $by );
				$conc[ $cur ]  = array( 'top1_pct' => $total > 0 ? round( 100 * $vals[0] / $total, 1 ) : 0, 'top5_pct' => $total > 0 ? round( 100 * array_sum( array_slice( $vals, 0, 5 ) ) / $total, 1 ) : 0, 'clients' => count( $by ) );
				$avg[ $cur ]   = round( $total / max( 1, count( $by ) ), 2 );
			}
			// Monthly recurring income per client.
			$mrr = array();
			foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'client_services' ) . " WHERE status='active' AND billing_period NOT IN ('','none')", ARRAY_A ) as $cs ) { // phpcs:ignore WordPress.DB
				if ( ! isset( $in_scope[ (int) $cs['client_id'] ] ) ) {
					continue;
				}
				$v = Subscriptions::monthly_value( $cs );
				if ( $v > 0 ) {
					$mrr[ $cs['currency'] ][ (int) $cs['client_id'] ] = ( isset( $mrr[ $cs['currency'] ][ (int) $cs['client_id'] ] ) ? $mrr[ $cs['currency'] ][ (int) $cs['client_id'] ] : 0 ) + $v;
				}
			}
			$mrr_rows = array();
			foreach ( $mrr as $cur => $by ) {
				arsort( $by );
				$mrr_rows[] = array( 'currency' => $cur, 'total' => round( array_sum( $by ), 2 ), 'clients' => count( $by ), 'top' => array_map( function ( $cid, $v ) use ( $in_scope ) {
					return array( 'id' => $cid, 'name' => $in_scope[ $cid ], 'amount' => round( $v, 2 ) );
				}, array_slice( array_keys( $by ), 0, 5 ), array_slice( array_values( $by ), 0, 5 ) ) );
			}
			// Who owes us, and how late.
			$owe = array();
			foreach ( (array) $wpdb->get_results( "SELECT client_id, currency, SUM(amount - amount_paid) bal, MIN(due_date) oldest FROM $iv WHERE status IN ('sent','due','partially_paid','overdue') AND due_date < '$today' GROUP BY client_id, currency", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				if ( isset( $in_scope[ (int) $r['client_id'] ] ) && (float) $r['bal'] > 0 ) {
					$owe[] = array( 'id' => (int) $r['client_id'], 'name' => $in_scope[ (int) $r['client_id'] ], 'currency' => $r['currency'], 'balance' => round( (float) $r['bal'], 2 ), 'days_late' => (int) floor( ( strtotime( $today ) - strtotime( $r['oldest'] ) ) / DAY_IN_SECONDS ) );
				}
			}
			usort( $owe, function ( $a, $b ) {
				return $b['days_late'] <=> $a['days_late'];
			} );
			$out['money'] = array( 'top' => $top, 'concentration' => $conc, 'avg_per_client' => $avg, 'mrr' => $mrr_rows, 'overdue' => array_slice( $owe, 0, 8 ) );
		}
		return $out;
	}
}
