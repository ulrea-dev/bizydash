/**
 * Per-resource table + form configuration (columns, fields, filters, defaults).
 * Ported 1:1 from the original React pages; consumed by ResourceTable.vue.
 */
import { ElMessage, ElMessageBox } from 'element-plus';
import { h } from 'vue';
import { api, errorMessage } from './api';
import { navigate, store } from './store';
import BillStatus from './components/BillStatus.vue';
import MoneyMap from './components/MoneyMap.vue';
import StatusTag from './components/StatusTag.vue';
import { avatarStyle, CONTRACT_STATUS, CONTRACT_STATUS_OPTIONS, dept, initials, PROJECT_STATUS, PROJECT_STATUS_OPTIONS, relDays, TASK_STATUS, TASK_STATUS_OPTIONS, TIMINGS, currencyOptions, defaultCurrency, fmtDate, fmtMoney, humanize, labelOf, options, timingOptions, today } from './util';

const ACTIVE_STATUSES = [
	{ value: 'active', label: 'Active' },
	{ value: 'inactive', label: 'Inactive' },
];
const KIND_OPTIONS = [
	{ value: 'recurring', label: 'Recurring' },
	{ value: 'one_off', label: 'One-off' },
];
const cap = ( s ) => s.replace( /_/g, ' ' ).replace( /^\w/, ( c ) => c.toUpperCase() );
const opts = ( list ) => list.map( ( s ) => ( { value: s, label: cap( s ) } ) );
const muted = ( t ) => h( 'span', { class: 'bd-muted' }, t );

export const brandsConfig = () => ( {
	title: dept().many,
	description: `The ${ dept().lowerMany } of your business — tag each client with the ones they belong to, and filter your numbers by ${ dept().lower }.`,
	resource: 'brands',
	singular: dept().lower,
	newLabel: `Add ${ dept().lower }`,
	filters: [
		{ name: 'type', label: 'Type', options: options( 'brand_type' ) },
		{ name: 'status', label: 'Status', options: ACTIVE_STATUSES },
	],
	defaults: { status: 'active', type: ( options( 'brand_type' )[ 0 ] || {} ).value || '' },
	actionsWidth: 96,
	actions: ( r ) => [ { label: 'Overview', type: 'primary', onClick: () => navigate( `/departments/${ r.id }` ) } ],
	columns: [
		{ type: 'logo', label: '', width: 64 },
		{ prop: 'name', label: dept().one, type: 'strong', minWidth: 160 },
		{ prop: 'type', label: 'Type', type: 'lookup', lookup: 'brand_type' },
		{ prop: 'description', label: 'Description', minWidth: 200 },
		{ prop: 'status', label: 'Status', type: 'status' },
	],
	fields: [
		{ name: 'name', label: `${ dept().one } name`, required: true, wide: true },
		{ name: 'type', label: `${ dept().one } type`, type: 'select', required: true, options: () => options( 'brand_type' ) },
		{ name: 'status', label: 'Status', type: 'select', required: true, options: ACTIVE_STATUSES },
		{ name: 'description', label: 'Description', type: 'textarea', wide: true },
		{ name: 'logo_attachment_id', label: 'Logo', type: 'attachment', urlKey: 'logo_url', wide: true },
	],
} );

const TYPE_OPTIONS = [ { value: 'service', label: 'Service' }, { value: 'product', label: 'Product' } ];
const PRICING_OPTIONS = [ { value: 'fixed', label: 'Fixed price' }, { value: 'unit', label: 'Per unit' } ];

/** "₦25,000 / page" style price text for catalogue rows and pickers. */
export const catalogPrice = ( s, fallbackCurrency ) => fmtMoney( s.default_price, s.default_currency || fallbackCurrency ) + ( s.pricing === 'unit' ? ' / ' + ( s.unit_label || 'unit' ) : '' );

export const catalogConfig = ( { type = '' } = {} ) => ( {
	title: 'Catalog',
	description: 'Everything you sell — services and products. Defaults are copied onto invoices and client services, and can be overridden each time.',
	resource: 'services',
	bulkDepartment: true,
	singular: type === 'product' ? 'product' : type === 'service' ? 'service' : 'catalog item',
	newLabel: type === 'product' ? 'Add product' : type === 'service' ? 'Add service' : 'Add to catalog',
	filters: [
		{ name: 'kind', label: 'Billing', options: KIND_OPTIONS },
		{ name: 'status', label: 'Status', options: ACTIVE_STATUSES },
	],
	defaults: type === 'product'
		? { type: 'product', pricing: 'unit', unit_label: '', kind: 'one_off', default_billing_period: 'none', status: 'active', default_currency: defaultCurrency(), default_cost: 0, default_price: 0 }
		: { type: 'service', pricing: 'fixed', unit_label: '', kind: 'recurring', default_billing_period: 'annual', status: 'active', default_currency: defaultCurrency(), default_cost: 0, default_price: 0 },
	columns: [
		{
			prop: 'name',
			label: 'Name',
			minWidth: 220,
			render: ( r ) => h( 'div', [ h( 'strong', r.name ), h( 'div', { class: 'bd-small' }, [ h( 'span', { class: [ 'bd-badge', 'bd-badge--plain', r.type === 'product' ? 'bd-badge--info' : 'bd-badge--neutral' ] }, r.type === 'product' ? 'Product' : 'Service' ) ] ) ] ),
		},
		{ prop: 'pricing', label: 'Pricing', render: ( r ) => ( r.pricing === 'unit' ? `Per ${ r.unit_label || 'unit' }` : 'Fixed price' ) },
		{
			prop: 'kind',
			label: 'Billing',
			render: ( r ) => ( r.kind === 'recurring' ? h( 'div', [ h( 'span', { class: [ 'bd-badge', 'bd-badge--plain', 'bd-badge--info' ] }, r.type === 'product' ? 'Subscription' : 'Retainer' ), h( 'div', { class: 'bd-muted bd-small' }, labelOf( 'billing_period', r.default_billing_period ) ) ] ) : h( 'span', { class: [ 'bd-badge', 'bd-badge--plain', 'bd-badge--neutral' ] }, 'One-off' ) ),
		},
		{ prop: 'default_billing_treatment', label: 'How it’s billed', minWidth: 150, render: ( r ) => ( r.kind === 'recurring' ? labelOf( 'billing_treatment', r.default_billing_treatment ) : muted( '—' ) ) },
		{ label: dept().one, minWidth: 200, render: ( r ) => ( r.department_name ? h( 'span', { class: 'bd-badge bd-badge--neutral bd-badge--plain' }, r.department_name ) : muted( '—' ) ) },
		{ prop: 'default_cost', label: 'Default cost', type: 'money', currencyProp: 'default_currency', align: 'right' },
		{ label: 'Default price', align: 'right', minWidth: 140, render: ( r ) => h( 'span', { class: 'bd-money' }, catalogPrice( r ) ) },
		{ prop: 'status', label: 'Status', type: 'status' },
	],
	fields: [
		{ name: 'name', label: 'Name', required: true, wide: true, placeholder: 'e.g. Website design, or Product photography' },
		{ name: 'type', label: 'Type', type: 'select', required: true, options: TYPE_OPTIONS, help: 'Services are work you do; products are things you supply or count.', onPick: ( v ) => ( v === 'product' ? { pricing: 'unit' } : { pricing: 'fixed' } ) },
		{ name: 'pricing', label: 'Pricing', type: 'select', required: true, options: PRICING_OPTIONS, help: 'Fixed price = one amount, no quantity (shown in the “Fixed price” block on invoices). Per unit = quantity × price (shown in the “By quantity” block).' },
		{ name: 'department_id', label: dept().one, type: 'remote', remote: { resource: 'brands', label: ( b ) => b.name, params: () => ( { status: 'active' } ) }, help: 'Income from this item is reported under this department.' },
		{ name: 'subject_label', label: 'What do you track for each client?', placeholder: 'e.g. Domain name, Website, Licence key', help: 'The question asked when you add this to a client — the answer is shown on bills, invoices and reminders (for example the actual domain).' },
		{ name: 'unit_label', label: 'Unit', placeholder: 'page, hour, image, account…', show: ( v ) => v.pricing === 'unit', help: 'What one unit is, e.g. “page” gives “₦25,000 / page”.' },
		{ name: 'kind', label: 'Billing', type: 'select', required: true, options: KIND_OPTIONS, help: 'One-off is billed once. Recurring bills every cycle — a recurring product is a subscription (hosting, domains, email accounts), a recurring service is a retainer.', onPick: ( v ) => ( v === 'one_off' ? { default_billing_period: 'none' } : { default_billing_period: 'annual' } ) },
		{ name: 'default_billing_period', label: 'Billing cycle', type: 'select', options: () => options( 'billing_period' ).filter( ( o ) => o.value !== 'none' ), show: ( v ) => v.kind === 'recurring' },
		{ name: 'default_billing_treatment', label: 'Default billing treatment', type: 'select', options: () => options( 'billing_treatment' ), show: ( v ) => v.kind === 'recurring' },
		{ name: 'default_cost', label: 'Default cost (what it costs us)', type: 'number' },
		{ name: 'default_price', label: 'Default client price', type: 'number' },
		{ name: 'default_currency', label: 'Currency', type: 'select', required: true, options: currencyOptions },
		{ name: 'status', label: 'Status', type: 'select', required: true, options: ACTIVE_STATUSES },
		{ name: 'description', label: 'Description', type: 'textarea', wide: true },
	],
} );

