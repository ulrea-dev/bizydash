<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage } from '../api';
import AnalyticsBar from '../components/AnalyticsBar.vue';
import Chart from '../components/Chart.vue';
import ChartCard from '../components/ChartCard.vue';
import StatCard from '../components/StatCard.vue';
import { rangeFor } from '../analytics';
import { fmtDate } from '../util';

const filters = reactive( { period: '90d', ...rangeFor( '90d' ), department_id: 0 } );
const data = ref( null );
const loading = ref( true );

const load = async () => {
	loading.value = true;
	try {
		data.value = await api.getPath( 'contracts/analytics', { from: filters.from, to: filters.to, department_id: filters.department_id } );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		loading.value = false;
	}
};
onMounted( load );
watch( () => [ filters.from, filters.to, filters.department_id ], load );

const s = computed( () => ( data.value ? data.value.stats : {} ) );
/** "3.2 hours" under a day, "2.4 days" beyond. */
const dur = ( h ) => {
	if ( h === null || h === undefined ) return '—';
	if ( h < 1 ) return Math.max( 1, Math.round( h * 60 ) ) + ' min';
	if ( h < 48 ) return Math.round( h * 10 ) / 10 + ' hours';
	return Math.round( ( h / 24 ) * 10 ) / 10 + ' days';
};
const unit = computed( () => ( data.value ? data.value.granularity : 'month' ) );
const flowEmpty = computed( () => ! data.value || ( ! s.value.sent && ! s.value.signed ) );
const STEPS = [ [ 'invited', 'Invited' ], [ 'opened', 'Opened the link' ], [ 'consented', 'Gave consent' ], [ 'verified', 'Verified the code' ], [ 'signed', 'Signed' ] ];
const funnel = computed( () => {
	const f = data.value ? data.value.funnel : {};
	const top = f.invited || 0;
	return STEPS.map( ( [ k, label ], i ) => ( { key: k, label, n: f[ k ] || 0, pct: top ? Math.round( ( ( f[ k ] || 0 ) / top ) * 100 ) : 0, lost: i && top ? ( f[ STEPS[ i - 1 ][ 0 ] ] || 0 ) - ( f[ k ] || 0 ) : 0 } ) );
} );
const OUT = [ [ 'signed', 'Signed' ], [ 'waiting', 'Waiting' ], [ 'declined', 'Declined' ], [ 'void', 'Voided' ], [ 'expired', 'Expired' ] ];
const outcomes = computed( () => ( data.value ? OUT.filter( ( [ k ] ) => data.value.outcomes[ k ] ) : [] ) );
</script>

