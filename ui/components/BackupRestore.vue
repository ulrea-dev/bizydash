<script setup>
import { ref, reactive, computed, onMounted, nextTick } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { api, errorMessage } from '../api';
import { fmtDateTime } from '../util';
import { useJobLoop } from '../useJobLoop';
import Badge from './Badge.vue';
import RestoreDialog from './RestoreDialog.vue';
import { store } from '../store';

const MODULE_LABELS = { core: 'Clients & team', work: 'Work', finance: 'Finance', contracts: 'Contracts' };

const items = ref( [] );
const health = ref( {} );
const loading = ref( true );
const settings = reactive( { auto: 'off', keep: 7, files: true, logs: true, last_run: '', last_ok: '', last_status: '', last_error: '' } );
const opts = reactive( { files: true, logs: true, encrypt: false, password: '', password2: '' } );
const savingAuto = ref( false );
const job = useJobLoop();
const lastResult = ref( null );
const busyId = ref( '' );
const restoreRow = ref( null );
const showRestore = ref( false );
const uploading = ref( false );
const uploadPct = ref( 0 );
const fileInput = ref( null );

function openRestore( row ) {
	restoreRow.value = row;
	showRestore.value = true;
}

/** Send a backup file in 2 MB pieces, so it works however small the host's upload limit is. */
async function uploadFile( file ) {
	if ( ! file ) return;
	uploading.value = true;
	uploadPct.value = 0;
	const SIZE = 2 * 1024 * 1024;
	const total = Math.max( 1, Math.ceil( file.size / SIZE ) );
	const uid = Array.from( crypto.getRandomValues( new Uint8Array( 16 ) ) ).map( ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );
	try {
		let res = null;
		for ( let i = 0; i < total; i++ ) {
			let tries = 0;
			for ( ;; ) {
				const fd = new FormData();
				fd.append( 'upload', uid );
				fd.append( 'index', i );
				fd.append( 'total', total );
				fd.append( 'file', file.slice( i * SIZE, ( i + 1 ) * SIZE ), 'part' );
				try {
					res = await api.upload( 'backups/upload', fd );
					break;
				} catch ( e ) {
					if ( e.status || ++tries > 3 ) throw e;
					await new Promise( ( r ) => setTimeout( r, 1500 * tries ) );
				}
			}
			uploadPct.value = Math.round( ( ( i + 1 ) / total ) * 100 );
		}
		await load();
		if ( res && res.backup ) openRestore( res.backup );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		uploading.value = false;
		if ( fileInput.value ) fileInput.value.value = '';
	}
}

const pwMismatch = computed( () => opts.encrypt && ( opts.password.length < 8 || opts.password !== opts.password2 ) );

async function load() {
	loading.value = true;
	try {
		const r = await api.getPath( 'backups' );
		items.value = r.items || [];
		health.value = r.health || {};
		Object.assign( settings, r.settings || {} );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		loading.value = false;
	}
}

async function backupNow() {
	lastResult.value = null;
	try {
		const first = await api.post( 'backups', { files: opts.files, logs: opts.logs, encrypt: opts.encrypt } );
		const done = await job.run( 'backups/step', first, opts.encrypt ? { password: opts.password } : {} );
		lastResult.value = done.result;
		ElMessage.success( 'Backup saved.' );
		opts.password = opts.password2 = '';
		await load();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
		await load();
	}
}

