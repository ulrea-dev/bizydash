<script setup>
import { computed } from 'vue';
import { BILL_STATE } from '../util';
import Badge from './Badge.vue';

const props = defineProps( { state: { type: String, default: '' } } );
const def = computed( () => BILL_STATE[ props.state ] || { label: props.state, tone: 'neutral' } );
</script>

<template>
	<Badge :tone="def.tone" :label="def.label" />
</template>
