import { store } from './store';

const boot = window.bizydashBoot || {};

const qs = ( o ) => {
	const p = new URLSearchParams();
	Object.entries( o || {} ).forEach( ( [ k, v ] ) => {
		if ( v !== undefined && v !== null && v !== '' && v !== false ) {
			p.append( k, v === true ? 1 : v );
		}
	} );
	return p.toString();
};

/** Join the REST base with a path + query, supporting both pretty and ?rest_route= permalinks. */
export const buildUrl = ( path, params ) => {
	const base = boot.restUrl || '/wp-json/bizydash/v1';
	const q = qs( params );
	let url = base;
	if ( base.includes( '?' ) ) {
		// ?rest_route=/bizydash/v1 → the route lives in the query string.
		url = base + path;
		return q ? url + '&' + q : url;
	}
	url = base.replace( /\/$/, '' ) + path;
	return q ? url + '?' + q : url;
};

async function request( method, path, { params, data, form } = {} ) {
	const res = await fetch( buildUrl( path, params ), {
		method,
		credentials: 'same-origin',
		headers: { 'X-WP-Nonce': boot.nonce || '', ...( form ? {} : { 'Content-Type': 'application/json' } ), Accept: 'application/json' },
		body: form || ( data === undefined ? undefined : JSON.stringify( data ) ),
	} );
	let body = null;
	try {
		body = await res.json();
	} catch ( e ) {
		body = null;
	}
	if ( ! res.ok ) {
		const err = new Error( ( body && body.message ) || `Request failed (${ res.status })` );
		err.status = res.status;
		err.body = body;
		throw err;
	}
	return body;
}

export const api = {
	list: ( res, params ) => request( 'GET', `/${ res }`, { params } ),
	get: ( res, id ) => request( 'GET', `/${ res }/${ id }` ),
	create: ( res, data ) => request( 'POST', `/${ res }`, { data } ),
	update: ( res, id, data ) => request( 'PUT', `/${ res }/${ id }`, { data } ),
	remove: ( res, id ) => request( 'DELETE', `/${ res }/${ id }` ),
	post: ( path, data ) => request( 'POST', `/${ path }`, { data: data || {} } ),
	upload: ( path, form ) => request( 'POST', `/${ path }`, { form } ),
	getPath: ( path, params ) => request( 'GET', `/${ path }`, { params } ),
};

export async function refreshMeta() {
	store.meta = await api.getPath( 'meta' );
}

export const errorMessage = ( e ) => ( e && e.message ) || 'Something went wrong.';
