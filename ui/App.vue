<script setup>
import { computed, ref, watch, watchEffect, onBeforeUnmount } from 'vue';
import { routeModule, store } from './store';
import Sidebar from './components/Sidebar.vue';
import TopBar from './components/TopBar.vue';
import Activity from './views/Activity.vue';
import ClientDetail from './views/ClientDetail.vue';
import Clients from './views/Clients.vue';
import ClientsAnalytics from './views/ClientsAnalytics.vue';
import TeamAnalytics from './views/TeamAnalytics.vue';
import Dashboard from './views/Dashboard.vue';
import DepartmentDetail from './views/DepartmentDetail.vue';
import DepartmentsOverview from './views/DepartmentsOverview.vue';
import Finance from './views/Finance.vue';
import InvoiceEditor from './views/InvoiceEditor.vue';
import Team from './views/Team.vue';
import TeamDetail from './views/TeamDetail.vue';
import ProjectDetail from './views/ProjectDetail.vue';
import Work from './views/Work.vue';
import WorkOverview from './views/WorkOverview.vue';
import Settings from './views/Settings.vue';
import Legal from './views/Legal.vue';
import ContractDetail from './views/ContractDetail.vue';
import ContractsOverview from './views/ContractsOverview.vue';
import ModuleOff from './views/ModuleOff.vue';

const menuOpen = ref( false );
watch( () => store.route.path, () => ( menuOpen.value = false ) );

const view = computed( () => {
	const [ section, id ] = store.route.parts;
	const mod = routeModule( section );
	if ( ! store.enabled( mod ) ) {
		return { c: ModuleOff, key: 'off-' + mod, props: { module: mod } };
	}
	const p = store.route.parts;
	switch ( section ) {
		case 'clients':
			if ( id === 'analytics' ) {
				return { c: ClientsAnalytics, key: 'clients-analytics' };
			}
			return id ? { c: ClientDetail, key: 'client-' + id, props: { id } } : { c: Clients, key: 'clients' };
		case 'billing':
			if ( p[ 1 ] === 'invoices' && p[ 2 ] ) {
				return { c: InvoiceEditor, key: 'inv-' + p[ 2 ] + ':' + ( store.route.query.client || '' ), props: { id: p[ 2 ] } };
			}
			return { c: Finance, key: 'finance-billing', props: { section: 'billing', sub: p[ 1 ] || '' } };
		case 'renewals':
			return { c: Finance, key: 'finance-billing', props: { section: 'billing', sub: 'recurring' } };
		case 'charges':
			return { c: Finance, key: 'finance-billing', props: { section: 'billing', sub: 'one-off' } };
		case 'invoices':
			return { c: Finance, key: 'finance-billing', props: { section: 'billing', sub: 'invoices' } };
		case 'finance':
			if ( p[ 1 ] === 'billing' && p[ 2 ] === 'invoices' && p[ 3 ] ) {
				return { c: InvoiceEditor, key: 'inv-' + p[ 3 ] + ':' + ( store.route.query.client || '' ), props: { id: p[ 3 ] } };
			}
			return { c: Finance, key: 'finance-' + ( p[ 1 ] || '' ) + '-' + ( p[ 2 ] || '' ), props: { section: p[ 1 ] || '', sub: p[ 2 ] || '' } };
		case 'team':
			if ( id === 'analytics' ) {
				return { c: TeamAnalytics, key: 'team-analytics' };
			}
			return id ? { c: TeamDetail, key: 'team-' + id, props: { id } } : { c: Team, key: 'team' };
		case 'work':
			if ( p[ 1 ] === 'projects' && p[ 2 ] ) {
				return { c: ProjectDetail, key: 'project-' + p[ 2 ], props: { id: p[ 2 ] } };
			}
			if ( ! p[ 1 ] ) {
				return { c: WorkOverview, key: 'work-overview' };
			}
			return { c: Work, key: 'work-' + p[ 1 ], props: { tab: p[ 1 ] } };
		case 'legal':
		case 'contracts':
			if ( ! p[ 1 ] ) {
				return { c: ContractsOverview, key: 'contracts-overview' };
			}
			if ( ! [ 'all', 'contracts', 'templates', 'analytics' ].includes( p[ 1 ] ) ) {
				return { c: ContractDetail, key: 'contract-' + p[ 1 ] + ':' + ( store.route.query.client || '' ), props: { id: p[ 1 ] } };
			}
			return { c: Legal, key: 'contracts-' + p[ 1 ], props: { tab: [ 'templates', 'analytics' ].includes( p[ 1 ] ) ? p[ 1 ] : 'all' } };
		case 'catalog':
		case 'services':
			return { c: Finance, key: 'finance-catalog', props: { section: 'catalog' } };
		case 'departments':
			return id !== undefined && /^\d+$/.test( id ) ? { c: DepartmentDetail, key: 'dept-' + id, props: { id } } : { c: DepartmentsOverview, key: 'depts' };
		case 'brands':
			return { c: Settings, key: 'settings-brands', props: { initialTab: 'brands' } };
		case 'activity':
			return { c: Activity, key: 'activity' };
		case 'settings':
			return { c: Settings, key: 'settings-' + ( store.route.query.tab || '' ), props: { initialTab: store.route.query.tab || 'general' } };
		default:
			return { c: Dashboard, key: 'dashboard' };
	}
} );

// Element Plus dark variables hang off html.dark; scope the toggle to this screen's lifetime.
watchEffect( () => {
	document.documentElement.classList.toggle( 'dark', store.theme === 'dark' );
} );
onBeforeUnmount( () => document.documentElement.classList.remove( 'dark' ) );

</script>

<template>
	<div :class="[ 'bd-app', 'bd-shell', store.theme === 'dark' && 'dark', store.fullscreen && 'is-fullscreen' ]">
		<Sidebar :open="menuOpen" @close="menuOpen = false" />
		<div class="bd-shell__main">
			<TopBar @menu="menuOpen = !menuOpen" />
			<main class="bd-main">
				<component :is="view.c" :key="view.key" v-bind="view.props || {}" />
			</main>
		</div>
	</div>
</template>