<template>
	<div v-loading="loading" class="bd-can">
		<AnalyticsBar :model-value="filters" @update:model-value="Object.assign( filters, $event )" />

		<template v-if="data">
			<el-row :gutter="16" class="bd-mb">
				<el-col :xs="12" :md="4"><StatCard label="Sent" sub="In this period">{{ s.sent }}</StatCard></el-col>
				<el-col :xs="12" :md="4"><StatCard label="Signed" sub="Completed in this period">{{ s.signed }}</StatCard></el-col>
				<el-col :xs="12" :md="4"><StatCard label="Completion rate" :sub="s.cohort_pending ? s.cohort_pending + ' still waiting' : 'Of those decided'">{{ s.completion_pct === null ? '—' : s.completion_pct + '%' }}</StatCard></el-col>
				<el-col :xs="12" :md="4"><StatCard label="Time to sign" :sub="s.median_hours !== null ? 'Median ' + dur( s.median_hours ) : 'No signed contracts'">{{ dur( s.avg_hours ) }}</StatCard></el-col>
				<el-col :xs="12" :md="4"><StatCard label="Waiting now" sub="Sent, not finished" :tone="s.waiting ? 'warning' : ''">{{ s.waiting }}</StatCard></el-col>
				<el-col :xs="12" :md="4"><StatCard label="Declined or voided" sub="In this period" :tone="s.declined_void ? 'danger' : ''">{{ s.declined_void }}</StatCard></el-col>
			</el-row>

			<div class="bd-angrid">
				<ChartCard title="Sent vs signed" :sub="'Per ' + unit" :empty="flowEmpty" empty-text="No contracts were sent or signed in this period.">
					<Chart type="bar" :labels="data.buckets" :series="[ { label: 'Sent', data: data.flow.sent, color: '#c3c8d2' }, { label: 'Signed', data: data.flow.signed, color: 'var(--bd-accent)' } ]" />
				</ChartCard>
				<ChartCard title="Where signers drop off" sub="Everyone invited in this period" :empty="!data.funnel.invited" empty-text="Nobody was invited to sign in this period.">
					<div class="bd-funnel">
						<div v-for="st in funnel" :key="st.key" class="bd-funnel__row">
							<span class="bd-funnel__label">{{ st.label }}</span>
							<span class="bd-funnel__bar"><span :style="{ width: st.pct + '%' }" /></span>
							<span class="bd-funnel__n bd-num">{{ st.n }} <span class="bd-muted">· {{ st.pct }}%</span></span>
							<span :class="[ 'bd-funnel__lost bd-small', st.lost > 0 ? 'bd-late' : 'bd-muted' ]">{{ st.lost > 0 ? '−' + st.lost : '' }}</span>
						</div>
					</div>
					<p v-if="s.signer_avg_hours !== null" class="bd-muted bd-small bd-mt">A signer takes {{ dur( s.signer_avg_hours ) }} on average from invitation to signing.</p>
				</ChartCard>
			</div>

			<div class="bd-angrid bd-mt">
				<ChartCard title="What happened to contracts sent in this period" :empty="!outcomes.length" empty-text="No contracts sent in this period.">
					<Chart type="doughnut" :labels="outcomes.map( ( o ) => o[ 1 ] )" :series="[ { label: 'Contracts', data: outcomes.map( ( o ) => data.outcomes[ o[ 0 ] ] ) } ]" :height="210" />
				</ChartCard>
				<ChartCard title="Waiting the longest" sub="Sent but not finished — a nudge may help" :empty="!data.waiting.length" empty-text="Nothing is waiting on a signature.">
					<el-table :data="data.waiting" size="small">
						<el-table-column label="Contract" min-width="150"><template #default="{ row }"><a class="bd-link" :href="'#/contracts/' + row.id">{{ row.client_name || 'Contract #' + row.id }}</a><div class="bd-muted bd-small">Sent {{ fmtDate( row.sent_at ) }}</div></template></el-table-column>
						<el-table-column label="Waiting on" min-width="140" prop="waiting_on" />
						<el-table-column label="For" align="right" min-width="80"><template #default="{ row }"><span :class="row.days_waiting > 7 && 'bd-late'">{{ row.days_waiting }} d</span></template></el-table-column>
					</el-table>
				</ChartCard>
			</div>

			<div class="bd-angrid bd-mt">
				<ChartCard title="Slowest to sign" sub="Average time from sending to the last signature" :empty="!data.slowest_clients.length" empty-text="No signed contracts in this period.">
					<el-table :data="data.slowest_clients" size="small">
						<el-table-column label="Client" min-width="170"><template #default="{ row }"><a v-if="row.id" class="bd-link" :href="'#/clients/' + row.id">{{ row.name }}</a><span v-else>{{ row.name }}</span></template></el-table-column>
						<el-table-column label="Signed" align="right" min-width="80" prop="signed" />
						<el-table-column label="Average" align="right" min-width="110"><template #default="{ row }">{{ dur( row.avg_hours ) }}</template></el-table-column>
					</el-table>
				</ChartCard>
				<ChartCard title="By template" :empty="!data.templates.length" empty-text="No contracts sent in this period.">
					<el-table :data="data.templates" size="small">
						<el-table-column label="Template" min-width="170" prop="name" />
						<el-table-column label="Sent" align="right" min-width="70" prop="sent" />
						<el-table-column label="Signed" align="right" min-width="90"><template #default="{ row }">{{ row.signed }}<span v-if="row.rate_pct !== null" class="bd-muted"> · {{ row.rate_pct }}%</span></template></el-table-column>
						<el-table-column label="Avg time" align="right" min-width="100"><template #default="{ row }">{{ dur( row.avg_hours ) }}</template></el-table-column>
					</el-table>
				</ChartCard>
			</div>

			<ChartCard class="bd-mt" title="By department" :empty="!data.departments.length" empty-text="No contracts sent in this period.">
				<el-table :data="data.departments" size="small">
					<el-table-column label="Department" min-width="200" prop="name" />
					<el-table-column label="Sent" align="right" min-width="80" prop="sent" />
					<el-table-column label="Signed" align="right" min-width="80" prop="signed" />
					<el-table-column label="Declined" align="right" min-width="90" prop="declined" />
				</el-table>
			</ChartCard>
		</template>
	</div>
</template>
