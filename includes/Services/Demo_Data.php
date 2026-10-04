<?php
namespace BizyDash\Services;

use BizyDash\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Optional sample data, so a new install can be explored before real data goes in. Every row created is
 * remembered, so "Remove sample data" deletes exactly that and nothing the customer added.
 */
class Demo_Data {

	const OPTION = 'bizydash_demo';

	public static function status() {
		$ids = get_option( self::OPTION, array() );
		$ids = is_array( $ids ) ? $ids : array();
		return array( 'loaded' => ! empty( $ids ), 'counts' => array_map( 'count', $ids ) );
	}

	private static function add( $table, array $row ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$row = array_merge( array( 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ), $row );
		if ( 'invoice_items' === $table || 'client_brands' === $table ) {
			unset( $row['created_by'], $row['created_at'], $row['updated_at'] );
		}
		$wpdb->insert( Schema::table( $table ), $row );
		$id = (int) $wpdb->insert_id;
		self::$ids[ $table ][] = $id;
		return $id;
	}

	private static $ids = array();

	/** @return array|\WP_Error */
	public static function load( $force = false ) {
		global $wpdb;
		if ( self::status()['loaded'] ) {
			return new \WP_Error( 'bd_demo_loaded', 'Sample data is already loaded.', array( 'status' => 409 ) );
		}
		if ( ! $force && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'clients' ) ) > 0 ) { // phpcs:ignore WordPress.DB
			return new \WP_Error( 'bd_demo_notempty', 'Sample data can only be added to an empty workspace.', array( 'status' => 409 ) );
		}
		self::$ids = array();
		$day = function ( $offset ) {
			return gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' ' . $offset . ' days' ) );
		};

		$web  = self::add( 'brands', array( 'name' => 'Web Studio (Sample)', 'type' => '', 'description' => 'Websites and hosting', 'status' => 'active' ) );
		$mkt  = self::add( 'brands', array( 'name' => 'Marketing (Sample)', 'type' => '', 'description' => 'Campaigns and content', 'status' => 'active' ) );
		$cl   = array();
		foreach ( array( array( 'Bright Foods (Sample)', 'Ada Obi', 'ada@bright.example', $web ), array( 'Northwind Logistics (Sample)', 'Sam Eze', 'sam@northwind.example', $mkt ), array( 'Lagos Dental (Sample)', 'Dr Tunde', 'tunde@dental.example', $web ) ) as $c ) {
			$id = self::add( 'clients', array( 'company_name' => $c[0], 'contact_person' => $c[1], 'email' => $c[2], 'status' => 'active', 'currency' => 'NGN' ) );
			self::add( 'client_brands', array( 'client_id' => $id, 'brand_id' => $c[3], 'is_primary' => 1 ) );
			self::add( 'client_contacts', array( 'client_id' => $id, 'name' => $c[1], 'email' => $c[2], 'kind' => 'primary', 'sort_order' => 0 ) );
			$cl[] = $id;
		}
		$t1 = self::add( 'team_members', array( 'name' => 'Zara Bello (Sample)', 'email' => 'zara@sample.example', 'role' => 'Designer', 'kind' => 'staff', 'status' => 'active', 'department_id' => $web, 'currency' => 'NGN' ) );
		$t2 = self::add( 'team_members', array( 'name' => 'Tunde Okafor (Sample)', 'email' => 'tunde@sample.example', 'role' => 'Developer', 'kind' => 'staff', 'status' => 'active', 'department_id' => $web, 'currency' => 'NGN' ) );

		$p1 = self::add( 'projects', array( 'client_id' => $cl[0], 'name' => 'Website redesign', 'status' => 'active', 'start_date' => $day( -20 ), 'due_date' => $day( 25 ), 'department_id' => $web, 'price' => 650000, 'currency' => 'NGN', 'owner_id' => $t1 ) );
		$ph1 = self::add( 'project_phases', array( 'project_id' => $p1, 'name' => 'Design', 'sort_order' => 1, 'status' => 'done', 'due_date' => $day( -5 ), 'price' => 250000, 'department_id' => $web ) );
		$ph2 = self::add( 'project_phases', array( 'project_id' => $p1, 'name' => 'Build', 'sort_order' => 2, 'status' => 'doing', 'due_date' => $day( 20 ), 'price' => 400000, 'department_id' => $web ) );
		self::add( 'tasks', array( 'project_id' => $p1, 'client_id' => $cl[0], 'phase_id' => $ph1, 'title' => 'Homepage mock-up', 'status' => 'done', 'priority' => 'normal', 'assignee_id' => $t1, 'department_id' => $web ) );
		self::add( 'tasks', array( 'project_id' => $p1, 'client_id' => $cl[0], 'phase_id' => $ph2, 'title' => 'Build the home page', 'status' => 'doing', 'priority' => 'high', 'due_date' => $day( 7 ), 'assignee_id' => $t2, 'department_id' => $web ) );
		self::add( 'tasks', array( 'project_id' => $p1, 'client_id' => $cl[0], 'phase_id' => $ph2, 'title' => 'Contact form', 'status' => 'todo', 'priority' => 'normal', 'due_date' => $day( 14 ), 'assignee_id' => $t2, 'department_id' => $web ) );
		self::add( 'projects', array( 'client_id' => $cl[1], 'name' => 'Q4 campaign', 'status' => 'planned', 'start_date' => $day( 5 ), 'due_date' => $day( 60 ), 'department_id' => $mkt, 'price' => 300000, 'currency' => 'NGN' ) );

		$invs = array(
			array( $cl[0], 'SAMPLE-0001', 'Website design — deposit', 325000, 325000, -30, -16, $web ),
			array( $cl[1], 'SAMPLE-0002', 'Monthly retainer', 150000, 60000, -12, -2, $mkt ),
			array( $cl[2], 'SAMPLE-0003', 'Website hosting (annual)', 85000, 0, -3, 11, $web ),
			array( $cl[0], 'SAMPLE-0004', 'Website design — balance', 325000, 0, 0, 14, $web ),
		);
		foreach ( $invs as $v ) {
			$id = self::add( 'invoices', array( 'client_id' => $v[0], 'invoice_number' => $v[1], 'title' => $v[2], 'amount' => $v[3], 'subtotal' => $v[3], 'currency' => 'NGN', 'issue_date' => $day( $v[5] ), 'due_date' => $day( $v[6] ), 'status' => 'sent', 'discount_type' => 'none', 'department_id' => $v[7], 'notes' => 'Sample data' ) );
			self::add( 'invoice_items', array( 'invoice_id' => $id, 'source_type' => 'manual', 'kind' => 'fixed', 'description' => $v[2], 'quantity' => 1, 'unit_price' => $v[3], 'amount' => $v[3], 'department_id' => $v[7] ) );
			Invoices::ensure_token( $id );
			if ( $v[4] > 0 ) {
				self::add( 'payments', array( 'invoice_id' => $id, 'client_id' => $v[0], 'amount' => $v[4], 'currency' => 'NGN', 'paid_on' => $day( $v[5] + 3 ), 'reference' => 'Sample payment', 'department_id' => $v[7] ) );
			}
			Invoices::refresh_status( $id );
		}
		foreach ( array( array( 'Hosting for staging servers', 'Hosting', 42000, -9 ), array( 'Design software subscription', 'Software', 28000, -4 ), array( 'Facebook ads', 'Marketing', 60000, -2 ) ) as $e ) {
			self::add( 'expenses', array( 'spent_on' => $day( $e[3] ), 'amount' => $e[2], 'currency' => 'NGN', 'category' => $e[1], 'description' => $e[0], 'status' => 'paid' ) );
		}
		update_option( self::OPTION, self::$ids, false );
		return self::status();
	}

	/** Delete exactly what load() created, plus anything attached to it. */
	public static function clear() {
		global $wpdb;
		$ids = get_option( self::OPTION, array() );
		if ( ! is_array( $ids ) || ! $ids ) {
			return self::status();
		}
		$in = function ( $t ) use ( $ids ) {
			return implode( ',', array_map( 'intval', $ids[ $t ] ?? array( 0 ) ) );
		};
		$del = function ( $t, $where ) use ( $wpdb ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $t ) . ' WHERE ' . $where ); // phpcs:ignore WordPress.DB
		};
		$inv = $in( 'invoices' );
		foreach ( array( 'payments', 'invoice_items', 'invoice_installments' ) as $t ) {
			$del( $t, "invoice_id IN ($inv)" );
		}
		$del( 'invoices', "id IN ($inv)" );
		$prj = $in( 'projects' );
		$del( 'tasks', "project_id IN ($prj) OR id IN (" . $in( 'tasks' ) . ')' );
		$del( 'project_phases', "project_id IN ($prj)" );
		$del( 'projects', "id IN ($prj)" );
		$cl = $in( 'clients' );
		foreach ( array( 'client_brands', 'client_contacts', 'client_services', 'charges', 'renewals' ) as $t ) {
			$del( $t, "client_id IN ($cl)" );
		}
		$del( 'activity', "client_id IN ($cl)" );
		$del( 'clients', "id IN ($cl)" );
		$del( 'expenses', 'id IN (' . $in( 'expenses' ) . ')' );
		$del( 'team_members', 'id IN (' . $in( 'team_members' ) . ')' );
		$del( 'brands', 'id IN (' . $in( 'brands' ) . ')' );
		delete_option( self::OPTION );
		return self::status();
	}
}
