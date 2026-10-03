<?php
namespace BizyDash;

defined( 'ABSPATH' ) || exit;

class Install {

	public static function activate() {
		self::maybe_upgrade( true );
		Cron::schedule();
	}

	/**
	 * Runs on every request (cheap option compare) so schema changes migrate without re-activation.
	 */
	public static function maybe_upgrade( $force = false ) {
		if ( ! $force && get_option( 'bizydash_db_version' ) === Database\Schema::VERSION ) {
			return;
		}
		$from = (int) get_option( 'bizydash_db_version' );
		Database\Schema::install();
		Lookups::seed();
		self::migrate( $from );
		self::grant_capability();
		update_option( 'bizydash_db_version', Database\Schema::VERSION );
	}

	/** Data back-fills for schema upgrades (columns themselves are added by dbDelta). */
	private static function migrate( $from ) {
		global $wpdb;
		if ( $from > 0 && $from < 19 ) {
			// Existing subscriptions, retainers and one-off bills get the department they already counted under
			// (the catalog item's, else the client's), and an invoice whose lines all share one department is attached to it.
			$cs  = Database\Schema::table( 'client_services' );
			$ch  = Database\Schema::table( 'charges' );
			$sv  = Database\Schema::table( 'services' );
			$cb  = Database\Schema::table( 'client_brands' );
			$inv = Database\Schema::table( 'invoices' );
			$it  = Database\Schema::table( 'invoice_items' );
			$cli = "COALESCE((SELECT brand_id FROM $cb WHERE client_id = %s.client_id ORDER BY is_primary DESC, id ASC LIMIT 1), 0)";
			$wpdb->query( "UPDATE $cs x LEFT JOIN $sv s ON s.id = x.service_id SET x.department_id = IF(COALESCE(s.department_id,0) > 0, s.department_id, " . sprintf( $cli, 'x' ) . ') WHERE x.department_id = 0' ); // phpcs:ignore WordPress.DB
			$wpdb->query( "UPDATE $ch x LEFT JOIN $cs c ON c.id = x.client_service_id LEFT JOIN $sv s ON s.id = IF(x.service_id > 0, x.service_id, c.service_id) SET x.department_id = IF(COALESCE(s.department_id,0) > 0, s.department_id, " . sprintf( $cli, 'x' ) . ') WHERE x.department_id = 0' ); // phpcs:ignore WordPress.DB
			$wpdb->query( "UPDATE $inv i SET i.department_id = (SELECT MIN(department_id) FROM $it WHERE invoice_id = i.id) WHERE i.department_id = 0 AND (SELECT COUNT(DISTINCT department_id) FROM $it WHERE invoice_id = i.id) = 1 AND (SELECT MIN(department_id) FROM $it WHERE invoice_id = i.id) > 0" ); // phpcs:ignore WordPress.DB
		}
		if ( $from > 0 && $from < 20 ) {
			// Payments count under their invoice's department, payouts under the person's.
			$pay = Database\Schema::table( 'payments' );
			foreach ( (array) $wpdb->get_col( "SELECT DISTINCT invoice_id FROM $pay" ) as $iid ) { // phpcs:ignore WordPress.DB
				Services\Invoices::restamp_payments( (int) $iid );
			}
			$po = Database\Schema::table( 'payouts' );
			$tm = Database\Schema::table( 'team_members' );
			$wpdb->query( "UPDATE $po x JOIN $tm m ON m.id = x.team_member_id SET x.department_id = m.department_id WHERE x.department_id = 0" ); // phpcs:ignore WordPress.DB
		}
		if ( $from > 0 && $from < 18 ) {
			// Older finished tasks and phases have no completion time: use their last update so analytics can count them.
			foreach ( array( 'tasks', 'project_phases' ) as $n ) {
				$tbl = Database\Schema::table( $n );
				$wpdb->query( "UPDATE $tbl SET completed_at = updated_at WHERE status = 'done' AND completed_at IS NULL" ); // phpcs:ignore WordPress.DB
			}
		}
		if ( $from > 0 && $from < 5 ) {
			$cl = Database\Schema::table( 'clients' );
			$ct = Database\Schema::table( 'client_contacts' );
			$now = current_time( 'mysql' );
			$wpdb->query( "INSERT INTO $ct (client_id, name, email, phone, kind, sort_order, created_at, updated_at) SELECT c.id, IF(c.contact_person <> '', c.contact_person, c.company_name), c.email, c.phone, 'primary', 0, '$now', '$now' FROM $cl c WHERE (c.email <> '' OR c.contact_person <> '') AND NOT EXISTS (SELECT 1 FROM $ct x WHERE x.client_id = c.id)" ); // phpcs:ignore WordPress.DB
		}
		if ( $from > 0 && $from < 4 ) {
			$sv = Database\Schema::table( 'services' );
			$ch = Database\Schema::table( 'charges' );
			$cs = Database\Schema::table( 'client_services' );
			$wpdb->query( "UPDATE $sv SET kind = 'one_off' WHERE default_billing_period IN ('', 'none')" ); // phpcs:ignore WordPress.DB
			$wpdb->query( "UPDATE $sv SET kind = 'recurring' WHERE default_billing_period NOT IN ('', 'none')" ); // phpcs:ignore WordPress.DB
			$wpdb->query( "UPDATE $ch c JOIN $cs cs ON cs.id = c.client_service_id SET c.service_id = cs.service_id WHERE c.service_id = 0 AND c.client_service_id > 0" ); // phpcs:ignore WordPress.DB
		}
		if ( $from > 0 && $from < 12 ) {
			Services\Finance::backfill_costs(); // older invoice lines get a cost and department
		}
		if ( $from > 0 && $from < 10 ) {
			$rn = Database\Schema::table( 'renewals' );
			$cs = Database\Schema::table( 'client_services' );
			$lk = Database\Schema::table( 'lookups' );
			$wpdb->query( "UPDATE $rn r JOIN $cs c ON c.id = r.client_service_id SET r.subject = c.subject WHERE r.subject = '' AND c.subject <> ''" ); // phpcs:ignore WordPress.DB
			foreach ( array( 'separately_billed' => array( 'Separately billed', 'Billed separately' ), 'cost_only' => array( 'Cost only', 'Not billed (our cost)' ), 'no_charge' => array( 'No charge', 'Not billed (free)' ) ) as $key => $lab ) {
				$wpdb->query( $wpdb->prepare( "UPDATE $lk SET label = %s WHERE type = 'billing_treatment' AND item_key = %s AND label = %s", $lab[1], $key, $lab[0] ) ); // phpcs:ignore WordPress.DB
			}
		}
		if ( $from > 0 && $from < 9 ) {
			$inv = Database\Schema::table( 'invoices' );
			$it  = Database\Schema::table( 'invoice_items' );
			$wpdb->query( "UPDATE $inv i SET i.title = LEFT(COALESCE((SELECT description FROM $it WHERE invoice_id = i.id ORDER BY id LIMIT 1), ''), 190) WHERE i.title = ''" ); // phpcs:ignore WordPress.DB
			$mail = get_option( 'bizydash_mail' );
			if ( is_array( $mail ) ) {
				if ( isset( $mail['invoice_subject'] ) && 'Invoice {{invoice_number}} from {{business_name}}' === $mail['invoice_subject'] ) {
					$mail['invoice_subject'] = 'Invoice {{invoice_number}} — {{invoice_title}}';
				}
				if ( isset( $mail['invoice_message'] ) ) {
					$mail['invoice_message'] = str_replace( 'Please find invoice {{invoice_number}} for {{amount_due}}, due {{due_date}}.', 'Please find invoice {{invoice_number}} for {{invoice_title}}. The amount due is {{amount_due}}, payable by {{due_date}}.', $mail['invoice_message'] );
				}
				update_option( 'bizydash_mail', $mail );
			}
		}
		if ( $from > 0 && $from < 7 ) {
			$sv = Database\Schema::table( 'services' );
			$wpdb->query( "UPDATE $sv SET type = 'service', pricing = 'fixed' WHERE type = '' OR pricing = ''" ); // phpcs:ignore WordPress.DB
		}
		if ( $from > 0 && $from < 6 ) {
			$it = Database\Schema::table( 'invoice_items' );
			$wpdb->query( "UPDATE $it SET kind = IF(quantity = 1, 'fixed', 'quantity')" ); // phpcs:ignore WordPress.DB
		}
		if ( $from > 0 && $from < 3 ) {
			$inv = Database\Schema::table( 'invoices' );
			$it  = Database\Schema::table( 'invoice_items' );
			$wpdb->query( "UPDATE $inv SET subtotal = amount WHERE subtotal = 0 AND amount > 0" ); // phpcs:ignore WordPress.DB
			$wpdb->query( "UPDATE $it SET unit_price = amount WHERE unit_price = 0 AND amount > 0" ); // phpcs:ignore WordPress.DB
		}
	}

	private static function grant_capability() {
		$role = get_role( 'administrator' );
		foreach ( array( 'manage_bizydash', 'bizydash_finance' ) as $cap ) {
			if ( $role && ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}
		// A role for team members who work in BizyDash but should never see WordPress itself.
		$staff = get_role( 'bizydash_staff' );
		if ( ! $staff ) {
			add_role( 'bizydash_staff', 'BizyDash team member', array( 'read' => true, 'upload_files' => true, 'manage_bizydash' => true ) );
		} elseif ( ! $staff->has_cap( 'manage_bizydash' ) ) {
			$staff->add_cap( 'manage_bizydash' );
		}
	}
}
