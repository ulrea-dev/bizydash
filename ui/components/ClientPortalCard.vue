<script setup>
import { ref, onMounted, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage } from '../api';
import { store } from '../store';
import { fmtDateTime } from '../util';

const props = defineProps( { clientId: { type: [ String, Number ], required: true }, reloadKey: { type: Number, default: 0 } } );

const info = ref( null );
const sending = ref( 0 );

async function load() {
	try {
		info.value = await api.getPath( `clients/${ props.clientId }/logins` );
	} catch ( e ) {
		info.value = null;
	}
}

async function invite( u ) {
	sending.value = u.id;
	try {
		await api.post( `clients/${ props.clientId }/portal-invite`, { user_id: u.id } );
		ElMessage.success( `Invitation sent to ${ u.email }.` );
		await load();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		sending.value = 0;
	}
}

const openPreview = () => window.open( info.value.preview_url, '_blank' );

onMounted( load );
watch( () => [ props.clientId, props.reloadKey ], load );
</script>

<template>
	<el-card v-if="info" shadow="never" class="bd-mb">
		<template #header>
			<div class="bd-widget__head"><span>Client portal</span>
				<el-button size="small" @click="openPreview">View portal as this client</el-button>
			</div>
		</template>
		<p class="bd-muted bd-small">Each contact with an email can sign in to the client portal to see this client's projects, invoices and contracts. Their login is created automatically. Send an invitation so they can choose a password.</p>
		<el-alert v-if="!info.enabled" type="warning" show-icon :closable="false" title="The client portal is switched off in Settings → Client portal." class="bd-mb" />
		<el-table v-if="info.users.length" :data="info.users" size="small">
			<el-table-column label="Person" min-width="180"><template #default="{ row }"><div class="bd-strong">{{ row.name }}</div><div class="bd-muted bd-small">{{ row.email }}</div></template></el-table-column>
			<el-table-column label="Status" min-width="170"><template #default="{ row }"><span v-if="row.last_login">Last signed in {{ fmtDateTime( row.last_login ) }}</span><span v-else-if="row.invited" class="bd-muted">Invited {{ fmtDateTime( row.invited ) }}, not signed in yet</span><span v-else class="bd-muted">Not invited yet</span></template></el-table-column>
			<el-table-column width="150" align="right"><template #default="{ row }"><el-button v-if="store.caps.clients" size="small" :loading="sending === row.id" @click="invite( row )">{{ row.invited || row.last_login ? 'Send again' : 'Send invitation' }}</el-button></template></el-table-column>
		</el-table>
		<p v-else class="bd-muted">No logins yet. Add a contact with an email address on this client and a login is created.</p>
		<p class="bd-help bd-mt">Portal address: <a :href="info.portal_url" target="_blank" rel="noopener">{{ info.portal_url }}</a></p>
	</el-card>
</template>

