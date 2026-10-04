<?php
namespace BizyDash\Rest;

use BizyDash\Resources;
use BizyDash\Services\Activity;

defined( 'ABSPATH' ) || exit;

/**
 * Generic CRUD controller driven by a Resources registry entry.
 */
class Resource_Controller {

	const NS = 'bizydash/v1';

	private $name;
	private $cfg;
	private $repo;

	public function __construct( $name ) {
		$this->name = $name;
		$this->repo = Resources::repo( $name );
		$this->cfg  = $this->repo->cfg;
	}

	public static function can() {
		return current_user_can( 'manage_bizydash' );
	}

	/** Finance resources (payroll, expenses, bank details) need the finance capability as well. */
	public static function can_finance() {
		return current_user_can( 'bizydash_finance' );
	}

	/** Who may use this resource: money resources need the finance cap, module resources their module's cap, core resources can be read by everyone but only changed by the right role. */
	private function perm( $write = false ) {
		if ( ! empty( $this->cfg['finance'] ) ) {
			return array( __CLASS__, 'can_finance' );
		}
		$cap = \BizyDash\Roles::module_cap( \BizyDash\Modules::resource_module( $this->name ) );
		if ( '' === $cap && $write ) {
			$cap = \BizyDash\Roles::write_cap( $this->name );
		}
		if ( '' !== $cap ) {
			return function () use ( $cap ) {
				return current_user_can( 'manage_bizydash' ) && current_user_can( $cap );
			};
		}
		return array( __CLASS__, 'can' );
	}

	public function register() {
		$route    = $this->cfg['route'];
		$perm     = $this->perm();
		$wperm    = $this->perm( true );
		$readonly = ! empty( $this->cfg['readonly'] );

		$collection = array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'index' ), 'permission_callback' => $perm ),
		);
		$item = array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'show' ), 'permission_callback' => $perm ),
		);
		if ( ! $readonly ) {
			$collection[] = array( 'methods' => 'POST', 'callback' => array( $this, 'create' ), 'permission_callback' => $wperm );
			$item[]       = array( 'methods' => 'PUT,PATCH', 'callback' => array( $this, 'update' ), 'permission_callback' => $wperm );
			$item[]       = array( 'methods' => 'DELETE', 'callback' => array( $this, 'destroy' ), 'permission_callback' => $wperm );
		}

		register_rest_route( self::NS, '/' . $route, $collection );
		register_rest_route( self::NS, '/' . $route . '/(?P<id>\d+)', $item );
	}

	private function decorate( array $rows, $single = false ) {
		if ( ! empty( $this->cfg['decorate'] ) ) {
			$rows = call_user_func( $this->cfg['decorate'], $rows, $single );
		}
		return $rows;
	}

	private function hook( $key ) {
		return ! empty( $this->cfg[ $key ] ) ? $this->cfg[ $key ] : null;
	}

	private function client_id_of( array $row ) {
		if ( 'clients' === $this->name ) {
			return (int) $row['id'];
		}
		return isset( $row['client_id'] ) ? (int) $row['client_id'] : 0;
	}

	private function title( array $row ) {
		$fn = $this->hook( 'title' );
		return $fn ? call_user_func( $fn, $row ) : '#' . $row['id'];
	}

	private function log( $verb, array $row, $extra = '' ) {
		if ( isset( $this->cfg['log'] ) && false === $this->cfg['log'] ) {
			return;
		}
		Activity::log( $this->name, $row['id'], $this->client_id_of( $row ), $verb, sprintf( '%s %s: %s%s', $this->cfg['label'], $verb, $this->title( $row ), $extra ) );
	}

	public function index( \WP_REST_Request $req ) {
		$res          = $this->repo->query( $req->get_params() );
		$res['items'] = $this->decorate( $res['items'] );
		return rest_ensure_response( $res );
	}

	public function show( \WP_REST_Request $req ) {
		$row = $this->repo->find( (int) $req['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'bizydash_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		$rows = $this->decorate( array( $row ), true );
		return rest_ensure_response( $rows[0] );
	}

	public function create( \WP_REST_Request $req ) {
		$params = $req->get_params();
		$data   = $this->repo->sanitize( $params );

		if ( $fn = $this->hook( 'before_save' ) ) {
			$data = call_user_func( $fn, $data, null, $params );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
		}
		foreach ( (array) $this->hook( 'required' ) as $f ) {
			if ( ! isset( $data[ $f ] ) || '' === $data[ $f ] || 0 === $data[ $f ] || 0.0 === $data[ $f ] ) {
				return new \WP_Error( 'bizydash_missing', sprintf( '"%s" is required.', str_replace( '_', ' ', $f ) ), array( 'status' => 400 ) );
			}
		}

		$id = $this->repo->insert( $data );
		if ( ! $id ) {
			return new \WP_Error( 'bizydash_db', 'Could not save the record.', array( 'status' => 500 ) );
		}
		$row = $this->repo->find( $id );
		if ( $fn = $this->hook( 'after_save' ) ) {
			call_user_func( $fn, $row, null, $params );
			$row = $this->repo->find( $id );
		}
		$this->log( 'added', $row );

		$rows = $this->decorate( array( $row ), true );
		$resp = rest_ensure_response( $rows[0] );
		$resp->set_status( 201 );
		return $resp;
	}

	public function update( \WP_REST_Request $req ) {
		$id       = (int) $req['id'];
		$existing = $this->repo->find( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'bizydash_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		$params = $req->get_params();
		$data   = $this->repo->sanitize( $params );

		if ( $fn = $this->hook( 'before_save' ) ) {
			$data = call_user_func( $fn, $data, $existing, $params );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
		}
		foreach ( (array) $this->hook( 'required' ) as $f ) {
			if ( array_key_exists( $f, $data ) && ( '' === $data[ $f ] || 0 === $data[ $f ] || null === $data[ $f ] ) ) {
				return new \WP_Error( 'bizydash_missing', sprintf( '"%s" is required.', str_replace( '_', ' ', $f ) ), array( 'status' => 400 ) );
			}
		}

		$this->repo->update( $id, $data );
		$row = $this->repo->find( $id );
		if ( $fn = $this->hook( 'after_save' ) ) {
			call_user_func( $fn, $row, $existing, $params );
			$row = $this->repo->find( $id );
		}

		$extra = '';
		if ( isset( $existing['status'], $row['status'] ) && $existing['status'] !== $row['status'] ) {
			$extra = sprintf( ' (status %s → %s)', $existing['status'], $row['status'] );
		}
		$this->log( 'updated', $row, $extra );

		$rows = $this->decorate( array( $row ), true );
		return rest_ensure_response( $rows[0] );
	}

	public function destroy( \WP_REST_Request $req ) {
		$id  = (int) $req['id'];
		$row = $this->repo->find( $id );
		if ( ! $row ) {
			return new \WP_Error( 'bizydash_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		if ( $fn = $this->hook( 'before_delete' ) ) {
			$ok = call_user_func( $fn, $row );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		$title = $this->title( $row );
		$this->repo->delete( $id );
		if ( $fn = $this->hook( 'after_delete' ) ) {
			call_user_func( $fn, $row );
		}
		if ( ! isset( $this->cfg['log'] ) || false !== $this->cfg['log'] ) {
			Activity::log( $this->name, $id, $this->client_id_of( $row ), 'deleted', sprintf( '%s deleted: %s', $this->cfg['label'], $title ) );
		}
		return rest_ensure_response( array( 'deleted' => true, 'id' => $id ) );
	}
}
