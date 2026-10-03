<script setup>
import { ref, onMounted, computed } from 'vue';
import { api } from '../api';
import ResourceTable from '../components/ResourceTable.vue';
import ViewSwitch from '../components/ViewSwitch.vue';
import { clientsConfig } from '../resources';

const brands = ref( [] );
onMounted( async () => {
	try {
		brands.value = ( await api.list( 'brands', { per_page: 200 } ) ).items;
	} catch ( e ) {
		brands.value = [];
	}
} );
const config = computed( () => clientsConfig( brands.value ) );
</script>

<template>
	<ResourceTable :config="config" use-route-filters>
		<template #header-actions><ViewSwitch area="clients" current="list" /></template>
	</ResourceTable>
</template>
