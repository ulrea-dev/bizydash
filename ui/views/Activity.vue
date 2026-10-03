<script setup>
import ResourceTable from '../components/ResourceTable.vue';
import { activityConfig } from '../resources';

defineProps( { fixed: { type: Object, default: () => ( {} ) }, embedded: Boolean, reloadKey: { type: Number, default: 0 } } );
</script>

<template><ResourceTable :config="activityConfig( { embedded } )" :fixed="fixed" :embedded="embedded" :reload-key="reloadKey" /></template>
