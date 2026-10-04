<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage, refreshMeta } from '../api';
import { fmtDateTime } from '../util';
import { useJobLoop } from '../useJobLoop';
import Badge from './Badge.vue';

const props = defineProps( { modelValue: Boolean, backup: { type: Object, default: null } } );
const emit = defineEmits( [ 'update:modelValue', 'restored' ] );

const PHASES = { safety: 'Making a safety copy of your current data…', extract: 'Reading the backup…', files: 'Restoring files…', stage: 'Copying records into place…', validate: 'Checking every record…', swap: 'Switching over…', post: 'Finishing up…' };
const STATUS = { available: [ 'Available', 'success' ], empty: [ 'No records', 'warning' ], missing: [ 'Not in this backup', 'neutral' ] };

const step = ref( 'password' ); // password | analyzing | choose | confirm | run | done | failed
const srcId = ref( '' );
const password = ref( '' );
const report = ref( null );
const selected = ref( [] );
const logs = ref( true );
const applyModules = ref( false );
const typed = ref( '' );
const err = ref( '' );
const result = ref( null );
const warnings = ref( [] );
const isUndo = ref( false );
const cancelled = ref( false );
const job = useJobLoop();
const jobId = ref( '' );

const encrypted = computed( () => !! ( props.backup && props.backup.encrypted ) && ! isUndo.value );
const mods = computed( () => ( report.value ? report.value.modules : [] ) );
const byId = computed( () => Object.fromEntries( mods.value.map( ( m ) => [ m.id, m ] ) ) );
const hasLogs = computed( () => mods.value.some( ( m ) => m.tables.some( ( t ) => t.optional && t.backup !== null ) ) );
const blocked = computed( () => report.value && report.value.compat.status === 'blocked' );
const dependents = computed( () => {
	if ( ! selected.value.includes( 'core' ) ) return [];
	return [ 'work', 'finance', 'contracts' ].filter( ( id ) => byId.value[ id ] && ! selected.value.includes( id ) && byId.value[ id ].live_rows > 0 );
} );
const fixable = computed( () => dependents.value.filter( ( id ) => byId.value[ id ].status !== 'missing' ) );
const unfixable = computed( () => dependents.value.filter( ( id ) => byId.value[ id ].status === 'missing' ) );
const canContinue = computed( () => ! blocked.value && selected.value.length > 0 && dependents.value.length === 0 );
const canRun = computed( () => typed.value.trim().toUpperCase() === 'RESTORE' );
const phaseText = computed( () => PHASES[ job.phase.value ] || 'Working…' );
const canCancel = computed( () => [ 'safety', 'extract', 'files', 'stage', 'validate' ].includes( job.phase.value ) );

function reset() {
	srcId.value = props.backup ? props.backup.id : '';
	password.value = '';
	report.value = null;
	selected.value = [];
	typed.value = '';
	err.value = '';
	result.value = null;
	warnings.value = [];
	isUndo.value = false;
	cancelled.value = false;
	jobId.value = '';
	step.value = 'password';
	if ( props.backup && ! props.backup.encrypted ) analyze();
}

watch( () => props.modelValue, ( v ) => { if ( v ) reset(); }, { immediate: true } );

async function analyze() {
	step.value = 'analyzing';
	err.value = '';
	try {
		report.value = await api.post( 'backups/analyze', { id: srcId.value, password: password.value } );
		if ( ! selected.value.length ) {
			selected.value = report.value.modules.filter( ( m ) => m.status === 'available' ).map( ( m ) => m.id );
		}
		step.value = 'choose';
	} catch ( e ) {
		err.value = errorMessage( e );
		step.value = props.backup && props.backup.encrypted && ! isUndo.value ? 'password' : 'failed';
	}
}

function selectDependents() {
	fixable.value.forEach( ( id ) => selected.value.push( id ) );
}

async function run() {
	step.value = 'run';
	err.value = '';
	cancelled.value = false;
	try {
		const first = await api.post( 'backups/restore', { id: srcId.value, password: password.value, modules: selected.value, opts: { logs: logs.value, apply_modules: applyModules.value } } );
		jobId.value = first.id;
		const done = await job.run( 'backups/restore-step', first, { password: password.value } );
		result.value = done.result;
		warnings.value = done.warnings || [];
		step.value = 'done';
		emit( 'restored' );
		refreshMeta().catch( () => {} );
	} catch ( e ) {
		err.value = cancelled.value ? 'Cancelled. Nothing was changed.' : errorMessage( e );
		step.value = 'failed';
	}
}

