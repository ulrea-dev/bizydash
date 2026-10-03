<?php
namespace BizyDash;

defined( 'ABSPATH' ) || exit;

/**
 * BizyDash is a core (Clients + Team) with switchable modules around it.
 *
 * A module owns its screens, REST routes, scheduled jobs, public pages and menu entries. Switching one off hides it
 * and stops its jobs and public links, but never deletes data — switch it back on and everything is there.
 */
class Modules {

	const OPTION           = 'bizydash_modules';
	/** Core areas that are not modules but still have their own Analytics page (and switch). */
	const CORE_ANALYTICS = array( 'clients' => 'Clients', 'team' => 'Team' );
	const ANALYTICS_OPTION = 'bizydash_modules_analytics'; // id => bool, kept apart from the on/off map above

	/** Module registry. `menu` entries become the module's own sub-menu (app sidebar and WordPress admin). */
	public static function defs() {
		return array(
			'core'      => array(
				'label' => 'Core',
				'desc'  => 'Home, Clients, Team, Departments, Activity and Settings. Always on — every module builds on it.',
				'core'  => true,
			),
			'work'      => array(
				'label'  => 'Work',
				'desc'   => 'Projects, tasks and phases. Who is doing what, and when it is due.',
				'icon'   => 'work',
				'analytics' => true,
				'route'  => '/work',
				'wp'     => array( 'dashicons-yes-alt', 27 ),
				'menu'   => array(
					array( 'label' => 'Overview', 'route' => '/work' ),
					array( 'label' => 'My tasks', 'route' => '/work/mine' ),
					array( 'label' => 'All tasks', 'route' => '/work/all' ),
					array( 'label' => 'Projects', 'route' => '/work/projects' ),
					array( 'label' => 'Analytics', 'route' => '/work/analytics', 'analytics' => true ),
				),
			),
			'finance'   => array(
				'label'  => 'Finance',
				'desc'   => 'Billing, invoices, expenses, payroll, the catalog, and profit.',
				'icon'   => 'finance',
				'analytics' => true,
				'route'  => '/finance',
				'wp'     => array( 'dashicons-money-alt', 28 ),
				'menu'   => array(
					array( 'label' => 'Overview', 'route' => '/finance/overview', 'cap' => 'bizydash_finance' ),
					array( 'label' => 'Analytics', 'route' => '/finance/analytics', 'cap' => 'bizydash_finance', 'analytics' => true ),
					array( 'label' => 'Billing', 'route' => '/finance/billing' ),
					array( 'label' => 'Invoices', 'route' => '/finance/billing/invoices' ),
					array( 'label' => 'Expenses', 'route' => '/finance/expenses', 'cap' => 'bizydash_finance' ),
					array( 'label' => 'Payroll', 'route' => '/finance/payroll', 'cap' => 'bizydash_finance' ),
					array( 'label' => 'Catalog', 'route' => '/finance/catalog' ),
				),
			),
			'contracts' => array(
				'label'  => 'Contracts',
				'desc'   => 'Contracts, templates and e-signatures with a sealed PDF and a public verification page.',
				'icon'   => 'contracts',
				'analytics' => true,
				'route'  => '/contracts',
				'wp'     => array( 'dashicons-media-document', 29 ),
				'menu'   => array(
					array( 'label' => 'Overview', 'route' => '/contracts' ),
					array( 'label' => 'All contracts', 'route' => '/contracts/all' ),
					array( 'label' => 'Templates', 'route' => '/contracts/templates' ),
					array( 'label' => 'Analytics', 'route' => '/contracts/analytics', 'analytics' => true ),
					array( 'label' => 'Verify a certificate', 'route' => '', 'url' => 'verify' ),
				),
			),
		);
	}

	/** Which REST resources belong to which module (anything not listed is core). */
	const RESOURCES = array(
		'work'      => array( 'projects', 'tasks', 'project-phases' ),
		'finance'   => array( 'services', 'client-services', 'renewals', 'charges', 'invoices', 'payments', 'expenses', 'recurring-expenses', 'pay-allocations', 'pay-items', 'payouts' ),
		'contracts' => array( 'contract-templates', 'contracts' ),
	);

	public static function resource_module( $name ) {
		foreach ( self::RESOURCES as $m => $list ) {
			if ( in_array( $name, $list, true ) ) {
				return $m;
			}
		}
		return 'core';
	}

	public static function states() {
		$saved = get_option( self::OPTION, array() );
		$out   = array();
		foreach ( self::defs() as $id => $d ) {
			$out[ $id ] = ! empty( $d['core'] ) ? true : ( is_array( $saved ) && array_key_exists( $id, $saved ) ? (bool) $saved[ $id ] : true );
		}
		return $out;
	}

	public static function enabled( $id ) {
		$s = self::states();
		return ! empty( $s[ $id ] );
	}

