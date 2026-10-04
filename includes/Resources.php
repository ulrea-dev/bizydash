<?php
namespace BizyDash;

use BizyDash\Database\Schema;
use BizyDash\Services\Activity;
use BizyDash\Services\Billing;
use BizyDash\Services\Charges;
use BizyDash\Services\Contracts;
use BizyDash\Services\Invoices;
use BizyDash\Services\Renewals;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of REST resources. Each entry drives a Repository + Resource_Controller.
 */
class Resources {

	const CHARGE_STATUSES  = array( 'pending', 'invoiced', 'waived', 'cancelled' );
	const RENEWAL_STATUSES = array( 'upcoming', 'due', 'renewed', 'skipped', 'cancelled' );
	const INVOICE_STATUSES = array( 'draft', 'sent', 'due', 'partially_paid', 'paid', 'overdue', 'cancelled' );
	const TIMINGS          = array( 'now', 'end_of_month', 'end_of_quarter', 'next_renewal', 'custom' );

	/** @var Repository[] */
	private static $repos = array();

	public static function repo( $name ) {
		if ( ! isset( self::$repos[ $name ] ) ) {
			$all = self::all();
			self::$repos[ $name ] = new Repository( $name, $all[ $name ] );
		}
		return self::$repos[ $name ];
	}

	public static function names() {
		return array_values( array_filter( array_keys( self::all() ), function ( $n ) {
			return Modules::enabled( Modules::resource_module( $n ) );
		} ) );
	}

	private static function client_join() {
		return 'LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=t.client_id';
	}

