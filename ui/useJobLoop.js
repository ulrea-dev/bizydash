import { ref } from 'vue';
import { api, errorMessage } from './api';

/**
 * Drives a step-based server job: calls the step route until it reports done, with a few retries on
 * network hiccups, so a slow host never leaves a half-finished job.
 */
export function useJobLoop() {
	const running = ref( false );
	const progress = ref( 0 );
	const phase = ref( '' );
	const error = ref( '' );

	async function run( path, first, stepData = {} ) {
		running.value = true;
		error.value = '';
		progress.value = 0;
		try {
			let state = first;
			while ( state && ! state.done ) {
				progress.value = state.progress || 0;
				phase.value = state.phase || '';
				let tries = 0;
				for ( ;; ) {
					try {
						state = await api.post( path, { job: state.id, ...stepData } );
						break;
					} catch ( e ) {
						// A definite server answer (4xx/5xx with a message) stops the job; a dropped connection is retried.
						if ( e.status || ++tries > 3 ) throw e;
						await new Promise( ( r ) => setTimeout( r, 1500 * tries ) );
					}
				}
			}
			progress.value = 100;
			return state;
		} catch ( e ) {
			error.value = errorMessage( e );
			throw e;
		} finally {
			running.value = false;
		}
	}

	return { running, progress, phase, error, run };
}
