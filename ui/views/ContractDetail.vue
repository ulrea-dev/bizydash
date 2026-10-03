<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { ArrowLeft } from '@element-plus/icons-vue';
import { api, errorMessage } from '../api';
import RemoteSelect from '../components/RemoteSelect.vue';
import RichText from '../components/RichText.vue';
import { CONTRACT_FIELDS } from '../resources';
import { copyText, CONTRACT_STATUS, dept, fmtDate, SIGNER_STATUS } from '../util';
import { navigate, store } from '../store';

const props = defineProps( { id: { type: [ String, Number ], default: 'new' } } );
const isNew = computed( () => String( props.id ) === 'new' );
const c = ref( null );
const audit = ref( null );
const error = ref( '' );
const saving = ref( false );
const form = reactive( { title: '', client_id: 0, template_id: 0, department_id: 0, body: '', expires_on: '', signers: [] } );
const templates = ref( [] );

const draft = computed( () => isNew.value || ( c.value && c.value.status === 'draft' ) );
const ROLES = [ { value: 'client', label: 'Client' }, { value: 'company', label: 'Our company' }, { value: 'witness', label: 'Witness' } ];

const fill = ( r ) => {
	form.title = r.title;
	form.client_id = r.client_id || 0;
	form.template_id = r.template_id || 0;
	form.department_id = r.department_id || 0;
	form.body = r.body || '';
	form.expires_on = r.expires_on || '';
	form.signers = ( r.signers || [] ).map( ( s ) => ( { id: s.id, name: s.name, email: s.email, role: s.role } ) );
};

const load = async () => {
	try {
		c.value = await api.get( 'contracts', props.id );
		fill( c.value );
		syncPreview();
		if ( c.value.status !== 'draft' ) {
			audit.value = await api.getPath( `contracts/${ props.id }/audit` );
		}
		error.value = '';
	} catch ( e ) {
		error.value = errorMessage( e );
	}
};

onMounted( async () => {
	try {
		templates.value = ( await api.list( 'contract-templates', { per_page: 100, is_active: 1 } ) ).items;
	} catch ( e ) {
		templates.value = [];
	}
	if ( isNew.value ) {
		form.signers = [ { name: '', email: '', role: 'client' } ];
		const pre = Number( store.route.query.client || 0 );
		if ( pre ) {
			form.client_id = pre;
			pickClient( await api.get( 'clients', pre ) );
		}
	} else {
		load();
	}
} );

const pickClient = ( cl ) => {
	if ( ! cl ) {
		return;
	}
	if ( ! form.department_id && cl.brands && cl.brands.length ) {
		form.department_id = ( cl.brands.find( ( b ) => b.is_primary ) || cl.brands[ 0 ] ).id;
	}
	const first = form.signers[ 0 ];
	if ( first && ! first.name && ! first.email ) {
		first.name = cl.contact_person || cl.company_name;
		first.email = cl.email || '';
	}
};

const pickTemplate = ( t ) => {
	if ( t && ( ! form.body || ! form.body.replace( /<[^>]*>/g, '' ).trim() ) ) {
		form.body = t.body || '';
	}
	if ( t && ! form.title ) {
		form.title = t.name;
	}
	if ( t && t.department_id && ! form.department_id ) {
		form.department_id = t.department_id;
	}
};

const onImported = ( r ) => {
	if ( ! form.title.trim() && r.title ) {
		form.title = r.title;
	}
};

const addSigner = () => form.signers.push( { name: '', email: '', role: 'company' } );
const removeSigner = ( i ) => form.signers.splice( i, 1 );

const payload = () => ( { title: form.title, client_id: form.client_id, template_id: form.template_id, department_id: form.department_id || 0, body: form.body, expires_on: form.expires_on, signers: form.signers } );

const save = async ( thenSend = false ) => {
	if ( ! form.title.trim() ) {
		ElMessage.warning( 'Give the contract a title.' );
		return;
	}
	saving.value = true;
	try {
		let rec;
		if ( isNew.value ) {
			rec = await api.create( 'contracts', payload() );
		} else {
			rec = await api.update( 'contracts', props.id, payload() );
		}
		if ( thenSend ) {
			await doSend( rec.id );
		} else {
			ElMessage.success( 'Draft saved' );
		}
		if ( isNew.value ) {
			navigate( `/contracts/${ rec.id }` );
		} else {
			await load();
		}
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		saving.value = false;
	}
};

const doSend = async ( id ) => {
	const r = await api.post( `contracts/${ id }/send` );
	if ( r.failed && r.failed.length ) {
		ElMessage.warning( 'Sent, but these emails were not accepted by the mail server: ' + r.failed.join( ', ' ) + '. Copy their links below instead.' );
	} else {
		ElMessage.success( 'Sent for signature' );
	}
};

