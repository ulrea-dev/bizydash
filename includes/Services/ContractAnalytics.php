<?php
namespace BizyDash\Services;

use BizyDash\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Contracts → Analytics. Answers: how many agreements go out and come back, how long people take, and where signers drop off.
 * "Sent" counts contracts by the day they were sent; "signed" by the day the last signer finished; the funnel follows every
 * signer invited in the period through opened → consented → code verified → signed. Contracts carry no money.
 */
class ContractAnalytics {

	private static function hours( $a, $b ) {
		return max( 0, ( strtotime( $b ) - strtotime( $a ) ) / HOUR_IN_SECONDS );
	}

	private static function stats( array $list ) {
		sort( $list );
		$n = count( $list );
		if ( ! $n ) {
			return array( 'count' => 0, 'avg_hours' => null, 'median_hours' => null );
		}
		$mid = $n % 2 ? $list[ ( $n - 1 ) / 2 ] : ( $list[ $n / 2 - 1 ] + $list[ $n / 2 ] ) / 2;
		return array( 'count' => $n, 'avg_hours' => round( array_sum( $list ) / $n, 1 ), 'median_hours' => round( $mid, 1 ) );
	}

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
		$ct   = Schema::table( 'contracts' );
		$sg   = Schema::table( 'contract_signers' );
		$ev   = Schema::table( 'contract_events' );

		$where = null !== $dept ? $wpdb->prepare( 'c.department_id=%d', $dept ) : '1=1';
		$rows  = (array) $wpdb->get_results( "SELECT c.id, c.status, c.client_id, c.template_id, c.department_id, c.sent_at, c.completed_at, cl.company_name, tp.name AS template_name FROM $ct c LEFT JOIN " . Schema::table( 'clients' ) . " cl ON cl.id=c.client_id LEFT JOIN " . Schema::table( 'contract_templates' ) . " tp ON tp.id=c.template_id WHERE $where AND c.status <> 'draft'", ARRAY_A ); // phpcs:ignore WordPress.DB
		$day = function ( $dt ) {
			return $dt ? substr( $dt, 0, 10 ) : '';
		};

		$bl  = FinanceAnalytics::buckets( $from, $to, $gran );
		$idx = array();
		foreach ( $bl as $i => $b ) {
			$idx[ $b['key'] ] = $i;
		}
		$sent_s = array_fill( 0, count( $bl ), 0 );
		$sign_s = array_fill( 0, count( $bl ), 0 );
		$stat   = array( 'sent' => 0, 'signed' => 0, 'waiting' => 0, 'declined' => 0, 'void' => 0, 'expired' => 0, 'cohort_signed' => 0, 'cohort_pending' => 0 );
		$cycle  = array();
		$byclient = array();
		$bytpl    = array();
		$bydept   = array();
		$waiting  = array();
		$ids_cohort = array();

		foreach ( $rows as $r ) {
			$sd = $day( $r['sent_at'] );
			$cd = $day( $r['completed_at'] );
			$st = $r['status'];
			if ( in_array( $st, array( 'sent', 'partially_signed' ), true ) ) {
				++$stat['waiting'];
				$waiting[] = $r;
			}
			if ( $sd && $sd >= $from && $sd <= $to ) {
				++$stat['sent'];
				$ids_cohort[] = (int) $r['id'];
				$k = FinanceAnalytics::bucket( $sd, $gran );
				isset( $idx[ $k ] ) && ++$sent_s[ $idx[ $k ] ];
				'signed' === $st && ++$stat['cohort_signed'];
				in_array( $st, array( 'sent', 'partially_signed' ), true ) && ++$stat['cohort_pending'];
				isset( $stat[ $st ] ) && in_array( $st, array( 'declined', 'void', 'expired' ), true ) && ++$stat[ $st ];
				$tn = $r['template_name'] ? $r['template_name'] : 'No template';
				$bytpl[ $tn ] = isset( $bytpl[ $tn ] ) ? $bytpl[ $tn ] : array( 'sent' => 0, 'signed' => 0, 'hours' => array() );
				++$bytpl[ $tn ]['sent'];
				$dk = (int) $r['department_id'];
				$bydept[ $dk ] = isset( $bydept[ $dk ] ) ? $bydept[ $dk ] : array( 'sent' => 0, 'signed' => 0, 'declined' => 0 );
				++$bydept[ $dk ]['sent'];
				'signed' === $st && ++$bydept[ $dk ]['signed'];
				'declined' === $st && ++$bydept[ $dk ]['declined'];
				'signed' === $st && ++$bytpl[ $tn ]['signed'];
			}
			if ( 'signed' === $st && $cd && $cd >= $from && $cd <= $to ) {
				++$stat['signed'];
				$k = FinanceAnalytics::bucket( $cd, $gran );
				isset( $idx[ $k ] ) && ++$sign_s[ $idx[ $k ] ];
				if ( $r['sent_at'] ) {
					$h        = self::hours( $r['sent_at'], $r['completed_at'] );
					$cycle[]  = $h;
					$cn       = $r['company_name'] ? $r['company_name'] : 'No client';
					$byclient[ $cn ] = isset( $byclient[ $cn ] ) ? $byclient[ $cn ] : array( 'id' => (int) $r['client_id'], 'hours' => array() );
					$byclient[ $cn ]['hours'][] = $h;
					$tn = $r['template_name'] ? $r['template_name'] : 'No template';
					$bytpl[ $tn ] = isset( $bytpl[ $tn ] ) ? $bytpl[ $tn ] : array( 'sent' => 0, 'signed' => 0, 'hours' => array() );
					$bytpl[ $tn ]['hours'][] = $h;
				}
			}
		}

