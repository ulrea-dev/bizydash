<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue';
import { Chart } from '../chartSetup';
import { fmtMoney } from '../util';

/**
 * One chart for every analytics panel.
 * type: line | bar | doughnut. series: [{ label, data, color? }]. labels: category names.
 * money: currency code to format values as money (omit for plain numbers).
 */
const props = defineProps( {
	type: { type: String, default: 'line' },
	labels: { type: Array, default: () => [] },
	series: { type: Array, default: () => [] },
	currency: { type: String, default: '' },
	stacked: Boolean,
	height: { type: Number, default: 260 },
	dark: Boolean,
} );
const canvas = ref( null );
let chart = null;

const token = ( name, fallback ) => getComputedStyle( document.getElementById( 'bizydash-root' ) || document.body ).getPropertyValue( name ).trim() || fallback;
const PALETTE = [ '--bd-accent', '--bd-success', '--bd-warning', '--bd-info', '--bd-danger' ];
const FALLBACK = [ '#635bff', '#15a46e', '#d9822b', '#2f8fd8', '#d92d20', '#8a63d2', '#2aa7a0' ];
const resolve = ( c ) => {
	const m = c && /^var\((--[\w-]+)\)$/.exec( c );
	return m ? token( m[ 1 ], '' ) || undefined : c;
};
const color = ( i ) => token( PALETTE[ i ], FALLBACK[ i % FALLBACK.length ] ) || FALLBACK[ i % FALLBACK.length ];
const fmt = ( v ) => ( props.currency ? fmtMoney( v, props.currency ) : Number( v ).toLocaleString() );

const draw = () => {
	if ( ! canvas.value ) return;
	const muted = token( '--bd-muted', '#667085' );
	const ring = token( '--bd-ring', 'rgba(10,37,64,.08)' );
	const isDonut = props.type === 'doughnut';
	const datasets = props.series.map( ( s, i ) => {
		const c = resolve( s.color ) || color( i );
		if ( isDonut ) {
			return { label: s.label, data: s.data, backgroundColor: props.labels.map( ( _, k ) => color( k ) ), borderWidth: 0 };
		}
		return props.type === 'bar'
			? { label: s.label, data: s.data, backgroundColor: c, borderRadius: 3, maxBarThickness: 28 }
			: { label: s.label, data: s.data, borderColor: c, backgroundColor: c, tension: 0.25, borderWidth: 2, pointRadius: 0, pointHoverRadius: 4 };
	} );
	const options = {
		responsive: true,
		maintainAspectRatio: false,
		interaction: { mode: isDonut ? 'nearest' : 'index', intersect: isDonut },
		plugins: {
			legend: { display: isDonut || props.series.length > 1, position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, color: muted, font: { size: 12 } } },
			tooltip: { backgroundColor: token( '--bd-ink', '#0a2540' ), padding: 10, cornerRadius: 6, callbacks: { label: ( c ) => ` ${ isDonut ? c.label : c.dataset.label }  ${ fmt( isDonut ? c.parsed : c.parsed.y ) }` } },
		},
		...( isDonut ? { cutout: '68%' } : {
			scales: {
				x: { stacked: props.stacked, ticks: { color: muted, padding: 6, font: { size: 12 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 8 }, grid: { display: false }, border: { display: false } },
				y: { stacked: props.stacked, beginAtZero: true, ticks: { color: muted, padding: 6, maxTicksLimit: 5, font: { size: 12 }, callback: ( v ) => fmt( v ) }, grid: { color: ring }, border: { display: false } },
			},
		} ),
	};
	chart && chart.destroy();
	chart = new Chart( canvas.value, { type: props.type, data: { labels: props.labels, datasets }, options } );
};

onMounted( draw );
onBeforeUnmount( () => chart && chart.destroy() );
watch( () => [ props.type, props.labels, props.series, props.currency, props.stacked, props.dark ], () => setTimeout( draw, 0 ), { deep: true } );
</script>

<template>
	<div class="bd-chart" :style="{ height: height + 'px' }"><canvas ref="canvas" /></div>
</template>
