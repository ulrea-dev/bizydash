<script setup>
import { ref, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { Search } from 'lucide-vue-next';
import { api } from '../api';
import { navigate } from '../store';
import { dept } from '../util';

const open = ref( false );
const q = ref( '' );
const results = ref( [] );
const active = ref( 0 );
const loading = ref( false );
const input = ref( null );
let timer = null;
let seq = 0;

const TYPE_LABEL = { client: 'Client', invoice: 'Invoice', service: 'Catalog', subscription: 'Recurring', project: 'Project', task: 'Task', brand: dept().one };

watch( open, async ( v ) => {
	if ( v ) {
		q.value = '';
		results.value = [];
		active.value = 0;
		await nextTick();
		setTimeout( () => input.value && input.value.focus(), 50 );
	}
} );

watch( q, ( term ) => {
	clearTimeout( timer );
	if ( term.trim().length < 2 ) {
		results.value = [];
		return;
	}
	timer = setTimeout( async () => {
		const id = ++seq;
		loading.value = true;
		try {
			const rows = await api.getPath( 'search', { q: term } );
			if ( id === seq ) {
				results.value = rows;
				active.value = 0;
			}
		} catch ( e ) {
			results.value = [];
		} finally {
			loading.value = false;
		}
	}, 220 );
} );

const go = ( item ) => {
	open.value = false;
	if ( item.type === 'client' || ( [ 'invoice', 'subscription' ].includes( item.type ) && item.client_id ) ) {
		navigate( `/clients/${ item.client_id }` );
	} else if ( item.type === 'project' ) {
		navigate( `/work/projects/${ item.id }` );
	} else if ( item.type === 'task' ) {
		navigate( item.project_id ? `/work/projects/${ item.project_id }` : '/work/all' );
	} else if ( item.type === 'service' ) {
		navigate( '/catalog' );
	} else if ( item.type === 'brand' ) {
		navigate( '/departments' );
	}
};

const onKey = ( e ) => {
	if ( e.key === 'ArrowDown' ) {
		active.value = Math.min( active.value + 1, results.value.length - 1 );
		e.preventDefault();
	} else if ( e.key === 'ArrowUp' ) {
		active.value = Math.max( active.value - 1, 0 );
		e.preventDefault();
	} else if ( e.key === 'Enter' && results.value[ active.value ] ) {
		go( results.value[ active.value ] );
	}
};

const shortcut = ( e ) => {
	if ( ( e.metaKey || e.ctrlKey ) && e.key.toLowerCase() === 'k' ) {
		e.preventDefault();
		open.value = true;
	}
};
onMounted( () => document.addEventListener( 'keydown', shortcut ) );
onBeforeUnmount( () => document.removeEventListener( 'keydown', shortcut ) );
</script>

<template>
	<button type="button" class="bd-iconbtn" aria-label="Search (Ctrl/⌘ K)" title="Search (Ctrl/⌘ K)" @click="open = true">
		<el-icon :size="18"><Search :stroke-width="1.75" /></el-icon>
	</button>

	<el-dialog v-model="open" class="bd-search-dialog" :show-close="false" append-to-body destroy-on-close @opened="input && input.focus()">
		<div class="bd-search__bar">
			<el-icon :size="18"><Search :stroke-width="1.75" /></el-icon>
			<input ref="input" v-model="q" type="text" :placeholder="'Search clients, invoices, subscriptions, projects, tasks, ' + dept().lowerMany + '…'" aria-label="Search" @keydown="onKey" />
			<span class="bd-search__kbd">Esc</span>
		</div>
		<div class="bd-search__results">
			<button v-for="( r, i ) in results" :key="r.type + r.id" type="button" :class="[ 'bd-search__item', { 'is-active': i === active } ]" @mouseenter="active = i" @click="go( r )">
				<div>
					<div class="bd-strong">{{ r.label }}</div>
					<div class="bd-muted bd-small">{{ r.sub }}</div>
				</div>
				<span class="bd-badge bd-badge--neutral">{{ TYPE_LABEL[ r.type ] }}</span>
			</button>
			<div v-if="!results.length" class="bd-search__hint">{{ q.trim().length < 2 ? 'Type at least 2 characters to search.' : loading ? 'Searching…' : 'No results.' }}</div>
		</div>
	</el-dialog>
</template>
