<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { ArrowLeft } from '@element-plus/icons-vue';
import { api, errorMessage } from '../api';
import AnalyticsBar from '../components/AnalyticsBar.vue';
import Badge from '../components/Badge.vue';
import ChartCard from '../components/ChartCard.vue';
import MoneyMap from '../components/MoneyMap.vue';
import StatCard from '../components/StatCard.vue';
import { rangeFor } from '../analytics';
import { CONTRACT_STATUS, dept, fmtDate, fmtMoney, humanize, labelOf } from '../util';
import { navigate, store } from '../store';

const props = defineProps( { id: { type: [ String, Number ], required: true } } );
const filters = reactive( { period: 'ytd', ...rangeFor( 'ytd' ) } );
const data = ref( null );
const loading = ref( true );
const error = ref( '' );

const load = async () => {
	loading.value = true;
	try {
		data.value = await api.getPath( `departments/${ props.id }/overview`, { from: filters.from, to: filters.to } );
		error.value = '';
	} catch ( e ) {
		error.value = errorMessage( e );
	} finally {
		loading.value = false;
	}
};
onMounted( load );
watch( () => [ props.id, filters.from, filters.to ], load );

const d = computed( () => data.value || {} );
const s = computed( () => ( data.value ? data.value.sections : {} ) );
const asMap = ( o ) => Object.fromEntries( Object.entries( o || {} ).map( ( [ c, v ] ) => [ c, { amount: v, count: 1 } ] ) );
const money = computed( () => ( d.value.money || {} ) );
const incomeMap = computed( () => Object.fromEntries( Object.entries( money.value ).map( ( [ c, v ] ) => [ c, { amount: v.income, count: 1 } ] ) ) );
const profitMap = computed( () => Object.fromEntries( Object.entries( money.value ).map( ( [ c, v ] ) => [ c, { amount: v.profit, count: 1 } ] ) ) );
const profitNeg = computed( () => Object.values( money.value ).some( ( v ) => v.profit < 0 ) );
const INV = { draft: 'neutral', sent: 'info', partially_paid: 'warning', overdue: 'danger', paid: 'success', due: 'warning' };
const id = computed( () => Number( props.id ) );
const q = ( route ) => ( id.value ? `${ route }${ route.includes( '?' ) ? '&' : '?' }department_id=${ id.value }` : route );
const contractCounts = computed( () => Object.entries( ( d.value.contracts && d.value.contracts.by_status ) || {} ).map( ( [ k, n ] ) => ( { k, n } ) ) );
</script>

