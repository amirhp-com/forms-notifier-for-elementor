/**
 * Tools: export, import, system information, system log.
 */
( function () {
	'use strict';

	const { __, _x, _n, sprintf } = wp.i18n;
	const { h, mount, frag, icon, boot, api, ui, fmt } = window.fnfe;

	const today = () => new Date().toISOString().slice( 0, 10 );

	function exportCard() {
		const include = { settings: true, forms: true };
		const box = ( key, label ) => h( 'label', { class: 'fnfe-radio' }, h( 'input', { type: 'checkbox', class: 'fnfe-check', checked: true, onChange( event ) {
			include[ key ] = event.target.checked;
			button.disabled = ! include.settings && ! include.forms;
		} } ), h( 'span', null, label ) );
		const button = ui.Button( { label: __( 'Download', 'forms-notifier-for-elementor' ), icon: 'download', onClick: () => api.download( 'tools/export', { include: Object.keys( include ).filter( ( key ) => include[ key ] ) }, 'forms-notifier-' + today() + '.json' ).catch( ui.fail ) } );
		return ui.Card( { title: __( 'Export', 'forms-notifier-for-elementor' ), desc: __( 'A JSON file for another site or a backup. Tokens and passwords are never part of it.', 'forms-notifier-for-elementor' ), foot: button }, h( 'div', { class: 'fnfe-radios' }, box( 'settings', __( 'Settings and channels', 'forms-notifier-for-elementor' ) ), box( 'forms', __( 'Form settings', 'forms-notifier-for-elementor' ) ) ) );
	}

	function importCard() {
		let parsed = null;
		let mode = 'merge';
		const out = h( 'div', null );
		const name = h( 'span', null, __( 'Drop the file here or choose it', 'forms-notifier-for-elementor' ) );
		const input = h( 'input', { type: 'file', accept: '.json,application/json', class: 'fnfe-sr', onChange: () => read( input.files[ 0 ] ) } );
		const drop = h( 'label', { class: 'fnfe-drop', onDragover( event ) {
			event.preventDefault();
			drop.classList.add( 'is-over' );
		}, onDragleave: () => drop.classList.remove( 'is-over' ), onDrop( event ) {
			event.preventDefault();
			drop.classList.remove( 'is-over' );
			read( event.dataTransfer.files[ 0 ] );
		} }, icon( 'upload', 22 ), name, input );

		function read( file ) {
			if ( ! file ) {
				return;
			}
			mount( out, null );
			parsed = null;
			button.disabled = true;
			if ( file.size > 2097152 ) {
				mount( out, ui.Callout( 'danger', __( 'The file is larger than 2 MB.', 'forms-notifier-for-elementor' ) ) );
				return;
			}
			file.text().then( ( text ) => {
				try {
					parsed = JSON.parse( text );
				} catch ( e ) {
					parsed = null;
				}
				if ( ! parsed || typeof parsed !== 'object' || Array.isArray( parsed ) ) {
					parsed = null;
					mount( out, ui.Callout( 'danger', __( 'This is not a JSON file.', 'forms-notifier-for-elementor' ) ) );
					return;
				}
				name.textContent = file.name;
				button.disabled = false;
			} );
		}

		const run = () => ( mode === 'replace' ? ui.confirm( { title: __( 'Replace the current settings?', 'forms-notifier-for-elementor' ), text: __( 'Settings that are not in the file go back to their defaults.', 'forms-notifier-for-elementor' ), confirm: __( 'Replace', 'forms-notifier-for-elementor' ), danger: true } ) : Promise.resolve( true ) ).then( ( yes ) => ( yes ? api.post( 'tools/import', { data: parsed, mode: mode } ).then( ( data ) => {
			mount( out, frag(
				/* translators: %1$s: number of settings, %2$s: number of forms */
				ui.Callout( 'success', sprintf( __( 'Imported: %1$s settings, %2$s forms.', 'forms-notifier-for-elementor' ), fmt.number( data.imported.settings ), fmt.number( data.imported.forms ) ) ),
				data.warnings.length ? ui.Callout( 'warning', h( 'ul', { class: 'fnfe-list-plain' }, data.warnings.map( ( text ) => h( 'li', { dir: 'auto' }, text ) ) ), { title: __( 'Please check', 'forms-notifier-for-elementor' ) } ) : null
			) );
			ui.forget();
			ui.refreshChannels();
		}, ( error ) => mount( out, ui.Callout( 'danger', error.message ) ) ) : null ) );
		const button = ui.Button( { label: __( 'Import', 'forms-notifier-for-elementor' ), icon: 'upload', disabled: true, onClick: run } );
		return ui.Card( { title: __( 'Import', 'forms-notifier-for-elementor' ), desc: __( 'Reads a file made by the export.', 'forms-notifier-for-elementor' ), foot: button }, drop, ui.Segmented( { label: __( 'How to import', 'forms-notifier-for-elementor' ), value: mode, options: [ { value: 'merge', label: __( 'Add to the current settings', 'forms-notifier-for-elementor' ) }, { value: 'replace', label: __( 'Replace them', 'forms-notifier-for-elementor' ) } ], onChange( value ) {
			mode = value;
		} } ), out );
	}

	/** System information as indented text. */
	function asText( value, depth ) {
		const pad = '  '.repeat( depth || 0 );
		return Object.keys( value ).map( ( key ) => {
			const item = value[ key ];
			if ( Array.isArray( item ) ) {
				return pad + key + ': ' + ( item.join( ', ' ) || '-' );
			}
			if ( item && typeof item === 'object' ) {
				return pad + key + ':\n' + asText( item, ( depth || 0 ) + 1 );
			}
			return pad + key + ': ' + ( item === true ? 'yes' : item === false ? 'no' : item === null || item === '' ? '-' : item );
		} ).join( '\n' );
	}

	function systemCard() {
		const body = h( 'div', null, ui.Skeleton( 'form', 3 ) );
		const load = () => api.get( 'tools/system' ).then( ( data ) => mount( body, ui.Code( asText( data ) ) ), ( error ) => mount( body, ui.ErrorState( error, load ) ) );
		load();
		return ui.Card( { title: __( 'System information', 'forms-notifier-for-elementor' ), desc: __( 'Copy this into a support request. It holds no secrets.', 'forms-notifier-for-elementor' ) }, body );
	}

	function logCard( shell ) {
		const state = { level: shell.query.level || '', page: 1, search: '' };
		const body = h( 'div', null );
		const bar = h( 'div', null );
		const levels = [ { value: '', label: __( 'All', 'forms-notifier-for-elementor' ) }, { value: 'error', label: _x( 'Error', 'log level', 'forms-notifier-for-elementor' ), tone: 'danger' }, { value: 'warning', label: _x( 'Warning', 'log level', 'forms-notifier-for-elementor' ), tone: 'warn' }, { value: 'info', label: _x( 'Info', 'log level', 'forms-notifier-for-elementor' ), tone: 'info' }, { value: 'debug', label: _x( 'Debug', 'log level', 'forms-notifier-for-elementor' ), tone: 'mute' } ];
		const load = () => {
			mount( bar, ui.Chips( { label: __( 'Level', 'forms-notifier-for-elementor' ), value: state.level, options: levels, onChange( value ) {
				state.level = value;
				state.page = 1;
				load();
			} } ) );
			return api.get( 'logs', { level: state.level, search: state.search, page: state.page, per_page: 20 } ).then( ( data ) => mount( body, data.items.length ? frag( ui.Table( { label: __( 'System log', 'forms-notifier-for-elementor' ), rows: data.items, columns: [
				{ label: __( 'Level', 'forms-notifier-for-elementor' ), render: ( row ) => ui.Badge( ( levels.find( ( item ) => item.value === row.level ) || { label: row.level } ).label, ( levels.find( ( item ) => item.value === row.level ) || {} ).tone ) },
				{ label: __( 'Message', 'forms-notifier-for-elementor' ), primary: true, class: 'fnfe-table__wide', render: ( row ) => h( 'div', { class: 'fnfe-cell2' }, h( 'span', { class: 'fnfe-latin', dir: 'auto' }, row.message ), row.data && Object.keys( row.data ).length ? h( 'small', { class: 'fnfe-logdata' }, ui.mono( JSON.stringify( row.data ) ) ) : null ) },
				{ label: __( 'Part', 'forms-notifier-for-elementor' ), render: ( row ) => ui.mono( row.context ) },
				{ label: __( 'Time', 'forms-notifier-for-elementor' ), class: 'fnfe-nowrap', render: ( row ) => ui.Time( row.created ) },
			] } ), ui.Pagination( { page: data.page, perPage: data.per_page, total: data.total, onPage( page ) {
				state.page = page;
				load();
			} } ) ) : ui.Empty( { icon: 'list', small: true, text: state.level || state.search ? __( 'No entries match.', 'forms-notifier-for-elementor' ) : __( 'The system log is empty.', 'forms-notifier-for-elementor' ) } ) ), ( error ) => mount( body, ui.ErrorState( error, load ) ) );
		};
		mount( body, ui.Skeleton( 'rows', 4 ) );
		load();
		const card = ui.Card( { title: __( 'System log', 'forms-notifier-for-elementor' ), desc: __( 'What the plugin did in the background.', 'forms-notifier-for-elementor' ), flush: true, actions: ui.Button( { label: __( 'Clear', 'forms-notifier-for-elementor' ), icon: 'trash', size: 's', variant: 'ghost', class: 'is-danger', onClick: () => ui.confirm( { title: __( 'Clear the system log?', 'forms-notifier-for-elementor' ), text: __( 'The message log is not affected.', 'forms-notifier-for-elementor' ), confirm: __( 'Clear', 'forms-notifier-for-elementor' ), danger: true } ).then( ( yes ) => yes && api.del( 'logs' ).then( load, ui.fail ) ) } ) }, h( 'div', { class: 'fnfe-toolbar fnfe-toolbar--card' }, bar, h( 'span', { class: 'fnfe-grow' } ), ui.Search( { onSearch( text ) {
			state.search = text;
			state.page = 1;
			load();
		} } ) ), body );
		card.reload = load;
		return card;
	}

	function dangerCard( after ) {
		const row = ( title, text, button ) => h( 'li', null, h( 'span', { class: 'fnfe-feed__text' }, h( 'strong', null, title ), h( 'small', null, text ) ), button );
		const list = h( 'ul', { class: 'fnfe-feed fnfe-feed--plain' } );
		list.appendChild( row( __( 'Delete all system log entries', 'forms-notifier-for-elementor' ), __( 'Messages and submissions stay.', 'forms-notifier-for-elementor' ), ui.Button( { label: __( 'Delete', 'forms-notifier-for-elementor' ), variant: 'danger', size: 's', onClick: () => ui.confirm( { title: __( 'Delete all system log entries?', 'forms-notifier-for-elementor' ), confirm: __( 'Delete', 'forms-notifier-for-elementor' ), danger: true } ).then( ( yes ) => yes && api.del( 'logs' ).then( () => {
			window.fnfe.toast( __( 'System log deleted.', 'forms-notifier-for-elementor' ), 'success' );
			after();
		}, ui.fail ) ) } ) ) );
		ui.hasRoute( '/app/revoke' ).then( ( yes ) => yes && list.appendChild( row( __( 'Sign out of the mini app everywhere', 'forms-notifier-for-elementor' ), __( 'Every admin signs in again the next time they open it.', 'forms-notifier-for-elementor' ), ui.Button( { label: __( 'Sign out all', 'forms-notifier-for-elementor' ), variant: 'danger', size: 's', onClick: () => ui.confirm( { title: __( 'End all mini app sessions?', 'forms-notifier-for-elementor' ), confirm: __( 'Sign out all', 'forms-notifier-for-elementor' ), danger: true } ).then( ( ok ) => ok && api.post( 'app/revoke' ).then( () => window.fnfe.toast( __( 'All sessions ended.', 'forms-notifier-for-elementor' ), 'success' ), ui.fail ) ) } ) ) ) );
		return ui.Card( { title: __( 'Danger zone', 'forms-notifier-for-elementor' ), flush: true, class: 'fnfe-card--danger' }, list );
	}

	/** Texts for WPML or Polylang. */
	function stringsCard() {
		const provider = { wpml: 'WPML', polylang: 'Polylang' }[ boot.i18n && boot.i18n.provider ] || ( boot.i18n && boot.i18n.provider ) || '';
		const out = h( 'div', null );
		const button = ui.Button( { label: __( 'Register texts for translation', 'forms-notifier-for-elementor' ), icon: 'globe', disabled: ! provider, onClick: () => api.post( 'strings/sync' ).then( ( data ) => mount( out, data.ok
			? ui.Callout( 'success', sprintf( /* translators: %s: number of texts */ _n( '%s text is ready to translate.', '%s texts are ready to translate.', data.registered, 'forms-notifier-for-elementor' ), fmt.number( data.registered ) ) )
			: ui.Callout( 'warning', __( 'No translation plugin is active. Reload this page.', 'forms-notifier-for-elementor' ) ) ), ( error ) => mount( out, ui.Callout( 'danger', error.message ) ) ) } );
		return ui.Card( {
			title: __( 'Translations', 'forms-notifier-for-elementor' ),
			desc: provider
				? sprintf( /* translators: %s: WPML or Polylang */ __( 'Makes your message texts, subjects and buttons available in %s. This happens by itself; use it when a text is missing there.', 'forms-notifier-for-elementor' ), provider )
				: __( 'For sites with WPML or Polylang. None of them is active here.', 'forms-notifier-for-elementor' ),
			actions: button,
		}, out );
	}

	function optional() {
		const el = h( 'div', null );
		const elementor = h( 'div', null );
		ui.canImport().then( ( yes ) => yes && mount( elementor, ui.Card( { title: __( 'Elementor submissions', 'forms-notifier-for-elementor' ), desc: __( 'Copy the submissions Elementor Pro collected before you used this plugin.', 'forms-notifier-for-elementor' ), actions: ui.Button( { label: __( 'Import…', 'forms-notifier-for-elementor' ), icon: 'database', onClick: () => ui.importDialog() } ) }, null ) ) );
		ui.hasRoute( '/strings/sync' ).then( ( yes ) => yes && mount( el, stringsCard() ) );
		return [ elementor, el ];
	}

	window.fnfe.screens.register( 'tools', {
		title: __( 'Tools', 'forms-notifier-for-elementor' ),
		cap: 'manage',
		render( container, params, shell ) {
			shell.header( { title: __( 'Tools', 'forms-notifier-for-elementor' ), crumbs: [ { label: __( 'Settings', 'forms-notifier-for-elementor' ), path: 'settings' }, { label: __( 'Tools', 'forms-notifier-for-elementor' ) } ], help: 'troubleshooting' } );
			const log = logCard( shell );
			mount( container, h( 'div', { class: 'fnfe-split' }, h( 'div', { class: 'fnfe-split__side' }, ui.settingsNav( 'tools' ) ), h( 'div', { class: 'fnfe-split__main' }, h( 'div', { class: 'fnfe-duo' }, exportCard(), importCard() ), optional(), log, systemCard(), dangerCard( log.reload ) ) ) );
			return null;
		},
	} );
}() );
