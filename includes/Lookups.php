<?php
namespace BizyDash;

use BizyDash\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Configurable option lists (client types, billing periods, billing treatments, ...).
 */
class Lookups {

	public static function defaults() {
		return array(
			'client_type'       => array(
				array( 'retainer', 'Retainer', 0, '#2563eb' ),
				array( 'adhoc', 'Ad-hoc', 0, '#7c3aed' ),
			),
			'client_status'     => array(
				array( 'active', 'Active', 0, '#16a34a' ),
				array( 'paused', 'Paused', 0, '#d97706' ),
				array( 'inactive', 'Inactive', 0, '#6b7280' ),
			),
			'brand_type'        => array(
				array( 'product', 'Product', 0, '#0891b2' ),
				array( 'service', 'Service', 0, '#db2777' ),
			),
			'billing_period'    => array(
				array( 'none', 'One-time', 0, '' ),
				array( 'monthly', 'Monthly', 1, '' ),
				array( 'quarterly', 'Quarterly', 3, '' ),
				array( 'semiannual', 'Semi-annually', 6, '' ),
				array( 'annual', 'Annually', 12, '' ),
				array( 'custom', 'Custom', 0, '' ),
			),
			'expense_category'  => array(
				array( 'software', 'Software & tools', 0, '#2563eb' ),
				array( 'hosting', 'Hosting & domains', 0, '#0891b2' ),
				array( 'rent', 'Rent & utilities', 0, '#d97706' ),
				array( 'marketing', 'Marketing & ads', 0, '#db2777' ),
				array( 'equipment', 'Equipment', 0, '#7c3aed' ),
				array( 'professional', 'Professional fees', 0, '#16a34a' ),
				array( 'travel', 'Travel & transport', 0, '#6b7280' ),
				array( 'bank', 'Bank & payment fees', 0, '#6b7280' ),
				array( 'office', 'Office & supplies', 0, '#6b7280' ),
				array( 'training', 'Training', 0, '#6b7280' ),
				array( 'other', 'Other', 0, '#6b7280' ),
			),
			'billing_treatment' => array(
				array( 'included_in_retainer', 'Included in retainer', 0, '#2563eb' ),
				array( 'separately_billed', 'Billed separately', 0, '#16a34a' ),
				array( 'cost_only', 'Not billed (our cost)', 0, '#d97706' ),
				array( 'no_charge', 'Not billed (free)', 0, '#6b7280' ),
				array( 'custom', 'Custom', 0, '#7c3aed' ),
			),
		);
	}

	/** Insert any missing system defaults (never overwrites edits). */
	public static function seed() {
		global $wpdb;
		$table = Schema::table( 'lookups' );
		foreach ( self::defaults() as $type => $items ) {
			foreach ( $items as $i => $it ) {
				$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE type=%s AND item_key=%s", $type, $it[0] ) ); // phpcs:ignore WordPress.DB
				if ( $exists ) {
					continue;
				}
				$wpdb->insert(
					$table,
					array(
						'type'       => $type,
						'item_key'   => $it[0],
						'label'      => $it[1],
						'months'     => $it[2],
						'color'      => $it[3],
						'sort_order' => $i,
						'is_active'  => 1,
						'is_system'  => 1,
					)
				);
			}
		}
	}

	/** All lookups grouped by type. */
	public static function grouped() {
		global $wpdb;
		$table = Schema::table( 'lookups' );
		$rows  = $wpdb->get_results( "SELECT * FROM $table ORDER BY type ASC, sort_order ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r['type'] ][] = array(
				'id'         => (int) $r['id'],
				'key'        => $r['item_key'],
				'label'      => $r['label'],
				'color'      => $r['color'],
				'months'     => (int) $r['months'],
				'sort_order' => (int) $r['sort_order'],
				'is_active'  => (bool) $r['is_active'],
				'is_system'  => (bool) $r['is_system'],
			);
		}
		return $out;
	}

	public static function months_for( $period ) {
		global $wpdb;
		$table = Schema::table( 'lookups' );
		$m     = $wpdb->get_var( $wpdb->prepare( "SELECT months FROM $table WHERE type='billing_period' AND item_key=%s", $period ) ); // phpcs:ignore WordPress.DB
		return (int) $m;
	}
}
