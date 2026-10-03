<?php
namespace BizyDash;

use BizyDash\Database\Schema;
use BizyDash\Services\Activity;
use BizyDash\Services\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Public, shareable invoice page: https://site/invoice/<slug> (legacy ?bd_invoice=<token> still works)
 * A standalone branded document (no theme) that prints cleanly to PDF.
 */
class Public_Invoice {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
	}

	public static function maybe_render() {
		$token = '';
		if ( ! empty( $_GET['bd_invoice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$token = sanitize_text_field( wp_unslash( $_GET['bd_invoice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} else {
			$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			$rel  = trim( substr( (string) $path, strlen( $home ) ), '/' );
			$base = Invoices::slug_base();
			if ( 0 === strpos( $rel, $base . '/' ) && preg_match( '#^' . preg_quote( $base, '#' ) . '/([A-Za-z0-9_-]{4,64})$#', $rel, $m ) ) {
				$token = $m[1];
			}
		}
		if ( '' === $token ) {
			return;
		}
		global $wpdb;
		$tbl   = Schema::table( 'invoices' );
		$inv   = ( strlen( $token ) >= 4 ) ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tbl WHERE public_token=%s", $token ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // WP Rocket, W3TC, WP Super Cache, etc.
		}
		do_action( 'litespeed_control_set_nocache', 'bizydash invoice' );
		header( 'X-LiteSpeed-Cache-Control: no-cache, no-store' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		header( 'Referrer-Policy: no-referrer' );

		$is_admin = current_user_can( 'manage_bizydash' );
		if ( ! $inv || ( ! $is_admin && in_array( $inv['status'], array( 'draft', 'cancelled' ), true ) ) ) {
			status_header( 404 );
			echo self::shell( 'Invoice unavailable', '<main class="bd-wrap"><div class="bd-doc bd-doc--empty"><h1>This invoice isn’t available</h1><p>The link may have expired or been replaced. Please contact the sender for a new link.</p></div></main>', '' ); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}

		if ( ! $is_admin ) {
			$first = empty( $inv['viewed_at'] );
			$wpdb->query( $wpdb->prepare( "UPDATE $tbl SET view_count = view_count + 1, viewed_at = COALESCE(viewed_at, %s) WHERE id=%d", current_time( 'mysql' ), $inv['id'] ) ); // phpcs:ignore WordPress.DB
			if ( $first ) {
				Activity::log( 'invoices', $inv['id'], $inv['client_id'], 'viewed', sprintf( 'Invoice %s was opened by the client', $inv['invoice_number'] ) );
			}
		}

		status_header( 200 );
		echo self::render( $inv, $is_admin ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/* ───────────────────────── Formatting ───────────────────────── */

	public static function money( $amount, $currency ) {
		$sym = $currency;
		foreach ( (array) Settings::get( 'currencies' ) as $c ) {
			if ( $c['code'] === $currency ) {
				$sym = $c['symbol'];
				break;
			}
		}
		return ( (float) $amount < 0 ? '−' : '' ) . $sym . number_format( abs( (float) $amount ), 2 );
	}

	private static function date( $d ) {
		return $d ? esc_html( wp_date( get_option( 'date_format' ), strtotime( $d . ' 12:00:00' ) ) ) : '—';
	}

	private static function nl( $text ) {
		return nl2br( esc_html( (string) $text ) );
	}

	private static function status_label( $status ) {
		$map = array(
			'paid'           => array( 'Paid', 'ok' ),
			'partially_paid' => array( 'Partially paid', 'warn' ),
			'overdue'        => array( 'Overdue', 'bad' ),
			'due'            => array( 'Due', 'warn' ),
			'sent'           => array( 'Awaiting payment', 'info' ),
			'draft'          => array( 'Draft', 'muted' ),
			'cancelled'      => array( 'Cancelled', 'muted' ),
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : array( ucfirst( $status ), 'muted' );
	}

	/* ───────────────────────── Payment methods ───────────────────────── */

	/** Shared by the public page: one card per bank account / payment link / other method. */
	private static function methods_html( array $methods, array $inv, $balance ) {
		$cur = $inv['currency'];
		ob_start();
		foreach ( $methods as $m ) {
			$title = '' !== $m['title'] ? $m['title'] : ( 'link' === $m['type'] ? 'Pay online' : ( 'bank' === $m['type'] ? 'Bank transfer' : 'Other' ) );
			if ( 'link' === $m['type'] ) :
				$label = '' !== $m['button_label'] ? $m['button_label'] : 'Pay ' . self::money( $balance, $cur );
				?>
				<a class="bd-m bd-m--link" href="<?php echo esc_url( $m['url'] ); ?>" target="_blank" rel="noopener noreferrer">
					<span class="bd-m__t"><?php echo esc_html( $title ); ?></span>
					<?php if ( $m['note'] ) : ?><span class="bd-m__s"><?php echo esc_html( $m['note'] ); ?></span><?php endif; ?>
					<span class="bd-m__go"><?php echo esc_html( $label ); ?> <i aria-hidden="true">→</i></span>
				</a>
				<?php
			elseif ( 'bank' === $m['type'] ) :
				$rows = array_filter(
					array(
						'Bank'           => $m['bank_name'],
						'Account name'   => $m['account_name'],
						'Account number' => $m['account_number'],
						( $m['extra_label'] ? $m['extra_label'] : 'Reference' ) => $m['extra_value'],
					),
					'strlen'
				);
				?>
				<div class="bd-m">
					<div class="bd-m__h"><span class="bd-m__t"><?php echo esc_html( $title ); ?></span><?php if ( $m['currency'] ) : ?><span class="bd-chip"><?php echo esc_html( $m['currency'] ); ?></span><?php endif; ?></div>
					<dl class="bd-kv">
						<?php foreach ( $rows as $k => $v ) : ?>
							<div><dt><?php echo esc_html( $k ); ?></dt><dd><span><?php echo esc_html( $v ); ?></span><?php if ( 'Account number' === $k || $k === $m['extra_label'] ) : ?><button type="button" class="bd-copy no-print" data-copy="<?php echo esc_attr( $v ); ?>">Copy</button><?php endif; ?></dd></div>
						<?php endforeach; ?>
					</dl>
					<?php if ( $m['note'] ) : ?><div class="bd-m__s"><?php echo self::nl( $m['note'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><?php endif; ?>
				</div>
				<?php
			else :
				?>
				<div class="bd-m">
					<div class="bd-m__h"><span class="bd-m__t"><?php echo esc_html( $title ); ?></span></div>
					<div class="bd-m__s bd-m__s--body"><?php echo self::nl( $m['note'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				</div>
				<?php
			endif;
		}
		return ob_get_clean();
	}

	/* ───────────────────────── Document ───────────────────────── */

	public static function render( array $inv, $is_admin = false ) {
		global $wpdb;
		$s        = Settings::get();
		$cur      = $inv['currency'];
		$client   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'clients' ) . ' WHERE id=%d', $inv['client_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$items    = $wpdb->get_results( $wpdb->prepare( 'SELECT it.*, s.name AS service_name FROM ' . Schema::table( 'invoice_items' ) . ' it LEFT JOIN ' . Schema::table( 'client_services' ) . ' cs ON cs.id=it.client_service_id LEFT JOIN ' . Schema::table( 'services' ) . ' s ON s.id=cs.service_id WHERE it.invoice_id=%d ORDER BY it.id', $inv['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$payments = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'payments' ) . ' WHERE invoice_id=%d ORDER BY paid_on, id', $inv['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$schedule = Invoices::schedule( $inv );
		$has_plan = ! empty( $schedule ) && empty( $schedule[0]['implicit'] );
		$balance  = max( 0, round( (float) $inv['amount'] - (float) $inv['amount_paid'], 2 ) );
		list( $st_label, $st_tone ) = self::status_label( $inv['status'] );
		$payable  = $balance > 0 && ! in_array( $inv['status'], array( 'paid', 'cancelled', 'draft' ), true );
		$methods  = $payable ? Settings::payment_methods( $cur, array( 'invoice_number' => $inv['invoice_number'], 'amount' => number_format( $balance, 2, '.', '' ), 'currency' => $cur ) ) : array();
		$has_link = false;
		foreach ( $methods as $m ) {
			$has_link = $has_link || 'link' === $m['type'];
		}
		$next = null;
		if ( $has_plan ) {
			foreach ( $schedule as $row ) {
				if ( in_array( $row['state'], array( 'overdue', 'due', 'upcoming', 'partial' ), true ) ) {
					$next = $row;
					break;
				}
			}
		}

		ob_start();
		?>
<div class="bd-bar no-print">
	<div class="bd-bar__in">
		<span class="bd-bar__who"><?php if ( $s['business_logo_url'] ) : ?><img src="<?php echo esc_url( $s['business_logo_url'] ); ?>" alt=""><?php endif; ?><b><?php echo esc_html( $s['business_name'] ); ?></b><span class="bd-hide-s">Invoice <?php echo esc_html( $inv['invoice_number'] ); ?></span></span>
		<span class="bd-bar__act">
			<?php if ( $is_admin && in_array( $inv['status'], array( 'draft', 'cancelled' ), true ) ) : ?><em>Preview — not visible to clients yet</em><?php endif; ?>
			<button type="button" class="ghost" onclick="window.print()">Print / PDF</button>
			<?php if ( $payable && ( $methods ) ) : ?><a class="solid" href="#pay"><?php echo $has_link ? 'Pay now' : 'How to pay'; ?></a><?php endif; ?>
		</span>
	</div>
</div>

<main class="bd-wrap">
<table class="bd-paper" role="presentation"><thead><tr><td><div class="bd-pg-sp"></div></td></tr></thead><tbody><tr><td>
<article class="bd-doc <?php echo 'paid' === $inv['status'] ? 'is-paid' : ''; ?>">
	<?php if ( 'paid' === $inv['status'] ) : ?><div class="bd-stamp">PAID</div><?php endif; ?>

	<header class="bd-head">
		<div class="bd-brand">
			<div>
				<?php if ( $s['business_logo_url'] ) : ?><img class="bd-logo" src="<?php echo esc_url( $s['business_logo_url'] ); ?>" alt="<?php echo esc_attr( $s['business_name'] ); ?>"><?php else : ?><div class="bd-biz"><?php echo esc_html( $s['business_name'] ); ?></div><?php endif; ?>
				<?php if ( $s['business_address'] ) : ?><div class="bd-muted bd-mt"><?php echo self::nl( $s['business_address'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><?php endif; ?>
				<div class="bd-muted"><?php echo esc_html( implode( ' · ', array_filter( array( $s['business_email'], $s['business_phone'] ) ) ) ); ?></div>
				<?php if ( $s['business_website'] ) : ?><div class="bd-muted"><?php echo esc_html( preg_replace( '#^https?://#', '', $s['business_website'] ) ); ?></div><?php endif; ?>
				<?php if ( $s['tax_id'] ) : ?><div class="bd-muted"><?php echo esc_html( $s['tax_label'] . ': ' . $s['tax_id'] ); ?></div><?php endif; ?>
			</div>
		</div>
		<div class="bd-title">
			<div class="bd-eyebrow">Invoice</div>
			<div class="bd-num"><?php echo esc_html( $inv['invoice_number'] ); ?></div>
			<span class="bd-pill bd-pill--<?php echo esc_attr( $st_tone ); ?>"><?php echo esc_html( $st_label ); ?></span>
		</div>
	</header>

	<?php if ( ! empty( $inv['title'] ) ) : ?>
	<section class="bd-for"><div class="bd-eyebrow">Invoice for</div><h1><?php echo esc_html( $inv['title'] ); ?></h1></section>
	<?php endif; ?>

	<section class="bd-sum">
		<div class="bd-sum__who">
			<div class="bd-k">Billed to</div>
			<div class="bd-strong bd-lg"><?php echo esc_html( $client ? $client['company_name'] : '' ); ?></div>
			<?php if ( $client ) : ?>
				<?php if ( $client['contact_person'] ) : ?><div class="bd-muted"><?php echo esc_html( $client['contact_person'] ); ?></div><?php endif; ?>
				<?php if ( $client['address'] ) : ?><div class="bd-muted"><?php echo self::nl( $client['address'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><?php endif; ?>
				<?php if ( $client['email'] ) : ?><div class="bd-muted"><?php echo esc_html( $client['email'] ); ?></div><?php endif; ?>
			<?php endif; ?>
		</div>
		<div class="bd-sum__dates">
			<div><div class="bd-k">Issued</div><div class="bd-strong"><?php echo self::date( $inv['issue_date'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div></div>
			<div><div class="bd-k">Due</div><div class="bd-strong <?php echo 'overdue' === $inv['status'] ? 'bd-late' : ''; ?>"><?php echo self::date( $inv['due_date'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div></div>
		</div>
		<div class="bd-sum__due">
			<div class="bd-k"><?php echo $balance > 0 ? 'Amount due' : 'Total paid'; ?></div>
			<div class="bd-big"><?php echo esc_html( self::money( $balance > 0 ? $balance : $inv['amount'], $cur ) ); ?></div>
			<?php if ( $balance > 0 && (float) $inv['amount_paid'] > 0 ) : ?>
				<div class="bd-sub"><?php echo esc_html( self::money( $inv['amount_paid'], $cur ) ); ?> paid of <?php echo esc_html( self::money( $inv['amount'], $cur ) ); ?></div>
			<?php elseif ( $next && $balance > 0 ) : ?>
				<div class="bd-sub">Next: <?php echo esc_html( self::money( $next['balance'], $cur ) ); ?> by <?php echo wp_strip_all_tags( self::date( $next['due_date'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<?php endif; ?>
		</div>
	</section>

	<?php
	$fixed = array();
	$qtys  = array();
	foreach ( $items as $it ) {
		if ( isset( $it['kind'] ) && 'quantity' === $it['kind'] ) {
			$qtys[] = $it;
		} else {
			$fixed[] = $it;
		}
	}
	$groups = array();
	if ( $fixed ) {
		$groups[] = array( 'fixed', 'Fixed price', $fixed, 'One price, no quantity' );
	}
	if ( $qtys ) {
		$groups[] = array( 'quantity', 'By quantity', $qtys, 'Quantity × unit price' );
	}
	$split = count( $groups ) > 1;
	foreach ( $groups as $g ) :
		list( $gkind, $glabel, $gitems, $gnote ) = $g;
		$gsum = 0;
		?>
	<div class="bd-grp<?php echo $split ? ' bd-grp--card' : ''; ?>">
		<?php if ( $split ) : ?><h2 class="bd-grp__h"><span><?php echo esc_html( $glabel ); ?></span><em><?php echo esc_html( $gnote ); ?> · <?php echo (int) count( $gitems ); ?> <?php echo 1 === count( $gitems ) ? 'line' : 'lines'; ?></em></h2><?php endif; ?>
		<table class="bd-lines bd-lines--<?php echo esc_attr( $gkind ); ?>">
			<thead><tr><th>Description</th><?php if ( 'quantity' === $gkind ) : ?><th class="r bd-hide-s">Qty</th><th class="r bd-hide-s">Unit price</th><?php endif; ?><th class="r">Amount</th></tr></thead>
			<tbody>
			<?php
			foreach ( $gitems as $it ) :
				$gsum += (float) $it['amount'];
				?>
				<tr>
					<td>
						<div class="bd-strong"><?php echo esc_html( $it['description'] ); ?></div>
						<?php
						$sub = array();
						if ( ! empty( $it['service_name'] ) && 0 !== strcasecmp( trim( $it['service_name'] ), trim( $it['description'] ) ) ) {
							$sub[] = $it['service_name'];
						}
						if ( ! empty( $it['period_start'] ) ) {
							$sub[] = wp_strip_all_tags( self::date( $it['period_start'] ) ) . ' – ' . wp_strip_all_tags( self::date( $it['period_end'] ) );
						}
						if ( 'quantity' === $gkind ) {
							$sub[] = rtrim( rtrim( number_format( (float) $it['quantity'], 2 ), '0' ), '.' ) . ' × ' . self::money( $it['unit_price'], $cur );
						}
						if ( $sub ) :
							?>
							<div class="bd-muted bd-sm<?php echo 'quantity' === $gkind ? ' bd-show-s' : ''; ?>"><?php echo esc_html( implode( ' · ', $sub ) ); ?></div>
						<?php endif; ?>
					</td>
					<?php if ( 'quantity' === $gkind ) : ?>
						<td class="r bd-hide-s"><?php echo esc_html( rtrim( rtrim( number_format( (float) $it['quantity'], 2 ), '0' ), '.' ) ); ?></td>
						<td class="r bd-hide-s"><?php echo esc_html( self::money( $it['unit_price'], $cur ) ); ?></td>
					<?php endif; ?>
					<td class="r bd-strong"><?php echo esc_html( self::money( $it['amount'], $cur ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $split ) : ?><div class="bd-grp__sum"><span><?php echo esc_html( $glabel ); ?> subtotal</span><span><?php echo esc_html( self::money( $gsum, $cur ) ); ?></span></div><?php endif; ?>
	</div>
	<?php endforeach; ?>
	<?php if ( ! $items ) : ?>
	<table class="bd-lines"><thead><tr><th>Description</th><th class="r">Amount</th></tr></thead><tbody><tr><td><div class="bd-strong"><?php echo esc_html( $inv['description'] ? $inv['description'] : 'Services' ); ?></div></td><td class="r bd-strong"><?php echo esc_html( self::money( $inv['amount'], $cur ) ); ?></td></tr></tbody></table>
	<?php endif; ?>

	<section class="bd-totals">
		<div class="bd-totals__box">
			<div class="row"><span>Subtotal</span><span><?php echo esc_html( self::money( $inv['subtotal'] > 0 ? $inv['subtotal'] : $inv['amount'], $cur ) ); ?></span></div>
			<?php if ( (float) $inv['discount_amount'] > 0 ) : ?>
				<div class="row"><span>Discount<?php echo 'percent' === $inv['discount_type'] ? ' (' . esc_html( (float) $inv['discount_value'] ) . '%)' : ''; ?></span><span>−<?php echo esc_html( self::money( $inv['discount_amount'], $cur ) ); ?></span></div>
			<?php endif; ?>
			<?php if ( (float) $inv['tax_amount'] > 0 ) : ?>
				<div class="row"><span><?php echo esc_html( $s['tax_label'] ); ?> (<?php echo esc_html( (float) $inv['tax_rate'] ); ?>%)</span><span><?php echo esc_html( self::money( $inv['tax_amount'], $cur ) ); ?></span></div>
			<?php endif; ?>
			<div class="row total"><span>Total</span><span><?php echo esc_html( self::money( $inv['amount'], $cur ) ); ?></span></div>
			<?php if ( (float) $inv['amount_paid'] > 0 ) : ?>
				<div class="row"><span>Paid</span><span>−<?php echo esc_html( self::money( $inv['amount_paid'], $cur ) ); ?></span></div>
				<div class="row due"><span>Balance due</span><span><?php echo esc_html( self::money( $balance, $cur ) ); ?></span></div>
			<?php endif; ?>
		</div>
	</section>

	<?php if ( $has_plan ) : ?>
	<?php
	$sch_paid = 0;
	$sch_left = 0;
	foreach ( $schedule as $row ) {
		$sch_paid += (float) $row['paid'];
		$sch_left += (float) $row['balance'];
	}
	?>
	<section class="bd-grp bd-grp--card bd-block">
		<h2 class="bd-grp__h"><span>Payment schedule</span><em><?php echo (int) count( $schedule ); ?> instalments</em></h2>
		<div class="bd-scroll"><table class="bd-sched">
			<thead><tr><th>Instalment</th><th>Due</th><th class="r">Amount</th><th class="r">Paid</th><th class="r">Remaining</th><th></th></tr></thead>
			<tbody>
			<?php
			foreach ( $schedule as $row ) :
				$tone  = array( 'paid' => 'ok', 'partial' => 'warn', 'overdue' => 'bad', 'due' => 'warn', 'upcoming' => 'muted' );
				$label = array( 'paid' => 'Paid', 'partial' => 'Part-paid', 'overdue' => 'Overdue', 'due' => 'Due today', 'upcoming' => 'Upcoming' );
				?>
				<tr>
					<td class="bd-strong"><?php echo esc_html( $row['label'] ); ?><?php echo null !== $row['percent'] ? ' <span class="bd-muted bd-sm">(' . esc_html( (float) $row['percent'] ) . '%)</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td><?php echo self::date( $row['due_date'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td class="r"><?php echo esc_html( self::money( $row['amount'], $cur ) ); ?></td>
					<td class="r"><?php echo esc_html( self::money( $row['paid'], $cur ) ); ?></td>
					<td class="r bd-strong"><?php echo esc_html( self::money( $row['balance'], $cur ) ); ?></td>
					<td class="r"><span class="bd-pill bd-pill--<?php echo esc_attr( $tone[ $row['state'] ] ); ?>"><?php echo esc_html( $label[ $row['state'] ] ); ?></span></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
		<div class="bd-grp__sum"><span>Paid <?php echo esc_html( self::money( $sch_paid, $cur ) ); ?></span><span>Remaining <?php echo esc_html( self::money( $sch_left, $cur ) ); ?></span></div>
	</section>
	<?php endif; ?>

	<?php if ( $payments ) : ?>
	<?php
	$rec_total = 0;
	foreach ( $payments as $p ) {
		$rec_total += (float) $p['amount'];
	}
	?>
	<section class="bd-grp bd-grp--card bd-block">
		<h2 class="bd-grp__h"><span>Payments received</span><em><?php echo (int) count( $payments ); ?> <?php echo 1 === count( $payments ) ? 'payment' : 'payments'; ?></em></h2>
		<table class="bd-sched bd-sched--rec">
			<tbody>
			<?php foreach ( $payments as $p ) : ?>
				<tr><td><?php echo self::date( $p['paid_on'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td><td class="bd-muted"><?php echo esc_html( trim( $p['method'] . ' ' . $p['reference'] ) ); ?></td><td class="r bd-strong"><?php echo esc_html( self::money( $p['amount'], $cur ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<div class="bd-grp__sum"><span>Total received</span><span><?php echo esc_html( self::money( $rec_total, $cur ) ); ?></span></div>
	</section>
	<?php endif; ?>

	<?php if ( $payable && ( $methods || $s['payment_instructions'] ) ) : ?>
	<section class="bd-pay" id="pay">
		<div class="bd-pay__h">
			<h2>How to pay</h2>
			<p>Please use <strong><?php echo esc_html( $inv['invoice_number'] ); ?></strong> as your payment reference<?php echo $methods ? '. Choose whichever option suits you.' : '.'; ?></p>
		</div>
		<?php if ( $methods ) : ?><div class="bd-methods"><?php echo self::methods_html( $methods, $inv, $balance ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><?php endif; ?>
		<?php if ( $s['payment_instructions'] ) : ?><div class="bd-pay__note"><?php echo self::nl( $s['payment_instructions'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><?php endif; ?>
	</section>
	<?php endif; ?>

	<?php if ( $inv['notes'] || $inv['terms'] ) : ?>
	<section class="bd-cols">
		<?php if ( $inv['notes'] ) : ?><div><h2>Notes</h2><div><?php echo self::nl( $inv['notes'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div></div><?php endif; ?>
		<?php if ( $inv['terms'] ) : ?><div><h2>Terms</h2><div class="bd-muted"><?php echo self::nl( $inv['terms'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div></div><?php endif; ?>
	</section>
	<?php endif; ?>

	<footer class="bd-foot"><?php echo esc_html( $s['invoice_footer'] ); ?></footer>
</article>
</td></tr></tbody><tfoot><tr><td><div class="bd-pg-sp"></div></td></tr></tfoot></table>
</main>
<script>
document.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('[data-copy]');if(!b)return;var t=b.getAttribute('data-copy'),d=function(){var o=b.textContent;b.textContent='Copied';b.classList.add('is-done');setTimeout(function(){b.textContent=o;b.classList.remove('is-done')},1400)};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(d,function(){})}else{var a=document.createElement('textarea');a.value=t;document.body.appendChild(a);a.select();try{document.execCommand('copy');d()}catch(x){}document.body.removeChild(a)}});
</script>
		<?php
		$body = ob_get_clean();
		return self::shell( 'Invoice ' . $inv['invoice_number'] . ( ! empty( $inv['title'] ) ? ' — ' . $inv['title'] : '' ) . ' — ' . $s['business_name'], $body, $s['business_name'] );
	}

	public static function shell( $title, $body, $biz ) {
		$font = BIZYDASH_URL . 'assets/fonts/';
		ob_start();
		?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title><?php echo esc_html( $title ); ?></title>
<style>
@font-face{font-family:'Inter';font-weight:100 900;font-display:swap;src:url('<?php echo esc_url( $font . 'inter-latin.woff2' ); ?>') format('woff2');unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}
@font-face{font-family:'Inter';font-weight:100 900;font-display:swap;src:url('<?php echo esc_url( $font . 'inter-latin-ext.woff2' ); ?>') format('woff2');unicode-range:U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+20A0-20AB,U+20AD-20C0,U+2113}
:root{--ink:#0e121b;--ink2:#525866;--muted:#868e9d;--line:rgba(14,18,27,.07);--bg:#f3f5f8;--card:#fff;--deep:#222530;--soft:#f6f8fa}
*{box-sizing:border-box}
html,body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.55 'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;letter-spacing:-.006em;-webkit-font-smoothing:antialiased;font-feature-settings:'cv11','ss03'}
.bd-bar{position:sticky;top:0;z-index:5;background:rgba(255,255,255,.82);backdrop-filter:saturate(180%) blur(14px);box-shadow:0 1px 0 var(--line)}
.bd-bar__in{max-width:920px;margin:0 auto;padding:11px 20px;display:flex;justify-content:space-between;align-items:center;gap:12px}
.bd-bar__who{display:flex;align-items:center;gap:10px;color:var(--ink2);font-weight:500;min-width:0}.bd-bar__who b{color:var(--ink);font-weight:650;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.bd-bar__who img{height:24px;width:auto;border-radius:5px}
.bd-bar__act{display:flex;align-items:center;gap:8px}.bd-bar__act em{font-style:normal;color:#8a5300;background:#fffaeb;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600}
.bd-bar button,.bd-bar a.solid{border:0;font:inherit;font-weight:600;padding:8px 15px;border-radius:9px;cursor:pointer;text-decoration:none;line-height:1.4;display:inline-block}
.bd-bar .ghost{background:#fff;color:var(--ink);box-shadow:0 0 0 1px rgba(14,18,27,.12),0 1px 2px rgba(14,18,27,.05)}
.bd-bar .solid{background:var(--deep);color:#fff;box-shadow:0 1px 2px rgba(14,18,27,.3)}
.bd-wrap{max-width:920px;margin:0 auto;padding:26px 16px 56px}
.bd-doc{position:relative;background:var(--card);border-radius:18px;padding:50px 54px 40px;box-shadow:0 0 0 1px rgba(14,18,27,.04),0 18px 48px -22px rgba(14,18,27,.22)}
.bd-doc--empty{text-align:center;padding:72px 32px}.bd-doc--empty h1{font-size:22px;margin:0 0 8px}
.bd-head{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;margin-bottom:30px}
.bd-brand{display:flex;gap:16px;align-items:flex-start}.bd-logo{display:block;height:58px;width:auto;max-width:200px;object-fit:contain;border-radius:6px}
.bd-biz{font-size:17px;font-weight:700;letter-spacing:-.02em}.bd-muted{color:var(--muted)}.bd-sm{font-size:12px}.bd-mt{margin-top:10px}.bd-strong{font-weight:600}.bd-lg{font-size:16px}.bd-late{color:#b42318}
.bd-title{text-align:right}.bd-eyebrow{font-size:11px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}.bd-num{font-size:26px;font-weight:700;letter-spacing:-.035em;line-height:1.15;margin:3px 0 10px}
.bd-pill{display:inline-block;padding:3px 11px;border-radius:999px;font-size:12px;font-weight:650;background:#f2f5f8;color:#222530}
.bd-pill--ok{background:#e0faec;color:#0b4627}.bd-pill--warn{background:#fffaeb;color:#624c18}.bd-pill--bad{background:#ffebec;color:#681219}.bd-pill--info{background:#ebf1ff;color:#122368}
.bd-for{margin:-6px 0 22px}.bd-for h1{margin:4px 0 0;font-size:24px;line-height:1.25;font-weight:700;letter-spacing:-.03em;color:var(--ink)}
.bd-sum{display:grid;grid-template-columns:1.35fr .8fr 1.15fr;gap:14px;margin-bottom:30px}
.bd-sum>div{border-radius:14px;padding:18px 20px}
.bd-sum__who,.bd-sum__dates{background:var(--soft)}
.bd-sum__dates{display:flex;flex-direction:column;gap:14px;justify-content:center}
.bd-sum__due{background:var(--deep);color:#fff;display:flex;flex-direction:column;justify-content:center;text-align:left}
.bd-sum__due .bd-k{color:rgba(255,255,255,.6)}.bd-sub{color:rgba(255,255,255,.7);font-size:12.5px;margin-top:4px}
.bd-k{font-size:11px;font-weight:650;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:4px}
.bd-big{font-size:30px;font-weight:700;letter-spacing:-.04em;line-height:1.1}
table{width:100%;border-collapse:collapse}th{font-size:11px;font-weight:650;letter-spacing:.07em;text-transform:uppercase;color:var(--muted);text-align:left;padding:0 0 10px;box-shadow:inset 0 -1px 0 var(--line)}
td{padding:15px 0;box-shadow:inset 0 -1px 0 var(--line);vertical-align:top}tbody tr:last-child td{box-shadow:none}.r{text-align:right}th.r{text-align:right}
.bd-lines td:first-child{padding-right:16px}.bd-lines--fixed td:first-child,.bd-lines--fixed th:first-child{width:auto}.bd-grp+.bd-grp{margin-top:22px}
.bd-grp--card{border:1px solid #dfe3ea;border-radius:14px}
.bd-grp__h{margin:0;display:flex;align-items:baseline;justify-content:space-between;gap:12px;padding:12px 18px;background:#eceff4;border-radius:13px 13px 0 0;color:var(--ink);font-size:12.5px;font-weight:800;letter-spacing:.09em;text-transform:uppercase}
.bd-grp__h em{font-style:normal;font-weight:500;letter-spacing:0;text-transform:none;font-size:12.5px;color:var(--ink2)}
.bd-grp--card .bd-lines th{padding:12px 18px 8px;box-shadow:none}.bd-grp--card .bd-lines td{padding:14px 18px}.bd-grp--card .bd-lines td{box-shadow:inset 0 -1px 0 var(--line)}.bd-grp--card .bd-lines tbody tr:last-child td{box-shadow:none}.bd-grp--card .bd-lines td:first-child{padding-right:16px}
.bd-block.bd-grp--card{margin-top:22px}.bd-grp--card .bd-sched th{padding:12px 0 8px 18px;box-shadow:none;white-space:nowrap}.bd-grp--card .bd-sched td{padding:14px 0 14px 18px;box-shadow:inset 0 -1px 0 var(--line)}.bd-grp--card .bd-sched th:last-child,.bd-grp--card .bd-sched td:last-child{padding-right:18px}.bd-grp--card .bd-sched tbody tr:last-child td{box-shadow:none}.bd-grp--card .bd-sched td.r,.bd-grp--card .bd-sched th.r{padding-left:10px}.bd-grp--card .bd-scroll{overflow-x:auto}
.bd-grp__sum{display:flex;justify-content:space-between;padding:13px 18px;margin-top:0;background:var(--soft);border-radius:0 0 13px 13px;box-shadow:inset 0 1px 0 #dfe3ea;color:var(--ink);font-weight:700;font-size:14px}
.bd-grp:not(.bd-grp--card) .bd-grp__sum{display:none}.bd-show-s{display:none}
.bd-totals{display:flex;justify-content:flex-end;margin-top:14px}.bd-totals__box{width:340px}
.row{display:flex;justify-content:space-between;padding:6px 0;color:var(--ink2)}.row.total{font-size:16px;font-weight:700;color:var(--ink);margin-top:6px;padding-top:12px;box-shadow:inset 0 1px 0 var(--line)}
.row.due{font-size:17px;font-weight:700;color:var(--ink);background:var(--soft);margin:8px -14px 0;padding:12px 14px;border-radius:10px}
.bd-block{margin-top:34px}h2{font-size:11.5px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--muted);margin:0 0 10px}
.bd-scroll{overflow-x:auto}.bd-sched td,.bd-sched th{padding-right:14px}.bd-sched td.r,.bd-sched th.r{white-space:nowrap}.bd-sched td:last-child,.bd-sched th:last-child{padding-right:0}
.bd-pay{margin-top:38px;background:var(--soft);border-radius:16px;padding:26px 26px 22px;scroll-margin-top:70px}
.bd-pay__h h2{margin:0 0 4px;color:var(--ink);font-size:16px;letter-spacing:-.02em;text-transform:none;font-weight:700}.bd-pay__h p{margin:0 0 18px;color:var(--ink2)}
.bd-methods{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:14px;align-items:start}
.bd-m{display:block;background:#fff;border-radius:13px;padding:18px 18px 16px;text-decoration:none;color:inherit;box-shadow:0 0 0 1px rgba(14,18,27,.05),0 1px 2px rgba(14,18,27,.04)}
.bd-m__h{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px}.bd-m__t{font-weight:650;font-size:14.5px;letter-spacing:-.015em;display:block}
.bd-m__s{color:var(--ink2);font-size:13px;margin-top:8px}.bd-m__s--body{margin-top:0}
.bd-chip{font-size:11px;font-weight:700;letter-spacing:.05em;color:var(--ink2);background:var(--soft);padding:2px 8px;border-radius:999px}
.bd-kv{margin:0}.bd-kv>div{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:8px 0;box-shadow:inset 0 -1px 0 var(--line)}.bd-kv>div:last-child{box-shadow:none}
.bd-kv dt{color:var(--muted);font-size:12.5px;flex:none}.bd-kv dd{margin:0;font-weight:600;display:flex;align-items:center;gap:8px;text-align:right;word-break:break-word}
.bd-copy{border:0;background:var(--soft);color:var(--ink2);font:inherit;font-size:11.5px;font-weight:650;padding:3px 9px;border-radius:7px;cursor:pointer}.bd-copy:hover{background:#e9edf2}.bd-copy.is-done{background:#e0faec;color:#0b4627}
.bd-m--link{background:var(--deep);color:#fff;transition:transform .12s,box-shadow .12s;display:flex;flex-direction:column;box-shadow:0 6px 18px -8px rgba(34,37,48,.55)}.bd-m--link:hover{transform:translateY(-1px);box-shadow:0 10px 24px -8px rgba(34,37,48,.6)}
.bd-m--link .bd-m__s{color:rgba(255,255,255,.68);margin-top:4px}.bd-m__go{margin-top:16px;display:inline-flex;align-items:center;gap:6px;font-weight:650;background:#fff;color:var(--deep);padding:9px 14px;border-radius:9px;align-self:flex-start}.bd-m__go i{font-style:normal}
.bd-pay__note{margin-top:16px;color:var(--ink2);font-size:13px}
.bd-cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:28px;margin-top:34px}.bd-cols>div>div{color:var(--ink2)}
.bd-foot{margin-top:34px;padding-top:18px;box-shadow:inset 0 1px 0 var(--line);text-align:center;color:var(--muted);font-size:13px}
.bd-stamp{position:absolute;right:56px;top:150px;transform:rotate(-12deg);border:3px solid #1fc16b;color:#1fc16b;font-weight:800;font-size:34px;letter-spacing:.1em;padding:2px 18px;border-radius:10px;opacity:.28;pointer-events:none}
@media screen and (max-width:760px){.bd-wrap{padding:0 0 32px}.bd-doc{border-radius:0;padding:26px 18px}.bd-head{flex-direction:column}.bd-title{text-align:left}.bd-sum{grid-template-columns:1fr}.bd-totals__box{width:100%}.bd-stamp{right:20px;top:120px}.bd-hide-s{display:none}.bd-show-s{display:block}.bd-pay{padding:20px 14px 16px}.bd-methods{grid-template-columns:1fr}}
.bd-paper{display:block;width:100%}.bd-paper>thead,.bd-paper>tfoot{display:none}.bd-paper>tbody,.bd-paper>tbody>tr,.bd-paper>tbody>tr>td{display:block;padding:0;box-shadow:none}
@page{margin:0}
@media print{
html,body{background:#fff;width:100%}
*{-webkit-print-color-adjust:exact;print-color-adjust:exact}
.no-print{display:none!important}
.bd-wrap{padding:0;max-width:none;margin:0}
.bd-paper{display:table;width:100%;border-collapse:collapse}
.bd-paper>thead{display:table-header-group}.bd-paper>tfoot{display:table-footer-group}.bd-paper>tbody{display:table-row-group}.bd-paper>tbody>tr{display:table-row}
table.bd-paper>thead>tr>td,table.bd-paper>tbody>tr>td,table.bd-paper>tfoot>tr>td{display:table-cell;padding:0;box-shadow:none}
.bd-pg-sp{height:13mm}
.bd-doc{box-shadow:none;border-radius:0;padding:0 13mm;max-width:none;overflow:visible;zoom:.9}
.bd-grp--card{-webkit-box-decoration-break:clone;box-decoration-break:clone}.bd-grp__h{break-after:avoid;page-break-after:avoid}.bd-grp__sum{break-before:avoid}.bd-head,.bd-sum,.bd-totals,.bd-pay,.bd-m,.bd-block,.bd-cols>div,.bd-foot,.bd-lines tr,.bd-sched tr{break-inside:avoid;page-break-inside:avoid}
.bd-lines thead,.bd-sched thead{display:table-header-group}
.bd-lines tbody tr:last-child{break-after:avoid}
h2,.bd-pay__h{break-after:avoid;page-break-after:avoid}
.bd-sum{margin-bottom:22px}.bd-pay{margin-top:28px}.bd-m{box-shadow:none;border:1px solid #e3e7ec}.bd-m--link{box-shadow:none;border-color:var(--deep)}
.bd-sum>div,.bd-pay{box-shadow:none}
.bd-scroll{overflow:visible}
}
</style>
</head>
<body>
<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput ?>
</body>
</html>
		<?php
		return ob_get_clean();
	}
}
