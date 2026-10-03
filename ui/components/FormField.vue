<script setup>
import { ref, onMounted } from 'vue';
import { api } from '../api';
import { dept } from '../util';
import RemoteSelect from './RemoteSelect.vue';
import RichText from './RichText.vue';
import AttachmentField from './AttachmentField.vue';

const props = defineProps( { field: { type: Object, required: true }, values: { type: Object, required: true } } );
const f = props.field;

const set = ( value, picked ) => {
	props.values[ f.name ] = value;
	if ( f.onPick ) {
		const patch = f.onPick( picked !== undefined ? picked : value, props.values );
		if ( patch ) {
			Object.assign( props.values, patch );
		}
	}
};

const opts = () => ( typeof f.options === 'function' ? f.options( props.values ) : f.options || [] );

const brands = ref( [] );
onMounted( async () => {
	if ( f.type === 'brands' ) {
		brands.value = ( await api.list( 'brands', { per_page: 200, status: 'active' } ) ).items;
	}
} );
</script>

<template>
	<el-input v-if="f.type === 'textarea'" :model-value="values[ f.name ]" type="textarea" :rows="f.rows || 3" @update:model-value="set( $event )" />

	<RichText v-else-if="f.type === 'richtext'" :model-value="values[ f.name ] || ''" :fields="f.fields || []" :min-height="f.minHeight || 280" :importable="f.importable" @update:model-value="set( $event )" />

	<el-input-number
		v-else-if="f.type === 'number'"
		:model-value="values[ f.name ] === '' ? undefined : values[ f.name ]"
		:precision="f.step === '1' ? 0 : 2"
		:step="f.step === '1' ? 1 : 100"
		:min="f.min"
		controls-position="right"
		style="width: 100%"
		@update:model-value="set( $event )"
	/>

	<el-date-picker v-else-if="f.type === 'date'" :model-value="values[ f.name ] || null" type="date" value-format="YYYY-MM-DD" format="MMM D, YYYY" placeholder="Pick a date" style="width: 100%" @update:model-value="set( $event || '' )" />

	<el-color-picker v-else-if="f.type === 'color'" :model-value="values[ f.name ] || '#6b7280'" @update:model-value="set( $event )" />

	<el-switch v-else-if="f.type === 'checkbox'" :model-value="!!values[ f.name ]" :active-text="f.checkLabel" @update:model-value="set( $event )" />

	<el-select v-else-if="f.type === 'select'" :model-value="values[ f.name ] === '' ? null : values[ f.name ]" :clearable="!f.required" :placeholder="f.placeholder || 'Select…'" filterable style="width: 100%" @update:model-value="set( $event ?? '' )">
		<el-option v-for="o in opts()" :key="o.value" :label="o.label" :value="o.value" />
	</el-select>

	<RemoteSelect
		v-else-if="f.type === 'remote'"
		:model-value="values[ f.name ]"
		:resource="f.remote.resource"
		:label="f.remote.label"
		:params="f.remote.params ? f.remote.params( values ) : {}"
		:placeholder="f.placeholder || 'Select…'"
		@update:model-value="values[ f.name ] = $event"
		@pick="( item ) => item && f.onPick && Object.assign( values, f.onPick( item, values ) || {} )"
	/>

	<template v-else-if="f.type === 'brands'">
		<el-checkbox-group v-if="brands.length" :model-value="values[ f.name ] || []" @update:model-value="set( $event )">
			<el-checkbox v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }} <small class="bd-muted">({{ b.type }})</small></el-checkbox>
		</el-checkbox-group>
		<span v-else class="bd-muted">No active {{ dept().lowerMany }} yet — add them in Settings → {{ dept().many }}.</span>
	</template>

	<AttachmentField v-else-if="f.type === 'attachment'" :model-value="values[ f.name ] || 0" :url="values[ f.urlKey ] || ''" :title="f.label" @update:model-value="set( $event )" @update:url="values[ f.urlKey ] = $event" />

	<div v-else-if="f.type === 'static'">{{ f.render ? f.render( values ) : values[ f.name ] }}</div>

	<el-input v-else :model-value="values[ f.name ]" :type="f.type || 'text'" :placeholder="f.placeholder" @update:model-value="set( $event )" />
</template>
