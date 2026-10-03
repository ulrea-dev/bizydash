<?php
namespace BizyDash;

use BizyDash\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Config-driven table access. Column types:
 * s string, t textarea, i int, d decimal, date, c currency code, u url, e email, b bool.
 */
class Repository {

	public $name;
	public $cfg;
	public $table;

	public function __construct( $name, array $cfg ) {
		$this->name  = $name;
		$this->cfg   = $cfg + array(
			'cols'       => array(),
			'filters'    => array(),
			'search'     => array(),
			'order'      => 't.id DESC',
			'order_map'  => array(),
			'select'     => '',
			'join'       => '',
			'timestamps' => true,
			'extra'      => null,
		);
		$this->table = Schema::table( $cfg['table'] );
	}

	public function sanitize( array $in ) {
		$out = array();
		foreach ( $this->cfg['cols'] as $col => $type ) {
			if ( ! array_key_exists( $col, $in ) ) {
				continue;
			}
			$v = $in[ $col ];
			switch ( $type ) {
				case 'i':
					$out[ $col ] = absint( $v );
					break;
				case 'd':
					$n           = str_replace( ',', '', (string) $v );
					$out[ $col ] = is_numeric( $n ) ? round( (float) $n, 2 ) : 0;
					break;
				case 'date':
					$v = (string) $v;
					$ok = preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
					$out[ $col ] = $ok ? $v : null;
					break;
				case 'c':
					$out[ $col ] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $v ), 0, 3 ) );
					break;
				case 'u':
					$out[ $col ] = esc_url_raw( (string) $v );
					break;
				case 'e':
					$out[ $col ] = sanitize_email( (string) $v );
					break;
				case 'b':
					$out[ $col ] = ( empty( $v ) || 'false' === $v || '0' === $v ) ? 0 : 1;
					break;
				case 't':
					$out[ $col ] = sanitize_textarea_field( (string) $v );
					break;
				case 'h':
					$out[ $col ] = wp_kses_post( (string) $v ); // rich text (contract wording)
					break;
				default:
					$out[ $col ] = sanitize_text_field( (string) $v );
			}
		}
		return $out;
	}

	private function formats( array $data ) {
		$f = array();
		foreach ( $data as $col => $v ) {
			$type = isset( $this->cfg['cols'][ $col ] ) ? $this->cfg['cols'][ $col ] : 's';
			$f[]  = in_array( $type, array( 'i', 'b' ), true ) || in_array( $col, array( 'created_by', 'user_id' ), true ) ? '%d' : '%s';
		}
		return $f;
	}

	private function build( array $a ) {
		$where = array( '1=1' );
		$p     = array();

		foreach ( $this->cfg['filters'] as $col ) {
			if ( ! isset( $a[ $col ] ) || '' === $a[ $col ] ) {
				continue;
			}
			$vals = array_values( array_filter( array_map( 'trim', explode( ',', (string) $a[ $col ] ) ), 'strlen' ) );
			if ( ! $vals ) {
				continue;
			}
			$where[] = "t.$col IN (" . implode( ',', array_fill( 0, count( $vals ), '%s' ) ) . ')';
			$p       = array_merge( $p, $vals );
		}

		foreach ( $this->cfg['cols'] as $col => $type ) {
			if ( 'date' !== $type ) {
				continue;
			}
			foreach ( array( '_from' => '>=', '_to' => '<=' ) as $suffix => $op ) {
				$k = $col . $suffix;
				if ( ! empty( $a[ $k ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $a[ $k ] ) ) {
					$where[] = "t.$col $op %s";
					$p[]     = $a[ $k ];
				}
			}
		}

		$s = isset( $a['search'] ) ? trim( (string) $a['search'] ) : '';
		if ( '' !== $s && $this->cfg['search'] ) {
			global $wpdb;
			$like = '%' . $wpdb->esc_like( $s ) . '%';
			$or   = array();
			foreach ( $this->cfg['search'] as $c ) {
				$or[] = ( false !== strpos( $c, '.' ) ? $c : "t.$c" ) . ' LIKE %s';
				$p[]  = $like;
			}
			$where[] = '(' . implode( ' OR ', $or ) . ')';
		}

		if ( $this->cfg['extra'] ) {
			$res = call_user_func( $this->cfg['extra'], $a );
			if ( ! empty( $res[0] ) ) {
				$where[] = $res[0];
				$p       = array_merge( $p, $res[1] );
			}
		}

		return array( $this->table . ' t ' . $this->cfg['join'], implode( ' AND ', $where ), $p );
	}

	private function order( array $a ) {
		$ob = isset( $a['orderby'] ) ? (string) $a['orderby'] : '';
		if ( $ob ) {
			$dir = ( isset( $a['order'] ) && 'desc' === strtolower( $a['order'] ) ) ? 'DESC' : 'ASC';
			if ( isset( $this->cfg['order_map'][ $ob ] ) ) {
				return $this->cfg['order_map'][ $ob ] . " $dir";
			}
			if ( isset( $this->cfg['cols'][ $ob ] ) || in_array( $ob, array( 'id', 'created_at' ), true ) ) {
				return "t.$ob $dir";
			}
		}
		return $this->cfg['order'];
	}

	private function prep( $sql, array $p ) {
		global $wpdb;
		return $p ? $wpdb->prepare( $sql, $p ) : $sql; // phpcs:ignore WordPress.DB
	}

	public function query( array $a ) {
		global $wpdb;
		list( $from, $where, $p ) = $this->build( $a );

		$per  = min( 200, max( 1, (int) ( isset( $a['per_page'] ) ? $a['per_page'] : 50 ) ) );
		$page = max( 1, (int) ( isset( $a['page'] ) ? $a['page'] : 1 ) );

		$sql   = "SELECT t.*{$this->cfg['select']} FROM $from WHERE $where ORDER BY " . $this->order( $a ) . ' LIMIT %d OFFSET %d';
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $p, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$total = (int) $wpdb->get_var( $this->prep( "SELECT COUNT(*) FROM $from WHERE $where", $p ) ); // phpcs:ignore WordPress.DB

		return array(
			'items' => array_map( array( $this, 'cast' ), (array) $rows ),
			'total' => $total,
			'pages' => (int) ceil( $total / $per ),
		);
	}

	public function find( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT t.*{$this->cfg['select']} FROM {$this->table} t {$this->cfg['join']} WHERE t.id=%d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? $this->cast( $row ) : null;
	}

	public function insert( array $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		if ( $this->cfg['timestamps'] ) {
			$data['created_at'] = $now;
			$data['updated_at'] = $now;
			$data['created_by'] = get_current_user_id();
		}
		$ok = $wpdb->insert( $this->table, $data, $this->formats( $data ) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public function update( $id, array $data ) {
		global $wpdb;
		if ( $this->cfg['timestamps'] ) {
			$data['updated_at'] = current_time( 'mysql' );
		}
		if ( ! $data ) {
			return true;
		}
		return false !== $wpdb->update( $this->table, $data, array( 'id' => (int) $id ), $this->formats( $data ), array( '%d' ) );
	}

	public function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( $this->table, array( 'id' => (int) $id ), array( '%d' ) );
	}

	private function cast( array $row ) {
		foreach ( $row as $k => $v ) {
			if ( 'id' === $k || '_id' === substr( $k, -3 ) ) {
				$row[ $k ] = (int) $v;
			} elseif ( isset( $this->cfg['cols'][ $k ] ) ) {
				$t = $this->cfg['cols'][ $k ];
				if ( 'i' === $t ) {
					$row[ $k ] = (int) $v;
				} elseif ( 'd' === $t ) {
					$row[ $k ] = (float) $v;
				} elseif ( 'b' === $t ) {
					$row[ $k ] = (bool) $v;
				}
			}
		}
		return $row;
	}
}
