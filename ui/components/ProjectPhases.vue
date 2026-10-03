<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Check, ChevronDown, ChevronRight, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { api, errorMessage } from '../api';
import Badge from './Badge.vue';
import RecordDrawer from './RecordDrawer.vue';
import RemoteSelect from './RemoteSelect.vue';
import { avatarStyle, dept, fmtDate, fmtMoney, initials } from '../util';
import { setTaskStatus, taskFields } from '../resources';
import { store } from '../store';

const props = defineProps( { project: { type: Object, required: true }, reloadKey: { type: Number, default: 0 } } );
const emit = defineEmits( [ 'changed' ] );

const phases = ref( [] );
const tasks = ref( [] );
const loading = ref( true );
const collapsed = reactive( {} ); // phase id (or 0) -> true when folded away
const quick = reactive( {} ); // phase id (or 0) -> text typed in its "add a task" box
const newName = ref( '' );
const cur = computed( () => props.project.currency || store.meta.settings.default_currency );
const fin = computed( () => store.canFinance );

const STATUS = { todo: 'To do', doing: 'Doing', done: 'Done' };
const PHASE_TONE = { todo: 'neutral', doing: 'info', done: 'success' };

const load = async () => {
	loading.value = true;
	try {
		const [ p, t ] = await Promise.all( [
			api.list( 'project-phases', { project_id: props.project.id, per_page: 100 } ),
			api.list( 'tasks', { project_id: props.project.id, per_page: 200 } ),
		] );
		phases.value = p.items;
		tasks.value = t.items;
		p.items.forEach( ( ph ) => {
			if ( ! ( ph.id in collapsed ) ) collapsed[ ph.id ] = ph.status === 'done';
		} );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		loading.value = false;
	}
};
onMounted( load );
watch( () => props.reloadKey, load );

