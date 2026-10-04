<script setup>
import { ref, onMounted } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage } from '../api';
import Badge from './Badge.vue';

const CAP_LABEL = { manage_bizydash: 'Sign in', bizydash_billing: 'Billing', bizydash_finance: 'Finance', bizydash_work: 'Work', bizydash_contracts: 'Contracts', bizydash_clients: 'Edit clients', bizydash_team: 'Edit team', bizydash_admin: 'Settings & backups', bizydash_portal: 'Client portal' };

const data = ref( null );
const loading = ref( true );
const syncing = ref( false );
const syncMsg = ref( '' );

async function load() {
	loading.value = true;
	try {
		data.value = await api.getPath( 'access' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		loading.value = false;
	}
}

async function toggleAuto( v ) {
	try {
		await api.post( 'settings', { portal_auto_users: v } );
		data.value.auto = v;
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
}

async function syncAll() {
	syncing.value = true;
	syncMsg.value = '';
	try {
		let after = 0;
		let n = 0;
		for ( ;; ) {
			const r = await api.post( 'access/sync-clients', { after } );
			n += r.processed;
			if ( ! r.next ) break;
			after = r.next;
		}
		syncMsg.value = `Checked ${ n } client${ n === 1 ? '' : 's' }.`;
		await load();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		syncing.value = false;
	}
}

onMounted( load );
</script>

<template>
	<div v-loading="loading" style="min-height: 120px">
		<template v-if="data">
			<el-card shadow="never" class="bd-mb">
				<template #header><div class="bd-widget__head"><span>Client logins</span></div></template>
				<p class="bd-muted bd-small">Every client is an organisation with contact people. Each contact who has an email gets a WordPress login with the <strong>BD Client</strong> role, linked to their organisation, ready for the client portal. Nobody is emailed and no password is shared. The people on a client's contacts are the logins for that client.</p>
				<div class="bd-mt"><el-switch :model-value="data.auto" active-text="Create a login automatically when I add a client or contact" @update:model-value="toggleAuto" /></div>
				<div class="bd-mt bd-small">
					<Badge tone="info">{{ data.stats.portal_users }} client login{{ data.stats.portal_users === 1 ? '' : 's' }}</Badge>
					<Badge tone="neutral">{{ data.stats.clients_with_login }} of {{ data.stats.clients }} clients linked</Badge>
				</div>
				<div class="bd-mt">
					<el-button :loading="syncing" @click="syncAll">Create logins for existing clients</el-button>
					<span v-if="syncMsg" class="bd-muted bd-small" style="margin-left: 10px">{{ syncMsg }}</span>
					<div class="bd-help">Clients without any contact email cannot get a login. Add an email to a contact first.</div>
				</div>
			</el-card>

			<el-card shadow="never">
				<template #header><div class="bd-widget__head"><span>Roles</span></div></template>
				<p class="bd-muted bd-small">Give people a role from their profile in Team. These are standard WordPress roles, so you can fine-tune what each one may do with any role editor plugin; BizyDash re-adds anything missing on update but never removes what you add.</p>
				<el-table :data="data.roles" size="small">
					<el-table-column label="Role" min-width="180"><template #default="{ row }"><div class="bd-strong">{{ row.label }}</div><div class="bd-muted bd-small">{{ row.desc }}</div></template></el-table-column>
					<el-table-column label="Can" min-width="260"><template #default="{ row }"><Badge v-for="c in row.caps" :key="c" tone="neutral" style="margin: 0 4px 4px 0">{{ CAP_LABEL[ c ] || c }}</Badge></template></el-table-column>
					<el-table-column label="People" width="90" align="right"><template #default="{ row }">{{ row.users }}</template></el-table-column>
				</el-table>
				<p class="bd-help bd-mt">WordPress administrators always have full access to everything.</p>
			</el-card>
		</template>
	</div>
</template>
