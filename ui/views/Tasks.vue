<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage } from '../api';
import ResourceTable from '../components/ResourceTable.vue';
import TaskBoard from '../components/TaskBoard.vue';
import { tasksConfig } from '../resources';
import { store } from '../store';

const props = defineProps( { fixed: { type: Object, default: () => ( {} ) }, embedded: Boolean, scope: { type: String, default: 'all' }, reloadKey: { type: Number, default: 0 }, useRouteFilters: Boolean } );
const emit = defineEmits( [ 'changed' ] );

const view = ref( 'list' );
const quick = ref( '' );
const adding = ref( false );
const key = ref( 0 );
const showDone = ref( !! ( props.fixed.project_id || props.fixed.client_id ) );
const myTeamId = ref( 0 );
const teamLoaded = ref( false );

const loadTeam = async () => {
	try {
		const res = await api.list( 'team', { per_page: 100 } );
		const me = res.items.find( ( t ) => t.is_me );
		myTeamId.value = me ? me.id : 0;
	} catch ( e ) {
		myTeamId.value = 0;
	} finally {
		teamLoaded.value = true;
	}
};
onMounted( loadTeam );

const params = computed( () => ( {
	...( props.scope === 'mine' ? { mine: 1 } : {} ),
	...( showDone.value ? {} : { open: 1 } ),
} ) );
const config = computed( () => tasksConfig( { hideClient: !! props.fixed.client_id, hideProject: !! props.fixed.project_id, fixed: props.fixed } ) );
const needsSetup = computed( () => props.scope === 'mine' && teamLoaded.value && ! myTeamId.value );

const bump = () => {
	key.value++;
	emit( 'changed' );
};

const quickAdd = async () => {
	const title = quick.value.trim();
	if ( ! title || adding.value ) {
		return;
	}
	adding.value = true;
	try {
		await api.create( 'tasks', { title, ...props.fixed, ...( props.scope === 'mine' && myTeamId.value ? { assignee_id: myTeamId.value } : {} ) } );
		quick.value = '';
		bump();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		adding.value = false;
	}
};

const setupMe = async () => {
	try {
		await api.create( 'team', { name: store.user || 'Me', email: store.userEmail, cc_invoices: false } );
		await loadTeam();
		bump();
		ElMessage.success( 'Done — tasks you are given will show here.' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};

// A parent asked for a refresh: reload only. (Emitting 'changed' here would bounce back and loop forever.)
watch( () => props.reloadKey, () => key.value++ );
</script>

<template>
	<div class="bd-tasks">
		<el-alert v-if="needsSetup" type="info" show-icon :closable="false" class="bd-mb" title="“My tasks” shows tasks given to you">
			<template #default>You are not in your team list yet. <a href="#" @click.prevent="setupMe">Add me to the team</a> (or link yourself in Settings → Team).</template>
		</el-alert>

		<div class="bd-quickadd">
			<el-input v-model="quick" size="large" placeholder="Add a task and press Enter…" :disabled="adding" aria-label="Quick add a task" @keyup.enter="quickAdd" />
			<el-button type="primary" size="large" :loading="adding" :disabled="!quick.trim()" @click="quickAdd">Add</el-button>
		</div>

		<ResourceTable v-if="view === 'list'" :config="config" :fixed="fixed" :params="params" :use-route-filters="useRouteFilters" embedded :reload-key="key + reloadKey" @changed="bump">
			<template #toolbar>
				<el-switch v-model="showDone" active-text="Show done" class="bd-mr" />
				<el-radio-group v-model="view" class="bd-seg-sm" aria-label="View"><el-radio-button value="list">List</el-radio-button><el-radio-button value="board">Board</el-radio-button></el-radio-group>
			</template>
		</ResourceTable>
		<div v-else>
			<div class="bd-toolbar bd-mb">
				<span class="bd-toolbar__spacer" />
				<el-radio-group v-model="view" class="bd-seg-sm" aria-label="View"><el-radio-button value="list">List</el-radio-button><el-radio-button value="board">Board</el-radio-button></el-radio-group>
			</div>
			<TaskBoard :fixed="fixed" :params="{ ...( scope === 'mine' ? { mine: 1 } : {} ) }" :reload-key="key + reloadKey" @changed="bump" />
		</div>
	</div>
</template>
