import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { meta, routes } from './fixtures';

// Chart.js needs a real canvas; the chart's data wiring is covered by the dashboard test.
vi.mock( '../components/Chart.vue', () => ( { default: { props: [ 'type', 'labels', 'series', 'currency' ], template: '<div class="chart-stub">{{ type }}:{{ labels.length }}:{{ currency }}</div>' } } ) );
vi.mock( '../components/TrendChart.vue', () => ( { default: { props: [ 'labels', 'billed', 'paid', 'currency' ], template: '<div class="chart-stub">{{ labels.length }} months · {{ currency }}</div>' } } ) );

const calls = [];
globalThis.fetch = vi.fn( async ( url, init = {} ) => {
	const u = new URL( url, 'http://localhost' );
	const path = ( u.searchParams.get( 'rest_route' ) || u.pathname ).replace( '/bizydash/v1', '' ).replace( '/wp-json', '' );
	calls.push( { method: init.method || 'GET', path, query: Object.fromEntries( u.searchParams ), body: typeof init.body === 'string' ? JSON.parse( init.body ) : null } );
	const factory = ( init.method === 'POST' && routes[ 'POST ' + path ] ) || routes[ path ];
	if ( ! factory ) {
		return { ok: false, status: 404, json: async () => ( { message: 'no route ' + path } ) };
	}
	return { ok: true, status: 200, json: async () => factory() };
} );

const NAV = {
	core: [ { label: 'Home', route: '/', icon: 'home' }, { label: 'Clients', route: '/clients', icon: 'clients' }, { label: 'Team', route: '/team', icon: 'team' }, { label: 'Departments', route: '/departments', icon: 'departments' } ],
	modules: [
		{ id: 'work', label: 'Work', icon: 'work', route: '/work', items: [ { label: 'Overview', route: '/work', url: '' }, { label: 'My tasks', route: '/work/mine', url: '' }, { label: 'All tasks', route: '/work/all', url: '' }, { label: 'Projects', route: '/work/projects', url: '' }, { label: 'Analytics', route: '/work/analytics', url: '' } ] },
		{ id: 'finance', label: 'Finance', icon: 'finance', route: '/finance', items: [ { label: 'Overview', route: '/finance/overview', url: '' }, { label: 'Analytics', route: '/finance/analytics', url: '' }, { label: 'Billing', route: '/finance/billing', url: '' }, { label: 'Invoices', route: '/finance/billing/invoices', url: '' }, { label: 'Expenses', route: '/finance/expenses', url: '' } ] },
		{ id: 'contracts', label: 'Contracts', icon: 'contracts', route: '/contracts', items: [ { label: 'Overview', route: '/contracts', url: '' }, { label: 'All contracts', route: '/contracts/all', url: '' }, { label: 'Templates', route: '/contracts/templates', url: '' }, { label: 'Analytics', route: '/contracts/analytics', url: '' }, { label: 'Verify a certificate', route: '', url: 'http://localhost/verify' } ] },
	],
	footer: [ { label: 'Activity', route: '/activity', icon: 'activity' }, { label: 'Settings', route: '/settings', icon: 'settings' } ],
};
const MODULES = [ { id: 'core', label: 'Core', desc: '', core: true, enabled: true, core_analytics: [ { id: 'clients', label: 'Clients', on: true }, { id: 'team', label: 'Team', on: true } ] }, { id: 'work', label: 'Work', desc: 'w', core: false, enabled: true, has_analytics: true, analytics: true }, { id: 'finance', label: 'Finance', desc: 'f', core: false, enabled: true }, { id: 'contracts', label: 'Contracts', desc: 'c', core: false, enabled: true } ];

window.bizydashBoot = { restUrl: 'http://localhost/wp-json/bizydash/v1', nonce: 'n', meta, nav: NAV, modules: MODULES, coreAnalytics: { clients: true, team: true }, frontend: false, fullscreen: true, adminUrl: 'http://localhost/wp-admin/', appUrl: 'http://localhost/app/', logoutUrl: 'http://localhost/wp-login.php?action=logout', canManage: true, user: 'Admin', userEmail: 'me@dz.example', canFinance: true, theme: 'light' };

const { components } = await import( '../components-registry' );
const { default: App } = await import( '../App.vue' );
const { store } = await import( '../store' );

let warnings;
let mounted = [];
afterEach( () => {
	mounted.forEach( ( w ) => w.unmount() );
	mounted = [];
	document.body.innerHTML = '';
} );
beforeEach( () => {
	calls.length = 0;
	warnings = [];
	vi.spyOn( console, 'warn' ).mockImplementation( ( ...a ) => warnings.push( 'warn: ' + a.join( ' ' ) ) );
	vi.spyOn( console, 'error' ).mockImplementation( ( ...a ) => warnings.push( 'error: ' + a.join( ' ' ) ) );
	document.body.innerHTML = '<div id="bizydash-root"></div>';
} );

async function visit( hash ) {
	window.location.hash = hash;
	store.route = ( () => {
		const raw = hash.slice( 1 ) || '/';
		const [ path, qs = '' ] = raw.split( '?' );
		return { path, parts: path.split( '/' ).filter( Boolean ), query: Object.fromEntries( new URLSearchParams( qs ) ) };
	} )();
	const w = mount( App, { attachTo: document.getElementById( 'bizydash-root' ), global: { plugins: components } } );
	mounted.push( w );
	for ( let i = 0; i < 4; i++ ) {
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 20 ) );
	}
	return w;
}
const text = () => document.body.textContent.replace( /\s+/g, ' ' );