export const clientFields = () => [
	{ name: 'company_name', label: 'Company name', required: true, wide: true },
	{ name: 'client_type', label: 'Client type', type: 'select', required: true, options: () => options( 'client_type' ) },
	{ name: 'status', label: 'Status', type: 'select', required: true, options: () => options( 'client_status' ) },
	{ name: 'brand_ids', label: dept().many, type: 'brands', wide: true },
	{ name: 'contact_person', label: 'Primary contact name', show: ( v, isNew ) => isNew, help: 'You can add more contacts (and set who is copied) from the client page.' },
	{ name: 'email', label: 'Primary contact email', type: 'email', show: ( v, isNew ) => isNew },
	{ name: 'phone', label: 'Primary contact phone', show: ( v, isNew ) => isNew },
	{ name: 'currency', label: 'Billing currency', type: 'select', required: true, options: currencyOptions, help: 'Services, charges and invoices default to this currency.' },
	{ name: 'address', label: 'Address', type: 'textarea', wide: true },
	{ name: 'notes', label: 'Notes', type: 'textarea', wide: true },
];

/** "Ada Obi · ada@x.com  +2 more" from a client's contacts (primary first). */
const contactLine = ( r ) => {
	const list = r.contacts && r.contacts.length ? r.contacts : [];
	const first = list.find( ( c ) => c.kind === 'primary' ) || list[ 0 ];
	if ( ! first ) {
		return [ r.contact_person, r.email ].filter( Boolean ).join( ' · ' ) || '—';
	}
	return [ first.name, first.email ].filter( Boolean ).join( ' · ' ) + ( list.length > 1 ? `  +${ list.length - 1 } more` : '' );
};

export const clientDefaults = () => ( {
	client_type: ( options( 'client_type' )[ 0 ] || {} ).value || 'retainer',
	status: 'active',
	currency: defaultCurrency(),
	brand_ids: [],
} );

export const clientsConfig = ( brands = [] ) => ( {
	title: 'Clients',
	description: 'Everyone you serve. Open a client for their billing, work, contracts and history.',
	resource: 'clients',
	bulkDepartment: true,
	bulkDepartmentMulti: true,
	singular: 'client',
	newLabel: 'Add client',
	initialFilters: { status: 'active' },
	filters: [
		{ name: 'client_type', label: 'Type', options: options( 'client_type' ) },
		{ name: 'brand_id', label: dept().one, options: brands.map( ( b ) => ( { value: b.id, label: b.name } ) ) },
		{ name: 'status', label: 'Status', options: options( 'client_status', true ) },
	],
	defaults: clientDefaults(),
	columns: [
		{
			label: 'Client',
			minWidth: 260,
			render: ( r ) =>
				h( 'a', { class: 'bd-cell-client', href: `#/clients/${ r.id }` }, [
					h( 'span', { class: 'bd-ava', style: avatarStyle( r.company_name ) }, initials( r.company_name ) ),
					h( 'div', [ h( 'div', { class: 'bd-strong' }, r.company_name ), h( 'div', { class: 'bd-muted bd-small' }, contactLine( r ) ) ] ),
				] ),
		},
		{ prop: 'client_type', label: 'Type', type: 'lookup', lookup: 'client_type' },
		{ label: dept().many, type: 'brands', minWidth: 220 },
		{ prop: 'currency', label: 'Currency', width: 90 },
		{ prop: 'status', label: 'Status', type: 'status' },
	],
	fields: clientFields(),
} );


const SUB_STATUSES = [
	{ value: 'active', label: 'Active' },
	{ value: 'paused', label: 'Paused' },
	{ value: 'cancelled', label: 'Cancelled' },
];
const CHARGE_STATUSES = opts( [ 'pending', 'invoiced', 'waived', 'cancelled' ] );

/** One-off bills (stored as `charges`). */
export const oneOffConfig = ( { hideClient, onInvoice } = {} ) => ( {
	title: 'One-off',
	description: 'Single bills — a project, a once-off service or a pass-through cost.',
	resource: 'charges',
	singular: 'one-off bill',
	newLabel: 'New one-off bill',
	selectable: true,
	isSelectable: ( r ) => r.bill_status === 'not_invoiced',
	searchable: true,
	defaults: { billing_timing: 'now', incurred_on: today(), status: 'pending' },
	actionsWidth: 96,
	actions: ( r, ctx ) => [
		r.bill_status === 'not_invoiced' ? { label: 'Invoice', type: 'primary', onClick: () => onInvoice( r ) } : null,
		r.invoice_id && r.bill_status !== 'not_invoiced' ? { label: 'View invoice', onClick: () => navigate( `/billing/invoices/${ r.invoice_id }` ) } : null,
		r.bill_status === 'not_invoiced'
			? {
				label: 'Waive',
				menu: true,
				onClick: async () => {
					try {
						await ElMessageBox.confirm( `Waive "${ r.description }"? It will no longer be billed.`, 'Waive bill', { type: 'warning', confirmButtonText: 'Waive' } );
					} catch ( e ) {
						return;
					}
					try {
						await api.post( `charges/${ r.id }/waive` );
						ElMessage.success( 'Waived' );
						ctx.reload();
					} catch ( e ) {
						ElMessage.error( errorMessage( e ) );
					}
				},
			}
			: null,
	],
	columns: [
		{
			label: 'Bill',
			minWidth: 250,
			render: ( r ) => h( 'div', [ h( 'div', { class: 'bd-strong' }, r.description ), r.service_name ? h( 'div', { class: 'bd-muted bd-small' }, r.service_name ) : null ] ),
		},
		...( hideClient ? [] : [ { prop: 'client_name', label: 'Client', type: 'client', minWidth: 190 } ] ),
		{
			label: 'Due',
			minWidth: 130,
			render: ( r ) =>
				r.bill_status === 'not_invoiced'
					? h( 'div', [ h( 'div', r.bill_on_date ? fmtDate( r.bill_on_date ) : 'No date' ), h( 'div', { class: ( r.bill_on_date && r.bill_on_date < today() ? 'bd-late' : 'bd-muted' ) + ' bd-small' }, r.bill_on_date ? relDays( Math.round( ( new Date( r.bill_on_date + 'T00:00:00' ) - new Date( today() + 'T00:00:00' ) ) / 86400000 ) ) : TIMINGS[ r.billing_timing ] || '' ) ] )
					: h( 'div', fmtDate( r.bill_on_date ) ),
		},
		{
			label: 'Amount',
			align: 'right',
			minWidth: 150,
			render: ( r ) =>
				h( 'div', [
					h( 'div', { class: 'bd-money bd-strong' }, fmtMoney( r.price, r.currency ) ),
					h( 'div', { class: 'bd-small bd-num ' + ( r.margin < 0 ? 'bd-late' : 'bd-muted' ) }, `cost ${ fmtMoney( r.cost, r.currency ) } · margin ${ fmtMoney( r.margin, r.currency ) }` ),
				] ),
		},
		{
			label: 'Status',
			minWidth: 160,
			render: ( r ) => h( 'div', [ h( BillStatus, { status: r.bill_status } ), r.invoice_number ? h( 'a', { class: 'bd-link bd-small bd-block', href: `#/billing/invoices/${ r.invoice_id }` }, r.invoice_number ) : null ] ),
		},
	],
	fields: [
		{
			name: 'client_id',
			label: 'Client',
			type: 'remote',
			required: true,
			wide: true,
			remote: { resource: 'clients', label: ( c ) => c.company_name, params: () => ( { status: 'active' } ) },
			onPick: ( c, v ) => ( { currency: c.currency, ...( v.department_id ? {} : { department_id: primaryDept( c ) || 0 } ) } ),
		},
		{
			name: 'service_id',
			label: 'Catalog item (optional)',
			type: 'remote',
			wide: true,
			placeholder: 'Pick from your catalog to prefill, or type a custom title',
			remote: { resource: 'services', label: ( s ) => s.name, params: () => ( { status: 'active', kind: 'one_off', pricing: 'fixed' } ) },
			// Prefill title + prices from the catalogue (only when blank so edits aren't overwritten).
			onPick: ( s, v ) => ( { description: v.description || s.name, price: v.price || s.default_price, cost: v.cost || s.default_cost, ...( s.department_id ? { department_id: s.department_id } : {} ) } ),
		},
		{ name: 'description', label: 'Title', wide: true, placeholder: 'e.g. Logo design, plugin licence, 3 extra mailboxes' },
		{ name: 'cost', label: 'Our cost', type: 'number' },
		{ name: 'price', label: 'Client price', type: 'number' },
		{ name: 'currency', label: 'Currency', type: 'select', required: true, options: currencyOptions },
		deptField( 'Which department earns this. Defaults to the catalog item’s, else the client’s.' ),
		{ name: 'billing_timing', label: 'Bill', type: 'select', required: true, options: timingOptions },
		{ name: 'bill_on_date', label: 'Due / bill on', type: 'date', show: ( v ) => v.billing_timing === 'custom' },
		{ name: 'incurred_on', label: 'Date incurred', type: 'date' },
		{ name: 'status', label: 'Status', type: 'select', options: CHARGE_STATUSES, onlyEdit: true },
		{ name: 'notes', label: 'Notes', type: 'textarea', wide: true },
	],
} );

