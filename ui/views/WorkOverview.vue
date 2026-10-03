<script setup>
import { computed, onMounted, ref } from 'vue';
import { Finished, Timer, Warning, CircleCheck, FolderOpened } from '@element-plus/icons-vue';
import { api, errorMessage } from '../api';
import PageHeader from '../components/PageHeader.vue';
import StatCard from '../components/StatCard.vue';
import { fmtDate } from '../util';
import { navigate } from '../store';

const d = ref( null );
const error = ref( '' );
onMounted( async () => {
	try {
		d.value = await api.getPath( 'work/overview' );
	} catch ( e ) {
		error.value = errorMessage( e );
	}
} );
const s = computed( () => ( d.value ? d.value.stats : {} ) );
const open = ( t ) => navigate( t.project_id ? `/work/projects/${ t.project_id }` : '/work/all' );
</script>

<template>
	<div class="bd-overview">
		<PageHeader eyebrow="Work" title="Overview" description="What is due, who is busy, and which projects are moving.">
			<el-button @click="navigate( '/work/projects' )">Projects</el-button>
			<el-button type="primary" @click="navigate( '/work/mine' )">My tasks</el-button>
		</PageHeader>
		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />
		<el-skeleton v-else-if="!d" :rows="6" animated />
		<template v-else>
			<el-row :gutter="16" class="bd-mb">
				<el-col :xs="12" :md="6"><StatCard label="Open tasks" to="/work/all" :icon="Finished" :sub="s.mine ? s.mine + ' assigned to you' : 'Across the team'"><span class="bd-stat__text">{{ s.open }}</span></StatCard></el-col>
				<el-col :xs="12" :md="6"><StatCard label="Overdue" to="/work/all" :icon="Warning" :tone="s.overdue ? 'danger' : ''" :sub="s.overdue ? 'Needs attention' : 'Nothing is late'"><span class="bd-stat__text">{{ s.overdue }}</span></StatCard></el-col>
				<el-col :xs="12" :md="6"><StatCard label="Due this week" to="/work/all" :icon="Timer" :sub="s.done_week + ' finished in the last 7 days'"><span class="bd-stat__text">{{ s.due_week }}</span></StatCard></el-col>
				<el-col :xs="12" :md="6"><StatCard label="Active projects" to="/work/projects" :icon="FolderOpened" :tone="s.projects_late ? 'warning' : ''" :sub="s.projects_late ? s.projects_late + ' past their due date' : 'All on schedule'"><span class="bd-stat__text">{{ s.projects }}</span></StatCard></el-col>
			</el-row>

			<el-row :gutter="20">
				<el-col :xs="24" :lg="12">
					<el-card shadow="never" class="bd-widget bd-mb">
						<template #header><div class="bd-widget__head"><span>{{ d.has_me ? 'Your tasks' : 'Overdue' }}</span><a href="#/work/mine">{{ d.has_me ? 'All mine' : 'All tasks' }}</a></div></template>
						<ul class="bd-list">
							<li v-for="t in ( d.has_me ? d.mine : d.overdue )" :key="t.id" class="bd-click" @click="open( t )">
								<div><div class="bd-strong">{{ t.title }}</div><div class="bd-muted bd-small">{{ [ t.client_name, t.assignee_name ].filter( Boolean ).join( ' · ' ) }}</div></div>
								<span :class="[ 'bd-small', t.due_date && t.due_date < new Date().toISOString().slice( 0, 10 ) ? 'bd-late' : 'bd-muted' ]">{{ t.due_date ? fmtDate( t.due_date ) : '' }}</span>
							</li>
							<li v-if="!( d.has_me ? d.mine : d.overdue ).length" class="bd-muted">{{ d.has_me ? 'You have no open tasks.' : 'Nothing is overdue.' }}</li>
						</ul>
					</el-card>
					<el-card shadow="never" class="bd-widget bd-mb">
						<template #header><div class="bd-widget__head"><span>Due in the next 7 days</span><a href="#/work/all">All tasks</a></div></template>
						<ul class="bd-list">
							<li v-for="t in d.soon" :key="t.id" class="bd-click" @click="open( t )">
								<div><div class="bd-strong">{{ t.title }}</div><div class="bd-muted bd-small">{{ [ t.client_name, t.assignee_name || 'Unassigned' ].filter( Boolean ).join( ' · ' ) }}</div></div>
								<span class="bd-small bd-muted">{{ fmtDate( t.due_date ) }}</span>
							</li>
							<li v-if="!d.soon.length" class="bd-muted">Nothing due this week.</li>
						</ul>
					</el-card>
				</el-col>
				<el-col :xs="24" :lg="12">
					<el-card shadow="never" class="bd-widget bd-mb">
						<template #header><div class="bd-widget__head"><span>Projects in motion</span><a href="#/work/projects">All projects</a></div></template>
						<ul class="bd-list">
							<li v-for="p in d.projects" :key="p.id" class="bd-click" @click="navigate( `/work/projects/${ p.id }` )">
								<div style="flex: 1; min-width: 0">
									<div class="bd-strong">{{ p.name }}</div>
									<div class="bd-muted bd-small">{{ p.client_name || 'Internal' }}{{ p.due_date ? ' · due ' + fmtDate( p.due_date ) : '' }}</div>
									<div class="bd-prog bd-mt-s"><div class="bd-prog__bar"><span :style="{ width: p.progress + '%' }" /></div></div>
								</div>
								<span class="bd-small" :class="p.is_overdue ? 'bd-late' : 'bd-muted'">{{ p.total ? `${ p.done }/${ p.total }` : 'No tasks' }}</span>
							</li>
							<li v-if="!d.projects.length" class="bd-muted">No active projects. <a class="bd-link" href="#/work/projects">Start one</a>.</li>
						</ul>
					</el-card>
					<el-card shadow="never" class="bd-widget bd-mb">
						<template #header><div class="bd-widget__head"><span>Who is busiest</span><a href="#/team">Team</a></div></template>
						<ul class="bd-list">
							<li v-for="p in d.load" :key="p.id">
								<div class="bd-strong">{{ p.name }}</div>
								<span class="bd-small"><span class="bd-muted">{{ p.open_tasks }} open</span><span v-if="Number( p.late )" class="bd-late"> · {{ p.late }} late</span></span>
							</li>
							<li v-if="!d.load.length" class="bd-muted">No tasks are assigned yet.</li>
						</ul>
					</el-card>
				</el-col>
			</el-row>
		</template>
	</div>
</template>

<style>
.bd-click { cursor: pointer; }
.bd-click:hover .bd-strong { text-decoration: underline; }
.bd-mt-s { margin-top: 6px; }
</style>
