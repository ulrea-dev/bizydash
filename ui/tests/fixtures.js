export const settings = {
	company_name: 'Digital Zooks', default_currency: 'NGN',
	currencies: [ { code: 'NGN', symbol: '₦', name: 'Naira' }, { code: 'USD', symbol: '$', name: 'US Dollar' } ],
	due_soon_days: 30, invoice_prefix: 'INV-', invoice_next: 6, default_due_days: 14, delete_on_uninstall: false,
	payment_methods: [
		{ id: 'pm1', type: 'bank', title: 'GTBank — Naira', currency: 'NGN', enabled: true, bank_name: 'GTBank', account_name: 'Digital Zooks Ltd', account_number: '0123456789', extra_label: '', extra_value: '', url: '', button_label: '', note: '' },
		{ id: 'pm2', type: 'link', title: 'Pay by card', currency: '', enabled: true, bank_name: '', account_name: '', account_number: '', extra_label: '', extra_value: '', url: 'https://buy.stripe.com/x', button_label: '', note: '' },
	],
};
const lk = ( key, label, color = '', months = 0 ) => ( { id: Math.random(), key, label, color, months, sort_order: 0, is_active: true, is_system: true } );
export const lookups = {
	client_type: [ lk( 'retainer', 'Retainer', '#2563eb' ), lk( 'adhoc', 'Ad-hoc', '#7c3aed' ) ],
	client_status: [ lk( 'active', 'Active' ), lk( 'paused', 'Paused' ), lk( 'inactive', 'Inactive' ) ],
	brand_type: [ lk( 'product', 'Product' ), lk( 'service', 'Service' ) ],
	billing_period: [ lk( 'none', 'One-time' ), lk( 'monthly', 'Monthly', '', 1 ), lk( 'annual', 'Annually', '', 12 ), lk( 'custom', 'Custom' ) ],
	expense_category: [ lk( 'software', 'Software & tools' ), lk( 'rent', 'Rent & utilities' ), lk( 'marketing', 'Marketing & ads' ) ],
	billing_treatment: [ lk( 'included_in_retainer', 'Included in retainer' ), lk( 'separately_billed', 'Separately billed' ) ],
};
export const meta = { settings, lookups, enums: {} };

export const client = { id: 1, company_name: 'ABC Company (Demo)', contact_person: 'Ada Obi', email: 'ada@abc.example', phone: '0800', address: '', notes: 'VIP client', client_type: 'retainer', status: 'active', currency: 'NGN', retainer_amount: 200000, retainer_period: 'monthly', brands: [ { id: 2, name: 'Digital Zooks Agency (Demo)', type: 'service', is_primary: true } ], brand_ids: [ 2 ], contacts: [ { id: 1, name: 'Ada Obi', email: 'ada@abc.example', phone: '0800', job_title: 'COO', kind: 'primary' }, { id: 2, name: 'Ngozi Eze', email: 'ngozi@abc.example', phone: '', job_title: 'Accountant', kind: 'secondary' } ] };
export const brand = { id: 2, name: 'Digital Zooks Agency (Demo)', type: 'service', description: 'Web design', logo_attachment_id: 0, logo_url: '', status: 'active' };
export const product = { id: 8, kind: 'one_off', type: 'product', pricing: 'unit', unit_label: 'image', name: 'Product photography', description: '', default_billing_period: 'none', default_cost: 3000, default_price: 6500, default_billing_treatment: '', default_currency: 'NGN', status: 'active' };
export const service = { id: 3, type: 'service', pricing: 'fixed', unit_label: '', kind: 'recurring', name: 'Web hosting', description: '', default_billing_period: 'annual', default_cost: 90000, default_price: 120000, default_billing_treatment: 'separately_billed', default_currency: 'NGN', status: 'active' };
export const clientServiceUnit = { id: 9, service_type: 'product', service_pricing: 'unit', service_unit: 'account', quantity: 12, unit_price: 18000, unit_cost: 9000, is_retainer: false, mrr: 18000, next_cycle: null, client_id: 1, service_id: 8, client_name: 'ABC Company (Demo)', service_name: 'Business email accounts', provider: '', status: 'active', cost: 108000, client_price: 216000, currency: 'NGN', billing_treatment: 'separately_billed', billing_period: 'monthly', interval_days: 0, next_renewal_date: '2026-11-01', notes: '' };
export const clientService = { id: 4, subject: 'acme-demo.com', service_subject_label: 'Website / domain', service_type: 'product', service_pricing: 'fixed', service_unit: '', quantity: 1, unit_price: 0, unit_cost: 0, is_retainer: false, mrr: 10000, next_cycle: { id: 5, due_date: '2026-12-01', price: 120000, currency: 'NGN', invoice_id: 0, invoice_number: null, bill_status: 'not_invoiced' }, client_id: 1, service_id: 3, client_name: 'ABC Company (Demo)', service_name: 'Web hosting', provider: 'Hostinger', status: 'active', cost: 90000, client_price: 120000, currency: 'NGN', billing_treatment: 'separately_billed', billing_period: 'annual', interval_days: 0, next_renewal_date: '2026-12-01', start_date: '2026-01-01', notes: '' };
export const renewal = { id: 5, bill_status: 'not_invoiced', invoice_number: null, client_service_id: 4, client_id: 1, client_name: 'ABC Company (Demo)', service_name: 'Web hosting', due_date: '2026-10-08', frequency: 'annual', interval_days: 0, cost: 90000, price: 120000, currency: 'NGN', status: 'upcoming', invoice_id: 0, pending_charge_ids: [ 6 ], pending_charges: [ { id: 6, price: 25000 } ], pending_total: 25000, total_to_bill: 145000 };
export const charge = { id: 6, bill_status: 'not_invoiced', invoice_number: null, client_id: 1, client_name: 'ABC Company (Demo)', client_service_id: 4, service_name: 'Web hosting', renewal_id: 5, description: '3 additional email users', cost: 15000, price: 25000, margin: 10000, currency: 'NGN', incurred_on: '2026-09-22', billing_timing: 'next_renewal', bill_on_date: '2026-10-08', status: 'pending', invoice_id: 0, notes: '' };
export const invoiceBase = { id: 7, client_id: 1, client_name: 'ABC Company (Demo)', invoice_number: 'INV-0001', title: 'Website redesign and hosting', description: 'Retainer', amount: 200000, amount_paid: 80000, balance: 120000, currency: 'NGN', issue_date: '2026-09-29', due_date: '2026-10-13', status: 'partially_paid', invoice_url: 'https://example.com/i/1', attachment_id: 0, attachment_url: '', notes: '', items: [], payments: [ { id: 9, invoice_id: 7, amount: 80000, currency: 'NGN', paid_on: '2026-09-30', reference: 'Part payment' } ] };
export const invoice = {
	...invoiceBase,
	subtotal: 200000, discount_type: 'none', discount_value: 0, discount_amount: 0, tax_rate: 0, tax_amount: 0, terms: 'Pay on time.', public_url: 'https://example.com/?bd_invoice=abc', viewed_at: '2026-09-30 10:00:00', view_count: 2, sent_at: '2026-09-29 09:00:00',
	has_installments: true,
	items: [ { id: 1, description: 'Website design', quantity: '1.00', unit_price: '150000.00', amount: '150000.00', source_type: 'manual', source_id: 0, client_service_id: 0, period_start: null, period_end: null, service_name: null }, { id: 2, description: 'Hosting renewal', quantity: '1.00', unit_price: '50000.00', amount: '50000.00', source_type: 'renewal', source_id: 5, client_service_id: 4, period_start: '2026-12-01', period_end: '2027-11-30', service_name: 'Web hosting' } ],
	payments: [ { id: 9, invoice_id: 7, amount: 80000, currency: 'NGN', paid_on: '2026-09-30', method: 'Bank transfer', reference: 'Part payment' } ],
	schedule: [ { id: 1, label: 'Deposit', due_date: '2026-10-05', percent: 50, amount: 100000, paid: 80000, balance: 20000, state: 'partial', implicit: false }, { id: 2, label: 'Final payment', due_date: '2026-11-05', percent: 50, amount: 100000, paid: 0, balance: 100000, state: 'upcoming', implicit: false } ],
};
export const activity = { id: 10, client_id: 1, client_name: 'ABC Company (Demo)', entity_type: 'invoices', action: 'created', summary: 'Invoice INV-0001 created from 2 item(s)', user_name: 'Admin', created_at: '2026-09-29 10:15:00' };

