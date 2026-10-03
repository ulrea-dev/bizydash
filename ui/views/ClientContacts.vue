<script setup>
import { computed } from 'vue';
import ResourceTable from '../components/ResourceTable.vue';
import { contactsConfig } from '../resources';

const props = defineProps( { clientId: { type: [ Number, String ], required: true }, reloadKey: { type: Number, default: 0 } } );
const emit = defineEmits( [ 'changed' ] );

const config = computed( () => contactsConfig() );
const fixed = computed( () => ( { client_id: Number( props.clientId ) } ) );
</script>

<template>
	<div>
		<el-alert type="info" show-icon :closable="false" class="bd-mb" title="Primary contacts receive invoice emails. Secondary contacts are copied (Cc). Your own team members are copied from Settings → Team." />
		<ResourceTable :config="config" :fixed="fixed" embedded :reload-key="reloadKey" @changed="emit( 'changed' )" />
	</div>
</template>