const send = async () => {
	try {
		await ElMessageBox.confirm( 'Once sent, the wording is locked and fingerprinted so everyone signs exactly the same text. Each signer gets an email with their own link.', 'Send for signature?', { confirmButtonText: 'Send now' } );
	} catch ( e ) {
		return;
	}
	saving.value = true;
	try {
		await api.update( 'contracts', props.id, payload() );
		await doSend( props.id );
		await load();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		saving.value = false;
	}
};

const remind = async () => {
	try {
		const r = await api.post( `contracts/${ props.id }/remind` );
		ElMessage.success( `Reminder sent to ${ r.reminded } ${ r.reminded === 1 ? 'person' : 'people' }` );
		await load();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};

const voidIt = async () => {
	let reason = '';
	try {
		( { value: reason } = await ElMessageBox.prompt( 'Signers will no longer be able to sign. The history stays on record.', 'Void this contract?', { confirmButtonText: 'Void contract', inputPlaceholder: 'Reason (optional)', type: 'warning' } ) );
	} catch ( e ) {
		return;
	}
	try {
		await api.post( `contracts/${ props.id }/void`, { reason } );
		await load();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};

const removeDraft = async () => {
	try {
		await ElMessageBox.confirm( 'Delete this draft?', 'Delete', { type: 'warning', confirmButtonText: 'Delete' } );
		await api.remove( 'contracts', props.id );
		navigate( '/contracts' );
	} catch ( e ) {
		if ( e !== 'cancel' ) {
			ElMessage.error( errorMessage( e ) );
		}
	}
};

const pdfBusy = ref( false );
const makePdf = async () => {
	pdfBusy.value = true;
	try {
		await api.post( `contracts/${ props.id }/pdf` );
		ElMessage.success( 'Signed PDF created and sealed' );
		await load();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		pdfBusy.value = false;
	}
};

const copy = async ( url, what = 'Signing link' ) => ElMessage[ ( await copyText( url ) ) ? 'success' : 'warning' ]( what + ' copied' );

const prev = reactive( { url: '', access: 'private', busy: false } );
const syncPreview = () => {
	prev.url = ( c.value && c.value.preview_url ) || '';
	prev.access = ( c.value && c.value.view_access ) || 'private';
};
const callPreview = async ( body ) => {
	prev.busy = true;
	try {
		const r = await api.post( `contracts/${ props.id }/preview`, body );
		prev.url = r.url;
		prev.access = r.access;
		if ( c.value ) {
			c.value.preview_url = r.url;
			c.value.view_access = r.access;
		}
		return true;
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
		return false;
	} finally {
		prev.busy = false;
	}
};
const makePreview = async () => {
	if ( await callPreview( {} ) ) {
		ElMessage.success( 'Preview link created' );
	}
};
const setAccess = async ( v ) => {
	if ( await callPreview( { access: v } ) ) {
		ElMessage.success( v === 'public' ? 'Anyone with the link can now read it' : 'Only people on the signer list can open it' );
	}
};
const newPreview = async () => {
	try {
		await ElMessageBox.confirm( 'The old preview link will stop working.', 'Replace the preview link?', { type: 'warning', confirmButtonText: 'Replace link' } );
	} catch ( e ) {
		return;
	}
	if ( await callPreview( { regenerate: true } ) ) {
		ElMessage.success( 'New preview link ready' );
	}
};

const dt = ( s ) => ( s ? new Date( s.replace( ' ', 'T' ) + 'Z' ).toLocaleString( undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' } ) : '—' );
const LABELS = { created: 'Draft created', sent: 'Sent for signature', invite_emailed: 'Invitation emailed', reminder_emailed: 'Reminder emailed', viewed: 'Document opened', consent_given: 'Consented to e-sign', otp_sent: 'Verification code emailed', otp_failed: 'Wrong code entered', otp_expired: 'Code expired', otp_verified: 'Code verified', signed: 'Signed', declined: 'Declined', voided: 'Voided', completed: 'All parties signed', pdf_generated: 'Signed PDF sealed', pdf_downloaded: 'Signed PDF downloaded', pdf_failed: 'PDF could not be created', preview_link_created: 'Preview link created', preview_link_replaced: 'Preview link replaced', preview_access_changed: 'Preview access changed', preview_otp_sent: 'Preview code emailed', preview_otp_failed: 'Wrong preview code', preview_otp_verified: 'Preview unlocked', preview_viewed: 'Preview opened', preview_denied: 'Preview refused' };
const who = ( e ) => {
	const s = ( c.value.signers || [] ).find( ( x ) => Number( x.id ) === Number( e.signer_id ) );
	return s ? s.name : e.user_name || 'System';
};
const detail = ( e ) => {
	let d = {};
	try {
		d = JSON.parse( e.detail || '{}' ) || {};
	} catch ( x ) {
		d = {};
	}
	switch ( e.event ) {
		case 'otp_sent': return `Code ${ d.code } emailed to ${ d.to }${ d.mail_accepted ? '' : ' (email failed)' }`;
		case 'otp_failed': return `Entered ${ d.entered } (attempt ${ d.attempt })`;
		case 'otp_verified': return `Entered ${ d.entered } — matches the code emailed to ${ d.email }`;
		case 'signed': return `${ d.name } · ${ d.method } signature`;
		case 'consent_given': return `Typed name “${ d.typed_name }”`;
		case 'invite_emailed':
		case 'reminder_emailed': return `${ d.to } — ${ d.ok ? 'accepted by mail server' : 'not accepted' }`;
		case 'declined':
		case 'voided': return d.reason || '';
		case 'sent': return 'Fingerprint ' + String( d.document_sha256 || '' ).slice( 0, 16 ) + '…';
		case 'pdf_generated': return 'PDF fingerprint ' + String( d.pdf_sha256 || '' ).slice( 0, 16 ) + '…';
		case 'pdf_downloaded': return d.by || '';
		case 'pdf_failed': return d.error || '';
		case 'preview_access_changed': return 'Now ' + ( d.access || '' );
		case 'preview_otp_sent': return `Code ${ d.code } emailed to ${ d.to }`;
		case 'preview_otp_failed': return `Entered ${ d.entered }`;
		case 'preview_otp_verified': return `Entered ${ d.entered } — matches the code emailed to ${ d.email }`;
		case 'preview_viewed': return ( d.mode === 'public' ? 'Public link' : 'Verified' ) + ( d.who ? ' · ' + d.who : '' );
		case 'preview_denied': return `${ d.email } is not on the signer list`;
		case 'completed': return 'Final fingerprint ' + String( d.final_sha256 || '' ).slice( 0, 16 ) + '…';
		default: return '';
	}
};
</script>

<template>
	<div class="bd-contract">
		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />
		<div class="bd-pagehead">
			<div>
				<el-button text :icon="ArrowLeft" @click="navigate( '/contracts' )">All contracts</el-button>
				<span class="bd-eyebrow">Contract{{ c ? ' · ' + c.number : '' }}</span>
				<h1>{{ isNew ? 'New contract' : c ? c.title : '…' }}</h1>
				<p v-if="c" class="bd-muted">
					<span :class="[ 'bd-badge', 'bd-badge--' + CONTRACT_STATUS[ c.status ].tone ]">{{ CONTRACT_STATUS[ c.status ].label }}</span>
					<a v-if="c.client_id" class="bd-link" :href="'#/clients/' + c.client_id"> · {{ c.client_name }}</a>
					<span v-if="c.sent_at"> · Sent {{ fmtDate( c.sent_at ) }}</span>
					<span v-if="c.completed_at"> · Completed {{ fmtDate( c.completed_at ) }}</span>
				</p>
			</div>
			<div class="bd-pagehead__actions">
				<template v-if="draft">
					<el-button v-if="!isNew" text type="danger" @click="removeDraft">Delete</el-button>
					<el-button :loading="saving" @click="save( false )">Save draft</el-button>
					<el-button v-if="isNew" type="primary" :loading="saving" @click="save( true )">Save &amp; send</el-button>
					<el-button v-else type="primary" :loading="saving" @click="send">Send for signature</el-button>
				</template>
				<template v-else-if="c">
					<el-button v-if="[ 'sent', 'partially_signed' ].includes( c.status )" @click="remind">Remind signers</el-button>
					<el-button v-if="[ 'sent', 'partially_signed' ].includes( c.status )" text type="danger" @click="voidIt">Void</el-button>
					<el-button v-if="c.status === 'signed' && c.has_pdf" type="primary" tag="a" :href="c.pdf_url">Download signed PDF</el-button>
					<el-button v-else-if="c.status === 'signed'" type="primary" :loading="pdfBusy" @click="makePdf">Create signed PDF</el-button>
					<el-button :type="c.status === 'signed' && c.has_pdf ? 'default' : 'primary'" tag="a" :href="c.certificate_url" target="_blank">{{ c.status === 'signed' ? 'Open certificate' : 'View record' }}</el-button>
				</template>
			</div>
		</div>

		<el-row :gutter="16">
			<el-col :xs="24" :md="16">
				<el-card v-if="draft" shadow="never" class="bd-mb">
					<el-form label-position="top">
						<el-row :gutter="14">
							<el-col :span="24"><el-form-item label="Title"><el-input v-model="form.title" placeholder="e.g. Website design agreement — Acme Ltd" /></el-form-item></el-col>
							<el-col :xs="24" :sm="12"><el-form-item label="Client">
								<RemoteSelect v-model="form.client_id" resource="clients" :label="( x ) => x.company_name" :params="{ status: 'active' }" placeholder="Choose a client" @pick="pickClient" />
							</el-form-item></el-col>
							<el-col :xs="24" :sm="12"><el-form-item label="Start from a template">
								<RemoteSelect v-model="form.template_id" resource="contract-templates" :label="( x ) => x.name" :params="{ is_active: 1 }" placeholder="Blank contract" @pick="pickTemplate" />
							</el-form-item></el-col>
							<el-col :xs="24" :sm="12"><el-form-item :label="dept().one">
								<RemoteSelect v-model="form.department_id" resource="brands" :label="( x ) => x.name" :params="{ status: 'active' }" :placeholder="'Follows the template or client'" />
							</el-form-item></el-col>
						</el-row>
					</el-form>
					<RichText v-model="form.body" :fields="CONTRACT_FIELDS" :min-height="420" importable @imported="onImported" />
					<p class="bd-help">Fields like <span v-pre>{{client_name}}</span> fill in when you save. After sending, the text is locked.</p>
				</el-card>
				<el-card v-else-if="c" shadow="never" class="bd-mb">
					<template #header><div class="bd-widget__head"><span>Agreement (locked)</span><span class="bd-muted bd-small">SHA-256 {{ c.body_hash.slice( 0, 16 ) }}…</span></div></template>
					<RichText :model-value="c.body" readonly :min-height="120" />
				</el-card>

				<el-card v-if="audit" shadow="never" class="bd-mb">
					<template #header><div class="bd-widget__head"><span>Evidence trail</span><span :class="audit.chain.ok ? '' : 'bd-late'" class="bd-small">{{ audit.chain.ok ? `✔ Log verified (${ audit.chain.count } entries)` : '✖ Log does not verify' }}</span></div></template>
					<div v-if="audit.integrity && audit.integrity.checks.length" :class="[ 'bd-integrity', audit.integrity.ok ? 'is-ok' : 'is-bad' ]">
						<div class="bd-strong">{{ audit.integrity.ok ? 'Genuine and unaltered' : 'Integrity check failed' }}<span v-if="audit.integrity.cert_id" class="bd-cert"> · Certificate ID {{ audit.integrity.cert_id }}</span></div>
						<ul><li v-for="ck in audit.integrity.checks" :key="ck.label">{{ ck.ok ? '✔' : '✖' }} {{ ck.label }}</li></ul>
						<a v-if="audit.integrity.cert_id" class="bd-link bd-small" :href="audit.verify_url" target="_blank" rel="noopener">Public verification page →</a>
					</div>
					<p class="bd-muted bd-small">Every step, with the exact time (your local time) and the verification codes sent and entered. Only visible to you and the people signing.</p>
					<el-timeline class="bd-mt">
						<el-timeline-item v-for="e in audit.events" :key="e.id" :timestamp="dt( e.created_at )" placement="top">
							<div class="bd-strong">{{ LABELS[ e.event ] || e.event }} <span class="bd-muted">· {{ who( e ) }}</span></div>
							<div v-if="detail( e )" class="bd-muted bd-small">{{ detail( e ) }}</div>
							<div v-if="e.ip" class="bd-muted bd-small">IP {{ e.ip }}</div>
						</el-timeline-item>
					</el-timeline>
				</el-card>
			</el-col>

			<el-col :xs="24" :md="8">
				<el-card shadow="never" class="bd-mb">
					<template #header><div class="bd-widget__head"><span>Who signs</span></div></template>
					<template v-if="draft">
						<div v-for="( s, i ) in form.signers" :key="i" class="bd-signer-edit">
							<el-input v-model="s.name" placeholder="Full name" size="small" />
							<el-input v-model="s.email" placeholder="Email address" size="small" />
							<div class="bd-signer-edit__row">
								<el-select v-model="s.role" size="small" style="flex: 1"><el-option v-for="r in ROLES" :key="r.value" :value="r.value" :label="r.label" /></el-select>
								<el-button text type="danger" size="small" @click="removeSigner( i )">Remove</el-button>
							</div>
						</div>
						<el-button size="small" @click="addSigner">+ Add a signer</el-button>
						<p class="bd-help">Each person gets their own link and confirms with a one-time code sent to their email.</p>
					</template>
					<ul v-else-if="c" class="bd-list">
						<li v-for="s in c.signers" :key="s.id">
							<div>
								<div class="bd-strong">{{ s.name }}</div>
								<div class="bd-muted bd-small">{{ s.email }} · {{ s.role }}</div>
								<div v-if="s.signed_at" class="bd-muted bd-small">Signed {{ fmtDate( s.signed_at ) }}</div>
							</div>
							<div class="bd-signer-act">
								<span :class="[ 'bd-badge', 'bd-badge--' + SIGNER_STATUS[ s.status ].tone ]">{{ SIGNER_STATUS[ s.status ].label }}</span>
								<el-button v-if="s.status !== 'signed' && s.status !== 'declined' && c.status !== 'void'" text size="small" @click="copy( s.link )">Copy link</el-button>
							</div>
						</li>
					</ul>
				</el-card>
				<el-card v-if="!isNew" shadow="never" class="bd-mb">
					<template #header><div class="bd-widget__head"><span>Preview link</span></div></template>
					<p class="bd-muted bd-small">Let someone read this contract before it is signed — even while it is still a draft.</p>
					<el-radio-group :model-value="prev.access" size="small" class="bd-mt" :disabled="prev.busy" @update:model-value="setAccess">
						<el-radio-button value="private">Private</el-radio-button>
						<el-radio-button value="public">Public</el-radio-button>
					</el-radio-group>
					<p class="bd-help">{{ prev.access === 'public' ? 'Anyone with the link can read it (reading only — no signing, no evidence details). Best for non-sensitive agreements.' : 'Only the people listed under “Who signs” can open it, by confirming a code emailed to them. People who open their personal signing email link skip this.' }}</p>
					<div v-if="prev.url" class="bd-ie__link">
						<el-input :model-value="prev.url" readonly size="small" />
						<el-button size="small" @click="copy( prev.url, 'Preview link' )">Copy</el-button>
					</div>
					<el-button v-if="!prev.url" size="small" type="primary" :loading="prev.busy" class="bd-mt" @click="makePreview">Create preview link</el-button>
					<template v-else>
						<el-button text size="small" tag="a" :href="prev.url" target="_blank" class="bd-mt">Open</el-button>
						<el-button text size="small" class="bd-mt" @click="newPreview">New link</el-button>
					</template>
				</el-card>
				<el-card v-if="draft" shadow="never" class="bd-mb">
					<template #header><div class="bd-widget__head"><span>Options</span></div></template>
					<el-form label-position="top"><el-form-item label="Signing link expires (optional)"><el-date-picker v-model="form.expires_on" type="date" value-format="YYYY-MM-DD" style="width: 100%" /></el-form-item></el-form>
				</el-card>
				<el-card v-if="audit && audit.otps.length" shadow="never" class="bd-mb">
					<template #header><div class="bd-widget__head"><span>Email codes</span></div></template>
					<p class="bd-muted bd-small">The code we emailed and the code typed in.</p>
					<ul class="bd-list">
						<li v-for="o in audit.otps" :key="o.id">
							<div>
								<div class="bd-strong bd-money">{{ o.code }} → {{ o.entered_code || '—' }}</div>
								<div class="bd-muted bd-small">{{ o.email }} · {{ dt( o.sent_at ) }}</div>
							</div>
							<span :class="[ 'bd-badge', o.status === 'verified' ? 'bd-badge--success' : 'bd-badge--neutral' ]">{{ o.status }}</span>
						</li>
					</ul>
				</el-card>
			</el-col>
		</el-row>
	</div>
</template>

<style>
.bd-signer-edit { display: flex; flex-direction: column; gap: 6px; padding-bottom: 12px; margin-bottom: 12px; box-shadow: inset 0 -1px 0 rgba(14, 18, 27, 0.07); }
.bd-signer-edit__row { display: flex; gap: 8px; align-items: center; }
.bd-integrity { border-radius: 10px; padding: 12px 16px; margin-bottom: 12px; font-size: 13px; }
.bd-integrity.is-ok { background: #effaf4; color: #0b4627; }
.bd-integrity.is-bad { background: #fff1f0; color: #b42318; }
.bd-integrity ul { margin: 6px 0; padding-left: 0; list-style: none; }
.bd-cert { font-family: ui-monospace, Menlo, monospace; letter-spacing: 0.04em; }
.bd-signer-act { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; }
</style>
