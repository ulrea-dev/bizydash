<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { Coin, Wallet, TrendCharts, Tickets, Finished, Filter, CircleCheckFilled, CircleCheck, DocumentChecked } from '@element-plus/icons-vue';
import { ElMessageBox } from 'element-plus';
import { api, errorMessage } from '../api';
import { store, navigate } from '../store';
import BillingState from '../components/BillingState.vue';
import MoneyMap from '../components/MoneyMap.vue';
import PageHeader from '../components/PageHeader.vue';
import StatCard from '../components/StatCard.vue';
import TrendChart from '../components/TrendChart.vue';
import { avatarStyle, dept, fmtDate, fmtDateTime, fmtMoney, initials, relDays, today } from '../util';

const CHIPS = [
	{ id: 'all', label: 'All', params: {} },
	{ id: 'retainer', label: 'Retainers', params: { client_type: 'retainer' } },
	{ id: 'adhoc', label: 'Ad-hoc', params: { client_type: 'adhoc' } },
	{ id: 'product', get label() { return 'Product ' + dept().lowerMany; }, params: { brand_type: 'product' } },
	{ id: 'service', get label() { return 'Service ' + dept().lowerMany; }, params: { brand_type: 'service' } },
];

const chip = ref( 'all' );
const brandId = ref( '' );
const brands = ref( [] );
const data = ref( null );
const error = ref( '' );
const chart = ref( null );
const fm = computed( () => store.enabled( 'finance' ) );
const wm = computed( () => store.enabled( 'work' ) );
const cm = computed( () => store.enabled( 'contracts' ) );
const contracts = ref( null );
const chartCurrency = ref( '' );

const load = async () => {
	const params = { ...CHIPS.find( ( c ) => c.id === chip.value ).params };
	if ( brandId.value ) {
		params.brand_id = brandId.value;
	}
	try {
		data.value = await api.getPath( 'dashboard', params );
		error.value = '';
	} catch ( e ) {
		error.value = errorMessage( e );
	}
};

onMounted( async () => {
	load();
	try {
		brands.value = ( await api.list( 'brands', { per_page: 200, status: 'active' } ) ).items;
	} catch ( e ) {
		brands.value = [];
	}
	if ( cm.value ) {
		try {
			contracts.value = await api.getPath( 'contracts/overview' );
		} catch ( e ) {
			contracts.value = null;
		}
	}
	try {
		if ( ! fm.value ) {
			throw new Error( 'finance off' );
		}
		chart.value = await api.getPath( 'dashboard/chart', { months: 6 } );
		const codes = Object.keys( chart.value.currencies || {} );
		chartCurrency.value = codes.includes( store.meta.settings.default_currency ) ? store.meta.settings.default_currency : codes[ 0 ] || '';
	} catch ( e ) {
		chart.value = null;
	}
} );
watch( [ chip, brandId ], load );

const s = computed( () => data.value && data.value.stats );
const b = computed( () => data.value && data.value.billing );
const chartCodes = computed( () => ( chart.value ? Object.keys( chart.value.currencies || {} ) : [] ) );
const series = computed( () => ( chart.value && chartCurrency.value ? chart.value.currencies[ chartCurrency.value ] : null ) );

/** Sum { state: {cur: {amount,count}} } buckets into one { cur: {amount,count} } map. */
const merge = ( ...maps ) => {
	const out = {};
	maps.forEach( ( m ) =>
		Object.entries( m || {} ).forEach( ( [ cur, v ] ) => {
			out[ cur ] = out[ cur ] || { amount: 0, count: 0 };
			out[ cur ].amount += v.amount;
			out[ cur ].count += v.count;
		} )
	);
	return out;
};
const count = ( m ) => Object.values( m || {} ).reduce( ( n, v ) => n + v.count, 0 );
const ready = computed( () => ( b.value ? b.value.pipeline.ready : {} ) );
const overdueBill = computed( () => ( b.value ? b.value.pipeline.overdue : {} ) );
const scheduled = computed( () => ( b.value ? merge( b.value.pipeline.scheduled, b.value.pipeline.unscheduled ) : {} ) );
const awaiting = computed( () => ( b.value ? b.value.awaiting : {} ) );
const overduePay = computed( () => ( b.value ? b.value.overdue : {} ) );

