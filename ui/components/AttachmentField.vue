<script setup>
import { ref, computed } from 'vue';
import { Paperclip } from '@element-plus/icons-vue';

const props = defineProps( { modelValue: { type: Number, default: 0 }, url: { type: String, default: '' }, title: { type: String, default: 'Choose file' } } );
const emit = defineEmits( [ 'update:modelValue', 'update:url' ] );
const name = ref( '' );
const shownUrl = computed( () => ( name.value ? '' : props.url ) );

const pick = () => {
	if ( ! window.wp || ! window.wp.media ) {
		return;
	}
	const frame = window.wp.media( { title: props.title, multiple: false } );
	frame.on( 'select', () => {
		const a = frame.state().get( 'selection' ).first().toJSON();
		name.value = a.filename || a.title;
		emit( 'update:modelValue', a.id );
		emit( 'update:url', a.url );
	} );
	frame.open();
};
const clear = () => {
	name.value = '';
	emit( 'update:modelValue', 0 );
	emit( 'update:url', '' );
};
</script>

<template>
	<div class="bd-attach">
		<template v-if="modelValue">
			<el-icon><Paperclip /></el-icon>
			<a v-if="shownUrl" :href="shownUrl" target="_blank" rel="noreferrer">View file</a>
			<span v-else>{{ name || 'Attachment #' + modelValue }}</span>
			<el-button size="small" @click="pick">Replace</el-button>
			<el-button size="small" text type="danger" @click="clear">Remove</el-button>
		</template>
		<template v-else>
			<el-button size="small" :icon="Paperclip" @click="pick">Choose file</el-button>
		</template>
	</div>
</template>