const page = ( items ) => ( { items, total: items.length, pages: 1 } );
export const dashboard = {
	stats: { active_clients: 4, renewals_due: 7, pending_charges: { NGN: { amount: 89000, count: 4 }, USD: { amount: 89, count: 1 } }, outstanding: { NGN: { amount: 255000, count: 2 } }, due_this_month: { NGN: { amount: 120000, count: 1 } }, overdue: { NGN: { amount: 135000, count: 1 } }, overdue_count: 1 },
	renewals: [ { id: 5, client_id: 1, client_name: 'ABC Company (Demo)', service_name: 'Web hosting', due_date: '2026-10-08', price: 120000, currency: 'NGN', status: 'upcoming' } ],
	charges: [ { id: 6, client_id: 1, client_name: 'ABC Company (Demo)', description: '3 additional email users', price: 25000, currency: 'NGN', bill_on_date: '2026-10-08' } ],
	invoices: [ { id: 7, client_id: 1, client_name: 'XYZ Logistics (Demo)', invoice_number: 'INV-0003', amount: 135000, amount_paid: 0, currency: 'NGN', due_date: '2026-09-22', status: 'overdue' } ],
	invoices_title: 'Overdue invoices',
	finance: { profit_month: { NGN: { income: 1500000, direct: 300000, keep: 1200000, team: 500000, expenses: 78000, profit: 622000, margin_pct: 41 } }, owed_team: { NGN: { amount: 599500, count: 7 } } },
	todo_total: 3,
	todo: [
		{ kind: 'invoice', key: 'invoice-7', title: 'INV-0003 — Monthly retainer', sub: 'XYZ Logistics (Demo) · not paid yet', client_id: 2, days: -10, amount: 135000, currency: 'NGN', action: { label: 'Send reminder', to: '/billing/invoices/7?send=1' } },
		{ kind: 'bill', key: 'bill-renewal-5', title: 'Domain registration — acme-demo.com', sub: 'ABC Company (Demo) · ready to invoice', client_id: 1, days: 3, amount: 15000, currency: 'NGN', action: { label: 'Invoice it', to: '/billing/invoices/new?client=1&bill=renewal-5' } },
		{ kind: 'task', key: 'task-5', title: 'Send brand brief to client', sub: 'ABC Company (Demo) · Zara Team', client_id: 1, days: -1, priority: 'high', action: { label: 'Open', to: '/work/projects/3' } },
	],
	coming_up: [
		{ key: 'bill-renewal-5', title: 'Web hosting — acme-demo.com', sub: 'ABC Company (Demo)', client_id: 1, date: '2026-10-06', days: 4, amount: 120000, currency: 'NGN', type: 'renewal' },
		{ key: 'bill-renewal-9', title: 'SSL certificate — acme-demo.com', sub: 'ABC Company (Demo)', client_id: 1, date: '2026-10-20', days: 18, amount: 25000, currency: 'NGN', type: 'renewal' },
	],
	setup: { profile: true, payment: true, client: true, catalog: true, invoice: false },
	due_soon_days: 30,
	billing: {
		pipeline: { overdue: { NGN: { amount: 120000, count: 1 } }, ready: { NGN: { amount: 25000, count: 1 }, USD: { amount: 89, count: 1 } }, scheduled: { NGN: { amount: 300000, count: 3 } }, unscheduled: {} },
		awaiting: { NGN: { amount: 120000, count: 1 } },
		overdue: { NGN: { amount: 135000, count: 1 } },
		to_invoice: { one_off: { NGN: { amount: 25000, count: 1 } }, recurring: { NGN: { amount: 120000, count: 1 } } },
		mrr: { NGN: { amount: 41000, count: 4 } },
		arr: { NGN: { amount: 492000, count: 4 } },
		collected: { NGN: { amount: 80000, count: 1 } },
		attention: [
			{ kind: 'invoice', ref_id: 3, client_id: 2, client_name: 'XYZ Logistics (Demo)', label: 'Invoice INV-0003', date: '2026-09-22', days: -9, amount: 135000, currency: 'NGN', state: 'overdue_payment' },
			{ kind: 'renewal', ref_id: 5, client_id: 1, client_name: 'ABC Company (Demo)', label: 'Web hosting renewal', date: '2026-09-25', days: -6, amount: 120000, currency: 'NGN', state: 'overdue_to_bill' },
			{ kind: 'charge', ref_id: 6, client_id: 1, client_name: 'ABC Company (Demo)', label: '3 additional email users', date: '2026-10-08', days: 7, amount: 25000, currency: 'NGN', state: 'ready_to_bill' },
		],
	},
	activity: [ { id: 10, client_id: 1, client_name: 'ABC Company (Demo)', summary: 'Invoice INV-0001 created', action: 'created', entity_type: 'invoices', created_at: '2026-09-29 10:15:00' } ],
	due_soon_days: 30,
};
export const chart = { months: [ '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10' ], labels: [ 'May 2026', 'Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026', 'Oct 2026' ], currencies: { NGN: { billed: [ 0, 0, 0, 0, 335000, 0 ], paid: [ 0, 0, 0, 0, 200000, 0 ] }, USD: { billed: [ 0, 0, 0, 0, 450, 0 ], paid: [ 0, 0, 0, 0, 0, 0 ] } } };

