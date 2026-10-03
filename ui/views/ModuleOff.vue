<script setup>
import { computed } from 'vue';
import { store } from '../store';

const props = defineProps( { module: { type: String, default: '' } } );
const label = computed( () => ( ( store.modules.find( ( m ) => m.id === props.module ) || {} ).label || 'This module' ) );
</script>

<template>
	<div class="bd-empty-module">
		<h1>{{ label }} is switched off</h1>
		<p class="bd-muted">Your data is safe — switch it back on and everything is there.</p>
		<a v-if="store.canManage" class="el-button el-button--primary" href="#/settings?tab=modules">Open Settings → Modules</a>
		<p v-else class="bd-muted">Ask an administrator to switch it on.</p>
	</div>
</template>

<style>
.bd-empty-module { text-align: center; padding: 96px 16px; display: flex; flex-direction: column; gap: 12px; align-items: center; }
.bd-empty-module h1 { font-size: 24px; }
</style>