async function cancel() {
	cancelled.value = true;
	try {
		await api.post( 'backups/restore-cancel', { job: jobId.value } );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
}

function undo() {
	if ( ! result.value || ! result.value.safety_id ) return;
	srcId.value = result.value.safety_id;
	selected.value = result.value.modules.slice();
	isUndo.value = true;
	password.value = '';
	typed.value = '';
	step.value = 'confirm';
	analyze().then( () => { step.value = 'confirm'; } );
}

const closable = computed( () => step.value !== 'run' );
function close() {
	if ( closable.value ) emit( 'update:modelValue', false );
}
const summaryLine = ( id ) => {
	const m = byId.value[ id ];
	return m ? `${ m.label }: ${ m.live_rows } records now → ${ m.backup_rows } from the backup` : id;
};
</script>

<template>
	<el-dialog :model-value="modelValue" :title="isUndo ? 'Undo the restore' : 'Restore from a backup'" width="720px" append-to-body :close-on-click-modal="false" :close-on-press-escape="closable" :show-close="closable" class="bd-restore" @update:model-value="close">
		<div v-if="backup" class="bd-muted bd-small bd-mb">
			<template v-if="isUndo">Going back to the safety copy made just before the restore.</template>
			<template v-else>Backup from {{ fmtDateTime( backup.created_at ) }}<template v-if="backup.site_url"> · {{ backup.site_url }}</template></template>
		</div>

		<!-- Password -->
		<div v-if="step === 'password'">
			<p>This backup is password protected. Enter the password that was chosen when it was made.</p>
			<el-input v-model="password" type="password" show-password placeholder="Password" autocomplete="off" @keyup.enter="analyze" />
			<el-alert v-if="err" :title="err" type="error" show-icon :closable="false" class="bd-mt" />
		</div>

		<div v-else-if="step === 'analyzing'" class="bd-muted" v-loading="true" style="min-height: 120px">Reading the backup…</div>

		<!-- Choose -->
		<div v-else-if="step === 'choose' && report">
			<el-alert :title="report.compat.message" :type="report.compat.status === 'blocked' ? 'error' : report.compat.status === 'migrate' ? 'info' : 'success'" show-icon :closable="false" class="bd-mb" />
			<p class="bd-strong bd-mb-sm">What do you want to restore?</p>
			<p class="bd-muted bd-small bd-mb-sm">Each part you choose is replaced entirely by what is in the backup. Parts you leave out are not touched.</p>
			<div v-for="m in mods" :key="m.id" class="bd-rmod" :class="{ 'is-off': m.status === 'missing' }">
				<el-checkbox :model-value="selected.includes( m.id )" :disabled="m.status === 'missing' || blocked" :aria-label="m.label" @update:model-value="( v ) => ( v ? selected.push( m.id ) : ( selected = selected.filter( ( x ) => x !== m.id ) ) )" />
				<div class="bd-rmod__body">
					<div class="bd-strong">{{ m.label }} <Badge :tone="STATUS[ m.status ][ 1 ]">{{ STATUS[ m.status ][ 0 ] }}</Badge><Badge v-if="!m.enabled" tone="neutral">Switched off here</Badge></div>
					<div class="bd-muted bd-small">{{ m.desc }}</div>
					<div v-if="m.status !== 'missing'" class="bd-small">In the backup: <strong>{{ m.backup_rows }}</strong> records · on this site now: <strong>{{ m.live_rows }}</strong></div>
					<div v-if="m.note" class="bd-small bd-warn">{{ m.note }}</div>
				</div>
			</div>
			<el-alert v-if="dependents.length" type="warning" show-icon :closable="false" class="bd-mt">
				<template #title>Clients and departments are linked to your {{ dependents.map( ( d ) => byId[ d ].label ).join( ', ' ) }} records.</template>
				<template v-if="unfixable.length"><div class="bd-small bd-mb-sm">This backup has no {{ unfixable.map( ( d ) => byId[ d ].label ).join( ', ' ) }} data to restore alongside them, so the clients cannot be restored from it without breaking those links. Restore the other parts instead, or use a backup that includes everything.</div></template>
				<el-button v-if="fixable.length" size="small" @click="selectDependents">Restore {{ fixable.map( ( d ) => byId[ d ].label ).join( ' and ' ) }} together</el-button>
			</el-alert>
			<div v-if="hasLogs" class="bd-mt"><el-checkbox v-model="logs">Also restore the activity history and email log</el-checkbox></div>
			<div v-if="report.settings" class="bd-mt"><el-checkbox v-model="applyModules">Also switch modules on or off the way they were in the backup</el-checkbox><div class="bd-help">Normally your current module switches are kept.</div></div>

			<div class="bd-rnotes bd-mt">
				<p class="bd-strong bd-mb-sm">About this backup</p>
				<ul>
					<li v-for="( n, i ) in report.notes" :key="i">{{ n }}</li>
					<li v-if="report.unsupported.length">It also contains data this version does not know how to restore, which is skipped: {{ report.unsupported.join( ', ' ) }}.</li>
					<li v-if="report.files.missing.length">{{ report.files.missing.length }} file{{ report.files.missing.length === 1 ? '' : 's' }} listed in the backup could not be found inside it.</li>
					<li v-if="report.manifest.files_included">{{ report.files.count }} file{{ report.files.count === 1 ? '' : 's' }} (signed PDFs, logos, receipts) will be put back if they are missing.</li>
				</ul>
			</div>
		</div>

		<!-- Confirm -->
		<div v-else-if="step === 'confirm' && report">
			<el-alert type="warning" show-icon :closable="false" class="bd-mb" title="This replaces the parts below on this site.">
				<ul class="bd-rlist"><li v-for="id in selected" :key="id">{{ summaryLine( id ) }}</li></ul>
			</el-alert>
			<p class="bd-small">Before anything changes, BizyDash makes and verifies a safety copy of your current data. If anything goes wrong while restoring, your data is left exactly as it was. You can also undo a finished restore from that safety copy.</p>
			<p class="bd-small bd-mt">Contracts keep their signed records and audit trail exactly as backed up. Visitors opening contract or invoice links will see a short “back shortly” notice while this runs.</p>
			<p class="bd-mt bd-strong">Type RESTORE to continue</p>
			<el-input v-model="typed" placeholder="RESTORE" autocomplete="off" @keyup.enter="canRun && run()" />
		</div>

		<!-- Running -->
		<div v-else-if="step === 'run'">
			<el-progress :percentage="job.progress.value" :stroke-width="10" />
			<p class="bd-mt">{{ phaseText }}</p>
			<p class="bd-muted bd-small">Keep this page open. This can take a minute or two.</p>
		</div>

		<!-- Done -->
		<div v-else-if="step === 'done' && result">
			<el-alert type="success" show-icon :closable="false" :title="isUndo ? 'Restore undone.' : 'Restore complete.'" class="bd-mb" />
			<ul class="bd-rlist">
				<li v-for="( n, id ) in result.restored" :key="id"><strong>{{ byId[ id ] ? byId[ id ].label : id }}</strong>: {{ n }} records</li>
				<li v-if="result.contracts">Contracts: <strong>{{ result.contracts.ok }} of {{ result.contracts.total }}</strong> pass the integrity check</li>
				<li v-if="result.identical && result.identical.length" class="bd-muted">{{ result.identical.length }} tables came back byte-for-byte identical to the backup.</li>
			</ul>
			<el-alert v-for="( w, i ) in warnings" :key="i" :title="w" type="warning" show-icon :closable="false" class="bd-mt" />
			<p v-if="result.safety_id && !isUndo" class="bd-muted bd-small bd-mt">A safety copy of what you had before is kept in Saved backups.</p>
		</div>

		<!-- Failed -->
		<div v-else-if="step === 'failed'">
			<el-alert :title="err || 'Something went wrong.'" type="error" show-icon :closable="false" />
		</div>

		<template #footer>
			<template v-if="step === 'password'"><el-button @click="close">Cancel</el-button><el-button type="primary" :disabled="!password" @click="analyze">Continue</el-button></template>
			<template v-else-if="step === 'choose'"><el-button @click="close">Cancel</el-button><el-button type="primary" :disabled="!canContinue" @click="step = 'confirm'">Continue</el-button></template>
			<template v-else-if="step === 'confirm'"><el-button @click="isUndo ? close() : ( step = 'choose' )">{{ isUndo ? 'Cancel' : 'Back' }}</el-button><el-button type="danger" :disabled="!canRun" @click="run">{{ isUndo ? 'Undo the restore' : 'Restore now' }}</el-button></template>
			<template v-else-if="step === 'run'"><el-button :disabled="!canCancel" @click="cancel">Cancel</el-button></template>
			<template v-else-if="step === 'done'"><el-button v-if="result && result.safety_id && !isUndo" @click="undo">Undo this restore</el-button><el-button type="primary" @click="close">Done</el-button></template>
			<template v-else><el-button type="primary" @click="close">Close</el-button></template>
		</template>
	</el-dialog>
</template>

<style scoped>
.bd-rmod { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border: 1px solid var(--bd-line); border-radius: var(--bd-radius-sm); margin-bottom: 8px; }
.bd-rmod.is-off { opacity: 0.6; }
.bd-rmod__body { min-width: 0; flex: 1; display: grid; gap: 2px; }
.bd-warn { color: var(--bd-badge-warning-text); }
.bd-rnotes ul, .bd-rlist { margin: 4px 0 0; padding-left: 18px; display: grid; gap: 4px; }
.bd-rnotes { font-size: 13px; color: var(--bd-muted); }
.bd-mb-sm { margin-bottom: 6px; }
</style>
