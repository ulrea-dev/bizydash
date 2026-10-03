<script setup>
defineProps( { title: String, description: String, eyebrow: String } );
</script>

<template>
	<div class="bd-pagehead">
		<div>
			<span v-if="eyebrow" class="bd-eyebrow">{{ eyebrow }}</span>
			<h1>{{ title }}</h1>
			<p v-if="description" class="bd-muted">{{ description }}</p>
		</div>
		<div class="bd-pagehead__actions"><slot /></div>
	</div>
</template>
