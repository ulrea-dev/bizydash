<?php
namespace BizyDash\Services;

use BizyDash\Database\Schema;
use BizyDash\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the client portal shows, always for ONE client. Nothing here returns costs, margins, team pay, budgets,
 * internal notes or other clients' records: if a field is not listed in a query, a client cannot see it.
 */
class Portal_Data {

	private static function t( $n ) {
		return Schema::table( $n );
	}

	public static function client( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT id, company_name, contact_person, email, currency FROM ' . self::t( 'clients' ) . ' WHERE id=%d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/* ───────────────────────── Projects & tasks ───────────────────────── */

	public static function projects( $client_id ) {
		global $wpdb;
		$tk   = self::t( 'tasks' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT p.id, p.name, p.description, p.status, p.start_date, p.due_date,
				(SELECT COUNT(*) FROM $tk t WHERE t.project_id=p.id) AS tasks_total,
				(SELECT COUNT(*) FROM $tk t WHERE t.project_id=p.id AND t.status='done') AS tasks_done,
				(SELECT COUNT(*) FROM " . self::t( 'project_phases' ) . " ph WHERE ph.project_id=p.id) AS phases
			FROM " . self::t( 'projects' ) . " p WHERE p.client_id=%d ORDER BY FIELD(p.status,'active','planned','on_hold','done'), p.due_date IS NULL, p.due_date ASC, p.id DESC", // phpcs:ignore WordPress.DB
			$client_id
		), ARRAY_A );
		foreach ( $rows as &$r ) {
			$r['id']          = (int) $r['id'];
			$r['tasks_total'] = (int) $r['tasks_total'];
			$r['tasks_done']  = (int) $r['tasks_done'];
			$r['percent']     = $r['tasks_total'] ? (int) round( 100 * $r['tasks_done'] / $r['tasks_total'] ) : ( 'done' === $r['status'] ? 100 : 0 );
		}
		return $rows;
	}