/** path (without query) → response factory */
export const routes = {
	'/meta': () => meta,
	'/dashboard': () => dashboard,
	'/dashboard/chart': () => chart,
	'/preferences': () => ( { theme: 'light' } ),
	'/search': () => [ { type: 'client', id: 1, client_id: 1, label: 'ABC Company (Demo)', sub: 'Ada Obi' } ],
	'/brands': () => page( [ brand ] ),
	'/expenses': () => page( [ expense, { ...expense, id: 2, description: 'October office rent', vendor: 'Landlord Ltd', category: 'rent', amount: 250000, recurring_id: 3, is_recurring: true } ] ),
	'/expenses/summary': () => ( { this_month: { NGN: { amount: 268000, count: 2 } }, last_month: { NGN: { amount: 240000, count: 3 } }, recurring_monthly: { NGN: { amount: 260000, count: 2 } }, by_category: { rent: { NGN: { amount: 250000, count: 1 } }, software: { NGN: { amount: 18000, count: 1 } } } } ),
	'/recurring-expenses': () => page( [ { id: 3, name: 'Office rent', vendor: 'Landlord Ltd', category: 'rent', amount: 250000, currency: 'NGN', period: 'monthly', monthly_value: 250000, next_date: '2026-11-01', days_until: 30, status: 'active' } ] ),
	'/projects': () => page( [ project ] ),
	'/work/overview': () => workOverview,
	'/contracts/overview': () => contractsOverview,
	'/contracts': () => page( [ contract ] ),
	'/contracts/5': () => contract,
	'/contracts/6': () => contractDraft,
	'/contracts/5/audit': () => contractAudit,
	'/contract-templates': () => page( [ { id: 1, name: 'NDA', description: 'Mutual NDA', body: '<p>x</p>', is_active: 1 } ] ),
	'/project-phases': () => page( [ phase1, { ...phase1, id: 2, name: 'Build', status: 'todo', price: 1200000, team_budget: 400000, allocated: 0, allocations: [], over_budget: false, task_total: 3, task_done: 0 } ] ),
	'/payroll': () => payroll,
	'/finance/overview': () => overview,
	'POST /payroll/run': () => ( { period: '2026-10', created: 2, skipped: 0 } ),
	'/pay-items': () => page( [ payItem, { ...payItem, id: 12, description: 'Launch bonus', kind: 'bonus', amount: 20000, project_name: null, phase_name: null } ] ),
	'/payouts': () => page( [ { id: 4, number: 'PAY-0001', team_member_id: 1, member_name: 'Zara Team', paid_on: '2026-09-30', method: 'Bank transfer', reference: 'TRF-1', amount: 300000, currency: 'NGN', status: 'paid', item_count: 2 } ] ),
	'POST /payouts/create': () => ( { id: 5, number: 'PAY-0002' } ),
	'/projects/3': () => project,
	'/tasks': () => page( [ task, task2 ] ),
	'/services': () => page( [ service, product ] ),
	'/clients': () => page( [ client ] ),
	'/clients/1': () => client,
	'/client-services': () => page( [ clientService, clientServiceUnit ] ),
	'/client-services/4': () => clientService,
	'/renewals': () => page( [ renewal ] ),
	'/charges': () => page( [ charge ] ),
	'/invoices': () => page( [ invoice ] ),
	'/invoices/7': () => invoice,
	'POST /invoices': () => invoice,
	'/clients/1/unbilled': () => [ { key: 'charge-6', type: 'charge', ref_id: 6, client_id: 1, client_name: 'ABC Company (Demo)', client_service_id: 4, label: '3 additional email users', sub: 'Web hosting', date: '2026-10-08', days: 7, price: 100000, cost: 15000, currency: 'NGN', state: 'ready', period_start: null, period_end: null } ],
	'/activity': () => page( [ activity ] ),
	'/lookups': () => page( lookups.client_type.map( ( l, i ) => ( { id: i + 1, type: 'client_type', item_key: l.key, label: l.label, color: l.color, months: 0, sort_order: i, is_active: true, is_system: true } ) ) ),
	'/billing/summary': () => ( {
		kind: 'one_off',
		statuses: { not_invoiced: { NGN: { amount: 25000, count: 1 } }, awaiting: { NGN: { amount: 120000, count: 2 } }, overdue: { NGN: { amount: 135000, count: 1 } }, paid: { NGN: { amount: 60000, count: 3 } }, waived: {} },
		mrr: { NGN: { amount: 41000, count: 4 } },
	} ),
	'/contacts': () => page( client.contacts.map( ( c ) => ( { ...c, client_id: 1 } ) ) ),
	'/team': () => page( [ teamMember ] ),
	'/team/1': () => teamMember,
	'/invoices/7/email-defaults': () => ( {
		invoice_number: 'INV-0001', status: 'draft', client_name: 'ABC Company (Demo)',
		to: [ { name: 'Ada Obi', email: 'ada@abc.example', job_title: 'COO' } ],
		cc: [ { name: 'Ngozi Eze', email: 'ngozi@abc.example', job_title: 'Accountant', source: 'contact' }, { name: 'Zara Team', email: 'zara@dz.example', job_title: 'Account manager', source: 'team' } ],
		contacts: [ { name: 'Ada Obi', email: 'ada@abc.example', job_title: 'COO' }, { name: 'Ngozi Eze', email: 'ngozi@abc.example', job_title: 'Accountant' } ],
		team: [ { name: 'Zara Team', email: 'zara@dz.example', job_title: 'Account manager' } ],
		subject: 'Invoice INV-0001 from Digital Zooks', message: 'Hi Ada,\n\nPlease find invoice INV-0001.', from: { name: 'Digital Zooks', email: 'billing@dz.example', reply_to: '' },
		method: 'wp', bcc_self: false, user_email: 'me@dz.example', link: 'https://example.com/?bd_invoice=abc',
	} ),
	'POST /invoices/7/email-preview': () => ( { html: '<html><body><h1>Amount due ₦120,000.00</h1></body></html>', subject: 'Invoice INV-0001 from Digital Zooks' } ),
	'POST /invoices/7/send': () => ( { ok: true, to: [ 'ada@abc.example' ], cc: [ 'ngozi@abc.example', 'zara@dz.example' ] } ),
	'/invoices/7/emails': () => [ { id: 1, type: 'invoice', status: 'sent', subject: 'Invoice INV-0001 from Digital Zooks', to_emails: 'ada@abc.example', cc_emails: 'ngozi@abc.example', error: '', created_at: '2026-09-30 10:00:00' } ],
	'/mail-settings': () => ( { method: 'wp', from_name: '', from_email: '', reply_to: '', smtp_host: '', smtp_port: 587, smtp_secure: 'tls', smtp_auth: true, smtp_user: '', smtp_pass_set: false, invoice_subject: 'Invoice {{invoice_number}} from {{business_name}}', invoice_message: 'Hi {{contact_name}},', bcc_self: false, resolved_from: { name: 'Digital Zooks', email: 'billing@dz.example', reply_to: '' } } ),
	'POST /mail-settings': () => ( { method: 'wp', smtp_pass_set: false, invoice_subject: 'x', invoice_message: 'y', resolved_from: {} } ),
	'POST /mail-settings/test': () => ( { ok: true, via: 'the site mailer (wp_mail)', to: 'me@dz.example' } ),
	'/payments': () => page( [ { id: 9, invoice_id: 7, client_id: 1, client_name: 'ABC Company (Demo)', invoice_number: 'INV-0001', amount: 80000, currency: 'NGN', paid_on: '2026-09-30', reference: 'Part payment' } ] ),
	'/finance/analytics': () => ( {
		range: { from: '2026-07-04', to: '2026-10-02' }, granularity: 'week', view: 'all', department: null, bases: { income: 'invoiced', pay: 'earned' }, base: 'NGN', rates: { USD: 1500 }, missing: [], currencies: [ 'NGN', 'USD' ],
		summary: { NGN: { income: 1500000, direct: 400000, keep: 1100000, team: 300000, expenses: 100000, profit: 700000, margin_pct: 47 }, USD: { income: 900, direct: 100, keep: 800, team: 0, expenses: 50, profit: 750, margin_pct: 83 } },
		buckets: [ 'Jul 6', 'Jul 13', 'Jul 20' ],
		series: { NGN: { income: [ 100, 200, 300 ], costs: [ 50, 60, 70 ], profit: [ 50, 140, 230 ] }, USD: { income: [ 10, 20, 30 ], costs: [ 5, 5, 5 ], profit: [ 5, 15, 25 ] } },
		departments: [ { id: 2, name: 'Digital Zooks Agency (Demo)', values: { NGN: { income: 1000000, keep: 800000, profit: 500000 }, USD: { income: 900, keep: 800, profit: 750 } } } ],
		clients: [ { id: 1, name: 'ABC Company (Demo)', currency: 'NGN', income: 900000, keep: 700000 } ],
		projects: [ { id: 3, name: 'Website redesign', client_name: 'ABC Company (Demo)', currency: 'NGN', price: 2000000, team_cost: 400000, margin: 1600000 } ],
		categories: [ { category: 'software', values: { NGN: 18000 }, sort: 18000 } ],
		ageing: { NGN: { current: 100000, d30: 50000, d60: 0, d61: 20000 } },
		recurring: { income: { NGN: { amount: 41000, count: 4 } }, costs: { NGN: { amount: 20000, count: 1 } } },
		mix: [ { currency: 'NGN', amount: 1500000, share_pct: 52.6 }, { currency: 'USD', amount: 900, share_pct: 47.4 } ],
	} ),
	'/work/analytics': () => ( {
		range: { from: '2026-07-04', to: '2026-10-02' }, granularity: 'week', department: null,
		stats: { open: 7, overdue: 2, created: 12, completed: 9, on_time: 6, with_due: 8, avg_cycle_days: 4.5, median_cycle_days: 3, on_time_pct: 75 },
		buckets: [ 'Jul 6', 'Jul 13', 'Jul 20' ], flow: { created: [ 3, 4, 5 ], completed: [ 2, 3, 4 ] },
		open_status: { todo: 5, doing: 2 }, open_priority: { normal: 6, high: 1 },
		people: [ { id: 1, name: 'Zara Team', open: 4, overdue: 1, done: 5, avg_cycle_days: 3.2 }, { id: 0, name: 'Unassigned', open: 3, overdue: 1, done: 0, avg_cycle_days: null } ],
		departments: [ { id: 2, name: 'Digital Zooks Agency (Demo)', open: 7, overdue: 2, done: 9 } ],
		schedule: { on_track: 2, late: 1, no_date: 0 },
		projects: [ { id: 3, name: 'Website redesign', client_name: 'ABC Company (Demo)', due_date: '2026-09-20', total: 4, done: 1, progress: 25, state: 'late', days_late: 12 } ],
		money: [ { id: 2, name: 'Digital Zooks Agency (Demo)', values: { NGN: { price: 800000, budget: 600000, allocated: 510000, earned: 150000, paid: 0 } } } ],
	} ),
	'/contracts/analytics': () => ( {
		range: { from: '2026-07-04', to: '2026-10-02' }, granularity: 'month', department: null,
		stats: { sent: 8, signed: 5, waiting: 2, declined_void: 1, completion_pct: 71, cohort_pending: 1, avg_hours: 30, median_hours: 20, signer_avg_hours: 26 },
		buckets: [ 'Jul 2026', 'Aug 2026', 'Sep 2026' ], flow: { sent: [ 2, 3, 3 ], signed: [ 1, 2, 2 ] },
		funnel: { invited: 10, opened: 9, consented: 7, verified: 6, signed: 5 },
		outcomes: { signed: 5, waiting: 1, declined: 1, void: 0, expired: 1 },
		slowest_clients: [ { id: 1, name: 'ABC Company (Demo)', signed: 2, avg_hours: 70 } ],
		waiting: [ { id: 4, client_name: 'XYZ Logistics (Demo)', sent_at: '2026-09-20 10:00:00', days_waiting: 12, waiting_on: 'Chidi Obi' } ],
		templates: [ { name: 'Service agreement', sent: 6, signed: 4, rate_pct: 67, avg_hours: 28 } ],
		departments: [ { id: 2, name: 'Digital Zooks Agency (Demo)', sent: 8, signed: 5, declined: 1 } ],
	} ),
	'/clients/analytics': () => ( {
		range: { from: '2026-07-04', to: '2026-10-02' }, granularity: 'month', department: null,
		stats: { total: 9, active: 7, new: 3, quiet: 1 }, buckets: [ 'Jul 2026', 'Aug 2026', 'Sep 2026' ], new_series: [ 1, 0, 2 ],
		by_type: { retainer: 5, adhoc: 4 }, by_status: { active: 7, paused: 1, inactive: 1 },
		departments: [ { id: 2, name: 'Digital Zooks Agency (Demo)', clients: 6 }, { id: 0, name: 'No department', clients: 3 } ],
		quiet: [ { id: 4, name: 'Northwind Ltd (Demo)', last_invoice: '2026-01-15' } ],
		money: { top: [ { id: 1, name: 'ABC Company (Demo)', currency: 'NGN', income: 900000, share_pct: 64.3 } ], concentration: { NGN: { top1_pct: 64.3, top5_pct: 100, clients: 2 } }, avg_per_client: { NGN: 700000 },
			mrr: [ { currency: 'NGN', total: 41000, clients: 2, top: [ { id: 1, name: 'ABC Company (Demo)', amount: 30000 } ] } ],
			overdue: [ { id: 2, name: 'XYZ Logistics (Demo)', currency: 'NGN', balance: 135000, days_late: 12 } ] },
	} ),
	'/team/analytics': () => ( {
		range: { from: '2026-07-04', to: '2026-10-02' }, granularity: 'month', department: null,
		stats: { active: 6, joined: 1, employees: 4, contractors: 2, open: 9, done: 7 }, kinds: { employee: 4, contractor: 2 },
		departments: [ { id: 2, name: 'Digital Zooks Agency (Demo)', people: 4 } ],
		people: [ { id: 1, name: 'Zara Team', kind: 'employee', department: 'Digital Zooks Agency (Demo)', open: 4, overdue: 1, projects: 2, done: 5, avg_cycle_days: 3.2, earned: { NGN: 150000 }, owed: { NGN: 100000 }, paid: {} } ],
		buckets: [ 'Jul 2026', 'Aug 2026', 'Sep 2026' ],
		money: { salary_monthly: { NGN: 850000 }, by_kind: { NGN: { salary: 700000, phase: 150000 } }, series: { NGN: [ 100, 200, 300 ] }, by_department: [ { id: 2, name: 'Digital Zooks Agency (Demo)', values: { NGN: 850000 } } ] },
	} ),
	'/departments/overview': () => ( {
		range: { from: '2026-01-01', to: '2026-10-03' }, sections: { work: true, contracts: true, finance: true, billing: true },
		departments: [
			{ id: 2, name: 'Digital Zooks Agency (Demo)', description: 'Web and brand work', clients: 3, people: 2, open_tasks: 4, active_projects: 1, contracts_waiting: 2, income: { NGN: 6212300 }, profit: { NGN: 5092300 }, mrr: { NGN: 438166.66 }, receivable: { NGN: 5614892.5 } },
			{ id: 0, name: 'No department', description: 'Things not attached to a department yet.', clients: 0, people: 1, open_tasks: 9, active_projects: 2, contracts_waiting: 5, income: { NGN: 535000 }, profit: { NGN: -475700 }, mrr: {}, receivable: { NGN: 255000 } },
		],
	} ),
	'/departments/2/overview': () => ( {
		id: 2, name: 'Digital Zooks Agency (Demo)', description: 'Web and brand work', range: { from: '2026-01-01', to: '2026-10-03' }, sections: { work: true, contracts: true, finance: true, billing: true },
		clients: [ { id: 1, name: 'ABC Company (Demo)', status: 'active', type: 'retainer' } ], people: [ { id: 1, name: 'Zara Team', role: 'Account manager', kind: 'employee' } ],
		work: { open: 4, overdue: 1, done: 3 }, projects: [ { id: 3, name: 'Website redesign', client_name: 'ABC Company (Demo)', status: 'active', due_date: '2026-11-04', total: 6, done: 2, progress: 33 } ],
		contracts: { by_status: { sent: 2, signed: 1 }, recent: [ { id: 4, number: 'CT-0004', title: 'Service agreement', client_name: 'ABC Company (Demo)', status: 'sent', sent_at: '2026-09-20' } ] },
		billing: { to_invoice: { NGN: { amount: 165000, count: 2 } }, items: [ { key: 'charge:6', title: '3 additional email users', client_name: 'ABC Company (Demo)', type: 'one_off', price: 25000, currency: 'NGN', date: '2026-10-08' } ] },
		invoices: [ { id: 7, number: 'INV-0001', title: 'Website redesign', client_name: 'ABC Company (Demo)', amount: 200000, currency: 'NGN', status: 'sent', issue_date: '2026-09-29' } ],
		money: { NGN: { income: 6212300, direct: 100, keep: 6212200, team: 100, expenses: 100, profit: 5092300, margin_pct: 82 } }, mrr: { NGN: 438166.66 }, receivable: { NGN: 5614892.5 },
		cash: { received: { NGN: 80000 }, paid_out: { NGN: 452000 }, team_owed: { NGN: 375000 } }, expense_categories: [ { category: 'software', values: { NGN: 18000 }, sort: 18000 } ],
	} ),
	'POST /bulk/department': () => ( { updated: 2, failed: [] } ),
	'/currencies/usage': () => ( { NGN: 14 } ),
	'/billing/list': () => ( {
		items: [
			{ key: 'renewal:5', kind: 'renewal', type: 'subscription', renewal_id: 5, charge_id: 0, client_service_id: 4, client_id: 1, client_name: 'ABC Company (Demo)', title: 'Web hosting — acme-demo.com', service_name: 'Web hosting', subject: 'acme-demo.com', period: 'annual', department_id: 2, department_name: 'Digital Zooks Agency (Demo)', date: '2026-09-25', price: 120000, quantity: 1, unit_price: 0, currency: 'NGN', bill_status: 'not_invoiced', invoice_id: 0, invoice_number: '', invoice_status: '', state: 'overdue', days: -6, can_select: true, sort: 0 },
			{ key: 'renewal:8', kind: 'renewal', type: 'retainer', renewal_id: 8, charge_id: 0, client_service_id: 10, client_id: 1, client_name: 'ABC Company (Demo)', title: 'Website management — Site A', service_name: 'Website management', subject: 'Site A', period: 'monthly', date: '2026-10-05', price: 200000, quantity: 1, unit_price: 0, currency: 'NGN', bill_status: 'not_invoiced', invoice_id: 0, invoice_number: '', invoice_status: '', state: 'ready', days: 4, can_select: true, sort: 1 },
			{ key: 'charge:6', kind: 'charge', type: 'one_off', renewal_id: 0, charge_id: 6, client_service_id: 4, client_id: 1, client_name: 'ABC Company (Demo)', title: '3 additional email users', service_name: 'Web hosting', subject: '', period: '', department_id: 1, department_name: 'Zooks Cloud (Demo)', date: '2026-10-08', price: 25000, quantity: 1, unit_price: 0, currency: 'NGN', bill_status: 'not_invoiced', invoice_id: 0, invoice_number: '', invoice_status: '', state: 'ready', days: 7, can_select: true, sort: 1 },
			{ key: 'charge:9', kind: 'charge', type: 'one_off', renewal_id: 0, charge_id: 9, client_service_id: 0, client_id: 2, client_name: 'Northwind Ltd (Demo)', title: 'Premium theme purchase', service_name: '', subject: '', period: '', date: '2026-09-30', price: 89, quantity: 1, unit_price: 0, currency: 'USD', bill_status: 'not_invoiced', invoice_id: 0, invoice_number: '', invoice_status: '', state: 'overdue', days: -1, can_select: true, sort: 0 },
		],
		total: 4, page: 1, per_page: 25,
		chips: { all: 4, retainer: 1, subscription: 1, one_off: 2 },
		money: { to_invoice: { NGN: { amount: 345000, count: 3 }, USD: { amount: 89, count: 1 } }, outstanding: {} },
	} ),
	'/billing/queue': () => ( {
		items: [
			{ key: 'renewal-5', type: 'renewal', ref_id: 5, client_id: 1, client_name: 'ABC Company (Demo)', client_service_id: 4, label: 'Web hosting renewal', sub: 'annual', date: '2026-09-25', days: -6, price: 120000, cost: 90000, currency: 'NGN', state: 'overdue' },
			{ key: 'charge-6', type: 'charge', ref_id: 6, client_id: 1, client_name: 'ABC Company (Demo)', client_service_id: 4, label: '3 additional email users', sub: 'Web hosting', date: '2026-10-08', days: 7, price: 25000, cost: 15000, currency: 'NGN', state: 'ready' },
		],
		total: 2, pages: 1,
		summary: { overdue: { NGN: { amount: 120000, count: 1 } }, ready: { NGN: { amount: 25000, count: 1 } }, scheduled: {}, unscheduled: {} },
	} ),
	'/clients/1/ledger': () => [
		{ type: 'invoice', id: 7, date: '2026-09-29', label: 'Invoice INV-0001', sub: 'due 2026-10-13', amount: 200000, currency: 'NGN', status: 'partially_paid', invoice_id: 7, client_service_id: 0 },
		{ type: 'payment', id: 9, date: '2026-09-30', label: 'Payment received', sub: 'INV-0001 Part payment', amount: 80000, currency: 'NGN', status: 'paid', invoice_id: 7, client_service_id: 0 },
		{ type: 'renewal', id: 5, date: '2026-12-01', label: 'Web hosting renewal', sub: 'annual', amount: 120000, currency: 'NGN', status: 'upcoming', invoice_id: 0, client_service_id: 4 },
	],
	'/client-services/4/ledger': () => [ { type: 'invoice_line', id: 1, date: '2026-01-02', label: 'Invoiced on INV-0001', sub: '2026-01-01 → 2026-12-31', amount: 120000, currency: 'NGN', status: 'paid', invoice_id: 7, client_service_id: 4 } ],
	'/invoices/from-sources': () => invoice,
};

