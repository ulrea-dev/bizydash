<?php
namespace BizyDash;

use BizyDash\Database\Schema;
use BizyDash\Services\Expenses;
use BizyDash\Services\Invoices;

defined( 'ABSPATH' ) || exit;

class Cron {

	const HOOK = 'bizydash_daily';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'daily', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** Roll invoice statuses forward (due/overdue) and flag renewals that are coming up. */
	public static function run() {
		global $wpdb;

		if ( Modules::enabled( 'finance' ) ) {
			$inv = Schema::table( 'invoices' );
			$ids = $wpdb->get_col( "SELECT id FROM $inv WHERE status IN ('sent','due','partially_paid','overdue')" ); // phpcs:ignore WordPress.DB
			foreach ( (array) $ids as $id ) {
				Invoices::refresh_status( (int) $id );
			}

			Expenses::post_due();

			$ren  = Schema::table( 'renewals' );
			$days = (int) Settings::get( 'due_soon_days' );
			$soon = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . " +$days days" ) );
			$wpdb->query( $wpdb->prepare( "UPDATE $ren SET status='due' WHERE status='upcoming' AND due_date IS NOT NULL AND due_date <= %s", $soon ) ); // phpcs:ignore WordPress.DB
		}
	}
}