async function saveAuto() {
	savingAuto.value = true;
	try {
		Object.assign( settings, await api.post( 'backups/settings', { auto: settings.auto, keep: settings.keep, files: settings.files, logs: settings.logs } ) );
		await load();
		ElMessage.success( 'Automatic backup settings saved.' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		savingAuto.value = false;
	}
}

async function download( row, password = '' ) {
	busyId.value = row.id;
	try {
		const r = await api.post( `backups/${ row.id }/download-token`, password ? { password } : {} );
		window.location.href = r.url;
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		busyId.value = '';
	}
}

async function downloadProtected( row ) {
	try {
		const { value } = await ElMessageBox.prompt( 'Choose a password for the downloaded copy (8+ characters). You will need it to restore. It cannot be recovered.', 'Download with a password', { inputType: 'password', inputPattern: /^.{8,}$/, inputErrorMessage: 'Use at least 8 characters', confirmButtonText: 'Download' } );
		await download( row, value );
	} catch ( e ) { /* cancelled */ }
}

async function remove( row ) {
	try {
		await ElMessageBox.confirm( 'Delete this backup file from the site? This cannot be undone.', 'Delete backup', { type: 'warning', confirmButtonText: 'Delete', confirmButtonClass: 'el-button--danger' } );
	} catch ( e ) { return; }
	try {
		await api.remove( 'backups', row.id );
		items.value = items.value.filter( ( i ) => i.id !== row.id );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
}

async function rescan() {
	try {
		const r = await api.post( 'backups/rescan' );
		ElMessage.success( r.added ? `Found ${ r.added } backup file${ r.added === 1 ? '' : 's' }.` : 'No new backup files in the folder.' );
		await load();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
}

const typeBadge = ( t ) => ( { manual: [ 'Manual', 'neutral' ], auto: [ 'Automatic', 'info' ], pre_restore: [ 'Before restore', 'warning' ], imported: [ 'Added', 'neutral' ] }[ t ] || [ t, 'neutral' ] );
const sizeOf = ( b ) => ( b >= 1073741824 ? ( b / 1073741824 ).toFixed( 1 ) + ' GB' : b >= 1048576 ? ( b / 1048576 ).toFixed( 1 ) + ' MB' : Math.max( 1, Math.round( b / 1024 ) ) + ' KB' );
const summary = ( r ) => Object.entries( r.rows || {} ).filter( ( [ , n ] ) => n > 0 ).map( ( [ m, n ] ) => `${ MODULE_LABELS[ m ] || m } ${ n }` ).join(' · ') || 'No records';
const phaseLabel = computed( () => ( { data: 'Saving your records…', options: 'Saving settings…', files: 'Checking files…', zip: 'Packing the backup…' }[ job.phase.value ] || 'Working…' ) );
const warnings = computed( () => {
	const h = health.value, w = [];
	if ( h.zip === false ) w.push( 'This server has no zip support, so backups cannot be created. Ask your host to enable the PHP zip extension.' );
	if ( h.writable === false ) w.push( 'The backup folder is not writable.' );
	if ( h.exposed === true ) w.push( 'The backup folder can be opened from the web. Anyone with a file’s link could download it. Your server may ignore .htaccess (Nginx): ask your host to block this folder, or set BIZYDASH_BACKUP_DIR to a location outside the web root.' );
	if ( h.cron_disabled && settings.auto !== 'off' ) w.push( 'WP-Cron is disabled on this site, so automatic backups only run if your host triggers cron itself.' );
	if ( settings.last_status === 'failed' ) w.push( 'The last automatic backup failed: ' + ( settings.last_error || 'unknown error' ) );
	return w;
} );

onMounted( async () => {
	await load();
	if ( store.route.query && store.route.query.restore ) {
		await nextTick();
		const el = document.querySelector( '.bd-restore-hl' );
		if ( el && el.scrollIntoView ) el.scrollIntoView( { block: 'center' } );
	}
} );
</script>

<template>
	<div class="bd-backup">
		<el-alert v-for="( w, i ) in warnings" :key="i" :title="w" type="warning" show-icon :closable="false" class="bd-mb" />

		<el-card shadow="never" class="bd-mb">
			<template #header><div class="bd-widget__head"><span>Back up now</span></div></template>
			<p class="bd-muted bd-small">Saves a copy of your clients, team, work, finance and contracts on this site. You can download it afterwards, and later restore it here or on another site.</p>
			<div class="bd-backup__opts">
				<el-checkbox v-model="opts.files" :disabled="job.running.value">Include files (signed contract PDFs, logos, receipts)</el-checkbox>
				<el-checkbox v-model="opts.logs" :disabled="job.running.value">Include activity history and email log</el-checkbox>
				<el-checkbox v-model="opts.encrypt" :disabled="job.running.value || health.encryption === false">Protect with a password</el-checkbox>
			</div>
			<div v-if="opts.encrypt" class="bd-backup__pw">
				<el-input v-model="opts.password" type="password" show-password placeholder="Password (8+ characters)" :disabled="job.running.value" autocomplete="new-password" />
				<el-input v-model="opts.password2" type="password" show-password placeholder="Repeat password" :disabled="job.running.value" autocomplete="new-password" />
				<div class="bd-help">There is no way to recover this password. Without it the backup cannot be opened.</div>
			</div>
			<div class="bd-mt">
				<el-button type="primary" :loading="job.running.value" :disabled="pwMismatch || health.zip === false" @click="backupNow">{{ job.running.value ? 'Backing up…' : 'Back up now' }}</el-button>
			</div>
			<div v-if="job.running.value" class="bd-mt">
				<el-progress :percentage="job.progress.value" :stroke-width="8" />
				<div class="bd-muted bd-small bd-mt-sm">{{ phaseLabel }} Keep this page open.</div>
			</div>
			<div v-if="lastResult && !job.running.value" class="bd-mt bd-small">
				<Badge tone="success">Saved</Badge> {{ summary( lastResult ) }} · {{ sizeOf( lastResult.size ) }}
			</div>
		</el-card>

		<el-card shadow="never" class="bd-mb">
			<template #header><div class="bd-widget__head"><span>Automatic backups</span></div></template>
			<el-form label-position="top" class="bd-backup__auto">
				<el-row :gutter="16">
					<el-col :xs="24" :sm="8"><el-form-item label="How often"><el-select v-model="settings.auto"><el-option label="Off" value="off" /><el-option label="Every day" value="daily" /><el-option label="Every week" value="weekly" /></el-select></el-form-item></el-col>
					<el-col :xs="24" :sm="8"><el-form-item label="Keep the latest"><el-input-number v-model="settings.keep" :min="1" :max="60" :disabled="settings.auto === 'off'" /></el-form-item></el-col>
					<el-col :xs="24" :sm="8"><el-form-item label="Include"><div class="bd-backup__opts"><el-checkbox v-model="settings.files" :disabled="settings.auto === 'off'">Files</el-checkbox><el-checkbox v-model="settings.logs" :disabled="settings.auto === 'off'">Activity &amp; email log</el-checkbox></div></el-form-item></el-col>
				</el-row>
			</el-form>
			<div class="bd-help">Older automatic backups are deleted to keep only the number above. Manual backups are never deleted for you. Automatic backups are not password protected (a password stored on the same site would not protect anything); download a copy with a password when you want one.</div>
			<div class="bd-mt bd-row-gap">
				<el-button type="primary" :loading="savingAuto" @click="saveAuto">Save</el-button>
				<span v-if="settings.last_ok" class="bd-muted bd-small">Last automatic backup: {{ fmtDateTime( settings.last_ok ) }}</span>
				<span v-else-if="settings.auto !== 'off'" class="bd-muted bd-small">No automatic backup has run yet.</span>
				<span v-if="health.next_auto" class="bd-muted bd-small">Next: {{ fmtDateTime( new Date( health.next_auto * 1000 ).toISOString() ) }}</span>
			</div>
		</el-card>

		<el-card shadow="never" class="bd-mb" :class="{ 'bd-restore-hl': store.route.query && store.route.query.restore }">
			<template #header><div class="bd-widget__head"><span>Restore from a backup file</span></div></template>
			<p class="bd-muted bd-small">Have a backup from this or another site? Upload it, choose what to restore, and BizyDash puts it back, safely. You can restore everything or only some parts. You can also restore any saved backup below.</p>
			<input ref="fileInput" type="file" accept=".bizydash,.zip" hidden @change="uploadFile( $event.target.files[ 0 ] )" />
			<div class="bd-mt"><el-button :loading="uploading" @click="fileInput.click()">{{ uploading ? 'Uploading… ' + uploadPct + '%' : 'Upload a backup file…' }}</el-button></div>
		</el-card>

		<RestoreDialog v-model="showRestore" :backup="restoreRow" @restored="load" />

		<el-card shadow="never">
			<template #header><div class="bd-widget__head"><span>Saved backups</span><el-button text size="small" @click="rescan">Look for files added by FTP</el-button></div></template>
			<el-table v-loading="loading" :data="items" size="small" empty-text="No backups yet. Use “Back up now” above.">
				<el-table-column label="Created" min-width="170"><template #default="{ row }">{{ fmtDateTime( row.created_at ) }}</template></el-table-column>
				<el-table-column label="Type" width="130"><template #default="{ row }"><Badge :tone="typeBadge( row.type )[ 1 ]">{{ typeBadge( row.type )[ 0 ] }}</Badge></template></el-table-column>
				<el-table-column label="Contains" min-width="220"><template #default="{ row }"><span v-if="row.encrypted" class="bd-muted">Password protected</span><span v-else>{{ summary( row ) }}</span><span v-if="row.files_included" class="bd-muted bd-small"> · {{ row.files }} file{{ row.files === 1 ? '' : 's' }}</span></template></el-table-column>
				<el-table-column label="Size" width="90"><template #default="{ row }">{{ sizeOf( row.size ) }}</template></el-table-column>
				<el-table-column width="400" align="right">
					<template #default="{ row }">
						<el-button size="small" type="primary" plain @click="openRestore( row )">Restore…</el-button>
						<el-button size="small" :loading="busyId === row.id" @click="download( row )">Download</el-button>
						<el-button v-if="!row.encrypted" size="small" @click="downloadProtected( row )">With password…</el-button>
						<el-button size="small" type="danger" text @click="remove( row )">Delete</el-button>
					</template>
				</el-table-column>
			</el-table>
			<p class="bd-help bd-mt">Stored in <code>{{ health.folder }}</code><span v-if="health.free_bytes"> · {{ sizeOf( health.free_bytes ) }} free</span>.</p>
		</el-card>
	</div>
</template>

<style scoped>
.bd-backup__opts { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }
.bd-backup__pw { display: grid; gap: 8px; max-width: 360px; margin-top: 12px; }
.bd-row-gap { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.bd-mt-sm { margin-top: 6px; }
.bd-restore-hl { box-shadow: 0 0 0 2px var(--bd-link); }
</style>
