<?php
namespace BizyDash;

defined( 'ABSPATH' ) || exit;

/**
 * BizyDash's own WordPress roles and capabilities. Roles can be edited later (with any role editor); this class
 * (re)creates them on install and upgrade so they always exist, and keeps a few helpers for "what can this user do".
 *
 * Capabilities
 *   manage_bizydash      sign in to the BizyDash app (every internal role)
 *   bizydash_billing     invoices, billing, payments, catalog (Finance module, non-payroll)
 *   bizydash_finance     the money screens: expenses, payroll, profit, bank details
 *   bizydash_work        projects, phases and tasks
 *   bizydash_contracts   contracts and templates
 *   bizydash_clients     add / edit / delete clients, contacts and departments
 *   bizydash_team        add / edit / delete team members, and give them access
 *   bizydash_admin       everything in BizyDash, settings, backups (but not WordPress itself)
 *   bizydash_portal      the client portal (clients only; never the internal app)
 */
class Roles {

	const CAPS = array( 'manage_bizydash', 'bizydash_billing', 'bizydash_finance', 'bizydash_work', 'bizydash_contracts', 'bizydash_clients', 'bizydash_team', 'bizydash_admin' );

	/** Extra modules a Team Manager can be given, as chosen per person. */
	const MANAGER_MODULES = array(
		'work'      => array( 'label' => 'Work', 'caps' => array( 'bizydash_work' ) ),
		'clients'   => array( 'label' => 'Clients', 'caps' => array( 'bizydash_clients' ) ),
		'billing'   => array( 'label' => 'Billing & invoices', 'caps' => array( 'bizydash_billing' ) ),
		'finance'   => array( 'label' => 'Finance (expenses, payroll, profit)', 'caps' => array( 'bizydash_finance', 'bizydash_billing' ) ),
		'contracts' => array( 'label' => 'Contracts', 'caps' => array( 'bizydash_contracts' ) ),
	);

	public static function roles() {
		$base = array( 'read' => true, 'upload_files' => true, 'manage_bizydash' => true );
		return array(
			'bizydash_client'         => array( 'label' => 'BD Client', 'desc' => 'A client’s own login for the client portal. Cannot open the BizyDash app or WordPress admin.', 'caps' => array( 'read' => true, 'bizydash_portal' => true ), 'internal' => false ),
			'bizydash_client_manager' => array( 'label' => 'BD Client Manager', 'desc' => 'Looks after clients: edits clients and contacts, sees their work, billing and contracts.', 'caps' => $base + array( 'bizydash_clients' => true, 'bizydash_work' => true, 'bizydash_billing' => true, 'bizydash_contracts' => true ), 'internal' => true ),
			'bizydash_work_team'      => array( 'label' => 'BD Work Team', 'desc' => 'Does the work: projects, phases and tasks.', 'caps' => $base + array( 'bizydash_work' => true ), 'internal' => true ),
			'bizydash_finance_team'   => array( 'label' => 'BD Finance Team', 'desc' => 'Billing, invoices, payments, expenses and payroll.', 'caps' => $base + array( 'bizydash_billing' => true, 'bizydash_finance' => true ), 'internal' => true ),
			'bizydash_contract_team'  => array( 'label' => 'BD Contract Team', 'desc' => 'Contracts, templates and e-signatures.', 'caps' => $base + array( 'bizydash_contracts' => true ), 'internal' => true ),
			'bizydash_team_manager'   => array( 'label' => 'BD Team Manager', 'desc' => 'Manages the team and its work. You choose which other parts of BizyDash each manager can also manage.', 'caps' => $base + array( 'bizydash_team' => true, 'bizydash_work' => true ), 'internal' => true ),
			'bizydash_admin'          => array( 'label' => 'BD Admin', 'desc' => 'Full access to BizyDash, including settings, modules and backups. No access to WordPress itself.', 'caps' => $base + array( 'bizydash_billing' => true, 'bizydash_finance' => true, 'bizydash_work' => true, 'bizydash_contracts' => true, 'bizydash_clients' => true, 'bizydash_team' => true, 'bizydash_admin' => true ), 'internal' => true ),
			// The original catch-all team role, kept so existing people lose nothing.
			'bizydash_staff'          => array( 'label' => 'BizyDash team member', 'desc' => 'The original team role: everything except the money screens.', 'caps' => $base + array( 'bizydash_billing' => true, 'bizydash_work' => true, 'bizydash_contracts' => true, 'bizydash_clients' => true, 'bizydash_team' => true ), 'internal' => true, 'legacy' => true ),
		);
	}

