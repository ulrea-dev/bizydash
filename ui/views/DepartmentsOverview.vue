<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage } from '../api';
import AnalyticsBar from '../components/AnalyticsBar.vue';
import MoneyMap from '../components/MoneyMap.vue';
import PageHeader from '../components/PageHeader.vue';
import { rangeFor } from '../analytics';
import { dept } from '../util';
import { navigate, store } from '../store';

const filters = reactive( { period: 'ytd', ...rangeFor( 'ytd' ) } );
const data = ref( null );
const loading = ref( true );

const load = async () => {
	loading.value = true;
	try {
		data.value = await api.getPath( 'departments/overview', { from: filters.from, to: filters.to } );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		loading.value = false;
	}
};
onMounted( load );
watch( () => [ filters.from, filters.to ], load );

const s = computed( () => ( data.value ? data.value.sections : {} ) );
const asMap = ( o ) => Object.fromEntries( Object.entries( o || {} ).map( ( [ c, v ] ) => [ c, { amount: v, count: 1 } ] ) );
const open = ( id ) => navigate( '/departments/' + id );
</script>

<template>
	<div v-loading="loading" class="bd-depts">
		<PageHeader :title="dept().many" :description="'Everything that belongs to each ' + dept().lower + ': people, clients, work, contracts and money.'">
			<el-button v-if="store.canManage" @click="navigate( '/settings?tab=brands' )">Manage {{ dept().lowerMany }}</el-button>
		</PageHeader>
		<AnalyticsBar :model-value="filters" :show-department="false" @update:model-value="Object.assign( filters, $event )" />

		<p v-if="data && !data.departments.length" class="bd-muted">No {{ dept().lowerMany }} yet — add them in Settings.</p>
		<div class="bd-deptgrid">
			<a v-for="d in ( data ? data.departments : [] )" :key="d.id" class="bd-deptcard" :href="'#/departments/' + d.id" @click.prevent="open( d.id )">
				<div class="bd-deptcard__head">
					<strong>{{ d.name }}</strong>
					<span v-if="d.description" class="bd-muted bd-small">{{ d.description }}</span>
				</div>
				<div class="bd-deptcard__nums">
					<span><b>{{ d.clients }}</b> clients</span>
					<span><b>{{ d.people }}</b> people</span>
					<span v-if="s.work"><b>{{ d.open_tasks }}</b> open tasks</span>
					<span v-if="s.work"><b>{{ d.active_projects }}</b> projects</span>
					<span v-if="s.contracts"><b>{{ d.contracts_waiting }}</b> to sign</span>
				</div>
				<div v-if="s.finance" class="bd-deptcard__money">
					<div><span class="bd-muted bd-small">Income</span><MoneyMap :map="asMap( d.income )" empty="—" /></div>
					<div><span class="bd-muted bd-small">Profit</span><MoneyMap :map="asMap( d.profit )" empty="—" /></div>
					<div><span class="bd-muted bd-small">Monthly recurring</span><MoneyMap :map="asMap( d.mrr )" empty="—" /></div>
					<div><span class="bd-muted bd-small">Owed by clients</span><MoneyMap :map="asMap( d.receivable )" empty="—" /></div>
				</div>
			</a>
		</div>
	</div>
</template>
