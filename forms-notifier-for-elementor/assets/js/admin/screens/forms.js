/**
 * Form list.
 */
( function () {
	'use strict';

	const { __, _x, _n, sprintf } = wp.i18n;
	const { h, mount, frag, boot, api, ui, fmt, router } = window.fnfe;

	ui.sourceLabel = ( id ) => ( ( boot.sources || [] ).find( ( item ) => item.id === id ) || { label: id === 'api' ? __( 'Custom code', 'forms-notifier-for-elementor' ) : id } ).label;

	/** Whether the form notifies, in words. */
	ui.formState = ( form ) => {
		if ( form.enabled === 'inherit' ) {
			return ui.Badge( form.active ? __( 'On, as the site', 'forms-notifier-for-elementor' ) : __( 'Off, as the site', 'forms-notifier-for-elementor' ), form.active ? 'ok' : 'mute', form.active ? 'check-circle' : 'minus' );
		}
		return form.active ? ui.Badge( _x( 'On', 'form state', 'forms-notifier-for-elementor' ), 'ok', 'check-circle' ) : ui.Badge( _x( 'Off', 'form state', 'forms-notifier-for-elementor' ), 'mute', 'minus' );
	};

	ui.sourcesMissing = () => ( ( boot.sources || [] ).some( ( item ) => item.available ) ? null : ui.Callout( 'warning', __( 'Forms of Elementor Pro, atomic forms and Hello Plus forms are supported. None of them is active on this site, so no submission can be caught. Channels can still be set up.', 'forms-notifier-for-elementor' ), { title: __( 'No form builder found', 'forms-notifier-for-elementor' ) } ) );

	function chips( ids ) {
		return ids.length ? h( 'span', { class: 'fnfe-tiles' }, ids.slice( 0, 5 ).map( ( id ) => h( 'span', { title: ui.channelLabel( id ) }, ui.Tile( id, 's' ), h( 'span', { class: 'fnfe-sr' }, ui.channelLabel( id ) ) ) ), ids.length > 5 ? h( 'span', { class: 'fnfe-muted' }, '+' + fmt.number( ids.length - 5 ) ) : null ) : h( 'span', { class: 'fnfe-muted' }, '–' );
	}

	window.fnfe.screens.register( 'forms', {
		title: __( 'Forms', 'forms-notifier-for-elementor' ),
		cap: 'forms',
		render( container, params, shell ) {
			const state = {};
			const read = ( query ) => Object.assign( state, { search: query.q || '', source: query.source || '', page: Number( query.page ) || 1, orderby: query.orderby || '', order: query.order || '' } );
			read( shell.query );
			const list = h( 'div', null );
			const lost = h( 'div', null );
			const note = h( 'span', { class: 'fnfe-muted', role: 'status' } );
			const filters = h( 'div', null );
			const search = ui.Search( { value: state.search, placeholder: __( 'Search forms', 'forms-notifier-for-elementor' ), onSearch: ( text ) => shell.setQuery( { q: text, page: '' }, true ) } );
			const scanButton = ui.Button( { label: __( 'Scan now', 'forms-notifier-for-elementor' ), icon: 'refresh', variant: 'primary', onClick: scan } );
			shell.header( { title: __( 'Forms', 'forms-notifier-for-elementor' ), sub: __( 'Every Elementor form of this site. Open one to give it its own receivers, texts and rules.', 'forms-notifier-for-elementor' ), actions: scanButton } );
			mount( container, frag( ui.sourcesMissing(), h( 'div', { class: 'fnfe-toolbar' }, filters, h( 'span', { class: 'fnfe-grow' } ), note, search ), list, lost ) );

			function scan() {
				note.textContent = __( 'Scanning…', 'forms-notifier-for-elementor' );
				// Large sites are scanned in steps until the server reports the end.
				const step = ( total, guard ) => api.post( 'forms/scan' ).then( ( data ) => {
					const sum = { found: Math.max( total.found, data.found || 0 ), added: total.added + ( data.added || 0 ), updated: total.updated + ( data.updated || 0 ), missing: data.missing || 0 };
					if ( data.done === false && guard < 200 ) {
						/* translators: %s: number of documents */
						note.textContent = sprintf( __( 'Scanning… %s documents', 'forms-notifier-for-elementor' ), fmt.number( data.documents || 0 ) );
						return step( sum, guard + 1 );
					}
					return sum;
				} );
				return step( { found: 0, added: 0, updated: 0, missing: 0 }, 0 ).then( ( sum ) => {
					note.textContent = __( 'Scanned just now', 'forms-notifier-for-elementor' );
					/* translators: %1$s: forms found, %2$s: new forms */
					window.fnfe.toast( sprintf( __( 'Scan finished: %1$s found, %2$s new.', 'forms-notifier-for-elementor' ), fmt.number( sum.found ), fmt.number( sum.added ) ), 'success' );
					ui.forget( 'forms' );
					load();
				}, ( error ) => {
					note.textContent = '';
					ui.fail( error );
				} );
			}

			function drawFilters() {
				mount( filters, ui.Chips( { label: __( 'Kind of form', 'forms-notifier-for-elementor' ), value: state.source, options: [ { value: '', label: __( 'All', 'forms-notifier-for-elementor' ) } ].concat( ( boot.sources || [] ).filter( ( item ) => item.available || item.id === state.source ).map( ( item ) => ( { value: item.id, label: item.label } ) ) ), onChange: ( value ) => shell.setQuery( { source: value, page: '' } ) } ) );
			}

			function columns() {
				return [
					{ label: __( 'Form', 'forms-notifier-for-elementor' ), primary: true, sort: 'name', render: ( form ) => h( 'div', { class: 'fnfe-cell2' }, h( 'a', { href: '#/forms/' + form.id, dir: 'auto' }, form.name || form.form_key ), h( 'small', null, ui.sourceLabel( form.source ) ) ) },
					{ label: __( 'Page', 'forms-notifier-for-elementor' ), render: ( form ) => form.post_title ? h( 'span', { class: 'fnfe-inlineedit' }, h( 'bdi', null, form.post_title ), form.edit_url ? ui.IconButton( { icon: 'external', size: 's', href: form.edit_url, target: '_blank', label: __( 'Edit in Elementor', 'forms-notifier-for-elementor' ) } ) : null ) : '–' },
					{ label: __( 'Notifies', 'forms-notifier-for-elementor' ), render: ui.formState },
					{ label: __( 'Channels', 'forms-notifier-for-elementor' ), render: ( form ) => chips( form.channels || [] ) },
					{ label: __( 'Submissions', 'forms-notifier-for-elementor' ), class: 'fnfe-num', sort: 'submissions_count', render: ( form ) => form.submissions_count ? h( 'a', { href: '#/submissions?form_id=' + form.id }, fmt.number( form.submissions_count ) ) : fmt.number( 0 ) },
					{ label: __( 'Last one', 'forms-notifier-for-elementor' ), sort: 'last_submission', render: ( form ) => ui.Time( form.last_submission ) },
				];
			}

			function draw( data ) {
				if ( data.last_scan && ! note.textContent ) {
					/* translators: %s: relative time */
					note.textContent = sprintf( __( 'Last scan %s', 'forms-notifier-for-elementor' ), fmt.relative( data.last_scan ) );
				}
				const filtered = state.search || state.source;
				if ( ! data.items.length ) {
					mount( list, filtered
						? ui.Empty( { icon: 'search', title: __( 'No forms match these filters', 'forms-notifier-for-elementor' ), actions: ui.Button( { label: __( 'Clear filters', 'forms-notifier-for-elementor' ), onClick: () => shell.setQuery( { q: '', source: '', page: '' } ) } ) } )
						: ui.Empty( { icon: 'forms', title: __( 'No Elementor forms found yet', 'forms-notifier-for-elementor' ), text: __( 'Forms in pages, popups, templates and global widgets count. Scan the site, or just submit a form once.', 'forms-notifier-for-elementor' ), actions: ui.Button( { label: __( 'Scan for forms', 'forms-notifier-for-elementor' ), icon: 'refresh', variant: 'primary', onClick: scan } ) } ) );
					return;
				}
				mount( list, ui.Card( { flush: true }, ui.Table( { label: __( 'Forms', 'forms-notifier-for-elementor' ), rows: data.items, onRow: ( form ) => router.go( 'forms/' + form.id ), columns: columns(), orderby: state.orderby, order: state.order, onSort: ( orderby, order ) => shell.setQuery( { orderby: orderby, order: order, page: '' } ) } ), ui.Pagination( { page: data.page, perPage: data.per_page, total: data.total, onPage: ( page ) => shell.setQuery( { page: page } ) } ) ) );
			}

			function load() {
				const query = { search: state.search, source: state.source, status: 'active', page: state.page, per_page: 20, orderby: state.orderby, order: state.order };
				if ( ! list.firstChild ) {
					mount( list, ui.Skeleton( 'rows', 6 ) );
				}
				ui.cached( 'forms:' + JSON.stringify( query ), () => api.get( 'forms', query ), draw, ( error ) => mount( list, ui.ErrorState( error, load ) ) );
				api.get( 'forms', { status: 'missing,trashed', per_page: 50 } ).then( ( data ) => {
					const gone = data.items.filter( ( form ) => form.status !== 'active' );
					mount( lost, gone.length ? ui.Card( { flush: true, class: 'fnfe-card--quiet' }, ui.Fold( { class: 'fnfe-fold--card', /* translators: %s: number of forms */ title: sprintf( _n( '%s form no longer exists on the site', '%s forms no longer exist on the site', gone.length, 'forms-notifier-for-elementor' ), fmt.number( gone.length ) ) }, ui.Table( { rows: gone, columns: columns().slice( 0, 2 ).concat( columns()[ 4 ] ), actions: ( form ) => ui.IconButton( { icon: 'trash', size: 's', danger: true, label: __( 'Remove from the list', 'forms-notifier-for-elementor' ), onClick: () => ui.confirm( { /* translators: %s: form name */ title: sprintf( __( 'Remove "%s"?', 'forms-notifier-for-elementor' ), form.name ), text: __( 'Its settings are deleted. Saved submissions stay.', 'forms-notifier-for-elementor' ), confirm: __( 'Remove', 'forms-notifier-for-elementor' ), danger: true } ).then( ( yes ) => yes && api.del( 'forms/' + form.id ).then( load, ui.fail ) ) } ) } ) ) ) : null );
				}, () => {} );
			}

			drawFilters();
			load();
			const cleanup = () => {};
			cleanup.update = ( query ) => {
				read( query );
				drawFilters();
				const input = search.querySelector( 'input' );
				if ( document.activeElement !== input ) {
					input.value = state.search;
				}
				load();
			};
			return cleanup;
		},
	} );

	/* Link from the Elementor editor: the key is known there, the id is not. */
	window.fnfe.screens.register( 'forms/key/:key', {
		title: __( 'Form', 'forms-notifier-for-elementor' ),
		cap: 'forms',
		render( container, params ) {
			mount( container, ui.Skeleton( 'form', 4 ) );
			api.get( 'forms/key/' + params.key ).then( ( data ) => router.go( 'forms/' + data.form.id, true ), ( error ) => mount( container, error.status === 404 ? ui.Empty( { icon: 'forms', title: __( 'This form is not known yet', 'forms-notifier-for-elementor' ), text: __( 'Save the page in Elementor, then scan for forms.', 'forms-notifier-for-elementor' ), actions: ui.Button( { label: __( 'All forms', 'forms-notifier-for-elementor' ), href: '#/forms' } ) } ) : ui.ErrorState( error ) ) );
			return null;
		},
	} );
}() );