	/** One project of this client with its phases and tasks, or null. */
	public static function project( $client_id, $id, $with_assignee ) {
		global $wpdb;
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, description, status, start_date, due_date FROM ' . self::t( 'projects' ) . ' WHERE id=%d AND client_id=%d', $id, $client_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $p ) {
			return null;
		}
		$phases = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, status, start_date, due_date, completed_at FROM ' . self::t( 'project_phases' ) . ' WHERE project_id=%d ORDER BY sort_order ASC, id ASC', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$tasks  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT t.id, t.title, t.status, t.priority, t.due_date, t.phase_id, t.completed_at' . ( $with_assignee ? ', a.name AS assignee' : '' ) . ' FROM ' . self::t( 'tasks' ) . ' t' . ( $with_assignee ? ' LEFT JOIN ' . self::t( 'team_members' ) . ' a ON a.id=t.assignee_id' : '' ) . " WHERE t.project_id=%d ORDER BY FIELD(t.status,'doing','todo','done'), t.due_date IS NULL, t.due_date ASC, t.sort_order ASC, t.id ASC", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$by = array();
		foreach ( $tasks as $t ) {
			$by[ (int) $t['phase_id'] ][] = $t;
		}
		foreach ( $phases as &$ph ) {
			$ph['tasks'] = $by[ (int) $ph['id'] ] ?? array();
			unset( $by[ (int) $ph['id'] ] );
		}
		unset( $ph );
		$p['phases'] = $phases;
		$p['other']  = array_merge( $by[0] ?? array(), ...array_values( array_diff_key( $by, array( 0 => 1 ) ) ) );
		$total       = count( $tasks );
		$done        = count( array_filter( $tasks, function ( $t ) {
			return 'done' === $t['status'];
		} ) );
		$p['tasks_total'] = $total;
		$p['tasks_done']  = $done;
		$p['percent']     = $total ? (int) round( 100 * $done / $total ) : ( 'done' === $p['status'] ? 100 : 0 );
		return $p;
	}

	/* ───────────────────────── Money ───────────────────────── */

	/** Invoices the client can see (never drafts or cancelled ones). */
	public static function invoices( $client_id, $filter = 'all' ) {
		global $wpdb;
		$where = "i.client_id=%d AND i.status NOT IN ('draft','cancelled')";
		if ( 'open' === $filter ) {
			$where .= " AND i.status IN ('sent','due','partially_paid','overdue')";
		} elseif ( 'paid' === $filter ) {
			$where .= " AND i.status='paid'";
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT i.id, i.invoice_number, i.title, i.amount, i.amount_paid, i.currency, i.issue_date, i.due_date, i.status, i.public_token FROM " . self::t( 'invoices' ) . " i WHERE $where ORDER BY i.issue_date DESC, i.id DESC", $client_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as &$r ) {
			$r['id']      = (int) $r['id'];
			$r['balance'] = max( 0, round( (float) $r['amount'] - (float) $r['amount_paid'], 2 ) );
		}
		return $rows;
	}

	public static function invoice( $client_id, $id ) {
		global $wpdb;
		$i = $wpdb->get_row( $wpdb->prepare( "SELECT id, invoice_number, title, description, amount, amount_paid, subtotal, discount_amount, tax_rate, tax_amount, currency, issue_date, due_date, status, public_token, terms FROM " . self::t( 'invoices' ) . " WHERE id=%d AND client_id=%d AND status NOT IN ('draft','cancelled')", $id, $client_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $i ) {
			return null;
		}
		$i['balance']  = max( 0, round( (float) $i['amount'] - (float) $i['amount_paid'], 2 ) );
		$i['items']    = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT description, quantity, unit_price, amount FROM ' . self::t( 'invoice_items' ) . ' WHERE invoice_id=%d ORDER BY id', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$i['payments'] = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT amount, currency, paid_on, method, reference FROM ' . self::t( 'payments' ) . ' WHERE invoice_id=%d ORDER BY paid_on, id', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $i;
	}

	/** Money still owed, per currency, and how many invoices are overdue. */
	public static function owed( $client_id ) {
		$out = array( 'by_currency' => array(), 'overdue' => 0, 'open' => 0 );
		foreach ( self::invoices( $client_id, 'open' ) as $r ) {
			$out['by_currency'][ $r['currency'] ] = round( ( $out['by_currency'][ $r['currency'] ] ?? 0 ) + $r['balance'], 2 );
			$out['open']++;
			if ( 'overdue' === $r['status'] ) {
				$out['overdue']++;
			}
		}
		return $out;
	}

	/** Bills we have raised for the client that are not on an invoice yet: recurring cycles and one-off charges. Prices only. */
	public static function upcoming( $client_id ) {
		global $wpdb;
		$rows = array();
		$sv   = self::t( 'services' );
		$cs   = self::t( 'client_services' );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT r.id, r.due_date, r.price, r.currency, r.subject, r.frequency, s.name, s.type FROM " . self::t( 'renewals' ) . " r LEFT JOIN $cs c ON c.id=r.client_service_id LEFT JOIN $sv s ON s.id=c.service_id WHERE r.client_id=%d AND r.status IN ('upcoming','due') AND r.invoice_id=0 AND r.price>0 ORDER BY r.due_date ASC", $client_id ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$rows[] = array( 'title' => '' !== (string) $r['subject'] ? $r['name'] . ' — ' . $r['subject'] : (string) $r['name'], 'kind' => 'product' === $r['type'] ? 'Subscription' : 'Recurring', 'date' => $r['due_date'], 'amount' => (float) $r['price'], 'currency' => $r['currency'] );
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT description, price, currency, bill_on_date, incurred_on FROM " . self::t( 'charges' ) . " WHERE client_id=%d AND status='pending' AND invoice_id=0 AND price>0 ORDER BY COALESCE(bill_on_date, incurred_on) ASC", $client_id ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$rows[] = array( 'title' => (string) $r['description'], 'kind' => 'One-off', 'date' => $r['bill_on_date'] ? $r['bill_on_date'] : $r['incurred_on'], 'amount' => (float) $r['price'], 'currency' => $r['currency'] );
		}
		usort( $rows, function ( $a, $b ) {
			return strcmp( (string) $a['date'], (string) $b['date'] );
		} );
		return $rows;
	}

	/* ───────────────────────── Contracts ───────────────────────── */

	/** Contracts that have been sent (never drafts or voided ones). `$email` marks the ones this person still has to sign. */
	public static function contracts( $client_id, $email = '' ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, number, title, status, sent_at, completed_at, pdf_file, pdf_hash, expires_on FROM " . self::t( 'contracts' ) . " WHERE client_id=%d AND status NOT IN ('draft','void') ORDER BY COALESCE(completed_at, sent_at, created_at) DESC, id DESC", $client_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as &$r ) {
			$r['id']       = (int) $r['id'];
			$r['signers']  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT name, role, status, signed_at FROM ' . self::t( 'contract_signers' ) . ' WHERE contract_id=%d ORDER BY sort_order, id', $r['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
			$r['sign_url'] = '';
			if ( '' !== $email && in_array( $r['status'], array( 'sent', 'partially_signed' ), true ) ) {
				$tok = $wpdb->get_row( $wpdb->prepare( 'SELECT token, status FROM ' . self::t( 'contract_signers' ) . " WHERE contract_id=%d AND email=%s AND status NOT IN ('signed','declined') LIMIT 1", $r['id'], $email ), ARRAY_A ); // phpcs:ignore WordPress.DB
				if ( $tok ) {
					$r['sign_url'] = Contracts::sign_url( $tok['token'] );
				}
			}
			$r['has_pdf']  = 'signed' === $r['status'] && '' !== (string) $r['pdf_file'];
			$r['cert_url'] = 'signed' === $r['status'] ? ( Contracts::cert_id( $r['id'] ) ? Contracts::verify_url( Contracts::cert_id( $r['id'] ) ) : '' ) : '';
			unset( $r['pdf_hash'], $r['pdf_file'] );
		}
		return $rows;
	}

	/** The signed PDF of one of the client's contracts, as array( path, filename ), or null. */
	public static function contract_pdf( $client_id, $id ) {
		global $wpdb;
		$c = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'contracts' ) . " WHERE id=%d AND client_id=%d AND status='signed'", $id, $client_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $c || ! Contract_Pdf::verify( $c ) ) {
			return null;
		}
		return array( Contract_Pdf::path( $c ), Contract_Pdf::filename( $c ) );
	}

	/** Which sections exist for this site and settings. */
	public static function sections() {
		$s = \BizyDash\Settings::get();
		return array(
			'projects'  => ! empty( $s['portal_show_projects'] ) && Modules::enabled( 'work' ),
			'tasks'     => ! empty( $s['portal_show_tasks'] ),
			'assignee'  => ! empty( $s['portal_show_assignee'] ),
			'invoices'  => ! empty( $s['portal_show_invoices'] ) && Modules::enabled( 'finance' ),
			'billing'   => ! empty( $s['portal_show_billing'] ) && Modules::enabled( 'finance' ),
			'contracts' => ! empty( $s['portal_show_contracts'] ) && Modules::enabled( 'contracts' ),
		);
	}
}
