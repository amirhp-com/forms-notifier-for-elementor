/**
 * Screen of one channel.
 */
( function () {
	'use strict';

	const { __, sprintf } = wp.i18n;
	const { h, mount, frag, api, ui, fields, router, bus } = window.fnfe;

	const BOTS = [ 'telegram', 'bale' ];

	/** Tabs of a bot channel. */
	function botTabs( id, active ) {
		return ui.Tabs( {
			label: __( 'Sections', 'forms-notifier-for-elementor' ),
			class: 'fnfe-tabs--page',
			active: active,
			tabs: [
				{ id: 'settings', label: __( 'Settings', 'forms-notifier-for-elementor' ), icon: 'settings' },
				{ id: 'chats', label: __( 'Chats', 'forms-notifier-for-elementor' ), icon: 'chat' },
				{ id: 'admins', label: __( 'Admins', 'forms-notifier-for-elementor' ), icon: 'users' },
				{ id: 'connection', label: __( 'Connection', 'forms-notifier-for-elementor' ), icon: 'bolt' },
			],
			onSelect: ( tab ) => router.go( tab === 'settings' ? 'channels/' + id : 'bots/' + id + '/' + tab ),
		} );
	}

	/** Header of a channel: tile, state, problems, switch, test. panel may be null. */
	ui.channelHeader = ( shell, id, panel ) => {
		const meta = ui.channelMeta( id );
		const toggle = ui.Switch( {
			checked: meta.enabled,
			/* translators: %s: channel name */
			ariaLabel: sprintf( __( 'Use %s', 'forms-notifier-for-elementor' ), meta.label ),
			onChange( on ) {
				ui.toggleChannel( meta, on ).then( () => {
					if ( panel && panel.sync ) {
						panel.sync( 'channels.' + id + '.enabled', on );
					}
				}, ( error ) => {
					toggle.set( ! on );
					ui.fail( error );
				} );
			},
		} );
		shell.header( {
			title: meta.label,
			sub: meta.description,
			lead: ui.Tile( id, 'l' ),
			crumbs: [ { label: __( 'Channels', 'forms-notifier-for-elementor' ), path: 'channels' }, { label: meta.label } ],
			help: 'channel-' + id,
			actions: [
				ui.channelPill( meta ),
				toggle,
				ui.Button( { label: __( 'Send test', 'forms-notifier-for-elementor' ), icon: 'send', variant: meta.enabled && meta.ready && ! ( panel && panel.dirty() ) ? 'primary' : 'secondary', onClick: () => testDialog( meta, panel ) } ),
			],
		} );
	};

	function testDialog( meta, panel ) {
		const receivers = panel ? fields.list( panel.get( 'channels.' + meta.id + '.receivers' ) ) : [];
		const typed = meta.capabilities.receivers === 'list';
		const draft = { to: '', text: '' };
		const out = h( 'div', null );
		const grid = h( 'div', { class: 'fnfe-grid' } );
		const other = fields.render( { key: 'to', type: 'text', label: __( 'Address', 'forms-notifier-for-elementor' ), attrs: { dir: 'ltr' } }, '', ( text ) => {
			draft.to = text.trim();
		}, {} );
		if ( typed ) {
			const usable = receivers.filter( ( row ) => row.enabled !== false && ! /^[{[]/.test( row.address ) );
			const options = usable.map( ( row ) => ( { value: row.address, label: row.label ? row.label : row.address } ) );
			options.push( { value: '', label: __( 'Another address…', 'forms-notifier-for-elementor' ) } );
			draft.to = options[ 0 ].value;
			other.hidden = draft.to !== '';
			grid.appendChild( fields.render( { key: 'pick', type: 'select', label: __( 'Send to', 'forms-notifier-for-elementor' ), options: options }, draft.to, ( value ) => {
				draft.to = value;
				other.hidden = value !== '';
			}, {} ) );
			grid.appendChild( other );
		} else {
			grid.appendChild( h( 'p', { class: 'fnfe-field fnfe-field--full fnfe-muted' }, __( 'This channel has one fixed destination.', 'forms-notifier-for-elementor' ) ) );
		}
		if ( meta.id === 'sms' || meta.id === 'whatsapp' ) {
			grid.appendChild( fields.render( { key: 'text', type: 'textarea', label: __( 'Text (optional)', 'forms-notifier-for-elementor' ), desc: __( 'A pattern text works too.', 'forms-notifier-for-elementor' ) }, '', ( text ) => {
				draft.text = text;
			}, {} ) );
		}
		const dialog = ui.Modal( {
			/* translators: %s: channel name */
			title: sprintf( __( 'Send a test through %s', 'forms-notifier-for-elementor' ), meta.label ),
			desc: panel && panel.dirty() ? __( 'Uses the values on the screen, also the ones you have not saved.', 'forms-notifier-for-elementor' ) : '',
			lead: ui.Tile( meta.id ),
			body: frag( grid, out ),
			footer: [
				ui.Button( { label: __( 'Close', 'forms-notifier-for-elementor' ), variant: 'ghost', onClick: () => dialog.close() } ),
				ui.Button( { label: __( 'Send test', 'forms-notifier-for-elementor' ), icon: 'send', variant: 'primary', onClick() {
					mount( out, null );
					const overlay = panel ? panel.overlay() : { values: {}, secrets: {} };
					const action = draft.text.trim() && 'test_' + meta.id;
					return ( action
						? api.post( 'channels/' + meta.id + '/action/' + action, { params: { to: draft.to, text: draft.text }, values: overlay.values, secrets: overlay.secrets } )
						: api.post( 'channels/' + meta.id + '/test', { to: draft.to, values: overlay.values, secrets: overlay.secrets } )
					).then( ( result ) => {
						mount( out, ui.Result( result ) );
						bus.emit( 'changed' );
					}, ( error ) => mount( out, ui.Result( { ok: false, message: error.message } ) ) );
				} } ),
			],
		} );
	}

	/** Side outline that follows the scroll position. */
	ui.outline = ( items ) => {
		const links = items.map( ( item ) => h( 'a', { href: '#' + item.id, dataset: { id: item.id }, onClick( event ) {
			event.preventDefault();
			event.stopPropagation();
			item.el.scrollIntoView( { behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth', block: 'start' } );
			const fold = item.el.querySelector( '.fnfe-fold--card' );
			if ( fold ) {
				fold.open();
			}
			const target = item.el.querySelector( 'h2,button' );
			if ( target ) {
				target.setAttribute( 'tabindex', target.tagName === 'H2' ? '-1' : '0' );
				target.focus( { preventScroll: true } );
			}
		} }, item.title ) );
		const el = h( 'nav', { class: 'fnfe-outline', 'aria-label': __( 'On this page', 'forms-notifier-for-elementor' ) }, links );
		const spy = () => {
			let active = null;
			items.forEach( ( item, i ) => {
				links[ i ].hidden = item.el.hidden || ! item.title;
				if ( ! item.el.hidden && item.el.getBoundingClientRect().top < 180 ) {
					active = i;
				}
			} );
			links.forEach( ( link, i ) => ( i === ( active === null ? 0 : active ) ? link.setAttribute( 'aria-current', 'true' ) : link.removeAttribute( 'aria-current' ) ) );
		};
		window.addEventListener( 'scroll', spy, { passive: true } );
		spy();
		el.spy = spy;
		el.destroy = () => window.removeEventListener( 'scroll', spy );
		return el;
	};

	/** Gateways that are in use or have an account, as cards that open their fields. */
	function gateways( id, panel, list ) {
		const prefix = 'channels.' + id + '.';
		const used = [];
		const add = ( value ) => fields.list( Array.isArray( value ) ? value : [ value ] ).forEach( ( item ) => item && used.indexOf( item ) === -1 && used.push( item ) );
		[ 'default_provider', 'default_fallbacks', 'provider', 'fallback_provider' ].forEach( ( key ) => add( panel.get( prefix + key ) ) );
		fields.list( panel.get( prefix + 'routes' ) ).forEach( ( row ) => {
			add( row.provider );
			add( row.fallbacks );
		} );
		const shown = list.filter( ( item ) => used.indexOf( item.id ) > -1 || item.configured ).sort( ( a, b ) => ( used.indexOf( b.id ) > -1 ) - ( used.indexOf( a.id ) > -1 ) );
		if ( ! shown.length ) {
			return null;
		}
		return h( 'div', { class: 'fnfe-gateways' }, shown.map( ( item ) => h(
			'button',
			{ type: 'button', class: 'fnfe-gateway', 'aria-pressed': panel.get( prefix + 'edit_provider' ) === item.id, onClick: () => panel.set( prefix + 'edit_provider', item.id ) },
			h( 'span', { class: 'fnfe-gateway__name' }, item.label ),
			h( 'span', { class: 'fnfe-gateway__line' }, used.indexOf( item.id ) === 0 ? ui.Badge( __( 'Default', 'forms-notifier-for-elementor' ), 'info' ) : used.indexOf( item.id ) > 0 ? ui.Badge( __( 'In use', 'forms-notifier-for-elementor' ), 'mute' ) : null, item.configured ? ui.Badge( __( 'Account set', 'forms-notifier-for-elementor' ), 'ok', 'check' ) : ui.Badge( __( 'Account missing', 'forms-notifier-for-elementor' ), 'warn', 'alert' ), item.supports && item.supports.pattern ? ui.Badge( __( 'Patterns', 'forms-notifier-for-elementor' ), 'mute' ) : null )
		) ) );
	}

	/** Request body an endpoint receives, for the webhook screen. */
	function payloadCard() {
		const sample = { event: 'submission.created', delivery: '8f14e45fceea167a5a36dedd4bea2543', sent_at: '2026-01-01T10:00:00Z', site: { name: 'Example', url: 'https://example.com' }, form: { id: 12, key: '45:a1b2c3d', name: 'Contact', source: 'pro' }, submission: { id: 981, status: 'new', lang: 'en', page_url: 'https://example.com/contact/' }, fields: { name: 'Sara', email: 'sara@example.com' }, fields_meta: [ { id: 'name', label: 'Name', type: 'text', value: 'Sara' } ], files: [], text: '…' };
		return h( 'section', { class: 'fnfe-card' }, ui.Fold( { class: 'fnfe-fold--card', title: __( 'What an endpoint receives', 'forms-notifier-for-elementor' ), desc: __( 'Standard JSON body.', 'forms-notifier-for-elementor' ) }, ui.Code( JSON.stringify( sample, null, 2 ), 'json' ) ) );
	}

	window.fnfe.screens.register( 'channels/:id', {
		title: __( 'Channel', 'forms-notifier-for-elementor' ),
		cap: 'manage',
		render( container, params, shell ) {
			const id = params.id;
			let panel = null;
			let outline = null;
			let alive = true;
			let providers = [];
			const slot = h( 'div', { class: 'fnfe-gatewayslot' } );
			const meta = ui.channelMeta( id );
			if ( ! meta ) {
				shell.header( { title: __( 'Channel', 'forms-notifier-for-elementor' ), crumbs: [ { label: __( 'Channels', 'forms-notifier-for-elementor' ), path: 'channels' }, { label: id } ] } );
				mount( container, ui.Empty( { icon: 'channels', title: __( 'This channel does not exist', 'forms-notifier-for-elementor' ), text: __( 'The plugin that added it may be inactive.', 'forms-notifier-for-elementor' ), actions: ui.Button( { label: __( 'All channels', 'forms-notifier-for-elementor' ), href: '#/channels' } ) } ) );
				return null;
			}
			ui.channelHeader( shell, id, null );
			const body = h( 'div', null, ui.Skeleton( 'form', 6 ) );
			mount( container, frag( BOTS.indexOf( id ) > -1 ? botTabs( id, 'settings' ) : null, body ) );

			const problems = h( 'div', null );
			const drawProblems = () => {
				const now = ui.channelMeta( id );
				mount( problems, now && now.enabled && now.problems.length ? ui.Callout( 'warning', h( 'ul', { class: 'fnfe-list-plain' }, now.problems.map( ( text ) => h( 'li', null, text ) ) ), { title: __( 'Not ready to send', 'forms-notifier-for-elementor' ) } ) : null );
			};

			function load() {
				mount( body, ui.Skeleton( 'form', 6 ) );
				Promise.all( [ api.get( 'channels/' + id ), api.get( 'settings', { tab: id } ) ] ).then( ( pair ) => {
					if ( ! alive ) {
						return;
					}
					// The switch of the channel lives in the header.
					const enabledKey = 'channels.' + id + '.enabled';
					let sections = pair[ 0 ].sections.map( ( section ) => Object.assign( {}, section, { fields: section.fields.filter( ( field ) => field.key !== enabledKey ) } ) ).filter( ( section ) => section.fields.length );
					sections = wp.hooks.applyFilters( 'fnfe.channelScreen.sections', sections, id );
					panel = fields.panel( {
						shell: shell,
						channel: id,
						sections: sections,
						data: pair[ 1 ],
						onSaved: () => ui.refreshChannels(),
						onAction: ( action ) => ( action.id === 'connect' ? ui.refreshChannels() : null ),
						onDraw: place,
					} );
					mount( body, h( 'div', { class: 'fnfe-split' }, h( 'div', { class: 'fnfe-split__side' } ), h( 'div', { class: 'fnfe-split__main' }, problems, panel.el, id === 'webhook' ? payloadCard() : null ) ) );
					drawProblems();
					place();
					ui.channelHeader( shell, id, panel );
					if ( sections.some( ( section ) => section.id === id + '_providers' ) ) {
						api.get( 'providers/' + id ).then( ( data ) => {
							providers = data.items;
							place();
						}, () => {} );
					}
				}, ( error ) => alive && mount( body, ui.ErrorState( error, load ) ) );
			}

			/** Outline and gateway cards follow every redraw of the form. */
			function place() {
				const side = body.querySelector( '.fnfe-split__side' );
				if ( ! panel || ! side ) {
					return;
				}
				if ( outline ) {
					outline.destroy();
				}
				outline = ui.outline( panel.sections );
				mount( side, outline );
				const section = panel.el.querySelector( '#fnfe-s-' + id + '_providers .fnfe-grid' );
				if ( section && providers.length ) {
					mount( slot, gateways( id, panel, providers ) );
					section.parentNode.insertBefore( slot, section );
				}
			}

			const offs = [
				bus.on( 'channels', () => {
					if ( panel && ui.channelMeta( id ) ) {
						ui.channelHeader( shell, id, panel );
						drawProblems();
					}
				} ),
				bus.on( 'draft', ( info ) => {
					if ( outline ) {
						outline.spy();
					}
					if ( panel && providers.length && info && /provider|fallbacks|routes/.test( info.key || '' ) ) {
						mount( slot, gateways( id, panel, providers ) );
					}
				} ),
			];
			load();
			return () => {
				alive = false;
				offs.forEach( ( off ) => off() );
				if ( outline ) {
					outline.destroy();
				}
			};
		},
	} );

	ui.botTabs = botTabs;
}() );
