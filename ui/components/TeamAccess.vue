<script setup>
import { ref, watch, onMounted } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage } from '../api';

const props = defineProps( { memberId: { type: [ String, Number ], required: true }, hasEmail: Boolean } );
const emit = defineEmits( [ 'changed' ] );

const info = ref( null );
const role = ref( '' );
const modules = ref( [] );
const notify = ref( false );
const saving = ref( false );

async function load() {
	try {
		info.value = await api.getPath( `team/${ props.memberId }/access` );
		role.value = info.value.role;
		modules.value = info.value.modules || [];
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
}

async function save() {
	saving.value = true;
	try {
		info.value = await api.post( `team/${ props.memberId }/access`, { role: role.value, modules: modules.value, notify: notify.value } );
		ElMessage.success( role.value ? 'Access saved.' : 'Access removed.' );
		emit( 'changed' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		saving.value = false;
	}
}

onMounted( load );
watch( () => props.memberId, load );
</script>

<template>
	<el-card v-if="info" shadow="never" class="bd-mb">
		<template #header><div class="bd-widget__head"><span>Access</span></div></template>
		<el-alert v-if="info.is_wp_admin" type="info" show-icon :closable="false" title="This person is a WordPress administrator and already has full access." />
		<template v-else>
			<p class="bd-muted bd-small">What this person can open in BizyDash. {{ info.has_login ? 'Login: ' + info.login + '.' : 'They do not have a login yet; choosing a role creates one.' }}</p>
			<el-select v-model="role" placeholder="No access" clearable style="width: 100%; max-width: 360px">
				<el-option v-for="r in info.roles" :key="r.key" :value="r.key" :label="r.label" />
			</el-select>
			<div v-if="role" class="bd-help bd-mt-xs">{{ ( info.roles.find( ( r ) => r.key === role ) || {} ).desc }}</div>
			<div v-if="role === 'bizydash_team_manager'" class="bd-mt">
				<div class="bd-strong bd-small bd-mb-xs">Also lets them manage</div>
				<el-checkbox-group v-model="modules"><el-checkbox v-for="( label, k ) in info.manager_modules" :key="k" :value="k" :label="label" /></el-checkbox-group>
			</div>
			<div v-if="role && !info.has_login" class="bd-mt"><el-checkbox v-model="notify">Email them a link to set their password</el-checkbox></div>
			<div class="bd-mt"><el-button type="primary" :loading="saving" :disabled="!hasEmail && !info.has_login" @click="save">{{ info.has_login ? 'Save access' : 'Create login' }}</el-button></div>
		</template>
	</el-card>
</template>

<style scoped>
.bd-mt-xs { margin-top: 4px; }
.bd-mb-xs { margin-bottom: 4px; }
</style>