	public static function all() {
		$cs_join = 'LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=t.client_id '
			. 'LEFT JOIN ' . Schema::table( 'client_services' ) . ' cs ON cs.id=t.client_service_id '
			. 'LEFT JOIN ' . Schema::table( 'services' ) . ' s ON s.id=cs.service_id';

		return array(

			'brands'          => array(
				'route'    => 'brands',
				'table'    => 'brands',
				'label'    => Settings::get( 'department_label' ),
				'cols'     => array( 'name' => 's', 'type' => 's', 'description' => 't', 'logo_attachment_id' => 'i', 'status' => 's' ),
				'filters'  => array( 'type', 'status' ),
				'search'   => array( 'name' ),
				'order'    => 't.name ASC',
				'required' => array( 'name' ),
				'title'    => function ( $r ) {
					return $r['name'];
				},
				'before_save' => function ( $d, $existing ) {
					if ( ! $existing ) {
						$d += array( 'status' => 'active' );
					}
					return $d;
				},
				'decorate' => function ( $rows ) {
					foreach ( $rows as &$r ) {
						$r['logo_url'] = $r['logo_attachment_id'] ? wp_get_attachment_image_url( $r['logo_attachment_id'], 'thumbnail' ) : '';
					}
					return $rows;
				},
				'after_delete' => function ( $row ) {
					global $wpdb;
					$wpdb->delete( Schema::table( 'client_brands' ), array( 'brand_id' => $row['id'] ) );
				},
			),

			'clients'         => array(
				'route'    => 'clients',
				'table'    => 'clients',
				'label'    => 'Client',
				'cols'     => array(
					'company_name'    => 's', 'contact_person' => 's', 'email' => 'e', 'phone' => 's', 'address' => 't', 'notes' => 't',
					'client_type'     => 's', 'status' => 's', 'currency' => 'c', 'retainer_amount' => 'd', 'retainer_period' => 's',
				),
				'filters'  => array( 'client_type', 'status', 'currency' ),
				'search'   => array( 'company_name', 'contact_person', 'email' ),
				'order'    => 't.company_name ASC',
				'required' => array( 'company_name' ),
				'title'    => function ( $r ) {
					return $r['company_name'];
				},
				'extra'    => function ( $a ) {
					$sql = array();
					$p   = array();
					$cb  = Schema::table( 'client_brands' );
					if ( ! empty( $a['brand_id'] ) ) {
						$sql[] = "EXISTS (SELECT 1 FROM $cb x WHERE x.client_id=t.id AND x.brand_id=%d)";
						$p[]   = (int) $a['brand_id'];
					}
					if ( ! empty( $a['brand_type'] ) ) {
						$sql[] = "EXISTS (SELECT 1 FROM $cb x JOIN " . Schema::table( 'brands' ) . ' b ON b.id=x.brand_id WHERE x.client_id=t.id AND b.type=%s)';
						$p[]   = $a['brand_type'];
					}
					return array( implode( ' AND ', $sql ), $p );
				},
				'before_save' => function ( $d, $existing ) {
					if ( ! $existing ) {
						$d += array(
							'status'      => 'active',
							'client_type' => 'adhoc',
							'currency'    => Settings::get( 'default_currency' ),
						);
					}
					if ( isset( $d['currency'] ) && '' === $d['currency'] ) {
						$d['currency'] = Settings::get( 'default_currency' );
					}
					return $d;
				},
				'after_save' => function ( $row, $existing, $params ) {
					global $wpdb;
					// New client with a contact name/email entered on the form → first primary contact.
					if ( ! $existing && ( '' !== (string) $row['email'] || '' !== (string) $row['contact_person'] ) ) {
						$ct = Schema::table( 'client_contacts' );
						if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $ct WHERE client_id=%d LIMIT 1", $row['id'] ) ) ) { // phpcs:ignore WordPress.DB
							$now = current_time( 'mysql' );
							$wpdb->insert( $ct, array( 'client_id' => $row['id'], 'name' => $row['contact_person'] ? $row['contact_person'] : $row['company_name'], 'email' => $row['email'], 'phone' => $row['phone'], 'kind' => 'primary', 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
						}
					}
					\BizyDash\Services\Portal_Users::sync_client( $row['id'] );
					if ( ! isset( $params['brand_ids'] ) || ! is_array( $params['brand_ids'] ) ) {
						return;
					}
					$tbl = Schema::table( 'client_brands' );
					$wpdb->delete( $tbl, array( 'client_id' => $row['id'] ) );
					$primary = isset( $params['primary_brand_id'] ) ? (int) $params['primary_brand_id'] : 0;
					foreach ( array_unique( array_map( 'intval', $params['brand_ids'] ) ) as $i => $bid ) {
						if ( $bid ) {
							$wpdb->insert( $tbl, array( 'client_id' => $row['id'], 'brand_id' => $bid, 'is_primary' => ( $primary ? (int) ( $primary === $bid ) : (int) ( 0 === $i ) ) ) );
						}
					}
				},
				'decorate' => function ( $rows ) {
					global $wpdb;
					if ( ! $rows ) {
						return $rows;
					}
					$ids = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
					$res = $wpdb->get_results( 'SELECT cb.client_id, cb.is_primary, b.id, b.name, b.type FROM ' . Schema::table( 'client_brands' ) . ' cb JOIN ' . Schema::table( 'brands' ) . " b ON b.id=cb.brand_id WHERE cb.client_id IN ($ids) ORDER BY cb.is_primary DESC, b.name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
					$map = array();
					foreach ( (array) $res as $b ) {
						$map[ $b['client_id'] ][] = array( 'id' => (int) $b['id'], 'name' => $b['name'], 'type' => $b['type'], 'is_primary' => (bool) $b['is_primary'] );
					}
					$cmap = array();
					$cres = $wpdb->get_results( 'SELECT id, client_id, name, email, phone, job_title, kind FROM ' . Schema::table( 'client_contacts' ) . " WHERE client_id IN ($ids) ORDER BY kind='primary' DESC, sort_order ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
					foreach ( (array) $cres as $c ) {
						$cmap[ $c['client_id'] ][] = array( 'id' => (int) $c['id'], 'name' => $c['name'], 'email' => $c['email'], 'phone' => $c['phone'], 'job_title' => $c['job_title'], 'kind' => $c['kind'] );
					}
					foreach ( $rows as &$r ) {
						$r['brands']    = isset( $map[ $r['id'] ] ) ? $map[ $r['id'] ] : array();
						$r['brand_ids'] = wp_list_pluck( $r['brands'], 'id' );
						$r['contacts']  = isset( $cmap[ $r['id'] ] ) ? $cmap[ $r['id'] ] : array();
					}
					return $rows;
				},
				'after_delete' => function ( $row ) {
					global $wpdb;
					$wpdb->delete( Schema::table( 'client_brands' ), array( 'client_id' => $row['id'] ) );
					$wpdb->delete( Schema::table( 'client_contacts' ), array( 'client_id' => $row['id'] ) );
					\BizyDash\Services\Portal_Users::unlink_client( $row['id'] );
				},
			),

			'services'        => array(
				'route'    => 'services',
				'table'    => 'services',
				'label'    => 'Service',
				'cols'     => array(
					'name' => 's', 'description' => 't', 'default_billing_period' => 's', 'default_cost' => 'd', 'default_price' => 'd',
					'default_billing_treatment' => 's', 'default_currency' => 'c', 'kind' => 's', 'type' => 's', 'pricing' => 's', 'unit_label' => 's', 'subject_label' => 's', 'department_id' => 'i', 'status' => 's',
				),
				'select'   => ', dp.name AS department_name',
				'join'     => 'LEFT JOIN ' . Schema::table( 'brands' ) . ' dp ON dp.id=t.department_id',
				'filters'  => array( 'status', 'kind', 'type', 'pricing', 'department_id' ),
				'search'   => array( 'name', 'description' ),
				'order'    => 't.name ASC',
				'required' => array( 'name' ),
				'title'    => function ( $r ) {
					return $r['name'];
				},
				'before_save' => function ( $d, $existing ) {
					if ( ! $existing ) {
						$d += array( 'status' => 'active' );
					}
					$type    = isset( $d['type'] ) ? $d['type'] : ( $existing ? $existing['type'] : 'service' );
					$type    = 'product' === $type ? 'product' : 'service';
					$pricing = isset( $d['pricing'] ) ? $d['pricing'] : ( $existing ? $existing['pricing'] : ( 'product' === $type ? 'unit' : 'fixed' ) );
					$pricing = 'unit' === $pricing ? 'unit' : 'fixed';
					$d['type']    = $type;
					$d['pricing'] = $pricing;
					if ( 'unit' !== $pricing ) {
						$d['unit_label'] = '';
					} elseif ( isset( $d['unit_label'] ) ) {
						$d['unit_label'] = trim( $d['unit_label'] );
					}
					$kind   = isset( $d['kind'] ) ? $d['kind'] : ( $existing ? $existing['kind'] : '' );
					$period = isset( $d['default_billing_period'] ) ? $d['default_billing_period'] : ( $existing ? $existing['default_billing_period'] : '' );
					if ( ! in_array( $kind, array( 'one_off', 'recurring' ), true ) ) {
						$kind = ( $period && 'none' !== $period ) ? 'recurring' : 'one_off';
					}
					if ( 'recurring' === $kind && ( ! $period || 'none' === $period ) ) {
						return new \WP_Error( 'bizydash_invalid', 'A recurring service needs a billing cycle (e.g. monthly or annually).', array( 'status' => 400 ) );
					}
					$d['kind'] = $kind;
					if ( 'one_off' === $kind ) {
						$d['default_billing_period'] = 'none';
					}
					return $d;
				},
			),

			'client-services' => array(
				'route'    => 'client-services',
				'table'    => 'client_services',
				'label'    => 'Client service',
				'select'   => ', c.company_name AS client_name, s.name AS service_name, s.type AS service_type, s.pricing AS service_pricing, s.unit_label AS service_unit, s.subject_label AS service_subject_label, dp.name AS department_name',
				'join'     => 'LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=t.client_id LEFT JOIN ' . Schema::table( 'services' ) . ' s ON s.id=t.service_id LEFT JOIN ' . Schema::table( 'brands' ) . ' dp ON dp.id=t.department_id',
				'cols'     => array(
					'client_id' => 'i', 'service_id' => 'i', 'provider' => 's', 'subject' => 's', 'start_date' => 'date', 'status' => 's', 'cost' => 'd', 'client_price' => 'd',
					'currency'  => 'c', 'billing_treatment' => 's', 'billing_period' => 's', 'interval_days' => 'i', 'next_renewal_date' => 'date', 'notes' => 't',
					'quantity' => 'd', 'unit_price' => 'd', 'unit_cost' => 'd', 'department_id' => 'i',
				),
				'filters'  => array( 'client_id', 'service_id', 'status', 'billing_treatment', 'currency', 'billing_period', 'department_id' ),
				'extra'    => function ( $a ) {
					$sql  = array();
					$args = array();
					if ( ! empty( $a['recurring'] ) ) {
						$sql[] = "t.billing_period NOT IN ('','none')";
					}
					if ( ! empty( $a['service_type'] ) && in_array( $a['service_type'], array( 'service', 'product' ), true ) ) {
						$sql[]  = 's.type = %s';
						$args[] = $a['service_type'];
					}
					return array( implode( ' AND ', $sql ), $args );
				},
				'search'   => array( 'provider', 't.subject', 'c.company_name', 's.name' ),
				'order'    => 'c.company_name ASC, s.name ASC',
				'order_map' => array( 'client_name' => 'c.company_name', 'service_name' => 's.name' ),
				'required' => array( 'client_id', 'service_id' ),
				'title'    => function ( $r ) {
					return $r['service_name'] . ( ! empty( $r['subject'] ) ? ' (' . $r['subject'] . ')' : '' ) . ' — ' . $r['client_name'];
				},
				'before_save' => function ( $d, $existing, $params ) {
					global $wpdb;
					if ( ! $existing && ! empty( $d['service_id'] ) ) {
						$svc = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'services' ) . ' WHERE id=%d', $d['service_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
						if ( $svc && empty( $params['department_id'] ) ) {
							$d['department_id'] = Services\Finance::default_department( $svc['id'], isset( $d['client_id'] ) ? $d['client_id'] : 0 ); // the catalog item's department, else the client's
						}
						if ( $svc ) {
							$map = array( 'cost' => 'default_cost', 'client_price' => 'default_price', 'billing_treatment' => 'default_billing_treatment', 'billing_period' => 'default_billing_period', 'currency' => 'default_currency' );
							foreach ( $map as $k => $from ) {
								if ( ! isset( $params[ $k ] ) || '' === $params[ $k ] ) {
									$d[ $k ] = 'cost' === $k || 'client_price' === $k ? (float) $svc[ $from ] : $svc[ $from ];
								}
							}
						}
						$d += array( 'status' => 'active' );
					}
					// Per-unit subscriptions (e.g. 12 email accounts): price and cost per cycle = quantity x unit.
					$sid = isset( $d['service_id'] ) ? (int) $d['service_id'] : ( $existing ? (int) $existing['service_id'] : 0 );
					$svc = $sid ? $wpdb->get_row( $wpdb->prepare( 'SELECT pricing, default_price, default_cost FROM ' . Schema::table( 'services' ) . ' WHERE id=%d', $sid ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB
					if ( $svc && 'unit' === $svc['pricing'] ) {
						$touch = ! $existing || isset( $d['quantity'] ) || isset( $d['unit_price'] ) || isset( $d['unit_cost'] );
						if ( $touch ) {
							$qty  = isset( $d['quantity'] ) ? (float) $d['quantity'] : ( $existing ? (float) $existing['quantity'] : 1 );
							$qty  = $qty > 0 ? $qty : 1;
							$up   = isset( $d['unit_price'] ) && '' !== $d['unit_price'] ? (float) $d['unit_price'] : ( $existing ? (float) $existing['unit_price'] : (float) $svc['default_price'] );
							$uc   = isset( $d['unit_cost'] ) && '' !== $d['unit_cost'] ? (float) $d['unit_cost'] : ( $existing ? (float) $existing['unit_cost'] : (float) $svc['default_cost'] );
							$d['quantity']     = $qty;
							$d['unit_price']   = $up;
							$d['unit_cost']    = $uc;
							$d['client_price'] = round( $qty * $up, 2 );
							$d['cost']         = round( $qty * $uc, 2 );
						}
					}
					$cid = isset( $d['client_id'] ) ? $d['client_id'] : ( $existing ? $existing['client_id'] : 0 );
					if ( empty( $d['currency'] ) && ( ! $existing || ( isset( $d['currency'] ) && '' === $d['currency'] ) ) ) {
						$d['currency'] = Billing::client_currency( $cid );
					}
					return $d;
				},
				'after_save' => function ( $row ) {
					Renewals::sync_from_client_service( $row );
				},
				'decorate' => function ( $rows ) {
					$next = \BizyDash\Services\Subscriptions::next_cycles( wp_list_pluck( $rows, 'id' ) );
					$ret  = (int) get_option( \BizyDash\Services\Retainers::OPTION ); // legacy: never create the special service any more
					foreach ( $rows as &$r ) {
						$r['is_retainer'] = (int) $r['service_id'] === (int) $ret;
						$r['mrr']         = 'active' === $r['status'] ? \BizyDash\Services\Subscriptions::monthly_value( $r ) : 0;
						$r['next_cycle']  = isset( $next[ $r['id'] ] ) ? $next[ $r['id'] ] : null;
					}
					return $rows;
				},
				'after_delete' => function ( $row ) {
					global $wpdb;
					$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'renewals' ) . " WHERE client_service_id=%d AND status IN ('upcoming','due') AND invoice_id=0", $row['id'] ) ); // phpcs:ignore WordPress.DB
				},
			),

			'renewals'        => array(
				'route'    => 'renewals',
				'table'    => 'renewals',
				'label'    => 'Renewal',
				'select'   => ', c.company_name AS client_name, s.name AS service_name, inv.invoice_number, inv.status AS invoice_status, dp.name AS department_name',
				'join'     => $cs_join . ' LEFT JOIN ' . Schema::table( 'invoices' ) . ' inv ON inv.id=t.invoice_id',
				'extra'    => function ( $a ) {
					$f = ! empty( $a['bill_status'] ) ? Billing::bill_status_sql( $a['bill_status'], "'upcoming','due'" ) : '';
					return array( $f, array() );
				},
				'cols'     => array(
					'client_service_id' => 'i', 'client_id' => 'i', 'due_date' => 'date', 'frequency' => 's', 'interval_days' => 'i', 'cost' => 'd',
					'price'             => 'd', 'currency' => 'c', 'status' => 's', 'renewed_on' => 'date', 'invoice_id' => 'i', 'notes' => 't', 'subject' => 's',
				),
				'filters'  => array( 'client_id', 'client_service_id', 'status', 'frequency', 'currency' ),
				'search'   => array( 'c.company_name', 's.name', 't.subject' ),
				'order'    => 't.due_date ASC',
				'order_map' => array( 'client_name' => 'c.company_name', 'service_name' => 's.name' ),
				'required' => array( 'client_service_id', 'due_date' ),
				'title'    => function ( $r ) {
					return $r['service_name'] . ( ! empty( $r['subject'] ) ? ' (' . $r['subject'] . ')' : '' ) . ' — ' . $r['client_name'] . ' (' . $r['due_date'] . ')';
				},
				'before_save' => function ( $d, $existing ) {
					global $wpdb;
					if ( ! $existing ) {
						$cs = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'client_services' ) . ' WHERE id=%d', isset( $d['client_service_id'] ) ? $d['client_service_id'] : 0 ), ARRAY_A ); // phpcs:ignore WordPress.DB
						if ( ! $cs ) {
							return new \WP_Error( 'bizydash_invalid', 'Choose a valid client service.', array( 'status' => 400 ) );
						}
						$d['client_id'] = (int) $cs['client_id'];
						$d             += array(
							'frequency' => $cs['billing_period'],
							'cost'      => (float) $cs['cost'],
							'price'     => Billing::billable_price( $cs['billing_treatment'], $cs['client_price'] ),
							'currency'  => $cs['currency'],
							'subject'   => $cs['subject'],
							'status'    => 'upcoming',
						);
					}
					return $d;
				},
				'decorate' => function ( $rows ) {
					$rows = Renewals::decorate( $rows );
					foreach ( $rows as &$r ) {
						$r['bill_status'] = Billing::bill_status( $r, array( 'upcoming', 'due' ) );
					}
					return $rows;
				},
			),

			'charges'         => array(
				'route'    => 'charges',
				'table'    => 'charges',
				'label'    => 'One-off bill',
				'select'   => ', c.company_name AS client_name, s.name AS service_name, inv.invoice_number, inv.status AS invoice_status',
				'join'     => 'LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=t.client_id LEFT JOIN ' . Schema::table( 'client_services' ) . ' cs ON cs.id=t.client_service_id LEFT JOIN ' . Schema::table( 'services' ) . ' s ON s.id=IF(t.service_id>0, t.service_id, cs.service_id) LEFT JOIN ' . Schema::table( 'invoices' ) . ' inv ON inv.id=t.invoice_id' . ' LEFT JOIN ' . Schema::table( 'brands' ) . ' dp ON dp.id=t.department_id',
				'extra'    => function ( $a ) {
					$f = ! empty( $a['bill_status'] ) ? Billing::bill_status_sql( $a['bill_status'], "'pending'" ) : '';
					return array( $f, array() );
				},
				'cols'     => array(
					'client_id' => 'i', 'client_service_id' => 'i', 'service_id' => 'i', 'renewal_id' => 'i', 'description' => 's', 'cost' => 'd', 'price' => 'd', 'currency' => 'c',
					'incurred_on' => 'date', 'billing_timing' => 's', 'bill_on_date' => 'date', 'status' => 's', 'invoice_id' => 'i', 'notes' => 't', 'department_id' => 'i',
				),
				'filters'  => array( 'client_id', 'client_service_id', 'service_id', 'status', 'billing_timing', 'currency', 'invoice_id', 'department_id' ),
				'search'   => array( 't.description', 'c.company_name' ),
				'order'    => 't.bill_on_date IS NULL, t.bill_on_date ASC, t.id DESC',
				'order_map' => array( 'client_name' => 'c.company_name' ),
				'required' => array( 'client_id', 'description' ),
				'title'    => function ( $r ) {
					return $r['description'] . ' — ' . $r['client_name'];
				},
				'before_save' => function ( $d, $existing, $params ) {
					global $wpdb;
					$timings = Resources::TIMINGS;
					if ( ! $existing && empty( $params['department_id'] ) ) {
						$svc_id = ! empty( $d['service_id'] ) ? (int) $d['service_id'] : ( ! empty( $d['client_service_id'] ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT service_id FROM ' . Schema::table( 'client_services' ) . ' WHERE id=%d', (int) $d['client_service_id'] ) ) : 0 ); // phpcs:ignore WordPress.DB
						$d['department_id'] = Services\Finance::default_department( $svc_id, isset( $d['client_id'] ) ? $d['client_id'] : 0 ); // the catalog item's department, else the client's
					}
					// Picking a catalogue service prefills price/cost (unless supplied) and finds the client's matching subscription for "next renewal" timing.
					if ( ! empty( $d['service_id'] ) ) {
						$svc = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'services' ) . ' WHERE id=%d', $d['service_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
						if ( $svc && ! $existing ) {
							foreach ( array( 'price' => 'default_price', 'cost' => 'default_cost' ) as $k => $from ) {
								if ( ! isset( $params[ $k ] ) || '' === $params[ $k ] ) {
									$d[ $k ] = (float) $svc[ $from ];
								}
							}
							if ( empty( $d['description'] ) ) {
								$d['description'] = $svc['name'];
							}
						}
						$cid_for = isset( $d['client_id'] ) ? $d['client_id'] : ( $existing ? $existing['client_id'] : 0 );
						if ( $cid_for && empty( $d['client_service_id'] ) && ( ! $existing || empty( $existing['client_service_id'] ) ) ) {
							$cs_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'client_services' ) . " WHERE client_id=%d AND service_id=%d AND status='active' ORDER BY id DESC LIMIT 1", $cid_for, $d['service_id'] ) ); // phpcs:ignore WordPress.DB
							if ( $cs_id ) {
								$d['client_service_id'] = $cs_id;
							}
						}
					}
					if ( isset( $d['billing_timing'] ) && ! in_array( $d['billing_timing'], $timings, true ) ) {
						return new \WP_Error( 'bizydash_invalid', 'Unknown billing timing.', array( 'status' => 400 ) );
					}
					if ( ! $existing ) {
						$d += array( 'status' => 'pending', 'billing_timing' => 'now', 'incurred_on' => Billing::today() );
						if ( empty( $d['incurred_on'] ) ) {
							$d['incurred_on'] = Billing::today();
						}
					}
					$merged = array_merge( $existing ? $existing : array(), $d );
					if ( empty( $merged['currency'] ) ) {
						$d['currency'] = $merged['currency'] = Billing::client_currency( $merged['client_id'] );
					}
					if ( ! empty( $merged['client_service_id'] ) ) {
						global $wpdb;
						$cid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT client_id FROM ' . Schema::table( 'client_services' ) . ' WHERE id=%d', $merged['client_service_id'] ) ); // phpcs:ignore WordPress.DB
						if ( $cid && $cid !== (int) $merged['client_id'] ) {
							return new \WP_Error( 'bizydash_invalid', 'That service belongs to a different client.', array( 'status' => 400 ) );
						}
					}
					// Recompute the bill date when timing / incurred date changed, or on create without an explicit custom date.
					$changed = ! $existing
						|| ( isset( $d['billing_timing'] ) && $d['billing_timing'] !== $existing['billing_timing'] )
						|| ( isset( $d['incurred_on'] ) && $d['incurred_on'] !== $existing['incurred_on'] )
						|| ( isset( $d['client_service_id'] ) && (int) $d['client_service_id'] !== (int) $existing['client_service_id'] );
					if ( $changed && 'custom' !== $merged['billing_timing'] ) {
						list( $date, $rid ) = Charges::resolve_bill_date( $merged );
						$d['bill_on_date']  = $date;
						$d['renewal_id']    = $rid;
					} elseif ( 'custom' === $merged['billing_timing'] ) {
						$d['renewal_id'] = 0;
					}
					return $d;
				},
				'decorate' => function ( $rows ) {
					foreach ( $rows as &$r ) {
						$r['margin']      = round( $r['price'] - $r['cost'], 2 );
						$r['bill_status'] = Billing::bill_status( $r, array( 'pending' ) );
					}
					return $rows;
				},
			),