	/** Create or refresh every role so new capabilities reach roles that already exist. */
	public static function install() {
		$admin = get_role( 'administrator' );
		foreach ( self::CAPS as $cap ) {
			if ( $admin && ! $admin->has_cap( $cap ) ) {
				$admin->add_cap( $cap );
			}
		}
		foreach ( self::roles() as $key => $r ) {
			$role = get_role( $key );
			if ( ! $role ) {
				add_role( $key, $r['label'], $r['caps'] );
				continue;
			}
			foreach ( $r['caps'] as $cap => $grant ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap, $grant );
				}
			}
		}
	}

	/** May this user manage BizyDash itself (settings, modules, backups)? WordPress administrators and BD Admins. */
	public static function can_admin() {
		return current_user_can( 'manage_options' ) || current_user_can( 'bizydash_admin' );
	}

	/** The capability that gates a module's screens and data (empty = open to every BizyDash user). */
	public static function module_cap( $module ) {
		$m = array( 'work' => 'bizydash_work', 'finance' => 'bizydash_billing', 'contracts' => 'bizydash_contracts' );
		return isset( $m[ $module ] ) ? $m[ $module ] : '';
	}

	/** Who may change records of the core resources (everyone with BizyDash can read them). */
	public static function write_cap( $resource ) {
		$m = array( 'clients' => 'bizydash_clients', 'contacts' => 'bizydash_clients', 'brands' => 'bizydash_clients', 'team' => 'bizydash_team' );
		return isset( $m[ $resource ] ) ? $m[ $resource ] : '';
	}

	/** Give a WordPress user one BizyDash role (replacing any other BizyDash role), plus a manager's chosen modules. */
	public static function assign( $user_id, $role_key, array $manager_modules = array() ) {
		$u = get_userdata( (int) $user_id );
		if ( ! $u ) {
			return new \WP_Error( 'bd_user', 'That user does not exist.', array( 'status' => 404 ) );
		}
		if ( user_can( $u, 'manage_options' ) ) {
			return new \WP_Error( 'bd_admin_user', 'This person is a WordPress administrator and already has full access.', array( 'status' => 400 ) );
		}
		$roles = self::roles();
		if ( '' !== $role_key && ! isset( $roles[ $role_key ] ) ) {
			return new \WP_Error( 'bd_role', 'Unknown role.', array( 'status' => 400 ) );
		}
		foreach ( array_keys( $roles ) as $k ) {
			if ( in_array( $k, (array) $u->roles, true ) ) {
				$u->remove_role( $k );
			}
		}
		foreach ( self::MANAGER_MODULES as $m => $d ) {
			foreach ( $d['caps'] as $c ) {
				$u->remove_cap( $c );
			}
		}
		delete_user_meta( $u->ID, 'bizydash_manager_modules' );
		if ( '' === $role_key ) {
			return true;
		}
		$u->add_role( $role_key );
		if ( 'bizydash_team_manager' === $role_key ) {
			$chosen = array_values( array_intersect( $manager_modules, array_keys( self::MANAGER_MODULES ) ) );
			foreach ( $chosen as $m ) {
				foreach ( self::MANAGER_MODULES[ $m ]['caps'] as $c ) {
					$u->add_cap( $c );
				}
			}
			update_user_meta( $u->ID, 'bizydash_manager_modules', $chosen );
		}
		return true;
	}

	/** Current BizyDash role of a user: array( role key, manager modules ). */
	public static function role_of( $user_id ) {
		$u = get_userdata( (int) $user_id );
		if ( ! $u ) {
			return array( '', array() );
		}
		foreach ( array_keys( self::roles() ) as $k ) {
			if ( in_array( $k, (array) $u->roles, true ) ) {
				$mods = get_user_meta( $u->ID, 'bizydash_manager_modules', true );
				return array( $k, is_array( $mods ) ? $mods : array() );
			}
		}
		return array( '', array() );
	}

	/** Booleans the browser uses to show or hide parts of the app. */
	public static function ui_caps() {
		$out = array();
		foreach ( self::CAPS as $c ) {
			$out[ str_replace( array( 'bizydash_', 'manage_bizydash' ), array( '', 'app' ), $c ) ] = current_user_can( $c ) || current_user_can( 'manage_options' );
		}
		return $out;
	}
}