/** Recurring bills: subscriptions (client services with a cycle). */
export const recurringConfig = ( { hideClient, currency, onCycles } = {} ) => ( {
	title: 'Recurring',
	description: 'Subscriptions and retainers — each one bills every cycle.',
	resource: 'client-services',
	singular: 'subscription',
	newLabel: 'New subscription',
	initialFilters: { status: 'active' },
	filters: [
		{ name: 'status', label: 'Status', options: SUB_STATUSES },
		{ name: 'billing_period', label: 'Repeats', options: options( 'billing_period' ).filter( ( o ) => ! [ 'none', 'custom' ].includes( o.value ) ) },
	],
	defaults: { status: 'active', currency },
	actionsWidth: 96,
	actions: ( r ) => [ { label: 'Bills', type: 'primary', onClick: () => onCycles( r ) } ],
	columns: [
		{
			label: 'Subscription',
			minWidth: 230,
			render: ( r ) =>
				h( 'div', [
					h( 'div', [ h( 'span', { class: 'bd-strong' }, r.subject || r.service_name ), h( 'span', { class: [ 'bd-badge', 'bd-badge--plain', 'bd-ml', r.service_type === 'product' ? 'bd-badge--info' : 'bd-badge--neutral' ] }, r.service_type === 'product' ? 'Subscription' : 'Retainer' ) ] ),
					[ r.subject ? r.service_name : '', r.provider ].filter( Boolean ).length ? h( 'div', { class: 'bd-muted bd-small' }, [ r.subject ? r.service_name : '', r.provider ].filter( Boolean ).join( ' · ' ) ) : null,
				] ),
		},
		...( hideClient ? [] : [ { prop: 'client_name', label: 'Client', type: 'client', minWidth: 190 } ] ),
		{ label: 'Repeats', minWidth: 110, render: ( r ) => labelOf( 'billing_period', r.billing_period ) },
		{
			label: 'Price',
			align: 'right',
			minWidth: 130,
			render: ( r ) => h( 'div', [ h( 'div', { class: 'bd-money bd-strong' }, fmtMoney( r.client_price, r.currency ) ), Number( r.unit_price ) > 0 ? h( 'div', { class: 'bd-muted bd-small bd-num' }, `${ Number( r.quantity ) } × ${ fmtMoney( r.unit_price, r.currency ) }${ r.service_unit ? ' / ' + r.service_unit : '' }` ) : null, r.mrr ? h( 'div', { class: 'bd-muted bd-small bd-num' }, `MRR ${ fmtMoney( r.mrr, r.currency ) }` ) : null ] ),
		},
		{
			label: 'Next due',
			minWidth: 130,
			render: ( r ) =>
				r.next_cycle
					? h( 'div', [ h( 'div', fmtDate( r.next_cycle.due_date ) ), h( 'div', { class: ( r.next_cycle.due_date < today() ? 'bd-late' : 'bd-muted' ) + ' bd-small' }, relDays( Math.round( ( new Date( r.next_cycle.due_date + 'T00:00:00' ) - new Date( today() + 'T00:00:00' ) ) / 86400000 ) ) ) ] )
					: muted( '—' ),
		},
		{
			label: 'Billing',
			minWidth: 160,
			render: ( r ) => ( r.next_cycle ? h( 'div', [ h( BillStatus, { status: r.next_cycle.bill_status } ), r.next_cycle.invoice_number ? h( 'a', { class: 'bd-link bd-small bd-block', href: `#/billing/invoices/${ r.next_cycle.invoice_id }` }, r.next_cycle.invoice_number ) : null ] ) : muted( '—' ) ),
		},
		{ prop: 'status', label: 'Status', type: 'status', minWidth: 100 },
	],
	fields: [
		{
			name: 'client_id',
			label: 'Client',
			type: 'remote',
			required: true,
			wide: true,
			remote: { resource: 'clients', label: ( c ) => c.company_name, params: () => ( { status: 'active' } ) },
			onPick: ( c, v ) => ( { currency: c.currency, ...( v.department_id ? {} : { department_id: primaryDept( c ) || 0 } ) } ),
		},
		{
			name: 'service_id',
			label: 'Catalog item',
			type: 'remote',
			required: true,
			wide: true,
			remote: { resource: 'services', label: ( s ) => s.name, params: () => ( { status: 'active', kind: 'recurring' } ) },
			// Copy catalogue defaults into the form (new records only) so they can be overridden per client.
			onPick: ( s, v ) => {
				const patch = {};
				if ( ! v.id ) {
					patch.cost = s.default_cost;
					patch.client_price = s.default_price;
					patch.service_pricing = s.pricing;
					patch.service_subject_label = s.subject_label;
					patch.service_unit = s.unit_label;
					patch.quantity = 1;
					patch.unit_price = s.pricing === 'unit' ? s.default_price : 0;
					patch.unit_cost = s.pricing === 'unit' ? s.default_cost : 0;
					patch.billing_period = s.default_billing_period;
					patch.billing_treatment = s.default_billing_treatment;
					if ( s.department_id ) {
						patch.department_id = s.department_id;
					}
					if ( s.default_currency ) {
						patch.currency = s.default_currency;
					}
				}
				return patch;
			},
		},
		{ name: 'subject', label: ( v ) => v.service_subject_label || 'What is it for?', placeholder: 'e.g. acme.com', wide: true, help: 'Shown on bills, invoices and reminders so you always know exactly what the client is paying for.' },
		deptField( 'Which department earns this subscription or retainer. Defaults to the catalog item’s, else the client’s.' ),
		{ name: 'provider', label: 'Provider', placeholder: 'e.g. Namecheap, Google' },
		{ name: 'billing_treatment', label: 'How it’s billed', type: 'select', options: () => options( 'billing_treatment' ) },
		{ name: 'status', label: 'Status', type: 'select', required: true, options: SUB_STATUSES },
		{ name: 'quantity', label: 'Quantity', type: 'number', step: '1', show: ( v ) => v.service_pricing === 'unit', help: 'How many units, e.g. 12 email accounts.' },
		{ name: 'unit_price', label: 'Client price per unit', type: 'number', show: ( v ) => v.service_pricing === 'unit', help: 'Billed each cycle: quantity × unit price.' },
		{ name: 'unit_cost', label: 'Our cost per unit', type: 'number', show: ( v ) => v.service_pricing === 'unit' },
		{ name: 'cost', label: 'Our cost per cycle', type: 'number', show: ( v ) => v.service_pricing !== 'unit' },
		{ name: 'client_price', label: 'Client price per cycle', type: 'number', show: ( v ) => v.service_pricing !== 'unit' },
		{ name: 'currency', label: 'Currency', type: 'select', required: true, options: currencyOptions },
		{ name: 'start_date', label: 'Start date', type: 'date' },
		{ name: 'billing_period', label: 'Repeats', type: 'select', required: true, options: () => options( 'billing_period' ).filter( ( o ) => o.value !== 'none' ) },
		{ name: 'interval_days', label: 'Repeat every (days)', type: 'number', step: '1', show: ( v ) => v.billing_period === 'custom' },
		{ name: 'next_renewal_date', label: 'Next due date', type: 'date', help: 'Creates / updates the open cycle automatically.' },
		{ name: 'notes', label: 'Notes', type: 'textarea', wide: true },
	],
} );

const OPEN = 'upcoming,due';
export const isOpenRenewal = ( r ) => [ 'upcoming', 'due' ].includes( r.status );