		// Declined or voided in the period, from the audit log (there is no declined_at / voided_at column).
		$ids_all = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$dv = 0;
		if ( $ids_all ) {
			$dv = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT contract_id) FROM $ev WHERE event IN ('declined','voided') AND DATE(created_at) BETWEEN %s AND %s AND contract_id IN (" . implode( ',', $ids_all ) . ')', $from, $to ) ); // phpcs:ignore WordPress.DB
		}

		// Signer funnel and per-signer time, for signers invited in the period.
		$funnel = array( 'invited' => 0, 'opened' => 0, 'consented' => 0, 'verified' => 0, 'signed' => 0 );
		$signer_hours = array();
		if ( $ids_all ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT invited_at, viewed_at, consent_at, otp_verified_at, signed_at FROM $sg WHERE invited_at IS NOT NULL AND DATE(invited_at) BETWEEN %s AND %s AND contract_id IN (" . implode( ',', $ids_all ) . ')', $from, $to ), ARRAY_A ) as $s ) { // phpcs:ignore WordPress.DB
				++$funnel['invited'];
				$s['viewed_at'] && ++$funnel['opened'];
				$s['consent_at'] && ++$funnel['consented'];
				$s['otp_verified_at'] && ++$funnel['verified'];
				if ( $s['signed_at'] ) {
					++$funnel['signed'];
					$signer_hours[] = self::hours( $s['invited_at'], $s['signed_at'] );
				}
			}
		}

		$cs = self::stats( $cycle );
		$ss = self::stats( $signer_hours );
		$decided = $stat['cohort_signed'] + $stat['declined'] + $stat['void'] + $stat['expired'];

		$cl_rows = array();
		foreach ( $byclient as $name => $v ) {
			$s         = self::stats( $v['hours'] );
			$cl_rows[] = array( 'id' => $v['id'], 'name' => $name, 'signed' => $s['count'], 'avg_hours' => $s['avg_hours'] );
		}
		usort( $cl_rows, function ( $a, $b ) {
			return $b['avg_hours'] <=> $a['avg_hours'];
		} );
		$tp_rows = array();
		foreach ( $bytpl as $name => $v ) {
			$s         = self::stats( $v['hours'] );
			$tp_rows[] = array( 'name' => $name, 'sent' => $v['sent'], 'signed' => $v['signed'], 'rate_pct' => $v['sent'] ? (int) round( 100 * $v['signed'] / $v['sent'] ) : null, 'avg_hours' => $s['avg_hours'] );
		}
		usort( $tp_rows, function ( $a, $b ) {
			return $b['sent'] <=> $a['sent'];
		} );
		$dn = array( 0 => 'General / no department' );
		foreach ( (array) $wpdb->get_results( 'SELECT id, name FROM ' . Schema::table( 'brands' ), ARRAY_A ) as $b ) { // phpcs:ignore WordPress.DB
			$dn[ (int) $b['id'] ] = $b['name'];
		}
		$dp_rows = array();
		foreach ( $bydept as $id => $v ) {
			$dp_rows[] = array( 'id' => $id, 'name' => isset( $dn[ $id ] ) ? $dn[ $id ] : 'Unknown' ) + $v;
		}
		usort( $waiting, function ( $a, $b ) {
			return strcmp( (string) $a['sent_at'], (string) $b['sent_at'] );
		} );
		$wait_rows = array();
		foreach ( array_slice( $waiting, 0, 6 ) as $r ) {
			$names = (array) $wpdb->get_col( $wpdb->prepare( "SELECT name FROM $sg WHERE contract_id=%d AND status IN ('pending','viewed')", $r['id'] ) ); // phpcs:ignore WordPress.DB
			$wait_rows[] = array( 'id' => (int) $r['id'], 'client_name' => $r['company_name'], 'sent_at' => $r['sent_at'], 'days_waiting' => (int) floor( ( strtotime( $today ) - strtotime( substr( $r['sent_at'], 0, 10 ) ) ) / DAY_IN_SECONDS ), 'waiting_on' => implode( ', ', $names ) );
		}

		return array(
			'range'       => array( 'from' => $from, 'to' => $to ),
			'granularity' => $gran,
			'department'  => $dept,
			'stats'       => array(
				'sent'          => $stat['sent'],
				'signed'        => $stat['signed'],
				'waiting'       => $stat['waiting'],
				'declined_void' => $dv,
				'completion_pct' => $decided ? (int) round( 100 * $stat['cohort_signed'] / $decided ) : null,
				'cohort_pending' => $stat['cohort_pending'],
				'avg_hours'     => $cs['avg_hours'],
				'median_hours'  => $cs['median_hours'],
				'signer_avg_hours' => $ss['avg_hours'],
			),
			'buckets'     => wp_list_pluck( $bl, 'label' ),
			'flow'        => array( 'sent' => $sent_s, 'signed' => $sign_s ),
			'funnel'      => $funnel,
			'outcomes'    => array( 'signed' => $stat['cohort_signed'], 'waiting' => $stat['cohort_pending'], 'declined' => $stat['declined'], 'void' => $stat['void'], 'expired' => $stat['expired'] ),
			'slowest_clients' => array_slice( $cl_rows, 0, 8 ),
			'waiting'     => $wait_rows,
			'templates'   => $tp_rows,
			'departments' => $dp_rows,
		);
	}
}
