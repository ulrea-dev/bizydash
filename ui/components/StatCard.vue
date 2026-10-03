<script setup>
defineProps( { label: String, sub: String, tone: { type: String, default: '' }, to: { type: String, default: '' }, icon: { type: Object, default: null } } );
</script>

<template>
	<a :class="['bd-stat', tone && 'bd-stat--' + tone]" :href="to ? '#' + to : undefined">
		<div class="bd-stat__top">
			<span class="bd-stat__label">{{ label }}</span>
			<div v-if="icon" class="bd-stat__icon"><el-icon :size="18"><component :is="icon" /></el-icon></div>
		</div>
		<div class="bd-stat__value"><slot /></div>
		<span v-if="sub || $slots.sub" class="bd-stat__sub"><slot name="sub">{{ sub }}</slot></span>
	</a>
</template>
