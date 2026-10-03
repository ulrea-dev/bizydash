<script setup>
import { computed } from 'vue';
import { STATUS_TYPES, humanize } from '../util';
import Badge from './Badge.vue';

const props = defineProps( { status: { type: String, default: '' } } );
// Element tag-type names → badge tones.
const TONE = { success: 'success', warning: 'warning', primary: 'info', danger: 'danger', info: 'neutral' };
const tone = computed( () => TONE[ STATUS_TYPES[ props.status ] ] || 'neutral' );
</script>

<template>
	<Badge :tone="tone" :label="humanize( status )" />
</template>
