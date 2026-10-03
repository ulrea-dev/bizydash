<script setup>
import { computed } from 'vue';
import { Activity, ChevronRight, ExternalLink, FileSignature, LayoutDashboard, LogOut, Network, Settings, SquareCheckBig, UsersRound, Users, Wallet } from 'lucide-vue-next';
import { store, routeModule } from '../store';

const props = defineProps( { open: Boolean } );
const emit = defineEmits( [ 'close' ] );

const ICONS = { home: LayoutDashboard, clients: Users, team: UsersRound, departments: Network, work: SquareCheckBig, finance: Wallet, contracts: FileSignature, activity: Activity, settings: Settings };

/** Old bookmarks (#/billing, #/invoices, #/legal…) still highlight the right entry. */
const LEGACY = { billing: '/finance/billing', invoices: '/finance/billing/invoices', renewals: '/finance/billing', charges: '/finance/billing', catalog: '/finance/catalog', services: '/finance/catalog', legal: '/contracts', brands: '/settings' };
const path = computed( () => {
	const parts = store.route.parts;
	if ( parts[ 0 ] && LEGACY[ parts[ 0 ] ] ) {
		const base = LEGACY[ parts[ 0 ] ];
		return parts[ 0 ] === 'billing' ? base + ( parts[ 1 ] ? '/' + parts[ 1 ] : '' ) : parts[ 0 ] === 'legal' ? '/contracts' + ( parts[ 1 ] ? '/' + parts.slice( 1 ).join( '/' ) : '' ) : base;
	}
	return ( store.route.path || '/' ).replace( /\/+$/, '' ) || '/';
} );
const activeModule = computed( () => routeModule( path.value.split( '/' )[ 1 ] || '' ) );

const coreActive = ( route ) => ( route === '/' ? path.value === '/' : path.value === route || path.value.startsWith( route + '/' ) );
const matches = ( mod, it ) => {
	const p = path.value;
	if ( it.route === mod.route ) {
		return p === mod.route;
	}
	if ( p === it.route || p.startsWith( it.route + '/' ) ) {
		return true;
	}
	return mod.id === 'contracts' && it.route === '/contracts/all' && /^\/contracts\/(\d+|new)/.test( p );
};
/** Only the most specific entry lights up (Invoices, not Billing, on /finance/billing/invoices). */
const itemActive = ( mod, it ) => {
	if ( ! matches( mod, it ) ) {
		return false;
	}
	const best = mod.items.filter( ( x ) => ! x.url && matches( mod, x ) ).reduce( ( a, x ) => ( x.route.length > a.route.length ? x : a ), it );
	return best === it;
};
const initial = computed( () => ( store.user || 'U' ).slice( 0, 1 ).toUpperCase() );
</script>

<template>
	<aside :class="[ 'bd-side', { 'is-open': open } ]" aria-label="BizyDash">
		<a class="bd-side__brand" href="#/" @click="emit( 'close' )">
			<svg viewBox="0 0 32 32" width="30" height="30" aria-hidden="true"><defs><linearGradient id="bdlogo" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#3a4052" /><stop offset="1" stop-color="#14161d" /></linearGradient></defs><rect width="32" height="32" rx="9" fill="url(#bdlogo)" /><path d="M9.5 22V10h6.6a3.9 3.9 0 0 1 1.5 7.5A3.9 3.9 0 0 1 16 22H9.5zm2.9-9.6v2.5h3.2a1.25 1.25 0 0 0 0-2.5h-3.2zm0 5.2v2.5h3.6a1.25 1.25 0 0 0 0-2.5h-3.6z" fill="#fff" /></svg>
			<span>BizyDash</span>
		</a>

		<nav class="bd-side__nav">
			<a v-for="it in store.nav.core" :key="it.route" :href="'#' + it.route" :class="[ 'bd-side__link', { 'is-active': coreActive( it.route ) } ]" @click="emit( 'close' )">
				<el-icon :size="17"><component :is="ICONS[ it.icon ]" :stroke-width="1.75" /></el-icon>{{ it.label }}
			</a>

			<div v-if="store.nav.modules.length" class="bd-side__label">Modules</div>
			<div v-for="m in store.nav.modules" :key="m.id" :class="[ 'bd-side__mod', { 'is-open': activeModule === m.id } ]">
				<a :href="'#' + m.route" :class="[ 'bd-side__link', 'bd-side__link--mod', { 'is-active': activeModule === m.id } ]" @click="emit( 'close' )">
					<el-icon :size="17"><component :is="ICONS[ m.icon ]" :stroke-width="1.75" /></el-icon>{{ m.label }}
					<el-icon class="bd-side__caret" :size="14"><ChevronRight :stroke-width="2" /></el-icon>
				</a>
				<div v-if="activeModule === m.id" class="bd-side__sub">
					<template v-for="it in m.items" :key="it.label">
						<a v-if="it.url" :href="it.url" target="_blank" rel="noopener" class="bd-side__sublink">{{ it.label }}<el-icon :size="12"><ExternalLink :stroke-width="2" /></el-icon></a>
						<a v-else :href="'#' + it.route" :class="[ 'bd-side__sublink', { 'is-active': itemActive( m, it ) } ]" @click="emit( 'close' )">{{ it.label }}</a>
					</template>
				</div>
			</div>
		</nav>

		<div class="bd-side__foot">
			<a v-for="it in store.nav.footer" :key="it.route" :href="'#' + it.route" :class="[ 'bd-side__link', { 'is-active': coreActive( it.route ) } ]" @click="emit( 'close' )">
				<el-icon :size="17"><component :is="ICONS[ it.icon ]" :stroke-width="1.75" /></el-icon>{{ it.label }}
			</a>
			<a v-if="store.canManage && store.adminUrl" class="bd-side__link bd-side__link--quiet" :href="store.adminUrl"><el-icon :size="17"><ExternalLink :stroke-width="1.75" /></el-icon>WordPress admin</a>
			<a v-if="store.logoutUrl" class="bd-side__link bd-side__link--quiet" :href="store.logoutUrl"><el-icon :size="17"><LogOut :stroke-width="1.75" /></el-icon>Sign out</a>
			<div class="bd-side__user">
				<el-avatar :size="30" class="bd-avatar">{{ initial }}</el-avatar>
				<div class="bd-side__who"><strong>{{ store.user }}</strong><span>{{ store.userEmail }}</span></div>
			</div>
		</div>
	</aside>
	<div v-if="open" class="bd-side__scrim" @click="emit( 'close' )" />
</template>
