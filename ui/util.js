import { store } from './store';

export const lookup = ( type ) => store.meta.lookups[ type ] || [];

export const options = ( type, includeInactive = false ) =>
	lookup( type )
		.filter( ( i ) => includeInactive || i.is_active )
		.map( ( i ) => ( { value: i.key, label: i.label } ) );

export const humanize = ( s ) =>
	String( s || '' )
		.replace( /[_-]/g, ' ' )
		.replace( /^\w/, ( c ) => c.toUpperCase() );

export const labelOf = ( type, key ) => {
	const hit = lookup( type ).find( ( i ) => i.key === key );
	return hit ? hit.label : key ? humanize( key ) : '—';
};

export const colorOf = ( type, key ) => {
	const hit = lookup( type ).find( ( i ) => i.key === key );
	return hit ? hit.color : '';
};

export const currencies = () => store.meta.settings.currencies || [];
export const currencyOptions = () => currencies().filter( ( c ) => c.active !== false ).map( ( c ) => ( { value: c.code, label: `${ c.code } (${ c.symbol })` } ) );
export const defaultCurrency = () => store.meta.settings.default_currency || 'NGN';

export const symbolOf = ( code ) => {
	const c = currencies().find( ( x ) => x.code === code );
	return c ? c.symbol : code ? code + ' ' : '';
};

export const fmtMoney = ( amount, code ) => {
	const n = Number( amount ) || 0;
	const cents = Math.round( Math.abs( n ) * 100 ) % 100 !== 0; // whole amounts have no decimals; anything else always shows two
	return ( n < 0 ? '−' : '' ) + symbolOf( code ) + Math.abs( n ).toLocaleString( undefined, { minimumFractionDigits: cents ? 2 : 0, maximumFractionDigits: 2 } );
};

export const fmtDate = ( d ) => {
	if ( ! d ) {
		return '—';
	}
	const m = String( d ).match( /^(\d{4})-(\d{2})-(\d{2})/ );
	if ( ! m ) {
		return d;
	}
	return new Date( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ] ).toLocaleDateString( undefined, { month: 'short', day: 'numeric', year: 'numeric' } );
};

export const fmtDateTime = ( d ) => {
	if ( ! d ) {
		return '—';
	}
	const m = String( d ).match( /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/ );
	if ( ! m ) {
		return d;
	}
	return new Date( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ], +m[ 4 ], +m[ 5 ] ).toLocaleString( undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' } );
};

const pad = ( n ) => String( n ).padStart( 2, '0' );
export const isoDate = ( d ) => `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }`;
export const today = () => isoDate( new Date() );
export const addDays = ( days ) => {
	const d = new Date();
	d.setDate( d.getDate() + days );
	return isoDate( d );
};

export const TIMINGS = {
	now: 'Bill now',
	end_of_month: 'End of month',
	end_of_quarter: 'End of quarter',
	next_renewal: 'Next renewal',
	custom: 'Custom date',
};
export const timingOptions = () => Object.entries( TIMINGS ).map( ( [ value, label ] ) => ( { value, label } ) );

/** Element Plus tag type for a status string. */
export const STATUS_TYPES = {
	active: 'success',
	paid: 'success',
	renewed: 'success',
	pending: 'warning',
	due: 'warning',
	partially_paid: 'warning',
	paused: 'warning',
	upcoming: 'primary',
	sent: 'primary',
	invoiced: 'primary',
	overdue: 'danger',
	cancelled: 'info',
	inactive: 'info',
	draft: 'info',
	skipped: 'info',
	waived: 'info',
};

/**
 * Validates a mixed selection of renewals/charges for invoicing.
 * Pending charges that fall due with a selected renewal are rolled in (deduplicated by id).
 * Returns { ok, reason, client_id, client_name, currency, total, charge_ids, renewal_ids, count, hasRolledIn }.
 */
export function summariseSelection( { charges = [], renewals = [] } ) {
	const all = [ ...charges, ...renewals ];
	if ( ! all.length ) {
		return { ok: false, reason: 'Select at least one item.' };
	}
	if ( new Set( all.map( ( r ) => r.client_id ) ).size > 1 ) {
		return { ok: false, reason: 'Select items for a single client.' };
	}
	if ( new Set( all.map( ( r ) => r.currency ) ).size > 1 ) {
		return { ok: false, reason: 'Select items in a single currency.' };
	}

	const priceById = new Map();
	charges.forEach( ( c ) => priceById.set( c.id, Number( c.price ) ) );
	renewals.forEach( ( r ) =>
		( r.pending_charges || [] ).forEach( ( pc ) => {
			if ( ! priceById.has( pc.id ) ) {
				priceById.set( pc.id, Number( pc.price ) );
			}
		} )
	);
	const chargeTotal = [ ...priceById.values() ].reduce( ( s, v ) => s + v, 0 );
	const renewalTotal = renewals.reduce( ( s, r ) => s + Number( r.price ), 0 );

	return {
		ok: true,
		client_id: all[ 0 ].client_id,
		client_name: all[ 0 ].client_name,
		currency: all[ 0 ].currency,
		charge_ids: [ ...priceById.keys() ],
		renewal_ids: renewals.map( ( r ) => r.id ),
		count: priceById.size + renewals.length,
		total: chargeTotal + renewalTotal,
		hasRolledIn: priceById.size > charges.length,
	};
}

