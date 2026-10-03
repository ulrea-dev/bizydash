<script setup>
import { computed } from 'vue';
import { BILL_STATUS } from '../util';
import Badge from './Badge.vue';

const props = defineProps( { status: { type: String, default: '' } } );
const def = computed( () => BILL_STATUS[ props.status ] || { label: props.status, tone: 'neutral' } );
</script>

<template>
	<Badge :tone="def.tone" :label="def.label" />
</template>
