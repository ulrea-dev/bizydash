<script setup>
import { onMounted, ref } from 'vue';
import { ElMessage } from 'element-plus';
import { ArrowLeft } from '@element-plus/icons-vue';
import { api, errorMessage } from '../api';
import RecordDrawer from '../components/RecordDrawer.vue';
import StatCard from '../components/StatCard.vue';
import ProjectPhases from '../components/ProjectPhases.vue';
import { fmtDate, fmtMoney, PROJECT_STATUS } from '../util';
import { projectFields } from '../resources';
import { navigate, store } from '../store';

const props = defineProps( { id: { type: [ String, Number ], required: true } } );
const project = ref( null );
const error = ref( '' );
const key = ref( 0 );
const editing = ref( false );

const load = async () => {
	try {
		project.value = await api.get( 'projects', props.id );
		error.value = '';
	} catch ( e ) {
		error.value = errorMessage( e );
	}
};
onMounted( load );

const changed = () => {
	key.value++;
	load();
};
const save = ( values ) => api.update( 'projects', props.id, values );
const saved = () => {
	ElMessage.success( 'Project saved' );
	load();
};
const setStatus = async ( status ) => {
	try {
		await api.update( 'projects', props.id, { status } );
		load();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
</script>

<template>
	<div class="bd-project">
		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />
		<template v-if="project">
			<div class="bd-pagehead">
				<div>
					<el-button text :icon="ArrowLeft" @click="navigate( '/work/projects' )">All projects</el-button>
					<span class="bd-eyebrow">Project</span>
					<h1>{{ project.name }}</h1>
					<p class="bd-muted">
						<a v-if="project.client_id" class="bd-link" :href="'#/clients/' + project.client_id">{{ project.client_name }}</a><span v-else>Internal</span>
						· <span :class="[ 'bd-badge', 'bd-badge--' + PROJECT_STATUS[ project.status ].tone ]">{{ PROJECT_STATUS[ project.status ].label }}</span>
						<span v-if="project.due_date"> · Due {{ fmtDate( project.due_date ) }}</span>
						<span v-if="project.owner_name"> · Owner {{ project.owner_name }}</span>
					</p>
				</div>
				<div class="bd-pagehead__actions">
					<el-button v-if="project.status !== 'done'" @click="setStatus( 'done' )">Mark project done</el-button>
					<el-button v-else @click="setStatus( 'active' )">Reopen</el-button>
					<el-button type="primary" @click="editing = true">Edit project</el-button>
				</div>
			</div>

			<el-card shadow="never" class="bd-mb">
				<div class="bd-prog bd-prog--lg">
					<div class="bd-prog__bar"><span :style="{ width: project.progress + '%' }" /></div>
					<div class="bd-muted">{{ project.task_total ? `${ project.task_done } of ${ project.task_total } tasks done (${ project.progress }%)` : 'No tasks yet — add one inside a phase below.' }}</div>
				</div>
				<p v-if="project.description" class="bd-lead">{{ project.description }}</p>
			</el-card>

			<el-row v-if="store.canFinance && project.money" :gutter="16" class="bd-mb">
				<el-col :xs="12" :md="4"><StatCard label="Project price" :sub="project.currency || ''"><span class="bd-stat__text">{{ fmtMoney( project.money.price, project.currency || store.meta.settings.default_currency ) }}</span></StatCard></el-col>
				<el-col :xs="12" :md="4"><StatCard label="Team budget" sub="Across phases"><span class="bd-stat__text">{{ fmtMoney( project.money.budget, project.currency || store.meta.settings.default_currency ) }}</span></StatCard></el-col>
				<el-col :xs="12" :md="4"><StatCard label="Shared out" sub="Allocated to people"><span class="bd-stat__text">{{ fmtMoney( project.money.allocated, project.currency || store.meta.settings.default_currency ) }}</span></StatCard></el-col>
				<el-col :xs="12" :md="4"><StatCard label="Owed to team" :tone="project.money.owed ? 'warning' : ''" sub="Earned, not paid"><span class="bd-stat__text">{{ fmtMoney( project.money.owed, project.currency || store.meta.settings.default_currency ) }}</span></StatCard></el-col>
				<el-col :xs="12" :md="4"><StatCard label="Paid to team" sub="Payouts so far"><span class="bd-stat__text">{{ fmtMoney( project.money.paid, project.currency || store.meta.settings.default_currency ) }}</span></StatCard></el-col>
				<el-col :xs="12" :md="4"><StatCard label="Profit on project" :tone="project.money.margin < 0 ? 'danger' : ''" sub="Price − team pay"><span class="bd-stat__text">{{ fmtMoney( project.money.margin, project.currency || store.meta.settings.default_currency ) }}</span></StatCard></el-col>
			</el-row>
			<ProjectPhases :project="project" :reload-key="key" @changed="changed" />
			<RecordDrawer v-model="editing" title="Edit project" :fields="projectFields()" :record="project" :save="save" @saved="saved" />
		</template>
	</div>
</template>
