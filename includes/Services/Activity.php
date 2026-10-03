<?php
namespace BizyDash\Services;

use BizyDash\Database\Schema;

defined( 'ABSPATH' ) || exit;

class Activity {

	public static function log( $entity, $entity_id, $client_id, $action, $summary, array $meta = array() ) {
		global $wpdb;
		$wpdb->insert(
			Schema::table( 'activity' ),
			array(
				'entity_type' => $entity,
				'entity_id'   => (int) $entity_id,
				'client_id'   => (int) $client_id,
				'action'      => $action,
				'summary'     => mb_substr( $summary, 0, 500 ),
				'meta'        => $meta ? wp_json_encode( $meta ) : '',
				'user_id'     => get_current_user_id(),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
		);
	}
}