const toInvoice = computed( () => ( b.value ? merge( b.value.to_invoice.one_off, b.value.to_invoice.recurring ) : {} ) );
const mrr = computed( () => ( b.value ? b.value.mrr : {} ) );
const arr = computed( () => ( b.value ? b.value.arr : {} ) );
const collected = computed( () => ( b.value ? b.value.collected : {} ) );
const money1 = ( map ) => {
	const [ cur, v ] = Object.entries( map || {} )[ 0 ] || [];
	return cur ? fmtMoney( v.amount, cur ) : '—';
};
const toInvoiceSub = computed( () => ( b.value ? `${ count( b.value.to_invoice.one_off ) } one-off · ${ count( b.value.to_invoice.recurring ) } recurring` : '' ) );

const first = ( store.user || '' ).includes( '@' ) ? '' : ( store.user || '' ).split( ' ' )[ 0 ];
const hour = new Date().getHours();
const greeting = `${ hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening' }${ first ? ', ' + first : '' }`;
const today_label = new Date().toLocaleDateString( undefined, { weekday: 'long', month: 'long', day: 'numeric' } );


const KIND_MODULE = { bill: 'finance', invoice: 'finance', payroll: 'finance', renewal: 'finance', charge: 'finance', task: 'work' };
const todo = computed( () => ( ( data.value && data.value.todo ) || [] ).filter( ( t ) => store.enabled( KIND_MODULE[ t.kind ] || 'core' ) ) );
const comingUp = computed( () => ( ( data.value && data.value.coming_up ) || [] ).filter( ( t ) => fm.value || ! [ 'bill', 'invoice', 'renewal', 'expense', 'payroll' ].includes( t.kind ) ) );
const waiting = computed( () => ( b.value ? merge( b.value.awaiting, b.value.overdue ) : {} ) );
const moneyText = ( map ) => Object.entries( map || {} ).map( ( [ cur, v ] ) => fmtMoney( v.amount, cur ) ).join( ' + ' );
const summary = computed( () => {
	if ( ! data.value ) {
		return '';
	}
	const n = data.value.todo_total || 0;
	const parts = [ n ? `You have ${ n } thing${ n === 1 ? '' : 's' } to do today.` : 'You’re all caught up for today.' ];
	if ( count( waiting.value ) ) {
		parts.push( `${ moneyText( waiting.value ) } is waiting to be paid${ count( overduePay.value ) ? ` (${ moneyText( overduePay.value ) } overdue)` : '' }.` );
	}
	return parts.join( ' ' );
} );
const KIND_ICON = { bill: Coin, invoice: Tickets, task: Finished, payroll: Wallet };
const KIND_LABEL = { bill: 'Bill', invoice: 'Invoice', task: 'Task', payroll: 'Payroll' };
const fin = computed( () => ( data.value && data.value.finance ) || null );
const profitMap = computed( () => {
	const out = {};
	Object.entries( ( fin.value && fin.value.profit_month ) || {} ).forEach( ( [ c, v ] ) => ( out[ c ] = { amount: v.profit, count: 1 } ) );
	return out;
} );
const profitNeg = computed( () => Object.values( ( fin.value && fin.value.profit_month ) || {} ).some( ( v ) => v.profit < 0 ) );
const dueText = ( d ) => ( d === null || d === undefined ? '' : d < 0 ? `${ -d } day${ d === -1 ? '' : 's' } late` : d === 0 ? 'Today' : `In ${ d } day${ d === 1 ? '' : 's' }` );
const groups = computed( () => {
	const g = [ { label: 'This week', items: [] }, { label: 'Next week', items: [] }, { label: 'Later', items: [] } ];
	comingUp.value.forEach( ( i ) => g[ i.days <= 7 ? 0 : i.days <= 14 ? 1 : 2 ].items.push( i ) );
	return g.filter( ( x ) => x.items.length );
} );
const setup = computed( () => data.value && data.value.setup );
const SETUP_STEPS = [
	{ key: 'profile', label: 'Add your business details and logo', to: '/settings?tab=business' },
	{ key: 'payment', label: 'Add how clients can pay you', to: '/settings?tab=payments' },
	{ key: 'client', label: 'Add your first client', to: '/clients' },
	{ key: 'catalog', label: 'Add what you sell to the catalog', to: '/catalog' },
	{ key: 'invoice', label: 'Create your first invoice', to: '/billing/invoices/new' },
];
const setupSteps = computed( () => SETUP_STEPS.filter( ( s ) => fm.value || ! [ 'payment', 'catalog', 'invoice' ].includes( s.key ) ) );
const setupDone = computed( () => ( setup.value ? setupSteps.value.filter( ( s ) => setup.value[ s.key ] ).length : 0 ) );
const dismissed = ref( ( () => { try { return localStorage.getItem( 'bizydash_setup_dismissed' ) === '1'; } catch ( e ) { return false; } } )() );
const dismissSetup = () => {
	dismissed.value = true;
	try { localStorage.setItem( 'bizydash_setup_dismissed', '1' ); } catch ( e ) { /* ignore */ }
};
const showSetup = computed( () => setup.value && ! dismissed.value && setupDone.value < setupSteps.value.length );
const loadingDemo = ref( false );
const tryDemo = async () => {
	loadingDemo.value = true;
	try {
		await api.post( 'demo' );
		window.location.reload();
	} catch ( e ) {
		loadingDemo.value = false;
		error.value = errorMessage( e );
	}
};
const removingDemo = ref( false );
const removeDemo = async () => {
	try {
		await ElMessageBox.confirm( 'Remove all the sample clients, invoices, projects and everything else that came with them? Anything you added yourself stays.', 'Remove sample data', { type: 'warning', confirmButtonText: 'Remove sample data' } );
	} catch ( e ) { return; }
	removingDemo.value = true;
	try {
		await api.del( 'demo' );
		window.location.reload();
	} catch ( e ) {
		removingDemo.value = false;
		error.value = errorMessage( e );
	}
};
const startFresh = async () => {
	store.freshInstall = false;
	try { await api.post( 'backups/fresh-dismiss' ); } catch ( e ) { /* the card just stays hidden for this visit */ }
};
const filtering = computed( () => chip.value !== 'all' || !! brandId.value );