export const renewalsConfig = ( { embedded, fixed = {}, initialStatus = OPEN } = {} ) => ( {
	title: 'Bills',
	description: 'Each bill of a subscription or retainer — past and coming up.',
	resource: 'renewals',
	singular: 'bill',
	newLabel: 'Add bill',
	selectable: true,
	isSelectable: ( r ) => isOpenRenewal( r ) && ! r.invoice_id,
	initialFilters: { status: initialStatus },
	searchable: ! fixed.client_service_id,
	filters: [
		{
			name: 'status',
			label: 'Status',
			options: [
				{ value: OPEN, label: 'Open (coming up + due)' },
				{ value: 'renewed', label: 'Done' },
				{ value: 'skipped,cancelled', label: 'Skipped / cancelled' },
			],
		},
		...( fixed.client_service_id ? [] : [ { name: 'frequency', label: 'Repeats', options: options( 'billing_period' ) } ] ),
	],
	defaults: { status: 'upcoming' },
	actionsWidth: 96,
	actions: ( r, ctx ) => [
		isOpenRenewal( r )
			? {
				label: 'Done — next bill',
				type: 'primary',
				onClick: async () => {
					try {
						await ElMessageBox.confirm( `Mark this bill for ${ r.service_name } (${ r.client_name }) as done? The next bill is scheduled automatically.`, 'Done — schedule next bill', { type: 'info', confirmButtonText: 'Done' } );
					} catch ( e ) {
						return;
					}
					try {
						const res = await api.post( `renewals/${ r.id }/mark-renewed` );
						ElMessage.success( res.next ? `Done. Next bill due ${ fmtDate( res.next.due_date ) }.` : 'Done.' );
						ctx.reload();
					} catch ( e ) {
						ElMessage.error( errorMessage( e ) );
					}
				},
			}
			: null,
	],
	columns: [
		{
			prop: 'due_date',
			label: 'Due',
			minWidth: 140,
			render: ( r ) => {
				const late = isOpenRenewal( r ) && r.due_date < today();
				return h( 'span', { class: late ? 'bd-late' : '' }, fmtDate( r.due_date ) + ( late ? ' · overdue' : '' ) );
			},
		},
		...( embedded ? [] : [ { prop: 'client_name', label: 'Client', type: 'client', minWidth: 160 } ] ),
		...( fixed.client_service_id ? [] : [ {
			prop: 'service_name',
			label: 'Service',
			minWidth: 170,
			render: ( r ) => h( 'div', [ h( 'div', { class: 'bd-strong' }, r.subject || r.service_name ), h( 'div', { class: 'bd-muted bd-small' }, [ r.subject ? r.service_name : '', labelOf( 'billing_period', r.frequency ) ].filter( Boolean ).join( ' · ' ) ) ] ),
		} ] ),
		{
			label: 'Price',
			align: 'right',
			minWidth: 120,
			render: ( r ) => h( 'div', [ h( 'div', { class: 'bd-money bd-strong' }, fmtMoney( r.price, r.currency ) ), h( 'div', { class: 'bd-muted bd-small bd-money' }, 'cost ' + fmtMoney( r.cost, r.currency ) ) ] ),
		},
		{
			label: 'Status',
			minWidth: 140,
			render: ( r ) => h( 'div', [ h( BillStatus, { status: r.bill_status } ), r.invoice_number ? h( 'a', { class: 'bd-link bd-small bd-block', href: `#/billing/invoices/${ r.invoice_id }` }, r.invoice_number ) : r.status === 'renewed' ? h( 'div', { class: 'bd-muted bd-small' }, 'Renewed' ) : null ] ),
		},
	],
	fields: [
		{
			name: 'client_service_id',
			label: 'Subscription or retainer',
			type: 'remote',
			required: true,
			wide: true,
			remote: { resource: 'client-services', label: ( c ) => `${ c.subject || c.service_name } — ${ c.client_name }`, params: () => ( fixed.client_id ? { client_id: fixed.client_id } : {} ) },
			onPick: ( cs ) => ( { client_id: cs.client_id, frequency: cs.billing_period, cost: cs.cost, price: cs.client_price, currency: cs.currency } ),
		},
		{ name: 'due_date', label: 'Due date', type: 'date', required: true },
		{ name: 'frequency', label: 'Frequency', type: 'select', options: () => options( 'billing_period' ) },
		{ name: 'interval_days', label: 'Repeat every (days)', type: 'number', step: '1', show: ( v ) => v.frequency === 'custom' },
		{ name: 'cost', label: 'Our cost', type: 'number' },
		{ name: 'price', label: 'Client price', type: 'number' },
		{ name: 'currency', label: 'Currency', type: 'select', required: true, options: currencyOptions },
		{ name: 'status', label: 'Status', type: 'select', required: true, options: opts( [ 'upcoming', 'due', 'renewed', 'skipped', 'cancelled' ] ) },
		{ name: 'notes', label: 'Notes', type: 'textarea', wide: true },
	],
} );

const INVOICE_STATUSES = opts( [ 'draft', 'sent', 'due', 'partially_paid', 'paid', 'overdue', 'cancelled' ] );

export const invoicesConfig = ( { hideClient, fixed = {}, onPayments, onCopyLink, onSend, onEmail } = {} ) => ( {
	title: 'Invoices',
	description: 'Build, share and track invoices — one-off, recurring or in instalments.',
	resource: 'invoices',
	bulkDepartment: true,
	singular: 'invoice',
	newLabel: 'New invoice',
	onNew: () => navigate( '/billing/invoices/new' + ( fixed.client_id ? `?client=${ fixed.client_id }` : '' ) ),
	onEdit: ( r ) => navigate( `/billing/invoices/${ r.id }` ),
	filters: [ { name: 'status', label: 'Status', options: INVOICE_STATUSES }, ...( fixed.client_id ? [] : [ deptFilter() ] ) ],
	actionsWidth: 96,
	actions: ( r, ctx ) => [
		{ label: 'Payments', onClick: () => onPayments( r ) },
		r.status !== 'cancelled' ? { label: 'Email invoice…', menu: true, onClick: () => onEmail( r ) } : null,
		{ label: 'Copy link', menu: true, onClick: () => onCopyLink( r ) },
		r.status === 'draft' ? { label: 'Mark as sent', menu: true, onClick: () => onSend( r, ctx ) } : null,
		! [ 'paid', 'cancelled', 'draft' ].includes( r.status )
			? {
				label: 'Mark paid',
				menu: true,
				onClick: async () => {
					try {
						await ElMessageBox.confirm( `Record the outstanding balance as paid for ${ r.invoice_number }?`, 'Mark paid', { type: 'info', confirmButtonText: 'Mark paid' } );
					} catch ( e ) {
						return;
					}
					try {
						await api.create( 'payments', { invoice_id: r.id, amount: r.balance, paid_on: today(), reference: 'Marked as paid' } );
						ElMessage.success( 'Invoice marked paid' );
						ctx.reload();
					} catch ( e ) {
						ElMessage.error( errorMessage( e ) );
					}
				},
			}
			: null,
	],
	columns: [
		{
			prop: 'invoice_number',
			label: 'Invoice',
			minWidth: 105,
			render: ( r ) => {
				const note = r.viewed_at ? 'Viewed' : r.sent_at ? 'Sent' : null;
				return h( 'div', [ h( 'a', { class: 'bd-strong bd-link', href: `#/billing/invoices/${ r.id }` }, r.invoice_number ), note ? h( 'div', { class: 'bd-muted bd-small' }, note ) : null ] );
			},
		},
		{ prop: 'title', label: 'For', minWidth: 170, render: ( r ) => h( 'div', [ r.title ? h( 'div', { class: 'bd-strong' }, r.title ) : muted( '—' ), r.department_name ? h( 'div', { class: 'bd-muted bd-small' }, r.department_name ) : null ] ) },
		...( hideClient ? [] : [ { prop: 'client_name', label: 'Client', minWidth: 170, render: ( r ) => ( r.client_id ? h( 'a', { class: 'bd-link', href: `#/clients/${ r.client_id }` }, r.client_name || `#${ r.client_id }` ) : muted( '—' ) ) } ] ),
		{
			prop: 'due_date',
			label: 'Due',
			minWidth: 150,
			render: ( r ) => h( 'div', [ h( 'div', { class: r.status === 'overdue' ? 'bd-late' : '' }, fmtDate( r.due_date ) ), h( 'div', { class: 'bd-muted bd-small' }, 'issued ' + fmtDate( r.issue_date ) ) ] ),
		},
		{
			label: 'Amount',
			align: 'right',
			minWidth: 140,
			render: ( r ) => h( 'div', [ h( 'div', { class: 'bd-money' }, fmtMoney( r.amount, r.currency ) ), Number( r.amount_paid ) > 0 ? h( 'div', { class: 'bd-muted bd-small bd-money' }, 'paid ' + fmtMoney( r.amount_paid, r.currency ) ) : null ] ),
		},
		{ label: 'Balance', align: 'right', minWidth: 120, render: ( r ) => ( r.status === 'cancelled' ? muted( '—' ) : h( 'strong', { class: 'bd-money' }, fmtMoney( r.balance, r.currency ) ) ) },
		{ prop: 'status', label: 'Status', type: 'status', minWidth: 120 },
	],
	fields: [],
} );

export const activityConfig = ( { embedded } = {} ) => ( {
	title: 'Activity',
	description: 'A running history of everything that changes in BizyDash.',
	resource: 'activity',
	singular: 'entry',
	readOnly: true,
	perPage: embedded ? 10 : 30,
	emptyText: 'No activity yet.',
	filters: embedded
		? []
		: [ { name: 'entity_type', label: 'Type', options: [ 'clients', 'client-services', 'renewals', 'charges', 'invoices', 'payments', 'brands', 'services' ].map( ( t ) => ( { value: t, label: t === 'brands' ? dept().many : humanize( t ) } ) ) } ],
	columns: [
		{ prop: 'created_at', label: 'When', type: 'datetime', minWidth: 140 },
		...( embedded ? [] : [ { prop: 'client_name', label: 'Client', type: 'client', minWidth: 160 } ] ),
		{ prop: 'summary', label: 'What happened', minWidth: 300 },
		{ prop: 'user_name', label: 'By' },
	],
} );

export const lookupsConfig = ( type ) => ( {
	resource: 'lookups',
	singular: 'option',
	newLabel: 'Add option',
	searchable: false,
	perPage: 100,
	defaults: { is_active: true, sort_order: 50, color: '#6b7280', months: 0 },
	columns: [
		{ prop: 'sort_order', label: '#', width: 60 },
		{ prop: 'label', label: 'Label', type: 'strong', minWidth: 160 },
		{ prop: 'item_key', label: 'Key', type: 'code' },
		{ prop: 'color', label: 'Colour', type: 'swatch', width: 80 },
		...( type === 'billing_period' ? [ { prop: 'months', label: 'Months / cycle', width: 130 } ] : [] ),
		{ prop: 'is_active', label: 'Active', type: 'yesno', width: 80 },
		{ prop: 'is_system', label: 'Built-in', render: ( r ) => ( r.is_system ? 'Yes' : '' ), width: 90 },
	],
	fields: [
		{ name: 'label', label: 'Label', required: true },
		{ name: 'sort_order', label: 'Order', type: 'number', step: '1' },
		{ name: 'color', label: 'Colour', type: 'color' },
		{ name: 'months', label: 'Months per cycle', type: 'number', step: '1', show: () => type === 'billing_period', help: '0 for one-time / custom (custom uses a day interval per service).' },
		{ name: 'is_active', label: 'Active', type: 'checkbox', checkLabel: 'Available in dropdowns' },
	],
} );

