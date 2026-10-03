<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { Edit, Finished, Coin, Wallet, Calendar } from '@element-plus/icons-vue';
import { api, errorMessage } from '../api';
import MoneyMap from '../components/MoneyMap.vue';
import RecordDrawer from '../components/RecordDrawer.vue';
import StatCard from '../components/StatCard.vue';
import StatusTag from '../components/StatusTag.vue';
import TeamPay from '../components/TeamPay.vue';
import Projects from './Projects.vue';
import Tasks from './Tasks.vue';
import { avatarStyle, fmtDate, fmtMoney, initials, relDays, today } from '../util';
import { teamFields } from '../resources';
import { navigate, store } from '../store';

const props = defineProps( { id: { type: [ String, Number ], required: true } } );
const member = ref( null );
const error = ref( '' );
const tab = ref( 'overview' );
const key = ref( 0 );
const editing = ref( false );
const tasks = ref( [] );
const projects = ref( [] );

const load = async () => {
	try {
		const [ m, tk, pr ] = await Promise.all( [
			api.get( 'team', props.id ),
			api.list( 'tasks', { assignee_id: props.id, open: 1, per_page: 8 } ),
			api.list( 'projects', { owner_id: props.id, per_page: 8 } ),
		] );
		member.value = m;
		tasks.value = tk.items;
		projects.value = pr.items;
		error.value = '';
	} catch ( e ) {
		error.value = errorMessage( e );
	}
};
onMounted( load );
watch( key, load );

