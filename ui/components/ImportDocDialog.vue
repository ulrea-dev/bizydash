<script setup>
import { ref } from 'vue';
import { api, errorMessage } from '../api';

const props = defineProps( { modelValue: Boolean } );
const emit = defineEmits( [ 'update:modelValue', 'imported' ] );
const tab = ref( 'word' );
const url = ref( '' );
const busy = ref( false );
const error = ref( '' );
const over = ref( false );
const input = ref( null );

const close = () => {
	emit( 'update:modelValue', false );
	error.value = '';
};
const done = ( r ) => {
	emit( 'imported', r );
	close();
};
const upload = async ( file ) => {
	if ( ! file ) {
		return;
	}
	if ( ! /\.docx?$/i.test( file.name ) ) {
		error.value = 'Please choose a Word file (.doc or .docx).';
		return;
	}
	busy.value = true;
	error.value = '';
	try {
		const f = new FormData();
		f.append( 'file', file );
		done( await api.upload( 'contracts/import', f ) );
	} catch ( e ) {
		error.value = errorMessage( e );
	} finally {
		busy.value = false;
	}
};
const pick = ( e ) => upload( e.target.files[ 0 ] );
const drop = ( e ) => {
	over.value = false;
	upload( e.dataTransfer.files[ 0 ] );
};
const google = async () => {
	if ( ! url.value.trim() ) {
		error.value = 'Paste a Google Docs link first.';
		return;
	}
	busy.value = true;
	error.value = '';
	try {
		done( await api.post( 'contracts/import', { url: url.value.trim() } ) );
	} catch ( e ) {
		error.value = errorMessage( e );
	} finally {
		busy.value = false;
	}
};
</script>

<template>
	<el-dialog :model-value="modelValue" title="Import a document" width="520px" append-to-body @update:model-value="close">
		<el-tabs v-model="tab">
			<el-tab-pane label="Word file" name="word">
				<div :class="[ 'bd-drop', { 'is-over': over } ]" @dragover.prevent="over = true" @dragleave="over = false" @drop.prevent="drop" @click="input && input.click()">
					<strong>{{ busy ? 'Importing…' : 'Drop a Word file here' }}</strong>
					<span class="bd-muted">or click to choose a .doc or .docx file</span>
					<input ref="input" type="file" accept=".doc,.docx,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" hidden @change="pick">
				</div>
				<p class="bd-help">Works with .docx and older .doc files. Keeps headings, bold/italic, colours, tables, lines, page breaks, images and your numbering (1.1, (a)…).</p>
			</el-tab-pane>
			<el-tab-pane label="Google Docs link" name="google">
				<el-input v-model="url" placeholder="https://docs.google.com/document/d/…" @keyup.enter="google" />
				<p class="bd-help">In Google Docs click <b>Share → General access → Anyone with the link</b> (Viewer), then paste the link here.</p>
				<el-button type="primary" :loading="busy" class="bd-mt" @click="google">Import from Google Docs</el-button>
			</el-tab-pane>
		</el-tabs>
		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mt" />
	</el-dialog>
</template>

<style>
.bd-drop { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 34px 16px; border-radius: 12px; background: var(--bd-bg, #f6f8fa); box-shadow: inset 0 0 0 1.5px rgba(14, 18, 27, 0.14); cursor: pointer; text-align: center; }
.bd-drop.is-over, .bd-drop:hover { background: rgba(34, 37, 48, 0.06); box-shadow: inset 0 0 0 1.5px #222530; }
</style>