			'invoices'        => array(
				'route'    => 'invoices',
				'table'    => 'invoices',
				'label'    => 'Invoice',
				'select'   => ', c.company_name AS client_name, dp.name AS department_name, (SELECT COALESCE(SUM(ii.cost),0) FROM ' . Schema::table( 'invoice_items' ) . ' ii WHERE ii.invoice_id=t.id) AS cost_total',
				'join'     => self::client_join() . ' LEFT JOIN ' . Schema::table( 'brands' ) . ' dp ON dp.id=t.department_id',
				'cols'     => array(
					'client_id' => 'i', 'invoice_number' => 's', 'title' => 's', 'description' => 't', 'amount' => 'd', 'currency' => 'c', 'issue_date' => 'date', 'due_date' => 'date',
					'status'    => 's', 'invoice_url' => 'u', 'attachment_id' => 'i', 'notes' => 't', 'terms' => 't',
					'subtotal'  => 'd', 'discount_type' => 's', 'discount_value' => 'd', 'discount_amount' => 'd', 'tax_rate' => 'd', 'tax_amount' => 'd',
					'amount_paid' => 'd', 'view_count' => 'i', 'department_id' => 'i',
				),
				'filters'  => array( 'client_id', 'status', 'currency' ),
				'search'   => array( 't.invoice_number', 't.title', 't.description', 'c.company_name' ),
				'order'    => 't.due_date IS NULL, t.due_date DESC, t.id DESC',
				'order_map' => array( 'client_name' => 'c.company_name' ),
				'required' => array( 'client_id' ),
				'title'    => function ( $r ) {
					return $r['invoice_number'] . ' — ' . $r['client_name'];
				},
				'extra'    => function ( $a ) {
					$sql  = array();
					$args = array();
					if ( ! empty( $a['unpaid'] ) ) {
						$sql[] = "t.status IN ('sent','due','partially_paid','overdue')";
					}
					if ( ! empty( $a['department_id'] ) ) {
						// An invoice belongs to a department by its own setting, or — when set "by line" — through any of its lines.
						$sql[]  = '(t.department_id=%d OR (t.department_id=0 AND EXISTS (SELECT 1 FROM ' . Schema::table( 'invoice_items' ) . ' dl WHERE dl.invoice_id=t.id AND dl.department_id=%d)))';
						$args[] = (int) $a['department_id'];
						$args[] = (int) $a['department_id'];
					}
					return array( implode( ' AND ', $sql ), $args );
				},
				'before_save' => function ( $d, $existing, $params ) {
					// Server-computed / system fields can never be set from the client.
					unset( $d['amount_paid'], $d['view_count'], $d['subtotal'], $d['discount_amount'], $d['tax_amount'] );
					if ( isset( $d['status'] ) && ! in_array( $d['status'], Resources::INVOICE_STATUSES, true ) ) {
						return new \WP_Error( 'bizydash_invalid', 'Unknown invoice status.', array( 'status' => 400 ) );
					}
					if ( isset( $d['discount_type'] ) && ! in_array( $d['discount_type'], array( 'none', 'percent', 'fixed' ), true ) ) {
						$d['discount_type'] = 'none';
					}
					$client_id  = isset( $d['client_id'] ) ? $d['client_id'] : ( $existing ? $existing['client_id'] : 0 );
					$invoice_id = $existing ? $existing['id'] : 0;

					if ( ! $existing ) {
						$d += array( 'status' => 'draft', 'issue_date' => Billing::today(), 'terms' => (string) Settings::get( 'invoice_terms' ) );
						if ( empty( $d['invoice_number'] ) ) {
							$d['invoice_number'] = Invoices::next_number();
						}
					}
					if ( empty( $d['currency'] ) && ( ! $existing || ( isset( $d['currency'] ) && '' === $d['currency'] ) ) ) {
						$d['currency'] = Billing::client_currency( $client_id );
					}

					$total = isset( $d['amount'] ) ? (float) $d['amount'] : ( $existing ? (float) $existing['amount'] : 0.0 );
					if ( isset( $d['title'] ) ) {
						$d['title'] = mb_substr( trim( $d['title'] ), 0, 190 );
					}
					if ( isset( $params['items'] ) && is_array( $params['items'] ) ) {
						if ( ( isset( $d['title'] ) && '' === $d['title'] ) || ( ! $existing && ! isset( $d['title'] ) ) ) {
							$d['title'] = Invoices::default_title( Invoices::normalize_lines( $params['items'] ), isset( $d['issue_date'] ) ? $d['issue_date'] : ( $existing ? $existing['issue_date'] : '' ) );
						}
						$ok = Invoices::validate_lines( $client_id, $params['items'], $invoice_id );
						if ( is_wp_error( $ok ) ) {
							return $ok;
						}
						$type = isset( $d['discount_type'] ) ? $d['discount_type'] : ( $existing ? $existing['discount_type'] : 'none' );
						$val  = isset( $d['discount_value'] ) ? $d['discount_value'] : ( $existing ? $existing['discount_value'] : 0 );
						$rate = isset( $d['tax_rate'] ) ? $d['tax_rate'] : ( $existing ? $existing['tax_rate'] : 0 );
						$tot  = Invoices::compute_totals( Invoices::normalize_lines( $params['items'] ), $type, $val, $rate );
						$d['subtotal']        = $tot['subtotal'];
						$d['discount_amount'] = $tot['discount_amount'];
						$d['tax_amount']      = $tot['tax_amount'];
						$d['amount']          = $tot['total'];
						$total                = $tot['total'];
					} elseif ( ! $existing && isset( $d['amount'] ) ) {
						$d['subtotal'] = $d['amount']; // simple manual invoice
					}

					if ( isset( $params['installments'] ) && is_array( $params['installments'] ) ) {
						$norm = Invoices::normalize_installments( $params['installments'], $total );
						if ( is_wp_error( $norm ) ) {
							return $norm;
						}
						$last = $norm ? end( $norm ) : null;
						if ( $last && ! empty( $last['due_date'] ) ) {
							$d['due_date'] = $last['due_date']; // the invoice is fully due at its last instalment
						}
					}
					return $d;
				},
				'after_save' => function ( $row, $existing, $params ) {
					global $wpdb;
					if ( isset( $params['items'] ) && is_array( $params['items'] ) ) {
						Invoices::apply_lines( $row['id'], $params['items'] );
						Invoices::restamp_payments( $row['id'] ); // the mix of lines may have changed
					} elseif ( $existing && (int) $existing['department_id'] !== (int) $row['department_id'] ) {
						Invoices::restamp_lines( $row['id'] ); // attached to another department: the lines follow
					}
					if ( isset( $params['installments'] ) && is_array( $params['installments'] ) ) {
						$norm = Invoices::normalize_installments( $params['installments'], $row['amount'] );
						Invoices::save_installments( $row['id'], is_array( $norm ) ? $norm : array() );
					}
					// "Mark paid" from the status field: record the balance as a payment so the books agree.
					if ( 'paid' === $row['status'] && ( ! $existing || 'paid' !== $existing['status'] ) ) {
						$paid    = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . Schema::table( 'payments' ) . ' WHERE invoice_id=%d', $row['id'] ) ); // phpcs:ignore WordPress.DB
						$balance = round( $row['amount'] - $paid, 2 );
						if ( $balance > 0 ) {
							$now = current_time( 'mysql' );
							$wpdb->insert( Schema::table( 'payments' ), array( 'invoice_id' => $row['id'], 'client_id' => $row['client_id'], 'amount' => $balance, 'currency' => $row['currency'], 'paid_on' => Billing::today(), 'reference' => 'Marked as paid', 'department_id' => Invoices::payment_department( $row['id'] ), 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
						}
					}
					if ( 'cancelled' === $row['status'] && ( ! $existing || 'cancelled' !== $existing['status'] ) ) {
						Invoices::release_sources( $row['id'] );
					}
					if ( 'sent' === $row['status'] && empty( $row['sent_at'] ) ) {
						$wpdb->update( Schema::table( 'invoices' ), array( 'sent_at' => current_time( 'mysql' ) ), array( 'id' => $row['id'] ) );
					}
					Invoices::refresh_status( $row['id'] );
				},
				'decorate' => function ( $rows, $single ) {
					global $wpdb;
					foreach ( $rows as &$r ) {
						$r['balance']        = round( $r['amount'] - $r['amount_paid'], 2 );
						$cost_total          = isset( $r['cost_total'] ) ? (float) $r['cost_total'] : 0.0;
						$r['cost_total']     = $cost_total;
						$r['margin']         = round( ( (float) $r['amount'] - (float) $r['tax_amount'] ) - $cost_total, 2 ); // before tax; staff-only
						$r['attachment_url'] = $r['attachment_id'] ? wp_get_attachment_url( $r['attachment_id'] ) : '';
						$r['public_url']     = Invoices::public_url( isset( $r['public_token'] ) ? $r['public_token'] : '' );
						unset( $r['public_token'] );
						if ( $single ) {
							$r['items']    = $wpdb->get_results( $wpdb->prepare( 'SELECT it.*, s.name AS service_name FROM ' . Schema::table( 'invoice_items' ) . ' it LEFT JOIN ' . Schema::table( 'client_services' ) . ' cs ON cs.id=it.client_service_id LEFT JOIN ' . Schema::table( 'services' ) . ' s ON s.id=cs.service_id WHERE it.invoice_id=%d ORDER BY it.id', $r['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
							$r['payments'] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'payments' ) . ' WHERE invoice_id=%d ORDER BY paid_on, id', $r['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
							$r['schedule'] = Invoices::schedule( $r );
							$r['has_installments'] = ! empty( $r['schedule'] ) && empty( $r['schedule'][0]['implicit'] );
						}
					}
					return $rows;
				},
				'after_delete' => function ( $row ) {
					global $wpdb;
					Invoices::release_sources( $row['id'] );
					$wpdb->delete( Schema::table( 'invoice_items' ), array( 'invoice_id' => $row['id'] ) );
					$wpdb->delete( Schema::table( 'invoice_installments' ), array( 'invoice_id' => $row['id'] ) );
					$wpdb->delete( Schema::table( 'payments' ), array( 'invoice_id' => $row['id'] ) );
				},
			),

			'payments'        => array(
				'route'    => 'payments',
				'table'    => 'payments',
				'label'    => 'Payment',
				'select'   => ', i.invoice_number, c.company_name AS client_name, dp.name AS department_name',
				'join'     => 'LEFT JOIN ' . Schema::table( 'invoices' ) . ' i ON i.id=t.invoice_id LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=t.client_id LEFT JOIN ' . Schema::table( 'brands' ) . ' dp ON dp.id=t.department_id',
				'cols'     => array( 'invoice_id' => 'i', 'client_id' => 'i', 'amount' => 'd', 'currency' => 'c', 'paid_on' => 'date', 'reference' => 's', 'method' => 's', 'notes' => 't', 'department_id' => 'i' ),
				'filters'  => array( 'invoice_id', 'client_id', 'department_id' ),
				'search'   => array( 't.reference', 'i.invoice_number', 'c.company_name' ),
				'order_map' => array( 'client_name' => 'c.company_name' ),
				'order'    => 't.paid_on DESC, t.id DESC',
				'required' => array( 'invoice_id', 'amount' ),
				'title'    => function ( $r ) {
					return $r['invoice_number'] . ' (' . $r['amount'] . ' ' . $r['currency'] . ')';
				},
				'before_save' => function ( $d, $existing ) {
					global $wpdb;
					if ( isset( $d['amount'] ) && $d['amount'] <= 0 ) {
						return new \WP_Error( 'bizydash_invalid', 'Payment amount must be greater than zero.', array( 'status' => 400 ) );
					}
					if ( ! $existing ) {
						$inv = $wpdb->get_row( $wpdb->prepare( 'SELECT client_id, currency FROM ' . Schema::table( 'invoices' ) . ' WHERE id=%d', isset( $d['invoice_id'] ) ? $d['invoice_id'] : 0 ), ARRAY_A ); // phpcs:ignore WordPress.DB
						if ( ! $inv ) {
							return new \WP_Error( 'bizydash_invalid', 'Choose a valid invoice.', array( 'status' => 400 ) );
						}
						$d['client_id'] = (int) $inv['client_id'];
						$d['currency']  = $inv['currency'];
						$d['department_id'] = Invoices::payment_department( $d['invoice_id'] ); // counts under the invoice's department
						if ( empty( $d['paid_on'] ) ) {
							$d['paid_on'] = Billing::today();
						}
					}
					return $d;
				},
				'after_save' => function ( $row ) {
					Invoices::refresh_status( $row['invoice_id'] );
				},
				'after_delete' => function ( $row ) {
					Invoices::refresh_status( $row['invoice_id'] );
				},
			),

			'activity'        => array(
				'route'      => 'activity',
				'table'      => 'activity',
				'label'      => 'Activity',
				'readonly'   => true,
				'log'        => false,
				'timestamps' => false,
				'select'     => ', c.company_name AS client_name',
				'join'       => self::client_join(),
				'cols'       => array( 'entity_type' => 's', 'entity_id' => 'i', 'client_id' => 'i', 'action' => 's', 'summary' => 's', 'user_id' => 'i' ),
				'filters'    => array( 'entity_type', 'entity_id', 'client_id', 'action' ),
				'search'     => array( 't.summary', 'c.company_name' ),
				'order'      => 't.id DESC',
				'decorate'   => function ( $rows ) {
					foreach ( $rows as &$r ) {
						$u              = $r['user_id'] ? get_userdata( $r['user_id'] ) : null;
						$r['user_name'] = $u ? $u->display_name : '';
					}
					return $rows;
				},
			),

			'contacts'        => array(
				'route'    => 'contacts',
				'table'    => 'client_contacts',
				'label'    => 'Contact',
				'cols'     => array( 'client_id' => 'i', 'name' => 's', 'email' => 'e', 'phone' => 's', 'job_title' => 's', 'kind' => 's', 'sort_order' => 'i' ),
				'filters'  => array( 'client_id', 'kind' ),
				'search'   => array( 'name', 'email', 'job_title' ),
				'order'    => "t.kind='primary' DESC, t.sort_order ASC, t.id ASC",
				'required' => array( 'client_id', 'name' ),
				'title'    => function ( $r ) {
					return $r['name'] . ( $r['email'] ? ' <' . $r['email'] . '>' : '' );
				},
				'before_save' => function ( $d, $existing, $params ) {
					global $wpdb;
					if ( isset( $params['email'] ) && '' !== trim( (string) $params['email'] ) && empty( $d['email'] ) ) {
						return new \WP_Error( 'bizydash_invalid', 'Enter a valid email address.', array( 'status' => 400 ) );
					}
					$cid = isset( $d['client_id'] ) ? (int) $d['client_id'] : ( $existing ? (int) $existing['client_id'] : 0 );
					if ( ! isset( $d['kind'] ) || ! in_array( $d['kind'], array( 'primary', 'secondary' ), true ) ) {
						// First contact for a client is primary; later ones default to secondary.
						$has = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'client_contacts' ) . ' WHERE client_id=%d', $cid ) ); // phpcs:ignore WordPress.DB
						$d['kind'] = $existing ? $existing['kind'] : ( $has ? 'secondary' : 'primary' );
					}
					$email = isset( $d['email'] ) ? $d['email'] : ( $existing ? $existing['email'] : '' );
					if ( 'primary' === $d['kind'] && '' === $email ) {
						return new \WP_Error( 'bizydash_invalid', 'Primary contacts need an email address so invoices can reach them.', array( 'status' => 400 ) );
					}
					return $d;
				},
				'after_save' => function ( $row ) {
					\BizyDash\Services\Contacts::sync_client( $row['client_id'] );
				},
				'after_delete' => function ( $row ) {
					\BizyDash\Services\Contacts::sync_client( $row['client_id'] );
				},
			),

