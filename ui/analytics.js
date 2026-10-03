import { today } from './util';

/** Period presets used by the analytics bar. `to` is always today (or the end of a past month). */
export const PERIODS = [
	{ id: '30d', label: '30 days' },
	{ id: '90d', label: '90 days' },
	{ id: 'ytd', label: 'This year' },
	{ id: '12m', label: '12 months' },
	{ id: 'custom', label: 'Custom' },
];

const iso = ( d ) => d.toISOString().slice( 0, 10 );
const shift = ( dateStr, days ) => {
	const d = new Date( dateStr + 'T00:00:00Z' );
	d.setUTCDate( d.getUTCDate() + days );
	return iso( d );
};

/** { from, to } (inclusive, YYYY-MM-DD) for a preset id. */
export const rangeFor = ( id, custom = [] ) => {
	const to = today();
	if ( id === '30d' ) return { from: shift( to, -29 ), to };
	if ( id === '90d' ) return { from: shift( to, -89 ), to };
	if ( id === 'ytd' ) return { from: to.slice( 0, 4 ) + '-01-01', to };
	if ( id === '12m' ) {
		const d = new Date( to + 'T00:00:00Z' );
		d.setUTCMonth( d.getUTCMonth() - 11, 1 );
		return { from: iso( d ), to };
	}
	return { from: custom[ 0 ] || shift( to, -29 ), to: custom[ 1 ] || to };
};

/** Bucket size for a span so a chart has a sensible number of points. */
export const granularity = ( from, to ) => {
	const days = Math.round( ( new Date( to ) - new Date( from ) ) / 86400000 ) + 1;
	return days <= 45 ? 'day' : days <= 200 ? 'week' : 'month';
};

/** Download rows as a CSV file (headers from the first row's keys). */
export const toCsv = ( rows ) => {
	if ( ! rows.length ) return '';
	const keys = Object.keys( rows[ 0 ] );
	const esc = ( v ) => {
		const t = v === null || v === undefined ? '' : String( v );
		return /[",\n]/.test( t ) ? '"' + t.replace( /"/g, '""' ) + '"' : t;
	};
	return [ keys.join( ',' ), ...rows.map( ( r ) => keys.map( ( k ) => esc( r[ k ] ) ).join( ',' ) ) ].join( '\n' );
};
export const downloadCsv = ( name, rows ) => {
	const blob = new Blob( [ toCsv( rows ) ], { type: 'text/csv;charset=utf-8' } );
	const a = document.createElement( 'a' );
	a.href = URL.createObjectURL( blob );
	a.download = name;
	a.click();
	URL.revokeObjectURL( a.href );
};
