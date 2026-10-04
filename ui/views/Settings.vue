<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Plus, Delete, ArrowUp, ArrowDown } from '@element-plus/icons-vue';
import { api, errorMessage, refreshMeta } from '../api';
import { store } from '../store';
import PageHeader from '../components/PageHeader.vue';
import ResourceTable from '../components/ResourceTable.vue';
import AttachmentField from '../components/AttachmentField.vue';
import BackupRestore from '../components/BackupRestore.vue';
import RolesAccess from '../components/RolesAccess.vue';
import PortalSettings from '../components/PortalSettings.vue';
import { brandsConfig, lookupsConfig } from '../resources';
import { navigate } from '../store';
import { dept } from '../util';

const LOOKUP_TYPES = [
	{ id: 'client_type', label: 'Client types' },
	{ id: 'client_status', label: 'Client statuses' },
	{ id: 'brand_type', get label() { return dept().one + ' types'; } },
	{ id: 'billing_period', label: 'Billing periods' },
	{ id: 'billing_treatment', label: 'How it’s billed' },
	{ id: 'expense_category', label: 'Expense categories' },
];

const props = defineProps( { initialTab: { type: String, default: 'general' } } );
const form = reactive( JSON.parse( JSON.stringify( store.meta.settings ) ) );
if ( ! form.rates || Array.isArray( form.rates ) ) form.rates = {};
if ( ! form.base_currency ) form.base_currency = form.default_currency;
( form.currencies || [] ).forEach( ( c ) => { if ( c.active === undefined ) c.active = true; } );
const usage = ref( {} );
const saving = ref( false );
const tab = ref( props.initialTab );
const removingDemo = ref( false );
async function removeDemo() {
	try {
		await ElMessageBox.confirm( 'Remove all the sample clients, invoices, projects and everything else that came with them? Anything you added yourself stays.', 'Remove sample data', { type: 'warning', confirmButtonText: 'Remove sample data' } );
	} catch ( e ) { return; }
	removingDemo.value = true;
	try {
		await api.del( 'demo' );
		window.location.reload();
	} catch ( e ) {
		removingDemo.value = false;
		ElMessage.error( errorMessage( e ) );
	}
}
const lookupType = ref( LOOKUP_TYPES[ 0 ].id );
const lookupCfg = computed( () => lookupsConfig( lookupType.value ) );
const codeOptions = computed( () => ( form.currencies || [] ).filter( ( c ) => c.code && c.code.length === 3 ) );

