<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Search, Plus, MoreFilled } from '@element-plus/icons-vue';
import { api, errorMessage } from '../api';
import { store } from '../store';
import { dept } from '../util';
import CellRenderer from './CellRenderer';
import PageHeader from './PageHeader.vue';
import RecordDrawer from './RecordDrawer.vue';
import BulkDepartmentDialog from './BulkDepartmentDialog.vue';
import RemoteSelect from './RemoteSelect.vue';

const props = defineProps( {
	config: { type: Object, required: true },
	fixed: { type: Object, default: () => ( {} ) },
	embedded: Boolean,
	reloadKey: { type: Number, default: 0 },
	useRouteFilters: Boolean,
	params: { type: Object, default: () => ( {} ) },
} );
const emit = defineEmits( [ 'changed', 'loaded' ] );

const cfg = computed( () => props.config );
const items = ref( [] );
const total = ref( 0 );
const loading = ref( true );
const error = ref( '' );
const page = ref( 1 );
const search = ref( '' );
const filters = reactive( { ...( cfg.value.initialFilters || {} ), ...( props.useRouteFilters ? store.route.query : {} ) } );
// A value from the address is text; a filter whose options are numeric ids needs the number to show its label.
const typed = ( name, v ) => {
	const f = ( cfg.value.filters || [] ).find( ( x ) => x.name === name );
	return f && f.options && f.options.some( ( o ) => typeof o.value === 'number' ) && /^\d+$/.test( String( v ) ) ? Number( v ) : v;
};
Object.keys( filters ).forEach( ( k ) => ( filters[ k ] = typed( k, filters[ k ] ) ) );
const selected = ref( [] );
const bulk = reactive( { open: false } );
const bulkItems = computed( () => selected.value.map( ( r ) => ( { resource: cfg.value.resource, id: r.id } ) ) );
const drawer = reactive( { open: false, isNew: true, id: 0, record: {} } );
let seq = 0;
let timer = null;

const perPage = computed( () => cfg.value.perPage || 20 );
const readOnly = computed( () => !! cfg.value.readOnly );
const visibleFields = computed( () => ( cfg.value.fields || [] ).filter( ( f ) => ! ( f.name in props.fixed ) ) );

const load = async () => {
	const id = ++seq;
	loading.value = true;
	try {
		const res = await api.list( cfg.value.resource, { ...filters, ...props.params, ...props.fixed, search: search.value, page: page.value, per_page: perPage.value } );
		if ( id === seq ) {
			items.value = res.items;
			total.value = res.total;
			emit( 'loaded', res );
			error.value = '';
		}
	} catch ( e ) {
		if ( id === seq ) {
			error.value = errorMessage( e );
		}
	} finally {
		if ( id === seq ) {
			loading.value = false;
		}
	}
};

const reload = () => {
	selected.value = [];
	return load();
};
const afterChange = async () => {
	await reload();
	emit( 'changed' );
};

// A primitive string key: an array getter would look "changed" on every parent render and loop.
watch( () => JSON.stringify( [ page.value, filters, props.fixed, props.params, props.reloadKey ] ), load );
watch( search, () => {
	clearTimeout( timer );
	timer = setTimeout( () => {
		page.value = 1;
		load();
	}, 300 );
} );
watch( () => JSON.stringify( filters ), () => ( page.value = 1 ) );
onMounted( load );
// Pages that take their filters from the address (e.g. a "View all" link) follow it when it changes without remounting.
let lastQuery = props.useRouteFilters ? { ...store.route.query } : {};
watch( () => JSON.stringify( store.route.query ), () => {
	if ( ! props.useRouteFilters ) {
		return;
	}
	const now = store.route.query;
	Object.keys( lastQuery ).forEach( ( k ) => {
		if ( ! ( k in now ) ) {
			delete filters[ k ];
		}
	} );
	Object.keys( now ).forEach( ( k ) => {
		filters[ k ] = typed( k, now[ k ] );
	} );
	lastQuery = { ...now };
} );

const openNew = () => {
	if ( cfg.value.onNew ) {
		cfg.value.onNew();
		return;
	}
	Object.assign( drawer, { open: true, isNew: true, id: 0, record: { ...( cfg.value.defaults || {} ), ...props.fixed } } );
};
const openEdit = ( row ) => {
	if ( cfg.value.onEdit ) {
		cfg.value.onEdit( row );
		return;
	}
	Object.assign( drawer, { open: true, isNew: false, id: row.id, record: { ...row } } );
};

const saveRecord = async ( values ) => {
	const payload = { ...props.fixed };
	( cfg.value.fields || [] ).forEach( ( f ) => {
		if ( f.type !== 'static' && f.name in values ) {
			payload[ f.name ] = values[ f.name ];
		}
	} );
	const res = drawer.isNew ? await api.create( cfg.value.resource, payload ) : await api.update( cfg.value.resource, drawer.id, payload );
	ElMessage.success( `${ cfg.value.singular || 'Record' } saved` );
	return res;
};