describe( 'BizyDash screens render against API-shaped data', () => {
	it( 'header navigation + dashboard widgets', async () => {
		await visit( '#/' );
		const t = text();
		for ( const label of [ 'Home', 'Clients', 'Work', 'Team', 'Finance', 'Activity', 'Settings' ] ) {
			expect( t ).toContain( label );
		}
		for ( const label of [ 'Waiting to be paid', 'Ready to invoice', 'Collected this month', 'Profit this month' ] ) {
			expect( t ).toContain( label );
		}
		expect( t ).toContain( 'You have 3 things to do today.' ); // plain-English summary
		expect( t ).toContain( '₦255,000 is waiting to be paid' );
		expect( t ).toContain( '1 one-off · 1 recurring' );
		expect( t ).toContain( 'To do today' );
		expect( t ).toContain( 'INV-0003 — Monthly retainer' ); // invoices show their title
		expect( t ).toContain( 'Domain registration — acme-demo.com' ); // bills name what they are for
		expect( t ).toContain( 'Send reminder' );
		expect( t ).toContain( 'Invoice it' );
		expect( t ).toContain( 'Send brand brief to client' );
		expect( t ).toContain( 'Coming up' );
		expect( t ).toContain( 'This week' );
		expect( t ).toContain( 'Get set up — 4 of 5 done' );
		expect( t ).toContain( 'Profit this month' );
		expect( t ).toContain( '₦622,000' );
		expect( t ).not.toContain( 'Owed to the team' ); // lives in Finance → Payroll, not Home
		expect( t ).toContain( '6 months · NGN' ); // chart receives per-currency series
		expect( warnings ).toEqual( [] );
	} );

	it.each( [
		[ '#/clients', [ 'ABC Company (Demo)', 'Retainer', 'Ada Obi', 'Add client' ] ],
		[ '#/finance/billing', [ 'Finance', 'Billing', 'Retainers', 'Subscriptions', 'One-off', 'Digital Zooks Agency (Demo)', 'Zooks Cloud (Demo)', 'Web hosting — acme-demo.com', 'Website management — Site A', '3 additional email users', 'To invoice', 'New' ] ],
		[ '#/renewals', [ 'Billing', 'Web hosting — acme-demo.com' ] ],
		[ '#/charges', [ 'Billing', '3 additional email users' ] ],
		[ '#/invoices', [ 'INV-0001', 'Website redesign and hosting', '₦120,000', 'Partially paid', 'Payments', 'New invoice', 'Viewed' ] ],
		[ '#/work/mine', [ 'My tasks', 'Send brand brief to client', 'Renew abc-store.com', 'High', 'Show done' ] ],
		[ '#/work/projects', [ 'Website redesign', '1 of 4 tasks done', 'New project', 'Zara Team' ] ],
		[ '#/work/projects/3', [ 'Website redesign', '1 of 4 tasks done (25%)', 'Edit project', 'Mark project done', 'Send brand brief to client', 'Phases & tasks', 'Design', 'Build', '₦400,000 of ₦600,000 shared', 'Edit phase', 'Project price', 'Owed to team', 'Profit on project', '₦1,600,000' ] ],
		[ '#/team', [ 'Team', 'Zara Team', 'Account manager', 'Employee', '3 open tasks', '1 overdue', '₦350,000', '₦120,000', '₦900,000', 'Add team member' ] ],
		[ '#/team/1', [ 'Zara Team', 'Owed now', 'Paid this year', 'Open tasks', 'Active projects', 'Doing now', 'Details', 'This is you', 'Send brand brief to client' ] ],
		[ '#/finance/expenses', [ 'Spent this month', 'Recurring per month', 'Biggest this month', 'Figma team plan', 'Software & tools', '₦18,000', 'October office rent', 'Recurring', 'Add expense' ] ],
		[ '#/finance/overview', [ 'Income', 'Direct costs', 'What we keep', 'Team pay', 'Running expenses', 'Profit', '₦622,000', '41% of income', 'Last 6 months', 'Owed by clients', 'Owed to the team', 'Short by', 'By department', 'General / no department', 'Best clients', 'Projects', 'Website redesign', 'Download for your accountant', 'Income lines' ] ],
		[ '#/finance/payroll', [ 'Salaries — October 2026', 'Owed to the team', 'Paid this month', 'Who is owed', 'Zara Team', 'Kunle Adeyemi (Demo)', 'Not created yet', 'Run payroll for October 2026 (2)', 'Payouts', 'PAY-0001', 'Payslip' ] ],
		[ '#/finance/catalog', [ 'Catalog', 'Web hosting', 'Product photography', 'Add to catalog', 'Per image', 'Fixed price', 'Services', 'Products', '₦6,500 / image', 'Separately billed', 'Recurring' ] ],
		[ '#/brands', [ 'Digital Zooks Agency (Demo)', 'Add department', 'Departments' ] ],
		[ '#/activity', [ 'Invoice INV-0001 created from 2 item(s)', 'Admin' ] ],
		[ '#/settings', [ 'Default currency', 'Currencies', 'Lists & options', 'Business & invoices', 'Extra payment note', 'Default tax rate', 'Payment methods', 'Add bank account', 'Add payment link', 'GTBank — Naira', 'Pay by card', 'Email', 'Site mailer (wp_mail)', 'BizyDash SMTP', 'Send test email', 'Invoice email wording', 'Team', 'Departments', 'What do you call a department?' ] ],
	] )( '%s', async ( hash, expected ) => {
		await visit( hash );
		const t = text();
		for ( const s of expected ) {
			expect( t ).toContain( s );
		}
		expect( warnings ).toEqual( [] );
	} );

	it( 'client detail: plain stat cards, six tabs, overview and billing sub-views', async () => {
		await visit( '#/clients/1' );
		const t = text();
		expect( t ).toContain( 'ABC Company (Demo)' );
		for ( const label of [ 'Waiting to be paid', 'Ready to invoice', 'Monthly income', 'Open tasks' ] ) {
			expect( t ).toContain( label );
		}
		for ( const tab of [ 'Overview', 'Billing', 'Work', 'Contracts', 'Contacts', 'History' ] ) {
			expect( [ ...document.querySelectorAll( '.el-tabs__item' ) ].some( ( n ) => n.textContent.includes( tab ) ) ).toBe( true );
		}
		expect( [ ...document.querySelectorAll( '.el-tabs__item' ) ] ).toHaveLength( 6 );
		expect( t ).toContain( 'Next bills' );
		expect( t ).toContain( '3 additional email users' ); // from the client's unbilled bills
		expect( t ).toContain( 'Send brand brief to client' ); // open task on the overview
		expect( t ).toContain( 'Gets invoices' );
		// Billing tab: Recurring / One-off / Invoices switch
		[ ...document.querySelectorAll( '.el-tabs__item' ) ].find( ( n ) => n.textContent.includes( 'Billing' ) ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 80 ) );
		expect( text() ).toContain( 'Web hosting — acme-demo.com' ); // the one Billing list
		expect( calls.some( ( c ) => c.path === '/billing/list' && c.query.client_id === '1' ) ).toBe( true );
		expect( [ ...document.querySelectorAll( '.el-radio-button' ) ].map( ( n ) => n.textContent.trim() ).join( '|' ) ).toContain( 'Invoices' );
		expect( warnings ).toEqual( [] );
	} );

	it( 'settings: Team and Departments tabs load their lists on demand', async () => {
		await visit( '#/settings' );
		const tabBtn = ( label ) => [ ...document.querySelectorAll( '.el-tabs__item' ) ].find( ( t ) => t.textContent.trim() === label );
		tabBtn( 'Team' ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		expect( text() ).toContain( 'Open Team' );
		tabBtn( 'Departments' ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		expect( text() ).toContain( 'Digital Zooks Agency (Demo)' );
		expect( warnings ).toEqual( [] );
	} );

	it( 'new department drawer saves via POST /brands', async () => {
		const w = await visit( '#/brands' );
		const add = [ ...document.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.includes( 'Add department' ) );
		add.click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 50 ) );
		expect( document.body.textContent ).toContain( 'New department' );
		const save = [ ...document.querySelectorAll( '.el-drawer button' ) ].find( ( b ) => b.textContent.trim() === 'Save' );
		expect( save ).toBeTruthy();
		routes[ '/brands' ] = ( ( orig ) => () => orig() )( routes[ '/brands' ] );
		save.click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 50 ) );
		const post = calls.find( ( c ) => c.method === 'POST' && c.path === '/brands' );
		expect( post ).toBeTruthy();
		expect( post.body.status ).toBe( 'active' );
	} );

	it( 'invoice editor (existing): totals, schedule status, share + preview actions', async () => {
		await visit( '#/billing/invoices/7' );
		const t = text();
		for ( const needle of [ 'INV-0001', 'Recurring', 'Balance due', 'Instalment status', 'Deposit', '₦20,000 left', 'Copy link', 'Preview', 'Record payment', 'Save & send', 'Viewed by client 2×', 'Share' ] ) {
			expect( t ).toContain( needle );
		}
		const values = [ ...document.querySelectorAll( 'input' ) ].map( ( i ) => i.value );
		expect( values ).toContain( 'Website design' );
		expect( values ).toContain( 'Hosting renewal' );
		expect( warnings ).toEqual( [] );
	} );

	it( 'new invoice: add from bills, 30/40/30 split, and the saved payload', async () => {
		await visit( '#/billing/invoices/new?client=1' );
		expect( text() ).toContain( 'Create invoice' );
		const click = async ( pred, root = document ) => {
			const el = [ ...root.querySelectorAll( 'button, label' ) ].find( pred );
			expect( el, 'element to click' ).toBeTruthy();
			el.click();
			await flushPromises();
			await new Promise( ( r ) => setTimeout( r, 60 ) );
		};
		await click( ( b ) => b.textContent.trim() === 'From bills' );
		const box = document.querySelector( '.el-dialog .el-table__body .el-checkbox' );
		expect( box ).toBeTruthy();
		box.click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 40 ) );
		await click( ( b ) => b.textContent.includes( 'to invoice' ) );
		expect( text() ).toContain( '₦100,000' );
		await click( ( l ) => l.textContent.trim() === 'Instalments' );
		await click( ( b ) => b.textContent.trim() === '30 / 40 / 30' );
		const amounts = [ ...document.querySelectorAll( '.bd-inst__row .el-input-number input' ) ].map( ( i ) => i.value );
		expect( amounts ).toEqual( [ '30.00', '30000.00', '40.00', '40000.00', '30.00', '30000.00' ] );
		expect( text() ).toContain( 'Instalments add up to the invoice total' );
		await click( ( b ) => b.textContent.trim() === 'Save' );
		const post = calls.find( ( c ) => c.method === 'POST' && c.path === '/invoices' );
		expect( post ).toBeTruthy();
		expect( post.body.client_id ).toBe( 1 );
		expect( post.body ).toHaveProperty( 'title' ); // blank here; the server fills in the default
		expect( post.body.items ).toHaveLength( 1 );
		expect( post.body.items[ 0 ] ).toMatchObject( { source_type: 'charge', source_id: 6, unit_price: 100000 } );
		expect( post.body.installments.map( ( r ) => r.amount ) ).toEqual( [ 30000, 40000, 30000 ] );
		expect( post.body.installments[ 0 ].label ).toBe( 'Deposit' );
	} );

	it( 'new invoice: unbalanced instalments are blocked before saving', async () => {
		await visit( '#/billing/invoices/new?client=1' );
		const click = async ( pred ) => {
			[ ...document.querySelectorAll( 'button, label' ) ].find( pred ).click();
			await flushPromises();
			await new Promise( ( r ) => setTimeout( r, 60 ) );
		};
		await click( ( b ) => b.textContent.trim() === 'From bills' );
		document.querySelector( '.el-dialog .el-table__body .el-checkbox' ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 40 ) );
		await click( ( b ) => b.textContent.includes( 'to invoice' ) );
		await click( ( l ) => l.textContent.trim() === 'Instalments' );
		await click( ( b ) => b.textContent.trim() === 'Custom amounts' );
		// custom split starts balanced; remove one row → can't be a schedule of one
		[ ...document.querySelectorAll( '.bd-inst__row button' ) ][ 0 ].click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 40 ) );
		expect( text() ).toContain( 'Add at least two instalments' );
		await click( ( b ) => b.textContent.trim() === 'Save' );
		expect( calls.find( ( c ) => c.method === 'POST' && c.path === '/invoices' ) ).toBeUndefined();
	} );

	it( 'work: quick-add creates a task and Done updates its status', async () => {
		await visit( '#/work/all' );
		const input = document.querySelector( '.bd-quickadd input' );
		expect( input ).toBeTruthy();
		input.value = 'Call the printer';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		await flushPromises();
		input.dispatchEvent( new KeyboardEvent( 'keyup', { key: 'Enter', bubbles: true } ) );
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		const post = calls.find( ( c ) => c.method === 'POST' && c.path === '/tasks' );
		expect( post ).toBeTruthy();
		expect( post.body.title ).toBe( 'Call the printer' );
		const done = [ ...document.querySelectorAll( '.el-table__body button' ) ].find( ( b ) => b.textContent.trim() === 'Done' );
		expect( done ).toBeTruthy();
		done.click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		const put = calls.find( ( c ) => c.method === 'PUT' && c.path === '/tasks/5' );
		expect( put ).toBeTruthy();
		expect( put.body ).toEqual( { status: 'done' } );
		expect( warnings ).toEqual( [] );
	} );

	it( 'work: board view groups tasks into To do / Doing / Done', async () => {
		await visit( '#/work/all' );
		[ ...document.querySelectorAll( '.el-radio-button' ) ].find( ( b ) => b.textContent.trim() === 'Board' ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 80 ) );
		const cols = [ ...document.querySelectorAll( '.bd-board__col' ) ].map( ( c ) => c.textContent );
		expect( cols ).toHaveLength( 3 );
		expect( cols[ 0 ] ).toContain( 'Send brand brief to client' );
		expect( cols[ 1 ] ).toContain( 'Renew abc-store.com' );
		expect( warnings ).toEqual( [] );
	} );

	it( 'expenses: quick-add posts an expense; Recurring view lists templates', async () => {
		await visit( '#/finance/expenses' );
		const inputs = document.querySelectorAll( '.bd-quickadd--expense input' );
		const desc = [ ...inputs ].find( ( i ) => i.placeholder.includes( 'What was it for' ) );
		const amt = [ ...inputs ].find( ( i ) => i.placeholder === 'Amount' );
		desc.value = 'Domain for the agency';
		desc.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		amt.value = '15000';
		amt.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		amt.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		await flushPromises();
		[ ...document.querySelectorAll( '.bd-quickadd--expense button' ) ].find( ( b ) => b.textContent.trim() === 'Add' ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		const post = calls.find( ( c ) => c.method === 'POST' && c.path === '/expenses' );
		expect( post ).toBeTruthy();
		expect( post.body.description ).toBe( 'Domain for the agency' );
		expect( Number( post.body.amount ) ).toBe( 15000 );
		[ ...document.querySelectorAll( '.el-radio-button' ) ].find( ( b ) => b.textContent.trim() === 'Recurring' ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 80 ) );
		expect( text() ).toContain( 'Office rent' );
		expect( text() ).toContain( '₦250,000 / month' );
		expect( text() ).toContain( 'Post due now' );
		expect( warnings ).toEqual( [] );
	} );

	it( 'project phases: add a phase posts to /project-phases', async () => {
		await visit( '#/work/projects/3' );
		const input = document.querySelector( '.bd-phases input[aria-label="New phase name"]' ) || [ ...document.querySelectorAll( '.bd-phases input' ) ].find( ( i ) => i.placeholder.includes( 'New phase' ) );
		expect( input ).toBeTruthy();
		input.value = 'Launch';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		await flushPromises();
		[ ...document.querySelectorAll( '.bd-phases button' ) ].find( ( b ) => b.textContent.includes( 'Add phase' ) ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		const post = calls.find( ( c ) => c.method === 'POST' && c.path === '/project-phases' );
		expect( post ).toBeTruthy();
		expect( post.body ).toMatchObject( { project_id: 3, name: 'Launch' } );
		[ ...document.querySelectorAll( '.bd-phases button' ) ].find( ( b ) => b.textContent.includes( 'Edit phase' ) ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		expect( document.body.textContent ).toContain( 'Team pay' );
		expect( document.body.textContent ).toContain( 'Amara Eze (Demo)' );
		expect( document.body.textContent ).toContain( '₦300,000' );
		expect( document.body.textContent ).toContain( 'Team budget' );
		expect( warnings ).toEqual( [] );
	} );

	it( 'project phases: a task typed under a phase is created in that phase', async () => {
		await visit( '#/work/projects/3' );
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		expect( text() ).toContain( 'Send brand brief to client' ); // already listed under Design
		const input = document.querySelector( '.bd-phases input[aria-label="Add a task to Design"]' );
		expect( input ).toBeTruthy();
		input.value = 'Pick colours';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		input.dispatchEvent( new KeyboardEvent( 'keyup', { key: 'Enter', bubbles: true } ) );
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		const post = calls.find( ( c ) => c.method === 'POST' && c.path === '/tasks' );
		expect( post.body ).toMatchObject( { title: 'Pick colours', project_id: 3, phase_id: 1 } );
	} );

	it( 'team pay: owed earnings, pay selected records a payout', async () => {
		await visit( '#/team/1' );
		[ ...document.querySelectorAll( '.el-tabs__item' ) ].find( ( n ) => n.textContent.trim().startsWith( 'Pay' ) ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 100 ) );
		expect( text() ).toContain( 'Design — Website redesign' );
		expect( text() ).toContain( 'Launch bonus' );
		expect( text() ).toContain( 'Pay selected' );
		const box = document.querySelector( '.bd-teampay .el-table__body .el-checkbox' );
		expect( box ).toBeTruthy();
		box.click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 40 ) );
		expect( text() ).toContain( 'Pay selected (₦150,000' );
		[ ...document.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.includes( 'Pay selected (' ) ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		expect( document.body.textContent ).toContain( 'Record a payout' );
		[ ...document.querySelectorAll( '.el-dialog button' ) ].find( ( b ) => b.textContent.trim() === 'Record payout' ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		const post = calls.find( ( c ) => c.method === 'POST' && c.path === '/payouts/create' );
		expect( post ).toBeTruthy();
		expect( post.body.team_member_id ).toBe( 1 );
		expect( post.body.item_ids ).toEqual( [ 11 ] );
		expect( warnings ).toEqual( [] );
	} );

	it( 'payroll: run payroll posts the period; paying one person records a payout', async () => {
		await visit( '#/finance/payroll' );
		[ ...document.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.includes( 'Run payroll for' ) ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		const run = calls.find( ( c ) => c.method === 'POST' && c.path === '/payroll/run' );
		expect( run ).toBeTruthy();
		expect( run.body.period ).toMatch( /^\d{4}-\d{2}$/ );
		const pay = [ ...document.querySelectorAll( '.el-table__body button' ) ].find( ( b ) => b.textContent.trim() === 'Pay' );
		pay.click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		expect( document.body.textContent ).toContain( 'Record a payout' );
		[ ...document.querySelectorAll( '.el-dialog button' ) ].find( ( b ) => b.textContent.trim() === 'Record payout' ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		const post = calls.find( ( c ) => c.method === 'POST' && c.path === '/payouts/create' );
		expect( post ).toBeTruthy();
		expect( post.body.team_member_id ).toBe( 1 );
		expect( post.body.item_ids ).toEqual( [ 11 ] );
		expect( warnings ).toEqual( [] );
	} );

	it( 'catalog: Products tab filters by type and offers the product form', async () => {
		await visit( '#/catalog' );
		[ ...document.querySelectorAll( '.el-radio-button' ) ].find( ( b ) => b.textContent.trim() === 'Products' ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		expect( calls.some( ( c ) => c.path === '/services' && c.query.type === 'product' ) ).toBe( true );
		expect( text() ).toContain( 'Add product' );
		expect( warnings ).toEqual( [] );
	} );

	it( 'invoice editor splits fixed-price services from quantity items', async () => {
		await visit( '#/billing/invoices/new?client=1' );
		expect( text() ).toContain( 'Invoice title' );
		expect( document.querySelector( 'input[placeholder*="What is this invoice for"]' ) ).toBeTruthy();
		expect( text() ).toContain( 'Fixed-price lines' );
		expect( text() ).toContain( 'Quantity lines' );
		expect( document.querySelectorAll( '.bd-lines--fixed .bd-lines__head' ).length ).toBe( 1 );
		expect( document.querySelector( '.bd-lines--fixed' ).textContent ).not.toContain( 'Qty' );
		[ ...document.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === 'Add quantity line' ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 30 ) );
		const heads = [ ...document.querySelectorAll( '.bd-lines__head' ) ].map( ( h ) => h.textContent );
		expect( heads.some( ( h ) => h.includes( 'Qty' ) && h.includes( 'Unit price' ) ) ).toBe( true );
		expect( warnings ).toEqual( [] );
	} );

	it( 'billing: ticking a retainer, a subscription and a one-off creates ONE invoice request', async () => {
		await visit( '#/finance/billing' );
		const boxes = () => [ ...document.querySelectorAll( '.el-table__body .el-checkbox' ) ];
		expect( boxes() ).toHaveLength( 4 );
		[ 0, 1, 2 ].forEach( ( i ) => boxes()[ i ].click() );
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 30 ) );
		expect( text() ).toContain( '3 selected' );
		expect( text() ).toContain( '₦345,000' );
		[ ...document.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === 'Create invoice' ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		[ ...document.querySelectorAll( '.el-dialog button' ) ].find( ( b ) => b.textContent.includes( 'Create draft' ) ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		const post = calls.find( ( c ) => c.path === '/invoices/from-sources' );
		expect( post.body.client_id ).toBe( 1 );
		expect( post.body.charge_ids ).toEqual( [ 6 ] );
		expect( post.body.renewal_ids.sort() ).toEqual( [ 5, 8 ] );
		expect( Object.keys( post.body ).sort() ).toEqual( [ 'charge_ids', 'client_id', 'due_date', 'issue_date', 'notes', 'renewal_ids', 'status', 'title' ] );
	} );

	it( 'departments can be attached to invoices, bills, contracts and tasks from their forms', async () => {
		await visit( '#/billing/invoices/new?client=1' );
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		expect( text() ).toContain( 'Department' );
		expect( text() ).toContain( 'By line item' );
		await visit( '#/contracts/new' );
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		expect( text() ).toContain( 'Department' );
		expect( text() ).toContain( 'Follows the template or client' );
	} );

	it( 'departments overview: a card per department with its numbers, linking to the detail page', async () => {
		await visit( '#/departments' );
		await new Promise( ( r ) => setTimeout( r, 80 ) );
		const t = text();
		for ( const l of [ 'Digital Zooks Agency (Demo)', 'No department', '3 clients', '4 open tasks', '2 to sign', 'Income', '₦6,212,300', 'Owed by clients' ] ) {
			expect( t ).toContain( l );
		}
		expect( warnings ).toEqual( [] );
	} );

	it( 'department page: people, clients, work, contracts, billing and money for one department', async () => {
		await visit( '#/departments/2' );
		await new Promise( ( r ) => setTimeout( r, 80 ) );
		const t = text();
		for ( const l of [ 'Digital Zooks Agency (Demo)', 'Clients', 'People', 'Zara Team', 'Website redesign', 'Service agreement', 'Ready to invoice', '3 additional email users', 'Recent invoices', 'INV-0001', 'Cash and team pay', 'Received from clients', 'Spending' ] ) {
			expect( t ).toContain( l );
		}
		expect( calls.some( ( c ) => c.path === '/departments/2/overview' && c.query.from ) ).toBe( true );
		expect( warnings ).toEqual( [] );
	} );

	it( 'bulk: ticking invoices offers Set department and posts the selection', async () => {
		await visit( '#/finance/billing/invoices' );
		await new Promise( ( r ) => setTimeout( r, 80 ) );
		const boxes = [ ...document.querySelectorAll( '.el-table__body .el-checkbox' ) ];
		expect( boxes.length ).toBeGreaterThan( 0 );
		boxes[ 0 ].click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 40 ) );
		const btn = [ ...document.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.includes( 'Set department (1)' ) );
		expect( btn ).toBeTruthy();
		btn.click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		expect( document.body.textContent ).toContain( 'Take out of any' );
		[ ...document.querySelectorAll( '.el-radio-button' ) ].find( ( b ) => b.textContent.includes( 'Take out of any' ) ).querySelector( 'input' ).click();
		await flushPromises();
		[ ...document.querySelectorAll( '.el-dialog button' ) ].find( ( b ) => b.textContent.trim() === 'Apply' ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		const post = calls.find( ( c ) => c.path === '/bulk/department' );
		expect( post.body.department_id ).toBe( 0 );
		expect( post.body.items[ 0 ].resource ).toBe( 'invoices' );
	} );

	it( 'bulk clients: they can belong to several departments, so add / remove / only this one / remove from all', async () => {
		await visit( '#/clients' );
		await new Promise( ( r ) => setTimeout( r, 80 ) );
		const boxes = [ ...document.querySelectorAll( '.el-table__body .el-checkbox' ) ];
		expect( boxes.length ).toBeGreaterThan( 0 );
		boxes[ 0 ].click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 40 ) );
		[ ...document.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.includes( 'Set department (1)' ) ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		const labels = [ ...document.querySelectorAll( '.el-dialog .el-radio-button' ) ].map( ( b ) => b.textContent.trim() );
		expect( labels ).toEqual( [ 'Add to a department', 'Remove from a department', 'Only this one', 'Remove from all' ] );
		[ ...document.querySelectorAll( '.el-dialog .el-radio-button' ) ].find( ( b ) => b.textContent.includes( 'Remove from all' ) ).querySelector( 'input' ).click();
		await flushPromises();
		[ ...document.querySelectorAll( '.el-dialog button' ) ].find( ( b ) => b.textContent.trim() === 'Apply' ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		const post = calls.find( ( c ) => c.path === '/bulk/department' );
		expect( post.body.mode ).toBe( 'clear' );
		expect( post.body.items[ 0 ].resource ).toBe( 'clients' );
	} );

	it( 'department links carry their filter to the list pages', async () => {
		await visit( '#/work/all?department_id=2' );
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		expect( calls.some( ( c ) => c.path === '/tasks' && c.query.department_id === '2' ) ).toBe( true );
		calls.length = 0;
		await visit( '#/team?department_id=2' );
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		expect( calls.some( ( c ) => c.path === '/team' && c.query.department_id === '2' ) ).toBe( true );
		calls.length = 0;
		await visit( '#/finance/billing/invoices?department_id=2' );
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		expect( calls.some( ( c ) => c.path === '/invoices' && c.query.department_id === '2' ) ).toBe( true );
		calls.length = 0;
		await visit( '#/finance/billing?department_id=2' );
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		expect( calls.some( ( c ) => c.path === '/billing/list' && c.query.department_id === '2' ) ).toBe( true );
		calls.length = 0;
		await visit( '#/contracts/all?department_id=2' );
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		expect( calls.some( ( c ) => c.path === '/contracts' && c.query.department_id === '2' ) ).toBe( true );
		calls.length = 0;
		await visit( '#/work/projects?department_id=2' );
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		expect( calls.some( ( c ) => c.path === '/projects' && c.query.department_id === '2' ) ).toBe( true );
		calls.length = 0;
		await visit( '#/clients?brand_id=2' );
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		expect( calls.some( ( c ) => c.path === '/clients' && c.query.brand_id === '2' ) ).toBe( true );
	}, 120000 );

	it( 'department page links: clients, people, open tasks, projects, contracts, billing, invoices', async () => {
		await visit( '#/departments/2' );
		await new Promise( ( r ) => setTimeout( r, 80 ) );
		const hrefs = [ ...document.querySelectorAll( '.bd-deptpage a' ) ].map( ( a ) => a.getAttribute( 'href' ) || '' );
		for ( const h of [ '#/clients?brand_id=2', '#/team?department_id=2', '#/work/all?department_id=2', '#/work/projects?department_id=2', '#/contracts/all?department_id=2', '#/finance/billing?department_id=2', '#/finance/billing/invoices?department_id=2' ] ) {
			expect( hrefs ).toContain( h );
		}
	} );

	it( 'billing: another client or currency cannot be ticked once one is chosen', async () => {
		await visit( '#/finance/billing' );
		document.querySelectorAll( '.el-table__body .el-checkbox' )[ 0 ].click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 30 ) );
		const boxes = [ ...document.querySelectorAll( '.el-table__body .el-checkbox' ) ];
		expect( boxes[ 3 ].classList.contains( 'is-disabled' ) ).toBe( true );
		expect( boxes[ 1 ].classList.contains( 'is-disabled' ) ).toBe( false );
	} );

	it( 'billing: type chips and status select drive /billing/list', async () => {
		await visit( '#/finance/billing' );
		const chip = [ ...document.querySelectorAll( '.el-radio-button' ) ].find( ( b ) => b.textContent.includes( 'Subscriptions' ) );
		chip.querySelector( 'input' ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 60 ) );
		expect( calls.some( ( c ) => c.path === '/billing/list' && c.query.type === 'subscription' ) ).toBe( true );
	} );

	it( 'billing: clicking a recurring row opens its bills drawer', async () => {
		await visit( '#/finance/billing' );
		document.querySelector( '.el-table__body tr' ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 150 ) );
		expect( calls.some( ( c ) => c.path === '/client-services/4' ) ).toBe( true );
		expect( calls.some( ( c ) => c.path === '/renewals' && c.query.client_service_id === '4' ) ).toBe( true );
		expect( document.body.textContent ).toContain( 'Money history' );
	} );

	it( 'payroll: Add payment attaches a task and asks before paying a task twice', async () => {
		await visit( '#/finance/payroll' );
		[ ...document.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === 'Add payment' ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 80 ) );
		const body = document.body.textContent;
		expect( body ).toContain( 'Who is it for?' );
		expect( body ).toContain( 'Task (optional)' );
		expect( body ).toContain( 'What is it for?' );
		expect( calls.some( ( c ) => c.path === '/tasks' ) ).toBe( true );
		expect( warnings ).toEqual( [] );
	} );

	it( 'client contacts tab: primary receives, secondary copied', async () => {
		await visit( '#/clients/1' );
		[ ...document.querySelectorAll( '.el-tabs__item' ) ].find( ( t ) => t.textContent.includes( 'Contacts' ) ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 120 ) );
		const t = text();
		for ( const needle of [ 'Ada Obi', 'COO', 'ada@abc.example', 'Ngozi Eze', 'Accountant', 'Primary · receives', 'Secondary · copied', 'Add contact', 'Primary contacts receive invoice emails' ] ) {
			expect( t ).toContain( needle );
		}
		expect( warnings ).toEqual( [] );
	} );

	it( 'send invoice: defaults (To primary, Cc secondary + team), preview, send and test payloads', async () => {
		await visit( '#/billing/invoices/7' );
		const clickBtn = async ( pred ) => {
			const el = [ ...document.querySelectorAll( 'button' ) ].find( pred );
			expect( el, 'button' ).toBeTruthy();
			el.click();
			await flushPromises();
			await new Promise( ( r ) => setTimeout( r, 140 ) );
		};
		await clickBtn( ( b ) => b.textContent.includes( 'Save & send' ) );
		// saving an existing invoice then opens the drawer
		await new Promise( ( r ) => setTimeout( r, 200 ) );
		expect( calls.some( ( c ) => c.path === '/invoices/7/email-defaults' ) ).toBe( true );
		const body = document.body.textContent.replace( /\s+/g, ' ' );
		for ( const needle of [ 'Email invoice', 'Ada Obi', 'Ngozi Eze', 'Zara Team', 'From Digital Zooks <billing@dz.example>', 'via the site mailer', 'Send invoice', 'Send test', 'Send me a copy' ] ) {
			expect( body, needle ).toContain( needle );
		}
		const subject = [ ...document.querySelectorAll( '.el-drawer input' ) ].find( ( i ) => i.value === 'Invoice INV-0001 from Digital Zooks' );
		expect( subject ).toBeTruthy();
		const iframe = document.querySelector( 'iframe.bd-mailprev' );
		expect( iframe ).toBeTruthy();
		expect( iframe.getAttribute( 'srcdoc' ) ).toContain( 'Amount due' );
		const sb = iframe.getAttribute( 'sandbox' );
		expect( sb ).not.toContain( 'allow-scripts' ); // previews can never run scripts
		expect( iframe.getAttribute( 'srcdoc' ) ).toContain( '<base target="_blank">' );

		await clickBtn( ( b ) => b.textContent.trim() === 'Send test' );
		const test = calls.find( ( c ) => c.path === '/invoices/7/send' && c.body.test_to );
		expect( test.body.test_to ).toBe( 'me@dz.example' );
		expect( document.body.textContent ).toContain( 'Test email sent to ada@abc.example' );

		await clickBtn( ( b ) => b.textContent.trim() === 'Send invoice' );
		const real = calls.find( ( c ) => c.path === '/invoices/7/send' && ! c.body.test_to );
		expect( real.body.to.map( ( p ) => p.email ) ).toEqual( [ 'ada@abc.example' ] );
		expect( real.body.cc.map( ( p ) => p.email ).sort() ).toEqual( [ 'ngozi@abc.example', 'zara@dz.example' ] );
		expect( real.body.subject ).toBe( 'Invoice INV-0001 from Digital Zooks' );
		expect( real.body.to[ 0 ].name ).toBe( 'Ada Obi' );
	} );

	it( 'invoice editor shows email history', async () => {
		await visit( '#/billing/invoices/7' );
		const t = text();
		expect( t ).toContain( 'Emails' );
		expect( t ).toContain( 'To ada@abc.example' );
		expect( t ).toContain( 'Cc ngozi@abc.example' );
	} );

	it( 'legal: contracts list, signed progress and templates', async () => {
		await visit( '#/contracts/all' );
		expect( text() ).toContain( 'Website agreement — ABC' );
		expect( text() ).toContain( '1 of 2 signed' );
		expect( text() ).toContain( 'Partly signed' );
		await visit( '#/contracts/templates' );
		expect( text() ).toContain( 'Mutual NDA' );
		expect( warnings ).toEqual( [] );
	} );

	it( 'contract detail: sent contract shows signers, OTP evidence and verified log', async () => {
		await visit( '#/contracts/5' );
		expect( text() ).toContain( 'CT-0005' );
		expect( text() ).toContain( 'Ada Obi' );
		expect( text() ).toContain( 'Remind signers' );
		expect( text() ).toContain( 'Log verified' );
		expect( text() ).toContain( 'Genuine and unaltered' );
		expect( text() ).toContain( 'Preview link' );
		expect( [ ...document.querySelectorAll( '.bd-ie__link input' ) ].map( ( i ) => i.value ) ).toContain( 'http://x/contract/abc123xyz' );
		expect( text() ).toContain( '482913 → 482913' );
		expect( text() ).toContain( 'Code 482913 emailed to ada@abc.example' );
		expect( warnings ).toEqual( [] );
	} );

	it( 'contract detail: draft can be edited and sent', async () => {
		await visit( '#/contracts/6' );
		expect( text() ).toContain( 'Send for signature' );
		expect( text() ).toContain( 'Who signs' );
		expect( document.querySelector( '.bd-rt__body' ) ).toBeTruthy();
		expect( warnings ).toEqual( [] );
	} );

	it( 'contract draft: import dialog offers Word upload and Google Docs link', async () => {
		await visit( '#/contracts/6' );
		const btn = document.querySelector( '.bd-rt__imp' );
		expect( btn ).toBeTruthy();
		btn.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 50 ) );
		expect( text() ).toContain( 'Drop a Word file here' );
		expect( text() ).toContain( 'Google Docs link' );
		expect( [ ...document.querySelectorAll( '.bd-rt__bar button' ) ].map( ( b ) => b.title ) ).toEqual( expect.arrayContaining( [ 'Horizontal line', 'Start a new page here' ] ) );
	} );

	it( 'sidebar: core links, each module with its own sub-menu, active module expanded', async () => {
		await visit( '#/work/projects' );
		const side = document.querySelector( '.bd-side' ).textContent.replace( /\s+/g, ' ' );
		for ( const l of [ 'Home', 'Clients', 'Team', 'Work', 'Finance', 'Contracts', 'Activity', 'Settings' ] ) {
			expect( side ).toContain( l );
		}
		// The open module shows its own menu; the others stay collapsed.
		for ( const l of [ 'Overview', 'My tasks', 'All tasks', 'Projects' ] ) {
			expect( side ).toContain( l );
		}
		expect( side ).not.toContain( 'All contracts' );
		expect( document.querySelector( '.bd-top' ).textContent ).toContain( 'Projects' ); // breadcrumb
		expect( document.querySelector( '.bd-side__sublink.is-active' ).textContent ).toContain( 'Projects' );
		expect( warnings ).toEqual( [] );
	} );

	it( 'work overview and contracts overview render their own dashboards', async () => {
		await visit( '#/work' );
		expect( text() ).toContain( 'Open tasks' );
		expect( text() ).toContain( 'Projects in motion' );
		expect( text() ).toContain( 'Website redesign' );
		await visit( '#/contracts' );
		expect( text() ).toContain( 'Awaiting signatures' );
		expect( text() ).toContain( 'Waiting on signatures' );
		expect( text() ).toContain( 'Recently signed' );
		expect( warnings ).toEqual( [] );
	} );

	it( 'a switched-off module shows a clear screen instead of a broken page', async () => {
		const keep = store.nav.modules;
		store.nav = { ...store.nav, modules: keep.filter( ( m ) => m.id !== 'work' ) };
		await visit( '#/work/all' );
		expect( text() ).toContain( 'Work is switched off' );
		expect( text() ).toContain( 'Your data is safe' );
		store.nav = { ...store.nav, modules: keep };
	} );

	it( 'clients analytics: counts for everyone, income, recurring and overdue for finance', async () => {
		await visit( '#/clients/analytics' );
		await new Promise( ( r ) => setTimeout( r, 80 ) );
		const t = text();
		for ( const l of [ 'Analytics', 'List', 'New clients', 'Gone quiet', 'Northwind Ltd (Demo)', 'By department', 'No department', 'Where income comes from', '₦900,000', '64.3%', 'Recurring income', 'Overdue invoices by client', '12 days late', 'XYZ Logistics (Demo)' ] ) {
			expect( t ).toContain( l );
		}
		expect( calls.some( ( c ) => c.path === '/clients/analytics' && c.query.from ) ).toBe( true );
		expect( warnings ).toEqual( [] );
	} );

	it( 'team analytics: headcount, workload table, pay panels', async () => {
		await visit( '#/team/analytics' );
		await new Promise( ( r ) => setTimeout( r, 80 ) );
		const t = text();
		for ( const l of [ 'Analytics', 'People', 'Who is carrying what', 'Zara Team', '₦150,000', 'Owed now', 'Where pay goes', 'Pay by department earned for', 'Monthly salary bill', '₦850,000' ] ) {
			expect( t ).toContain( l );
		}
		expect( warnings ).toEqual( [] );
	} );

	it( 'clients and team lists have a List | Analytics switch', async () => {
		await visit( '#/clients' );
		expect( [ ...document.querySelectorAll( '.bd-viewswitch .el-radio-button' ) ].map( ( b ) => b.textContent.trim() ) ).toEqual( [ 'List', 'Analytics' ] );
	} );

	it( 'contracts analytics: stats, sent vs signed, signer funnel, waiting list, templates and departments', async () => {
		await visit( '#/contracts/analytics' );
		await new Promise( ( r ) => setTimeout( r, 80 ) );
		const t = text();
		for ( const l of [ 'Analytics', 'Completion rate', '71%', 'Time to sign', '30 hours', 'Median 20 hours', 'Sent vs signed', 'Where signers drop off', 'Invited', 'Verified the code', '6 · 60%', 'Waiting the longest', 'Chidi Obi', 'Slowest to sign', '2.9 days', 'By template', 'Service agreement', 'By department' ] ) {
			expect( t ).toContain( l );
		}
		expect( calls.some( ( c ) => c.path === '/contracts/analytics' && c.query.from ) ).toBe( true );
		expect( calls.some( ( c ) => c.path === '/contracts/analytics/' ) ).toBe( false );
		expect( warnings ).toEqual( [] );
	} );

	it( 'work analytics: stats, flow chart, workload, departments, projects and finance-only money', async () => {
		await visit( '#/work/analytics' );
		await new Promise( ( r ) => setTimeout( r, 80 ) );
		const t = text();
		for ( const l of [ 'Analytics', 'Open tasks', '2 overdue', 'Completed', 'Time to finish', '4.5 days', 'Median 3 days', 'Done on time', '75%', 'Created vs completed', 'Workload by person', 'Zara Team', 'Unassigned', 'Tasks by department', 'Projects', 'Website redesign', '12 days late', 'Project value and team pay by department', '₦800,000', '₦510,000' ] ) {
			expect( t ).toContain( l );
		}
		expect( calls.some( ( c ) => c.path === '/work/analytics' && c.query.from && c.query.to ) ).toBe( true );
		expect( warnings ).toEqual( [] );
	} );

	it( 'finance analytics: summary per currency, charts per currency, currency control with Combined', async () => {
		await visit( '#/finance/analytics' );
		await new Promise( ( r ) => setTimeout( r, 80 ) );
		const t = text();
		for ( const l of [ 'Analytics', 'Income', 'Direct costs', 'Team pay', 'Profit', '₦1,500,000', '$900', '47%', 'Income and costs — NGN', 'Income and costs — USD', 'Receivables ageing', 'Currency mix', 'Best clients', 'ABC Company (Demo)', 'Website redesign', 'Income lines', 'Combined' ] ) {
			expect( t ).toContain( l );
		}
		expect( calls.some( ( c ) => c.path === '/finance/analytics' && c.query.currency === 'all' ) ).toBe( true );
		const combinedBtn = [ ...document.querySelectorAll( '.el-radio-button' ) ].find( ( b ) => b.textContent.includes( 'Combined' ) );
		combinedBtn.querySelector( 'input' ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 80 ) );
		expect( calls.some( ( c ) => c.path === '/finance/analytics' && c.query.currency === 'combined' ) ).toBe( true );
		expect( warnings ).toEqual( [] );
	} );

	it( 'settings: modules tab lists every module with a switch', async () => {
		await visit( '#/settings?tab=modules' );
		expect( text() ).toContain( 'Always on' );
		for ( const l of [ 'Work', 'Finance', 'Contracts', 'Full-screen app mode' ] ) {
			expect( text() ).toContain( l );
		}
		expect( document.querySelectorAll( '.el-switch' ).length ).toBeGreaterThanOrEqual( 6 );
		expect( text() ).toContain( 'Analytics page' ); // per-module analytics switch
		expect( document.querySelector( '[aria-label="Team analytics"]' ) ).toBeTruthy(); // core areas have their own
		expect( document.querySelector( '[aria-label="Work analytics"]' ) ).toBeTruthy();
	} );

	it( 'home: a fresh install offers to restore a backup, and Start fresh hides the card', async () => {
		store.freshInstall = true;
		store.backupAlert = 'Disk full';
		await visit( '#/' );
		expect( text() ).toContain( 'Welcome to BizyDash' );
		expect( text() ).toContain( 'The last automatic backup failed.' );
		calls.length = 0;
		[ ...document.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === 'Restore a backup' ).click();
		await flushPromises();
		expect( window.location.hash ).toBe( '#/settings?tab=backup&restore=1' );
		await visit( '#/' );
		[ ...document.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === 'Start fresh' ).click();
		await flushPromises();
		expect( calls.some( ( c ) => c.method === 'POST' && c.path === '/backups/fresh-dismiss' ) ).toBe( true );
		expect( text() ).not.toContain( 'Welcome to BizyDash' );
		store.backupAlert = '';
	} );

	it( 'invoice import: preview merges names sharing an email, matches existing clients, then imports in one batch', async () => {
		await visit( '#/finance/billing/invoices' );
		const open = [ ...document.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === 'Import invoices…' );
		expect( open ).toBeTruthy();
		open.click();
		await flushPromises();
		const dlg = () => document.querySelector( '.bd-import' );
		expect( dlg().textContent ).toContain( 'Invoice.ng' );
		const input = dlg().querySelector( 'input[type="file"]' );
		Object.defineProperty( input, 'files', { value: [ new File( [ 'x' ], 'inv.xlsx' ) ], configurable: true } );
		input.dispatchEvent( new Event( 'change' ) );
		await new Promise( ( r ) => setTimeout( r, 300 ) );
		await flushPromises();
		let t = dlg().textContent;
		expect( t ).toContain( 'Sprout Invoices: 3 invoices from 3 customer names' );
		expect( t ).toContain( '1 look like invoices you already have' );
		expect( t ).toContain( 'Number prefix' );
		expect( t ).toContain( 'Same email as Victor Martins' );
		expect( t ).toContain( 'will create 2 invoices and 1 new client' ); // the two same-email names merge into one new client; Technext matches an existing one
		calls.length = 0;
		[ ...dlg().querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim().startsWith( 'Import 2' ) ).click();
		await new Promise( ( r ) => setTimeout( r, 300 ) );
		await flushPromises();
		const run = calls.find( ( c ) => c.path === '/invoices/import/run' );
		expect( run.body.rows.length ).toBe( 2 ); // the possible duplicate is skipped
		expect( run.body.plan.source ).toBe( 'Sprout Invoices' );
		expect( run.body.plan.number_prefix ).toBe( 'SI-' );
		expect( run.body.plan.targets.technext ).toEqual( { mode: 'existing', id: 7 } );
		expect( run.body.plan.targets.victormartins.name ).toBe( 'Ready Designs' );
		expect( run.body.last ).toBe( true );
		expect( dlg().textContent ).toContain( 'Imported 3 invoices' );
		expect( dlg().textContent ).toContain( 'Undo this import' );
	} );

	it( 'sample data: fresh install offers it, a banner and a Settings button remove it', async () => {
		store.freshInstall = true;
		await visit( '#/' );
		expect( [ ...document.querySelectorAll( 'button' ) ].some( ( b ) => b.textContent.trim() === 'Try with sample data' ) ).toBe( true );
		store.freshInstall = false;
		store.demoLoaded = true;
		await visit( '#/' );
		expect( text() ).toContain( 'You are looking at sample data.' );
		await visit( '#/settings?tab=general' );
		expect( [ ...document.querySelectorAll( 'button' ) ].some( ( b ) => b.textContent.trim() === 'Remove sample data' ) ).toBe( true );
		store.demoLoaded = false;
	} );

	it( 'settings: roles & access tab lists roles and client logins; team page shows an Access card', async () => {
		await visit( '#/settings?tab=access' );
		const t = text();
		for ( const l of [ 'Client logins', 'BD Client', 'BD Work Team', '2 of 25 clients linked', 'Create logins for existing clients' ] ) {
			expect( t ).toContain( l );
		}
		store.caps = { team: true };
		await visit( '#/team/1' );
		[ ...document.querySelectorAll( '.el-tabs__item' ) ].find( ( x ) => x.textContent.trim() === 'Details' ).click();
		await new Promise( ( r ) => setTimeout( r, 300 ) );
		await flushPromises();
		expect( text() ).toContain( 'Login: zara.' );
		store.caps = {};
	} );

	it( 'client portal: settings tab, and the client page lists logins with an invite button', async () => {
		await visit( '#/settings?tab=portal' );
		const t = text();
		for ( const l of [ 'Client portal is on', 'Address word', 'Welcome message', 'Tasks inside projects', 'Bills coming up' ] ) {
			expect( t ).toContain( l );
		}
		store.caps = { clients: true };
		await visit( '#/clients/1' );
		[ ...document.querySelectorAll( '.el-tabs__item' ) ].find( ( x ) => x.textContent.trim().startsWith( 'Contacts' ) ).click();
		await new Promise( ( r ) => setTimeout( r, 400 ) );
		await flushPromises();
		expect( text() ).toContain( 'View portal as this client' );
		expect( text() ).toContain( 'ada@abc.example' );
		calls.length = 0;
		[ ...document.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === 'Send again' || b.textContent.trim() === 'Send invitation' ).click();
		await flushPromises();
		expect( calls.some( ( c ) => c.method === 'POST' && c.path === '/clients/1/portal-invite' && c.body.user_id === 9 ) ).toBe( true );
		store.caps = {};
	} );

	it( 'settings: backup tab lists saved backups, warns about problems and runs a backup', async () => {
		await visit( '#/settings?tab=backup' );
		const t = text();
		for ( const l of [ 'Back up now', 'Automatic backups', 'Saved backups', 'Password protected', 'Clients & team 417' ] ) {
			expect( t ).toContain( l );
		}
		expect( t ).toContain( 'can be opened from the web' ); // exposed folder warning
		expect( t ).toContain( 'The last automatic backup failed: Disk full' );
		calls.length = 0;
		const btn = [ ...document.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === 'Back up now' );
		btn.click();
		await new Promise( ( r ) => setTimeout( r, 300 ) );
		await flushPromises();
		expect( calls.some( ( c ) => c.method === 'POST' && c.path === '/backups' && c.body.files === true ) ).toBe( true );
		expect( calls.some( ( c ) => c.path === '/backups/step' && c.body.job === 'c'.repeat( 16 ) ) ).toBe( true );
	} );

	it( 'restore dialog: shows what the backup holds, blocks missing parts, needs RESTORE typed, then runs', async () => {
		await visit( '#/settings?tab=backup' );
		const restoreBtn = [ ...document.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === 'Restore…' );
		restoreBtn.click();
		await new Promise( ( r ) => setTimeout( r, 300 ) );
		await flushPromises();
		const dlg = () => document.querySelector( '.bd-restore' );
		let t = dlg().textContent;
		for ( const l of [ 'Clients, departments & team', 'Available', 'No records', 'Not in this backup', 'older version', 'mystery_table', 'SMTP password' ] ) {
			expect( t ).toContain( l );
		}
		// contracts is missing from the backup, so its checkbox cannot be ticked
		expect( dlg().querySelector( '[aria-label="Contracts"]' ).closest( '.el-checkbox' ).classList.contains( 'is-disabled' ) ).toBe( true );
		// Clients + Work are ticked by default; Finance (206 records here) and Contracts (missing from the backup) are linked to clients
		const cont = () => [ ...dlg().querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === 'Continue' );
		expect( dlg().textContent ).toContain( 'Restore Finance together' );
		expect( dlg().textContent ).toContain( 'no Contracts data to restore alongside' ); // cannot be fixed with this backup
		expect( cont().disabled ).toBe( true );
		[ ...dlg().querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === 'Restore Finance together' ).click();
		await flushPromises();
		expect( cont().disabled ).toBe( true ); // Contracts still stands in the way
		dlg().querySelector( '[aria-label="Clients, departments & team"]' ).click(); // leave clients out
		await flushPromises();
		expect( cont().disabled ).toBe( false );
		cont().click();
		await flushPromises();
		t = dlg().textContent;
		expect( t ).toContain( 'Type RESTORE to continue' );
		const go = () => [ ...dlg().querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === 'Restore now' );
		expect( go().disabled ).toBe( true );
		const input = dlg().querySelector( 'input[placeholder="RESTORE"]' );
		input.value = 'RESTORE';
		input.dispatchEvent( new Event( 'input' ) );
		await flushPromises();
		expect( go().disabled ).toBe( false );
		calls.length = 0;
		go().click();
		await new Promise( ( r ) => setTimeout( r, 300 ) );
		await flushPromises();
		const start = calls.find( ( c ) => c.path === '/backups/restore' );
		expect( start.body.modules.sort() ).toEqual( [ 'finance', 'work' ] );
		expect( calls.some( ( c ) => c.path === '/backups/restore-step' ) ).toBe( true );
		t = dlg().textContent;
		expect( t ).toContain( 'Restore complete.' );
		expect( t ).toContain( '14 of 14' );
		expect( t ).toContain( 'Check SMTP' );
		expect( t ).toContain( 'Undo this restore' );
	} );

	it( 'settings: currencies tab has base currency, rates and on/off switches', async () => {
		await visit( '#/settings?tab=currencies' );
		const t = text();
		for ( const l of [ 'Base currency (for combined totals)', 'Rate: 1 unit = ?', 'Base currency', 'Used by' ] ) {
			expect( t ).toContain( l );
		}
		expect( document.querySelector( '[aria-label="USD active"]' ) ).toBeTruthy();
		expect( calls.some( ( c ) => c.path === '/currencies/usage' ) ).toBe( true );
	} );

	it( 'theme switcher offers Light, Dark and System and saves the choice', async () => {
		await visit( '#/' );
		document.querySelector( '[aria-label="Theme"]' ).click();
		await flushPromises();
		await new Promise( ( r ) => setTimeout( r, 80 ) );
		const items = [ ...document.querySelectorAll( '.bd-thememenu .bd-menuitem' ) ];
		expect( items.map( ( i ) => i.textContent.trim() ) ).toEqual( [ 'Light', 'Dark', 'System' ] );
		items[ 1 ].click();
		await flushPromises();
		expect( store.themePref ).toBe( 'dark' );
		expect( store.theme ).toBe( 'dark' );
		expect( calls.some( ( c ) => c.path === '/preferences' && c.body && c.body.theme === 'dark' ) ).toBe( true );
		items[ 2 ].click();
		await flushPromises();
		expect( store.themePref ).toBe( 'auto' );
		store.setTheme( 'light' );
	} );

	it( 'sidebar has a Sign out link for the front-end app', async () => {
		await visit( '#/' );
		const a = [ ...document.querySelectorAll( '.bd-side a' ) ].find( ( n ) => n.textContent.trim() === 'Sign out' );
		expect( a ).toBeTruthy();
		expect( a.getAttribute( 'href' ) ).toContain( 'action=logout' );
	} );

	it( 'dark mode toggles the html.dark class used by Element Plus variables', async () => {
		await visit( '#/' );
		store.theme = 'dark';
		await flushPromises();
		expect( document.documentElement.classList.contains( 'dark' ) ).toBe( true );
		store.theme = 'light';
		await flushPromises();
		expect( document.documentElement.classList.contains( 'dark' ) ).toBe( false );
	} );
} );
