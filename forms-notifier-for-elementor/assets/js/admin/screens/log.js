/**
 * Message log.
 */
( function () {
	'use strict';

	const { __, _x, _n, sprintf } = wp.i18n;
	const { h, mount, frag, boot, api, ui, fmt, fields, bus, router } = window.fnfe;

	const PER_PAGE = 20;
	const BUSY = [ 'queued', 'retry', 'sending' ];

	const purposeLabel = ( id ) => ( {
		admin: _x( 'Notification', 'message purpose', 'forms-notifier-for-elementor' ),
		submitter: _x( 'Reply', 'message purpose', 'forms-notifier-for-elementor' ),
		alert: _x( 'Alert', 'message purpose', 'forms-notifier-for-elementor' ),
		digest: _x( 'Digest', 'message purpose', 'forms-notifier-for-elementor' ),
		test: _x( 'Test', 'message purpose', 'forms-notifier-for-elementor' ),
	}[ id ] || id );

	/** Send again; resolves to the fresh summary. */
	ui.retryMessage = ( message ) => api.post( 'messages/' + message.id + '/retry' ).then( ( data ) => {
		const ok = data.message && data.message.status === 'sent';
		window.fnfe.toast( ok ? __( 'Sent.', 'forms-notifier-for-elementor' ) : ( data.message && data.message.error ) || __( 'It failed again.', 'forms-notifier-for-elementor' ), ok ? 'success' : 'danger' );
		bus.emit( 'changed' );
		return data.message;
	}, ( error ) => {
		ui.fail( error );
		return null;
	} );

	/** Delivery rows of a submission. */
	ui.deliveries = ( messages, reload ) => h( 'ul', { class: 'fnfe-timeline' }, messages.map( ( item ) => h( 'li', null,
		ui.Tile( item.channel, 's' ),
		h( 'div', { class: 'fnfe-feed__text' }, h( 'strong', null, item.channel_label, item.purpose === 'admin' ? null : h( 'span', { class: 'fnfe-muted' }, ' ', purposeLabel( item.purpose ) ) ), h( 'small', null, ui.raw( item.recipient_label || item.recipient ) ), item.error ? h( 'small', { class: 'fnfe-errtext', dir: 'auto' }, item.error ) : null ),
		ui.Time( item.sent || item.created ),
		ui.Status( item.status ),
		boot.user.caps.logs ? h( 'span', { class: 'fnfe-rowactions' }, [ 'failed', 'retry', 'skipped', 'cancelled' ].indexOf( item.status ) > -1 ? ui.IconButton( { icon: 'refresh', size: 's', label: __( 'Send again', 'forms-notifier-for-elementor' ), onClick: () => ui.retryMessage( item ).then( reload ) } ) : null, ui.IconButton( { icon: 'eye', size: 's', label: __( 'Details', 'forms-notifier-for-elementor' ), onClick: () => ui.messageSheet( item.id, reload ) } ) ) : null
	) ) );

	/** Side sheet of one message. */
	ui.messageSheet = ( id, changed, onClose ) => {
		const body = h( 'div', null, ui.Skeleton( 'form', 4 ) );
		const sheet = ui.Sheet( { title: __( 'Message', 'forms-notifier-for-elementor' ), size: 'l', body: body, onClose: onClose } );
		const load = () => api.get( 'messages/' + id ).then( ( data ) => draw( data.message ), ( error ) => mount( body, ui.ErrorState( error, load ) ) );
		const bulk = ( action, text ) => api.post( 'messages/bulk', { action: action, ids: [ id ] } ).then( ( data ) => {
			window.fnfe.toast( data.affected ? text : __( 'Nothing changed.', 'forms-notifier-for-elementor' ), data.affected ? 'success' : 'info' );
			bus.emit( 'changed' );
			if ( changed ) {
				changed();
			}
		}, ui.fail );

		function draw( item ) {
			const meta = ui.channelMeta( item.channel );
			const format = meta ? meta.format : 'text';
			const line = ( label, value ) => ( value === '' || value === null || value === undefined ? null : [ h( 'dt', null, label ), h( 'dd', null, value ) ] );
			sheet.el.querySelector( '.fnfe-dialog__title' ).textContent = item.channel_label;
			const raw = h( 'pre', { class: 'fnfe-preview__raw', dir: 'auto', hidden: true }, item.body );
			const shown = ui.bubble( { text: item.body, subject: item.subject, buttons: buttonRows( item.buttons ) }, format );
			const toggle = ui.IconButton( { icon: 'code', size: 's', label: __( 'Show the raw text', 'forms-notifier-for-elementor' ), attrs: { 'aria-pressed': false }, onClick() {
				raw.hidden = ! raw.hidden;
				shown.hidden = ! raw.hidden;
				toggle.setAttribute( 'aria-pressed', raw.hidden ? 'false' : 'true' );
			} } );
			mount( body, frag(
				h( 'div', { class: 'fnfe-msghead' }, ui.Tile( item.channel, 'l' ), h( 'div', { class: 'fnfe-grow' }, h( 'strong', { class: 'fnfe-latin', dir: 'auto' }, item.recipient_label || item.recipient ), item.recipient_label ? h( 'div', null, ui.mono( item.recipient ) ) : null ), ui.Status( item.status ) ),
				item.error ? ui.Callout( item.status === 'failed' ? 'danger' : 'warning', h( 'span', { dir: 'auto' }, item.error ) ) : null,
				h( 'div', { class: 'fnfe-preview fnfe-preview--sheet' }, h( 'div', { class: 'fnfe-preview__head' }, h( 'span', null, __( 'As sent', 'forms-notifier-for-elementor' ) ), h( 'span', { class: 'fnfe-grow' } ), item.body ? toggle : null ), item.body ? [ shown, raw ] : h( 'p', { class: 'fnfe-preview__none' }, __( 'The text was not kept.', 'forms-notifier-for-elementor' ) ) ),
				item.attachments.length || item.links.length ? h( 'ul', { class: 'fnfe-files' }, item.attachments.map( ( file ) => h( 'li', null, h( 'bdi', null, file.name ), h( 'span', { class: 'fnfe-muted' }, fmt.bytes( file.size ) ) ) ), item.links.map( ( link ) => h( 'li', null, h( 'a', { href: link.url, target: '_blank', rel: 'noopener noreferrer' }, h( 'bdi', null, link.name || link.url ) ) ) ) ) : null,
				h( 'dl', { class: 'fnfe-dl' },
					line( __( 'Purpose', 'forms-notifier-for-elementor' ), purposeLabel( item.purpose ) ),
					line( __( 'Form', 'forms-notifier-for-elementor' ), item.form_id ? h( 'a', { href: '#/forms/' + item.form_id, dir: 'auto' }, item.form_name || '#' + item.form_id ) : '' ),
					line( __( 'Submission', 'forms-notifier-for-elementor' ), item.submission_id ? h( 'a', { href: '#/submissions/' + item.submission_id }, ui.mono( '#' + item.submission_id ) ) : '' ),
					line( __( 'Caused by', 'forms-notifier-for-elementor' ), item.parent_id ? h( 'a', { href: '#/log/' + item.parent_id }, ui.mono( '#' + item.parent_id ) ) : '' ),
					line( __( 'Gateway', 'forms-notifier-for-elementor' ), item.provider && item.provider !== item.channel ? ui.mono( item.provider ) : '' ),
					line( __( 'Attempts', 'forms-notifier-for-elementor' ), h( 'span', { class: 'fnfe-inline' }, ui.Pips( item.attempts, item.max_attempts, item.status ), h( 'bdi', { dir: 'ltr' }, fmt.number( item.attempts ) + ' / ' + fmt.number( item.max_attempts ) ) ) ),
					line( __( 'Remote id', 'forms-notifier-for-elementor' ), item.remote_id ? ui.mono( item.remote_id ) : '' ),
					line( __( 'HTTP status', 'forms-notifier-for-elementor' ), item.http_code ? ui.mono( String( item.http_code ) ) : '' )
				),
				h( 'ol', { class: 'fnfe-times' },
					h( 'li', null, h( 'span', null, __( 'Created', 'forms-notifier-for-elementor' ) ), h( 'time', { datetime: item.created }, fmt.date( item.created ) ) ),
					item.sent ? h( 'li', { class: 'is-ok' }, h( 'span', null, ui.statusLabel( 'sent' ) ), h( 'time', { datetime: item.sent }, fmt.date( item.sent ) ) ) : null,
					item.next_attempt && BUSY.indexOf( item.status ) > -1 ? h( 'li', { class: 'is-next' }, h( 'span', null, __( 'Next attempt', 'forms-notifier-for-elementor' ) ), h( 'time', { datetime: item.next_attempt }, fmt.date( item.next_attempt ) ) ) : null
				),
				item.response ? ui.Fold( { class: 'fnfe-fold--inset', title: __( 'Answer of the service', 'forms-notifier-for-elementor' ) }, ui.Code( item.response ) ) : null
			) );
			sheet.setFooter( [
				ui.Button( { label: __( 'Delete', 'forms-notifier-for-elementor' ), icon: 'trash', variant: 'ghost', class: 'is-danger', onClick: () => ui.confirm( { title: __( 'Delete this entry?', 'forms-notifier-for-elementor' ), text: __( 'Only the log entry is deleted. A message that was sent stays sent.', 'forms-notifier-for-elementor' ), confirm: __( 'Delete', 'forms-notifier-for-elementor' ), danger: true } ).then( ( yes ) => yes && bulk( 'delete', __( 'Entry deleted.', 'forms-notifier-for-elementor' ) ).then( () => sheet.close() ) ) } ),
				h( 'span', { class: 'fnfe-grow' } ),
				BUSY.indexOf( item.status ) > -1 ? ui.Button( { label: __( 'Cancel sending', 'forms-notifier-for-elementor' ), variant: 'ghost', onClick: () => bulk( 'cancel', __( 'Cancelled.', 'forms-notifier-for-elementor' ) ).then( load ) } ) : null,
				item.status === 'sent' ? null : ui.Button( { label: item.status === 'queued' ? __( 'Send now', 'forms-notifier-for-elementor' ) : __( 'Send again', 'forms-notifier-for-elementor' ), icon: 'refresh', variant: 'primary', onClick: () => ui.retryMessage( item ).then( () => {
					if ( changed ) {
						changed();
					}
					return load();
				} ) } ),
			] );
		}
		load();
		return sheet;
	};

	function buttonRows( buttons ) {
		const list = fields.list( buttons );
		if ( list.length && Array.isArray( list[ 0 ] ) ) {
			return list;
		}
		const rows = {};
		list.forEach( ( button ) => ( rows[ button.row || 0 ] = rows[ button.row || 0 ] || [] ).push( button ) );
		return Object.values( rows );
	}

	function render( container, params, shell ) {
		const state = {};
		const read = ( query ) => Object.assign( state, { status: query.status || '', channel: query.channel || '', purpose: query.purpose || '', form_id: query.form_id || '', submission_id: query.submission_id || '', from: query.from || '', to: query.to || '', search: query.q || '', page: Number( query.page ) || 1 } );
		const filters = () => ( { status: state.status === 'queued' ? 'queued,sending' : state.status, channel: state.channel, purpose: state.purpose, form_id: state.form_id, submission_id: state.submission_id, from: state.from, to: state.to, search: state.search } );
		let selected = new Set();
		let timer = 0;
		let open = null;
		let forms = [];
		read( shell.query );

		const bars = h( 'div', null );
		const bulkSlot = h( 'div', null );
		const list = h( 'div', null );
		const updated = h( 'p', { class: 'fnfe-muted fnfe-updated', role: 'status' } );
		const search = ui.Search( { value: state.search, placeholder: __( 'Search receiver, error or text', 'forms-notifier-for-elementor' ), onSearch: ( text ) => shell.setQuery( { q: text, page: '' }, true ) } );
		const runButton = ui.Button( { label: __( 'Run queue now', 'forms-notifier-for-elementor' ), icon: 'play', onClick: () => api.post( 'queue/run' ).then( ( data ) => {
			/* translators: %1$s: sent messages, %2$s: failed messages, %3$s: waiting messages */
			window.fnfe.toast( sprintf( __( 'Queue run: %1$s sent, %2$s failed, %3$s waiting.', 'forms-notifier-for-elementor' ), fmt.number( data.sent ), fmt.number( data.failed ), fmt.number( data.remaining ) ), data.failed ? 'warning' : 'success' );
			bus.emit( 'changed' );
			load( true );
		}, ui.fail ) } );
		shell.wide();
		shell.header( { title: __( 'Log', 'forms-notifier-for-elementor' ), sub: __( 'Every message, its receiver and what the service answered.', 'forms-notifier-for-elementor' ), actions: [ ui.Button( { label: __( 'Export CSV', 'forms-notifier-for-elementor' ), icon: 'download', onClick: () => api.download( 'messages/export', filters(), 'messages.csv' ).catch( ui.fail ) } ), runButton ] } );
		mount( container, frag( bars, bulkSlot, list, updated ) );

		function drawFilters() {
			const option = ( value, label ) => ( { value: value, label: label } );
			const select = ( key, label, options ) => ui.Select( { small: true, label: label, value: state[ key ], options: [ option( '', label ) ].concat( options ), onChange: ( value ) => shell.setQuery( { [ key ]: value, page: '' } ) } );
			const any = [ 'channel', 'purpose', 'form_id', 'submission_id', 'from', 'to' ].some( ( key ) => state[ key ] );
			mount( bars, h( 'div', { class: 'fnfe-filters' },
				ui.Chips( { label: __( 'Status', 'forms-notifier-for-elementor' ), value: state.status, onChange: ( value ) => shell.setQuery( { status: value, page: '' } ), options: [ { value: '', label: __( 'All', 'forms-notifier-for-elementor' ) }, { value: 'sent', label: ui.statusLabel( 'sent' ), tone: 'ok' }, { value: 'failed', label: ui.statusLabel( 'failed' ), tone: 'danger' }, { value: 'retry', label: ui.statusLabel( 'retry' ), tone: 'warn' }, { value: 'queued', label: ui.statusLabel( 'queued' ), tone: 'info' }, { value: 'skipped', label: ui.statusLabel( 'skipped' ), tone: 'mute' } ] } ),
				h( 'div', { class: 'fnfe-filters__row' },
					search,
					select( 'channel', __( 'All channels', 'forms-notifier-for-elementor' ), ( boot.channels || [] ).map( ( item ) => option( item.id, item.label ) ) ),
					select( 'purpose', __( 'All purposes', 'forms-notifier-for-elementor' ), [ 'admin', 'submitter', 'alert', 'digest', 'test' ].map( ( id ) => option( id, purposeLabel( id ) ) ) ),
					select( 'form_id', __( 'All forms', 'forms-notifier-for-elementor' ), forms ),
					ui.DateRange( { from: state.from, to: state.to, onChange: ( name, value ) => shell.setQuery( { [ name ]: value, page: '' } ) } ),
					any ? ui.Button( { label: __( 'Clear filters', 'forms-notifier-for-elementor' ), variant: 'ghost', size: 's', icon: 'x', onClick: clear } ) : null
				)
			) );
		}

		function clear() {
			shell.setQuery( { status: '', channel: '', purpose: '', form_id: '', submission_id: '', from: '', to: '', q: '', page: '' } );
		}

		function drawBulk() {
			mount( bulkSlot, selected.size ? ui.BulkBar( selected.size, [
				ui.Button( { label: __( 'Send again', 'forms-notifier-for-elementor' ), icon: 'refresh', size: 's', onClick: () => bulk( 'retry' ) } ),
				ui.Button( { label: __( 'Cancel', 'forms-notifier-for-elementor' ), icon: 'x-circle', size: 's', onClick: () => bulk( 'cancel' ) } ),
				ui.Button( { label: __( 'Delete', 'forms-notifier-for-elementor' ), icon: 'trash', size: 's', class: 'is-danger', onClick: () => ui.confirm( { /* translators: %s: number of entries */ title: sprintf( _n( 'Delete %s entry?', 'Delete %s entries?', selected.size, 'forms-notifier-for-elementor' ), fmt.number( selected.size ) ), confirm: __( 'Delete', 'forms-notifier-for-elementor' ), danger: true } ).then( ( yes ) => yes && bulk( 'delete' ) ) } ),
			], () => {
				selected.clear();
				load( true );
			} ) : null );
		}

		function bulk( action ) {
			return api.post( 'messages/bulk', { action: action, ids: Array.from( selected ) } ).then( ( data ) => {
				/* translators: %s: number of entries */
				window.fnfe.toast( sprintf( _n( '%s entry changed.', '%s entries changed.', data.affected, 'forms-notifier-for-elementor' ), fmt.number( data.affected ) ), 'success' );
				selected = new Set();
				bus.emit( 'changed' );
				load( true );
			}, ui.fail );
		}

		const sheet = ( id ) => {
			if ( open ) {
				return;
			}
			open = ui.messageSheet( id, () => load( true ), () => {
				open = null;
				if ( params.id ) {
					router.go( 'log', true );
				}
			} );
		};

		function draw( data ) {
			window.clearTimeout( timer );
			if ( ! data.items.length ) {
				const filtered = Object.keys( state ).some( ( key ) => key !== 'page' && state[ key ] );
				mount( list, filtered
					? ui.Empty( { icon: 'search', title: __( 'No messages match these filters', 'forms-notifier-for-elementor' ), actions: ui.Button( { label: __( 'Clear filters', 'forms-notifier-for-elementor' ), onClick: clear } ) } )
					: ui.Empty( { icon: 'send', title: __( 'Nothing has been sent yet', 'forms-notifier-for-elementor' ), text: __( 'Submit a form, or send a test from a channel screen.', 'forms-notifier-for-elementor' ), actions: shell.can( 'manage' ) ? ui.Button( { label: __( 'Open channels', 'forms-notifier-for-elementor' ), variant: 'primary', href: '#/channels' } ) : null } ) );
				return;
			}
			const focus = document.activeElement && list.contains( document.activeElement ) ? Array.from( list.querySelectorAll( 'button,a,input' ) ).indexOf( document.activeElement ) : -1;
			mount( list, ui.Card( { flush: true }, ui.Table( {
				label: __( 'Messages', 'forms-notifier-for-elementor' ),
				rows: data.items,
				selectable: true,
				selected: selected,
				onSelect: drawBulk,
				onRow: ( row ) => sheet( row.id ),
				columns: [
					{ label: __( 'Status', 'forms-notifier-for-elementor' ), render: ( row ) => ui.Status( row.status ) },
					{ label: __( 'Receiver', 'forms-notifier-for-elementor' ), primary: true, class: 'fnfe-table__grow', render: ( row ) => h( 'div', { class: 'fnfe-cell2' }, h( 'button', { type: 'button', class: 'fnfe-linkbtn fnfe-latin', dir: 'auto', onClick: () => sheet( row.id ) }, row.recipient_label || row.recipient || '–' ), row.recipient_label && row.recipient ? h( 'small', null, ui.mono( row.recipient ) ) : null, row.error ? h( 'small', { class: 'fnfe-errtext', dir: 'auto', title: row.error }, row.error ) : null ) },
					{ label: __( 'Channel', 'forms-notifier-for-elementor' ), render: ( row ) => h( 'span', { class: 'fnfe-channelchip' }, ui.Tile( row.channel, 's' ), h( 'span', null, row.channel_label, row.purpose === 'admin' ? null : h( 'small', null, purposeLabel( row.purpose ) ) ) ) },
					{ label: __( 'Form', 'forms-notifier-for-elementor' ), class: 'fnfe-table__clip', render: ( row ) => ( row.submission_id ? h( 'a', { href: '#/submissions/' + row.submission_id, dir: 'auto' }, row.form_name || '#' + row.submission_id ) : h( 'bdi', null, row.form_name || '–' ) ) },
					{ label: __( 'Attempts', 'forms-notifier-for-elementor' ), class: 'fnfe-col-lg', render: ( row ) => ui.Pips( row.attempts, row.max_attempts, row.status ) },
					{ label: __( 'Time', 'forms-notifier-for-elementor' ), class: 'fnfe-nowrap', render: ( row ) => ui.Time( row.sent || row.created ) },
				],
				actions: ( row ) => ( [ 'failed', 'retry', 'cancelled', 'skipped' ].indexOf( row.status ) > -1 ? ui.IconButton( { icon: 'refresh', size: 's', label: __( 'Send again', 'forms-notifier-for-elementor' ), onClick: () => ui.retryMessage( row ).then( () => load( true ) ) } ) : null ),
			} ), ui.Pagination( { page: data.page, perPage: data.per_page, total: data.total, onPage: ( page ) => shell.setQuery( { page: page } ) } ) ) );
			if ( focus > -1 ) {
				const again = list.querySelectorAll( 'button,a,input' )[ focus ];
				if ( again ) {
					again.focus( { preventScroll: true } );
				}
			}
			drawBulk();
			if ( data.items.some( ( row ) => BUSY.indexOf( row.status ) > -1 ) ) {
				timer = window.setTimeout( () => ( document.hidden || selected.size ? tick() : load( true ) ), 15000 );
			}
		}

		function tick() {
			timer = window.setTimeout( () => ( document.hidden || selected.size ? tick() : load( true ) ), 15000 );
		}

		function load( quiet ) {
			window.clearTimeout( timer );
			if ( ! quiet ) {
				mount( list, ui.Skeleton( 'rows', 8 ) );
			}
			return api.get( 'messages', Object.assign( { page: state.page, per_page: PER_PAGE }, filters() ) ).then( ( data ) => {
				draw( data );
				/* translators: %s: time of day */
				updated.textContent = sprintf( __( 'Updated at %s', 'forms-notifier-for-elementor' ), fmt.date( new Date(), { timeStyle: 'medium' } ) );
			}, ( error ) => mount( list, ui.ErrorState( error, () => load() ) ) );
		}

		drawFilters();
		load();
		if ( boot.user.caps.forms ) {
			fields.forms().then( ( options ) => {
				forms = options;
				drawFilters();
			} );
		}
		if ( params.id ) {
			sheet( params.id );
		}
		const cleanup = () => window.clearTimeout( timer );
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

	const def = { title: __( 'Log', 'forms-notifier-for-elementor' ), cap: 'logs', render: render };
	window.fnfe.screens.register( 'log', def );
	window.fnfe.screens.register( 'log/:id', def );
}() );
