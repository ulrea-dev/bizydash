<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage } from '../api';
import AnalyticsBar from '../components/AnalyticsBar.vue';
import Badge from '../components/Badge.vue';
import Chart from '../components/Chart.vue';
import ChartCard from '../components/ChartCard.vue';
import RemoteSelect from '../components/RemoteSelect.vue';
import StatCard from '../components/StatCard.vue';
import { rangeFor } from '../analytics';
import { fmtDate, fmtMoney } from '../util';

const filters = reactive( { period: '90d', ...rangeFor( '90d' ), department_id: 0 } );
const assignee = ref( 0 );
const client = ref( 0 );
const data = ref( null );
const loading = ref( true );

const load = async () => {
	loading.value = true;
	try {
		data.value = await api.getPath( 'work/analytics', { from: filters.from, to: filters.to, department_id: filters.department_id, assignee_id: assignee.value || '', client_id: client.value || '' } );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		loading.value = false;
	}
};
onMounted( load );
watch( () => [ filters.from, filters.to, filters.department_id, assignee.value, client.value ], load );

const s = computed( () => ( data.value ? data.value.stats : {} ) );
const days = ( n ) => ( n === null || n === undefined ? '—' : n + ( n === 1 ? ' day' : ' days' ) );
const unit = computed( () => ( data.value ? data.value.granularity : 'week' ) );
const flowEmpty = computed( () => ! data.value || ( ! s.value.created && ! s.value.completed ) );
const STATE = { on_track: [ 'success', 'On track' ], late: [ 'danger', 'Late' ], no_date: [ 'neutral', 'No date' ] };
const money = computed( () => ( data.value && data.value.money ? data.value.money : null ) );
const moneyRows = computed( () => ( money.value || [] ).flatMap( ( d ) => Object.entries( d.values ).map( ( [ c, v ] ) => ( { key: d.id + c, name: d.name, currency: c, ...v } ) ) ) );
const deptEmpty = computed( () => ! data.value || ! data.value.departments.length );
</script>

