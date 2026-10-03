<script setup>
import { ref, watch, onMounted } from 'vue';
import { api } from '../api';

const props = defineProps( {
	modelValue: { type: [ Number, String ], default: 0 },
	resource: { type: String, required: true },
	label: { type: Function, required: true },
	params: { type: [ Object, null ], default: () => ( {} ) },
	placeholder: { type: String, default: 'Select…' },
	clearable: { type: Boolean, default: true },
	disabled: Boolean,
} );
const emit = defineEmits( [ 'update:modelValue', 'pick' ] );

const items = ref( [] );
const loading = ref( false );

const load = async () => {
	if ( props.params === null ) {
		items.value = [];
		return;
	}
	loading.value = true;
	try {
		items.value = ( await api.list( props.resource, { per_page: 200, ...props.params } ) ).items;
	} catch ( e ) {
		items.value = [];
	} finally {
		loading.value = false;
	}
};

onMounted( load );
watch( () => JSON.stringify( props.params ), load );

const onChange = ( id ) => {
	const val = id || 0;
	emit( 'update:modelValue', val );
	emit( 'pick', items.value.find( ( i ) => i.id === val ) || null );
};
</script>

<template>
	<el-select :model-value="modelValue || null" filterable :disabled="disabled" :clearable="clearable" :loading="loading" :placeholder="placeholder" style="width: 100%" @change="onChange">
		<el-option v-for="i in items" :key="i.id" :label="label( i )" :value="i.id" />
	</el-select>
</template>
