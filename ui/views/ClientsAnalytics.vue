<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage } from '../api';
import AnalyticsBar from '../components/AnalyticsBar.vue';
import Chart from '../components/Chart.vue';
import ChartCard from '../components/ChartCard.vue';
import PageHeader from '../components/PageHeader.vue';
import StatCard from '../components/StatCard.vue';
import ViewSwitch from '../components/ViewSwitch.vue';
import { rangeFor } from '../analytics';
import { fmtDate, fmtMoney, labelOf } from '../util';

const filters = reactive( { period: '90d', ...rangeFor( '90d' ), department_id: 0 } );
const data = ref( null );
const loading = ref( true );

const load = async () => {
	loading.value = true;
	try {
		data.value = await api.getPath( 'clients/analytics', { from: filters.from, to: filters.to, department_id: filters.department_id } );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		loading.value = false;
	}
};
onMounted( load );
watch( () => [ filters.from, filters.to, filters.department_id ], load );

const s = computed( () => ( data.value ? data.value.stats : {} ) );
const m = computed( () => ( data.value ? data.value.money : null ) );
const unit = computed( () => ( data.value ? data.value.granularity : 'month' ) );
const typeKeys = computed( () => Object.keys( data.value ? data.value.by_type : {} ) );
const statusKeys = computed( () => Object.keys( data.value ? data.value.by_status : {} ) );
const curs = computed( () => ( m.value ? Object.keys( m.value.concentration ) : [] ) );
const newTotal = computed( () => ( data.value ? data.value.new_series.reduce( ( a, b ) => a + b, 0 ) : 0 ) );
</script>