export const paymentsConfig = () => ( {
	title: 'Payments',
	resource: 'payments',
	singular: 'payment',
	readOnly: true,
	perPage: 20,
	emptyText: 'No payments recorded yet.',
	columns: [
		{ prop: 'paid_on', label: 'Date', type: 'date', minWidth: 120 },
		{ prop: 'client_name', label: 'Client', type: 'client', minWidth: 190 },
		{ prop: 'invoice_number', label: 'Invoice', type: 'strong' },
		{ prop: 'amount', label: 'Amount', type: 'money', align: 'right' },
		{ prop: 'reference', label: 'Reference', minWidth: 160 },
	],
} );

/** People at a client: primary contacts receive invoices (To); secondary contacts are copied (Cc). */
export const contactsConfig = () => ( {
	resource: 'contacts',
	singular: 'contact',
	newLabel: 'Add contact',
	searchable: false,
	perPage: 50,
	emptyText: 'No contacts yet — add the people who should receive invoices.',
	defaults: { kind: 'secondary' },
	actionsWidth: 120,
	actions: ( r, ctx ) => [
		{
			label: r.kind === 'primary' ? 'Make secondary' : 'Make primary',
			menu: true,
			onClick: async () => {
				try {
					await api.update( 'contacts', r.id, { kind: r.kind === 'primary' ? 'secondary' : 'primary' } );
					ElMessage.success( 'Updated' );
					ctx.reload();
				} catch ( e ) {
					ElMessage.error( errorMessage( e ) );
				}
			},
		},
	],
	columns: [
		{
			label: 'Contact',
			minWidth: 230,
			render: ( r ) =>
				h( 'div', { class: 'bd-cell-client' }, [
					h( 'span', { class: 'bd-ava', style: avatarStyle( r.name ) }, initials( r.name ) ),
					h( 'div', [ h( 'div', { class: 'bd-strong' }, r.name ), r.job_title ? h( 'div', { class: 'bd-muted bd-small' }, r.job_title ) : null ] ),
				] ),
		},
		{ prop: 'email', label: 'Email', minWidth: 220 },
		{ prop: 'phone', label: 'Phone', minWidth: 130 },
		{
			label: 'Invoice emails',
			minWidth: 170,
			render: ( r ) => h( 'span', { class: [ 'bd-badge', r.kind === 'primary' ? 'bd-badge--info' : 'bd-badge--neutral' ] }, r.kind === 'primary' ? 'Primary · receives' : 'Secondary · copied' ),
		},
	],
	fields: [
		{ name: 'name', label: 'Full name', required: true, wide: true },
		{ name: 'job_title', label: 'Job title', placeholder: 'e.g. Accountant, COO' },
		{ name: 'phone', label: 'Phone' },
		{ name: 'email', label: 'Email', type: 'email', required: true, wide: true },
		{
			name: 'kind',
			label: 'Role on invoices',
			type: 'select',
			required: true,
			wide: true,
			options: [
				{ value: 'primary', label: 'Primary — receives the invoice email' },
				{ value: 'secondary', label: 'Secondary — copied (Cc) on invoice emails' },
			],
			help: 'A client can have several primary contacts (for example the accountant and the COO).',
		},
	],
} );

/** Your own team members who can be copied on invoice emails. */
const KIND_LABEL = { employee: 'Employee', contractor: 'Contractor' };
const mapCell = ( map ) => ( map && Object.keys( map ).length ? h( MoneyMap, { map, empty: '—' } ) : muted( '—' ) );

export const teamFields = () => [
	{ name: 'name', label: 'Name', required: true, wide: true },
	{ name: 'email', label: 'Email', type: 'email', required: true },
	{ name: 'role', label: 'Role', placeholder: 'e.g. Designer, Account manager' },
	{ name: 'kind', label: 'Type', type: 'select', options: [ { value: 'employee', label: 'Employee (salary)' }, { value: 'contractor', label: 'Contractor (paid per project or task)' } ] },
	{ name: 'department_id', label: dept().one, type: 'remote', remote: { resource: 'brands', label: ( b ) => b.name, params: () => ( { status: 'active' } ) } },
	{ name: 'salary_amount', label: 'Salary', type: 'number', show: ( v ) => store.canFinance && v.kind !== 'contractor', help: 'Per pay period. Leave 0 if they are only paid per project.' },
	{ name: 'salary_period', label: 'Paid', type: 'select', options: [ { value: 'monthly', label: 'Monthly' } ], show: ( v ) => store.canFinance && v.kind !== 'contractor' },
	{ name: 'currency', label: 'Pay currency', type: 'select', options: currencyOptions, show: () => store.canFinance },
	{ name: 'start_date', label: 'Start date', type: 'date', show: () => store.canFinance },
	{ name: 'end_date', label: 'End date', type: 'date', show: () => store.canFinance, help: 'Leave empty while they are still with you.' },
	{ name: 'bank_name', label: 'Bank', show: () => store.canFinance },
	{ name: 'account_name', label: 'Account name', show: () => store.canFinance },
	{ name: 'account_number', label: 'Account number', show: () => store.canFinance },
	{ name: 'cc_invoices', label: 'Invoice emails', type: 'checkbox', checkLabel: 'Copy (Cc) this person on every invoice email', wide: true },
	{ name: 'status', label: 'Status', type: 'select', required: true, options: ACTIVE_STATUSES },
	{ name: 'notes', label: 'Notes', type: 'textarea', wide: true },
];

/** The department a bill, task or contract belongs to (empty = follow its client / catalog item / project). */
const deptField = ( help ) => ( { name: 'department_id', label: dept().one, type: 'remote', remote: { resource: 'brands', label: ( b ) => b.name, params: () => ( { status: 'active' } ) }, help: help || '' } );
/** Client's primary department, from the client record the picker returns. */
const primaryDept = ( c ) => {
	const b = ( c && c.brands ) || [];
	const p = b.find( ( x ) => x.is_primary ) || b[ 0 ];
	return p ? p.id : 0;
};

/** A department filter for list toolbars (options are loaded from the departments list). */
const deptFilter = () => ( { name: 'department_id', label: dept().one, remote: { resource: 'brands', label: ( b ) => b.name, params: { status: 'active' } } } );

export const teamConfig = () => ( {
	title: 'Team',
	description: 'The people you work with — what they are doing and what they are owed.',
	resource: 'team',
	bulkDepartment: true,
	singular: 'team member',
	newLabel: 'Add team member',
	emptyText: 'No team members yet. Add the people who work with you — they can be given tasks and paid for the work.',
	initialFilters: { status: 'active' },
	filters: [ { name: 'kind', label: 'Type', options: [ { value: 'employee', label: 'Employees' }, { value: 'contractor', label: 'Contractors' } ] }, { name: 'status', label: 'Status', options: ACTIVE_STATUSES }, deptFilter() ],
	defaults: { status: 'active', kind: 'employee', cc_invoices: false, currency: defaultCurrency(), salary_period: 'monthly' },
	onEdit: ( r ) => navigate( `/team/${ r.id }` ),
	actionsWidth: 110,
	actions: ( r, ctx ) => [ ! r.is_me ? { label: 'This is me', menu: true, onClick: async () => { try { await api.update( 'team', r.id, { wp_user_id: store.userId } ); ElMessage.success( 'Linked — “My tasks” now shows your work.' ); ctx.reload(); } catch ( e ) { ElMessage.error( errorMessage( e ) ); } } } : null ],
	columns: [
		{
			label: 'Person',
			minWidth: 250,
			render: ( r ) =>
				h( 'div', { class: 'bd-cell-client' }, [
					h( 'span', { class: 'bd-ava', style: avatarStyle( r.name ) }, initials( r.name ) ),
					h( 'div', [
						h( 'div', [ h( 'a', { class: 'bd-strong bd-link', href: `#/team/${ r.id }` }, r.name ), r.is_me ? h( 'span', { class: 'bd-badge bd-badge--success bd-badge--plain bd-ml' }, 'You' ) : null ] ),
						h( 'div', { class: 'bd-muted bd-small' }, [ r.role, KIND_LABEL[ r.kind ] ].filter( Boolean ).join( ' · ' ) ),
					] ),
				] ),
		},
		{ label: dept().one, minWidth: 200, render: ( r ) => ( r.department_name ? h( 'span', { class: 'bd-badge bd-badge--neutral bd-badge--plain' }, r.department_name ) : muted( '—' ) ) },
		{ label: 'Work', minWidth: 150, render: ( r ) => ( ! r.open_tasks && ! r.active_projects ? muted( 'Nothing open' ) : h( 'div', [ h( 'div', `${ r.open_tasks } open task${ r.open_tasks === 1 ? '' : 's' }` ), h( 'div', { class: ( r.overdue_tasks ? 'bd-late' : 'bd-muted' ) + ' bd-small' }, r.overdue_tasks ? `${ r.overdue_tasks } overdue` : `${ r.active_projects } project${ r.active_projects === 1 ? '' : 's' }` ) ] ) ) },
		...( store.canFinance ? [
			{ label: 'Salary', minWidth: 130, render: ( r ) => ( Number( r.salary_amount ) > 0 ? h( 'div', [ h( 'div', { class: 'bd-money' }, fmtMoney( r.salary_amount, r.currency || defaultCurrency() ) ), h( 'div', { class: 'bd-muted bd-small' }, 'per month' ) ] ) : muted( r.kind === 'contractor' ? 'Per project' : '—' ) ) },
			{ label: 'Owed now', align: 'right', minWidth: 130, render: ( r ) => mapCell( r.owed ) },
			{ label: 'Paid this year', align: 'right', minWidth: 130, render: ( r ) => mapCell( r.paid_year ) },
		] : [] ),
		{ prop: 'status', label: 'Status', type: 'status' },
	],
	fields: teamFields(),
} );