/* ───────────── Email ───────────── */
const PLACEHOLDERS = [ 'contact_name', 'client_name', 'invoice_number', 'invoice_title', 'amount_due', 'total', 'due_date', 'issue_date', 'business_name' ].map( ( k ) => '{' + '{' + k + '}' + '}' ).join( ' ' );
const mail = reactive( { method: 'wp', from_name: '', from_email: '', reply_to: '', smtp_host: '', smtp_port: 587, smtp_secure: 'tls', smtp_auth: true, smtp_user: '', smtp_pass: '', smtp_pass_set: false, invoice_subject: '', invoice_message: '', bcc_self: false, resolved_from: {} } );
const mailSaving = ref( false );
const testTo = ref( store.userEmail || '' );
const testing = ref( false );
const testResult = ref( { type: '', text: '' } );
const loadMail = async () => {
	try {
		Object.assign( mail, await api.getPath( 'mail-settings' ), { smtp_pass: '' } );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
onMounted( loadMail );
const saveMail = async ( quiet = false ) => {
	mailSaving.value = true;
	try {
		Object.assign( mail, await api.post( 'mail-settings', { ...mail } ), { smtp_pass: '' } );
		if ( ! quiet ) {
			ElMessage.success( 'Email settings saved' );
		}
		return true;
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
		return false;
	} finally {
		mailSaving.value = false;
	}
};
const clearPassword = async () => {
	mail.smtp_pass = '__clear__';
	await saveMail();
};
const sendTest = async () => {
	testing.value = true;
	testResult.value = { type: '', text: '' };
	try {
		if ( ! ( await saveMail( true ) ) ) {
			return;
		}
		const r = await api.post( 'mail-settings/test', { to: testTo.value } );
		testResult.value = { type: 'success', text: `Test email sent to ${ r.to } via ${ r.via }. Check your inbox (and spam folder).${ mail.method === 'wp' ? ' The site mailer only hands the message to the server — if it never arrives, switch to BizyDash SMTP.' : '' }` };
	} catch ( e ) {
		testResult.value = { type: 'error', text: errorMessage( e ) };
	} finally {
		testing.value = false;
	}
};

/* ───────────── Payment methods ───────────── */
const METHOD_TYPES = { bank: 'Bank account', link: 'Payment link', other: 'Other' };
const newMethod = ( type ) => ( { id: '', type, title: '', currency: '', enabled: true, bank_name: '', account_name: '', account_number: '', extra_label: '', extra_value: '', url: '', button_label: '', note: '' } );
const addMethod = ( type ) => {
	form.payment_methods = form.payment_methods || [];
	form.payment_methods.push( newMethod( type ) );
};
const removeMethod = ( i ) => form.payment_methods.splice( i, 1 );
const moveMethod = ( i, d ) => {
	const list = form.payment_methods;
	const j = i + d;
	if ( j < 0 || j >= list.length ) return;
	[ list[ i ], list[ j ] ] = [ list[ j ], list[ i ] ];
};
const methodTitle = ( m ) => m.title || ( m.type === 'bank' ? m.bank_name : '' ) || 'Untitled';

const loadUsage = async () => {
	try {
		usage.value = await api.getPath( 'currencies/usage' );
	} catch ( e ) {
		usage.value = {};
	}
};
onMounted( loadUsage );
const inUse = ( c ) => ( usage.value[ c.code ] || 0 );
const addCurrency = () => form.currencies.push( { code: '', symbol: '', name: '', active: true } );
const removeCurrency = ( i ) => form.currencies.splice( i, 1 );
const upper = ( c ) => ( c.code = c.code.toUpperCase().slice( 0, 3 ) );

const mods = ref( store.modules.map( ( m ) => ( { ...m } ) ) );
const modBusy = ref( false );
const toggleModule = async ( m, on ) => {
	m.enabled = on;
	modBusy.value = true;
	try {
		const payload = {};
		mods.value.filter( ( x ) => ! x.core ).forEach( ( x ) => ( payload[ x.id ] = x.enabled ) );
		payload._analytics = analyticsPayload();
		await api.post( 'modules', payload );
		ElMessage.success( on ? m.label + ' is on — reloading…' : m.label + ' is off — reloading…' );
		setTimeout( () => window.location.reload(), 700 );
	} catch ( e ) {
		m.enabled = ! on;
		ElMessage.error( errorMessage( e ) );
		modBusy.value = false;
	}
};
const coreA = reactive( { clients: store.coreAnalytics.clients !== false, team: store.coreAnalytics.team !== false } );
const analyticsPayload = () => {
	const a = { clients: coreA.clients, team: coreA.team };
	mods.value.filter( ( x ) => ! x.core ).forEach( ( x ) => ( a[ x.id ] = x.analytics !== false ) );
	return a;
};
const toggleAnalytics = async ( m, on ) => {
	m.analytics = on;
	modBusy.value = true;
	try {
		const payload = { _analytics: analyticsPayload() };
		mods.value.filter( ( x ) => ! x.core ).forEach( ( x ) => ( payload[ x.id ] = x.enabled ) );
		await api.post( 'modules', payload );
		ElMessage.success( m.label + ' analytics ' + ( on ? 'is on' : 'is off' ) + ' — reloading…' );
		setTimeout( () => window.location.reload(), 700 );
	} catch ( e ) {
		m.analytics = ! on;
		ElMessage.error( errorMessage( e ) );
		modBusy.value = false;
	}
};
const toggleCoreAnalytics = async ( id, on ) => {
	coreA[ id ] = on;
	modBusy.value = true;
	try {
		const payload = { _analytics: analyticsPayload() };
		mods.value.filter( ( x ) => ! x.core ).forEach( ( x ) => ( payload[ x.id ] = x.enabled ) );
		await api.post( 'modules', payload );
		ElMessage.success( 'Saved — reloading…' );
		setTimeout( () => window.location.reload(), 700 );
	} catch ( e ) {
		coreA[ id ] = ! on;
		ElMessage.error( errorMessage( e ) );
		modBusy.value = false;
	}
};
const appLink = computed( () => store.appUrl || '' );

const save = async () => {
	saving.value = true;
	try {
		await api.post( 'settings', JSON.parse( JSON.stringify( form ) ) );
		await refreshMeta();
		ElMessage.success( 'Settings saved' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		saving.value = false;
	}
};
</script>

<template>
	<div>
		<PageHeader title="Settings" description="Everything here is configuration — nothing about your agency is hard-coded." />
		<el-tabs v-model="tab" class="bd-tabs">
			<el-tab-pane label="General" name="general">
				<el-card shadow="never">
					<el-form label-position="top" style="max-width: 760px" @submit.prevent>
						<el-row :gutter="16">
							<el-col :span="24"><el-form-item label="Agency / company name"><el-input v-model="form.company_name" /></el-form-item></el-col>
							<el-col :xs="24" :sm="12"><el-form-item label="What do you call a department?"><el-input v-model="form.department_label" placeholder="Department" /><div class="bd-help">Use “Brand”, “Division”, “Team”… whatever fits your business.</div></el-form-item></el-col>
							<el-col :xs="24" :sm="12"><el-form-item label="Plural"><el-input v-model="form.department_label_plural" placeholder="Departments" /></el-form-item></el-col>
							<el-col :xs="24" :sm="12">
								<el-form-item label="Default currency">
									<el-select v-model="form.default_currency" style="width: 100%"><el-option v-for="c in codeOptions" :key="c.code" :value="c.code" :label="c.code + ' (' + c.symbol + ')'" /></el-select>
									<div class="bd-help">Used for new clients. Each client can bill in its own currency.</div>
								</el-form-item>
							</el-col>
							<el-col :xs="24" :sm="12"><el-form-item label="“Due soon” window (days)"><el-input-number v-model="form.due_soon_days" :min="0" controls-position="right" style="width: 100%" /></el-form-item></el-col>
							<el-col :xs="24" :sm="12"><el-form-item label="Invoice number prefix"><el-input v-model="form.invoice_prefix" /></el-form-item></el-col>
							<el-col :xs="24" :sm="12">
								<el-form-item label="Invoice link word">
									<el-input v-model="form.invoice_slug_base" placeholder="invoice"><template #prepend>/</template><template #append>/abc123</template></el-input>
									<div class="bd-help">Client links look like yoursite.com/{{ form.invoice_slug_base || 'invoice' }}/abc123. Changing it breaks links you already shared.</div>
								</el-form-item>
							</el-col>
							<el-col :xs="24" :sm="12">
								<el-form-item label="Contract preview links">
									<el-select v-model="form.contract_view_default" style="width: 100%"><el-option value="private" label="Private — signers confirm with an emailed code" /><el-option value="public" label="Public — anyone with the link can read" /></el-select>
									<div class="bd-help">The starting choice for new contracts. You can change it per contract.</div>
								</el-form-item>
							</el-col>
							<el-col :xs="24" :sm="12"><el-form-item label="Next invoice number"><el-input-number v-model="form.invoice_next" :min="1" controls-position="right" style="width: 100%" /></el-form-item></el-col>
							<el-col :xs="24" :sm="12"><el-form-item label="Default invoice terms (days)"><el-input-number v-model="form.default_due_days" :min="0" controls-position="right" style="width: 100%" /></el-form-item></el-col>
							<el-col :span="24"><el-form-item label="Data"><el-switch v-model="form.delete_on_uninstall" active-text="Delete all BizyDash data when the plugin is deleted" /></el-form-item></el-col>
							<el-col v-if="store.demoLoaded && store.canManage" :span="24"><el-form-item label="Sample data"><el-button :loading="removingDemo" @click="removeDemo">Remove sample data</el-button><div class="bd-help">The example clients, invoices and projects added for you to explore. Things you added yourself are kept.</div></el-form-item></el-col>
							<el-col v-if="form.delete_on_uninstall" :span="24"><el-form-item label="Backups"><el-switch v-model="form.delete_backups_on_uninstall" active-text="Also delete saved backups" /><div class="bd-help">Off by default, so a backup survives even if you remove the plugin and its data.</div></el-form-item></el-col>
						</el-row>
					</el-form>
					<el-button type="primary" :loading="saving" @click="save">Save settings</el-button>
				</el-card>
			</el-tab-pane>

			<el-tab-pane label="Modules & app" name="modules" lazy>
				<el-card shadow="never" class="bd-mb">
					<template #header><div class="bd-widget__head"><span>Modules</span></div></template>
					<p class="bd-muted bd-small">Switch on only what you use. Turning a module off hides it and stops its automatic jobs and public links — your data is never deleted, and switching it back on restores everything.</p>
					<ul class="bd-list bd-mt">
						<li v-for="m in mods" :key="m.id">
							<div style="min-width: 0"><div class="bd-strong">{{ m.label }} <span v-if="m.core" class="bd-badge bd-badge--neutral bd-badge--plain">Always on</span></div><div class="bd-muted bd-small">{{ m.desc }}</div><div v-if="m.core" class="bd-modsub"><span class="bd-muted bd-small">Analytics pages</span><template v-for="a in m.core_analytics" :key="a.id"><span class="bd-small">{{ a.label }}</span><el-switch size="small" :model-value="coreA[ a.id ]" :disabled="modBusy || !store.canManage" :aria-label="a.label + ' analytics'" @update:model-value="toggleCoreAnalytics( a.id, $event )" /></template></div><div v-if="m.has_analytics && m.enabled" class="bd-modsub">
								<span class="bd-muted bd-small">Analytics page</span>
								<el-switch size="small" :model-value="m.analytics !== false" :disabled="modBusy || !store.canManage" :aria-label="m.label + ' analytics'" @update:model-value="toggleAnalytics( m, $event )" />
							</div></div>
							<el-switch :model-value="m.enabled" :disabled="m.core || modBusy || !store.canManage" @update:model-value="toggleModule( m, $event )" />
						</li>
					</ul>
					<p v-if="!store.canManage" class="bd-help">Only a WordPress administrator can change modules.</p>
				</el-card>
				<el-card shadow="never">
					<template #header><div class="bd-widget__head"><span>The app</span></div></template>
					<el-form label-position="top" @submit.prevent>
						<el-form-item label="Full-screen app mode">
							<el-switch v-model="form.app_fullscreen" active-text="Hide the WordPress menu, admin bar and notices on BizyDash screens" />
						</el-form-item>
						<el-form-item label="Team members">
							<el-switch v-model="form.app_keep_staff" active-text="Keep people who are not WordPress administrators out of wp-admin" />
							<div class="bd-help">They sign in and land straight in BizyDash. Give someone access by adding them as a WordPress user with the “BizyDash team member” role.</div>
						</el-form-item>
						<el-form-item label="App on your website">
							<el-switch v-model="form.app_frontend" active-text="Open BizyDash on a page of your site, with no WordPress admin" />
						</el-form-item>
						<el-form-item v-if="form.app_frontend" label="Home page">
							<el-switch v-model="form.app_home" active-text="Use the app as my website's home page" />
							<div class="bd-help">Visitors who are not signed in see the sign-in page at {{ store.homeUrl }}, and your team lands in the app. Your website's own home page is hidden while this is on — a signed-in customer or member who is not on the team still sees it, and you can always open it at <code>{{ store.homeUrl }}?bd_site=1</code>. The address {{ store.homeUrl }}{{ form.app_slug || 'app' }}/ keeps working too.</div>
						</el-form-item>
						<el-form-item v-if="form.app_frontend" label="Sign-in page">
							<el-switch v-model="form.app_login" active-text="Show a branded sign-in and password reset when someone opens the app signed out" />
							<div class="bd-help">It uses your business name and logo. Signing in and password reset run through WordPress itself, so your security plugins and reset rules still apply. Turn it off to use the standard WordPress login.</div>
						</el-form-item>
						<el-form-item v-if="form.app_frontend" label="Page address">
							<el-input v-model="form.app_slug" placeholder="app"><template #prepend>{{ store.homeUrl }}</template><template #append>/</template></el-input>
							<div class="bd-help">Signed-in team members open <a class="bd-link" :href="appLink" target="_blank" rel="noopener">{{ appLink }}</a></div>
						</el-form-item>
					</el-form>
					<el-button type="primary" :loading="saving" @click="save">Save</el-button>
				</el-card>
			</el-tab-pane>

			<el-tab-pane label="Business & invoices" name="business">
				<el-card shadow="never">
					<p class="bd-muted bd-mb">This is what appears on every invoice and on the shareable invoice page.</p>
					<el-form label-position="top" style="max-width: 820px" @submit.prevent>
						<el-row :gutter="16">
							<el-col :xs="24" :sm="12"><el-form-item label="Business name"><el-input v-model="form.business_name" placeholder="Digitalzooks" /></el-form-item></el-col>
							<el-col :xs="24" :sm="12"><el-form-item label="Logo"><AttachmentField v-model="form.business_logo_id" v-model:url="form.business_logo_url" title="Business logo" /><div v-if="form.business_logo_url" class="bd-logo-prev"><img :src="form.business_logo_url" alt="Logo preview" /></div></el-form-item></el-col>
							<el-col :xs="24" :sm="12"><el-form-item label="Logo for emails (PNG/JPG)"><AttachmentField v-model="form.business_logo_email_id" v-model:url="form.business_logo_email_url" title="Email logo" /><div class="bd-help">Email apps don't show SVG logos, so add a PNG or JPG version here. Not needed if your main logo is already PNG/JPG.</div></el-form-item></el-col>
							<el-col :span="24"><el-form-item label="Address"><el-input v-model="form.business_address" type="textarea" :rows="3" /></el-form-item></el-col>
							<el-col :xs="24" :sm="8"><el-form-item label="Email"><el-input v-model="form.business_email" /></el-form-item></el-col>
							<el-col :xs="24" :sm="8"><el-form-item label="Phone"><el-input v-model="form.business_phone" /></el-form-item></el-col>
							<el-col :xs="24" :sm="8"><el-form-item label="Website"><el-input v-model="form.business_website" placeholder="https://" /></el-form-item></el-col>
							<el-col :xs="24" :sm="8"><el-form-item label="Tax label"><el-input v-model="form.tax_label" placeholder="VAT" /></el-form-item></el-col>
							<el-col :xs="24" :sm="8"><el-form-item label="Tax / VAT number"><el-input v-model="form.tax_id" /></el-form-item></el-col>
							<el-col :xs="24" :sm="8"><el-form-item label="Default tax rate (%)"><el-input-number v-model="form.default_tax_rate" :min="0" :max="100" :precision="2" controls-position="right" style="width: 100%" /></el-form-item></el-col>
							<el-col :span="24"><el-form-item label="Extra payment note"><el-input v-model="form.payment_instructions" type="textarea" :rows="2" placeholder="e.g. Please send proof of payment to billing@…" /><div class="bd-help">Optional line shown under your payment methods on unpaid invoices. Add bank accounts and payment links in the <a href="#" @click.prevent="tab = 'payments'">Payment methods</a> tab.</div></el-form-item></el-col>
							<el-col :span="24"><el-form-item label="Default terms"><el-input v-model="form.invoice_terms" type="textarea" :rows="3" /><div class="bd-help">Copied onto new invoices; editable per invoice.</div></el-form-item></el-col>
							<el-col :span="24"><el-form-item label="Footer line"><el-input v-model="form.invoice_footer" /></el-form-item></el-col>
						</el-row>
					</el-form>
					<el-button type="primary" :loading="saving" @click="save">Save settings</el-button>
				</el-card>
			</el-tab-pane>

			<el-tab-pane v-if="store.enabled( 'finance' )" label="Payment methods" name="payments">
				<el-card shadow="never">
					<p class="bd-muted bd-mb">How clients can pay you. These appear on the invoice page and in invoice emails — link methods become a “Pay now” button, bank accounts get copy buttons. Limit a method to one currency and it only shows on invoices in that currency.</p>
					<div v-if="!form.payment_methods?.length" class="bd-pm-empty">No payment methods yet. Add your first bank account or payment link.</div>
					<div class="bd-pm-list">
						<div v-for="( m, i ) in form.payment_methods" :key="i" class="bd-pm" :class="{ 'is-off': !m.enabled }">
							<div class="bd-pm__head">
								<div class="bd-pm__title"><el-tag size="small" effect="plain" round>{{ METHOD_TYPES[ m.type ] }}</el-tag><strong>{{ methodTitle( m ) }}</strong><el-tag v-if="m.currency" size="small" round type="info">{{ m.currency }}</el-tag></div>
								<div class="bd-pm__tools">
									<el-switch v-model="m.enabled" size="small" aria-label="Show this method" />
									<el-button text circle :icon="ArrowUp" :disabled="i === 0" aria-label="Move up" @click="moveMethod( i, -1 )" />
									<el-button text circle :icon="ArrowDown" :disabled="i === form.payment_methods.length - 1" aria-label="Move down" @click="moveMethod( i, 1 )" />
									<el-button text type="danger" circle :icon="Delete" aria-label="Remove" @click="removeMethod( i )" />
								</div>
							</div>
							<el-form label-position="top" @submit.prevent><el-row :gutter="14">
								<el-col :xs="24" :sm="16"><el-form-item label="Title"><el-input v-model="m.title" :placeholder="m.type === 'bank' ? 'e.g. GTBank — Naira account' : m.type === 'link' ? 'e.g. Pay by card (Stripe)' : 'e.g. Cash / cheque'" /></el-form-item></el-col>
								<el-col :xs="24" :sm="8">
									<el-form-item label="Show on invoices in">
										<el-select v-model="m.currency" clearable placeholder="All currencies" style="width: 100%"><el-option v-for="c in codeOptions" :key="c.code" :value="c.code" :label="c.code" /></el-select>
									</el-form-item>
								</el-col>
								<template v-if="m.type === 'bank'">
									<el-col :xs="24" :sm="8"><el-form-item label="Bank"><el-input v-model="m.bank_name" placeholder="Guaranty Trust Bank" /></el-form-item></el-col>
									<el-col :xs="24" :sm="8"><el-form-item label="Account name"><el-input v-model="m.account_name" /></el-form-item></el-col>
									<el-col :xs="24" :sm="8"><el-form-item label="Account number"><el-input v-model="m.account_number" /></el-form-item></el-col>
									<el-col :xs="24" :sm="8"><el-form-item label="Extra detail label"><el-input v-model="m.extra_label" placeholder="SWIFT / Sort code / IBAN" /></el-form-item></el-col>
									<el-col :xs="24" :sm="16"><el-form-item label="Extra detail"><el-input v-model="m.extra_value" /></el-form-item></el-col>
								</template>
								<template v-else-if="m.type === 'link'">
									<el-col :xs="24" :sm="16"><el-form-item label="Payment URL"><el-input v-model="m.url" placeholder="https://buy.stripe.com/…" /><div class="bd-help">You can use {invoice_number}, {amount} and {currency} in the link.</div></el-form-item></el-col>
									<el-col :xs="24" :sm="8"><el-form-item label="Button text"><el-input v-model="m.button_label" placeholder="Pay now" /></el-form-item></el-col>
								</template>
								<el-col :span="24"><el-form-item :label="m.type === 'other' ? 'Instructions' : 'Note (optional)'"><el-input v-model="m.note" type="textarea" :rows="2" /></el-form-item></el-col>
							</el-row></el-form>
						</div>
					</div>
					<div class="bd-mt">
						<el-button :icon="Plus" @click="addMethod( 'bank' )">Add bank account</el-button>
						<el-button :icon="Plus" @click="addMethod( 'link' )">Add payment link</el-button>
						<el-button :icon="Plus" @click="addMethod( 'other' )">Add other</el-button>
						<el-button type="primary" :loading="saving" @click="save">Save payment methods</el-button>
					</div>
				</el-card>
			</el-tab-pane>

			<el-tab-pane label="Email" name="email">
				<el-card shadow="never">
					<p class="bd-muted bd-mb">How BizyDash sends invoice emails. You can use your own SMTP account here — no extra plugin needed — or keep using the site's mailer.</p>
					<el-form label-position="top" style="max-width: 820px" @submit.prevent>
						<el-form-item label="Mailer">
							<el-radio-group v-model="mail.method" class="bd-seg-sm">
								<el-radio-button value="wp">Site mailer (wp_mail)</el-radio-button>
								<el-radio-button value="smtp">BizyDash SMTP</el-radio-button>
							</el-radio-group>
							<div class="bd-help">{{ mail.method === 'smtp' ? 'Used only for BizyDash emails. Other plugins on the site are unaffected.' : 'Uses whatever the site already uses to send mail (e.g. FluentSMTP, or the server default).' }}</div>
						</el-form-item>
						<el-row :gutter="16">
							<el-col :xs="24" :sm="8"><el-form-item label="From name"><el-input v-model="mail.from_name" :placeholder="mail.resolved_from.name || 'Your business name'" /></el-form-item></el-col>
							<el-col :xs="24" :sm="8"><el-form-item label="From email"><el-input v-model="mail.from_email" :placeholder="mail.resolved_from.email || 'billing@yourdomain.com'" /></el-form-item></el-col>
							<el-col :xs="24" :sm="8"><el-form-item label="Reply-to"><el-input v-model="mail.reply_to" :placeholder="mail.resolved_from.reply_to || 'Same as business email'" /></el-form-item></el-col>
						</el-row>

						<template v-if="mail.method === 'smtp'">
							<h4 class="bd-subhead">SMTP server</h4>
							<el-row :gutter="16">
								<el-col :xs="24" :sm="12"><el-form-item label="Host"><el-input v-model="mail.smtp_host" placeholder="smtp.yourprovider.com" /></el-form-item></el-col>
								<el-col :xs="12" :sm="6"><el-form-item label="Port"><el-input-number v-model="mail.smtp_port" :min="1" :max="65535" :controls="false" style="width: 100%" /></el-form-item></el-col>
								<el-col :xs="12" :sm="6">
									<el-form-item label="Security">
										<el-select v-model="mail.smtp_secure" style="width: 100%"><el-option value="tls" label="TLS (STARTTLS) · 587" /><el-option value="ssl" label="SSL · 465" /><el-option value="none" label="None" /></el-select>
									</el-form-item>
								</el-col>
								<el-col :xs="24" :sm="8"><el-form-item label="Authentication"><el-switch v-model="mail.smtp_auth" active-text="Use username & password" /></el-form-item></el-col>
								<template v-if="mail.smtp_auth">
									<el-col :xs="24" :sm="8"><el-form-item label="Username"><el-input v-model="mail.smtp_user" autocomplete="off" /></el-form-item></el-col>
									<el-col :xs="24" :sm="8">
										<el-form-item label="Password">
											<el-input v-model="mail.smtp_pass" type="password" show-password autocomplete="new-password" :placeholder="mail.smtp_pass_set ? '•••••••• saved — type to replace' : ''" />
											<div class="bd-help">Stored encrypted and never shown again. <a v-if="mail.smtp_pass_set" href="#" @click.prevent="clearPassword">Remove saved password</a></div>
										</el-form-item>
									</el-col>
								</template>
							</el-row>
						</template>

						<h4 class="bd-subhead">Invoice email wording</h4>
						<el-form-item label="Subject"><el-input v-model="mail.invoice_subject" /></el-form-item>
						<el-form-item label="Message">
							<el-input v-model="mail.invoice_message" type="textarea" :rows="7" />
							<div class="bd-help">Placeholders: {{ PLACEHOLDERS }}. You can still edit each email before sending.</div>
						</el-form-item>
						<el-form-item><el-switch v-model="mail.bcc_self" active-text="Send me a copy (Bcc) of every invoice email by default" /></el-form-item>
					</el-form>
					<el-button type="primary" :loading="mailSaving" @click="saveMail()">Save email settings</el-button>

					<h4 class="bd-subhead">Send a test</h4>
					<p class="bd-muted bd-mb">Saves the settings above, then sends a short test message so you know the connection works before you email a client.</p>
					<el-alert v-if="testResult.text" :title="testResult.text" :type="testResult.type" show-icon :closable="false" class="bd-mb" style="max-width: 820px" />
					<div class="bd-send__test">
						<el-input v-model="testTo" placeholder="you@yourcompany.com" style="width: 280px" aria-label="Test recipient" />
						<el-button :loading="testing" :disabled="!testTo" @click="sendTest">Send test email</el-button>
					</div>
					<p class="bd-help bd-mt">Tip: for the best inbox placement, send from an address on your own domain and make sure its SPF and DKIM records allow your SMTP provider.</p>
				</el-card>
			</el-tab-pane>

			<el-tab-pane label="Team" name="team">
				<el-card shadow="never">
					<p class="bd-muted bd-mb">Your team now has its own page: add people, see what they are working on, and (soon) what they are owed. Turning on “Copy on every invoice email” for a person is done on their profile.</p>
					<el-button type="primary" @click="navigate( '/team' )">Open Team</el-button>
				</el-card>
			</el-tab-pane>

			<el-tab-pane :label="dept().many" name="brands" lazy>
				<el-card shadow="never">
					<p class="bd-muted bd-mb">The {{ dept().lowerMany }} of your business — for example Web, Marketing or Support. Tag each client with the ones they belong to, then filter your numbers by {{ dept().lower }}.</p>
					<ResourceTable :config="brandsConfig()" embedded />
				</el-card>
			</el-tab-pane>

			<el-tab-pane label="Currencies" name="currencies">
				<el-card shadow="never">
					<p class="bd-muted">Switch on the currencies your business deals with. Totals are always shown per currency; analytics can also show one combined figure using the rates below (an estimate — one current rate per currency).</p>
					<el-form label-position="top" class="bd-mt" @submit.prevent>
						<el-form-item label="Base currency (for combined totals)" style="max-width: 320px">
							<el-select v-model="form.base_currency" style="width: 100%"><el-option v-for="c in codeOptions" :key="c.code" :value="c.code" :label="c.code + ' (' + c.symbol + ')'" /></el-select>
						</el-form-item>
					</el-form>
					<el-table :data="form.currencies" size="small" style="max-width: 920px">
						<el-table-column label="On" width="70"><template #default="{ row }"><el-switch v-model="row.active" size="small" :aria-label="row.code + ' active'" /></template></el-table-column>
						<el-table-column label="Code" width="100"><template #default="{ row }"><el-input v-model="row.code" maxlength="3" @input="upper( row )" /></template></el-table-column>
						<el-table-column label="Symbol" width="90"><template #default="{ row }"><el-input v-model="row.symbol" /></template></el-table-column>
						<el-table-column label="Name" min-width="150"><template #default="{ row }"><el-input v-model="row.name" /></template></el-table-column>
						<el-table-column :label="'Rate: 1 unit = ? ' + form.base_currency" width="190">
							<template #default="{ row }"><span v-if="row.code === form.base_currency" class="bd-muted bd-small">Base currency</span><el-input-number v-else v-model="form.rates[ row.code ]" :min="0" :precision="4" :controls="false" size="small" placeholder="e.g. 1500" style="width: 100%" /></template>
						</el-table-column>
						<el-table-column label="Used by" width="110"><template #default="{ row }"><span class="bd-muted bd-small">{{ inUse( row ) ? inUse( row ) + ' record' + ( inUse( row ) === 1 ? '' : 's' ) : '—' }}</span></template></el-table-column>
						<el-table-column width="60" align="right"><template #default="{ $index, row }"><el-button text type="danger" :icon="Delete" circle :disabled="form.currencies.length < 2 || inUse( row ) > 0 || row.code === form.base_currency" :title="inUse( row ) ? 'In use — switch it off instead' : 'Remove'" @click="removeCurrency( $index )" /></template></el-table-column>
					</el-table>
					<p class="bd-help">A currency that records use can be switched off (hidden from pickers and analytics switchers) but not removed. Old data keeps its currency and still counts in totals.</p>
					<div class="bd-mt">
						<el-button :icon="Plus" @click="addCurrency">Add currency</el-button>
						<el-button type="primary" :loading="saving" @click="save">Save settings</el-button>
					</div>
				</el-card>
			</el-tab-pane>

			<el-tab-pane label="Lists & options" name="lists" lazy>
				<el-card shadow="never">
					<p class="bd-muted">Rename, reorder, recolour or deactivate options, or add your own. Built-in options can't be deleted.</p>
					<el-radio-group v-model="lookupType" class="bd-mb"><el-radio-button v-for="t in LOOKUP_TYPES" :key="t.id" :value="t.id">{{ t.label }}</el-radio-button></el-radio-group>
					<ResourceTable :key="lookupType" :config="lookupCfg" :fixed="{ type: lookupType }" embedded @changed="refreshMeta" />
				</el-card>
			</el-tab-pane>

			<el-tab-pane v-if="store.canManage" label="Client portal" name="portal" lazy>
				<PortalSettings />
			</el-tab-pane>

			<el-tab-pane v-if="store.canManage" label="Roles & access" name="access" lazy>
				<RolesAccess />
			</el-tab-pane>

			<el-tab-pane v-if="store.canManage" label="Backup & restore" name="backup" lazy>
				<BackupRestore />
			</el-tab-pane>
		</el-tabs>
	</div>
</template>
