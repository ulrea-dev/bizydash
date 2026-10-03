<?php
/**
 * Removes BizyDash data on uninstall, only when the "delete on uninstall" setting is enabled.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$settings = get_option( 'bizydash_settings', array() );
if ( empty( $settings['delete_on_uninstall'] ) ) {
	return;
}

$tables = array( 'brands', 'clients', 'client_brands', 'services', 'client_services', 'renewals', 'charges', 'invoices', 'invoice_items', 'invoice_installments', 'payments', 'activity', 'lookups', 'client_contacts', 'team_members', 'email_log', 'projects', 'tasks', 'project_phases', 'pay_allocations', 'pay_items', 'payouts', 'expenses', 'recurring_expenses', 'contract_templates', 'contracts', 'contract_signers', 'contract_events', 'contract_otps' );
foreach ( $tables as $table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'bizydash_' . $table . '`' ); // phpcs:ignore WordPress.DB
}

delete_option( 'bizydash_settings' );
delete_option( 'bizydash_mail' );
delete_option( 'bizydash_db_version' );
delete_option( 'bizydash_modules' );
remove_role( 'bizydash_staff' );

$role = get_role( 'administrator' );
if ( $role ) {
	$role->remove_cap( 'manage_bizydash' );
}
