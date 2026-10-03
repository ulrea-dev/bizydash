<script setup>
/** The one status badge: soft tint, 6px radius, never wraps. `color` (optional) tints it with a configured lookup colour. */
defineProps( { tone: { type: String, default: 'neutral' }, label: { type: String, default: '' }, color: { type: String, default: '' } } );
</script>

<template>
	<span :class="[ 'bd-badge', 'bd-badge--' + tone ]" :style="color ? { color, background: color + '1a' } : undefined"><slot>{{ label }}</slot></span>
</template>
