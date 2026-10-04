<?php
namespace BizyDash\Backup;

use BizyDash\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * What belongs to which module, in parent-first order. One place that says which tables, options and files a
 * backup covers, so export, restore and the "what's inside" report agree.
 */
class Module_Map {

	/** Tables per module (unprefixed). `optional` ones can be left out; `excluded` ones are never backed up. */
	public static function modules() {
		return array(
			'core'      => array(
				'label'    => 'Clients, departments & team',
				'desc'     => 'Departments, clients and their contacts, team members, lists & options, and your settings.',
				'tables'   => array( 'brands', 'lookups', 'clients', 'client_brands', 'client_contacts', 'team_members', 'activity' ),
				'optional' => array( 'activity' ),
				'excluded' => array(),
			),
			'work'      => array(
				'label'    => 'Work',
				'desc'     => 'Projects, phases and tasks.',
				'tables'   => array( 'projects', 'project_phases', 'tasks' ),
				'optional' => array(),
				'excluded' => array(),
			),
			'finance'   => array(
				'label'    => 'Finance',
				'desc'     => 'Services, billing, invoices, payments, expenses and payroll.',
				'tables'   => array( 'services', 'client_services', 'renewals', 'charges', 'invoices', 'invoice_items', 'invoice_installments', 'payments', 'expenses', 'recurring_expenses', 'pay_allocations', 'pay_items', 'payouts', 'email_log' ),
				'optional' => array( 'email_log' ),
				'excluded' => array(),
			),
			'contracts' => array(
				'label'    => 'Contracts',
				'desc'     => 'Templates, contracts, signers, the tamper-proof audit trail and signed PDFs.',
				'tables'   => array( 'contract_templates', 'contracts', 'contract_signers', 'contract_events' ),
				'optional' => array(),
				'excluded' => array( 'contract_otps' ),
			),
		);
	}

	/** Every table the plugin owns, backed up or not. */
	public static function all_tables() {
		$out = array();
		foreach ( self::modules() as $m ) {
			$out = array_merge( $out, $m['tables'], $m['excluded'] );
		}
		return $out;
	}

	public static function tables_of( $module ) {
		$m = self::modules();
		return isset( $m[ $module ] ) ? $m[ $module ]['tables'] : array();
	}

	public static function module_of( $table ) {
		foreach ( self::modules() as $id => $m ) {
			if ( in_array( $table, $m['tables'], true ) || in_array( $table, $m['excluded'], true ) ) {
				return $id;
			}
		}
		return '';
	}

	/** Option name => owning module. `bizydash_mail`'s encrypted SMTP password is stripped on export. */
	public static function options() {
		return array(
			'bizydash_settings'            => 'core',
			'bizydash_mail'                => 'core',
			'bizydash_modules'             => 'core',
			'bizydash_modules_analytics'   => 'core',
			'bizydash_retainer_service_id' => 'finance',
			'bizydash_payout_next'         => 'finance',
			'bizydash_contract_next'       => 'contracts',
		);
	}

	/** Keys of the `bizydash_settings` option that belong to a module other than core (core owns the rest). */
	public static function settings_keys( $module ) {
		$m = array(
			'finance'   => array( 'invoice_prefix', 'invoice_next', 'invoice_slug_base', 'default_due_days', 'due_soon_days', 'invoice_terms', 'invoice_footer', 'payment_methods', 'payment_instructions' ),
			'contracts' => array( 'contract_view_default' ),
		);
		return isset( $m[ $module ] ) ? $m[ $module ] : array();
	}

	/** Columns that hold WordPress user ids, so a restore can map them to this site's users. */
	public static function user_columns() {
		return array( 'created_by', 'user_id', 'wp_user_id' );
	}

	/** Columns that hold media-library attachment ids: table => columns. */
	public static function attachment_columns() {
		return array(
			'brands'   => array( 'logo_attachment_id' ),
			'invoices' => array( 'attachment_id' ),
			'expenses' => array( 'attachment_id' ),
		);
	}

	/** Child column => parent table, used to spot orphaned rows after a partial restore. */
	public static function refs() {
		return array(
			'client_brands'        => array( 'client_id' => 'clients', 'brand_id' => 'brands' ),
			'client_contacts'      => array( 'client_id' => 'clients' ),
			'client_services'      => array( 'client_id' => 'clients', 'service_id' => 'services' ),
			'renewals'             => array( 'client_service_id' => 'client_services', 'client_id' => 'clients' ),
			'charges'              => array( 'client_id' => 'clients' ),
			'invoices'             => array( 'client_id' => 'clients' ),
			'invoice_items'        => array( 'invoice_id' => 'invoices' ),
			'invoice_installments' => array( 'invoice_id' => 'invoices' ),
			'payments'             => array( 'invoice_id' => 'invoices' ),
			'projects'             => array( 'client_id' => 'clients' ),
			'project_phases'       => array( 'project_id' => 'projects' ),
			'tasks'                => array( 'project_id' => 'projects' ),
			'contract_signers'     => array( 'contract_id' => 'contracts' ),
			'contract_events'      => array( 'contract_id' => 'contracts' ),
		);
	}

	public static function table( $name ) {
		return Schema::table( $name );
	}
}
