<script setup>
import { computed } from 'vue';
import { navigate, store } from '../store';

/** List | Analytics for Clients and Team (core areas, so they carry their own Analytics switch). */
const props = defineProps( { area: { type: String, required: true }, current: { type: String, default: 'list' } } );
const show = computed( () => store.coreAnalytics[ props.area ] !== false );
const go = ( v ) => navigate( v === 'analytics' ? `/${ props.area }/analytics` : `/${ props.area }` );
</script>

<template>
	<el-radio-group v-if="show" :model-value="current" class="bd-seg-sm bd-viewswitch" aria-label="View" @update:model-value="go">
		<el-radio-button value="list">List</el-radio-button>
		<el-radio-button value="analytics">Analytics</el-radio-button>
	</el-radio-group>
</template>
