import { reactive } from 'vue';

const boot = window.bizydashBoot || {};

function parseHash() {
	const raw = ( window.location.hash || '#/' ).slice( 1 ) || '/';
	const [ path, qs = '' ] = raw.split( '?' );
	return {
		path,
		parts: path.split( '/' ).filter( Boolean ),
		query: Object.fromEntries( new URLSearchParams( qs ) ),
	};
}

export const store = reactive( {
	meta: boot.meta || { settings: {}, lookups: {}, enums: {} },
	nav: boot.nav && boot.nav.core ? boot.nav : { core: [], modules: [], footer: [] },
	modules: boot.modules || [],
	coreAnalytics: boot.coreAnalytics || {},
	frontend: !! boot.frontend,
	fullscreen: !! boot.fullscreen,
	adminUrl: boot.adminUrl || '',
	appUrl: boot.appUrl || '',
	logoutUrl: boot.logoutUrl || '',
	canManage: !! boot.canManage,
	staffOnly: !! boot.staffOnly,
	user: boot.user || '',
	userEmail: boot.userEmail || '',
	userId: Number( boot.userId ) || 0,
	canFinance: !! boot.canFinance,
	homeUrl: boot.homeUrl || '/',
	themePref: [ 'light', 'dark', 'auto' ].includes( boot.theme ) ? boot.theme : 'light', // what the person chose
	theme: 'light', // what is shown right now: light | dark
	route: parseHash(),
} );

const systemDark = () => !! ( window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' ).matches );
const resolveTheme = ( pref ) => ( pref === 'auto' ? ( systemDark() ? 'dark' : 'light' ) : pref );
store.theme = resolveTheme( store.themePref );
if ( window.matchMedia ) {
	// "System" follows the device live, e.g. when it switches to dark at sunset.
	window.matchMedia( '(prefers-color-scheme: dark)' ).addEventListener( 'change', () => {
		if ( store.themePref === 'auto' ) {
			store.theme = resolveTheme( 'auto' );
		}
	} );
}
/** Choose Light, Dark or System. The sign-in page reads the same choice. */
store.setTheme = ( pref ) => {
	store.themePref = pref;
	store.theme = resolveTheme( pref );
	try {
		window.localStorage.setItem( 'bd-theme', pref === 'auto' ? 'auto' : pref );
	} catch ( e ) {}
};
store.setTheme( store.themePref );

/** Is this module switched on? (Core is always on.) */
store.enabled = ( id ) => id === 'core' || store.nav.modules.some( ( m ) => m.id === id );

/** Which module an in-app route belongs to (mirrors Modules::route_module in PHP). */
export const routeModule = ( first ) => ( {
	work: 'work', projects: 'work', tasks: 'work',
	finance: 'finance', billing: 'finance', catalog: 'finance', charges: 'finance', invoices: 'finance', renewals: 'finance', services: 'finance',
	contracts: 'contracts', legal: 'contracts',
}[ first ] || 'core' );

// Opening from a WordPress menu entry (e.g. "BizyDash Work") lands on that module's overview.
if ( ! window.location.hash && boot.initialRoute && boot.initialRoute !== '/' ) {
	window.location.hash = boot.initialRoute;
	store.route = parseHash();
}

window.addEventListener( 'hashchange', () => {
	store.route = parseHash();
} );

export const navigate = ( to ) => {
	window.location.hash = to;
};