<template>
	<div v-loading="loading" class="bd-deptpage">
		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />
		<template v-if="data">
			<div class="bd-pagehead">
				<div>
					<el-button text :icon="ArrowLeft" @click="navigate( '/departments' )">All {{ dept().lowerMany }}</el-button>
					<h1>{{ d.name }}</h1>
					<p v-if="d.description" class="bd-muted">{{ d.description }}</p>
				</div>
				<div class="bd-pagehead__actions"><el-button v-if="store.canManage && id" @click="navigate( '/settings?tab=brands' )">Edit {{ dept().lower }}</el-button></div>
			</div>

			<AnalyticsBar :model-value="filters" :show-department="false" @update:model-value="Object.assign( filters, $event )" />

			<el-row :gutter="16" class="bd-mb">
				<el-col :xs="12" :md="6"><StatCard label="Clients" :sub="'in this ' + dept().lower" :to="id ? '/clients?brand_id=' + id : ''">{{ d.clients.length }}</StatCard></el-col>
				<el-col :xs="12" :md="6"><StatCard label="People" sub="Active team members" :to="q( '/team' )">{{ d.people.length }}</StatCard></el-col>
				<el-col v-if="s.work" :xs="12" :md="6"><StatCard label="Open tasks" :sub="d.work.overdue ? d.work.overdue + ' overdue' : d.work.done + ' done in period'" :tone="d.work.overdue ? 'warning' : ''" :to="q( '/work/all' )">{{ d.work.open }}</StatCard></el-col>
				<el-col v-if="s.contracts" :xs="12" :md="6"><StatCard label="Contracts" sub="Waiting for signatures">{{ ( d.contracts.by_status.sent || 0 ) + ( d.contracts.by_status.partially_signed || 0 ) }}</StatCard></el-col>
			</el-row>

			<el-row v-if="s.finance" :gutter="16" class="bd-mb">
				<el-col :xs="12" :md="6"><StatCard label="Income" sub="Invoiced in the period"><MoneyMap :map="incomeMap" empty="—" /></StatCard></el-col>
				<el-col :xs="12" :md="6"><StatCard label="Profit" :tone="profitNeg ? 'danger' : ''" sub="After direct costs, team pay and expenses"><MoneyMap :map="profitMap" empty="—" /></StatCard></el-col>
				<el-col :xs="12" :md="6"><StatCard label="Monthly recurring" sub="Subscriptions and retainers"><MoneyMap :map="asMap( d.mrr )" empty="—" /></StatCard></el-col>
				<el-col :xs="12" :md="6"><StatCard label="Owed by clients" sub="Unpaid invoices"><MoneyMap :map="asMap( d.receivable )" empty="—" /></StatCard></el-col>
			</el-row>

			<div class="bd-angrid">
				<ChartCard title="Clients" :empty="!d.clients.length" empty-text="No clients are attached yet.">
					<template #tools><a class="bd-link bd-small" :href="'#/clients?brand_id=' + id" @click.prevent="navigate( '/clients?brand_id=' + id )">View all</a></template>
					<el-table :data="d.clients.slice( 0, 8 )" size="small">
						<el-table-column label="Client" min-width="180"><template #default="{ row }"><a class="bd-link" :href="'#/clients/' + row.id">{{ row.name }}</a></template></el-table-column>
						<el-table-column label="Type" min-width="110"><template #default="{ row }">{{ labelOf( 'client_type', row.type ) }}</template></el-table-column>
						<el-table-column label="Status" min-width="100"><template #default="{ row }">{{ labelOf( 'client_status', row.status ) }}</template></el-table-column>
					</el-table>
				</ChartCard>
				<ChartCard title="People" :empty="!d.people.length" empty-text="Nobody is in this department.">
					<template #tools><a class="bd-link bd-small" :href="'#' + q( '/team' )" @click.prevent="navigate( q( '/team' ) )">View all</a></template>
					<el-table :data="d.people" size="small">
						<el-table-column label="Person" min-width="180"><template #default="{ row }"><a class="bd-link" :href="'#/team/' + row.id">{{ row.name }}</a></template></el-table-column>
						<el-table-column label="Role" min-width="140" prop="role" />
						<el-table-column label="Type" min-width="100"><template #default="{ row }">{{ humanize( row.kind ) }}</template></el-table-column>
					</el-table>
				</ChartCard>
			</div>

			<div v-if="s.work || s.contracts" class="bd-angrid bd-mt">
				<ChartCard v-if="s.work" title="Projects" sub="Active and planned" :empty="!d.projects.length" empty-text="No open projects.">
					<template #tools><a class="bd-link bd-small" :href="'#' + q( '/work/all' )" @click.prevent="navigate( q( '/work/all' ) )">Open tasks</a> <a class="bd-link bd-small bd-ml" :href="'#' + q( '/work/projects' )" @click.prevent="navigate( q( '/work/projects' ) )">View all</a></template>
					<el-table :data="d.projects" size="small">
						<el-table-column label="Project" min-width="170"><template #default="{ row }"><a class="bd-link" :href="'#/work/projects/' + row.id">{{ row.name }}</a><div class="bd-muted bd-small">{{ row.client_name || 'Internal' }}</div></template></el-table-column>
						<el-table-column label="Progress" min-width="150"><template #default="{ row }"><div class="bd-prog"><div class="bd-prog__bar"><span :style="{ width: row.progress + '%' }" /></div><div class="bd-muted bd-small">{{ row.total ? row.done + ' of ' + row.total + ' tasks' : 'No tasks yet' }}</div></div></template></el-table-column>
						<el-table-column label="Due" min-width="110" align="right"><template #default="{ row }">{{ row.due_date ? fmtDate( row.due_date ) : '—' }}</template></el-table-column>
					</el-table>
				</ChartCard>
				<ChartCard v-if="s.contracts" title="Contracts" :empty="!d.contracts.recent.length" empty-text="No contracts in this department.">
					<template #tools><a class="bd-link bd-small" :href="'#' + q( '/contracts/all' )" @click.prevent="navigate( q( '/contracts/all' ) )">View all</a></template>
					<div class="bd-chips bd-mb"><Badge v-for="c in contractCounts" :key="c.k" :tone="( CONTRACT_STATUS[ c.k ] || {} ).tone || 'neutral'" :label="( ( CONTRACT_STATUS[ c.k ] || {} ).label || humanize( c.k ) ) + ' ' + c.n" /></div>
					<el-table :data="d.contracts.recent" size="small">
						<el-table-column label="Contract" min-width="190"><template #default="{ row }"><a class="bd-link" :href="'#/contracts/' + row.id">{{ row.title }}</a><div class="bd-muted bd-small">{{ [ row.number, row.client_name ].filter( Boolean ).join( ' · ' ) }}</div></template></el-table-column>
						<el-table-column label="Status" min-width="130"><template #default="{ row }">{{ ( CONTRACT_STATUS[ row.status ] || {} ).label || humanize( row.status ) }}</template></el-table-column>
					</el-table>
				</ChartCard>
			</div>

			<div v-if="s.billing" class="bd-angrid bd-mt">
				<ChartCard title="Ready to invoice" :sub="'Bills waiting to be invoiced'" :empty="!d.billing.items.length" empty-text="Nothing waiting to be invoiced.">
					<template #tools><a class="bd-link bd-small" :href="'#' + q( '/finance/billing' )" @click.prevent="navigate( q( '/finance/billing' ) )">View all</a></template>
					<el-table :data="d.billing.items" size="small">
						<el-table-column label="What" min-width="190"><template #default="{ row }">{{ row.title }}<div class="bd-muted bd-small">{{ row.client_name }}</div></template></el-table-column>
						<el-table-column label="Amount" min-width="110" align="right"><template #default="{ row }">{{ fmtMoney( row.price, row.currency ) }}</template></el-table-column>
					</el-table>
				</ChartCard>
				<ChartCard title="Recent invoices" :empty="!d.invoices.length" empty-text="No invoices yet.">
					<template #tools><a class="bd-link bd-small" :href="'#' + q( '/finance/billing/invoices' )" @click.prevent="navigate( q( '/finance/billing/invoices' ) )">View all</a></template>
					<el-table :data="d.invoices" size="small">
						<el-table-column label="Invoice" min-width="190"><template #default="{ row }"><a class="bd-link" :href="'#/finance/billing/invoices/' + row.id">{{ row.number }}</a><div class="bd-muted bd-small">{{ [ row.title, row.client_name ].filter( Boolean ).join( ' · ' ) }}</div></template></el-table-column>
						<el-table-column label="Amount" min-width="120" align="right"><template #default="{ row }">{{ fmtMoney( row.amount, row.currency ) }}</template></el-table-column>
						<el-table-column label="" min-width="100" align="right"><template #default="{ row }"><Badge :tone="INV[ row.status ] || 'neutral'" :label="humanize( row.status )" /></template></el-table-column>
					</el-table>
				</ChartCard>
			</div>

			<div v-if="s.finance" class="bd-angrid bd-mt">
				<ChartCard title="Cash and team pay" sub="Received and paid in the period, and what is still owed">
					<table class="bd-mini"><tbody>
						<tr><td>Received from clients</td><td class="bd-num"><MoneyMap :map="asMap( d.cash.received )" empty="—" /></td></tr>
						<tr><td>Paid out to the team</td><td class="bd-num"><MoneyMap :map="asMap( d.cash.paid_out )" empty="—" /></td></tr>
						<tr><td>Owed to the team now</td><td class="bd-num"><MoneyMap :map="asMap( d.cash.team_owed )" empty="—" /></td></tr>
					</tbody></table>
				</ChartCard>
				<ChartCard title="Spending" sub="Expenses in the period" :empty="!d.expense_categories.length" empty-text="No expenses in this period.">
					<table class="bd-mini"><tbody>
						<tr v-for="c in d.expense_categories" :key="c.category"><td>{{ labelOf( 'expense_category', c.category ) }}</td><td class="bd-num"><MoneyMap :map="asMap( c.values )" empty="—" /></td></tr>
					</tbody></table>
				</ChartCard>
			</div>
		</template>
	</div>
</template>