	/** Is the module's Analytics page switched on? (Defaults to on.) */
	public static function analytics_on( $id ) {
		$saved = get_option( self::ANALYTICS_OPTION, array() );
		$on    = ! is_array( $saved ) || ! array_key_exists( $id, $saved ) || (bool) $saved[ $id ];
		return isset( self::CORE_ANALYTICS[ $id ] ) ? $on : ( self::enabled( $id ) && $on );
	}

	public static function save( array $in ) {
		$out = array();
		foreach ( self::defs() as $id => $d ) {
			if ( empty( $d['core'] ) ) {
				$out[ $id ] = ! empty( $in[ $id ] );
			}
		}
		update_option( self::OPTION, $out, false );
		if ( isset( $in['_analytics'] ) && is_array( $in['_analytics'] ) ) {
			$an = array();
			foreach ( self::defs() as $id => $d ) {
				if ( empty( $d['core'] ) ) {
					$an[ $id ] = ! isset( $in['_analytics'][ $id ] ) || ! empty( $in['_analytics'][ $id ] );
				}
			}
			foreach ( array_keys( self::CORE_ANALYTICS ) as $id ) {
				$an[ $id ] = ! isset( $in['_analytics'][ $id ] ) || ! empty( $in['_analytics'][ $id ] );
			}
			update_option( self::ANALYTICS_OPTION, $an, false );
		}
		// Public pages (invoices, signing links) appear or vanish with their module — never serve a stale cached answer.
		do_action( 'litespeed_purge_all' );
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		return self::states();
	}

	/** Navigation for the signed-in user: the core links plus each enabled module's own sub-menu. */
	public static function nav() {
		$mods = array();
		foreach ( self::defs() as $id => $d ) {
			if ( ! empty( $d['core'] ) || ! self::enabled( $id ) ) {
				continue;
			}
			$items = array();
			foreach ( $d['menu'] as $it ) {
				if ( ! empty( $it['cap'] ) && ! current_user_can( $it['cap'] ) ) {
					continue;
				}
				if ( ! empty( $it['analytics'] ) && ! self::analytics_on( $id ) ) {
					continue;
				}
				$items[] = array(
					'label' => $it['label'],
					'route' => $it['route'],
					'url'   => ! empty( $it['url'] ) ? home_url( '/' . $it['url'] ) : '',
				);
			}
			$mods[] = array( 'id' => $id, 'label' => $d['label'], 'icon' => $d['icon'], 'route' => $d['route'], 'items' => $items );
		}
		return array(
			'core'    => array(
				array( 'label' => 'Home', 'route' => '/', 'icon' => 'home' ),
				array( 'label' => 'Clients', 'route' => '/clients', 'icon' => 'clients' ),
				array( 'label' => 'Team', 'route' => '/team', 'icon' => 'team' ),
				array( 'label' => 'Departments', 'route' => '/departments', 'icon' => 'departments' ),
			),
			'modules' => $mods,
			'footer'  => array(
				array( 'label' => 'Activity', 'route' => '/activity', 'icon' => 'activity' ),
				array( 'label' => 'Settings', 'route' => '/settings', 'icon' => 'settings' ),
			),
		);
	}

	/** For Settings → Modules. */
	public static function catalog() {
		$out = array();
		foreach ( self::defs() as $id => $d ) {
			$has = ! empty( $d['analytics'] );
			$saved = get_option( self::ANALYTICS_OPTION, array() );
			$out[] = array( 'id' => $id, 'label' => $d['label'], 'desc' => $d['desc'], 'core' => ! empty( $d['core'] ), 'enabled' => self::enabled( $id ), 'core_analytics' => ! empty( $d['core'] ) ? array_map( function ( $id, $label ) {
				return array( 'id' => $id, 'label' => $label, 'on' => Modules::analytics_on( $id ) );
			}, array_keys( self::CORE_ANALYTICS ), self::CORE_ANALYTICS ) : array(), 'has_analytics' => $has, 'analytics' => $has && ( ! is_array( $saved ) || ! array_key_exists( $id, $saved ) || (bool) $saved[ $id ] ) );
		}
		return $out;
	}

	/** Which module a given in-app route belongs to (for highlighting and "module is off" screens). */
	public static function route_module( $first_segment ) {
		$map = array(
			'work' => 'work', 'projects' => 'work', 'tasks' => 'work',
			'finance' => 'finance', 'billing' => 'finance', 'catalog' => 'finance', 'charges' => 'finance', 'invoices' => 'finance', 'renewals' => 'finance', 'services' => 'finance',
			'contracts' => 'contracts', 'legal' => 'contracts',
		);
		return isset( $map[ $first_segment ] ) ? $map[ $first_segment ] : 'core';
	}
}
