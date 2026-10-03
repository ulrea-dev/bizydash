<?php
namespace BizyDash\Services;

use BizyDash\Database\Schema;
use BizyDash\Lookups;

defined( 'ABSPATH' ) || exit;

/**
 * DEPRECATED. A client's retainer used to be mirrored from the client form into one hidden recurring service.
 * Retainers are now ordinary client services of a service-type catalog item (several per client, one per
 * thing managed: "Website management — Site A"). Existing mirrored rows stay as normal retainers; nothing
 * here runs on client save any more.
 */
class Retainers {

	const OPTION = 'bizydash_retainer_service_id';

	/** The catalogue service that represents retainers (created on first use). */
	public static function service_id() {
		global $wpdb;
		$tbl = Schema::table( 'services' );
		$id  = (int) get_option( self::OPTION );
		if ( $id && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tbl WHERE id=%d", $id ) ) ) { // phpcs:ignore WordPress.DB
			return $id;
		}
		$now = current_time( 'mysql' );
		$wpdb->insert( $tbl, array( 'name' => 'Retainer', 'description' => 'Recurring agency retainer (managed from each client\'s retainer settings).', 'default_billing_period' => 'monthly', 'default_billing_treatment' => 'separately_billed', 'status' => 'active', 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
		$id = (int) $wpdb->insert_id;
		update_option( self::OPTION, $id );
		return $id;
	}

	private static function first_due( $period ) {
		$today = Billing::today();
		if ( 'monthly' === $period ) {
			return gmdate( 'Y-m-01', strtotime( $today . ' +1 month' ) );
		}
		$months = Lookups::months_for( $period );
		return Billing::add_months( $today, max( 1, $months ) );
	}

	/** Create / update / retire the retainer service for a client row. */
	public static function sync( array $client ) {
		global $wpdb;
		$cs_tbl = Schema::table( 'client_services' );
		$amount = (float) $client['retainer_amount'];
		$period = (string) $client['retainer_period'];
		$want   = 'retainer' === $client['client_type'] && $amount > 0 && $period && 'none' !== $period && 'active' === $client['status'];

		$sid = self::service_id();
		$cs  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $cs_tbl WHERE client_id=%d AND service_id=%d ORDER BY id DESC LIMIT 1", $client['id'], $sid ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$now = current_time( 'mysql' );

		if ( ! $want ) {
			if ( $cs && 'active' === $cs['status'] ) {
				$wpdb->update( $cs_tbl, array( 'status' => 'cancelled', 'updated_at' => $now ), array( 'id' => $cs['id'] ) );
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'renewals' ) . " WHERE client_service_id=%d AND status IN ('upcoming','due') AND invoice_id=0", $cs['id'] ) ); // phpcs:ignore WordPress.DB
			}
			return;
		}

		$data = array(
			'client_id'         => (int) $client['id'],
			'service_id'        => $sid,
			'provider'          => 'In-house',
			'status'            => 'active',
			'cost'              => 0,
			'client_price'      => $amount,
			'currency'          => $client['currency'],
			'billing_treatment' => 'separately_billed',
			'billing_period'    => $period,
			'notes'             => 'Managed from the client\'s retainer settings.',
			'updated_at'        => $now,
		);
		if ( $cs ) {
			if ( empty( $cs['next_renewal_date'] ) ) {
				$data['next_renewal_date'] = self::first_due( $period );
			}
			$wpdb->update( $cs_tbl, $data, array( 'id' => $cs['id'] ) );
			$id = (int) $cs['id'];
		} else {
			$data += array( 'start_date' => Billing::today(), 'next_renewal_date' => self::first_due( $period ), 'created_by' => get_current_user_id(), 'created_at' => $now );
			$wpdb->insert( $cs_tbl, $data );
			$id = (int) $wpdb->insert_id;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $cs_tbl WHERE id=%d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		Renewals::sync_from_client_service( $row );
	}

	/** One-off: bring every existing retainer client in line. */
	public static function sync_all() {
		return 0; // retired — see class docblock
		global $wpdb;
		$n = 0;
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'clients' ) . " WHERE client_type='retainer'", ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB
			self::sync( $c );
			++$n;
		}
		return $n;
	}
}
