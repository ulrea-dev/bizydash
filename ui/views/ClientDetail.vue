<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { Edit, Coin, Tickets, TrendCharts, Finished } from '@element-plus/icons-vue';
import { api, errorMessage } from '../api';
import { store } from '../store';
import MoneyMap from '../components/MoneyMap.vue';
import PageHeader from '../components/PageHeader.vue';
import RecordDrawer from '../components/RecordDrawer.vue';
import StatCard from '../components/StatCard.vue';
import StatusTag from '../components/StatusTag.vue';
import { clientFields } from '../resources';
import { avatarStyle, fmtDate, fmtDateTime, fmtMoney, initials, labelOf, colorOf, relDays } from '../util';
import Activity from './Activity.vue';
import ClientLedger from './ClientLedger.vue';
import ClientContacts from './ClientContacts.vue';
import ClientPortalCard from '../components/ClientPortalCard.vue';
import ResourceTable from '../components/ResourceTable.vue';
import { contractsConfig } from '../resources';
import Invoices from './Invoices.vue';
import Projects from './Projects.vue';
import Billing from './Billing.vue';
import Tasks from './Tasks.vue';

const props = defineProps( { id: { type: String, required: true } } );

const client = ref( null );
const error = ref( '' );
const totals = reactive( { pending: {}, outstanding: {} } );
const tick = ref( 0 );
const tab = ref( 'overview' );
const billTab = ref( 'billing' );
const toInvoice = ref( [] );
const mrr = ref( {} );
const tasks = reactive( { open: 0, overdue: 0, items: [] } );
const counts = reactive( { pending: 0, open: 0 } );
const edit = reactive( { open: false } );
const history = ref( [] );
const allHistory = ref( false );
const shownHistory = computed( () => ( allHistory.value ? history.value : history.value.slice( 0, 8 ) ) );

const primaryCount = computed( () => ( client.value ? client.value.contacts.filter( ( c ) => c.kind === 'primary' ).length : 0 ) );
const primaryNames = computed( () => ( client.value ? client.value.contacts.filter( ( c ) => c.kind === 'primary' ).map( ( c ) => c.name ).join( ', ' ) : '' ) );

const group = ( rows, field ) => {
	const out = {};
	rows.forEach( ( r ) => {
		out[ r.currency ] = out[ r.currency ] || { amount: 0, count: 0 };
		out[ r.currency ].amount += Number( field( r ) );
		out[ r.currency ].count += 1;
	} );
	return out;
};

const load = async () => {
	try {
		// Only ask the modules that are switched on; a missing module simply contributes nothing.
		const fin = store.enabled( 'finance' );
		const wrk = store.enabled( 'work' );
		const none = { items: [], total: 0 };
		const [ c, ch, inv, act, unb, subs, tk, tkLate ] = await Promise.all( [
			api.get( 'clients', props.id ),
			fin ? api.list( 'charges', { client_id: props.id, status: 'pending', per_page: 200 } ) : none,
			fin ? api.list( 'invoices', { client_id: props.id, unpaid: 1, per_page: 200 } ) : none,
			api.list( 'activity', { client_id: props.id, per_page: 30 } ),
			fin ? api.getPath( `clients/${ props.id }/unbilled` ) : [],
			fin ? api.list( 'client-services', { client_id: props.id, recurring: 1, status: 'active', per_page: 200 } ) : none,
			wrk ? api.list( 'tasks', { client_id: props.id, open: 1, per_page: 6 } ) : none,
			wrk ? api.list( 'tasks', { client_id: props.id, due: 'overdue', per_page: 1 } ) : none,
		] );
		client.value = c;
		totals.pending = group( ch.items, ( r ) => r.price );
		totals.outstanding = group( inv.items, ( r ) => r.balance );
		counts.pending = ch.items.length;
		counts.open = inv.items.length;
		history.value = act.items;
		toInvoice.value = unb;
		const m = {};
		subs.items.forEach( ( r ) => {
			m[ r.currency ] = m[ r.currency ] || { amount: 0, count: 0 };
			m[ r.currency ].amount += Number( r.mrr || 0 );
			m[ r.currency ].count += 1;
		} );
		mrr.value = m;
		tasks.open = tk.total;
		tasks.overdue = tkLate.total;
		tasks.items = tk.items;
	} catch ( e ) {
		error.value = errorMessage( e );
	}
};
onMounted( load );
watch( tick, load );

// A child list changed (e.g. invoice created): refresh totals and sibling tabs.
const refresh = () => tick.value++;
const unbilledTotals = computed( () => group( toInvoice.value, ( r ) => r.price ) );
const nextBills = computed( () => [ ...toInvoice.value ].sort( ( a, b ) => ( a.days ?? 9999 ) - ( b.days ?? 9999 ) ).slice( 0, 6 ) );
const subsCount = computed( () => Object.values( mrr.value ).reduce( ( n, v ) => n + v.count, 0 ) );

