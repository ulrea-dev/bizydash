<?php
namespace BizyDash\Services;

use BizyDash\Database\Schema;
use BizyDash\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress logins for clients, ready for the client portal.
 *
 * A client is an organisation; its people are the contacts. Every contact with an email gets a WordPress user with the
 * BD Client role, and each user is linked (user meta `bizydash_client_id`) to the organisation(s) they belong to. One
 * person can belong to several clients. No email is sent and no password is shared: the portal will let people set
 * their own password.
 */
class Portal_Users {

	const META_CLIENT  = 'bizydash_client_id';
	const META_MANAGED = 'bizydash_portal_user';

	public static function enabled() {
		return false !== Settings::get( 'portal_auto_users' ) && (bool) Settings::get( 'portal_auto_users' );
	}

	/** @return array list of array( email, name ) */
	private static function wanted( $client_id ) {
		global $wpdb;
		$out = array();
		foreach ( Contacts::for_client( $client_id ) as $c ) {
			$e = sanitize_email( $c['email'] );
			if ( $e ) {
				$out[ strtolower( $e ) ] = array( 'email' => $e, 'name' => $c['name'] );
			}
		}
		if ( ! $out ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT company_name, contact_person, email FROM ' . Schema::table( 'clients' ) . ' WHERE id=%d', $client_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
			$e   = $row ? sanitize_email( $row['email'] ) : '';
			if ( $e ) {
				$out[ strtolower( $e ) ] = array( 'email' => $e, 'name' => $row['contact_person'] ? $row['contact_person'] : $row['company_name'] );
			}
		}
		return $out;
	}

	private static function login_for( $email ) {
		$base = sanitize_user( strstr( $email, '@', true ), true );
		$base = '' === $base ? 'client' : substr( $base, 0, 40 );
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . ++$i;
		}
		return $login;
	}

	/** @return int user id, or 0 when the person is staff / could not be created */
	public static function ensure_user( $email, $name, $client_id ) {
		$u = get_user_by( 'email', $email );
		if ( $u ) {
			if ( user_can( $u, 'manage_bizydash' ) || user_can( $u, 'manage_options' ) ) {
				return 0; // a staff member who is also someone's contact: leave their access alone
			}
			if ( ! in_array( 'bizydash_client', (array) $u->roles, true ) ) {
				$u->add_role( 'bizydash_client' );
			}
			$uid = (int) $u->ID;
		} else {
			$parts = preg_split( '/\s+/', trim( (string) $name ), 2 );
			$uid   = wp_insert_user( array(
				'user_login'   => self::login_for( $email ),
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => '' !== trim( (string) $name ) ? $name : $email,
				'first_name'   => $parts[0] ?? '',
				'last_name'    => $parts[1] ?? '',
				'role'         => 'bizydash_client',
			) );
			if ( is_wp_error( $uid ) ) {
				return 0;
			}
			update_user_meta( $uid, self::META_MANAGED, 1 );
			update_user_meta( $uid, 'show_admin_bar_front', 'false' );
		}
		if ( ! in_array( (int) $client_id, self::client_ids( $uid ), true ) ) {
			add_user_meta( $uid, self::META_CLIENT, (int) $client_id );
		}
		return $uid;
	}

	public static function client_ids( $user_id ) {
		return array_map( 'intval', (array) get_user_meta( (int) $user_id, self::META_CLIENT ) );
	}

	/** Make the client's logins match its contacts. Safe to call often. */
	public static function sync_client( $client_id ) {
		global $wpdb;
		if ( ! self::enabled() ) {
			return;
		}
		$client_id = (int) $client_id;
		$want      = self::wanted( $client_id );
		$keep      = array();
		foreach ( $want as $w ) {
			$uid = self::ensure_user( $w['email'], $w['name'], $client_id );
			if ( $uid ) {
				$keep[] = $uid;
			}
		}
		// People no longer among the contacts lose the link to this client (their login is kept).
		foreach ( self::users_for_client( $client_id, false ) as $uid ) {
			if ( ! in_array( $uid, $keep, true ) && get_user_meta( $uid, self::META_MANAGED, true ) ) {
				delete_user_meta( $uid, self::META_CLIENT, $client_id );
			}
		}
	}

	/** The client was deleted: drop every link to it. */
	public static function unlink_client( $client_id ) {
		foreach ( self::users_for_client( $client_id, false ) as $uid ) {
			delete_user_meta( $uid, self::META_CLIENT, (int) $client_id );
		}
	}

	public static function users_for_client( $client_id, $details = true ) {
		global $wpdb;
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM $wpdb->usermeta WHERE meta_key=%s AND meta_value=%d", self::META_CLIENT, (int) $client_id ) ) ); // phpcs:ignore WordPress.DB
		if ( ! $details ) {
			return $ids;
		}
		$out = array();
		foreach ( $ids as $id ) {
			$u = get_userdata( $id );
			if ( $u ) {
				$out[] = array( 'id' => $id, 'name' => $u->display_name, 'email' => $u->user_email, 'login' => $u->user_login );
			}
		}
		return $out;
	}

	/**
	 * Create logins for existing clients, a slice at a time. Returns array( processed, next ) where next is 0 when done.
	 */
	public static function sync_all( $after = 0, $limit = 200 ) {
		global $wpdb;
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'clients' ) . ' WHERE id > %d ORDER BY id ASC LIMIT %d', (int) $after, (int) $limit ) ) ); // phpcs:ignore WordPress.DB
		foreach ( $ids as $id ) {
			self::sync_client( $id );
		}
		$next = count( $ids ) >= $limit ? end( $ids ) : 0;
		return array( 'processed' => count( $ids ), 'next' => $next );
	}

	/** Slice for the upgrade routine and WP-Cron: carries on by itself until every client is done. */
	public static function cron_sync( $after = 0 ) {
		$r = self::sync_all( (int) $after, 150 );
		if ( $r['next'] ) {
			wp_schedule_single_event( time() + 30, 'bizydash_sync_portal_users', array( (int) $r['next'] ) );
		}
	}

	public static function stats() {
		global $wpdb;
		$clients = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'clients' ) ); // phpcs:ignore WordPress.DB
		$linked  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT meta_value) FROM $wpdb->usermeta WHERE meta_key=%s", self::META_CLIENT ) ); // phpcs:ignore WordPress.DB
		$users   = count( get_users( array( 'role' => 'bizydash_client', 'fields' => 'ID' ) ) );
		return array( 'clients' => $clients, 'clients_with_login' => $linked, 'portal_users' => $users );
	}
}
