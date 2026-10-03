<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage } from '../api';
import RecordDrawer from './RecordDrawer.vue';
import { avatarStyle, fmtDate, initials, TASK_STATUS } from '../util';
import { setTaskStatus, taskFields } from '../resources';

const props = defineProps( { fixed: { type: Object, default: () => ( {} ) }, params: { type: Object, default: () => ( {} ) }, reloadKey: { type: Number, default: 0 } } );
const emit = defineEmits( [ 'changed' ] );

const tasks = ref( [] );
const loading = ref( true );
const drawer = reactive( { open: false, record: {} } );
const COLUMNS = [ 'todo', 'doing', 'done' ];

const load = async () => {
	loading.value = true;
	try {
		tasks.value = ( await api.list( 'tasks', { ...props.params, ...props.fixed, per_page: 100 } ) ).items;
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		loading.value = false;
	}
};
watch( () => [ props.reloadKey, JSON.stringify( props.params ), JSON.stringify( props.fixed ) ], load, { immediate: true } );

const col = ( s ) => computed( () => tasks.value.filter( ( t ) => t.status === s ) );
const cols = { todo: col( 'todo' ), doing: col( 'doing' ), done: col( 'done' ) };
const fields = computed( () => taskFields( { fixed: props.fixed } ) );

const move = async ( t, status ) => {
	await setTaskStatus( t, status, () => {
		load();
		emit( 'changed' );
	} );
};
const edit = ( t ) => {
	drawer.record = { ...t };
	drawer.open = true;
};
const save = ( values ) => api.update( 'tasks', values.id, values );
const saved = () => {
	load();
	emit( 'changed' );
};
</script>

<template>
	<div v-loading="loading" class="bd-board">
		<div v-for="s in COLUMNS" :key="s" class="bd-board__col">
			<div class="bd-board__head"><span :class="[ 'bd-badge', 'bd-badge--' + TASK_STATUS[ s ].tone ]">{{ TASK_STATUS[ s ].label }}</span><span class="bd-muted">{{ cols[ s ].value.length }}</span></div>
			<div v-for="t in cols[ s ].value" :key="t.id" class="bd-card">
				<a href="#" class="bd-card__title" :class="{ 'bd-done': s === 'done' }" @click.prevent="edit( t )">{{ t.title }}</a>
				<div v-if="t.project_name || t.client_name" class="bd-muted bd-small">{{ [ t.project_name, t.client_name ].filter( Boolean ).join( ' · ' ) }}</div>
				<div class="bd-card__meta">
					<span v-if="t.due_date" :class="t.is_overdue ? 'bd-late' : 'bd-muted'" class="bd-small">{{ fmtDate( t.due_date ) }}</span>
					<span v-if="t.priority === 'high'" class="bd-badge bd-badge--danger bd-badge--plain">High</span>
					<span v-if="t.assignee_name" class="bd-ava" :style="avatarStyle( t.assignee_name )" :title="t.assignee_name">{{ initials( t.assignee_name ) }}</span>
				</div>
				<div class="bd-card__moves">
					<el-button v-for="m in COLUMNS.filter( ( x ) => x !== s )" :key="m" size="small" text @click="move( t, m )">→ {{ TASK_STATUS[ m ].label }}</el-button>
				</div>
			</div>
			<div v-if="!cols[ s ].value.length" class="bd-board__empty">Nothing here</div>
		</div>
		<RecordDrawer v-model="drawer.open" title="Edit task" :fields="fields" :record="drawer.record" :save="save" @saved="saved" />
	</div>
</template>
