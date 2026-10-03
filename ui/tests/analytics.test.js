import { describe, it, expect } from 'vitest';
import { rangeFor, granularity, toCsv } from '../analytics';

describe( 'analytics helpers', () => {
	it( 'preset ranges end today and start the right distance back', () => {
		const r = rangeFor( '30d' );
		expect( Math.round( ( new Date( r.to ) - new Date( r.from ) ) / 86400000 ) ).toBe( 29 );
		expect( rangeFor( 'ytd' ).from.endsWith( '-01-01' ) ).toBe( true );
		expect( rangeFor( '12m' ).from.endsWith( '-01' ) ).toBe( true );
		expect( rangeFor( 'custom', [ '2026-01-05', '2026-02-01' ] ) ).toEqual( { from: '2026-01-05', to: '2026-02-01' } );
	} );

	it( 'chart buckets scale with the span', () => {
		expect( granularity( '2026-10-01', '2026-10-30' ) ).toBe( 'day' );
		expect( granularity( '2026-07-01', '2026-10-30' ) ).toBe( 'week' );
		expect( granularity( '2025-10-01', '2026-10-30' ) ).toBe( 'month' );
	} );

	it( 'csv quotes commas, quotes and newlines', () => {
		expect( toCsv( [ { a: 'x,y', b: 'say "hi"', c: 3 } ] ) ).toBe( 'a,b,c\n"x,y","say ""hi""",3' );
		expect( toCsv( [] ) ).toBe( '' );
	} );
} );