export const project = { id: 3, client_id: 1, client_name: 'ABC Company (Demo)', name: 'Website redesign', description: 'New site for ABC', status: 'active', start_date: '2026-10-01', due_date: '2026-11-15', owner_id: 1, owner_name: 'Zara Team', task_total: 4, task_done: 1, progress: 25, is_overdue: false, currency: 'NGN', price: 2000000, money: { price: 2000000, budget: 600000, allocated: 400000, earned: 150000, paid: 0, owed: 150000, margin: 1600000 } };
export const task = { id: 5, project_id: 3, project_name: 'Website redesign', client_id: 1, client_name: 'ABC Company (Demo)', title: 'Send brand brief to client', phase_id: 1, phase_name: 'Design', notes: '', status: 'todo', priority: 'high', due_date: '2026-10-01', assignee_id: 1, assignee_name: 'Zara Team', client_service_id: 0, subscription_subject: '', subscription_name: '', is_overdue: true, is_today: false };
export const task2 = { ...task, id: 6, title: 'Renew abc-store.com', status: 'doing', priority: 'normal', project_id: 0, project_name: null, client_service_id: 4, subscription_subject: 'abc-store.com', is_overdue: false };

export const teamMember = { id: 1, name: 'Zara Team', email: 'zara@dz.example', role: 'Account manager', kind: 'employee', department_id: 2, department_name: 'Digital Zooks Agency (Demo)', cc_invoices: true, wp_user_id: 9, is_me: true, status: 'active', salary_amount: 350000, salary_period: 'monthly', currency: 'NGN', bank_name: 'GTBank', account_name: 'Zara Team', account_number: '0123456789', open_tasks: 3, overdue_tasks: 1, active_projects: 2, owed: { NGN: { amount: 120000, count: 2 } }, paid_year: { NGN: { amount: 900000, count: 1 } }, paid_all: { NGN: { amount: 900000, count: 1 } } };

