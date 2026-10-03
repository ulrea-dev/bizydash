<script setup>
import { onMounted, ref, watch } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import ImportDocDialog from './ImportDocDialog.vue';

const props = defineProps( { modelValue: { type: String, default: '' }, fields: { type: Array, default: () => [] }, minHeight: { type: Number, default: 320 }, readonly: Boolean, importable: Boolean } );
const emit = defineEmits( [ 'update:modelValue', 'imported' ] );
const importing = ref( false );
const el = ref( null );
const field = ref( '' );

const sync = () => {
	if ( el.value && el.value.innerHTML !== props.modelValue ) {
		el.value.innerHTML = props.modelValue || '';
	}
};
onMounted( sync );
watch( () => props.modelValue, sync );

const emitHtml = () => emit( 'update:modelValue', el.value.innerHTML );
const cmd = ( name, arg = null ) => {
	el.value.focus();
	document.execCommand( name, false, arg );
	emitHtml();
};
const insertField = () => {
	if ( ! field.value ) {
		return;
	}
	cmd( 'insertText', `{{${ field.value }}}` );
	field.value = '';
};
const rule = () => cmd( 'insertHTML', '<hr><p><br></p>' );
const pageBreak = () => cmd( 'insertHTML', '<hr class="bd-pagebreak"><p><br></p>' );
const imported = async ( r ) => {
	const has = props.modelValue && props.modelValue.replace( /<[^>]*>/g, '' ).trim();
	if ( has ) {
		try {
			await ElMessageBox.confirm( 'This replaces the text you have now with the imported document.', 'Replace the current text?', { confirmButtonText: 'Replace', type: 'warning' } );
		} catch ( e ) {
			return;
		}
	}
	emit( 'update:modelValue', r.html );
	emit( 'imported', r );
	( r.warnings || [] ).forEach( ( w ) => ElMessage( { message: w, type: 'warning', duration: 9000, showClose: true } ) );
	ElMessage.success( 'Document imported — check it over, then save.' );
};
const paste = ( e ) => {
	// Paste as plain text so pasted Word/web styles never leak into a legal document.
	e.preventDefault();
	const text = ( e.clipboardData || window.clipboardData ).getData( 'text/plain' );
	document.execCommand( 'insertText', false, text );
};
</script>

<template>
	<div class="bd-rt">
		<div v-if="!readonly" class="bd-rt__bar">
			<button type="button" title="Heading" @mousedown.prevent="cmd( 'formatBlock', 'h2' )">Heading</button>
			<button type="button" title="Sub-heading" @mousedown.prevent="cmd( 'formatBlock', 'h3' )">Sub-heading</button>
			<button type="button" title="Paragraph" @mousedown.prevent="cmd( 'formatBlock', 'p' )">Text</button>
			<span class="bd-rt__sep" />
			<button type="button" title="Bold" @mousedown.prevent="cmd( 'bold' )"><b>B</b></button>
			<button type="button" title="Italic" @mousedown.prevent="cmd( 'italic' )"><i>I</i></button>
			<button type="button" title="Underline" @mousedown.prevent="cmd( 'underline' )"><u>U</u></button>
			<button type="button" title="Align left" @mousedown.prevent="cmd( 'justifyLeft' )">⇤</button>
			<button type="button" title="Centre" @mousedown.prevent="cmd( 'justifyCenter' )">≡</button>
			<button type="button" title="Align right" @mousedown.prevent="cmd( 'justifyRight' )">⇥</button>
			<button type="button" title="Justify" @mousedown.prevent="cmd( 'justifyFull' )">☰</button>
			<button type="button" title="Bullets" @mousedown.prevent="cmd( 'insertUnorderedList' )">• List</button>
			<button type="button" title="Numbered" @mousedown.prevent="cmd( 'insertOrderedList' )">1. List</button>
			<span class="bd-rt__sep" />
			<button type="button" title="Horizontal line" @mousedown.prevent="rule">— Line</button>
			<button type="button" title="Start a new page here" @mousedown.prevent="pageBreak">⏎ Page break</button>
			<span v-if="importable" class="bd-rt__sep" />
			<button v-if="importable" type="button" class="bd-rt__imp" title="Import a Word file or Google Doc" @mousedown.prevent="importing = true">⬆ Import Word / Google Doc</button>
			<span v-if="fields.length" class="bd-rt__sep" />
			<select v-if="fields.length" v-model="field" class="bd-rt__sel" @change="insertField">
				<option value="">Insert a field…</option>
				<option v-for="f in fields" :key="f" :value="f">{{ f.replace( /_/g, ' ' ) }}</option>
			</select>
		</div>
		<ImportDocDialog v-if="importable" v-model="importing" @imported="imported" />
		<div ref="el" class="bd-rt__body" :contenteditable="!readonly" :style="{ minHeight: minHeight + 'px' }" @input="emitHtml" @paste="paste" />
	</div>
</template>

<style>
.bd-rt { border-radius: 10px; box-shadow: inset 0 0 0 1px rgba(14, 18, 27, 0.14); background: #fff; overflow: hidden; }
.bd-rt__bar { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; padding: 8px 10px; background: var(--bd-bg, #f6f8fa); box-shadow: inset 0 -1px 0 rgba(14, 18, 27, 0.07); }
.bd-rt__bar button { border: 0; background: transparent; padding: 5px 10px; border-radius: 7px; font: inherit; font-size: 13px; cursor: pointer; color: var(--bd-ink); }
.bd-rt__bar button:hover { background: rgba(14, 18, 27, 0.07); }
.bd-rt__sep { width: 1px; height: 18px; background: rgba(14, 18, 27, 0.12); margin: 0 6px; }
.bd-rt__sel { border: 0; background: #fff; border-radius: 7px; padding: 5px 8px; font: inherit; font-size: 13px; box-shadow: inset 0 0 0 1px rgba(14, 18, 27, 0.14); }
.bd-rt__body { padding: 18px 22px; outline: none; line-height: 1.7; font-size: 15px; }
.bd-rt__body h2 { font-size: 18px; margin: 1.2em 0 0.4em; font-weight: 650; }
.bd-rt__body h3 { font-size: 15.5px; margin: 1.1em 0 0.3em; font-weight: 650; }
.bd-rt__body p { margin: 0.6em 0; }
.bd-rt__body ul, .bd-rt__body ol { padding-left: 1.4em; }
.bd-rt__imp { font-weight: 600; color: var(--bd-ink) !important; }
.bd-rt__body h1 { font-size: 22px; margin: 1em 0 .4em; font-weight: 700; }
.bd-rt__body .ct-li { display: flex; margin: .45em 0; }
.bd-rt__body .ct-num { flex: none; }
.bd-rt__body .ct-tx { flex: 1; min-width: 0; }
.bd-rt__body table.ct-table { width: 100%; border-collapse: collapse; margin: 1em 0; }
.bd-rt__body table.ct-table td { padding: 7px 10px; vertical-align: top; }
.bd-rt__body table.ct-bordered td { border: 1px solid #cdd3dc; }
.bd-rt__body hr { border: 0; border-top: 1px solid #222530; margin: 1.2em 0; height: 0; }
.bd-rt__body hr.bd-pagebreak { border-top: 1px dashed #aab2bf; margin: 1.8em 0; position: relative; overflow: visible; }
.bd-rt__body hr.bd-pagebreak::after { content: 'page break'; position: absolute; right: 0; top: -9px; font-size: 10px; letter-spacing: .06em; text-transform: uppercase; color: #8b93a1; background: #fff; padding: 0 6px; }
.bd-rt__body img { max-width: 100%; height: auto; }
</style>
