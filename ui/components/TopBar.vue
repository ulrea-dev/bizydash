<script setup>
import { computed } from 'vue';
import { Check, Menu, Monitor, Moon, Sun } from 'lucide-vue-next';
import { api } from '../api';
import { store, routeModule } from '../store';
import GlobalSearch from './GlobalSearch.vue';

const emit = defineEmits( [ 'menu' ] );

const THEMES = [ { id: 'light', label: 'Light', icon: Sun }, { id: 'dark', label: 'Dark', icon: Moon }, { id: 'auto', label: 'System', icon: Monitor } ];
const pick = ( id ) => {
	store.setTheme( id );
	api.post( 'preferences', { theme: id } ).catch( () => {} );
};

/** "Work › Projects" style trail built from the navigation itself. */
const trail = computed( () => {
	const p = ( store.route.path || '/' ).replace( /\/+$/, '' ) || '/';
	const first = store.route.parts[ 0 ] || '';
	const mod = store.nav.modules.find( ( m ) => m.id === routeModule( first ) );
	if ( mod ) {
		const item = [ ...mod.items ].filter( ( i ) => ! i.url && i.route && i.route !== mod.route && ( p === i.route || p.startsWith( i.route + '/' ) ) ).sort( ( a, b ) => b.route.length - a.route.length )[ 0 ];
		return [ mod.label, item ? item.label : p === mod.route ? 'Overview' : '' ].filter( Boolean );
	}
	const all = [ ...store.nav.core, ...store.nav.footer ];
	const hit = all.find( ( i ) => ( i.route === '/' ? p === '/' : p === i.route || p.startsWith( i.route + '/' ) ) );
	return [ hit ? hit.label : 'BizyDash' ];
} );
</script>

<template>
	<header class="bd-top">
		<button type="button" class="bd-iconbtn bd-top__menu" aria-label="Open menu" @click="emit( 'menu' )"><el-icon :size="20"><Menu :stroke-width="1.75" /></el-icon></button>
		<div class="bd-top__trail">
			<template v-for="( t, i ) in trail" :key="t"><span v-if="i" class="bd-top__sep">/</span><span :class="{ 'is-last': i === trail.length - 1 }">{{ t }}</span></template>
		</div>
		<div class="bd-top__tools">
			<GlobalSearch />
			<el-dropdown trigger="click" placement="bottom-end">
				<button type="button" class="bd-iconbtn" aria-label="Theme">
					<el-icon :size="18"><component :is="store.themePref === 'auto' ? Monitor : store.theme === 'dark' ? Moon : Sun" :stroke-width="1.75" /></el-icon>
				</button>
				<template #dropdown>
					<div class="bd-more__menu bd-thememenu">
						<button v-for="t in THEMES" :key="t.id" type="button" class="bd-menuitem" @click="pick( t.id )">
							<component :is="t.icon" :size="15" :stroke-width="1.75" /> {{ t.label }}
							<Check v-if="store.themePref === t.id" :size="14" :stroke-width="2" class="bd-thememenu__check" />
						</button>
					</div>
				</template>
			</el-dropdown>
		</div>
	</header>
</template>
