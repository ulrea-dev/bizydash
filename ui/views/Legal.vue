<script setup>
import { computed } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import ResourceTable from '../components/ResourceTable.vue';
import { contractsConfig, contractTemplatesConfig } from '../resources';
import ContractsAnalytics from './ContractsAnalytics.vue';

const props = defineProps( { tab: { type: String, default: 'all' } } );
const templates = computed( () => props.tab === 'templates' );
const analytics = computed( () => props.tab === 'analytics' );
</script>

<template>
	<div class="bd-legal">
		<PageHeader v-if="analytics" eyebrow="Contracts" title="Analytics" description="How agreements move: sent, signed, how long it takes, and where signers drop off." />
		<PageHeader v-else-if="!templates" eyebrow="Contracts" title="All contracts" description="Agreements your clients sign online — with the proof to back them up." />
		<PageHeader v-else eyebrow="Contracts" title="Templates" description="Reusable agreements. Insert fields like the client name and they fill in automatically." />
		<ContractsAnalytics v-if="analytics" />
		<ResourceTable v-else-if="!templates" :config="contractsConfig()" use-route-filters embedded />
		<ResourceTable v-else :config="contractTemplatesConfig()" embedded />
	</div>
</template>
