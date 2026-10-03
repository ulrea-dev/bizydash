<script setup>
import { ref, computed } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage } from '../api';
import ResourceTable from '../components/ResourceTable.vue';
import PaymentsDialog from '../components/PaymentsDialog.vue';
import SendInvoiceDialog from '../components/SendInvoiceDialog.vue';
import { invoicesConfig, paymentsConfig } from '../resources';
import { copyText } from '../util';

const props = defineProps( { fixed: { type: Object, default: () => ( {} ) }, embedded: Boolean, currency: String, reloadKey: { type: Number, default: 0 }, useRouteFilters: Boolean } );
const emit = defineEmits( [ 'changed' ] );

const view = ref( 'invoices' );
const paying = ref( 0 );
const dialog = ref( false );
const key = ref( 0 );
const emailing = ref( 0 );
const emailOpen = ref( false );

const changed = () => {
	key.value++;
	emit( 'changed' );
};

const copyLink = async ( inv ) => {
	try {
		const { url } = await api.post( `invoices/${ inv.id }/share` );
		const ok = await copyText( url );
		ElMessage[ ok ? 'success' : 'warning' ]( ok ? 'Invoice link copied' : url );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
const send = async ( inv, ctx ) => {
	try {
		const res = await api.post( `invoices/${ inv.id }/mark-sent` );
		await copyText( res.url );
		ElMessage.success( 'Marked as sent — link copied' );
		ctx.reload();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};

const config = computed( () =>
	invoicesConfig( { hideClient: !!props.fixed.client_id, fixed: props.fixed, onPayments: ( r ) => { paying.value = r.id; dialog.value = true; }, onCopyLink: copyLink, onSend: send, onEmail: ( r ) => { emailing.value = r.id; emailOpen.value = true; } } )
);
</script>

<template>
	<div>
		<div v-if="!fixed.client_id" class="bd-toolbar">
			<el-radio-group v-model="view" class="bd-seg-sm">
				<el-radio-button value="invoices">Invoices</el-radio-button>
				<el-radio-button value="payments">Payments</el-radio-button>
			</el-radio-group>
		</div>
		<ResourceTable v-if="view === 'invoices'" :config="config" :fixed="fixed" :embedded="embedded" :reload-key="reloadKey + key" :use-route-filters="useRouteFilters" @changed="emit( 'changed' )" />
		<ResourceTable v-else :config="paymentsConfig()" embedded />
		<SendInvoiceDialog v-model="emailOpen" :invoice-id="emailing" @sent="changed" />
		<PaymentsDialog v-model="dialog" :invoice-id="paying" @changed="changed" />
	</div>
</template>
