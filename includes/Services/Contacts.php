<?php
namespace BizyDash\Services;

use BizyDash\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Client contact people: primary contacts receive invoices, secondary contacts are copied. */
class Contacts {

	public static function for_client( $client_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, client_id, name, email, phone, job_title, kind, sort_order FROM " . Schema::table( 'client_contacts' ) . " WHERE client_id=%d ORDER BY kind='primary' DESC, sort_order ASC, id ASC", $client_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as &$r ) {
			$r['id']        = (int) $r['id'];
			$r['client_id'] = (int) $r['client_id'];
		}
		return (array) $rows;
	}

	/** Keep the legacy contact columns on the client row mirroring its first primary contact. */
	public static function sync_client( $client_id ) {
		global $wpdb;
		$list    = self::for_client( $client_id );
		$primary = null;
		foreach ( $list as $c ) {
			if ( 'primary' === $c['kind'] && '' !== $c['email'] ) {
				$primary = $c;
				break;
			}
		}
		if ( ! $primary && $list ) {
			$primary = $list[0];
		}
		$wpdb->update(
			Schema::table( 'clients' ),
			array( 'contact_person' => $primary ? $primary['name'] : '', 'email' => $primary ? $primary['email'] : '', 'phone' => $primary ? $primary['phone'] : '' ),
			array( 'id' => (int) $client_id )
		);
	}

	/**
	 * Default invoice recipients for a client: primary contacts → To, secondary → Cc.
	 * Falls back to the client's main email so nothing silently has no recipient.
	 *
	 * @return array{to: array, cc: array}
	 */
	public static function recipients( $client_id ) {
		global $wpdb;
		$to = array();
		$cc = array();
		foreach ( self::for_client( $client_id ) as $c ) {
			if ( '' === $c['email'] ) {
				continue;
			}
			$row = array( 'name' => $c['name'], 'email' => $c['email'], 'job_title' => $c['job_title'] );
			if ( 'primary' === $c['kind'] ) {
				$to[] = $row;
			} else {
				$cc[] = $row;
			}
		}
		if ( ! $to ) {
			$cl = $wpdb->get_row( $wpdb->prepare( 'SELECT contact_person, email FROM ' . Schema::table( 'clients' ) . ' WHERE id=%d', $client_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
			if ( $cl && '' !== $cl['email'] ) {
				$to[] = array( 'name' => $cl['contact_person'], 'email' => $cl['email'], 'job_title' => '' );
			}
		}
		return array( 'to' => $to, 'cc' => $cc );
	}

	/** Active team members who are copied on invoices by default. */
	public static function team( $only_cc = false ) {
		global $wpdb;
		$where = "status='active'" . ( $only_cc ? ' AND cc_invoices=1' : '' );
		return (array) $wpdb->get_results( 'SELECT id, name, email, role, cc_invoices FROM ' . Schema::table( 'team_members' ) . " WHERE $where ORDER BY name", ARRAY_A ); // phpcs:ignore WordPress.DB
	}
}