export const expense = { id: 1, spent_on: '2026-10-02', description: 'Figma team plan', vendor: 'Figma', category: 'software', amount: 18000, currency: 'NGN', department_id: 0, department_name: null, client_id: 0, method: 'Card', reference: 'INV-9921', attachment_id: 0, attachment_url: '', recurring_id: 0, is_recurring: false, status: 'paid' };

export const phase1 = { id: 1, project_id: 3, name: 'Design', sort_order: 1, status: 'doing', start_date: null, due_date: '2026-10-20', price: 800000, team_budget: 600000, task_total: 4, task_done: 1, allocated: 400000, over_budget: false, allocations: [ { id: 21, team_member_id: 1, member_name: 'Zara Team', mode: 'percent', value: 50, amount: 300000 }, { id: 22, team_member_id: 5, member_name: 'Amara Eze (Demo)', mode: 'fixed', value: 100000, amount: 100000 } ] };
export const payItem = { id: 11, team_member_id: 1, member_name: 'Zara Team', kind: 'phase', project_id: 3, project_name: 'Website redesign', phase_id: 1, phase_name: 'Design', description: 'Design — Website redesign', amount: 150000, currency: 'NGN', earned_on: '2026-10-01', status: 'payable', payout_id: 0, payout_number: null, is_generated: true };

