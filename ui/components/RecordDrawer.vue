<script setup>
import { reactive, ref, watch, computed } from 'vue';
import { errorMessage } from '../api';
import FormField from './FormField.vue';

const props = defineProps( {
	modelValue: Boolean,
	title: { type: String, default: '' },
	fields: { type: Array, required: true },
	record: { type: Object, default: () => ( {} ) },
	isNew: Boolean,
	save: { type: Function, required: true },
	size: { type: String, default: '560px' },
} );
const emit = defineEmits( [ 'update:modelValue', 'saved' ] );

const values = reactive( {} );
const saving = ref( false );
const error = ref( '' );

watch(
	() => props.modelValue,
	( open ) => {
		if ( open ) {
			Object.keys( values ).forEach( ( k ) => delete values[ k ] );
			Object.assign( values, JSON.parse( JSON.stringify( props.record ) ) );
			error.value = '';
		}
	},
	{ immediate: true }
);

const visible = computed( () => props.fields.filter( ( f ) => ! ( f.show && ! f.show( values, props.isNew ) ) && ! ( f.onlyEdit && props.isNew ) ) );
const helpText = ( f ) => ( typeof f.help === 'function' ? f.help( values ) : f.help );

const submit = async () => {
	saving.value = true;
	error.value = '';
	try {
		const res = await props.save( { ...values } );
		emit( 'update:modelValue', false );
		emit( 'saved', res );
	} catch ( e ) {
		error.value = errorMessage( e );
	} finally {
		saving.value = false;
	}
};
</script>

<template>
	<el-drawer :model-value="modelValue" :title="title" :size="size" :close-on-click-modal="false" destroy-on-close append-to-body @update:model-value="emit( 'update:modelValue', $event )">
		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />
		<el-form label-position="top" @submit.prevent>
			<el-row :gutter="16">
				<el-col v-for="f in visible" :key="f.name" :span="f.wide ? 24 : 12" :xs="24">
					<el-form-item :label="typeof f.label === 'function' ? f.label( values ) : f.label" :required="f.required">
						<FormField :field="f" :values="values" />
						<div v-if="helpText( f )" class="bd-help">{{ helpText( f ) }}</div>
					</el-form-item>
				</el-col>
			</el-row>
		</el-form>
		<template #footer>
			<el-button @click="emit( 'update:modelValue', false )">Cancel</el-button>
			<el-button type="primary" :loading="saving" @click="submit">Save</el-button>
		</template>
	</el-drawer>
</template>
