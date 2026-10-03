<?php
namespace BizyDash;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'bizydash_settings';

	public static function defaults() {
		return array(
			'company_name'        => '',
			'default_currency'    => 'NGN',
			'currencies'          => array(
				array( 'code' => 'NGN', 'symbol' => '₦', 'name' => 'Nigerian Naira' ),
				array( 'code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar' ),
				array( 'code' => 'GBP', 'symbol' => '£', 'name' => 'British Pound' ),
				array( 'code' => 'EUR', 'symbol' => '€', 'name' => 'Euro' ),
			),
			'base_currency'       => '',
			'rates'               => array(),
			'due_soon_days'       => 30,
			'invoice_prefix'      => 'INV-',
			'invoice_next'        => 1,
			'invoice_slug_base'   => 'invoice',
			'contract_view_default' => 'private',
			'app_fullscreen'      => true,
			'app_keep_staff'      => true,
			'app_frontend'        => true,
			'app_login'           => true,
			'app_home'            => false,
			'app_slug'            => 'app',
			'default_due_days'    => 14,
			'delete_on_uninstall' => false,
			// Business profile (printed on invoices).
			'business_name'        => '',
			'business_logo_id'     => 0,
			'business_logo_email_id' => 0,
			'business_address'     => '',
			'business_email'       => '',
			'business_phone'       => '',
			'business_website'     => '',
			'tax_label'            => 'VAT',
			'tax_id'               => '',
			'default_tax_rate'     => 0,
			'payment_instructions' => '',
			'payment_methods'      => array(),
			'department_label'     => 'Department',
			'department_label_plural' => 'Departments',
			'invoice_terms'        => '',
			'invoice_footer'       => 'Thank you for your business.',
		);
	}

	public static function get( $key = null ) {
		$s = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
		$s['business_logo_url'] = ! empty( $s['business_logo_id'] ) ? (string) wp_get_attachment_url( (int) $s['business_logo_id'] ) : '';
		$s['business_logo_email_url'] = ! empty( $s['business_logo_email_id'] ) ? (string) wp_get_attachment_url( (int) $s['business_logo_email_id'] ) : '';
		if ( '' === $s['business_logo_email_url'] && $s['business_logo_url'] && ! preg_match( '/\.svg(\?|$)/i', $s['business_logo_url'] ) ) {
			$s['business_logo_email_url'] = $s['business_logo_url']; // raster logos work in email as-is
		}
		if ( '' === trim( (string) $s['business_name'] ) ) {
			$s['business_name'] = $s['company_name'] ? $s['company_name'] : get_bloginfo( 'name' );
		}
		if ( null === $key ) {
			return $s;
		}
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	public static function update( array $in ) {
		$cur = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
		$out = $cur;

		if ( isset( $in['company_name'] ) ) {
			$out['company_name'] = sanitize_text_field( $in['company_name'] );
		}
		if ( isset( $in['currencies'] ) && is_array( $in['currencies'] ) ) {
			$list = array();
			foreach ( $in['currencies'] as $c ) {
				$code = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', isset( $c['code'] ) ? (string) $c['code'] : '' ), 0, 3 ) );
				if ( 3 !== strlen( $code ) ) {
					continue;
				}
				$list[ $code ] = array(
					'code'   => $code,
					'symbol' => sanitize_text_field( isset( $c['symbol'] ) ? $c['symbol'] : $code ),
					'name'   => sanitize_text_field( isset( $c['name'] ) ? $c['name'] : $code ),
					'active' => ! isset( $c['active'] ) || (bool) $c['active'],
				);
			}
			if ( $list ) {
				// A currency that records still use is never dropped — it stays, switched off.
				foreach ( Services\Currency::usage() as $used => $n ) {
					if ( ! isset( $list[ $used ] ) ) {
						foreach ( (array) $out['currencies'] as $old ) {
							if ( $old['code'] === $used ) {
								$list[ $used ] = array_merge( $old, array( 'active' => false ) );
							}
						}
					}
				}
				$out['currencies'] = array_values( $list );
			}
		}
		if ( isset( $in['default_currency'] ) ) {
			$code = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $in['default_currency'] ), 0, 3 ) );
			$out['default_currency'] = $code;
		}
		$codes = wp_list_pluck( $out['currencies'], 'code' );
		if ( ! in_array( $out['default_currency'], $codes, true ) ) {
			$out['default_currency'] = $codes[0];
		}
		if ( isset( $in['base_currency'] ) ) {
			$b = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $in['base_currency'] ), 0, 3 ) );
			$out['base_currency'] = in_array( $b, $codes, true ) ? $b : '';
		}
		if ( '' === (string) $out['base_currency'] || ! in_array( $out['base_currency'], $codes, true ) ) {
			$out['base_currency'] = $out['default_currency'];
		}
		if ( isset( $in['rates'] ) && is_array( $in['rates'] ) ) {
			$rates = array();
			foreach ( $in['rates'] as $code => $r ) {
				$code = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $code ), 0, 3 ) );
				if ( in_array( $code, $codes, true ) && $code !== $out['base_currency'] && (float) $r > 0 ) {
					$rates[ $code ] = round( (float) $r, 6 );
				}
			}
			$out['rates'] = $rates;
		}
		foreach ( array( 'due_soon_days', 'default_due_days', 'invoice_next' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = max( 0, (int) $in[ $k ] );
			}
		}
		if ( $out['invoice_next'] < 1 ) {
			$out['invoice_next'] = 1;
		}
		if ( isset( $in['invoice_prefix'] ) ) {
			$out['invoice_prefix'] = sanitize_text_field( $in['invoice_prefix'] );
		}
		if ( isset( $in['invoice_slug_base'] ) ) {
			$base = sanitize_title( $in['invoice_slug_base'] );
			$out['invoice_slug_base'] = ( '' !== $base && ! in_array( $base, array( 'wp-admin', 'wp-json', 'wp-content', 'wp-includes', 'feed' ), true ) ) ? $base : 'invoice';
		}
		if ( isset( $in['contract_view_default'] ) ) {
			$out['contract_view_default'] = 'public' === $in['contract_view_default'] ? 'public' : 'private';
		}
		$home_was = ! empty( $cur['app_home'] );
		foreach ( array( 'app_fullscreen', 'app_keep_staff', 'app_frontend', 'app_login', 'app_home' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = (bool) $in[ $k ];
			}
		}
		if ( $home_was !== ! empty( $out['app_home'] ) ) {
			do_action( 'litespeed_purge_all' ); // the site's front page just changed: never serve a stale cached copy
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}
		}
		if ( isset( $in['app_slug'] ) ) {
			$sl = sanitize_title( $in['app_slug'] );
			$out['app_slug'] = ( '' !== $sl && ! in_array( $sl, array( 'wp-admin', 'wp-json', 'wp-content', 'wp-includes', 'wp-login', 'feed', 'sign', 'contract', 'verify', 'invoice' ), true ) ) ? $sl : 'app';
		}
		if ( isset( $in['delete_on_uninstall'] ) ) {
			$out['delete_on_uninstall'] = (bool) $in['delete_on_uninstall'];
		}

		foreach ( array( 'department_label', 'department_label_plural' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$v = trim( sanitize_text_field( $in[ $k ] ) );
				if ( '' !== $v ) {
					$out[ $k ] = mb_substr( $v, 0, 30 );
				}
			}
		}
		foreach ( array( 'business_name', 'business_email', 'business_phone', 'business_website', 'tax_label', 'tax_id', 'invoice_footer' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( $in[ $k ] );
			}
		}
		foreach ( array( 'business_address', 'payment_instructions', 'invoice_terms' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_textarea_field( $in[ $k ] );
			}
		}
		foreach ( array( 'business_logo_id', 'business_logo_email_id' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = absint( $in[ $k ] );
			}
		}
		if ( isset( $in['default_tax_rate'] ) ) {
			$out['default_tax_rate'] = max( 0, min( 100, round( (float) $in['default_tax_rate'], 2 ) ) );
		}
		if ( isset( $in['business_website'] ) ) {
			$out['business_website'] = esc_url_raw( $in['business_website'] );
		}
		if ( isset( $in['payment_methods'] ) && is_array( $in['payment_methods'] ) ) {
			$out['payment_methods'] = self::clean_methods( $in['payment_methods'] );
		}
		unset( $out['business_logo_url'], $out['business_logo_email_url'] );

		update_option( self::OPTION, $out );
		return self::get();
	}

	/** esc_url_raw() that keeps {invoice_number}-style placeholders intact. */
	private static function clean_link( $url ) {
		$map = array( '{invoice_number}' => 'bdph1x', '{amount}' => 'bdph2x', '{currency}' => 'bdph3x' );
		$url = esc_url_raw( strtr( trim( (string) $url ), $map ), array( 'http', 'https' ) );
		return strtr( $url, array_flip( $map ) );
	}

	/** Sanitise the list of ways a client can pay (bank accounts, payment links, other). */
	public static function clean_methods( array $list ) {
		$out = array();
		foreach ( array_slice( $list, 0, 20 ) as $m ) {
			if ( ! is_array( $m ) ) {
				continue;
			}
			$type = isset( $m['type'] ) && in_array( $m['type'], array( 'bank', 'link', 'other' ), true ) ? $m['type'] : 'other';
			$row  = array(
				'id'             => ! empty( $m['id'] ) ? sanitize_key( $m['id'] ) : 'pm' . wp_generate_password( 8, false, false ),
				'type'           => $type,
				'title'          => sanitize_text_field( isset( $m['title'] ) ? $m['title'] : '' ),
				'currency'       => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', isset( $m['currency'] ) ? (string) $m['currency'] : '' ), 0, 3 ) ),
				'enabled'        => ! isset( $m['enabled'] ) || (bool) $m['enabled'],
				'bank_name'      => sanitize_text_field( isset( $m['bank_name'] ) ? $m['bank_name'] : '' ),
				'account_name'   => sanitize_text_field( isset( $m['account_name'] ) ? $m['account_name'] : '' ),
				'account_number' => sanitize_text_field( isset( $m['account_number'] ) ? $m['account_number'] : '' ),
				'extra_label'    => sanitize_text_field( isset( $m['extra_label'] ) ? $m['extra_label'] : '' ),
				'extra_value'    => sanitize_text_field( isset( $m['extra_value'] ) ? $m['extra_value'] : '' ),
				'url'            => self::clean_link( isset( $m['url'] ) ? $m['url'] : '' ),
				'button_label'   => sanitize_text_field( isset( $m['button_label'] ) ? $m['button_label'] : '' ),
				'note'           => sanitize_textarea_field( isset( $m['note'] ) ? $m['note'] : '' ),
			);
			$has = ( 'bank' === $type && ( $row['account_number'] || $row['bank_name'] ) ) || ( 'link' === $type && $row['url'] ) || ( 'other' === $type && $row['note'] );
			if ( $row['title'] || $has ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Enabled payment methods that apply to an invoice currency, with link placeholders
	 * ({invoice_number}, {amount}, {currency}) filled in.
	 */
	public static function payment_methods( $currency = '', $vars = array() ) {
		$out = array();
		foreach ( (array) self::get( 'payment_methods' ) as $m ) {
			if ( empty( $m['enabled'] ) || ( '' !== $m['currency'] && $currency && $m['currency'] !== $currency ) ) {
				continue;
			}
			if ( 'link' === $m['type'] && $m['url'] ) {
				$rep = array();
				foreach ( $vars as $k => $v ) {
					$rep[ '{' . $k . '}' ] = rawurlencode( (string) $v );
				}
				$m['url'] = strtr( $m['url'], $rep );
			}
			$out[] = $m;
		}
		return $out;
	}
}