export const payroll = {
	period: '2026-10', label: 'October 2026', salaries_missing: 2,
	totals: { owed: { NGN: { amount: 745000, count: 1 } }, salaries: { NGN: { amount: 280000, count: 1 } }, paid_in_month: { NGN: { amount: 300000, count: 1 } } },
	members: [
		{ id: 1, name: 'Zara Team', role: 'Account manager', kind: 'employee', department_name: 'Digital Zooks Agency (Demo)', salary_amount: 350000, currency: 'NGN', salary_due: true, salary: null, other_owed: { NGN: 150000 }, owed: { NGN: 150000 }, items: [ { id: 11, description: 'Design — Website redesign', project_name: 'Website redesign', phase_name: 'Design', kind: 'phase', amount: 150000, currency: 'NGN' } ] },
		{ id: 6, name: 'Kunle Adeyemi (Demo)', role: 'Copywriter', kind: 'employee', department_name: null, salary_amount: 220000, currency: 'NGN', salary_due: true, salary: { id: 30, status: 'payable', amount: 220000, payout_number: null }, other_owed: {}, owed: { NGN: 220000 }, items: [ { id: 30, description: 'Salary — October 2026', project_name: null, phase_name: null, kind: 'salary', amount: 220000, currency: 'NGN' } ] },
	],
};

