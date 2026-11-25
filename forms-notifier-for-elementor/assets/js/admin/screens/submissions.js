/**
 * Submission list and import of submissions Elementor collected itself.
 */
( function () {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const { h, mount, frag, icon, boot, api, ui, fmt, fields, bus, router } = window.fnfe;

	const PER_PAGE = 20;
	const STATUSES = [ 'new', 'read', 'done', 'spam', 'trash' ];

	/** Ids of the list shown last, for previous and next on the detail screen. */
	ui.lastSubmissions = [];

	/* ------------------------------------------------------------ Import */

	/** Opens the import dialog. */
	ui.importDialog = ( done ) => {
		const body = h( 'div', null, ui.Skeleton( 'rows', 3 ) );
		let stop = false;
		let running = false;
		const dialog = ui.Modal( { title: __( 'Import Elementor submissions', 'forms-notifier-for-elementor' ), desc: __( 'Copies what Elementor saved earlier. Nobody is notified and nothing is imported twice.', 'forms-notifier-for-elementor' ), body: body, onClose() {
			stop = true;
			if ( done ) {
				done();
			}
		} } );
		const load = () => api.get( 'submissions/import' ).then( draw, ( error ) => mount( body, ui.ErrorState( error, load ) ) );

		function draw( data ) {
			const left = data.total - data.imported;
			if ( ! data.available || ! data.total ) {
				mount( body, ui.Empty( { icon: 'database', small: true, title: __( 'Nothing to import', 'forms-notifier-for-elementor' ), text: __( 'Elementor has not collected any submissions on this site.', 'forms-notifier-for-elementor' ) } ) );
				dialog.setFooter( null );
				return;
			}
			const progress = h( 'i', { style: { width: ( data.imported / data.total * 100 ) + '%' } } );
			const text = h( 'p', { role: 'status' }, /* translators: %1$s: imported submissions, %2$s: all submissions */ sprintf( __( '%1$s of %2$s are here already.', 'forms-notifier-for-elementor' ), fmt.number( data.imported ), fmt.number( data.total ) ) );
			mount( body, frag(
				h( 'div', { class: 'fnfe-progress', 'aria-hidden': 'true' }, progress ),
				text,
				h( 'ul', { class: 'fnfe-feed fnfe-feed--plain' }, ( data.forms || [] ).map( ( form ) => h( 'li', null, h( 'span', { class: 'fnfe-feed__text' }, h( 'strong', { dir: 'auto' }, form.name || form.form_key ) ), h( 'span', { class: 'fnfe-muted' }, fmt.number( form.imported ) + ' / ' + fmt.number( form.total ) ), form.imported < form.total && form.form_id ? ui.Button( { label: __( 'Import', 'forms-notifier-for-elementor' ), size: 's', onClick: () => run( form.form_id, form.total - form.imported ) } ) : ( form.imported >= form.total ? icon( 'check', 18 ) : null ) ) ) )
			) );

			function run( formId, amount ) {
				if ( running ) {
					return null;
				}
				running = true;
				let imported = 0;
				let seen = 0;
				dialog.setFooter( [ h( 'span', { class: 'fnfe-grow' } ), ui.Button( { label: __( 'Stop', 'forms-notifier-for-elementor' ), onClick() {
					stop = true;
				} } ) ] );
				const step = ( cursor ) => api.post( 'submissions/import', { form_id: formId || 0, cursor: cursor } ).then( ( answer ) => {
					imported += answer.imported;
					seen += answer.imported + answer.skipped;
					progress.style.width = Math.min( 100, imported / Math.max( 1, amount ) * 100 ) + '%';
					/* translators: %s: number of submissions */
					text.textContent = sprintf( _n( '%s submission imported…', '%s submissions imported…', imported, 'forms-notifier-for-elementor' ), fmt.number( imported ) );
					return answer.done || stop ? null : step( answer.cursor );
				} );
				return step( 0 ).then( () => {
					running = false;
					stop = false;
					/* translators: %s: number of submissions */
					window.fnfe.toast( sprintf( _n( '%s submission imported.', '%s submissions imported.', imported, 'forms-notifier-for-elementor' ), fmt.number( imported ) ), 'success' );
					bus.emit( 'changed' );
					return load();
				}, ( error ) => {
					running = false;
					ui.fail( error );
					return load();
				} );
			}

			dialog.setFooter( left > 0 ? [ h( 'span', { class: 'fnfe-grow' } ), ui.Button( { /* translators: %s: number of submissions */ label: sprintf( _n( 'Import %s submission', 'Import %s submissions', left, 'forms-notifier-for-elementor' ), fmt.number( left ) ), icon: 'download', variant: 'primary', onClick: () => run( 0, left ) } ) ] : [ h( 'span', { class: 'fnfe-grow' } ), ui.Button( { label: __( 'Done', 'forms-notifier-for-elementor' ), variant: 'primary', onClick: () => dialog.close() } ) ] );
		}
		load();
	};

	/** Resolves to true when the site can import. */
	ui.canImport = () => ( boot.user.caps.manage ? ui.hasRoute( '/submissions/import' ).then( Boolean ) : Promise.resolve( false ) );

	/* -------------------------------------------------------------- List */

	function render( container, params, shell ) {
		const state = {};
		const read = ( query ) => Object.assign( state, { status: query.status || '', form_id: query.form_id || '', from: query.from || '', to: query.to || '', search: query.q || '', page: Number( query.page ) || 1 } );
		const filters = () => ( { status: state.status, form_id: state.form_id, from: state.from, to: state.to, search: state.search } );
		let selected = new Set();
		let forms = [];
		let counts = {};
		read( shell.query );
		const bars = h( 'div', null );
		const bulkSlot = h( 'div', null );
		const list = h( 'div', null );
		const off = h( 'div', null );
		const search = ui.Search( { value: state.search, placeholder: __( 'Search submissions', 'forms-notifier-for-elementor' ), onSearch: ( text ) => shell.setQuery( { q: text, page: '' }, true ) } );
		const importButton = ui.Button( { label: __( 'Import from Elementor', 'forms-notifier-for-elementor' ), icon: 'database', onClick: () => ui.importDialog( () => load( true ) ) } );
		importButton.hidden = true;
		ui.canImport().then( ( yes ) => {
			importButton.hidden = ! yes;
		} );
		shell.wide();
		shell.header( { title: __( 'Submissions', 'forms-notifier-for-elementor' ), sub: __( 'What people sent through your forms.', 'forms-notifier-for-elementor' ), actions: [ importButton, ui.Button( { label: __( 'Export CSV', 'forms-notifier-for-elementor' ), icon: 'download', onClick: () => api.download( 'submissions/export', Object.assign( { format: 'csv' }, filters() ), 'submissions.csv' ).catch( ui.fail ) } ) ] } );
		mount( container, frag( off, bars, bulkSlot, list ) );

		function drawFilters() {
			mount( bars, h( 'div', { class: 'fnfe-filters' },
				ui.Chips( { label: __( 'Status', 'forms-notifier-for-elementor' ), value: state.status, onChange: ( value ) => shell.setQuery( { status: value, page: '' } ), options: [ { value: '', label: __( 'Inbox', 'forms-notifier-for-elementor' ) } ].concat( STATUSES.map( ( id ) => ( { value: id, label: ui.statusLabel( id ), count: counts[ id ] || null } ) ) ) } ),
				h( 'div', { class: 'fnfe-filters__row' }, search, forms.length ? ui.Select( { small: true, label: __( 'All forms', 'forms-notifier-for-elementor' ), value: state.form_id, options: [ { value: '', label: __( 'All forms', 'forms-notifier-for-elementor' ) } ].concat( forms ), onChange: ( value ) => shell.setQuery( { form_id: value, page: '' } ) } ) : null, ui.DateRange( { from: state.from, to: state.to, onChange: ( name, value ) => shell.setQuery( { [ name ]: value, page: '' } ) } ) )
			) );
		}

		function bulk( action ) {
			return api.post( 'submissions/bulk', { action: action, ids: Array.from( selected ) } ).then( ( data ) => {
				/* translators: %s: number of submissions */
				window.fnfe.toast( sprintf( _n( '%s submission changed.', '%s submissions changed.', data.affected, 'forms-notifier-for-elementor' ), fmt.number( data.affected ) ), 'success' );
				selected = new Set();
				bus.emit( 'changed' );
				load( true );
			}, ui.fail );
		}

		function drawBulk() {
			const act = ( id, label, glyph ) => ui.Button( { label: label, icon: glyph, size: 's', onClick: () => bulk( id ) } );
			mount( bulkSlot, selected.size ? ui.BulkBar( selected.size, [
				act( 'read', __( 'Mark as read', 'forms-notifier-for-elementor' ), 'eye' ),
				act( 'done', __( 'Mark as done', 'forms-notifier-for-elementor' ), 'check' ),
				act( 'spam', __( 'Spam', 'forms-notifier-for-elementor' ), 'flag' ),
				state.status === 'trash' ? null : act( 'trash', __( 'Move to trash', 'forms-notifier-for-elementor' ), 'trash' ),
				shell.can( 'manage' ) && state.status === 'trash' ? ui.Button( { label: __( 'Delete for good', 'forms-notifier-for-elementor' ), icon: 'trash', size: 's', class: 'is-danger', onClick: () => ui.confirm( { /* translators: %s: number of submissions */ title: sprintf( _n( 'Delete %s submission?', 'Delete %s submissions?', selected.size, 'forms-notifier-for-elementor' ), fmt.number( selected.size ) ), text: __( 'The submissions and their files are gone for good.', 'forms-notifier-for-elementor' ), confirm: __( 'Delete', 'forms-notifier-for-elementor' ), danger: true } ).then( ( yes ) => yes && bulk( 'delete' ) ) } ) : null,
			], () => {
				selected.clear();
				load( true );
			} ) : null );
		}

		function draw( data ) {
			counts = data.counts || counts;
			drawFilters();
			ui.lastSubmissions = data.items.map( ( item ) => item.id );
			if ( ! data.items.length ) {
				const filtered = state.status || state.form_id || state.from || state.to || state.search;
				mount( list, filtered
					? ui.Empty( { icon: 'search', title: __( 'No submissions match these filters', 'forms-notifier-for-elementor' ), actions: ui.Button( { label: __( 'Clear filters', 'forms-notifier-for-elementor' ), onClick: () => shell.setQuery( { status: '', form_id: '', from: '', to: '', q: '', page: '' } ) } ) } )
					: ui.Empty( { icon: 'inbox', title: __( 'No submissions yet', 'forms-notifier-for-elementor' ), text: __( 'The next form somebody sends appears here.', 'forms-notifier-for-elementor' ), actions: ui.Button( { label: __( 'Open forms', 'forms-notifier-for-elementor' ), href: '#/forms' } ) } ) );
				return;
			}
			mount( list, ui.Card( { flush: true }, ui.Table( {
				label: __( 'Submissions', 'forms-notifier-for-elementor' ),
				rows: data.items,
				selectable: true,
				selected: selected,
				onSelect: drawBulk,
				onRow: ( row ) => router.go( 'submissions/' + row.id ),
				rowClass: ( row ) => ( row.status === 'new' ? 'is-unread' : '' ),
				columns: [
					{ label: __( 'Delivery', 'forms-notifier-for-elementor' ), class: 'fnfe-table__dot', render: ( row ) => ui.Dot( row.delivery_status ) },
					{ label: __( 'Content', 'forms-notifier-for-elementor' ), primary: true, class: 'fnfe-table__wide', render: ( row ) => h( 'a', { class: 'fnfe-previewline fnfe-latin', href: '#/submissions/' + row.id, dir: 'auto' }, row.preview || /* translators: %s: submission id */ sprintf( __( 'Submission %s', 'forms-notifier-for-elementor' ), row.id ) ) },
					{ label: __( 'Form', 'forms-notifier-for-elementor' ), class: 'fnfe-table__clip', render: ( row ) => h( 'bdi', null, row.form_name ) },
					{ label: __( 'Files', 'forms-notifier-for-elementor' ), render: ( row ) => ( row.files_count ? h( 'span', { class: 'fnfe-inline' }, icon( 'paperclip', 16 ), fmt.number( row.files_count ) ) : '' ) },
					{ label: __( 'Status', 'forms-notifier-for-elementor' ), render: ( row ) => ui.Status( row.status ) },
					{ label: __( 'Time', 'forms-notifier-for-elementor' ), class: 'fnfe-nowrap', render: ( row ) => h( 'time', { datetime: row.created, title: row.created_local }, fmt.relative( row.created ) ) },
				],
			} ), ui.Pagination( { page: data.page, perPage: data.per_page, total: data.total, onPage: ( page ) => shell.setQuery( { page: page } ) } ) ) );
			drawBulk();
		}

		function load( quiet ) {
			if ( ! quiet ) {
				mount( list, ui.Skeleton( 'rows', 8 ) );
			}
			return api.get( 'submissions', Object.assign( { page: state.page, per_page: PER_PAGE }, filters() ) ).then( draw, ( error ) => mount( list, ui.ErrorState( error, () => load() ) ) );
		}

		drawFilters();
		load();
		if ( boot.user.caps.forms ) {
			fields.forms().then( ( options ) => {
				forms = options;
				drawFilters();
			} );
		}
		if ( shell.can( 'manage' ) ) {
			api.get( 'settings', { tab: 'general' } ).then( ( data ) => data.values[ 'general.store_submissions' ] === false && mount( off, ui.Callout( 'info', __( 'New submissions are forwarded but not saved on this site.', 'forms-notifier-for-elementor' ), { title: __( 'Saving is switched off', 'forms-notifier-for-elementor' ), actions: ui.Button( { label: __( 'Open settings', 'forms-notifier-for-elementor' ), size: 's', href: '#/settings/general' } ) } ) ), () => {} );
		}
		const cleanup = () => {};
		cleanup.update = ( query ) => {
			read( query );
			selected = new Set();
			drawFilters();
			const input = search.querySelector( 'input' );
			if ( document.activeElement !== input ) {
				input.value = state.search;
			}
			load();
		};
		return cleanup;
	}

	window.fnfe.screens.register( 'submissions', { title: __( 'Submissions', 'forms-notifier-for-elementor' ), cap: 'submissions', render: render } );
}() );
