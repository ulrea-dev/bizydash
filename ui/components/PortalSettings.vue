<script setup>
import { reactive, ref } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage, refreshMeta } from '../api';
import { store } from '../store';

const s = store.meta.settings;
const form = reactive( {
	portal_enabled: s.portal_enabled !== false,
	portal_slug: s.portal_slug || 'portal',
	portal_title: s.portal_title || '',
	portal_welcome: s.portal_welcome || '',
	portal_show_projects: s.portal_show_projects !== false,
	portal_show_tasks: s.portal_show_tasks !== false,
	portal_show_assignee: s.portal_show_assignee !== false,
	portal_show_invoices: s.portal_show_invoices !== false,
	portal_show_billing: s.portal_show_billing !== false,
	portal_show_contracts: s.portal_show_contracts !== false,
} );
const saving = ref( false );
const url = () => `${ store.homeUrl || window.location.origin + '/' }${ form.portal_slug || 'portal' }/`.replace( /([^:])\/\//g, '$1/' );

const openIt = () => window.open( url(), '_blank' );

async function save() {
	saving.value = true;
	try {
		await api.post( 'settings', { ...form } );
		await refreshMeta();
		ElMessage.success( 'Client portal settings saved.' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		saving.value = false;
	}
}
</script>

<template>
	<div>
		<el-card shadow="never" class="bd-mb">
			<template #header><div class="bd-widget__head"><span>Client portal</span></div></template>
			<p class="bd-muted bd-small">A page where each client signs in and sees only their own projects, tasks, invoices, upcoming bills and contracts. Clients get a login automatically (see Roles &amp; access) and you can email each contact an invitation from the client's Contacts tab.</p>
			<el-form label-position="top" class="bd-mt">
				<el-form-item><el-switch v-model="form.portal_enabled" active-text="Client portal is on" /></el-form-item>
				<el-row :gutter="16">
					<el-col :xs="24" :sm="12"><el-form-item label="Address word"><el-input v-model="form.portal_slug" placeholder="portal"><template #prepend>/</template><template #append>/</template></el-input><div class="bd-help">Clients open <strong>{{ url() }}</strong>. Changing it breaks addresses you already shared.</div></el-form-item></el-col>
					<el-col :xs="24" :sm="12"><el-form-item label="Portal name"><el-input v-model="form.portal_title" placeholder="Client portal" /><div class="bd-help">Shown in the browser tab.</div></el-form-item></el-col>
				</el-row>
				<el-form-item label="Welcome message"><el-input v-model="form.portal_welcome" type="textarea" :rows="3" placeholder="Shown at the top of the overview. Leave empty for the standard line." /></el-form-item>
				<el-form-item label="What clients can see">
					<div class="bd-portal-opts">
						<el-switch v-model="form.portal_show_projects" active-text="Projects" />
						<el-switch v-model="form.portal_show_tasks" :disabled="!form.portal_show_projects" active-text="Tasks inside projects" />
						<el-switch v-model="form.portal_show_assignee" :disabled="!form.portal_show_projects || !form.portal_show_tasks" active-text="Who is doing each task" />
						<el-switch v-model="form.portal_show_invoices" active-text="Invoices and payments" />
						<el-switch v-model="form.portal_show_billing" active-text="Bills coming up (not invoiced yet)" />
						<el-switch v-model="form.portal_show_contracts" active-text="Contracts and signed copies" />
					</div>
					<div class="bd-help">A section also needs its module switched on (Work, Finance, Contracts). Draft and cancelled invoices, draft and void contracts, costs, team pay and internal notes are never shown to clients.</div>
				</el-form-item>
			</el-form>
			<el-button type="primary" :loading="saving" @click="save">Save</el-button>
			<el-button @click="openIt">Open the portal</el-button>
		</el-card>
	</div>
</template>

<style scoped>
.bd-portal-opts { display: grid; gap: 10px; }
</style>
