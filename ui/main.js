import { createApp } from 'vue';
import 'element-plus/dist/index.css';
import 'element-plus/theme-chalk/dark/css-vars.css';
import './styles/theme.scss';
import App from './App.vue';
import { components } from './components-registry';

const el = document.getElementById( 'bizydash-root' );
if ( el ) {
	const app = createApp( App );
	components.forEach( ( c ) => app.use( c ) );
	app.mount( el );
}