const saveClient = async ( values ) => {
	const payload = {};
	clientFields().forEach( ( f ) => ( payload[ f.name ] = values[ f.name ] ) );
	const res = await api.update( 'clients', props.id, payload );
	ElMessage.success( 'Client saved' );
	return res;
};
</script>

<template>
	<div v-if="error && !client"><el-alert :title="error" type="error" show-icon :closable="false" /></div>
	<el-skeleton v-else-if="!client" :rows="8" animated />
	<div v-else class="bd-detail">
		<el-breadcrumb separator="/" class="bd-mb">
			<el-breadcrumb-item><a href="#/clients">Clients</a></el-breadcrumb-item>
			<el-breadcrumb-item>{{ client.company_name }}</el-breadcrumb-item>
		</el-breadcrumb>

		<div class="bd-hero">
			<span class="bd-ava bd-ava--lg" :style="avatarStyle( client.company_name )">{{ initials( client.company_name ) }}</span>
			<div class="bd-hero__main">
				<h1>{{ client.company_name }}</h1>
				<div class="bd-chips">
					<span class="bd-badge bd-badge--plain" :style="colorOf( 'client_type', client.client_type ) ? { color: colorOf( 'client_type', client.client_type ), background: colorOf( 'client_type', client.client_type ) + '1a' } : undefined">{{ labelOf( 'client_type', client.client_type ) }}</span>
					<StatusTag :status="client.status" />
					<span v-for="b in client.brands" :key="b.id" class="bd-badge bd-badge--neutral bd-badge--plain">{{ b.name }}</span>
				</div>
			</div>
			<el-button :icon="Edit" @click="edit.open = true">Edit client</el-button>
		</div>
		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />

		<el-row v-if="store.enabled( 'finance' ) || store.enabled( 'work' )" :gutter="16" class="bd-mb">
			<el-col v-if="store.enabled( 'finance' )" :xs="12" :md="6"><StatCard label="Waiting to be paid" :icon="Tickets" :tone="totals.outstanding && Object.keys( totals.outstanding ).length ? 'warning' : ''" :sub="counts.open + ' unpaid invoice' + ( counts.open === 1 ? '' : 's' )"><MoneyMap :map="totals.outstanding" empty="Nothing" /></StatCard></el-col>
			<el-col v-if="store.enabled( 'finance' )" :xs="12" :md="6"><StatCard label="Ready to invoice" :icon="Coin" :sub="toInvoice.length + ' bill' + ( toInvoice.length === 1 ? '' : 's' )"><MoneyMap :map="unbilledTotals" empty="Nothing" /></StatCard></el-col>
			<el-col v-if="store.enabled( 'finance' )" :xs="12" :md="6"><StatCard label="Monthly income" :icon="TrendCharts" :sub="subsCount + ' subscription' + ( subsCount === 1 ? '' : 's' ) + ' & retainers'"><MoneyMap :map="mrr" empty="—" /></StatCard></el-col>
			<el-col v-if="store.enabled( 'work' )" :xs="12" :md="6"><StatCard label="Open tasks" :icon="Finished" :tone="tasks.overdue ? 'danger' : ''" :sub="tasks.overdue ? tasks.overdue + ' overdue' : 'Nothing overdue'"><span class="bd-stat__text">{{ tasks.open }}</span></StatCard></el-col>
		</el-row>

		<el-tabs v-model="tab" class="bd-tabs">
			<el-tab-pane label="Overview" name="overview">
				<div class="bd-ovgrid">
					<el-card v-if="store.enabled( 'finance' )" shadow="never" class="bd-widget">
						<template #header><div class="bd-widget__head"><span>Next bills</span><a href="#" @click.prevent="tab = 'billing'">Open billing</a></div></template>
						<ul class="bd-list">
							<li v-for="i in nextBills" :key="i.key">
								<div><div class="bd-strong">{{ i.label }}</div><div class="bd-muted bd-small">{{ i.date ? fmtDate( i.date ) : 'No date set' }} · {{ relDays( i.days ) }}</div></div>
								<span class="bd-money bd-strong">{{ fmtMoney( i.price, i.currency ) }}</span>
							</li>
							<li v-if="!nextBills.length" class="bd-muted">Nothing to bill right now.</li>
						</ul>
					</el-card>
					<el-card v-if="store.enabled( 'work' )" shadow="never" class="bd-widget">
						<template #header><div class="bd-widget__head"><span>Open tasks</span><a href="#" @click.prevent="tab = 'work'">Open work</a></div></template>
						<ul class="bd-list">
							<li v-for="t in tasks.items" :key="t.id">
								<div><div class="bd-strong">{{ t.title }}</div><div class="bd-muted bd-small">{{ [ t.project_name, t.assignee_name || 'Unassigned' ].filter( Boolean ).join( ' · ' ) }}</div></div>
								<span :class="[ 'bd-small', t.is_overdue ? 'bd-late' : 'bd-muted' ]">{{ t.due_date ? fmtDate( t.due_date ) : '' }}</span>
							</li>
							<li v-if="!tasks.items.length" class="bd-muted">No open tasks for this client.</li>
						</ul>
					</el-card>
				</div>
				<el-card shadow="never" class="bd-widget bd-mb bd-people">
					<template #header><div class="bd-widget__head"><span>People</span><a href="#" @click.prevent="tab = 'contacts'">Manage contacts</a></div></template>
					<ul class="bd-list">
						<li v-for="c in client.contacts" :key="c.id">
							<div><div class="bd-strong">{{ c.name }} <span class="bd-badge bd-badge--plain" :class="c.kind === 'primary' ? 'bd-badge--info' : 'bd-badge--neutral'">{{ c.kind === 'primary' ? 'Gets invoices' : 'Copied' }}</span></div><div class="bd-muted bd-small">{{ [ c.job_title, c.email ].filter( Boolean ).join( ' · ' ) }}</div></div>
						</li>
						<li v-if="!client.contacts.length" class="bd-muted">No contacts yet.</li>
					</ul>
				</el-card>
				<el-card v-if="client.notes || client.address" shadow="never" class="bd-mb">
					<el-descriptions :column="1" size="small">
						<el-descriptions-item v-if="client.address" label="Address">{{ client.address }}</el-descriptions-item>
						<el-descriptions-item v-if="client.notes" label="Notes">{{ client.notes }}</el-descriptions-item>
					</el-descriptions>
				</el-card>
			</el-tab-pane>

			<el-tab-pane v-if="store.enabled( 'finance' )" name="billing" lazy>
				<template #label>Billing<span v-if="counts.open" class="bd-tabcount">{{ counts.open }}</span></template>
				<el-radio-group v-model="billTab" class="bd-seg-sm bd-mb" aria-label="Billing view">
					<el-radio-button value="billing">Billing<span v-if="counts.pending" class="bd-chipcount">{{ counts.pending }}</span></el-radio-button>
					<el-radio-button value="invoices">Invoices</el-radio-button>
				</el-radio-group>
				<Billing v-if="billTab === 'billing'" :fixed="{ client_id: Number( id ) }" embedded :currency="client.currency" :reload-key="tick" @changed="refresh" />
				<Invoices v-else :fixed="{ client_id: Number( id ) }" embedded :currency="client.currency" :reload-key="tick" @changed="refresh" />
			</el-tab-pane>

			<el-tab-pane v-if="store.enabled( 'work' )" name="work" lazy>
				<template #label>Work<span v-if="tasks.open" class="bd-tabcount">{{ tasks.open }}</span></template>
				<h3 class="bd-subhead" style="margin-top: 4px">Projects</h3>
				<Projects :fixed="{ client_id: Number( id ) }" embedded :reload-key="tick" @changed="refresh" />
				<h3 class="bd-subhead">Tasks</h3>
				<Tasks :fixed="{ client_id: Number( id ) }" embedded :reload-key="tick" @changed="refresh" />
			</el-tab-pane>

			<el-tab-pane v-if="store.enabled( 'contracts' )" label="Contracts" name="contracts" lazy>
				<ResourceTable :config="contractsConfig( { fixed: { client_id: Number( id ) } } )" :fixed="{ client_id: Number( id ) }" embedded :reload-key="tick" />
			</el-tab-pane>

			<el-tab-pane name="contacts" lazy>
				<template #label>Contacts<span v-if="client.contacts && client.contacts.length" class="bd-tabcount">{{ client.contacts.length }}</span></template>
				<ClientContacts :client-id="id" :reload-key="tick" @changed="refresh" />
				<ClientPortalCard :client-id="id" :reload-key="tick" />
			</el-tab-pane>

			<el-tab-pane label="History" name="history" lazy>
				<ClientLedger v-if="store.enabled( 'finance' )" :client-id="id" :reload-key="tick" />
				<h3 class="bd-subhead">Activity</h3>
				<el-card shadow="never" class="bd-mb">
					<ul v-if="history.length" class="bd-activity">
						<li v-for="a in shownHistory" :key="a.id"><span>{{ a.summary }}</span><time>{{ fmtDateTime( a.created_at ) }}</time></li>
					</ul>
					<el-empty v-else description="No activity yet." :image-size="72" />
					<div v-if="history.length > 8" class="bd-mt"><el-button text type="primary" @click="allHistory = !allHistory">{{ allHistory ? 'Show fewer' : `Show all ${ history.length }` }}</el-button></div>
				</el-card>
			</el-tab-pane>
		</el-tabs>

		<RecordDrawer v-model="edit.open" title="Edit client" :fields="clientFields()" :record="client" :save="saveClient" @saved="refresh" />
	</div>
</template>