/* ───────────── Work: projects + tasks ───────────── */
const pill = ( def, text ) => h( 'span', { class: [ 'bd-badge', 'bd-badge--' + ( def ? def.tone : 'neutral' ) ] }, text || ( def ? def.label : '' ) );
const person = ( name ) => ( name ? h( 'div', { class: 'bd-cell-client' }, [ h( 'span', { class: 'bd-ava', style: avatarStyle( name ) }, initials( name ) ), h( 'span', name ) ] ) : muted( 'Unassigned' ) );
const dueCell = ( r ) => {
	if ( ! r.due_date ) {
		return muted( '—' );
	}
	const days = Math.round( ( new Date( r.due_date + 'T00:00:00' ) - new Date( today() + 'T00:00:00' ) ) / 86400000 );
	const open = r.status !== 'done';
	return h( 'div', [ h( 'div', fmtDate( r.due_date ) ), open ? h( 'div', { class: ( days < 0 ? 'bd-late' : 'bd-muted' ) + ' bd-small' }, relDays( days ) ) : null ] );
};

export const setTaskStatus = async ( r, status, reload ) => {
	try {
		await api.update( 'tasks', r.id, { status } );
		reload && reload();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};

export const taskFields = ( { fixed = {} } = {} ) => [
	{ name: 'title', label: 'Task', required: true, wide: true, placeholder: 'What needs to be done?' },
	{ name: 'client_id', label: 'Client', type: 'remote', wide: true, remote: { resource: 'clients', label: ( c ) => c.company_name, params: () => ( { status: 'active' } ) }, help: 'Optional — leave empty for an internal task.' },
	{ name: 'project_id', label: 'Project', type: 'remote', wide: true, remote: { resource: 'projects', label: ( p ) => p.name + ( p.client_name ? ' — ' + p.client_name : '' ), params: ( v ) => ( v && v.client_id ? { client_id: v.client_id } : {} ) }, onPick: ( p ) => ( p.client_id ? { client_id: p.client_id } : {} ) },
	{ name: 'phase_id', label: 'Phase', type: 'remote', remote: { resource: 'project-phases', label: ( p ) => p.name, params: ( v ) => ( v && v.project_id ? { project_id: v.project_id } : null ) }, show: ( v ) => !! v.project_id, help: 'Which phase of the project this task belongs to.' },
	{ name: 'assignee_id', label: 'Assigned to', type: 'remote', remote: { resource: 'team', label: ( t ) => t.name, params: () => ( { status: 'active' } ) } },
	deptField( 'Optional. Leave empty to follow the phase or project.' ),
	{ name: 'due_date', label: 'Due date', type: 'date' },
	{ name: 'pay_amount', label: 'Pay for this task', type: 'number', show: () => store.canFinance, help: 'Optional. When the task is marked done, this amount is added to what the assignee is owed.' },
	{ name: 'priority', label: 'Priority', type: 'select', options: [ { value: 'normal', label: 'Normal' }, { value: 'high', label: 'High' } ] },
	{ name: 'status', label: 'Status', type: 'select', required: true, options: TASK_STATUS_OPTIONS },
	{ name: 'client_service_id', label: 'Linked subscription (optional)', type: 'remote', wide: true, remote: { resource: 'client-services', label: ( c ) => `${ c.subject || c.service_name } — ${ c.client_name }`, params: ( v ) => ( v && v.client_id ? { client_id: v.client_id } : {} ) }, help: 'For example a domain to renew.' },
	{ name: 'notes', label: 'Notes', type: 'textarea', wide: true },
].filter( ( f ) => ! ( f.name in fixed ) );

export const tasksConfig = ( { hideClient, hideProject, fixed = {} } = {} ) => ( {
	title: 'Tasks',
	description: 'Everything that needs doing — yours and your team’s.',
	resource: 'tasks',
	bulkDepartment: true,
	singular: 'task',
	newLabel: 'Add task',
	emptyText: 'No tasks here yet. Add one above, or press “Add task”.',
	initialFilters: {},
	filters: [
		{ name: 'status', label: 'Status', options: TASK_STATUS_OPTIONS },
		{ name: 'due', label: 'Due', options: [ { value: 'overdue', label: 'Overdue' }, { value: 'today', label: 'Due today or earlier' }, { value: 'week', label: 'Due this week' } ] },
		{ name: 'priority', label: 'Priority', options: [ { value: 'high', label: 'High priority' } ] },
		...( fixed.client_id || fixed.project_id ? [] : [ deptFilter() ] ),
	],
	defaults: { status: 'todo', priority: 'normal', ...fixed },
	actionsWidth: 120,
	actions: ( r, ctx ) => [
		r.status === 'todo' ? { label: 'Start', onClick: () => setTaskStatus( r, 'doing', ctx.reload ) } : null,
		r.status !== 'done' ? { label: 'Done', type: 'primary', onClick: () => setTaskStatus( r, 'done', ctx.reload ) } : { label: 'Reopen', onClick: () => setTaskStatus( r, 'todo', ctx.reload ) },
	],
	columns: [
		{
			label: 'Task',
			minWidth: 280,
			render: ( r ) =>
				h( 'div', [
					h( 'div', [ h( 'span', { class: r.status === 'done' ? 'bd-strong bd-done' : 'bd-strong' }, r.title ), r.priority === 'high' ? h( 'span', { class: 'bd-badge bd-badge--danger bd-badge--plain bd-ml' }, 'High' ) : null ] ),
					h( 'div', { class: 'bd-muted bd-small' }, [ ! hideProject && r.project_name ? r.project_name : '', r.phase_name || '', ! hideClient && r.client_name ? r.client_name : '', r.subscription_subject || r.subscription_name || '' ].filter( Boolean ).join( ' · ' ) ),
				] ),
		},
		{ label: 'Due', minWidth: 130, render: dueCell },
		{ label: 'Assigned to', minWidth: 160, render: ( r ) => person( r.assignee_name ) },
		{ label: 'Status', minWidth: 100, render: ( r ) => pill( TASK_STATUS[ r.status ] ) },
	],
	fields: taskFields( { fixed } ),
} );

export const projectsConfig = ( { hideClient, fixed = {} } = {} ) => ( {
	title: 'Projects',
	description: 'Bigger pieces of work, with their tasks inside.',
	resource: 'projects',
	bulkDepartment: true,
	singular: 'project',
	newLabel: 'New project',
	emptyText: 'No projects yet. Start one for a website build, a campaign, a rebrand…',
	filters: [ { name: 'status', label: 'Status', options: PROJECT_STATUS_OPTIONS }, ...( fixed.client_id ? [] : [ deptFilter() ] ) ],
	defaults: { status: 'active', ...fixed },
	onEdit: ( r ) => navigate( `/work/projects/${ r.id }` ),
	columns: [
		{ label: 'Project', minWidth: 260, render: ( r ) => h( 'div', [ h( 'a', { class: 'bd-strong bd-link', href: `#/work/projects/${ r.id }` }, r.name ), ! hideClient && r.client_name ? h( 'div', { class: 'bd-muted bd-small' }, r.client_name ) : null ] ) },
		{ label: 'Status', minWidth: 110, render: ( r ) => pill( PROJECT_STATUS[ r.status ] ) },
		{
			label: 'Progress',
			minWidth: 190,
			render: ( r ) =>
				h( 'div', { class: 'bd-prog' }, [
					h( 'div', { class: 'bd-prog__bar' }, [ h( 'span', { style: { width: r.progress + '%' } } ) ] ),
					h( 'div', { class: 'bd-muted bd-small' }, r.task_total ? `${ r.task_done } of ${ r.task_total } tasks done` : 'No tasks yet' ),
				] ),
		},
		{ label: 'Due', minWidth: 130, render: ( r ) => ( r.due_date ? h( 'div', [ h( 'div', fmtDate( r.due_date ) ), r.status !== 'done' ? h( 'div', { class: ( r.is_overdue ? 'bd-late' : 'bd-muted' ) + ' bd-small' }, relDays( Math.round( ( new Date( r.due_date + 'T00:00:00' ) - new Date( today() + 'T00:00:00' ) ) / 86400000 ) ) ) : null ] ) : muted( '—' ) ) },
		{ label: 'Owner', minWidth: 150, render: ( r ) => person( r.owner_name ) },
	],
	fields: projectFields( { fixed } ),
} );

export const projectFields = ( { fixed = {} } = {} ) => [
	{ name: 'name', label: 'Project name', required: true, wide: true, placeholder: 'e.g. Acme website redesign' },
	{ name: 'client_id', label: 'Client', type: 'remote', wide: true, remote: { resource: 'clients', label: ( c ) => c.company_name, params: () => ( { status: 'active' } ) }, help: 'Optional — leave empty for an internal project.' },
	{ name: 'department_id', label: dept().one, type: 'remote', remote: { resource: 'brands', label: ( b ) => b.name, params: () => ( { status: 'active' } ) } },
	{ name: 'status', label: 'Status', type: 'select', required: true, options: PROJECT_STATUS_OPTIONS },
	{ name: 'price', label: 'Project price (what the client pays)', type: 'number', help: 'Optional. Used for project profit.' },
	{ name: 'currency', label: 'Currency', type: 'select', options: currencyOptions },
	{ name: 'owner_id', label: 'Owner', type: 'remote', remote: { resource: 'team', label: ( t ) => t.name, params: () => ( { status: 'active' } ) } },
	{ name: 'start_date', label: 'Start date', type: 'date' },
	{ name: 'due_date', label: 'Due date', type: 'date' },
	{ name: 'description', label: 'What is this project about?', type: 'textarea', wide: true },
].filter( ( f ) => ! ( f.name in fixed ) );


/* ───────────── Finance: expenses ───────────── */
const PERIODS = () => options( 'billing_period' ).filter( ( o ) => ! [ 'none', 'custom' ].includes( o.value ) );

export const expenseFields = () => [
	{ name: 'description', label: 'What was it for?', required: false, wide: true, placeholder: 'e.g. Figma team plan, October rent, Facebook ads' },
	{ name: 'vendor', label: 'Paid to', placeholder: 'e.g. Figma, Landlord' },
	{ name: 'category', label: 'Category', type: 'select', required: true, options: () => options( 'expense_category' ) },
	{ name: 'amount', label: 'Amount', type: 'number', required: true },
	{ name: 'currency', label: 'Currency', type: 'select', options: currencyOptions },
	{ name: 'spent_on', label: 'Date', type: 'date' },
	{ name: 'department_id', label: dept().one, type: 'remote', remote: { resource: 'brands', label: ( b ) => b.name, params: () => ( { status: 'active' } ) }, help: 'Leave empty for a general business cost.' },
	{ name: 'method', label: 'Paid by', type: 'select', options: PAYMENT_METHODS_OPTS() },
	{ name: 'reference', label: 'Reference / receipt number' },
	{ name: 'client_id', label: 'Client (optional)', type: 'remote', remote: { resource: 'clients', label: ( c ) => c.company_name, params: () => ( { status: 'active' } ) }, help: 'Only if this cost is for one client.' },
	{ name: 'attachment_id', label: 'Receipt', type: 'attachment', urlKey: 'attachment_url', wide: true },
	{ name: 'notes', label: 'Notes', type: 'textarea', wide: true },
];
function PAYMENT_METHODS_OPTS() {
	return [ 'Bank transfer', 'Card', 'Cash', 'Cheque', 'Mobile money', 'Other' ].map( ( m ) => ( { value: m, label: m } ) );
}

export const expensesConfig = ( { fixed = {} } = {} ) => ( {
	title: 'Expenses',
	description: 'Everything the business spends money on.',
	resource: 'expenses',
	bulkDepartment: true,
	singular: 'expense',
	newLabel: 'Add expense',
	emptyText: 'No expenses in this period. Add one above.',
	filters: [ { name: 'category', label: 'Category', options: options( 'expense_category' ) }, deptFilter() ],
	defaults: { category: ( options( 'expense_category' )[ 0 ] || {} ).value || '', currency: defaultCurrency(), spent_on: today(), method: 'Bank transfer', ...fixed },
	columns: [
		{ label: 'Date', minWidth: 110, render: ( r ) => fmtDate( r.spent_on ) },
		{
			label: 'Expense',
			minWidth: 260,
			render: ( r ) =>
				h( 'div', [
					h( 'div', [ h( 'span', { class: 'bd-strong' }, r.description || r.vendor || 'Expense' ), r.is_recurring ? h( 'span', { class: 'bd-badge bd-badge--info bd-badge--plain bd-ml' }, 'Recurring' ) : null ] ),
					h( 'div', { class: 'bd-muted bd-small' }, [ r.description ? r.vendor : '', r.reference ].filter( Boolean ).join( ' · ' ) ),
				] ),
		},
		{ prop: 'category', label: 'Category', type: 'lookup', lookup: 'expense_category', minWidth: 150 },
		{ label: dept().one, minWidth: 200, render: ( r ) => ( r.department_name ? h( 'span', { class: 'bd-badge bd-badge--neutral bd-badge--plain' }, r.department_name ) : muted( 'General' ) ) },
		{ label: 'Amount', align: 'right', minWidth: 130, render: ( r ) => h( 'div', [ h( 'div', { class: 'bd-money bd-strong' }, fmtMoney( r.amount, r.currency ) ), r.attachment_url ? h( 'a', { class: 'bd-link bd-small', href: r.attachment_url, target: '_blank', rel: 'noreferrer' }, 'Receipt' ) : null ] ) },
	],
	fields: expenseFields(),
} );

export const recurringExpensesConfig = () => ( {
	title: 'Recurring expenses',
	description: 'Costs that repeat — they post themselves on the due date.',
	resource: 'recurring-expenses',
	singular: 'recurring expense',
	newLabel: 'Add recurring expense',
	emptyText: 'Nothing recurring yet. Add rent, software, internet — anything you pay on a schedule.',
	filters: [ { name: 'category', label: 'Category', options: options( 'expense_category' ) } ],
	defaults: { category: ( options( 'expense_category' )[ 0 ] || {} ).value || '', currency: defaultCurrency(), period: 'monthly', status: 'active', next_date: today() },
	columns: [
		{ label: 'Expense', minWidth: 240, render: ( r ) => h( 'div', [ h( 'div', { class: 'bd-strong' }, r.name ), r.vendor ? h( 'div', { class: 'bd-muted bd-small' }, r.vendor ) : null ] ) },
		{ prop: 'category', label: 'Category', type: 'lookup', lookup: 'expense_category', minWidth: 150 },
		{ label: 'Repeats', minWidth: 110, render: ( r ) => labelOf( 'billing_period', r.period ) },
		{ label: 'Amount', align: 'right', minWidth: 130, render: ( r ) => h( 'div', [ h( 'div', { class: 'bd-money bd-strong' }, fmtMoney( r.amount, r.currency ) ), h( 'div', { class: 'bd-muted bd-small bd-money' }, fmtMoney( r.monthly_value, r.currency ) + ' / month' ) ] ) },
		{ label: 'Next posts', minWidth: 130, render: ( r ) => ( r.next_date ? h( 'div', [ h( 'div', fmtDate( r.next_date ) ), h( 'div', { class: 'bd-muted bd-small' }, relDays( r.days_until ) ) ] ) : muted( '—' ) ) },
		{ prop: 'status', label: 'Status', type: 'status' },
	],
	fields: [
		{ name: 'name', label: 'What is it?', required: true, wide: true, placeholder: 'e.g. Office rent, Google Workspace, Internet' },
		{ name: 'vendor', label: 'Paid to' },
		{ name: 'category', label: 'Category', type: 'select', required: true, options: () => options( 'expense_category' ) },
		{ name: 'amount', label: 'Amount each time', type: 'number', required: true },
		{ name: 'currency', label: 'Currency', type: 'select', options: currencyOptions },
		{ name: 'period', label: 'Repeats', type: 'select', required: true, options: PERIODS },
		{ name: 'next_date', label: 'Next date', type: 'date', required: true, help: 'The expense is added automatically on this date, then every period after.' },
		{ name: 'department_id', label: dept().one, type: 'remote', remote: { resource: 'brands', label: ( b ) => b.name, params: () => ( { status: 'active' } ) } },
		{ name: 'status', label: 'Status', type: 'select', required: true, options: [ { value: 'active', label: 'Active' }, { value: 'paused', label: 'Paused' } ] },
		{ name: 'notes', label: 'Notes', type: 'textarea', wide: true },
	],
} );


/* ───────────── Finance: what the team is owed and has been paid ───────────── */
const ITEM_KIND = { payment: 'Payment', phase: 'Project phase', task: 'Task', salary: 'Salary', bonus: 'Bonus', deduction: 'Deduction', reimbursement: 'Reimbursement', adjustment: 'Adjustment' };
const ITEM_STATUS = { payable: { label: 'Owed', tone: 'warning' }, paid: { label: 'Paid', tone: 'success' }, void: { label: 'Voided', tone: 'neutral' } };

export const payItemFields = ( { member = null } = {} ) => [
	...( member ? [] : [ { name: 'team_member_id', label: 'Who is it for?', type: 'remote', required: true, wide: true, remote: { resource: 'team', label: ( m ) => m.name, params: () => ( { status: 'active' } ) }, onPick: ( m ) => ( { currency: m.currency || undefined } ) } ] ),
	{ name: 'kind', label: 'Type', type: 'select', required: true, options: [ { value: 'payment', label: 'Payment' }, { value: 'bonus', label: 'Bonus' }, { value: 'reimbursement', label: 'Reimbursement (expenses they covered)' }, { value: 'deduction', label: 'Deduction (advance, loan repayment…)' }, { value: 'adjustment', label: 'Adjustment' } ] },
	{
		name: 'task_id',
		label: 'Task (optional)',
		type: 'remote',
		wide: true,
		placeholder: 'Attach this to a task — project and phase follow it',
		remote: { resource: 'tasks', label: ( t ) => t.title + ( t.project_name ? ' — ' + t.project_name : '' ), params: ( v ) => ( ( member ? member.id : v.team_member_id ) ? { assignee_id: member ? member.id : v.team_member_id } : {} ) },
		onPick: ( t, v ) => ( { ...( ! member && ! v.team_member_id && t.assignee_id ? { team_member_id: t.assignee_id } : {} ), project_id: t.project_id || 0, phase_id: t.phase_id || 0, description: v.description || t.title, _where: [ t.project_name, t.phase_name ].filter( Boolean ).join( ' · ' ) } ),
		help: ( v ) => ( v._where ? 'Counts toward: ' + v._where : v.task_id ? 'Tied to this task.' : 'Leave empty for a payment that is not tied to a task.' ),
	},
	{ name: 'description', label: 'What is it for?', required: true, wide: true, placeholder: 'e.g. Logo design, Launch bonus, Transport refund' },
	{ name: 'amount', label: 'Amount', type: 'number', required: true, help: 'Enter a positive number — deductions are subtracted automatically.' },
	{ name: 'currency', label: 'Currency', type: 'select', options: currencyOptions, help: 'Defaults to their pay currency.' },
	{ name: 'earned_on', label: 'Date', type: 'date' },
];

export const payItemsConfig = ( { fixed = {} } = {} ) => ( {
	title: 'Earnings',
	description: 'What this person has earned — owed until it is paid.',
	resource: 'pay-items',
	singular: 'earning',
	newLabel: 'Add payment',
	emptyText: 'Nothing earned yet. Pay appears here when a phase or task with pay is marked done.',
	selectable: true,
	isSelectable: ( r ) => r.status === 'payable',
	initialFilters: { status: 'payable' },
	filters: [ { name: 'status', label: 'Status', options: [ { value: 'payable', label: 'Owed' }, { value: 'paid', label: 'Paid' }, { value: 'void', label: 'Voided' } ] } ],
	defaults: { kind: 'payment', currency: defaultCurrency(), earned_on: today(), ...fixed },
	actionsWidth: 80,
	onEdit: () => ElMessage.info( 'Earnings are not edited — void this one and add a new one if something changed.' ),
	actions: ( r, ctx ) => [
		r.status === 'payable' ? { label: 'Void', menu: true, onClick: async () => {
			try {
				await ElMessageBox.confirm( 'Void this earning? It will no longer be owed. (It stays in the books as voided.)', 'Void earning', { type: 'warning', confirmButtonText: 'Void' } );
			} catch ( e ) {
				return;
			}
			try {
				await api.update( 'pay-items', r.id, { status: 'void' } );
				ctx.reload();
			} catch ( e ) {
				ElMessage.error( errorMessage( e ) );
			}
		} } : null,
	],
	columns: [
		{ label: 'Earned', minWidth: 110, render: ( r ) => fmtDate( r.earned_on ) },
		{ label: 'What', minWidth: 280, render: ( r ) => h( 'div', [ h( 'div', [ h( 'span', { class: r.status === 'void' ? 'bd-strong bd-done' : 'bd-strong' }, r.description ), h( 'span', { class: 'bd-badge bd-badge--neutral bd-badge--plain bd-ml' }, ITEM_KIND[ r.kind ] || r.kind ) ] ), h( 'div', { class: 'bd-muted bd-small' }, [ r.project_name, r.phase_name, r.task_title && r.kind !== 'task' ? 'Task: ' + r.task_title : '' ].filter( Boolean ).join( ' · ' ) ) ] ) },
		{ label: 'Amount', align: 'right', minWidth: 130, render: ( r ) => h( 'span', { class: Number( r.amount ) < 0 ? 'bd-money bd-late' : 'bd-money bd-strong' }, fmtMoney( r.amount, r.currency ) ) },
		{ label: 'Status', minWidth: 130, render: ( r ) => h( 'div', [ pill( ITEM_STATUS[ r.status ] ), r.payout_number ? h( 'div', { class: 'bd-muted bd-small' }, r.payout_number ) : null ] ) },
	],
	fields: payItemFields(),
} );

export const payoutsConfig = ( { fixed = {} } = {} ) => ( {
	title: 'Payouts',
	description: 'Money paid out to the team.',
	resource: 'payouts',
	singular: 'payout',
	readOnly: true,
	emptyText: 'No payouts yet.',
	filters: [ { name: 'status', label: 'Status', options: [ { value: 'paid', label: 'Paid' }, { value: 'void', label: 'Voided' } ] } ],
	actionsWidth: 120,
	actions: ( r, ctx ) => [
		{ label: 'Payslip', onClick: () => window.open( `${ store.homeUrl }?bd_payslip=${ r.id }`, '_blank', 'noopener' ) },
		r.status === 'paid' ? { label: 'Void', menu: true, onClick: async () => {
			try {
				await ElMessageBox.confirm( `Void ${ r.number }? The items go back to “owed” so you can pay them again.`, 'Void payout', { type: 'warning', confirmButtonText: 'Void payout' } );
			} catch ( e ) {
				return;
			}
			try {
				await api.post( `payouts/${ r.id }/void` );
				ctx.reload();
			} catch ( e ) {
				ElMessage.error( errorMessage( e ) );
			}
		} } : null,
	],
	columns: [
		{ label: 'Payout', minWidth: 120, render: ( r ) => h( 'div', [ h( 'div', { class: r.status === 'void' ? 'bd-strong bd-done' : 'bd-strong' }, r.number ), h( 'div', { class: 'bd-muted bd-small' }, fmtDate( r.paid_on ) ) ] ) },
		...( fixed.team_member_id ? [] : [ { label: 'Paid to', minWidth: 160, render: ( r ) => person( r.member_name ) } ] ),
		{ label: 'How', minWidth: 160, render: ( r ) => h( 'div', [ h( 'div', r.method || '—' ), r.reference ? h( 'div', { class: 'bd-muted bd-small' }, r.reference ) : null ] ) },
		{ label: 'Items', minWidth: 80, render: ( r ) => `${ r.item_count } item${ r.item_count === 1 ? '' : 's' }` },
		{ label: 'Amount', align: 'right', minWidth: 130, render: ( r ) => h( 'span', { class: 'bd-money bd-strong' }, fmtMoney( r.amount, r.currency ) ) },
		{ label: 'Status', minWidth: 100, render: ( r ) => pill( r.status === 'void' ? { label: 'Voided', tone: 'neutral' } : { label: 'Paid', tone: 'success' } ) },
	],
	fields: [],
} );


/* ───────────── Contracts & templates ───────────── */
export const contractsConfig = ( { fixed = {} } = {} ) => ( {
	title: 'Contracts',
	description: 'Agreements your clients sign online — with the proof to back them up.',
	resource: 'contracts',
	bulkDepartment: true,
	singular: 'contract',
	newLabel: 'New contract',
	emptyText: 'No contracts yet. Create one from a template and send it for signature.',
	filters: [ { name: 'status', label: 'Status', options: CONTRACT_STATUS_OPTIONS }, ...( fixed.client_id ? [] : [ deptFilter() ] ) ],
	defaults: { ...fixed },
	onNew: () => navigate( '/contracts/new' + ( fixed.client_id ? `?client=${ fixed.client_id }` : '' ) ),
	onEdit: ( r ) => navigate( `/contracts/${ r.id }` ),
	columns: [
		{ label: 'Contract', minWidth: 280, render: ( r ) => h( 'div', [ h( 'a', { class: 'bd-strong bd-link', href: `#/contracts/${ r.id }` }, r.title ), h( 'div', { class: 'bd-muted bd-small' }, [ r.number, fixed.client_id ? '' : r.client_name, r.department_name ].filter( Boolean ).join( ' · ' ) ) ] ) },
		{ label: 'Status', minWidth: 160, render: ( r ) => pill( CONTRACT_STATUS[ r.status ] ) },
		{ label: 'Signatures', minWidth: 130, render: ( r ) => ( r.signer_total ? h( 'span', { class: r.signer_done === r.signer_total ? '' : 'bd-muted' }, `${ r.signer_done } of ${ r.signer_total } signed` ) : muted( '—' ) ) },
		{ label: 'Sent', minWidth: 120, render: ( r ) => ( r.sent_at ? fmtDate( r.sent_at ) : muted( 'Not sent' ) ) },
		{ label: 'Completed', minWidth: 120, render: ( r ) => ( r.completed_at ? fmtDate( r.completed_at ) : muted( '—' ) ) },
	],
	fields: [],
} );

export const CONTRACT_FIELDS = [ 'client_name', 'client_contact', 'client_email', 'client_address', 'company', 'company_address', 'date', 'contract_title', 'contract_number' ];

export const contractTemplatesConfig = () => ( {
	title: 'Templates',
	description: 'Reusable agreements. Insert fields like the client name and they fill in automatically.',
	resource: 'contract-templates',
	singular: 'template',
	newLabel: 'New template',
	emptyText: 'No templates yet. Add your standard service agreement, NDA or proposal terms.',
	filters: [],
	defaults: { is_active: true, body: '' },
	columns: [
		{ label: 'Template', minWidth: 300, render: ( r ) => h( 'div', [ h( 'div', { class: 'bd-strong' }, r.name ), r.description ? h( 'div', { class: 'bd-muted bd-small' }, r.description ) : null ] ) },
		{ label: 'Status', minWidth: 100, render: ( r ) => pill( r.is_active ? { label: 'Active', tone: 'success' } : { label: 'Hidden', tone: 'neutral' } ) },
	],
	fields: [
		{ name: 'name', label: 'Template name', required: true, wide: true, placeholder: 'e.g. Website design agreement' },
		{ name: 'description', label: 'When do you use it?', wide: true },
		deptField( 'Contracts made from this template start in this department.' ),
		{ name: 'body', label: 'Agreement text', type: 'richtext', wide: true, fields: CONTRACT_FIELDS, minHeight: 360, importable: true, help: 'Use “Insert a field” so names and dates fill in for each client.' },
		{ name: 'is_active', label: 'Available', type: 'checkbox', checkLabel: 'Show in the template list' },
	],
} );
