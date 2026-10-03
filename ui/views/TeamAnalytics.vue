<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage } from '../api';
import AnalyticsBar from '../components/AnalyticsBar.vue';
import Chart from '../components/Chart.vue';
import ChartCard from '../components/ChartCard.vue';
import MoneyMap from '../components/MoneyMap.vue';
import PageHeader from '../components/PageHeader.vue';
import StatCard from '../components/StatCard.vue';
import ViewSwitch from '../components/ViewSwitch.vue';
import { rangeFor } from '../analytics';
import { fmtMoney, humanize } from '../util';

const filters = reactive( { period: '90d', ...rangeFor( '90d' ), department_id: 0 } );
const data = ref( null );
const loading = ref( true );

const load = async () => {
	loading.value = true;
	try {
		data.value = await api.getPath( 'team/analytics', { from: filters.from, to: filters.to, department_id: filters.department_id } );
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
const days = ( n ) => ( n === null || n === undefined ? '—' : n + ( n === 1 ? ' day' : ' days' ) );
const doneChart = computed( () => ( data.value ? data.value.people.filter( ( p ) => p.done || p.open ) : [] ) );
const curs = computed( () => ( m.value ? Object.keys( m.value.series ) : [] ) );
const kindCurs = computed( () => ( m.value ? Object.keys( m.value.by_kind ) : [] ) );
const deptCurs = computed( () => {
	const set = new Set();
	( m.value ? m.value.by_department : [] ).forEach( ( d ) => Object.keys( d.values ).forEach( ( c ) => set.add( c ) ) );
	return [ ...set ];
} );
const deptRows = ( c ) => ( m.value ? m.value.by_department.filter( ( d ) => d.values[ c ] ) : [] );
const kindLabel = ( k ) => ( { phase: 'Project phases', task: 'Tasks', salary: 'Salaries', bonus: 'Bonuses', deduction: 'Deductions', reimbursement: 'Reimbursements', adjustment: 'Adjustments', payment: 'Payments' }[ k ] || humanize( k ) );
</script>

<template>
	<div v-loading="loading" class="bd-tean">
		<PageHeader eyebrow="Team" title="Analytics" description="Who is on the team, what each person is carrying, and where pay goes."><ViewSwitch area="team" current="analytics" /></PageHeader>
		<AnalyticsBar :model-value="filters" @update:model-value="Object.assign( filters, $event )" />

		<template v-if="data">
			<el-row :gutter="16" class="bd-mb">
				<el-col :xs="12" :md="6"><StatCard label="People" :sub="s.employees + ' employees · ' + s.contractors + ' contractors'">{{ s.active }}</StatCard></el-col>
				<el-col :xs="12" :md="6"><StatCard label="Joined" sub="In this period">{{ s.joined }}</StatCard></el-col>
				<el-col :xs="12" :md="6"><StatCard label="Tasks done" sub="In this period">{{ s.done }}</StatCard></el-col>
				<el-col :xs="12" :md="6"><StatCard label="Open tasks" sub="Across the team">{{ s.open }}</StatCard></el-col>
			</el-row>

			<div class="bd-angrid">
				<ChartCard title="Who is carrying what" sub="Tasks done in this period, and open now" :empty="!doneChart.length" empty-text="No tasks are assigned to the people shown.">
					<Chart type="bar" :labels="doneChart.map( ( p ) => p.name )" :series="[ { label: 'Open now', data: doneChart.map( ( p ) => p.open ), color: '#c3c8d2' }, { label: 'Done in period', data: doneChart.map( ( p ) => p.done ), color: 'var(--bd-accent)' } ]" />
				</ChartCard>
				<ChartCard title="By department" sub="People by their own department" :empty="!data.departments.length" empty-text="Nobody matches.">
					<Chart type="doughnut" :labels="data.departments.map( ( d ) => d.name )" :series="[ { label: 'People', data: data.departments.map( ( d ) => d.people ) } ]" :height="220" />
				</ChartCard>
			</div>

			<ChartCard class="bd-mt" title="People" :sub="m ? 'Work and pay for each person' : 'Work for each person'" :empty="!data.people.length" empty-text="Nobody matches.">
				<el-table :data="data.people" size="small">
					<el-table-column label="Person" min-width="190"><template #default="{ row }"><a class="bd-link bd-strong" :href="'#/team/' + row.id">{{ row.name }}</a><div class="bd-muted bd-small">{{ [ humanize( row.kind ), row.department ].filter( Boolean ).join( ' · ' ) }}</div></template></el-table-column>
					<el-table-column label="Open" align="right" min-width="70" prop="open" />
					<el-table-column label="Overdue" align="right" min-width="80"><template #default="{ row }"><span :class="row.overdue && 'bd-late'">{{ row.overdue }}</span></template></el-table-column>
					<el-table-column label="Projects" align="right" min-width="80" prop="projects" />
					<el-table-column label="Done" align="right" min-width="70" prop="done" />
					<el-table-column label="Avg to finish" align="right" min-width="110"><template #default="{ row }">{{ days( row.avg_cycle_days ) }}</template></el-table-column>
					<template v-if="m">
						<el-table-column label="Earned" align="right" min-width="130"><template #default="{ row }"><MoneyMap :map="Object.fromEntries( Object.entries( row.earned ).map( ( [ c, v ] ) => [ c, { amount: v, count: 1 } ] ) )" empty="—" /></template></el-table-column>
						<el-table-column label="Owed now" align="right" min-width="130"><template #default="{ row }"><MoneyMap :map="Object.fromEntries( Object.entries( row.owed ).map( ( [ c, v ] ) => [ c, { amount: v, count: 1 } ] ) )" empty="—" /></template></el-table-column>
					</template>
				</el-table>
			</ChartCard>

			<template v-if="m">
				<div class="bd-angrid bd-mt">
					<ChartCard v-for="c in curs" :key="'s' + c" :title="'Pay earned' + ( curs.length > 1 ? ' — ' + c : '' )" :sub="'Per ' + unit">
						<Chart type="bar" :labels="data.buckets" :series="[ { label: 'Pay earned', data: m.series[ c ], color: 'var(--bd-accent)' } ]" :currency="c" :height="220" />
					</ChartCard>
					<ChartCard v-for="c in kindCurs" :key="'k' + c" :title="'Where pay goes' + ( kindCurs.length > 1 ? ' — ' + c : '' )" sub="By type of pay">
						<Chart type="doughnut" :labels="Object.keys( m.by_kind[ c ] ).map( kindLabel )" :series="[ { label: 'Pay', data: Object.values( m.by_kind[ c ] ) } ]" :currency="c" :height="220" />
					</ChartCard>
					<ChartCard v-for="c in deptCurs" :key="'d' + c" :title="'Pay by department earned for' + ( deptCurs.length > 1 ? ' — ' + c : '' )" sub="Always every department">
						<table class="bd-mini"><tbody><tr v-for="d in deptRows( c )" :key="d.id"><td>{{ d.name }}</td><td class="bd-num">{{ fmtMoney( d.values[ c ], c ) }}</td></tr></tbody></table>
					</ChartCard>
					<ChartCard title="Monthly salary bill" sub="Active people with a salary" :empty="!Object.keys( m.salary_monthly ).length" empty-text="No salaries set.">
						<table class="bd-mini"><tbody><tr v-for="( v, c ) in m.salary_monthly" :key="c"><td>{{ c }}</td><td class="bd-num">{{ fmtMoney( v, c ) }}</td></tr></tbody></table>
					</ChartCard>
				</div>
			</template>
		</template>
	</div>
</template>
