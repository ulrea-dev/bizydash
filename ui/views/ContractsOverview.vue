<script setup>
import { onMounted, ref } from 'vue';
import { DocumentChecked, Edit, Clock, Select } from '@element-plus/icons-vue';
import { api, errorMessage } from '../api';
import PageHeader from '../components/PageHeader.vue';
import StatCard from '../components/StatCard.vue';
import { fmtDate } from '../util';
import { navigate, store } from '../store';

const d = ref( null );
const error = ref( '' );
onMounted( async () => {
	try {
		d.value = await api.getPath( 'contracts/overview' );
	} catch ( e ) {
		error.value = errorMessage( e );
	}
} );
const waiting = ( c ) => c.waiting_on || '';
const days = ( c ) => {
	if ( ! c.sent_at ) {
		return '';
	}
	const n = Math.max( 0, Math.floor( ( Date.now() - new Date( c.sent_at.replace( ' ', 'T' ) ).getTime() ) / 86400000 ) );
	return n === 0 ? 'sent today' : `sent ${ n } day${ n === 1 ? '' : 's' } ago`;
};
const verifyUrl = ( store.homeUrl || '/' ).replace( /\/?$/, '/' ) + 'verify';
</script>

<template>
	<div class="bd-overview">
		<PageHeader eyebrow="Contracts" title="Overview" description="What is waiting to be signed, and what has just been sealed.">
			<el-button tag="a" :href="verifyUrl" target="_blank">Verify a certificate</el-button>
			<el-button @click="navigate( '/contracts/templates' )">Templates</el-button>
			<el-button type="primary" @click="navigate( '/contracts/new' )">New contract</el-button>
		</PageHeader>
		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />
		<el-skeleton v-else-if="!d" :rows="6" animated />
		<template v-else>
			<el-row :gutter="16" class="bd-mb">
				<el-col :xs="12" :md="6"><StatCard label="Awaiting signatures" to="/contracts/all?status=sent" :icon="Clock" :tone="d.stats.awaiting ? 'warning' : ''" sub="Sent, not complete yet"><span class="bd-stat__text">{{ d.stats.awaiting }}</span></StatCard></el-col>
				<el-col :xs="12" :md="6"><StatCard label="Drafts" to="/contracts/all?status=draft" :icon="Edit" sub="Not sent yet"><span class="bd-stat__text">{{ d.stats.drafts }}</span></StatCard></el-col>
				<el-col :xs="12" :md="6"><StatCard label="Signed this month" to="/contracts/all?status=signed" :icon="Select" :tone="d.stats.signed_month ? 'success' : ''" :sub="d.stats.signed + ' signed in total'"><span class="bd-stat__text">{{ d.stats.signed_month }}</span></StatCard></el-col>
				<el-col :xs="12" :md="6"><StatCard label="All contracts" to="/contracts/all" :icon="DocumentChecked" :sub="d.stats.declined + ' declined or voided'"><span class="bd-stat__text">{{ d.stats.total }}</span></StatCard></el-col>
			</el-row>

			<el-row :gutter="20">
				<el-col :xs="24" :lg="12">
					<el-card shadow="never" class="bd-widget bd-mb">
						<template #header><div class="bd-widget__head"><span>Waiting on signatures</span><a href="#/contracts/all?status=sent">See all</a></div></template>
						<ul class="bd-list">
							<li v-for="c in d.awaiting" :key="c.id" class="bd-click" @click="navigate( `/contracts/${ c.id }` )">
								<div style="min-width: 0"><div class="bd-strong">{{ c.title }}</div><div class="bd-muted bd-small">{{ [ c.number, c.client_name ].filter( Boolean ).join( ' · ' ) }}{{ waiting( c ) ? ' · waiting on ' + waiting( c ) : '' }}</div></div>
								<span class="bd-small bd-muted" style="text-align: right">{{ c.signer_done }}/{{ c.signer_total }} signed<br>{{ days( c ) }}</span>
							</li>
							<li v-if="!d.awaiting.length" class="bd-muted">Nothing is waiting. <a class="bd-link" href="#/contracts/new">Send a contract</a>.</li>
						</ul>
					</el-card>
					<el-card shadow="never" class="bd-widget bd-mb">
						<template #header><div class="bd-widget__head"><span>Drafts</span><a href="#/contracts/all?status=draft">See all</a></div></template>
						<ul class="bd-list">
							<li v-for="c in d.drafts" :key="c.id" class="bd-click" @click="navigate( `/contracts/${ c.id }` )">
								<div><div class="bd-strong">{{ c.title }}</div><div class="bd-muted bd-small">{{ [ c.number, c.client_name ].filter( Boolean ).join( ' · ' ) }}</div></div>
								<span class="bd-small bd-muted">Continue →</span>
							</li>
							<li v-if="!d.drafts.length" class="bd-muted">No drafts.</li>
						</ul>
					</el-card>
				</el-col>
				<el-col :xs="24" :lg="12">
					<el-card shadow="never" class="bd-widget bd-mb">
						<template #header><div class="bd-widget__head"><span>Recently signed</span><a href="#/contracts/all?status=signed">See all</a></div></template>
						<ul class="bd-list">
							<li v-for="c in d.signed" :key="c.id" class="bd-click" @click="navigate( `/contracts/${ c.id }` )">
								<div><div class="bd-strong">{{ c.title }}</div><div class="bd-muted bd-small">{{ [ c.number, c.client_name ].filter( Boolean ).join( ' · ' ) }}</div></div>
								<span class="bd-small bd-muted">{{ fmtDate( c.completed_at ) }}</span>
							</li>
							<li v-if="!d.signed.length" class="bd-muted">Nothing signed yet.</li>
						</ul>
					</el-card>
				</el-col>
			</el-row>
		</template>
	</div>
</template>