			'team'            => array(
				'route'    => 'team',
				'table'    => 'team_members',
				'label'    => 'Team member',
				'log'      => false,
				'select'   => ', dp.name AS department_name',
				'join'     => 'LEFT JOIN ' . Schema::table( 'brands' ) . ' dp ON dp.id=t.department_id',
				'cols'     => array( 'name' => 's', 'email' => 'e', 'role' => 's', 'cc_invoices' => 'b', 'wp_user_id' => 'i', 'kind' => 's', 'department_id' => 'i', 'salary_amount' => 'd', 'salary_period' => 's', 'currency' => 'c', 'start_date' => 'date', 'end_date' => 'date', 'bank_name' => 's', 'account_name' => 's', 'account_number' => 's', 'notes' => 't', 'status' => 's' ),
				'filters'  => array( 'status', 'kind', 'department_id' ),
				'search'   => array( 'name', 'email', 'role' ),
				'order'    => 't.name ASC',
				'required' => array( 'name', 'email' ),
				'before_save' => function ( $d, $existing, $params ) {
					if ( isset( $params['email'] ) && empty( $d['email'] ) ) {
						return new \WP_Error( 'bizydash_invalid', 'Enter a valid email address.', array( 'status' => 400 ) );
					}
					if ( ! $existing ) {
						$d += array( 'status' => 'active', 'cc_invoices' => 1 );
					}
					// Link to the WordPress login with the same email so "My tasks" works.
					$email = isset( $d['email'] ) ? $d['email'] : ( $existing ? $existing['email'] : '' );
					if ( $email && ! isset( $d['wp_user_id'] ) && ( ! $existing || empty( $existing['wp_user_id'] ) ) ) {
						$u = get_user_by( 'email', $email );
						if ( $u ) {
							$d['wp_user_id'] = (int) $u->ID;
						}
					}
					return $d;
				},
				'decorate' => function ( $rows ) {
					return \BizyDash\Services\Team::decorate( $rows );
				},
			),