const changed = () => key.value++;
const save = ( values ) => {
	const body = {};
	teamFields().forEach( ( f ) => ( body[ f.name ] = values[ f.name ] ) );
	return api.update( 'team', props.id, body );
};
const saved = () => {
	ElMessage.success( 'Saved' );
	load();
};
const linkMe = async () => {
	try {
		await api.update( 'team', props.id, { wp_user_id: store.userId } );
		ElMessage.success( 'Linked — “My tasks” now shows your work.' );
		load();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
const kindLabel = computed( () => ( member.value && member.value.kind === 'contractor' ? 'Contractor' : 'Employee' ) );
const fixedWork = computed( () => ( { assignee_id: Number( props.id ) } ) );
const fixedOwner = computed( () => ( { owner_id: Number( props.id ) } ) );
const salaryText = computed( () => ( member.value && Number( member.value.salary_amount ) > 0 ? `${ fmtMoney( member.value.salary_amount, member.value.currency || store.meta.settings.default_currency ) } per month` : member.value && member.value.kind === 'contractor' ? 'Paid per project' : 'No salary set' ) );
</script>

<template>
	<div v-if="error && !member"><el-alert :title="error" type="error" show-icon :closable="false" /></div>
	<el-skeleton v-else-if="!member" :rows="8" animated />
	<div v-else class="bd-detail">
		<el-breadcrumb separator="/" class="bd-mb">
			<el-breadcrumb-item><a href="#/team">Team</a></el-breadcrumb-item>
			<el-breadcrumb-item>{{ member.name }}</el-breadcrumb-item>
		</el-breadcrumb>

		<div class="bd-hero">
			<span class="bd-ava bd-ava--lg" :style="avatarStyle( member.name )">{{ initials( member.name ) }}</span>
			<div class="bd-hero__main">
				<h1>{{ member.name }}</h1>
				<div class="bd-chips">
					<span class="bd-muted">{{ member.role || kindLabel }}</span>
					<span class="bd-badge bd-badge--neutral bd-badge--plain">{{ kindLabel }}</span>
					<span v-if="member.department_name" class="bd-badge bd-badge--info bd-badge--plain">{{ member.department_name }}</span>
					<StatusTag :status="member.status" />
					<span v-if="member.is_me" class="bd-badge bd-badge--success bd-badge--plain">This is you</span>
				</div>
			</div>
			<el-button :icon="Edit" @click="editing = true">Edit</el-button>
		</div>

		<el-row :gutter="16" class="bd-mb">
			<el-col v-if="store.canFinance" :xs="12" :md="6"><StatCard label="Owed now" :icon="Coin" :tone="member.owed && Object.keys( member.owed ).length ? 'warning' : ''" sub="Earned, not paid yet"><MoneyMap :map="member.owed" empty="Nothing" /></StatCard></el-col>
			<el-col v-if="store.canFinance" :xs="12" :md="6"><StatCard label="Paid this year" :icon="Wallet" sub="Payouts so far"><MoneyMap :map="member.paid_year" empty="Nothing yet" /></StatCard></el-col>
			<el-col :xs="12" :md="6"><StatCard label="Open tasks" :icon="Finished" :tone="member.overdue_tasks ? 'danger' : ''" :sub="member.overdue_tasks ? member.overdue_tasks + ' overdue' : 'Nothing overdue'"><span class="bd-stat__text">{{ member.open_tasks }}</span></StatCard></el-col>
			<el-col :xs="12" :md="6"><StatCard label="Active projects" :icon="Calendar" sub="With open tasks"><span class="bd-stat__text">{{ member.active_projects }}</span></StatCard></el-col>
		</el-row>

		<el-tabs v-model="tab" class="bd-tabs">
			<el-tab-pane label="Overview" name="overview">
				<el-row :gutter="20">
					<el-col :xs="24" :lg="12">
						<el-card shadow="never" class="bd-widget bd-mb">
							<template #header><div class="bd-widget__head"><span>Doing now</span><a href="#" @click.prevent="tab = 'work'">All work</a></div></template>
							<ul class="bd-list">
								<li v-for="t in tasks" :key="t.id">
									<div><div class="bd-strong">{{ t.title }}</div><div class="bd-muted bd-small">{{ [ t.project_name, t.client_name ].filter( Boolean ).join( ' · ' ) || 'Internal' }}</div></div>
									<span :class="[ 'bd-small', t.is_overdue ? 'bd-late' : 'bd-muted' ]">{{ t.due_date ? fmtDate( t.due_date ) : '' }}</span>
								</li>
								<li v-if="!tasks.length" class="bd-muted">No open tasks.</li>
							</ul>
						</el-card>
					</el-col>
					<el-col :xs="24" :lg="12">
						<el-card shadow="never" class="bd-widget bd-mb">
							<template #header><div class="bd-widget__head"><span>Projects they own</span></div></template>
							<ul class="bd-list">
								<li v-for="p in projects" :key="p.id">
									<div><a class="bd-strong bd-link" :href="'#/work/projects/' + p.id">{{ p.name }}</a><div class="bd-muted bd-small">{{ p.client_name || 'Internal' }} · {{ p.task_done }} of {{ p.task_total }} tasks done</div></div>
									<span class="bd-small bd-muted">{{ p.due_date ? fmtDate( p.due_date ) : '' }}</span>
								</li>
								<li v-if="!projects.length" class="bd-muted">Not the owner of any project.</li>
							</ul>
						</el-card>
					</el-col>
				</el-row>
			</el-tab-pane>

			<el-tab-pane name="work" lazy>
				<template #label>Work<span v-if="member.open_tasks" class="bd-tabcount">{{ member.open_tasks }}</span></template>
				<Projects :fixed="fixedOwner" embedded :reload-key="key" @changed="changed" />
				<div class="bd-mt"><Tasks :fixed="fixedWork" embedded :reload-key="key" @changed="changed" /></div>
			</el-tab-pane>

			<el-tab-pane v-if="store.canFinance" name="pay" lazy>
				<template #label>Pay<span v-if="member.owed && Object.keys( member.owed ).length" class="bd-tabcount">!</span></template>
				<TeamPay :member="member" :reload-key="key" @changed="changed" />
			</el-tab-pane>

			<el-tab-pane label="Details" name="details">
				<el-card shadow="never" class="bd-mb">
					<el-descriptions :column="1" size="small" border>
						<el-descriptions-item label="Email">{{ member.email }}</el-descriptions-item>
						<el-descriptions-item label="Type">{{ kindLabel }}</el-descriptions-item>
						<el-descriptions-item v-if="store.canFinance" label="Pay">{{ salaryText }}</el-descriptions-item>
						<el-descriptions-item v-if="member.start_date" label="Started">{{ fmtDate( member.start_date ) }}</el-descriptions-item>
						<el-descriptions-item v-if="store.canFinance && member.bank_name" label="Bank">{{ member.bank_name }} · {{ member.account_name }} · {{ member.account_number }}</el-descriptions-item>
						<el-descriptions-item label="Invoice emails">{{ member.cc_invoices ? 'Copied on every invoice' : 'Not copied' }}</el-descriptions-item>
						<el-descriptions-item label="Login">{{ member.is_me ? 'This is you' : member.wp_user_id ? 'Linked to a login' : 'Not linked to a login' }} <a v-if="!member.is_me" href="#" class="bd-link" @click.prevent="linkMe">This is me</a></el-descriptions-item>
						<el-descriptions-item v-if="member.notes" label="Notes">{{ member.notes }}</el-descriptions-item>
					</el-descriptions>
				</el-card>
			</el-tab-pane>
		</el-tabs>

		<RecordDrawer v-model="editing" title="Edit team member" :fields="teamFields()" :record="member" :save="save" size="560px" @saved="saved" />
	</div>
</template>