export const overview = {
	range: { from: '2026-10-01', to: '2026-10-31' }, bases: { income: 'invoiced', pay: 'earned' }, department: null,
	summary: { NGN: { income: 1500000, direct: 300000, keep: 1200000, team: 500000, expenses: 78000, profit: 622000, margin_pct: 41 } },
	chart: { labels: [ 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct' ], currencies: { NGN: { income: [ 0, 200000, 400000, 900000, 1200000, 1500000 ], costs: [ 100000, 150000, 300000, 500000, 700000, 878000 ], profit: [ -100000, 50000, 100000, 400000, 500000, 622000 ] } } },
	departments: [ { id: 1, name: 'Digital Zooks Agency (Demo)', values: { NGN: { income: 1000000, direct: 200000, keep: 800000, team: 300000, expenses: 40000, profit: 460000, margin_pct: 46 } } }, { id: 0, name: 'General / no department', values: { NGN: { income: 500000, direct: 100000, keep: 400000, team: 200000, expenses: 38000, profit: 162000, margin_pct: 32 } } } ],
	clients: [ { id: 1, name: 'ABC Company (Demo)', currency: 'NGN', income: 1200000, direct: 150000, keep: 1050000 } ],
	projects: [ { id: 1, name: 'Website redesign', client_name: 'ABC Company (Demo)', currency: 'NGN', price: 2000000, team_cost: 1480000, margin: 520000 } ],
	balances: { receivable: { NGN: { amount: 12937681.25, count: 6 } }, payable: { NGN: { amount: 599500, count: 7 } }, recurring_income: { NGN: { amount: 1178166.66, count: 22 } }, recurring_costs: { NGN: { amount: 1200000, count: 8 } } },
};

export const contract = { id: 5, number: 'CT-0005', title: 'Website agreement — ABC', client_id: 1, client_name: 'ABC Company (Demo)', status: 'partially_signed', body: '<h2>Agreement</h2><p>Hello</p>', body_hash: 'a'.repeat( 64 ), final_hash: '', sent_at: '2026-10-01 10:00:00', completed_at: null, signer_total: 2, signer_done: 1, certificate_url: 'http://x/?bd_certificate=5', has_pdf: false, preview_url: 'http://x/contract/abc123xyz', view_access: 'private', pdf_url: 'http://x/?bd_certificate=5&pdf=1', signers: [ { id: 11, name: 'Ada Obi', email: 'ada@abc.example', role: 'client', status: 'signed', signed_at: '2026-10-01 11:00:00', link: 'http://x/sign/aaa' }, { id: 12, name: 'Digitalzooks', email: 'ops@dz.example', role: 'company', status: 'pending', signed_at: null, link: 'http://x/sign/bbb' } ] };
export const contractDraft = { ...contract, id: 6, number: 'CT-0006', status: 'draft', sent_at: null, signer_done: 0 };
export const contractAudit = { integrity: { ok: true, complete: false, cert_id: '', checks: [ { label: 'The audit log is complete and untouched (3 entries, each sealed to the one before)', ok: true } ] }, verify_url: 'http://x/verify', chain: { ok: true, count: 3, broken_at: null }, certificate_url: 'http://x/?bd_certificate=5', events: [ { id: 1, signer_id: 0, user_name: 'Admin', event: 'sent', detail: '{"document_sha256":"abc"}', ip: '1.1.1.1', created_at: '2026-10-01 10:00:00' }, { id: 2, signer_id: 11, event: 'otp_sent', detail: '{"to":"ada@abc.example","code":"482913","mail_accepted":true}', ip: '2.2.2.2', created_at: '2026-10-01 10:30:00' }, { id: 3, signer_id: 11, event: 'otp_verified', detail: '{"entered":"482913","email":"ada@abc.example","match":true}', ip: '2.2.2.2', created_at: '2026-10-01 10:31:00' } ], otps: [ { id: 1, code: '482913', entered_code: '482913', email: 'ada@abc.example', sent_at: '2026-10-01 10:30:00', status: 'verified' } ] };

export const workOverview = { stats: { open: 14, overdue: 2, due_week: 8, done_week: 2, mine: 5, projects: 3, projects_late: 0 }, has_me: true, mine: [ { id: 1, title: 'Fix redirects', due_date: '2026-10-02', priority: 'high', status: 'todo', project_id: 3, client_name: 'ABC Company (Demo)', assignee_name: 'Zara Team' } ], overdue: [], soon: [ { id: 2, title: 'Set up staging', due_date: '2026-10-08', client_name: 'ABC Company (Demo)', assignee_name: 'Zara Team' } ], projects: [ { id: 3, name: 'Website redesign', client_name: 'ABC Company (Demo)', due_date: '2026-11-04', progress: 17, total: 6, done: 1, is_overdue: false } ], load: [ { id: 1, name: 'Zara Team', open_tasks: 5, late: 1 } ] };
export const contractsOverview = { stats: { drafts: 3, awaiting: 5, signed: 4, signed_month: 1, declined: 2, total: 14 }, awaiting: [ { id: 5, number: 'CT-0005', title: 'Monthly retainer', client_name: 'ABC Company (Demo)', sent_at: '2026-09-20 10:00:00', signer_total: 2, signer_done: 1, waiting_on: 'Digitalzooks' } ], signed: [ { id: 1, number: 'CT-0001', title: 'Website design agreement', client_name: 'ABC Company (Demo)', completed_at: '2026-10-01 10:00:00', signer_total: 2, signer_done: 2 } ], drafts: [ { id: 6, number: 'CT-0006', title: 'Draft NDA', client_name: 'XYZ', signer_total: 0, signer_done: 0 } ] };
