<script setup>
defineProps( { title: { type: String, required: true }, sub: { type: String, default: '' }, empty: Boolean, emptyText: { type: String, default: 'No data for this period.' } } );
</script>

<template>
	<section class="bd-chartcard">
		<header class="bd-chartcard__head">
			<div><h3>{{ title }}</h3><p v-if="sub" class="bd-muted bd-small">{{ sub }}</p></div>
			<div class="bd-chartcard__tools"><slot name="tools" /></div>
		</header>
		<p v-if="empty" class="bd-muted bd-chartcard__empty">{{ emptyText }}</p>
		<slot v-else />
	</section>
</template>