const remove = async ( row ) => {
	try {
		await ElMessageBox.confirm( `Delete this ${ cfg.value.singular || 'record' }? This cannot be undone.`, 'Delete', { type: 'warning', confirmButtonText: 'Delete', confirmButtonClass: 'el-button--danger' } );
	} catch ( e ) {
		return;
	}
	try {
		await api.remove( cfg.value.resource, row.id );
		ElMessage.success( 'Deleted' );
		await afterChange();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};

const ctx = { reload: afterChange, openEdit };
const allActions = ( row ) => ( cfg.value.actions ? cfg.value.actions( row, ctx ).filter( ( a ) => a ) : [] );
const rowActions = ( row ) => allActions( row ).filter( ( a ) => ! a.menu );
const menuActions = ( row ) => allActions( row ).filter( ( a ) => a.menu );
const actionsWidth = computed( () => ( readOnly.value ? 0 : 52 ) + ( cfg.value.actionsWidth || 0 ) );
const canSelect = ( row ) => ( cfg.value.isSelectable ? cfg.value.isSelectable( row ) : true );

defineExpose( { reload, load } );
</script>

<template>
	<div :class="['bd-resource', embedded && 'bd-resource--embedded']">
		<PageHeader v-if="!embedded" :title="cfg.title" :description="cfg.description">
			<slot name="header-actions" />
			<el-button v-if="!readOnly" type="primary" :icon="Plus" @click="openNew">{{ cfg.newLabel || 'Add new' }}</el-button>
		</PageHeader>

		<div class="bd-toolbar">
			<el-input v-if="cfg.searchable !== false" v-model="search" :prefix-icon="Search" placeholder="Search…" clearable class="bd-toolbar__search" />
			<template v-for="f in cfg.filters || []" :key="f.name">
				<RemoteSelect v-if="f.remote" :model-value="Number( filters[ f.name ] ) || 0" :resource="f.remote.resource" :label="f.remote.label" :params="f.remote.params || {}" :placeholder="f.label" class="bd-toolbar__filter" :aria-label="f.label" @update:model-value="filters[ f.name ] = $event || ''" />
				<el-select v-else v-model="filters[ f.name ]" :placeholder="f.label" clearable class="bd-toolbar__filter" :aria-label="f.label">
					<el-option v-for="o in f.options" :key="o.value" :label="o.label" :value="o.value" />
				</el-select>
			</template>
			<span class="bd-toolbar__spacer" />
			<div class="bd-toolbar__end">
				<el-button v-if="cfg.bulkDepartment && selected.length && !readOnly" @click="bulk.open = true">Set {{ dept().lower }} ({{ selected.length }})</el-button>
				<slot name="toolbar" :selected="selected" :clear="() => ( selected = [] )" :reload="afterChange" />
				<el-button v-if="embedded && !readOnly" type="primary" :icon="Plus" @click="openNew">{{ cfg.newLabel || 'Add new' }}</el-button>
			</div>
		</div>

		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />

		<div class="bd-tablecard">
			<el-table v-loading="loading" :data="items" :row-key="cfg.rowKey || 'id'" @selection-change="( rows ) => ( selected = rows )">
				<template #empty><el-empty :description="cfg.emptyText || 'Nothing here yet.'" :image-size="72" /></template>
				<el-table-column v-if="cfg.selectable || cfg.bulkDepartment" type="selection" width="44" :selectable="canSelect" />
				<el-table-column v-for="c in cfg.columns" :key="c.prop || c.label" :label="c.label" :prop="c.prop" :width="c.width" :min-width="c.width ? undefined : ( c.minWidth || 112 )" :align="c.align || 'left'">
					<template #default="{ row }"><CellRenderer :col="c" :row="row" :ctx="ctx" /></template>
				</el-table-column>
				<el-table-column v-if="!readOnly || cfg.actions" label="" :min-width="actionsWidth || 64" align="right">
					<template #default="{ row }">
						<div class="bd-rowactions">
							<el-button v-for="a in rowActions( row )" :key="a.label" size="small" :type="a.type" :plain="!!a.type" @click="a.onClick( row, ctx )">{{ a.label }}</el-button>
							<el-dropdown v-if="!readOnly || menuActions( row ).length" trigger="click" placement="bottom-end">
								<el-button size="small" text circle :icon="MoreFilled" aria-label="More actions" />
								<template #dropdown>
									<div class="bd-more__menu">
										<button v-for="a in menuActions( row )" :key="a.label" type="button" class="bd-menuitem" @click="a.onClick( row, ctx )">{{ a.label }}</button>
										<button v-if="!readOnly" type="button" class="bd-menuitem" @click="openEdit( row )">Edit</button>
										<button v-if="!readOnly && cfg.canDelete !== false" type="button" class="bd-menuitem bd-menuitem--danger" @click="remove( row )">Delete</button>
									</div>
								</template>
							</el-dropdown>
						</div>
					</template>
				</el-table-column>
			</el-table>
			<div v-if="total > perPage" class="bd-pager">
				<span>Showing {{ ( page - 1 ) * perPage + 1 }}–{{ Math.min( page * perPage, total ) }} of {{ total }}</span>
				<el-pagination v-model:current-page="page" background layout="prev, pager, next" :total="total" :page-size="perPage" />
			</div>
		</div>

		<BulkDepartmentDialog v-if="cfg.bulkDepartment" v-model="bulk.open" :items="bulkItems" :multi="!!cfg.bulkDepartmentMulti" :noun="cfg.singular || 'record'" @done="afterChange" />
		<RecordDrawer v-if="!readOnly" v-model="drawer.open" :title="( drawer.isNew ? 'New ' : 'Edit ' ) + ( cfg.singular || 'record' )" :fields="visibleFields" :record="drawer.record" :is-new="drawer.isNew" :save="saveRecord" @saved="afterChange" />
	</div>
</template>