			'projects'        => array(
				'route'    => 'projects',
				'table'    => 'projects',
				'label'    => 'Project',
				'select'   => ', c.company_name AS client_name, o.name AS owner_name, dp.name AS department_name, (SELECT COUNT(*) FROM ' . Schema::table( 'tasks' ) . ' tk WHERE tk.project_id=t.id) AS task_total, (SELECT COUNT(*) FROM ' . Schema::table( 'tasks' ) . " tk2 WHERE tk2.project_id=t.id AND tk2.status='done') AS task_done",
				'join'     => 'LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=t.client_id LEFT JOIN ' . Schema::table( 'team_members' ) . ' o ON o.id=t.owner_id LEFT JOIN ' . Schema::table( 'brands' ) . ' dp ON dp.id=t.department_id',
				'cols'     => array( 'client_id' => 'i', 'name' => 's', 'description' => 't', 'status' => 's', 'start_date' => 'date', 'due_date' => 'date', 'owner_id' => 'i', 'department_id' => 'i', 'price' => 'd', 'currency' => 'c' ),
				'filters'  => array( 'client_id', 'status', 'owner_id', 'department_id' ),
				'search'   => array( 't.name', 'c.company_name', 't.description' ),
				'order'    => "FIELD(t.status,'active','planned','on_hold','done'), t.due_date IS NULL, t.due_date ASC, t.id DESC",
				'order_map' => array( 'client_name' => 'c.company_name', 'owner_name' => 'o.name' ),
				'required' => array( 'name' ),
				'title'    => function ( $r ) {
					return $r['name'];
				},
				'before_save' => function ( $d, $existing ) {
					if ( isset( $d['status'] ) && ! in_array( $d['status'], array( 'planned', 'active', 'on_hold', 'done' ), true ) ) {
						return new \WP_Error( 'bizydash_invalid', 'Unknown project status.', array( 'status' => 400 ) );
					}
					if ( ! $existing ) {
						$d += array( 'status' => 'active' );
					}
					return $d;
				},
				'decorate' => function ( $rows ) {
					foreach ( $rows as &$r ) {
						$tot                = (int) $r['task_total'];
						$r['task_total']    = $tot;
						$r['task_done']     = (int) $r['task_done'];
						$r['progress']      = $tot ? (int) round( 100 * $r['task_done'] / $tot ) : 0;
						if ( current_user_can( 'bizydash_finance' ) ) {
							$r['money'] = \BizyDash\Services\Pay::project_money( $r );
						} else {
							unset( $r['price'] );
						}
						$r['is_overdue']    = ! empty( $r['due_date'] ) && 'done' !== $r['status'] && $r['due_date'] < Billing::today();
					}
					return $rows;
				},
				'after_delete' => function ( $row ) {
					global $wpdb;
					$wpdb->update( Schema::table( 'tasks' ), array( 'project_id' => 0 ), array( 'project_id' => (int) $row['id'] ) ); // keep the tasks, just unlink
				},
			),

