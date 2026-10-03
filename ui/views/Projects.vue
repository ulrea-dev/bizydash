<script setup>
import ResourceTable from '../components/ResourceTable.vue';
import { projectsConfig } from '../resources';

const props = defineProps( { fixed: { type: Object, default: () => ( {} ) }, embedded: Boolean, reloadKey: { type: Number, default: 0 }, useRouteFilters: Boolean } );
const emit = defineEmits( [ 'changed' ] );
</script>

<template>
	<ResourceTable :config="projectsConfig( { hideClient: !!fixed.client_id, fixed } )" :fixed="fixed" :embedded="embedded" :use-route-filters="useRouteFilters" :reload-key="reloadKey" @changed="emit( 'changed' )" />
</template>