const changed = async () => {
	await load();
	emit( 'changed' );
};
const wrap = async ( fn ) => {
	try {
		await fn();
		await changed();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};

/* ── Tasks inside each phase ── */
const ORDER = { doing: 0, todo: 1, done: 2 };
const tasksOf = ( phaseId ) => tasks.value.filter( ( t ) => Number( t.phase_id || 0 ) === phaseId ).sort( ( a, b ) => ORDER[ a.status ] - ORDER[ b.status ] || String( a.due_date || '9' ).localeCompare( String( b.due_date || '9' ) ) );
const loose = computed( () => tasksOf( 0 ) );

const addTask = async ( phaseId ) => {
	const title = ( quick[ phaseId ] || '' ).trim();
	if ( ! title ) return;
	await wrap( async () => {
		await api.create( 'tasks', { title, project_id: props.project.id, phase_id: phaseId, ...( props.project.client_id ? { client_id: props.project.client_id } : {} ) } );
		quick[ phaseId ] = '';
		collapsed[ phaseId ] = false;
	} );
};
const moveTask = ( t, phaseId ) => wrap( () => api.update( 'tasks', t.id, { phase_id: phaseId } ) );
const toggleDone = ( t ) => setTaskStatus( t, t.status === 'done' ? 'todo' : 'done', changed );

const drawer = reactive( { open: false, record: {} } );
const fields = computed( () => taskFields( { fixed: { project_id: props.project.id } } ) );
const editTask = ( t ) => {
	drawer.record = { ...t };
	drawer.open = true;
};
const saveTask = ( values ) => api.update( 'tasks', values.id, values );
const taskSaved = () => {
	ElMessage.success( 'Task saved' );
	changed();
};

/* ── Phases ── */
const addPhase = () => {
	const name = newName.value.trim();
	if ( ! name ) return;
	wrap( async () => {
		await api.create( 'project-phases', { project_id: props.project.id, name } );
		newName.value = '';
	} );
};
const setStatus = async ( ph, status ) => {
	if ( status === 'done' && fin.value && ph.allocations && ph.allocations.length ) {
		const sum = ph.allocations.reduce( ( t, a ) => t + a.amount, 0 );
		try {
			await ElMessageBox.confirm( `Marking “${ ph.name }” done adds ${ fmtMoney( sum, cur.value ) } to what ${ ph.allocations.length } ${ ph.allocations.length === 1 ? 'person is' : 'people are' } owed.`, 'Mark phase done', { type: 'info', confirmButtonText: 'Mark done' } );
		} catch ( e ) {
			return;
		}
	}
	await wrap( () => api.update( 'project-phases', ph.id, { status } ) );
};
const removePhase = async ( ph ) => {
	try {
		await ElMessageBox.confirm( `Delete the phase “${ ph.name }”? Its tasks stay (without a phase) and unpaid earnings from it are voided.`, 'Delete phase', { type: 'warning', confirmButtonText: 'Delete' } );
	} catch ( e ) {
		return;
	}
	edit.open = false;
	wrap( () => api.remove( 'project-phases', ph.id ) );
};
const pct = ( ph ) => ( Number( ph.team_budget ) > 0 ? Math.min( 100, Math.round( ( ph.allocated / ph.team_budget ) * 100 ) ) : 0 );
const people = ( ph ) => ( ph.allocations || [] ).map( ( a ) => a.member_name ).filter( Boolean );

/* ── Edit phase pop-up: details, budget, and who is paid what ── */
const edit = reactive( { open: false, id: 0, form: {}, saving: false } );
const current = computed( () => phases.value.find( ( p ) => p.id === edit.id ) || null );
const openEdit = ( ph ) => {
	edit.id = ph.id;
	edit.form = { name: ph.name, department_id: ph.department_id || 0, start_date: ph.start_date || '', due_date: ph.due_date || '', price: Number( ph.price ), team_budget: Number( ph.team_budget ) };
	edit.open = true;
};
const saveDetails = async () => {
	edit.saving = true;
	try {
		await api.update( 'project-phases', edit.id, { name: edit.form.name, department_id: edit.form.department_id || 0, start_date: edit.form.start_date || null, due_date: edit.form.due_date || null, ...( fin.value ? { price: Number( edit.form.price ) || 0, team_budget: Number( edit.form.team_budget ) || 0 } : {} ) } );
		ElMessage.success( 'Phase saved' );
		await changed();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		edit.saving = false;
	}
};
const share = reactive( { team_member_id: 0, mode: 'percent', value: null } );
const addShare = () => {
	if ( ! share.team_member_id || share.value === null || share.value === '' ) return;
	wrap( async () => {
		await api.create( 'pay-allocations', { scope: 'phase', scope_id: edit.id, team_member_id: share.team_member_id, mode: share.mode, value: share.value } );
		share.team_member_id = 0;
		share.value = null;
	} );
};
const saveShare = ( a, patch ) => wrap( () => api.update( 'pay-allocations', a.id, patch ) );
const removeShare = ( a ) => wrap( () => api.remove( 'pay-allocations', a.id ) );
</script>

<template>
	<div v-loading="loading" class="bd-phases">
		<div class="bd-widget__head bd-mb">
			<h3 class="bd-subhead">Phases &amp; tasks</h3>
			<span class="bd-quickadd bd-quickadd--sm"><el-input v-model="newName" placeholder="New phase, e.g. Design" aria-label="New phase name" @keyup.enter="addPhase" /><el-button :disabled="!newName.trim()" @click="addPhase"><Plus :size="14" /> Add phase</el-button></span>
		</div>
		<p v-if="!phases.length && !loading" class="bd-muted bd-mb">Split the project into phases (for example Design, Build, Launch). Tasks live inside their phase, and each phase can have its own budget and team pay.</p>

		<section v-for="ph in phases" :key="ph.id" class="bd-ph">
			<header class="bd-ph__head" @click="collapsed[ ph.id ] = !collapsed[ ph.id ]">
				<component :is="collapsed[ ph.id ] ? ChevronRight : ChevronDown" :size="16" class="bd-ph__chev" />
				<strong class="bd-ph__name">{{ ph.name }}</strong>
				<Badge :tone="PHASE_TONE[ ph.status ]" :label="STATUS[ ph.status ]" />
				<span class="bd-muted bd-small">{{ ph.task_done }} of {{ ph.task_total }} done<template v-if="ph.due_date"> · due {{ fmtDate( ph.due_date ) }}</template></span>
				<span class="bd-ph__spacer" />
				<span v-if="fin && Number( ph.team_budget ) > 0" :class="[ 'bd-small', ph.over_budget ? 'bd-late' : 'bd-muted' ]">{{ fmtMoney( ph.allocated, cur ) }} of {{ fmtMoney( ph.team_budget, cur ) }} shared</span>
				<span v-if="people( ph ).length" class="bd-ph__people"><span v-for="n in people( ph ).slice( 0, 4 )" :key="n" class="bd-ava" :style="avatarStyle( n )" :title="n">{{ initials( n ) }}</span></span>
				<el-button v-if="ph.status !== 'done'" size="small" text @click.stop="setStatus( ph, 'done' )"><Check :size="14" /> Mark done</el-button>
				<el-button size="small" text @click.stop="openEdit( ph )"><Pencil :size="14" /> Edit phase</el-button>
			</header>
			<div v-if="!collapsed[ ph.id ]" class="bd-ph__body">
				<div v-for="t in tasksOf( ph.id )" :key="t.id" class="bd-tk">
					<button type="button" :class="[ 'bd-tk__check', t.status === 'done' && 'is-done' ]" :aria-label="t.status === 'done' ? 'Reopen task' : 'Mark task done'" @click="toggleDone( t )"><Check v-if="t.status === 'done'" :size="12" /></button>
					<a href="#" :class="[ 'bd-tk__title', t.status === 'done' && 'bd-done' ]" @click.prevent="editTask( t )">{{ t.title }}</a>
					<Badge v-if="t.status === 'doing'" tone="info" label="Doing" />
					<Badge v-if="t.priority === 'high'" tone="danger" label="High" />
					<span class="bd-ph__spacer" />
					<span v-if="fin && Number( t.pay_amount ) > 0" class="bd-small bd-muted bd-num">{{ fmtMoney( t.pay_amount, cur ) }}</span>
					<span v-if="t.due_date" :class="[ 'bd-small', t.is_overdue ? 'bd-late' : 'bd-muted' ]">{{ fmtDate( t.due_date ) }}</span>
					<span v-if="t.assignee_name" class="bd-tk__who"><span class="bd-ava" :style="avatarStyle( t.assignee_name )">{{ initials( t.assignee_name ) }}</span><span class="bd-small">{{ t.assignee_name }}</span></span>
				</div>
				<p v-if="!tasksOf( ph.id ).length" class="bd-muted bd-small bd-tk__empty">No tasks in this phase yet.</p>
				<div class="bd-tk bd-tk--add">
					<Plus :size="14" class="bd-muted" />
					<input v-model="quick[ ph.id ]" class="bd-tk__input" :placeholder="`Add a task to ${ ph.name } and press Enter…`" :aria-label="`Add a task to ${ ph.name }`" @keyup.enter="addTask( ph.id )">
				</div>
			</div>
		</section>

		<section v-if="loose.length" class="bd-ph">
			<header class="bd-ph__head" @click="collapsed[ 0 ] = !collapsed[ 0 ]">
				<component :is="collapsed[ 0 ] ? ChevronRight : ChevronDown" :size="16" class="bd-ph__chev" />
				<strong class="bd-ph__name">No phase</strong>
				<span class="bd-muted bd-small">{{ loose.length }} task{{ loose.length === 1 ? '' : 's' }} not in a phase yet</span>
			</header>
			<div v-if="!collapsed[ 0 ]" class="bd-ph__body">
				<div v-for="t in loose" :key="t.id" class="bd-tk">
					<button type="button" :class="[ 'bd-tk__check', t.status === 'done' && 'is-done' ]" :aria-label="t.status === 'done' ? 'Reopen task' : 'Mark task done'" @click="toggleDone( t )"><Check v-if="t.status === 'done'" :size="12" /></button>
					<a href="#" :class="[ 'bd-tk__title', t.status === 'done' && 'bd-done' ]" @click.prevent="editTask( t )">{{ t.title }}</a>
					<span class="bd-ph__spacer" />
					<el-select v-if="phases.length" :model-value="null" size="small" placeholder="Move to phase…" class="bd-tk__move" aria-label="Move to phase" @change="moveTask( t, $event )">
						<el-option v-for="ph in phases" :key="ph.id" :label="ph.name" :value="ph.id" />
					</el-select>
					<span v-if="t.due_date" :class="[ 'bd-small', t.is_overdue ? 'bd-late' : 'bd-muted' ]">{{ fmtDate( t.due_date ) }}</span>
					<span v-if="t.assignee_name" class="bd-tk__who"><span class="bd-ava" :style="avatarStyle( t.assignee_name )">{{ initials( t.assignee_name ) }}</span><span class="bd-small">{{ t.assignee_name }}</span></span>
				</div>
			</div>
		</section>
		<div v-if="!phases.length && !loading" class="bd-tk bd-tk--add bd-ph">
			<Plus :size="14" class="bd-muted" />
			<input v-model="quick[ 0 ]" class="bd-tk__input" placeholder="Or add a task without a phase and press Enter…" aria-label="Add a task" @keyup.enter="addTask( 0 )">
		</div>

		<RecordDrawer v-model="drawer.open" title="Edit task" :fields="fields" :record="drawer.record" :save="saveTask" @saved="taskSaved" />

		<el-dialog v-model="edit.open" :title="current ? 'Edit phase — ' + current.name : 'Edit phase'" width="620px" append-to-body destroy-on-close>
			<template v-if="current">
				<el-form label-position="top" @submit.prevent>
					<el-row :gutter="16">
						<el-col :span="24"><el-form-item label="Phase name"><el-input v-model="edit.form.name" /></el-form-item></el-col>
						<el-col :span="24"><el-form-item :label="dept().one"><RemoteSelect :model-value="edit.form.department_id" resource="brands" :label="( b ) => b.name" :params="{ status: 'active' }" :placeholder="'Same as the project'" @update:model-value="edit.form.department_id = $event" /><div class="bd-help">Which {{ dept().one.toLowerCase() }} this phase belongs to. Leave empty to follow the project. Owed team pay on this phase follows it.</div></el-form-item></el-col>
						<el-col :span="12" :xs="24"><el-form-item label="Starts"><el-date-picker v-model="edit.form.start_date" type="date" value-format="YYYY-MM-DD" style="width: 100%" /></el-form-item></el-col>
						<el-col :span="12" :xs="24"><el-form-item label="Due"><el-date-picker v-model="edit.form.due_date" type="date" value-format="YYYY-MM-DD" style="width: 100%" /></el-form-item></el-col>
						<template v-if="fin">
							<el-col :span="12" :xs="24"><el-form-item :label="`Phase price (${ cur })`"><el-input-number v-model="edit.form.price" :min="0" :precision="2" :controls="false" style="width: 100%" /></el-form-item></el-col>
							<el-col :span="12" :xs="24"><el-form-item :label="`Team budget (${ cur })`"><el-input-number v-model="edit.form.team_budget" :min="0" :precision="2" :controls="false" style="width: 100%" /></el-form-item></el-col>
						</template>
					</el-row>
				</el-form>
				<div class="bd-right bd-mb"><el-button type="primary" :loading="edit.saving" :disabled="!edit.form.name" @click="saveDetails">Save details</el-button></div>

				<template v-if="fin">
					<h4 class="bd-subhead">Team pay</h4>
					<div class="bd-prog">
						<div class="bd-prog__bar"><span :class="{ 'is-over': current.over_budget }" :style="{ width: pct( current ) + '%' }" /></div>
						<div :class="current.over_budget ? 'bd-late bd-small' : 'bd-muted bd-small'">Shared out {{ fmtMoney( current.allocated, cur ) }} of {{ fmtMoney( current.team_budget, cur ) }}<template v-if="current.over_budget"> — more than the budget</template></div>
					</div>
					<div class="bd-split">
						<div v-for="a in current.allocations" :key="a.id" class="bd-split__row">
							<span class="bd-strong">{{ a.member_name }}</span>
							<el-radio-group :model-value="a.mode" size="small" @update:model-value="saveShare( a, { mode: $event } )"><el-radio-button value="percent">%</el-radio-button><el-radio-button value="fixed">{{ cur }}</el-radio-button></el-radio-group>
							<el-input-number :model-value="a.value" :min="0" :precision="2" :controls="false" size="small" @change="saveShare( a, { value: $event } )" />
							<span class="bd-money bd-strong">{{ fmtMoney( a.amount, cur ) }}</span>
							<el-button text circle aria-label="Remove share" @click="removeShare( a )"><Trash2 :size="14" /></el-button>
						</div>
						<p v-if="!current.allocations.length" class="bd-muted bd-small">Nobody is paid from this phase yet.</p>
						<div class="bd-split__row bd-split__row--new">
							<RemoteSelect :model-value="share.team_member_id" resource="team" :label="( t ) => t.name" :params="{ status: 'active' }" placeholder="Add a person…" @update:model-value="share.team_member_id = $event" />
							<el-radio-group v-model="share.mode" size="small"><el-radio-button value="percent">%</el-radio-button><el-radio-button value="fixed">{{ cur }}</el-radio-button></el-radio-group>
							<el-input-number v-model="share.value" :min="0" :precision="2" :controls="false" size="small" placeholder="Share" />
							<span />
							<el-button size="small" type="primary" :disabled="!share.team_member_id || share.value === null" @click="addShare">Add</el-button>
						</div>
						<p class="bd-help">A percentage is of the team budget. Money is owed to each person when the phase is marked done.</p>
					</div>
				</template>
			</template>
			<template #footer>
				<el-button v-if="current" text type="danger" class="bd-left" @click="removePhase( current )">Delete phase</el-button>
				<el-button @click="edit.open = false">Close</el-button>
			</template>
		</el-dialog>
	</div>
</template>