			'tasks'           => array(
				'route'    => 'tasks',
				'table'    => 'tasks',
				'label'    => 'Task',
				'select'   => ', c.company_name AS client_name, p.name AS project_name, ph.name AS phase_name, a.name AS assignee_name, cs.subject AS subscription_subject, sv.name AS subscription_name',
				'join'     => 'LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=t.client_id LEFT JOIN ' . Schema::table( 'projects' ) . ' p ON p.id=t.project_id LEFT JOIN ' . Schema::table( 'team_members' ) . ' a ON a.id=t.assignee_id LEFT JOIN ' . Schema::table( 'client_services' ) . ' cs ON cs.id=t.client_service_id LEFT JOIN ' . Schema::table( 'services' ) . ' sv ON sv.id=cs.service_id LEFT JOIN ' . Schema::table( 'project_phases' ) . ' ph ON ph.id=t.phase_id',
				'cols'     => array( 'project_id' => 'i', 'client_id' => 'i', 'title' => 's', 'notes' => 't', 'status' => 's', 'priority' => 's', 'due_date' => 'date', 'assignee_id' => 'i', 'client_service_id' => 'i', 'invoice_id' => 'i', 'phase_id' => 'i', 'pay_amount' => 'd', 'sort_order' => 'i', 'department_id' => 'i' ),
				'filters'  => array( 'client_id', 'project_id', 'phase_id', 'status', 'priority', 'assignee_id' ),
				'extra'    => function ( $a ) {
					global $wpdb;
					$sql  = array();
					$args = array();
					$today = Billing::today();
					if ( ! empty( $a['open'] ) ) {
						$sql[] = "t.status <> 'done'";
					}
					if ( isset( $a['department_id'] ) && '' !== $a['department_id'] ) {
						$sql[]  = 'COALESCE(NULLIF(t.department_id,0), NULLIF(ph.department_id,0), NULLIF(p.department_id,0), 0) = %d'; // its own, else the phase's, else the project's
						$args[] = (int) $a['department_id'];
					}
					if ( ! empty( $a['mine'] ) ) {
						$sql[]  = 't.assignee_id IN (SELECT id FROM ' . Schema::table( 'team_members' ) . ' WHERE wp_user_id=%d)';
						$args[] = get_current_user_id();
					}
					if ( ! empty( $a['due'] ) ) {
						if ( 'overdue' === $a['due'] ) {
							$sql[]  = "t.status <> 'done' AND t.due_date < %s";
							$args[] = $today;
						} elseif ( 'today' === $a['due'] ) {
							$sql[]  = "t.status <> 'done' AND t.due_date <= %s";
							$args[] = $today;
						} elseif ( 'week' === $a['due'] ) {
							$sql[]  = "t.status <> 'done' AND t.due_date <= %s";
							$args[] = gmdate( 'Y-m-d', strtotime( $today . ' +7 days' ) );
						}
					}
					return array( implode( ' AND ', $sql ), $args );
				},
				'search'   => array( 't.title', 't.notes', 'c.company_name', 'p.name' ),
				'order'    => "FIELD(t.status,'doing','todo','done'), t.due_date IS NULL, t.due_date ASC, t.sort_order ASC, t.id DESC",
				'order_map' => array( 'client_name' => 'c.company_name', 'project_name' => 'p.name', 'assignee_name' => 'a.name' ),
				'required' => array( 'title' ),
				'title'    => function ( $r ) {
					return $r['title'];
				},
				'before_save' => function ( $d, $existing ) {
					global $wpdb;
					if ( isset( $d['status'] ) && ! in_array( $d['status'], array( 'todo', 'doing', 'done' ), true ) ) {
						return new \WP_Error( 'bizydash_invalid', 'Unknown task status.', array( 'status' => 400 ) );
					}
					if ( ! current_user_can( 'bizydash_finance' ) ) {
						unset( $d['pay_amount'] ); // pay is a finance matter
					}
					if ( isset( $d['priority'] ) && ! in_array( $d['priority'], array( 'normal', 'high' ), true ) ) {
						$d['priority'] = 'normal';
					}
					if ( ! $existing ) {
						$d += array( 'status' => 'todo', 'priority' => 'normal' );
					}
					$pid = isset( $d['project_id'] ) ? (int) $d['project_id'] : ( $existing ? (int) $existing['project_id'] : 0 );
					$cid = isset( $d['client_id'] ) ? (int) $d['client_id'] : ( $existing ? (int) $existing['client_id'] : 0 );
					if ( $pid && ! $cid ) {
						$d['client_id'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT client_id FROM ' . Schema::table( 'projects' ) . ' WHERE id=%d', $pid ) ); // phpcs:ignore WordPress.DB
					}
					if ( isset( $d['status'] ) ) {
						$was = $existing ? $existing['status'] : '';
						if ( 'done' === $d['status'] && 'done' !== $was ) {
							$d['completed_at'] = current_time( 'mysql' );
						}
					}
					return $d;
				},
				'after_save' => function ( $row ) {
					global $wpdb;
					$tbl = Schema::table( 'tasks' );
					\BizyDash\Services\Pay::sync_task( $row );
					if ( 'done' !== $row['status'] && ! empty( $row['completed_at'] ) ) {
						$wpdb->query( $wpdb->prepare( "UPDATE $tbl SET completed_at = NULL WHERE id=%d", $row['id'] ) ); // phpcs:ignore WordPress.DB
					} elseif ( 'done' === $row['status'] && empty( $row['completed_at'] ) ) {
						$wpdb->query( $wpdb->prepare( "UPDATE $tbl SET completed_at = %s WHERE id=%d", current_time( 'mysql' ), $row['id'] ) ); // phpcs:ignore WordPress.DB
					}
				},
				'decorate' => function ( $rows ) {
					$today = Billing::today();
					$fin = current_user_can( 'bizydash_finance' );
					foreach ( $rows as &$r ) {
						if ( ! $fin ) {
							unset( $r['pay_amount'] );
						}
						$r['is_overdue'] = ! empty( $r['due_date'] ) && 'done' !== $r['status'] && $r['due_date'] < $today;
						$r['is_today']   = ! empty( $r['due_date'] ) && 'done' !== $r['status'] && $r['due_date'] === $today;
					}
					return $rows;
				},
			),

			'expenses'        => array(
				'route'    => 'expenses',
				'table'    => 'expenses',
				'label'    => 'Expense',
				'finance'  => true,
				'select'   => ', dp.name AS department_name, c.company_name AS client_name',
				'join'     => 'LEFT JOIN ' . Schema::table( 'brands' ) . ' dp ON dp.id=t.department_id LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=t.client_id',
				'cols'     => array( 'spent_on' => 'date', 'amount' => 'd', 'currency' => 'c', 'category' => 's', 'vendor' => 's', 'description' => 's', 'department_id' => 'i', 'client_id' => 'i', 'project_id' => 'i', 'method' => 's', 'reference' => 's', 'attachment_id' => 'i', 'status' => 's', 'notes' => 't' ),
				'filters'  => array( 'category', 'department_id', 'client_id', 'project_id', 'status', 'currency' ),
				'extra'    => function ( $a ) {
					$sql  = array();
					$args = array();
					if ( ! empty( $a['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $a['from'] ) ) {
						$sql[]  = 't.spent_on >= %s';
						$args[] = $a['from'];
					}
					if ( ! empty( $a['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $a['to'] ) ) {
						$sql[]  = 't.spent_on <= %s';
						$args[] = $a['to'];
					}
					return array( implode( ' AND ', $sql ), $args );
				},
				'search'   => array( 't.vendor', 't.description', 't.reference', 't.notes' ),
				'order'    => 't.spent_on DESC, t.id DESC',
				'order_map' => array( 'department_name' => 'dp.name' ),
				'required' => array( 'amount' ),
				'title'    => function ( $r ) {
					return trim( $r['vendor'] . ' ' . $r['description'] ) . ' (' . $r['amount'] . ' ' . $r['currency'] . ')';
				},
				'before_save' => function ( $d, $existing ) {
					if ( isset( $d['amount'] ) && (float) $d['amount'] <= 0 ) {
						return new \WP_Error( 'bizydash_invalid', 'The amount must be greater than zero.', array( 'status' => 400 ) );
					}
					if ( ! $existing ) {
						$d += array( 'status' => 'paid' );
						if ( empty( $d['spent_on'] ) ) {
							$d['spent_on'] = Billing::today();
						}
						if ( empty( $d['currency'] ) ) {
							$d['currency'] = Settings::get( 'default_currency' );
						}
					}
					return $d;
				},
				'decorate' => function ( $rows ) {
					foreach ( $rows as &$r ) {
						$r['attachment_url'] = $r['attachment_id'] ? wp_get_attachment_url( $r['attachment_id'] ) : '';
						$r['is_recurring']   = (int) $r['recurring_id'] > 0;
					}
					return $rows;
				},
			),

			'recurring-expenses' => array(
				'route'    => 'recurring-expenses',
				'table'    => 'recurring_expenses',
				'label'    => 'Recurring expense',
				'finance'  => true,
				'select'   => ', dp.name AS department_name',
				'join'     => 'LEFT JOIN ' . Schema::table( 'brands' ) . ' dp ON dp.id=t.department_id',
				'cols'     => array( 'name' => 's', 'vendor' => 's', 'category' => 's', 'amount' => 'd', 'currency' => 'c', 'period' => 's', 'next_date' => 'date', 'department_id' => 'i', 'status' => 's', 'notes' => 't' ),
				'filters'  => array( 'category', 'department_id', 'status' ),
				'search'   => array( 't.name', 't.vendor' ),
				'order'    => "FIELD(t.status,'active','paused'), t.next_date ASC, t.id DESC",
				'required' => array( 'name', 'amount' ),
				'title'    => function ( $r ) {
					return $r['name'];
				},
				'before_save' => function ( $d, $existing ) {
					if ( isset( $d['amount'] ) && (float) $d['amount'] <= 0 ) {
						return new \WP_Error( 'bizydash_invalid', 'The amount must be greater than zero.', array( 'status' => 400 ) );
					}
					if ( isset( $d['period'] ) && ( in_array( $d['period'], array( '', 'none', 'custom' ), true ) || Lookups::months_for( $d['period'] ) <= 0 ) ) {
						return new \WP_Error( 'bizydash_invalid', 'Choose how often it repeats (monthly, quarterly, annually…).', array( 'status' => 400 ) );
					}
					if ( ! $existing ) {
						$d += array( 'status' => 'active', 'period' => 'monthly' );
						if ( empty( $d['next_date'] ) ) {
							$d['next_date'] = Billing::today();
						}
						if ( empty( $d['currency'] ) ) {
							$d['currency'] = Settings::get( 'default_currency' );
						}
					}
					return $d;
				},
				'decorate' => function ( $rows ) {
					$today = Billing::today();
					foreach ( $rows as &$r ) {
						$r['monthly_value'] = \BizyDash\Services\Expenses::monthly_value( $r['amount'], $r['period'] );
						$r['days_until']    = $r['next_date'] ? (int) round( ( strtotime( $r['next_date'] ) - strtotime( $today ) ) / DAY_IN_SECONDS ) : null;
					}
					return $rows;
				},
			),

			'project-phases'  => array(
				'route'    => 'project-phases',
				'table'    => 'project_phases',
				'label'    => 'Phase',
				'select'   => ', (SELECT COUNT(*) FROM ' . Schema::table( 'tasks' ) . ' tk WHERE tk.phase_id=t.id) AS task_total, (SELECT COUNT(*) FROM ' . Schema::table( 'tasks' ) . " tk2 WHERE tk2.phase_id=t.id AND tk2.status='done') AS task_done",
				'cols'     => array( 'project_id' => 'i', 'name' => 's', 'sort_order' => 'i', 'status' => 's', 'start_date' => 'date', 'due_date' => 'date', 'price' => 'd', 'team_budget' => 'd', 'department_id' => 'i' ),
				'filters'  => array( 'project_id', 'status', 'department_id' ),
				'search'   => array( 't.name' ),
				'order'    => 't.sort_order ASC, t.id ASC',
				'required' => array( 'project_id', 'name' ),
				'title'    => function ( $r ) {
					return $r['name'];
				},
				'before_save' => function ( $d, $existing ) {
					global $wpdb;
					if ( isset( $d['status'] ) && ! in_array( $d['status'], array( 'todo', 'doing', 'done' ), true ) ) {
						return new \WP_Error( 'bizydash_invalid', 'Unknown phase status.', array( 'status' => 400 ) );
					}
					if ( ! current_user_can( 'bizydash_finance' ) ) {
						unset( $d['price'], $d['team_budget'] );
					}
					if ( ! $existing ) {
						$d += array( 'status' => 'todo' );
						if ( ! isset( $d['sort_order'] ) ) {
							$d['sort_order'] = 1 + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(sort_order),0) FROM ' . Schema::table( 'project_phases' ) . ' WHERE project_id=%d', isset( $d['project_id'] ) ? $d['project_id'] : 0 ) ); // phpcs:ignore WordPress.DB
						}
					}
					return $d;
				},
				'after_save' => function ( $row, $existing ) {
					global $wpdb;
					$tbl = Schema::table( 'project_phases' );
					if ( 'done' === $row['status'] && empty( $row['completed_at'] ) ) {
						$wpdb->query( $wpdb->prepare( "UPDATE $tbl SET completed_at=%s WHERE id=%d", current_time( 'mysql' ), $row['id'] ) ); // phpcs:ignore WordPress.DB
					} elseif ( 'done' !== $row['status'] && ! empty( $row['completed_at'] ) ) {
						$wpdb->query( $wpdb->prepare( "UPDATE $tbl SET completed_at=NULL WHERE id=%d", $row['id'] ) ); // phpcs:ignore WordPress.DB
					}
					if ( $existing ) {
						\BizyDash\Services\Pay::restamp_phase( $row['id'] ); // a phase may have moved department
					}
					\BizyDash\Services\Pay::sync_phase( $row['id'] );
				},
				'before_delete' => function ( $row ) {
					global $wpdb;
					$paid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'pay_items' ) . " WHERE phase_id=%d AND status='paid'", $row['id'] ) ); // phpcs:ignore WordPress.DB
					return $paid ? new \WP_Error( 'bizydash_blocked', 'This phase has paid team members. Void the payouts first, or keep the phase.', array( 'status' => 409 ) ) : true;
				},
				'after_delete' => function ( $row ) {
					global $wpdb;
					$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'pay_items' ) . " SET status='void' WHERE phase_id=%d AND status='payable'", $row['id'] ) ); // phpcs:ignore WordPress.DB
					$wpdb->delete( Schema::table( 'pay_allocations' ), array( 'scope' => 'phase', 'scope_id' => (int) $row['id'] ) );
					$wpdb->update( Schema::table( 'tasks' ), array( 'phase_id' => 0 ), array( 'phase_id' => (int) $row['id'] ) );
				},
				'decorate' => function ( $rows ) {
					global $wpdb;
					$fin = current_user_can( 'bizydash_finance' );
					$ids = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
					$by  = array();
					if ( $fin && $ids ) {
						$in = implode( ',', $ids );
						foreach ( (array) $wpdb->get_results( 'SELECT a.*, m.name AS member_name FROM ' . Schema::table( 'pay_allocations' ) . ' a LEFT JOIN ' . Schema::table( 'team_members' ) . " m ON m.id=a.team_member_id WHERE a.scope='phase' AND a.scope_id IN ($in) ORDER BY a.id", ARRAY_A ) as $a ) { // phpcs:ignore WordPress.DB
							$by[ (int) $a['scope_id'] ][] = $a;
						}
					}
					foreach ( $rows as &$r ) {
						$r['task_total'] = (int) $r['task_total'];
						$r['task_done']  = (int) $r['task_done'];
						if ( $fin ) {
							$alloc = 0.0;
							$list  = array();
							foreach ( isset( $by[ (int) $r['id'] ] ) ? $by[ (int) $r['id'] ] : array() as $a ) {
								$amt     = \BizyDash\Services\Pay::allocation_amount( $a, $r );
								$alloc  += $amt;
								$list[]  = array( 'id' => (int) $a['id'], 'team_member_id' => (int) $a['team_member_id'], 'member_name' => $a['member_name'], 'mode' => $a['mode'], 'value' => (float) $a['value'], 'amount' => $amt );
							}
							$r['allocations']  = $list;
							$r['allocated']    = round( $alloc, 2 );
							$r['over_budget']  = (float) $r['team_budget'] > 0 && $alloc > (float) $r['team_budget'] + 0.004;
						} else {
							unset( $r['price'], $r['team_budget'] );
						}
					}
					return $rows;
				},
			),

			'pay-allocations' => array(
				'route'    => 'pay-allocations',
				'table'    => 'pay_allocations',
				'label'    => 'Pay split',
				'finance'  => true,
				'select'   => ', m.name AS member_name, ph.team_budget AS base_budget, ph.name AS phase_name',
				'join'     => 'LEFT JOIN ' . Schema::table( 'team_members' ) . ' m ON m.id=t.team_member_id LEFT JOIN ' . Schema::table( 'project_phases' ) . " ph ON ph.id=t.scope_id AND t.scope='phase'",
				'cols'     => array( 'scope' => 's', 'scope_id' => 'i', 'team_member_id' => 'i', 'mode' => 's', 'value' => 'd', 'note' => 's' ),
				'filters'  => array( 'scope', 'scope_id', 'team_member_id' ),
				'order'    => 't.id ASC',
				'required' => array( 'scope_id', 'team_member_id' ),
				'title'    => function ( $r ) {
					return $r['member_name'] . ' — ' . $r['phase_name'];
				},
				'before_save' => function ( $d, $existing ) {
					global $wpdb;
					if ( isset( $d['mode'] ) && ! in_array( $d['mode'], array( 'fixed', 'percent' ), true ) ) {
						return new \WP_Error( 'bizydash_invalid', 'Choose fixed amount or percent.', array( 'status' => 400 ) );
					}
					$mode  = isset( $d['mode'] ) ? $d['mode'] : ( $existing ? $existing['mode'] : 'fixed' );
					$value = isset( $d['value'] ) ? (float) $d['value'] : ( $existing ? (float) $existing['value'] : 0 );
					if ( $value < 0 || ( 'percent' === $mode && $value > 100 ) ) {
						return new \WP_Error( 'bizydash_invalid', 'A percentage must be between 0 and 100.', array( 'status' => 400 ) );
					}
					$d['scope'] = 'phase';
					$sid = isset( $d['scope_id'] ) ? (int) $d['scope_id'] : ( $existing ? (int) $existing['scope_id'] : 0 );
					$mid = isset( $d['team_member_id'] ) ? (int) $d['team_member_id'] : ( $existing ? (int) $existing['team_member_id'] : 0 );
					$dup = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'pay_allocations' ) . " WHERE scope='phase' AND scope_id=%d AND team_member_id=%d AND id<>%d", $sid, $mid, $existing ? (int) $existing['id'] : 0 ) ); // phpcs:ignore WordPress.DB
					if ( $dup ) {
						return new \WP_Error( 'bizydash_invalid', 'That person already has a share in this phase — edit it instead.', array( 'status' => 409 ) );
					}
					if ( ! $existing ) {
						$d += array( 'mode' => 'fixed' );
					}
					return $d;
				},
				'after_save' => function ( $row ) {
					\BizyDash\Services\Pay::sync_phase( $row['scope_id'] );
				},
				'before_delete' => function ( $row ) {
					global $wpdb;
					$paid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'pay_items' ) . " WHERE allocation_id=%d AND status='paid'", $row['id'] ) ); // phpcs:ignore WordPress.DB
					return $paid ? new \WP_Error( 'bizydash_blocked', 'This share has already been paid. Void the payout first.', array( 'status' => 409 ) ) : true;
				},
				'after_delete' => function ( $row ) {
					global $wpdb;
					$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'pay_items' ) . " SET status='void' WHERE allocation_id=%d AND status='payable'", $row['id'] ) ); // phpcs:ignore WordPress.DB
				},
				'decorate' => function ( $rows ) {
					foreach ( $rows as &$r ) {
						$r['amount'] = \BizyDash\Services\Pay::allocation_amount( $r, array( 'team_budget' => $r['base_budget'] ) );
					}
					return $rows;
				},
			),

			'pay-items'       => array(
				'route'    => 'pay-items',
				'table'    => 'pay_items',
				'label'    => 'Earning',
				'finance'  => true,
				'select'   => ', m.name AS member_name, pj.name AS project_name, ph.name AS phase_name, po.number AS payout_number, tk.title AS task_title',
				'join'     => 'LEFT JOIN ' . Schema::table( 'team_members' ) . ' m ON m.id=t.team_member_id LEFT JOIN ' . Schema::table( 'projects' ) . ' pj ON pj.id=t.project_id LEFT JOIN ' . Schema::table( 'project_phases' ) . ' ph ON ph.id=t.phase_id LEFT JOIN ' . Schema::table( 'payouts' ) . ' po ON po.id=t.payout_id LEFT JOIN ' . Schema::table( 'tasks' ) . ' tk ON tk.id=t.task_id',
				'cols'     => array( 'team_member_id' => 'i', 'kind' => 's', 'project_id' => 'i', 'phase_id' => 'i', 'task_id' => 'i', 'period' => 's', 'description' => 's', 'amount' => 'd', 'currency' => 'c', 'earned_on' => 'date', 'status' => 's', 'department_id' => 'i' ),
				'filters'  => array( 'team_member_id', 'status', 'kind', 'project_id', 'phase_id', 'task_id', 'currency', 'department_id', 'period' ),
				'extra'    => function ( $a ) {
					$sql  = array();
					$args = array();
					if ( ! empty( $a['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $a['from'] ) ) {
						$sql[]  = 't.earned_on >= %s';
						$args[] = $a['from'];
					}
					if ( ! empty( $a['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $a['to'] ) ) {
						$sql[]  = 't.earned_on <= %s';
						$args[] = $a['to'];
					}
					return array( implode( ' AND ', $sql ), $args );
				},
				'search'   => array( 't.description', 'm.name', 'pj.name' ),
				'order'    => "FIELD(t.status,'payable','paid','void'), t.earned_on DESC, t.id DESC",
				'order_map' => array( 'member_name' => 'm.name' ),
				'required' => array( 'team_member_id', 'amount' ),
				'title'    => function ( $r ) {
					return $r['member_name'] . ': ' . $r['description'];
				},
				'before_save' => function ( $d, $existing, $params = array() ) {
					global $wpdb;
					$manual = array( 'payment', 'bonus', 'deduction', 'reimbursement', 'adjustment' );
					if ( $existing ) {
						$generated = in_array( $existing['kind'], array( 'phase', 'task', 'salary' ), true );
						if ( 'payable' !== $existing['status'] ) {
							return new \WP_Error( 'bizydash_blocked', 'Paid or voided items are never edited. Void the payout and re-create it instead.', array( 'status' => 409 ) );
						}
						if ( $generated ) {
							$keep = array();
							if ( isset( $d['status'] ) && 'void' === $d['status'] ) {
								$keep['status'] = 'void';
							}
							if ( ! $keep ) {
								return new \WP_Error( 'bizydash_blocked', 'This earning was created automatically. Change the phase or task instead, or void it.', array( 'status' => 409 ) );
							}
							return $keep;
						}
					} else {
						$d += array( 'kind' => 'bonus' );
						if ( ! in_array( $d['kind'], $manual, true ) ) {
							return new \WP_Error( 'bizydash_invalid', 'Only a payment, bonus, deduction, reimbursement or adjustment can be added by hand.', array( 'status' => 400 ) );
						}
						$d['status'] = 'payable';
						if ( empty( $d['earned_on'] ) ) {
							$d['earned_on'] = Billing::today();
						}
						if ( empty( $d['team_member_id'] ) || ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'team_members' ) . ' WHERE id=%d', (int) $d['team_member_id'] ) ) ) { // phpcs:ignore WordPress.DB
							return new \WP_Error( 'bizydash_invalid', 'Pick the team member this payment is for.', array( 'status' => 400 ) );
						}
						// Optionally tied to a task: the project and phase follow the task.
						if ( ! empty( $d['task_id'] ) ) {
							$task = $wpdb->get_row( $wpdb->prepare( 'SELECT id, title, project_id, phase_id FROM ' . Schema::table( 'tasks' ) . ' WHERE id=%d', (int) $d['task_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
							if ( ! $task ) {
								return new \WP_Error( 'bizydash_invalid', 'That task no longer exists.', array( 'status' => 400 ) );
							}
							$d['project_id'] = (int) $task['project_id'];
							$d['phase_id']   = (int) $task['phase_id'];
							if ( empty( $params['confirm_duplicate'] ) ) {
								$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'pay_items' ) . " WHERE task_id=%d AND status <> 'void'", (int) $task['id'] ) ); // phpcs:ignore WordPress.DB
								if ( $n ) {
									return new \WP_Error( 'task_already_paid', sprintf( '“%s” already has %d payment%s. Add another one?', $task['title'], $n, 1 === $n ? '' : 's' ), array( 'status' => 409 ) );
								}
							}
						} else {
							unset( $d['task_id'] );
						}
						if ( ! empty( $d['phase_id'] ) && empty( $d['project_id'] ) ) {
							$d['project_id'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT project_id FROM ' . Schema::table( 'project_phases' ) . ' WHERE id=%d', (int) $d['phase_id'] ) ); // phpcs:ignore WordPress.DB
						}
						if ( empty( $d['department_id'] ) ) {
							$d['department_id'] = Services\Pay::item_department( isset( $d['phase_id'] ) ? $d['phase_id'] : 0, isset( $d['project_id'] ) ? $d['project_id'] : 0, $d['team_member_id'] );
						}
						if ( empty( $d['currency'] ) ) {
							$cur = $wpdb->get_var( $wpdb->prepare( 'SELECT currency FROM ' . Schema::table( 'team_members' ) . ' WHERE id=%d', (int) $d['team_member_id'] ) ); // phpcs:ignore WordPress.DB
							$d['currency'] = $cur ? $cur : Settings::get( 'default_currency' );
						}
					}
					if ( isset( $d['status'] ) && ! in_array( $d['status'], array( 'payable', 'void' ), true ) ) {
						unset( $d['status'] );
					}
					$kind = isset( $d['kind'] ) ? $d['kind'] : ( $existing ? $existing['kind'] : 'bonus' );
					if ( isset( $d['amount'] ) ) {
						$d['amount'] = 'deduction' === $kind ? -abs( (float) $d['amount'] ) : ( abs( (float) $d['amount'] ) * ( 'adjustment' === $kind && (float) $d['amount'] < 0 ? -1 : 1 ) );
					}
					return $d;
				},
				'before_delete' => function () {
					return new \WP_Error( 'bizydash_blocked', 'Earnings are never deleted — void them instead so the books stay complete.', array( 'status' => 409 ) );
				},
				'decorate' => function ( $rows ) {
					foreach ( $rows as &$r ) {
						$r['is_generated'] = in_array( $r['kind'], array( 'phase', 'task', 'salary' ), true );
					}
					return $rows;
				},
			),

			'payouts'         => array(
				'route'    => 'payouts',
				'table'    => 'payouts',
				'label'    => 'Payout',
				'finance'  => true,
				'readonly' => true,
				'select'   => ', dp.name AS department_name, m.name AS member_name, (SELECT COUNT(*) FROM ' . Schema::table( 'pay_items' ) . ' pi WHERE pi.payout_id=t.id) AS item_count',
				'join'     => 'LEFT JOIN ' . Schema::table( 'team_members' ) . ' m ON m.id=t.team_member_id LEFT JOIN ' . Schema::table( 'brands' ) . ' dp ON dp.id=t.department_id',
				'cols'     => array( 'number' => 's', 'team_member_id' => 'i', 'paid_on' => 'date', 'method' => 's', 'reference' => 's', 'amount' => 'd', 'currency' => 'c', 'notes' => 't', 'status' => 's', 'department_id' => 'i' ),
				'filters'  => array( 'team_member_id', 'status', 'currency', 'department_id' ),
				'search'   => array( 't.number', 't.reference', 'm.name' ),
				'order'    => 't.paid_on DESC, t.id DESC',
				'order_map' => array( 'member_name' => 'm.name' ),
				'title'    => function ( $r ) {
					return $r['number'];
				},
				'decorate' => function ( $rows, $single ) {
					global $wpdb;
					foreach ( $rows as &$r ) {
						$r['item_count'] = (int) $r['item_count'];
						if ( $single ) {
							$r['items'] = $wpdb->get_results( $wpdb->prepare( 'SELECT id, kind, description, amount, currency, earned_on FROM ' . Schema::table( 'pay_items' ) . ' WHERE payout_id=%d ORDER BY id', $r['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
						}
					}
					return $rows;
				},
			),

			'contract-templates' => array(
				'route'    => 'contract-templates',
				'table'    => 'contract_templates',
				'label'    => 'Contract template',
				'cols'     => array( 'name' => 's', 'description' => 's', 'body' => 'h', 'is_active' => 'b', 'department_id' => 'i' ),
				'filters'  => array( 'is_active', 'department_id' ),
				'search'   => array( 't.name', 't.description' ),
				'order'    => 't.name ASC',
				'required' => array( 'name' ),
				'title'    => function ( $r ) {
					return $r['name'];
				},
				'before_save' => function ( $d ) {
					if ( isset( $d['body'] ) ) {
						$d['body'] = wp_kses_post( $d['body'] );
					}
					return $d;
				},
			),

			'contracts'       => array(
				'route'    => 'contracts',
				'table'    => 'contracts',
				'label'    => 'Contract',
				'select'   => ', c.company_name AS client_name, dp.name AS department_name, (SELECT COUNT(*) FROM ' . Schema::table( 'contract_signers' ) . ' sg WHERE sg.contract_id=t.id) AS signer_total, (SELECT COUNT(*) FROM ' . Schema::table( 'contract_signers' ) . " sg2 WHERE sg2.contract_id=t.id AND sg2.status='signed') AS signer_done",
				'join'     => 'LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=t.client_id LEFT JOIN ' . Schema::table( 'brands' ) . ' dp ON dp.id=t.department_id',
				'cols'     => array( 'title' => 's', 'client_id' => 'i', 'template_id' => 'i', 'project_id' => 'i', 'department_id' => 'i', 'body' => 'h', 'expires_on' => 'date', 'notes' => 't' ),
				'filters'  => array( 'client_id', 'status', 'project_id', 'department_id' ),
				'search'   => array( 't.number', 't.title', 'c.company_name' ),
				'order'    => 't.id DESC',
				'order_map' => array( 'client_name' => 'c.company_name' ),
				'required' => array( 'title' ),
				'title'    => function ( $r ) {
					return $r['number'] . ' ' . $r['title'];
				},
				'before_save' => function ( $d, $existing, $params ) {
					global $wpdb;
					if ( $existing && 'draft' !== $existing['status'] ) {
						// Once sent, the agreed text is frozen; only housekeeping fields may change.
						foreach ( array( 'title', 'body', 'client_id', 'template_id' ) as $k ) {
							unset( $d[ $k ] );
						}
					}
					if ( isset( $d['body'] ) ) {
						$d['body'] = wp_kses_post( $d['body'] );
					}
					if ( ! $existing ) {
						$d['status']      = 'draft';
						$d['number']      = Contracts::next_number();
						$d['view_access'] = 'public' === Settings::get( 'contract_view_default' ) ? 'public' : 'private';
						if ( empty( $d['department_id'] ) ) {
							$tp_dept = ! empty( $d['template_id'] ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT department_id FROM ' . Schema::table( 'contract_templates' ) . ' WHERE id=%d', (int) $d['template_id'] ) ) : 0; // phpcs:ignore WordPress.DB
							$d['department_id'] = $tp_dept ? $tp_dept : Services\Finance::client_department( isset( $d['client_id'] ) ? $d['client_id'] : 0 ); // the template's department, else the client's
						}
						if ( empty( $d['body'] ) && ! empty( $d['template_id'] ) ) {
							$d['body'] = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT body FROM ' . Schema::table( 'contract_templates' ) . ' WHERE id=%d', (int) $d['template_id'] ) ); // phpcs:ignore WordPress.DB
						}
					}
					if ( ( ! $existing || 'draft' === $existing['status'] ) && isset( $d['body'] ) ) {
						$ctx = array(
							'client_id' => isset( $d['client_id'] ) ? $d['client_id'] : ( $existing ? $existing['client_id'] : 0 ),
							'title'     => isset( $d['title'] ) ? $d['title'] : ( $existing ? $existing['title'] : '' ),
							'number'    => $existing ? $existing['number'] : $d['number'],
						);
						$d['body'] = Contracts::merge( $d['body'], $ctx );
					}
					return $d;
				},
				'after_save' => function ( $row, $existing, $params ) {
					if ( isset( $params['signers'] ) && is_array( $params['signers'] ) && 'draft' === $row['status'] ) {
						Contracts::sync_signers( $row['id'], $params['signers'] );
					}
					if ( ! $existing ) {
						Contracts::event( $row['id'], 0, 'created', array( 'number' => $row['number'] ) );
					}
				},
				'before_delete' => function ( $row ) {
					return in_array( $row['status'], array( 'draft', 'void', 'declined' ), true ) ? true : new \WP_Error( 'bizydash_blocked', 'A contract that is out for signature or signed is a legal record and cannot be deleted. Void it instead.', array( 'status' => 409 ) );
				},
				'after_delete' => function ( $row ) {
					global $wpdb;
					if ( 'draft' === $row['status'] ) {
						foreach ( array( 'contract_signers', 'contract_events', 'contract_otps' ) as $tbl ) {
							$wpdb->delete( Schema::table( $tbl ), array( 'contract_id' => (int) $row['id'] ) );
						}
					}
				},
				'decorate' => function ( $rows, $single ) {
					foreach ( $rows as &$r ) {
						$r['signer_total'] = (int) $r['signer_total'];
						$r['signer_done']  = (int) $r['signer_done'];
						if ( $single ) {
							$r['signers']         = Contracts::signers( $r['id'] );
							$r['certificate_url'] = home_url( '/?bd_certificate=' . (int) $r['id'] );
							$r['preview_url']     = Contracts::preview_url( $r['preview_slug'] );
							$r['has_pdf']         = ! empty( $r['pdf_file'] );
							$r['pdf_url']         = \BizyDash\Services\Contract_Pdf::url( $r['id'] );
							unset( $r['pdf_file'] );
						} else {
							unset( $r['body'] );
						}
					}
					return $rows;
				},
			),

			'lookups'         => array(
				'route'      => 'lookups',
				'table'      => 'lookups',
				'label'      => 'Option',
				'log'        => false,
				'timestamps' => false,
				'cols'       => array( 'type' => 's', 'item_key' => 's', 'label' => 's', 'color' => 's', 'months' => 'i', 'sort_order' => 'i', 'is_active' => 'b' ),
				'filters'    => array( 'type', 'is_active' ),
				'search'     => array( 'label' ),
				'order'      => 't.type ASC, t.sort_order ASC, t.id ASC',
				'required'   => array( 'type', 'label' ),
				'before_save' => function ( $d, $existing ) {
					global $wpdb;
					if ( ! $existing ) {
						$base = ! empty( $d['item_key'] ) ? sanitize_key( $d['item_key'] ) : sanitize_key( str_replace( ' ', '_', strtolower( $d['label'] ) ) );
						$key  = $base ? $base : 'option';
						$n    = 2;
						$tbl  = Schema::table( 'lookups' );
						while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tbl WHERE type=%s AND item_key=%s", $d['type'], $key ) ) ) { // phpcs:ignore WordPress.DB
							$key = $base . '_' . $n++;
						}
						$d['item_key'] = $key;
					} else {
						unset( $d['item_key'], $d['type'] ); // keys are immutable once records may reference them
					}
					return $d;
				},
				'before_delete' => function ( $row ) {
					global $wpdb;
					if ( ! empty( $row['is_system'] ) ) {
						return new \WP_Error( 'bizydash_protected', 'Built-in options can be renamed or deactivated, not deleted.', array( 'status' => 400 ) );
					}
					return true;
				},
			),
		);
	}
}