<template>
	<div v-loading="loading" class="bd-wan">
		<AnalyticsBar :model-value="filters" @update:model-value="Object.assign( filters, $event )">
			<RemoteSelect v-model="assignee" resource="team" :label="( t ) => t.name" :params="{ status: 'active' }" placeholder="Everyone" class="bd-toolbar__filter" aria-label="Person" />
			<RemoteSelect v-model="client" resource="clients" :label="( c ) => c.company_name" :params="{ status: 'active' }" placeholder="All clients" class="bd-toolbar__filter" aria-label="Client" />
		</AnalyticsBar>

		<template v-if="data">
			<el-row :gutter="16" class="bd-mb">
				<el-col :xs="12" :md="4"><StatCard label="Open tasks" :sub="s.overdue ? s.overdue + ' overdue' : 'None overdue'" :tone="s.overdue ? 'warning' : ''">{{ s.open }}</StatCard></el-col>
				<el-col :xs="12" :md="4"><StatCard label="Completed" sub="In this period">{{ s.completed }}</StatCard></el-col>
				<el-col :xs="12" :md="4"><StatCard label="Created" sub="In this period">{{ s.created }}</StatCard></el-col>
				<el-col :xs="12" :md="4"><StatCard label="Time to finish" :sub="s.median_cycle_days !== null ? 'Median ' + days( s.median_cycle_days ) : 'No finished tasks'">{{ days( s.avg_cycle_days ) }}</StatCard></el-col>
				<el-col :xs="12" :md="4"><StatCard label="Done on time" :sub="s.with_due ? s.on_time + ' of ' + s.with_due + ' with a due date' : 'No due dates'">{{ s.on_time_pct === null ? '—' : s.on_time_pct + '%' }}</StatCard></el-col>
				<el-col :xs="12" :md="4"><StatCard label="Projects late" :sub="data.schedule.on_track + ' on track'" :tone="data.schedule.late ? 'danger' : ''">{{ data.schedule.late }}</StatCard></el-col>
			</el-row>

			<div class="bd-angrid">
				<ChartCard title="Created vs completed" :sub="'Per ' + unit" :empty="flowEmpty" empty-text="No tasks were created or completed in this period.">
					<Chart type="bar" :labels="data.buckets" :series="[ { label: 'Created', data: data.flow.created, color: '#c3c8d2' }, { label: 'Completed', data: data.flow.completed, color: 'var(--bd-accent)' } ]" />
				</ChartCard>
				<ChartCard title="Open tasks" sub="Right now, by status and priority" :empty="!s.open" empty-text="Nothing open.">
					<Chart type="doughnut" :labels="[ 'To do', 'Doing' ]" :series="[ { label: 'Open', data: [ data.open_status.todo, data.open_status.doing ] } ]" :height="200" />
					<table class="bd-mini"><tbody><tr><td>Normal priority</td><td class="bd-num">{{ data.open_priority.normal }}</td></tr><tr><td>High priority</td><td class="bd-num">{{ data.open_priority.high }}</td></tr></tbody></table>
				</ChartCard>
			</div>

			<div class="bd-angrid bd-mt">
				<ChartCard title="Workload by person" sub="Open now · completed in this period" :empty="!data.people.length" empty-text="No tasks match.">
					<el-table :data="data.people" size="small">
						<el-table-column label="Person" min-width="150"><template #default="{ row }"><span :class="row.id ? 'bd-strong' : 'bd-muted'">{{ row.name }}</span></template></el-table-column>
						<el-table-column label="Open" align="right" min-width="70" prop="open" />
						<el-table-column label="Overdue" align="right" min-width="80"><template #default="{ row }"><span :class="row.overdue && 'bd-late'">{{ row.overdue }}</span></template></el-table-column>
						<el-table-column label="Done" align="right" min-width="70" prop="done" />
						<el-table-column label="Avg to finish" align="right" min-width="110"><template #default="{ row }">{{ days( row.avg_cycle_days ) }}</template></el-table-column>
					</el-table>
				</ChartCard>
				<ChartCard title="Tasks by department" sub="A task follows its phase's department, else its project's" :empty="deptEmpty" empty-text="No tasks match.">
					<Chart type="bar" :labels="data.departments.map( ( d ) => d.name )" :series="[ { label: 'Open', data: data.departments.map( ( d ) => d.open ), color: '#c3c8d2' }, { label: 'Overdue', data: data.departments.map( ( d ) => d.overdue ), color: 'var(--bd-danger)' }, { label: 'Done in period', data: data.departments.map( ( d ) => d.done ), color: 'var(--bd-accent)' } ]" />
				</ChartCard>
			</div>

			<ChartCard class="bd-mt" title="Projects" :sub="data.schedule.on_track + ' on track · ' + data.schedule.late + ' late · ' + data.schedule.no_date + ' without a due date'" :empty="!data.projects.length" empty-text="No open projects match.">
				<el-table :data="data.projects" size="small">
					<el-table-column label="Project" min-width="200"><template #default="{ row }"><a class="bd-link bd-strong" :href="'#/work/projects/' + row.id">{{ row.name }}</a><div class="bd-muted bd-small">{{ row.client_name || 'Internal' }}</div></template></el-table-column>
					<el-table-column label="Progress" min-width="180"><template #default="{ row }"><div class="bd-prog"><div class="bd-prog__bar"><span :style="{ width: row.progress + '%' }" /></div><div class="bd-muted bd-small">{{ row.total ? row.done + ' of ' + row.total + ' tasks' : 'No tasks yet' }}</div></div></template></el-table-column>
					<el-table-column label="Due" min-width="130"><template #default="{ row }">{{ row.due_date ? fmtDate( row.due_date ) : '—' }}<div v-if="row.days_late" class="bd-late bd-small">{{ row.days_late }} days late</div></template></el-table-column>
					<el-table-column label="" min-width="100" align="right"><template #default="{ row }"><Badge :tone="STATE[ row.state ][ 0 ]" :label="STATE[ row.state ][ 1 ]" /></template></el-table-column>
				</el-table>
			</ChartCard>

			<ChartCard v-if="money" class="bd-mt" title="Project value and team pay by department" sub="Phase price and team budget as planned · pay earned in this period · per currency">
				<el-table :data="moneyRows" size="small" empty-text="No phases or pay in this period.">
					<el-table-column label="Department" min-width="170" prop="name" />
					<el-table-column label="Project value" align="right" min-width="130"><template #default="{ row }">{{ fmtMoney( row.price, row.currency ) }}</template></el-table-column>
					<el-table-column label="Team budget" align="right" min-width="130"><template #default="{ row }">{{ fmtMoney( row.budget, row.currency ) }}</template></el-table-column>
					<el-table-column label="Shared out" align="right" min-width="130"><template #default="{ row }">{{ fmtMoney( row.allocated, row.currency ) }}</template></el-table-column>
					<el-table-column label="Earned (period)" align="right" min-width="140"><template #default="{ row }">{{ fmtMoney( row.earned, row.currency ) }}</template></el-table-column>
					<el-table-column label="Paid out" align="right" min-width="120"><template #default="{ row }">{{ fmtMoney( row.paid, row.currency ) }}</template></el-table-column>
				</el-table>
			</ChartCard>
		</template>
	</div>
</template>
