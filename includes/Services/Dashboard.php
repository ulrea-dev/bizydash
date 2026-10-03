<?php
namespace BizyDash\Services;

use BizyDash\Database\Schema;
use BizyDash\Settings;

defined( 'ABSPATH' ) || exit;

class Dashboard {

	/**
	 * Billed (invoices issued, excluding draft/cancelled) vs paid (payments received) per month, per currency.
	 *
	 * @return array{months: string[], labels: string[], currencies: array<string, array{billed: float[], paid: float[]}>}
	 */
	public static function chart( $months = 6 ) {
		global $wpdb;
		$months = max( 2, min( 24, (int) $months ) );
		$now    = Billing::today();

		$keys   = array();
		$labels = array();
		for ( $i = $months - 1; $i >= 0; $i-- ) {
			$ts       = strtotime( gmdate( 'Y-m-01', strtotime( $now ) ) . " -$i months" );
			$keys[]   = gmdate( 'Y-m', $ts );
			$labels[] = gmdate( 'M Y', $ts );
		}
		$from = $keys[0] . '-01';

		$inv = Schema::table( 'invoices' );
		$pay = Schema::table( 'payments' );

		$billed = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(issue_date,'%%Y-%%m') ym, currency, SUM(amount) total FROM $inv WHERE status NOT IN ('draft','cancelled') AND issue_date >= %s GROUP BY ym, currency", $from ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$paid   = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(paid_on,'%%Y-%%m') ym, currency, SUM(amount) total FROM $pay WHERE paid_on >= %s GROUP BY ym, currency", $from ), ARRAY_A ); // phpcs:ignore WordPress.DB

		$out = array();
		$put = function ( $rows, $field ) use ( &$out, $keys ) {
			foreach ( (array) $rows as $r ) {
				$idx = array_search( $r['ym'], $keys, true );
				if ( false === $idx ) {
					continue;
				}
				if ( ! isset( $out[ $r['currency'] ] ) ) {
					$out[ $r['currency'] ] = array( 'billed' => array_fill( 0, count( $keys ), 0 ), 'paid' => array_fill( 0, count( $keys ), 0 ) );
				}
				$out[ $r['currency'] ][ $field ][ $idx ] = round( (float) $r['total'], 2 );
			}
		};
		$put( $billed, 'billed' );
		$put( $paid, 'paid' );

		return array( 'months' => $keys, 'labels' => $labels, 'currencies' => (object) $out );
	}

	/** Cross-entity quick search used by the header search box. */
	public static function search( $q ) {
		global $wpdb;
		$q = trim( (string) $q );
		if ( strlen( $q ) < 2 ) {
			return array();
		}
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$out  = array();

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, company_name, contact_person, email FROM ' . Schema::table( 'clients' ) . ' WHERE company_name LIKE %s OR contact_person LIKE %s OR email LIKE %s ORDER BY company_name LIMIT 5', $like, $like, $like ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$out[] = array( 'type' => 'client', 'id' => (int) $r['id'], 'client_id' => (int) $r['id'], 'label' => $r['company_name'], 'sub' => trim( $r['contact_person'] . ' ' . $r['email'] ) );
		}

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT i.id, i.client_id, i.invoice_number, i.status, i.amount, i.currency, c.company_name FROM ' . Schema::table( 'invoices' ) . ' i LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=i.client_id WHERE i.invoice_number LIKE %s OR i.description LIKE %s ORDER BY i.id DESC LIMIT 5', $like, $like ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$out[] = array( 'type' => 'invoice', 'id' => (int) $r['id'], 'client_id' => (int) $r['client_id'], 'label' => $r['invoice_number'], 'sub' => $r['company_name'] . ' · ' . str_replace( '_', ' ', $r['status'] ) );
		}

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT cs.id, cs.client_id, cs.subject, cs.provider, s.name AS service_name, s.type AS service_type, c.company_name FROM ' . Schema::table( 'client_services' ) . ' cs LEFT JOIN ' . Schema::table( 'services' ) . ' s ON s.id=cs.service_id LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=cs.client_id WHERE cs.subject LIKE %s OR cs.provider LIKE %s ORDER BY cs.subject LIMIT 6', $like, $like ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$out[] = array( 'type' => 'subscription', 'id' => (int) $r['id'], 'client_id' => (int) $r['client_id'], 'label' => '' !== (string) $r['subject'] ? $r['subject'] : $r['service_name'], 'sub' => $r['service_name'] . ' · ' . $r['company_name'] . ( 'product' === $r['service_type'] ? '' : ' · retainer' ) );
		}

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name FROM ' . Schema::table( 'services' ) . ' WHERE name LIKE %s ORDER BY name LIMIT 5', $like ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$out[] = array( 'type' => 'service', 'id' => (int) $r['id'], 'client_id' => 0, 'label' => $r['name'], 'sub' => 'Catalog' );
		}

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT p.id, p.name, p.client_id, c.company_name FROM ' . Schema::table( 'projects' ) . ' p LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=p.client_id WHERE p.name LIKE %s ORDER BY p.name LIMIT 4', $like ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$out[] = array( 'type' => 'project', 'id' => (int) $r['id'], 'client_id' => (int) $r['client_id'], 'label' => $r['name'], 'sub' => 'Project' . ( $r['company_name'] ? ' · ' . $r['company_name'] : '' ) );
		}

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT t.id, t.title, t.status, t.project_id, t.client_id, c.company_name FROM ' . Schema::table( 'tasks' ) . ' t LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=t.client_id WHERE t.title LIKE %s ORDER BY t.status=\'done\', t.id DESC LIMIT 5', $like ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$out[] = array( 'type' => 'task', 'id' => (int) $r['id'], 'client_id' => (int) $r['client_id'], 'project_id' => (int) $r['project_id'], 'label' => $r['title'], 'sub' => 'Task · ' . ( 'done' === $r['status'] ? 'done' : 'open' ) . ( $r['company_name'] ? ' · ' . $r['company_name'] : '' ) );
		}

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, type FROM ' . Schema::table( 'brands' ) . ' WHERE name LIKE %s ORDER BY name LIMIT 5', $like ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$out[] = array( 'type' => 'brand', 'id' => (int) $r['id'], 'client_id' => 0, 'label' => $r['name'], 'sub' => ucfirst( $r['type'] ) . ' ' . strtolower( Settings::get( 'department_label' ) ) );
		}

		return $out;
	}

	/**
	 * @param array $f Filters: client_type, brand_id, brand_type, due_soon, overdue, unpaid.
	 */
	public static function data( array $f ) {
		global $wpdb;

		$t_cl = Schema::table( 'clients' );
		$t_re = Schema::table( 'renewals' );
		$t_ch = Schema::table( 'charges' );
		$t_in = Schema::table( 'invoices' );
		$t_ac = Schema::table( 'activity' );
		$t_cs = Schema::table( 'client_services' );
		$t_sv = Schema::table( 'services' );

		$today = Billing::today();
		$days  = (int) Settings::get( 'due_soon_days' );
		$soon_days = $days; // $days is reused as a loop variable further down
		$soon  = gmdate( 'Y-m-d', strtotime( "$today +$days days" ) );
		$m_from = gmdate( 'Y-m-01', strtotime( $today ) );
		$m_to   = gmdate( 'Y-m-t', strtotime( $today ) );

		// Client scope (type / brand filters) applied as a sub-select, already prepared.
		$cw = array( '1=1' );
		$cp = array();
		if ( ! empty( $f['client_type'] ) ) {
			$cw[] = 'c.client_type=%s';
			$cp[] = $f['client_type'];
		}
		if ( ! empty( $f['brand_id'] ) ) {
			$cw[] = 'EXISTS (SELECT 1 FROM ' . Schema::table( 'client_brands' ) . ' cb WHERE cb.client_id=c.id AND cb.brand_id=%d)';
			$cp[] = (int) $f['brand_id'];
		}
		if ( ! empty( $f['brand_type'] ) ) {
			$cw[] = 'EXISTS (SELECT 1 FROM ' . Schema::table( 'client_brands' ) . ' cb JOIN ' . Schema::table( 'brands' ) . ' b ON b.id=cb.brand_id WHERE cb.client_id=c.id AND b.type=%s)';
			$cp[] = $f['brand_type'];
		}
		$scoped    = count( $cw ) > 1;
		$client_where = implode( ' AND ', $cw );
		$scope     = '';
		if ( $scoped ) {
			$sub   = "SELECT c.id FROM $t_cl c WHERE $client_where";
			$scope = ' AND %SCOPE% IN (' . $wpdb->prepare( $sub, $cp ) . ')'; // phpcs:ignore WordPress.DB
		}
		$sc = function ( $col ) use ( $scope ) {
			return str_replace( '%SCOPE%', $col, $scope );
		};

		$active_sql = "SELECT COUNT(*) FROM $t_cl c WHERE c.status='active' AND $client_where";
		$active     = (int) $wpdb->get_var( $cp ? $wpdb->prepare( $active_sql, $cp ) : $active_sql ); // phpcs:ignore WordPress.DB

		$renewals_due = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t_re WHERE status IN ('upcoming','due') AND due_date IS NOT NULL AND due_date <= %s", $soon ) . $sc( 'client_id' ) ); // phpcs:ignore WordPress.DB

		$sum = function ( $sql ) use ( $wpdb ) {
			$out = array();
			foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				$out[ $r['currency'] ] = array( 'amount' => round( (float) $r['total'], 2 ), 'count' => (int) $r['n'] );
			}
			return $out;
		};

		$open = "('sent','due','partially_paid','overdue')";

		$pending = $sum( "SELECT currency, SUM(price) total, COUNT(*) n FROM $t_ch WHERE status='pending'" . $sc( 'client_id' ) . ' GROUP BY currency' ); // phpcs:ignore WordPress.DB
		$outstanding = $sum( "SELECT currency, SUM(amount-amount_paid) total, COUNT(*) n FROM $t_in WHERE status IN $open" . $sc( 'client_id' ) . ' GROUP BY currency' ); // phpcs:ignore WordPress.DB
		$this_month = $sum( $wpdb->prepare( "SELECT currency, SUM(amount-amount_paid) total, COUNT(*) n FROM $t_in WHERE status IN $open AND due_date BETWEEN %s AND %s", $m_from, $m_to ) . $sc( 'client_id' ) . ' GROUP BY currency' ); // phpcs:ignore WordPress.DB
		$overdue = $sum( "SELECT currency, SUM(amount-amount_paid) total, COUNT(*) n FROM $t_in WHERE status='overdue'" . $sc( 'client_id' ) . ' GROUP BY currency' ); // phpcs:ignore WordPress.DB
		$overdue_count = array_sum( wp_list_pluck( $overdue, 'count' ) );

		$ren_where = "r.status IN ('upcoming','due')";
		if ( ! empty( $f['due_soon'] ) ) {
			$ren_where .= $wpdb->prepare( ' AND r.due_date <= %s', $soon ); // phpcs:ignore WordPress.DB
		}
		$renewals = $wpdb->get_results( "SELECT r.id, r.client_id, r.due_date, r.price, r.currency, r.status, r.subject, c.company_name AS client_name, s.name AS service_name FROM $t_re r LEFT JOIN $t_cl c ON c.id=r.client_id LEFT JOIN $t_cs cs ON cs.id=r.client_service_id LEFT JOIN $t_sv s ON s.id=cs.service_id WHERE $ren_where" . $sc( 'r.client_id' ) . ' ORDER BY r.due_date ASC LIMIT 8', ARRAY_A ); // phpcs:ignore WordPress.DB

		$charges = $wpdb->get_results( "SELECT ch.id, ch.client_id, ch.description, ch.price, ch.currency, ch.bill_on_date, c.company_name AS client_name FROM $t_ch ch LEFT JOIN $t_cl c ON c.id=ch.client_id WHERE ch.status='pending'" . $sc( 'ch.client_id' ) . ' ORDER BY ch.bill_on_date IS NULL, ch.bill_on_date ASC, ch.id DESC LIMIT 8', ARRAY_A ); // phpcs:ignore WordPress.DB

		$inv_where = ! empty( $f['unpaid'] ) ? "i.status IN $open" : "i.status='overdue'";
		$inv_title = ! empty( $f['unpaid'] ) ? 'Unpaid invoices' : 'Overdue invoices';
		$invoices  = $wpdb->get_results( "SELECT i.id, i.client_id, i.invoice_number, i.amount, i.amount_paid, i.currency, i.due_date, i.status, c.company_name AS client_name FROM $t_in i LEFT JOIN $t_cl c ON c.id=i.client_id WHERE $inv_where" . $sc( 'i.client_id' ) . ' ORDER BY i.due_date ASC LIMIT 8', ARRAY_A ); // phpcs:ignore WordPress.DB

		$activity = $wpdb->get_results( "SELECT a.id, a.client_id, a.summary, a.action, a.entity_type, a.created_at, c.company_name AS client_name FROM $t_ac a LEFT JOIN $t_cl c ON c.id=a.client_id WHERE 1=1" . $sc( 'a.client_id' ) . ' ORDER BY a.id DESC LIMIT 10', ARRAY_A ); // phpcs:ignore WordPress.DB

		// ── Billing pipeline (renewals + charges not yet invoiced, then invoices awaiting payment) ──
		$scope_ids = null;
		if ( $scoped ) {
			$scope_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT c.id FROM $t_cl c WHERE $client_where", $cp ) ) ); // phpcs:ignore WordPress.DB
		}
		$queue   = BillingQueue::items( array( 'client_ids' => $scope_ids ) );
		$pipeline = BillingQueue::summarise( $queue );

		// To invoice, split by kind (renewal cycles = recurring, charges = one-off).
		$to_invoice = array( 'one_off' => array(), 'recurring' => array() );
		foreach ( $queue as $qi ) {
			if ( ! in_array( $qi['state'], array( 'overdue', 'ready' ), true ) ) {
				continue;
			}
			$k = $qi['kind'];
			$c = $qi['currency'];
			if ( ! isset( $to_invoice[ $k ][ $c ] ) ) {
				$to_invoice[ $k ][ $c ] = array( 'amount' => 0, 'count' => 0 );
			}
			$to_invoice[ $k ][ $c ]['amount'] = round( $to_invoice[ $k ][ $c ]['amount'] + $qi['price'], 2 );
			++$to_invoice[ $k ][ $c ]['count'];
		}

		// Recurring revenue base and cash collected this month.
		$mrr = Subscriptions::mrr_totals( $scope_ids );
		$arr = array();
		foreach ( $mrr as $cur => $v ) {
			$arr[ $cur ] = array( 'amount' => round( $v['amount'] * 12, 2 ), 'count' => $v['count'] );
		}
		$t_pay     = Schema::table( 'payments' );
		$collected = $sum( $wpdb->prepare( "SELECT currency, SUM(amount) total, COUNT(*) n FROM $t_pay WHERE paid_on BETWEEN %s AND %s", $m_from, $m_to ) . $sc( 'client_id' ) . ' GROUP BY currency' ); // phpcs:ignore WordPress.DB

		$awaiting = $sum( "SELECT currency, SUM(amount-amount_paid) total, COUNT(*) n FROM $t_in WHERE status IN ('sent','due','partially_paid')" . $sc( 'client_id' ) . ' GROUP BY currency' ); // phpcs:ignore WordPress.DB

		// "Needs attention": the most urgent items across the whole pipeline.
		$attention = array();
		foreach ( $queue as $i ) {
			if ( in_array( $i['state'], array( 'overdue', 'ready' ), true ) ) {
				$attention[] = array(
					'kind'        => $i['type'],
					'ref_id'      => $i['ref_id'],
					'client_id'   => $i['client_id'],
					'client_name' => $i['client_name'],
					'label'       => $i['label'],
					'date'        => $i['date'],
					'days'        => $i['days'],
					'amount'      => $i['price'],
					'currency'    => $i['currency'],
					'state'       => 'overdue' === $i['state'] ? 'overdue_to_bill' : 'ready_to_bill',
				);
			}
		}
		$inv_rows = $wpdb->get_results( "SELECT i.id, i.client_id, i.invoice_number, i.title, i.amount, i.amount_paid, i.currency, i.due_date, i.status, c.company_name AS client_name FROM $t_in i LEFT JOIN $t_cl c ON c.id=i.client_id WHERE i.status IN ('sent','due','partially_paid','overdue')" . $sc( 'i.client_id' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $inv_rows as $i ) {
			$days = $i['due_date'] ? (int) round( ( strtotime( $i['due_date'] ) - strtotime( $today ) ) / DAY_IN_SECONDS ) : null;
			if ( 'overdue' === $i['status'] || ( null !== $days && $days <= 7 ) ) {
				$attention[] = array(
					'kind'        => 'invoice',
					'ref_id'      => (int) $i['id'],
					'client_id'   => (int) $i['client_id'],
					'client_name' => $i['client_name'],
					'label'       => 'Invoice ' . $i['invoice_number'],
					'date'        => $i['due_date'],
					'days'        => $days,
					'amount'      => round( (float) $i['amount'] - (float) $i['amount_paid'], 2 ),
					'currency'    => $i['currency'],
					'state'       => 'overdue' === $i['status'] ? 'overdue_payment' : 'payment_due',
				);
			}
		}
		usort( $attention, function ( $a, $b ) {
			$da = null === $a['days'] ? 9999 : $a['days'];
			$db = null === $b['days'] ? 9999 : $b['days'];
			return $da <=> $db;
		} );
		$attention = array_slice( $attention, 0, 8 );

		// ── Home: what to do today, what is coming up, and first-run setup ──
		$todo = array();
		foreach ( $queue as $i ) {
			if ( 'overdue' === $i['state'] || ( null !== $i['days'] && $i['days'] <= 7 ) ) {
				$todo[] = array(
					'kind'        => 'bill',
					'key'         => 'bill-' . $i['type'] . '-' . $i['ref_id'],
					'title'       => $i['label'],
					'sub'         => $i['client_name'] . ' · ' . ( 'overdue' === $i['state'] ? 'late to invoice' : 'ready to invoice' ),
					'client_id'   => (int) $i['client_id'],
					'days'        => $i['days'],
					'amount'      => $i['price'],
					'currency'    => $i['currency'],
					'action'      => array( 'label' => 'Invoice it', 'to' => '/billing/invoices/new?client=' . (int) $i['client_id'] . '&bill=' . $i['type'] . '-' . (int) $i['ref_id'] ),
				);
			}
		}
		foreach ( (array) $inv_rows as $i ) {
			$d    = $i['due_date'] ? (int) round( ( strtotime( $i['due_date'] ) - strtotime( $today ) ) / DAY_IN_SECONDS ) : null;
			$note = 'overdue' === $i['status'] ? 'not paid yet' : 'payment due soon';
			if ( 'overdue' === $i['status'] && ( null === $d || $d > 0 ) ) {
				// Overdue through an instalment (e.g. the deposit), not the final due date.
				$full = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'invoices' ) . ' WHERE id=%d', $i['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
				foreach ( $full ? (array) Invoices::schedule( $full ) : array() as $row ) {
					if ( isset( $row['state'], $row['due_date'] ) && 'overdue' === $row['state'] ) {
						$d    = (int) round( ( strtotime( $row['due_date'] ) - strtotime( $today ) ) / DAY_IN_SECONDS );
						$note = 'an instalment is overdue';
						break;
					}
				}
			}
			if ( 'overdue' === $i['status'] || ( null !== $d && $d <= 3 ) ) {
				$todo[] = array(
					'kind'      => 'invoice',
					'key'       => 'invoice-' . $i['id'],
					'title'     => $i['invoice_number'] . ( ! empty( $i['title'] ) ? ' — ' . $i['title'] : '' ),
					'sub'       => $i['client_name'] . ' · ' . $note,
					'client_id' => (int) $i['client_id'],
					'days'      => $d,
					'amount'    => round( (float) $i['amount'] - (float) $i['amount_paid'], 2 ),
					'currency'  => $i['currency'],
					'action'    => array( 'label' => 'Send reminder', 'to' => '/billing/invoices/' . (int) $i['id'] . '?send=1' ),
				);
			}
		}
		$t_tk  = Schema::table( 'tasks' );
		$t_tm  = Schema::table( 'team_members' );
		$tasks = $wpdb->get_results( $wpdb->prepare( "SELECT t.id, t.title, t.due_date, t.priority, t.project_id, t.client_id, c.company_name AS client_name, a.name AS assignee_name FROM $t_tk t LEFT JOIN $t_cl c ON c.id=t.client_id LEFT JOIN $t_tm a ON a.id=t.assignee_id WHERE t.status <> 'done' AND t.due_date IS NOT NULL AND t.due_date <= %s" . $sc( 't.client_id' ) . ' ORDER BY t.due_date ASC LIMIT 20', $today ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $tasks as $t ) {
			$d      = (int) round( ( strtotime( $t['due_date'] ) - strtotime( $today ) ) / DAY_IN_SECONDS );
			$todo[] = array(
				'kind'      => 'task',
				'key'       => 'task-' . $t['id'],
				'title'     => $t['title'],
				'sub'       => trim( ( $t['client_name'] ? $t['client_name'] : 'Internal' ) . ' · ' . ( $t['assignee_name'] ? $t['assignee_name'] : 'Unassigned' ) ),
				'client_id' => (int) $t['client_id'],
				'days'      => $d,
				'priority'  => $t['priority'],
				'action'    => array( 'label' => 'Open', 'to' => (int) $t['project_id'] ? '/work/projects/' . (int) $t['project_id'] : '/work/all' ),
			);
		}
		$finance = null;
		if ( current_user_can( 'bizydash_finance' ) ) {
			$owed = array();
			foreach ( (array) $wpdb->get_results( 'SELECT currency, SUM(amount) t, COUNT(*) n FROM ' . Schema::table( 'pay_items' ) . " WHERE status='payable' GROUP BY currency", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				$owed[ $r['currency'] ] = array( 'amount' => round( (float) $r['t'], 2 ), 'count' => (int) $r['n'] );
			}
			$finance = array( 'profit_month' => Profit::quick( $m_from, $m_to ), 'owed_team' => $owed );

			// Payroll reminder: salaried people exist and this month's salaries are not created yet.
			$period = gmdate( 'Y-m', strtotime( $today ) );
			$due    = count( Payroll::eligible( $period ) );
			$have   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'pay_items' ) . " WHERE kind='salary' AND period=%s AND status <> 'void'", $period ) ); // phpcs:ignore WordPress.DB
			if ( $due > $have && (int) gmdate( 'j', strtotime( $today ) ) >= 20 ) {
				$todo[] = array( 'kind' => 'payroll', 'key' => 'payroll-' . $period, 'title' => 'Run payroll for ' . wp_date( 'F Y', strtotime( $today ) ), 'sub' => ( $due - $have ) . ' salar' . ( 1 === $due - $have ? 'y' : 'ies' ) . ' not created yet', 'client_id' => 0, 'days' => 0, 'action' => array( 'label' => 'Open payroll', 'to' => '/finance/payroll' ) );
			}
		}
		usort( $todo, function ( $a, $b ) {
			$da = null === $a['days'] ? 9999 : $a['days'];
			$db = null === $b['days'] ? 9999 : $b['days'];
			return $da <=> $db;
		} );
		$todo_total = count( $todo );
		$todo       = array_slice( $todo, 0, 12 );

		$coming = array();
		foreach ( $queue as $i ) {
			if ( null !== $i['days'] && $i['days'] >= 0 && $i['days'] <= $soon_days ) {
				$coming[] = array(
					'key'       => 'bill-' . $i['type'] . '-' . $i['ref_id'],
					'title'     => $i['label'],
					'sub'       => $i['client_name'],
					'client_id' => (int) $i['client_id'],
					'date'      => $i['date'],
					'days'      => $i['days'],
					'amount'    => $i['price'],
					'currency'  => $i['currency'],
					'type'      => $i['type'],
				);
			}
		}
		if ( current_user_can( 'bizydash_finance' ) ) {
			// Recurring expenses coming up join "Coming up".
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'recurring_expenses' ) . " WHERE status='active' AND next_date IS NOT NULL AND next_date BETWEEN %s AND %s", $today, $soon ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				$dd       = (int) round( ( strtotime( $r['next_date'] ) - strtotime( $today ) ) / DAY_IN_SECONDS );
				$coming[] = array( 'key' => 'rexp-' . $r['id'], 'title' => $r['name'], 'sub' => 'Recurring expense', 'client_id' => 0, 'date' => $r['next_date'], 'days' => $dd, 'amount' => (float) $r['amount'], 'currency' => $r['currency'], 'type' => 'expense' );
			}
		}
		usort( $coming, function ( $a, $b ) {
			return $a['days'] <=> $b['days'];
		} );
		$coming = array_slice( $coming, 0, 20 );

		$raw   = (array) get_option( Settings::OPTION, array() );
		$cnt   = function ( $t ) use ( $wpdb ) {
			return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $t ) ); // phpcs:ignore WordPress.DB
		};
		$setup = array(
			'profile' => ! empty( $raw['business_name'] ) && ! empty( $raw['business_email'] ),
			'payment' => ! empty( $raw['payment_methods'] ) || ! empty( $raw['payment_instructions'] ),
			'client'  => $cnt( 'clients' ) > 0,
			'catalog' => $cnt( 'services' ) > 0,
			'invoice' => $cnt( 'invoices' ) > 0,
		);

		$num = function ( $rows, $cols ) {
			foreach ( $rows as &$r ) {
				foreach ( $cols as $c ) {
					if ( isset( $r[ $c ] ) ) {
						$r[ $c ] = (float) $r[ $c ];
					}
				}
			}
			return $rows;
		};

		return array(
			'stats'          => array(
				'active_clients'  => $active,
				'renewals_due'    => $renewals_due,
				'pending_charges' => $pending,
				'outstanding'     => $outstanding,
				'due_this_month'  => $this_month,
				'overdue'         => $overdue,
				'overdue_count'   => $overdue_count,
			),
			'renewals'       => $num( (array) $renewals, array( 'price' ) ),
			'charges'        => $num( (array) $charges, array( 'price' ) ),
			'invoices'       => $num( (array) $invoices, array( 'amount', 'amount_paid' ) ),
			'invoices_title' => $inv_title,
			'activity'       => (array) $activity,
			'billing'        => array(
				'pipeline'  => $pipeline,
				'awaiting'  => $awaiting,
				'overdue'   => $overdue,
				'attention' => $attention,
				'to_invoice' => $to_invoice,
				'mrr'       => $mrr,
				'arr'       => $arr,
				'collected' => $collected,
			),
			'finance'        => $finance,
			'todo'           => $todo,
			'todo_total'     => $todo_total,
			'coming_up'      => $coming,
			'setup'          => $setup,
			'due_soon_days'  => $soon_days,
		);
	}
}