<template>
	<div v-loading="loading" class="bd-clan">
		<PageHeader eyebrow="Clients" title="Analytics" description="Who your clients are, how the book is growing, and where the money comes from."><ViewSwitch area="clients" current="analytics" /></PageHeader>
		<AnalyticsBar :model-value="filters" @update:model-value="Object.assign( filters, $event )" />

		<template v-if="data">
			<el-row :gutter="16" class="bd-mb">
				<el-col :xs="12" :md="6"><StatCard label="Clients" :sub="s.active + ' active'">{{ s.total }}</StatCard></el-col>
				<el-col :xs="12" :md="6"><StatCard label="New clients" sub="In this period">{{ s.new }}</StatCard></el-col>
				<el-col :xs="12" :md="6"><StatCard label="Gone quiet" sub="Active, no open work, no invoice in 6 months" :tone="s.quiet ? 'warning' : ''">{{ s.quiet }}</StatCard></el-col>
				<el-col v-if="m && curs.length" :xs="12" :md="6"><StatCard label="Biggest client's share" :sub="curs.length > 1 ? 'Of income, per currency' : 'Of income in this period'"><span v-for="c in curs" :key="c" class="bd-stat__text bd-block">{{ m.concentration[ c ].top1_pct }}%<span v-if="curs.length > 1" class="bd-muted bd-small"> {{ c }}</span></span></StatCard></el-col>
			</el-row>

			<div class="bd-angrid">
				<ChartCard title="New clients" :sub="'Per ' + unit" :empty="!newTotal" empty-text="No new clients in this period.">
					<Chart type="bar" :labels="data.buckets" :series="[ { label: 'New clients', data: data.new_series, color: 'var(--bd-accent)' } ]" :height="220" />
				</ChartCard>
				<ChartCard title="By type and status" :empty="!s.total" empty-text="No clients match.">
					<Chart type="doughnut" :labels="typeKeys.map( ( k ) => labelOf( 'client_type', k ) )" :series="[ { label: 'Clients', data: typeKeys.map( ( k ) => data.by_type[ k ] ) } ]" :height="200" />
					<table class="bd-mini"><tbody><tr v-for="k in statusKeys" :key="k"><td>{{ labelOf( 'client_status', k ) }}</td><td class="bd-num">{{ data.by_status[ k ] }}</td></tr></tbody></table>
				</ChartCard>
			</div>

			<div class="bd-angrid bd-mt">
				<ChartCard title="By department" sub="A client can belong to more than one" :empty="!data.departments.length" empty-text="No clients match.">
					<el-table :data="data.departments" size="small">
						<el-table-column label="Department" min-width="200" prop="name" />
						<el-table-column label="Clients" align="right" min-width="90" prop="clients" />
					</el-table>
				</ChartCard>
				<ChartCard title="Gone quiet" sub="Worth a check-in" :empty="!data.quiet.length" empty-text="Every active client has recent activity.">
					<el-table :data="data.quiet" size="small">
						<el-table-column label="Client" min-width="180"><template #default="{ row }"><a class="bd-link" :href="'#/clients/' + row.id">{{ row.name }}</a></template></el-table-column>
						<el-table-column label="Last invoice" align="right" min-width="130"><template #default="{ row }">{{ row.last_invoice ? fmtDate( row.last_invoice ) : 'Never' }}</template></el-table-column>
					</el-table>
				</ChartCard>
			</div>

			<template v-if="m">
				<div class="bd-angrid bd-mt">
					<ChartCard title="Where income comes from" sub="Invoiced in this period, by client" :empty="!m.top.length" empty-text="No invoices in this period.">
						<el-table :data="m.top" size="small">
							<el-table-column label="Client" min-width="170"><template #default="{ row }"><a class="bd-link" :href="'#/clients/' + row.id">{{ row.name }}</a></template></el-table-column>
							<el-table-column label="Income" align="right" min-width="130"><template #default="{ row }">{{ fmtMoney( row.income, row.currency ) }}</template></el-table-column>
							<el-table-column label="Share" align="right" min-width="80"><template #default="{ row }">{{ row.share_pct }}%</template></el-table-column>
						</el-table>
						<table v-if="curs.length" class="bd-mini"><tbody><tr v-for="c in curs" :key="c"><td>{{ curs.length > 1 ? c + ' — ' : '' }}top 5 clients</td><td class="bd-num">{{ m.concentration[ c ].top5_pct }}% of income</td><td class="bd-num">avg {{ fmtMoney( m.avg_per_client[ c ], c ) }} per client</td></tr></tbody></table>
					</ChartCard>
					<ChartCard title="Recurring income" sub="Per month, from active subscriptions and retainers" :empty="!m.mrr.length" empty-text="No recurring income yet.">
						<div v-for="r in m.mrr" :key="r.currency" class="bd-mb">
							<div class="bd-strong">{{ fmtMoney( r.total, r.currency ) }} <span class="bd-muted bd-small">/ month from {{ r.clients }} client{{ r.clients === 1 ? '' : 's' }}</span></div>
							<table class="bd-mini"><tbody><tr v-for="t in r.top" :key="t.id"><td><a class="bd-link" :href="'#/clients/' + t.id">{{ t.name }}</a></td><td class="bd-num">{{ fmtMoney( t.amount, r.currency ) }}</td></tr></tbody></table>
						</div>
					</ChartCard>
				</div>

				<ChartCard class="bd-mt" title="Overdue invoices by client" sub="Oldest first" :empty="!m.overdue.length" empty-text="No client is behind on an invoice.">
					<el-table :data="m.overdue" size="small">
						<el-table-column label="Client" min-width="200"><template #default="{ row }"><a class="bd-link" :href="'#/clients/' + row.id">{{ row.name }}</a></template></el-table-column>
						<el-table-column label="Owes" align="right" min-width="140"><template #default="{ row }">{{ fmtMoney( row.balance, row.currency ) }}</template></el-table-column>
						<el-table-column label="Oldest invoice" align="right" min-width="130"><template #default="{ row }"><span class="bd-late">{{ row.days_late }} days late</span></template></el-table-column>
					</el-table>
				</ChartCard>
			</template>
		</template>
	</div>
</template>
