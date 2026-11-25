/**
 * Channel list.
 */
( function () {
	'use strict';

	const { __, _n, _x, sprintf } = wp.i18n;
	const { h, mount, frag, icon, boot, api, ui, fmt, router, bus } = window.fnfe;

	/** State of a channel as [ label, tone ]. */
	ui.channelState = ( meta ) => {
		if ( ! meta.enabled ) {
			return [ _x( 'Off', 'channel state', 'forms-notifier-for-elementor' ), 'mute' ];
		}
		return meta.ready ? [ _x( 'Ready', 'channel state', 'forms-notifier-for-elementor' ), 'ok' ] : [ _x( 'Needs setup', 'channel state', 'forms-notifier-for-elementor' ), 'warn' ];
	};

	ui.channelPill = ( meta ) => {
		const state = ui.channelState( meta );
		const pill = h( 'span', { class: 'fnfe-lamp fnfe-lamp--' + state[ 1 ], title: meta.enabled && ! meta.ready ? meta.problems.join( '\n' ) : undefined }, h( 'i', { 'aria-hidden': 'true' } ), state[ 0 ] );
		return pill;
	};

	/** Switch a channel on or off. */
	ui.toggleChannel = ( meta, on ) => api.post( 'settings', { values: { [ 'channels.' + meta.id + '.enabled' ]: on }, secrets: {} } ).then( () => ui.refreshChannels() );

	function tile( meta ) {
		const go = () => router.go( 'channels/' + meta.id );
		return h(
			'article',
			{ class: [ 'fnfe-chan', meta.enabled ? 'is-on' : '' ], onClick( event ) {
				if ( ! event.target.closest( 'button,a' ) ) {
					go();
				}
			} },
			h( 'div', { class: 'fnfe-chan__top' }, ui.Tile( meta.id, 'l' ), h( 'span', { class: 'fnfe-grow' } ), ui.Switch( {
				checked: meta.enabled,
				/* translators: %s: channel name */
				ariaLabel: sprintf( __( 'Use %s', 'forms-notifier-for-elementor' ), meta.label ),
				onChange( on ) {
					ui.toggleChannel( meta, on ).then( ( list ) => {
						const next = ( list || [] ).find( ( item ) => item.id === meta.id );
						if ( on && next && ! next.ready ) {
							go();
						} else {
							window.fnfe.toast( on
								/* translators: %s: channel name */
								? sprintf( __( '%s is on.', 'forms-notifier-for-elementor' ), meta.label )
								/* translators: %s: channel name */
								: sprintf( __( '%s is off.', 'forms-notifier-for-elementor' ), meta.label ), 'success' );
						}
					}, ui.fail );
				},
			} ) ),
			h( 'h3', { class: 'fnfe-chan__name' }, h( 'a', { href: '#/channels/' + meta.id }, ui.tx( meta.label ) ) ),
			h( 'p', { class: 'fnfe-chan__desc' }, meta.enabled && ! meta.ready && meta.problems[ 0 ] ? ui.tx( meta.problems[ 0 ] ) : ui.tx( meta.description ) ),
			h( 'div', { class: 'fnfe-chan__foot' }, ui.channelPill( meta ), meta.capabilities.receivers === 'none' ? null : h( 'span', { class: 'fnfe-muted' }, /* translators: %s: number of receivers */ sprintf( _n( '%s receiver', '%s receivers', meta.receivers_count, 'forms-notifier-for-elementor' ), fmt.number( meta.receivers_count ) ) ) )
		);
	}

	function special( glyph, title, text, path ) {
		return h( 'a', { class: 'fnfe-chan fnfe-chan--link', href: '#/' + path }, h( 'span', { class: 'fnfe-chan__icon' }, icon( glyph, 22 ) ), h( 'span', null, h( 'strong', null, title ), h( 'small', null, text ) ), icon( 'chevron-end', 18 ) );
	}

	window.fnfe.screens.register( 'channels', {
		title: __( 'Channels', 'forms-notifier-for-elementor' ),
		cap: 'manage',
		render( container, params, shell ) {
			let query = ( shell.query.q || '' ).toLowerCase();
			const list = h( 'div', { class: 'fnfe-changroups' } );
			const draw = () => {
				const channels = ( boot.channels || [] ).filter( ( item ) => ! query || ( item.label + ' ' + item.description + ' ' + item.id ).toLowerCase().indexOf( query ) > -1 );
				const groups = Object.keys( boot.groups || {} );
				channels.forEach( ( item ) => groups.indexOf( item.group ) === -1 && groups.push( item.group ) );
				const nodes = groups.map( ( group ) => {
					const own = channels.filter( ( item ) => item.group === group );
					return own.length ? h( 'section', { class: [ 'fnfe-changroup', own.length === 1 ? 'fnfe-changroup--one' : '' ] }, h( 'h2', null, ( boot.groups || {} )[ group ] || group ), h( 'div', { class: 'fnfe-changrid' }, own.map( tile ) ) ) : null;
				} ).filter( Boolean );
				if ( ! ( boot.channels || [] ).length ) {
					nodes.push( ui.Empty( { icon: 'channels', title: __( 'No channels are available', 'forms-notifier-for-elementor' ), text: __( 'Reinstall the plugin if this stays empty.', 'forms-notifier-for-elementor' ) } ) );
				} else if ( ! nodes.length ) {
					nodes.push( ui.Empty( { icon: 'search', title: __( 'No channel matches', 'forms-notifier-for-elementor' ), actions: ui.Button( { label: __( 'Clear search', 'forms-notifier-for-elementor' ), onClick: () => shell.setQuery( { q: '' } ) } ) } ) );
				}
				mount( list, frag( nodes, query ? null : h( 'section', { class: 'fnfe-changroup' }, h( 'h2', null, __( 'For administrators', 'forms-notifier-for-elementor' ) ), h( 'div', { class: 'fnfe-changrid fnfe-changrid--2' }, special( 'alert', __( 'Failure alerts', 'forms-notifier-for-elementor' ), __( 'Hear about it when a message cannot be delivered.', 'forms-notifier-for-elementor' ), 'alerts' ), special( 'calendar', __( 'Digest', 'forms-notifier-for-elementor' ), __( 'A daily or weekly summary of this site.', 'forms-notifier-for-elementor' ), 'digest' ) ) ) ) );
			};
			const search = ui.Search( { value: shell.query.q || '', placeholder: __( 'Search channels', 'forms-notifier-for-elementor' ), wait: 150, onSearch: ( text ) => shell.setQuery( { q: text }, true ) } );
			const on = ( boot.channels || [] ).filter( ( item ) => item.enabled ).length;
			/* translators: %s: number of channels */
			shell.header( { title: __( 'Channels', 'forms-notifier-for-elementor' ), sub: on ? sprintf( _n( '%s channel is on', '%s channels are on', on, 'forms-notifier-for-elementor' ), fmt.number( on ) ) : __( 'Choose where form submissions go.', 'forms-notifier-for-elementor' ), actions: search } );
			container.appendChild( list );
			draw();
			const off = bus.on( 'channels', draw );
			ui.refreshChannels();
			const cleanup = () => off();
			cleanup.update = ( next ) => {
				query = ( next.q || '' ).toLowerCase();
				const input = search.querySelector( 'input' );
				if ( document.activeElement !== input ) {
					input.value = next.q || '';
				}
				draw();
			};
			return cleanup;
		},
	} );
}() );