/** Initials + a stable hue for avatar chips. */
export const initials = ( name ) =>
	String( name || '?' )
		.replace( /\(.*?\)/g, '' )
		.trim()
		.split( /\s+/ )
		.slice( 0, 2 )
		.map( ( w ) => w[ 0 ] )
		.join( '' )
		.toUpperCase() || '?';

export const avatarStyle = ( name ) => {
	let h = 0;
	for ( const c of String( name || '' ) ) {
		h = ( h * 31 + c.charCodeAt( 0 ) ) >>> 0;
	}
	return { '--h': h % 360 };
};

export const relDays = ( days ) => {
	if ( days === null || days === undefined ) {
		return 'No date';
	}
	if ( days === 0 ) {
		return 'Today';
	}
	return days > 0 ? `In ${ days } day${ days === 1 ? '' : 's' }` : `${ -days } day${ days === -1 ? '' : 's' } overdue`;
};

/** Billing-pipeline states → label + badge tone. */
export const BILL_STATE = {
	overdue: { label: 'Late to invoice', tone: 'danger' },
	ready: { label: 'Ready to invoice', tone: 'warning' },
	scheduled: { label: 'Scheduled', tone: 'neutral' },
	unscheduled: { label: 'No date set', tone: 'neutral' },
	overdue_to_bill: { label: 'Late to invoice', tone: 'danger' },
	ready_to_bill: { label: 'Ready to invoice', tone: 'warning' },
	overdue_payment: { label: 'Overdue', tone: 'danger' },
	payment_due: { label: 'Payment due', tone: 'warning' },
	awaiting: { label: 'Awaiting payment', tone: 'info' },
};

export const round2 = ( n ) => Math.round( ( Number( n ) || 0 ) * 100 ) / 100;

export const PAYMENT_METHODS = [ 'Bank transfer', 'Card', 'Cash', 'Cheque', 'Mobile money', 'Other' ].map( ( m ) => ( { value: m, label: m } ) );

export const addDaysTo = ( iso, days ) => {
	const m = String( iso || '' ).match( /^(\d{4})-(\d{2})-(\d{2})$/ );
	const d = m ? new Date( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ] ) : new Date();
	d.setDate( d.getDate() + days );
	return isoDate( d );
};

/** Copy text to the clipboard (with a fallback for non-secure contexts). */
export async function copyText( text ) {
	try {
		await navigator.clipboard.writeText( text );
		return true;
	} catch ( e ) {
		const ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		let ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch ( err ) {
			ok = false;
		}
		document.body.removeChild( ta );
		return ok;
	}
}

/** Status of any bill (one-off or recurring cycle), derived by the server. */
export const BILL_STATUS = {
	not_invoiced: { label: 'To invoice', tone: 'warning' },
	invoice_draft: { label: 'Invoice draft', tone: 'neutral' },
	awaiting: { label: 'Awaiting payment', tone: 'info' },
	overdue: { label: 'Overdue', tone: 'danger' },
	paid: { label: 'Paid', tone: 'success' },
	waived: { label: 'Waived', tone: 'neutral' },
	closed: { label: 'Closed', tone: 'neutral' },
};

/** Work: plain-word statuses. */
export const TASK_STATUS = {
	todo: { label: 'To do', tone: 'neutral' },
	doing: { label: 'Doing', tone: 'info' },
	done: { label: 'Done', tone: 'success' },
};
export const PROJECT_STATUS = {
	planned: { label: 'Planned', tone: 'neutral' },
	active: { label: 'Active', tone: 'info' },
	on_hold: { label: 'On hold', tone: 'warning' },
	done: { label: 'Done', tone: 'success' },
};
export const CONTRACT_STATUS = {
	draft: { label: 'Draft', tone: 'neutral' },
	sent: { label: 'Awaiting signatures', tone: 'warning' },
	partially_signed: { label: 'Partly signed', tone: 'info' },
	signed: { label: 'Signed', tone: 'success' },
	declined: { label: 'Declined', tone: 'danger' },
	void: { label: 'Voided', tone: 'danger' },
};
export const CONTRACT_STATUS_OPTIONS = Object.entries( CONTRACT_STATUS ).map( ( [ value, d ] ) => ( { value, label: d.label } ) );
export const SIGNER_STATUS = {
	pending: { label: 'Waiting', tone: 'warning' },
	viewed: { label: 'Opened', tone: 'info' },
	signed: { label: 'Signed', tone: 'success' },
	declined: { label: 'Declined', tone: 'danger' },
};

export const TASK_STATUS_OPTIONS = Object.entries( TASK_STATUS ).map( ( [ value, d ] ) => ( { value, label: d.label } ) );
export const PROJECT_STATUS_OPTIONS = Object.entries( PROJECT_STATUS ).map( ( [ value, d ] ) => ( { value, label: d.label } ) );

/** What this business calls its departments (default "Department"; some prefer "Brand"). */
export const dept = () => {
	const st = ( store.meta && store.meta.settings ) || {};
	const one = st.department_label || 'Department';
	return { one, many: st.department_label_plural || one + 's', lower: one.toLowerCase(), lowerMany: ( st.department_label_plural || one + 's' ).toLowerCase() };
};