const attnLink = ( a ) => ( a.kind === 'invoice' ? '#/billing/invoices' : a.kind === 'renewal' ? '#/finance/billing?type=subscription' : '#/finance/billing' );
const attnKind = ( a ) => ( { renewal: 'Recurring', charge: 'One-off', invoice: 'Invoice' }[ a.kind ] );
</script>

<template>
	<div class="bd-dashboard">
		<PageHeader :eyebrow="today_label" :title="greeting" :description="summary || 'Loading your day…'">
			<el-popover placement="bottom-end" :width="300" trigger="click">
				<template #reference><el-button :icon="Filter" :type="filtering ? 'primary' : 'default'" plain>{{ filtering ? 'Filtered' : 'Filter' }}</el-button></template>
				<div class="bd-filterpop">
					<div class="bd-muted bd-small bd-mb">Show numbers for…</div>
					<el-radio-group v-model="chip" class="bd-mb"><el-radio-button v-for="c in CHIPS" :key="c.id" :value="c.id">{{ c.label }}</el-radio-button></el-radio-group>
					<el-select v-model="brandId" clearable :placeholder="'All ' + dept().lowerMany" style="width: 100%"><el-option v-for="br in brands" :key="br.id" :label="br.name" :value="br.id" /></el-select>
				</div>
			</el-popover>
		</PageHeader>

		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />
		<el-skeleton v-if="!data && !error" :rows="6" animated />

		<el-alert v-if="store.backupAlert" type="warning" show-icon :closable="false" class="bd-mb" title="The last automatic backup failed.">
			<span class="bd-small">{{ store.backupAlert }}</span> <a href="#/settings?tab=backup" class="bd-link">Open backup settings</a>
		</el-alert>

		<el-alert v-if="store.demoLoaded && store.canManage" type="info" show-icon :closable="false" class="bd-mb" title="You are looking at sample data.">
			<span class="bd-small">When you are ready for your real data, remove it in one click.</span>
			<el-button size="small" :loading="removingDemo" style="margin-left: 10px" @click="removeDemo">Remove sample data</el-button>
		</el-alert>

		<el-card v-if="store.freshInstall" shadow="never" class="bd-mb bd-welcome">
			<div class="bd-widget__head"><span>Welcome to BizyDash</span></div>
			<p class="bd-muted">Starting from scratch, or moving from another site? If you already have a BizyDash backup, restore it now and your clients, work, invoices and contracts come straight back. You can also do this later in Settings.</p>
			<div class="bd-mt">
				<el-button type="primary" @click="navigate( '/settings?tab=backup&restore=1' )">Restore a backup</el-button>
				<el-button @click="startFresh">Start fresh</el-button>
				<el-button :loading="loadingDemo" @click="tryDemo">Try with sample data</el-button>
			</div>
		</el-card>

		<template v-if="s && b">
			<el-card v-if="showSetup" shadow="never" class="bd-mb bd-setup">
				<div class="bd-widget__head"><span>Get set up — {{ setupDone }} of {{ setupSteps.length }} done</span><a href="#" @click.prevent="dismissSetup">Hide</a></div>
				<ul class="bd-steps">
					<li v-for="st in setupSteps" :key="st.key" :class="{ 'is-done': setup[ st.key ] }">
						<el-icon><CircleCheckFilled v-if="setup[ st.key ]" /><CircleCheck v-else /></el-icon>
						<a :href="'#' + st.to" class="bd-link">{{ st.label }}</a>
					</li>
				</ul>
			</el-card>

			<el-row :gutter="16" class="bd-stats">
				<el-col v-if="fm" :xs="12" :md="6">
					<StatCard label="Waiting to be paid" to="/billing/invoices?unpaid=1" :icon="Tickets" :tone="count( overduePay ) ? 'danger' : ''" :sub="count( overduePay ) ? moneyText( overduePay ) + ' overdue' : count( waiting ) + ' invoice' + ( count( waiting ) === 1 ? '' : 's' )"><MoneyMap :map="waiting" empty="Nothing" /></StatCard>
				</el-col>
				<el-col v-if="fm" :xs="12" :md="6">
					<StatCard label="Ready to invoice" to="/finance/billing" :icon="Coin" :tone="count( overdueBill ) ? 'danger' : count( toInvoice ) ? 'warning' : ''" :sub="toInvoiceSub"><MoneyMap :map="toInvoice" empty="Nothing" /></StatCard>
				</el-col>
				<el-col v-if="fm" :xs="12" :md="6">
					<StatCard label="Collected this month" to="/billing/invoices" :icon="Wallet" sub="Payments received"><MoneyMap :map="collected" empty="Nothing yet" /></StatCard>
				</el-col>
				<el-col v-if="fm && fin" :xs="12" :md="6">
					<StatCard label="Profit this month" to="/finance/overview" :icon="TrendCharts" :tone="profitNeg ? 'danger' : ''" sub="After direct costs, team pay and expenses"><MoneyMap :map="profitMap" empty="Nothing yet" /></StatCard>
				</el-col>
				<el-col v-if="false" :xs="12" :md="6">
					<StatCard label="Owed to the team" to="/finance/payroll" :icon="Wallet" :tone="Object.keys( fin.owed_team ).length ? 'warning' : ''" sub="Earned, not paid yet"><MoneyMap :map="fin.owed_team" empty="Nothing" /></StatCard>
				</el-col>
				<el-col v-if="cm && contracts" :xs="12" :md="6">
					<StatCard label="Contracts awaiting signatures" to="/contracts/all?status=sent" :icon="DocumentChecked" :tone="contracts.stats.awaiting ? 'warning' : ''" :sub="contracts.stats.signed_month + ' signed this month'"><span class="bd-stat__text">{{ contracts.stats.awaiting }}</span></StatCard>
				</el-col>
				<el-col v-if="fm && !fin" :xs="12" :md="6">
					<StatCard label="Monthly income" to="/finance/billing" :icon="TrendCharts" :sub="count( mrr ) + ' subscription' + ( count( mrr ) === 1 ? '' : 's' ) + ' & retainers'"><MoneyMap :map="mrr" empty="—" /></StatCard>
				</el-col>
			</el-row>

			<el-row :gutter="20" class="bd-mb">
				<el-col :xs="24" :lg="14">
					<el-card shadow="never" class="bd-widget">
						<template #header><div class="bd-widget__head"><span>To do today <span v-if="data.todo_total" class="bd-tabcount">{{ data.todo_total }}</span></span><a v-if="wm" href="#/work/mine">My tasks</a></div></template>
						<ul class="bd-list bd-todo">
							<li v-for="t in todo" :key="t.key">
								<div class="bd-todo__main">
									<el-icon class="bd-todo__icon"><component :is="KIND_ICON[ t.kind ]" /></el-icon>
									<div>
										<div class="bd-strong">{{ t.title }}<span v-if="t.priority === 'high'" class="bd-badge bd-badge--danger bd-badge--plain bd-ml">High</span></div>
										<div class="bd-muted bd-small">{{ KIND_LABEL[ t.kind ] }} · {{ t.sub }}</div>
									</div>
								</div>
								<div class="bd-todo__side">
									<div class="bd-attn__right">
										<span v-if="t.amount" class="bd-money bd-strong">{{ fmtMoney( t.amount, t.currency ) }}</span>
										<span :class="[ 'bd-small', t.days !== null && t.days < 0 ? 'bd-late' : 'bd-muted' ]">{{ dueText( t.days ) }}</span>
									</div>
									<el-button size="small" @click="navigate( t.action.to )">{{ t.action.label }}</el-button>
								</div>
							</li>
							<li v-if="!todo.length" class="bd-muted">Nothing is due today. Enjoy the quiet — or <a class="bd-link" href="#/work/mine">plan some work</a>.</li>
						</ul>
					</el-card>
				</el-col>
				<el-col :xs="24" :lg="10">
					<el-card shadow="never" class="bd-widget">
						<template #header><div class="bd-widget__head"><span>Coming up <span class="bd-muted">· next {{ data.due_soon_days }} days</span></span><a href="#/finance/billing">All recurring</a></div></template>
						<div v-for="g in groups" :key="g.label" class="bd-coming">
							<div class="bd-coming__h">{{ g.label }}</div>
							<ul class="bd-list">
								<li v-for="i in g.items" :key="i.key">
									<div class="bd-cell-client">
										<span class="bd-ava bd-ava--sm" :style="avatarStyle( i.sub )">{{ initials( i.sub ) }}</span>
										<div><div class="bd-strong">{{ i.title }}</div><div class="bd-muted bd-small">{{ i.sub }}</div></div>
									</div>
									<div class="bd-attn__right"><span class="bd-ink">{{ fmtDate( i.date ) }}</span><span class="bd-muted bd-small bd-money">{{ fmtMoney( i.amount, i.currency ) }}</span></div>
								</li>
							</ul>
						</div>
						<p v-if="!groups.length" class="bd-muted">Nothing coming due in the next {{ data.due_soon_days }} days.</p>
					</el-card>
				</el-col>
			</el-row>

			<el-row :gutter="20">
				<el-col v-if="fm" :xs="24" :lg="14">
					<el-card shadow="never" class="bd-widget">
						<template #header>
							<div class="bd-widget__head">
								<span>Billed vs paid <span class="bd-muted">· last 6 months</span></span>
								<span class="bd-legend"><span><i style="background: var(--bd-accent)" />Billed</span><span><i style="background: var(--bd-success)" />Paid</span></span>
								<el-radio-group v-if="chartCodes.length > 1" v-model="chartCurrency" class="bd-seg-sm">
									<el-radio-button v-for="c in chartCodes" :key="c" :value="c">{{ c }}</el-radio-button>
								</el-radio-group>
							</div>
						</template>
						<TrendChart v-if="series" :labels="chart.labels" :billed="series.billed" :paid="series.paid" :currency="chartCurrency" :dark="store.theme === 'dark'" />
						<el-empty v-else description="No invoices or payments yet." :image-size="72" />
					</el-card>
				</el-col>
				<el-col :xs="24" :lg="fm ? 10 : 24">
					<el-card shadow="never" class="bd-widget">
						<template #header><div class="bd-widget__head"><span>Recent activity</span><a href="#/activity">View all</a></div></template>
						<el-timeline v-if="data.activity.length" class="bd-timeline">
							<el-timeline-item v-for="a in data.activity" :key="a.id" :timestamp="fmtDateTime( a.created_at )" size="large">{{ a.summary }}</el-timeline-item>
						</el-timeline>
						<p v-else class="bd-muted">No activity yet.</p>
					</el-card>
				</el-col>
			</el-row>
		</template>
	</div>
</template>
