<script setup>
import ResourceTable from '../components/ResourceTable.vue';
import ViewSwitch from '../components/ViewSwitch.vue';
import { teamConfig } from '../resources';
</script>

<template>
	<ResourceTable :config="teamConfig()" use-route-filters>
		<template #header-actions><ViewSwitch area="team" current="list" /></template>
	</ResourceTable>
</template>
