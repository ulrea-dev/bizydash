<script setup>
import { computed, ref, watch } from 'vue';
import { PERIODS, rangeFor } from '../analytics';
import RemoteSelect from './RemoteSelect.vue';
import { dept } from '../util';

/**
 * The one control row every Analytics page uses: period, department and (Finance) currency.
 * v-model is { period, from, to, department_id, currency }; `currencies` adds the currency control.
 */
const props = defineProps( {
	modelValue: { type: Object, required: true },
	currencies: { type: Array, default: null }, // [{ value, label }] — omit to hide the control
	showDepartment: { type: Boolean, default: true },
} );
const emit = defineEmits( [ 'update:modelValue' ] );

const custom = ref( [ props.modelValue.from, props.modelValue.to ] );
const set = ( patch ) => emit( 'update:modelValue', { ...props.modelValue, ...patch } );
const pickPeriod = ( id ) => {
	const r = rangeFor( id, custom.value );
	set( { period: id, ...r } );
};
watch( custom, ( v ) => {
	if ( props.modelValue.period === 'custom' && v && v[ 0 ] && v[ 1 ] ) set( { from: v[ 0 ], to: v[ 1 ] } );
} );
const periods = computed( () => PERIODS );
</script>

<template>
	<div class="bd-anbar">
		<el-radio-group :model-value="modelValue.period" class="bd-seg-sm" aria-label="Period" @update:model-value="pickPeriod">
			<el-radio-button v-for="p in periods" :key="p.id" :value="p.id">{{ p.label }}</el-radio-button>
		</el-radio-group>
		<el-date-picker v-if="modelValue.period === 'custom'" v-model="custom" type="daterange" value-format="YYYY-MM-DD" range-separator="–" start-placeholder="From" end-placeholder="To" size="default" aria-label="Custom range" />
		<RemoteSelect v-if="showDepartment" :model-value="modelValue.department_id || 0" resource="brands" :label="( b ) => b.name" :params="{ status: 'active' }" :placeholder="'All ' + dept().lowerMany" class="bd-toolbar__filter" aria-label="Department" @update:model-value="set( { department_id: $event || 0 } )" />
		<el-radio-group v-if="currencies" :model-value="modelValue.currency" class="bd-seg-sm" aria-label="Currency" @update:model-value="set( { currency: $event } )">
			<el-radio-button v-for="c in currencies" :key="c.value" :value="c.value">{{ c.label }}</el-radio-button>
		</el-radio-group>
		<span class="bd-toolbar__spacer" />
		<slot />
	</div>
</template>
