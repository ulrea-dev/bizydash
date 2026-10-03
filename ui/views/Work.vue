<script setup>
import { computed } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import Projects from './Projects.vue';
import Tasks from './Tasks.vue';
import WorkAnalytics from './WorkAnalytics.vue';

const props = defineProps( { tab: { type: String, default: 'mine' } } );
const section = computed( () => ( [ 'mine', 'all', 'projects', 'analytics' ].includes( props.tab ) ? props.tab : 'mine' ) );
const HEAD = {
	mine: [ 'My tasks', 'The tasks assigned to you.' ],
	all: [ 'All tasks', 'Everything that needs doing, across the team.' ],
	projects: [ 'Projects', 'Bigger pieces of work, with their tasks inside.' ],
	analytics: [ 'Analytics', 'How work is flowing — by department, person and project.' ],
};
</script>

<template>
	<div class="bd-work">
		<PageHeader eyebrow="Work" :title="HEAD[ section ][ 0 ]" :description="HEAD[ section ][ 1 ]" />
		<Tasks v-if="section === 'mine'" scope="mine" embedded use-route-filters />
		<Tasks v-else-if="section === 'all'" scope="all" embedded use-route-filters />
		<WorkAnalytics v-else-if="section === 'analytics'" />
		<Projects v-else embedded use-route-filters />
	</div>
</template>
