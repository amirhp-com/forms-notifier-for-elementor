/**
 * Screen of one form.
 */
( function () {
	'use strict';

	const { __, _x, _n, sprintf } = wp.i18n;
	const { h, mount, frag, icon, boot, api, ui, fmt, fields } = window.fnfe;

	const TABS = () => [
		{ id: 'overview', label: __( 'Overview', 'forms-notifier-for-elementor' ), icon: 'forms' },
		{ id: 'channels', label: __( 'Channels', 'forms-notifier-for-elementor' ), icon: 'channels' },
		{ id: 'reply', label: __( 'Reply to sender', 'forms-notifier-for-elementor' ), icon: 'chat' },
		{ id: 'rules', label: __( 'Rules', 'forms-notifier-for-elementor' ), icon: 'branch' },
		{ id: 'fields', label: __( 'Fields and privacy', 'forms-notifier-for-elementor' ), icon: 'shield' },
	];

	const ROUTE = { enabled: 'inherit', receivers_mode: 'inherit', receivers: [], template_mode: 'inherit', template: { _: '' }, subject: { _: '' }, buttons_mode: 'inherit', buttons: [], options: {} };
	const object = ( value ) => ( value && typeof value === 'object' && ! Array.isArray( value ) ? value : {} );

	const onOff = () => [
		{ value: 'inherit', label: __( 'As the site', 'forms-notifier-for-elementor' ) },
		{ value: 'on', label: _x( 'On', 'form state', 'forms-notifier-for-elementor' ), tone: 'ok' },
		{ value: 'off', label: _x( 'Off', 'form state', 'forms-notifier-for-elementor' ) },
	];

	function render( container, params, shell ) {
		const id = params.id;
		const tab = TABS().some( ( item ) => item.id === params.tab ) ? params.tab : 'overview';
		const body = h( 'div', null, ui.Skeleton( 'form', 6 ) );
		let data = null;
		let saved = null;
		let config = null;
		let errors = {};
		let alive = true;
		const opened = new Set();

		shell.header( { title: __( 'Form', 'forms-notifier-for-elementor' ), crumbs: [ { label: __( 'Forms', 'forms-notifier-for-elementor' ), path: 'forms' }, { label: '…' } ] } );
		mount( container, body );

		const dirty = () => ! fields.same( config, saved );
		const bar = () => shell.savebar( dirty() ? { save: save, discard: discard } : null );
		const set = ( path, value ) => {
			window.fnfe.path.set( config, path, value );
			bar();
		};

		function discard() {
			config = fields.clone( saved );
			errors = {};
			draw();
			bar();
		}

		function save() {
			return api.put( 'forms/' + id, { config: config } ).then( ( answer ) => {
				errors = answer.errors || {};
				saved = fields.clone( answer.config );
				config = fields.clone( answer.config );
				ui.forget( 'forms' );
				const list = Object.values( errors );
				if ( list.length ) {
					window.fnfe.toast( list.join( ' ' ), 'danger' );
				} else {
					window.fnfe.toast( __( 'Form settings saved.', 'forms-notifier-for-elementor' ), 'success' );
				}
				return api.get( 'forms/' + id ).then( ( fresh ) => {
					data = fresh;
				}, () => {} ).then( () => {
					if ( alive ) {
						header();
						draw();
						bar();
					}
					return ! list.length;
				} );
			}, ( error ) => {
				ui.fail( error );
				return false;
			} );
		}

		const context = ( extra ) => Object.assign( { formId: Number( id ), formFields: data.fields, get: () => undefined, secrets: {} }, extra );
		const route = ( channel ) => Object.assign( {}, fields.clone( ROUTE ), object( config.channels )[ channel ] );
		const setRoute = ( channel, key, value ) => {
			config.channels = Object.assign( {}, object( config.channels ), { [ channel ]: Object.assign( route( channel ), { [ key ]: value } ) } );
			bar();
		};

		function header() {
			const form = data.form;
			shell.header( {
				title: form.name || form.form_key,
				sub: h( 'span', null, h( 'bdi', null, form.post_title ), ' ', ui.mono( form.form_key ) ),
				crumbs: [ { label: __( 'Forms', 'forms-notifier-for-elementor' ), path: 'forms' }, { label: form.name || form.form_key } ],
				help: 'forms',
				actions: [
					ui.formState( form ),
					form.edit_url ? ui.Button( { label: __( 'Edit in Elementor', 'forms-notifier-for-elementor' ), icon: 'external', href: form.edit_url, target: '_blank' } ) : null,
					ui.Button( { label: __( 'Send test submission', 'forms-notifier-for-elementor' ), icon: 'send', variant: dirty() ? 'secondary' : 'primary', onClick: test } ),
				],
			} );
		}

		function test() {
			const out = h( 'div', null );
			let channels = [];
			const dialog = ui.Modal( {
				title: __( 'Send a test submission', 'forms-notifier-for-elementor' ),
				desc: dirty() ? __( 'Uses the saved settings. Save first to test your changes.', 'forms-notifier-for-elementor' ) : __( 'Sends sample values of this form to its receivers now.', 'forms-notifier-for-elementor' ),
				body: frag( h( 'div', { class: 'fnfe-grid' }, fields.render( { key: 'channels', type: 'multiselect', label: __( 'Channels', 'forms-notifier-for-elementor' ), options: ( data.form.channels || [] ).map( ( channel ) => ( { value: channel, label: ui.channelLabel( channel ) } ) ), placeholder: __( 'All active channels', 'forms-notifier-for-elementor' ) }, [], ( next ) => {
					channels = next;
				}, {} ) ), out ),
				footer: [ ui.Button( { label: __( 'Close', 'forms-notifier-for-elementor' ), variant: 'ghost', onClick: () => dialog.close() } ), ui.Button( { label: __( 'Send test', 'forms-notifier-for-elementor' ), icon: 'send', variant: 'primary', onClick: () => api.post( 'forms/' + id + '/test', { channels: channels } ).then( ( answer ) => {
					const list = answer.messages || [];
					mount( out, list.length ? ui.Table( { rows: list.map( ( item, i ) => Object.assign( { id: i }, item ) ), columns: [
						{ label: __( 'Channel', 'forms-notifier-for-elementor' ), primary: true, render: ( row ) => ui.ChannelChip( row.channel ) },
						{ label: __( 'Receiver', 'forms-notifier-for-elementor' ), render: ( row ) => h( 'bdi', null, row.recipient ) },
						{ label: __( 'Result', 'forms-notifier-for-elementor' ), render: ( row ) => ( row.ok ? ui.Status( 'sent' ) : h( 'span', null, ui.Status( 'failed' ), ' ', row.error ) ) },
					] } ) : ui.Callout( 'warning', __( 'Nothing was sent: no channel with receivers is active for this form.', 'forms-notifier-for-elementor' ) ) );
					window.fnfe.bus.emit( 'changed' );
				}, ( error ) => mount( out, ui.Result( { ok: false, message: error.message } ) ) ) } ) ],
			} );
		}

		/* ------------------------------------------------------ Overview */

		function overview() {
			const form = data.form;
			const editor = object( data.editor );
			const effective = object( data.effective && data.effective.channels );
			const active = Object.keys( effective ).filter( ( key ) => effective[ key ].active );
			const line = ( key ) => {
				const item = effective[ key ];
				const source = { global: __( 'site-wide receivers', 'forms-notifier-for-elementor' ), form: __( 'changed for this form', 'forms-notifier-for-elementor' ), editor: __( 'overridden in the Elementor editor', 'forms-notifier-for-elementor' ) }[ item.source ] || '';
				return h( 'li', null, h( 'a', { href: '#/forms/' + id + '/channels' }, ui.Tile( key, 's' ), h( 'span', { class: 'fnfe-feed__text' }, h( 'strong', null, ui.channelLabel( key ) ), h( 'small', null, /* translators: %s: number of receivers */ sprintf( _n( '%s receiver', '%s receivers', item.receivers, 'forms-notifier-for-elementor' ), fmt.number( item.receivers ) ), ', ', source ) ), item.source === 'global' ? null : ui.Badge( item.source === 'editor' ? 'Elementor' : __( 'Own settings', 'forms-notifier-for-elementor' ), 'info' ) ) );
			};
			const linked = Number( config.link ) || 0;
			const linkSlot = h( 'div', null );
			if ( linked ) {
				const show = ( name ) => mount( linkSlot, ui.Callout( 'info', __( 'Channels, receivers, texts and rules come from that form. Texts can differ per language there.', 'forms-notifier-for-elementor' ), { /* translators: %s: form name */ title: sprintf( __( 'Uses the settings of "%s"', 'forms-notifier-for-elementor' ), name ), actions: [ ui.Button( { label: __( 'Open that form', 'forms-notifier-for-elementor' ), size: 's', href: '#/forms/' + linked } ), ui.Button( { label: __( 'Unlink and copy', 'forms-notifier-for-elementor' ), size: 's', variant: 'ghost', onClick: () => api.get( 'forms/' + linked ).then( ( other ) => {
					config = Object.assign( fields.clone( other.config ), { link: 0 } );
					bar();
					draw();
				}, ui.fail ) } ) ] } ) );
				show( '#' + linked );
				fields.forms().then( ( list ) => show( ( list.find( ( item ) => item.value === String( linked ) ) || { label: '#' + linked } ).label ) );
			}
			const seg = ui.Segmented( { options: onOff(), value: config.enabled, label: __( 'Notifications of this form', 'forms-notifier-for-elementor' ), onChange: ( value ) => set( 'enabled', value ) } );
			return frag(
				linkSlot,
				editor.fnfe_enabled === 'no' ? ui.Callout( 'warning', __( 'Notifications are switched off in the form action of the Elementor editor. That wins over this screen.', 'forms-notifier-for-elementor' ) ) : null,
				h( 'div', { class: 'fnfe-cols' },
					h( 'div', null,
						ui.Card( { title: __( 'Notifications of this form', 'forms-notifier-for-elementor' ), desc: form.active ? __( 'Right now this form sends notifications.', 'forms-notifier-for-elementor' ) : __( 'Right now this form sends nothing.', 'forms-notifier-for-elementor' ) }, seg ),
						ui.Card( { title: __( 'Who hears about it', 'forms-notifier-for-elementor' ), flush: true, actions: h( 'a', { class: 'fnfe-more', href: '#/forms/' + id + '/channels' }, __( 'Change', 'forms-notifier-for-elementor' ), icon( 'chevron-end', 16 ) ) }, active.length ? h( 'ul', { class: 'fnfe-feed' }, active.map( line ) ) : ui.Empty( { icon: 'channels', small: true, text: __( 'No channel is active for this form.', 'forms-notifier-for-elementor' ) } ) ),
						ui.Card( { title: __( 'Same settings as another form', 'forms-notifier-for-elementor' ), desc: __( 'For translations of one page: link them and configure only one.', 'forms-notifier-for-elementor' ) }, linkPicker() )
					),
					ui.Card( { title: __( 'Fields', 'forms-notifier-for-elementor' ), desc: __( 'Copy a tag into a message text.', 'forms-notifier-for-elementor' ), flush: true }, data.fields.length ? h( 'ul', { class: 'fnfe-fieldlist' }, data.fields.map( ( field ) => h( 'li', null, h( 'span', { class: 'fnfe-feed__text' }, h( 'strong', { dir: 'auto' }, field.label || field.id ), h( 'small', null, field.type ) ), ui.mono( '{field:' + field.id + '}' ), ui.CopyButton( '{field:' + field.id + '}', __( 'Copy tag', 'forms-notifier-for-elementor' ) ) ) ) ) : ui.Empty( { icon: 'list', small: true, text: __( 'No fields are known yet. Scan for forms again.', 'forms-notifier-for-elementor' ) } ) )
				)
			);
		}

		function linkPicker() {
			const slot = h( 'div', null );
			fields.forms().then( ( list ) => mount( slot, ui.Combobox( { options: [ { value: '0', label: __( 'Own settings', 'forms-notifier-for-elementor' ) } ].concat( list.filter( ( item ) => item.value !== String( id ) ) ), value: String( config.link || 0 ), label: __( 'Form to follow', 'forms-notifier-for-elementor' ), onChange( value ) {
				set( 'link', Number( value ) || 0 );
				draw();
			} } ) ) );
			return slot;
		}

		/* ------------------------------------------------------ Channels */

		function channelFold( meta ) {
			const now = route( meta.id );
			const own = [ 'enabled', 'receivers_mode', 'template_mode', 'buttons_mode' ].some( ( key ) => now[ key ] !== 'inherit' ) || Object.keys( object( now.options ) ).length > 0;
			const effective = object( data.effective && data.effective.channels )[ meta.id ];
			const fold = ui.Fold( {
				class: 'fnfe-chanfold',
				lead: ui.Tile( meta.id ),
				title: meta.label,
				desc: effective ? ( effective.active ? /* translators: %s: number of receivers */ sprintf( _n( '%s receiver', '%s receivers', effective.receivers, 'forms-notifier-for-elementor' ), fmt.number( effective.receivers ) ) : __( 'Sends nothing for this form', 'forms-notifier-for-elementor' ) ) : '',
				badge: h( 'span', { class: 'fnfe-badges' }, own ? ui.Badge( __( 'Own settings', 'forms-notifier-for-elementor' ), 'info' ) : null, meta.enabled ? null : ui.Badge( __( 'Off on this site', 'forms-notifier-for-elementor' ) ) ),
				open: opened.has( meta.id ),
				onToggle( on, el ) {
					if ( on ) {
						opened.add( meta.id );
						if ( ! el.firstChild ) {
							mount( el, channelBody( meta ) );
						}
					} else {
						opened.delete( meta.id );
					}
				},
			} );
			if ( opened.has( meta.id ) ) {
				mount( fold.body, channelBody( meta ) );
			}
			return fold;
		}

		function channelBody( meta ) {
			const el = h( 'div', null );
			const caps = meta.capabilities;
			const seg = ( key, label, options ) => h( 'div', { class: 'fnfe-field fnfe-field--full' }, h( 'div', { class: 'fnfe-field__label' }, label ), ui.Segmented( { options: options, value: route( meta.id )[ key ], label: label, onChange( value ) {
				setRoute( meta.id, key, value );
				build();
			} } ) );
			const build = () => {
				const now = route( meta.id );
				const ctx = context( { channel: meta.id } );
				const grid = h( 'div', { class: 'fnfe-grid' } );
				const add = ( node ) => node && grid.appendChild( node );
				const key = 'channels.' + meta.id;
				add( seg( 'enabled', __( 'Use this channel for this form', 'forms-notifier-for-elementor' ), onOff() ) );
				if ( now.enabled !== 'off' ) {
					if ( caps.receivers === 'list' ) {
						add( seg( 'receivers_mode', __( 'Receivers', 'forms-notifier-for-elementor' ), [ { value: 'inherit', label: __( 'Site-wide', 'forms-notifier-for-elementor' ) }, { value: 'append', label: __( 'Site-wide and these', 'forms-notifier-for-elementor' ) }, { value: 'replace', label: __( 'Only these', 'forms-notifier-for-elementor' ) } ] ) );
						if ( now.receivers_mode !== 'inherit' ) {
							const node = fields.render( { key: key + '.receivers', type: 'receivers', channel: meta.id, label: '' }, now.receivers, ( value ) => setRoute( meta.id, 'receivers', value ), ctx );
							node.setError( errors[ key + '.receivers' ] || '' );
							add( node );
						}
					}
					add( seg( 'template_mode', __( 'Message', 'forms-notifier-for-elementor' ), [ { value: 'inherit', label: __( 'As the site', 'forms-notifier-for-elementor' ) }, { value: 'custom', label: __( 'Own text', 'forms-notifier-for-elementor' ) } ] ) );
					if ( now.template_mode === 'custom' ) {
						if ( caps.subject ) {
							add( fields.render( { key: key + '.subject', type: 'text', localized: true, label: __( 'Subject', 'forms-notifier-for-elementor' ) }, now.subject, ( value ) => setRoute( meta.id, 'subject', value ), ctx ) );
						}
						add( fields.render( { key: key + '.template', type: 'template', localized: true, channel: meta.id, purpose: 'admin', label: '' }, now.template, ( value ) => setRoute( meta.id, 'template', value ), Object.assign( {}, ctx, { get: () => route( meta.id ).subject } ) ) );
					}
					if ( ( caps.buttons || [] ).length ) {
						add( seg( 'buttons_mode', __( 'Buttons', 'forms-notifier-for-elementor' ), [ { value: 'inherit', label: __( 'Site-wide', 'forms-notifier-for-elementor' ) }, { value: 'append', label: __( 'Site-wide and these', 'forms-notifier-for-elementor' ) }, { value: 'replace', label: __( 'Only these', 'forms-notifier-for-elementor' ) }, { value: 'none', label: __( 'None', 'forms-notifier-for-elementor' ) } ] ) );
						if ( now.buttons_mode === 'append' || now.buttons_mode === 'replace' ) {
							add( fields.render( { key: key + '.buttons', type: 'buttons', channel: meta.id, label: '' }, now.buttons, ( value ) => setRoute( meta.id, 'buttons', value ), ctx ) );
						}
					}
				}
				const options = h( 'div', { class: 'fnfe-gap' } );
				mount( el, frag(
					meta.enabled ? null : ui.Callout( 'info', __( 'This channel is off on this site. Switching it on here makes it send for this form only.', 'forms-notifier-for-elementor' ), { actions: shell.can( 'manage' ) ? ui.Button( { label: __( 'Open the channel', 'forms-notifier-for-elementor' ), size: 's', href: '#/channels/' + meta.id } ) : null } ),
					grid,
					options
				) );
				if ( now.enabled !== 'off' && shell.can( 'manage' ) ) {
					formFields( meta.id ).then( ( list ) => {
						if ( list.length ) {
							const values = list.reduce( ( out, field ) => Object.assign( out, { [ field.key ]: window.fnfe.path.get( object( route( meta.id ).options ), field.key, undefined ) } ), {} );
							mount( options, fields.form( [ { id: 'opt_' + meta.id, title: '', fields: list } ], values, ( optionKey, value ) => setRoute( meta.id, 'options', window.fnfe.path.set( fields.clone( object( route( meta.id ).options ) ), optionKey, value ) ), Object.assign( {}, context( { channel: meta.id } ), { bare: true, get: ( path ) => ( path in values ? values[ path ] : undefined ) } ) ).el );
						}
					} );
				}
			};
			build();
			return el;
		}

		const optionCache = {};
		function formFields( channel ) {
			optionCache[ channel ] = optionCache[ channel ] || api.get( 'channels/' + channel ).then( ( answer ) => answer.form_fields || [], () => [] );
			return optionCache[ channel ];
		}

		function channelsTab() {
			const list = ( boot.channels || [] ).slice().sort( ( a, b ) => ( b.enabled - a.enabled ) );
			const on = list.filter( ( item ) => item.enabled || route( item.id ).enabled === 'on' );
			const off = list.filter( ( item ) => on.indexOf( item ) === -1 );
			return frag(
				config.link ? ui.Callout( 'info', __( 'This form follows another form. Settings here have no effect until you unlink it.', 'forms-notifier-for-elementor' ) ) : null,
				on.length ? h( 'div', { class: 'fnfe-stack' }, on.map( channelFold ) ) : ui.Empty( { icon: 'channels', small: true, text: __( 'No channel is on yet.', 'forms-notifier-for-elementor' ), actions: shell.can( 'manage' ) ? ui.Button( { label: __( 'Choose a channel', 'forms-notifier-for-elementor' ), variant: 'primary', href: '#/channels' } ) : null } ),
				off.length ? h( 'section', { class: 'fnfe-card fnfe-card--quiet' }, ui.Fold( { class: 'fnfe-fold--card', title: __( 'Channels that are switched off', 'forms-notifier-for-elementor' ), open: off.some( ( item ) => opened.has( item.id ) ) }, h( 'div', { class: 'fnfe-stack' }, off.map( channelFold ) ) ) ) : null
			);
		}

		/* --------------------------------------------------------- Reply */

		const fieldOptions = ( empty, types ) => [ { value: '', label: empty } ].concat( data.fields.filter( ( item ) => ! types || types.indexOf( item.type ) > -1 ).map( ( item ) => ( { value: item.id, label: ( item.label || item.id ) } ) ) );

		function replyTab() {
			const reply = config.submitter;
			const channels = ( boot.channels || [] ).filter( ( item ) => item.capabilities.submitter );
			const auto = ( kind ) => data.fields.find( ( item ) => item.type === kind );
			const card = ( meta ) => {
				const now = Object.assign( { enabled: false, field: '', template: { _: '' }, subject: { _: '' } }, object( reply.channels )[ meta.id ] );
				const write = ( key, value ) => {
					now[ key ] = value;
					config.submitter.channels = Object.assign( {}, object( config.submitter.channels ), { [ meta.id ]: fields.clone( now ) } );
					bar();
				};
				const kind = meta.capabilities.receiver_kind === 'email' ? 'email' : 'tel';
				const guess = auto( kind );
				const inner = h( 'div', { class: 'fnfe-grid', hidden: ! now.enabled },
					fields.render( { key: 'field', type: 'select', label: __( 'Address field', 'forms-notifier-for-elementor' ), width: 'half', options: fieldOptions( guess ? /* translators: %s: field label */ sprintf( __( 'Automatic: %s', 'forms-notifier-for-elementor' ), guess.label || guess.id ) : __( 'Automatic', 'forms-notifier-for-elementor' ) ) }, now.field, ( value ) => write( 'field', value ), {} ),
					meta.capabilities.subject ? fields.render( { key: 'subject', type: 'text', localized: true, label: __( 'Subject', 'forms-notifier-for-elementor' ) }, now.subject, ( value ) => write( 'subject', value ), context( {} ) ) : null,
					fields.render( { key: 'template', type: 'template', localized: true, channel: meta.id, purpose: 'submitter', label: __( 'Text', 'forms-notifier-for-elementor' ) }, now.template, ( value ) => write( 'template', value ), context( { channel: meta.id, get: () => now.subject } ) ) );
				return ui.Card( { title: h( 'span', { class: 'fnfe-titleline' }, ui.Tile( meta.id, 's' ), meta.label ), actions: ui.Switch( { checked: now.enabled, /* translators: %s: channel name */ ariaLabel: sprintf( __( 'Reply by %s', 'forms-notifier-for-elementor' ), meta.label ), onChange( on ) {
					write( 'enabled', on );
					inner.hidden = ! on;
				} } ) }, meta.enabled ? null : ui.Callout( 'warning', __( 'This channel is off on this site, so no reply is sent.', 'forms-notifier-for-elementor' ) ), now.enabled && ! now.field && ! guess ? ui.Callout( 'info', __( 'This form has no matching field. Choose one.', 'forms-notifier-for-elementor' ) ) : null, inner );
			};
			const rest = h( 'div', { hidden: ! reply.enabled },
				ui.Card( {}, h( 'div', { class: 'fnfe-grid' }, fields.render( { key: 'consent_field', type: 'select', label: __( 'Only when this field is filled', 'forms-notifier-for-elementor' ), desc: __( 'For example a consent checkbox.', 'forms-notifier-for-elementor' ), width: 'half', options: fieldOptions( __( 'Always', 'forms-notifier-for-elementor' ) ) }, reply.consent_field, ( value ) => set( 'submitter.consent_field', value ), {} ) ) ),
				channels.length ? channels.map( card ) : ui.Empty( { icon: 'chat', small: true, text: __( 'No channel on this site can write to the person who sent the form.', 'forms-notifier-for-elementor' ) } )
			);
			return frag(
				ui.Card( {}, h( 'div', { class: 'fnfe-grid' }, fields.render( { key: 'submitter', type: 'toggle', label: __( 'Reply to the person who sent the form', 'forms-notifier-for-elementor' ), desc: __( 'A confirmation by SMS, WhatsApp or email to the address they entered.', 'forms-notifier-for-elementor' ) }, reply.enabled, ( on ) => {
					set( 'submitter.enabled', on );
					rest.hidden = ! on;
				}, {} ) ) ),
				rest
			);
		}

		/* --------------------------------------------------------- Rules */

		function rulesTab() {
			return ui.Card( { title: __( 'Routing rules', 'forms-notifier-for-elementor' ), desc: __( 'Checked from top to bottom for every submission.', 'forms-notifier-for-elementor' ) }, fields.render( { key: 'rules', type: 'rules', label: '' }, config.rules, ( value ) => set( 'rules', value ), context( {} ) ) );
		}

		/* --------------------------------------------- Fields and privacy */

		function fieldsTab() {
			const map = config.fields;
			const all = data.fields.map( ( item ) => ( { value: item.id, label: item.label || item.id } ) );
			const pick = ( key, label, empty ) => fields.render( { key: key, type: 'select', label: label, width: 'half', options: fieldOptions( empty ) }, map[ key ], ( value ) => set( 'fields.' + key, value ), {} );
			const many = ( key, label, desc ) => fields.render( { key: key, type: 'multiselect', label: label, desc: desc, options: all }, map[ key ], ( value ) => set( 'fields.' + key, value ), {} );
			const automatic = __( 'Automatic', 'forms-notifier-for-elementor' );
			const policy = object( config.attachments );
			const modes = [ { value: 'inherit', label: __( 'As the site', 'forms-notifier-for-elementor' ) }, { value: 'off', label: __( 'Do not include files', 'forms-notifier-for-elementor' ) }, { value: 'link', label: __( 'Send download links', 'forms-notifier-for-elementor' ) }, { value: 'send', label: __( 'Send the files', 'forms-notifier-for-elementor' ) }, { value: 'both', label: __( 'Send the files and the links', 'forms-notifier-for-elementor' ) } ];
			const more = h( 'div', { class: 'fnfe-grid' } );
			const drawPolicy = () => {
				const now = object( config.attachments );
				const own = now.mode && now.mode !== 'inherit' && now.mode !== 'off';
				mount( more, own ? [
					fields.render( { key: 'types', type: 'select', label: __( 'File types', 'forms-notifier-for-elementor' ), width: 'half', options: [ { value: 'all', label: __( 'All safe types', 'forms-notifier-for-elementor' ) }, { value: 'images', label: __( 'Images only', 'forms-notifier-for-elementor' ) }, { value: 'custom', label: __( 'Selected extensions', 'forms-notifier-for-elementor' ) } ] }, now.types || 'all', ( value ) => {
						set( 'attachments.types', value );
						drawPolicy();
					}, {} ),
					now.types === 'custom' ? fields.render( { key: 'extensions', type: 'tags', label: __( 'Allowed extensions', 'forms-notifier-for-elementor' ), width: 'half' }, now.extensions || [], ( value ) => set( 'attachments.extensions', value ), {} ) : null,
					fields.render( { key: 'max_mb', type: 'number', label: __( 'Largest file to send (MB)', 'forms-notifier-for-elementor' ), width: 'half', attrs: { min: 1, max: 2048 } }, now.max_mb === undefined ? 20 : now.max_mb, ( value ) => set( 'attachments.max_mb', value ), {} ),
					fields.render( { key: 'max_files', type: 'number', label: __( 'Most files per message', 'forms-notifier-for-elementor' ), width: 'half', attrs: { min: 1, max: 100 } }, now.max_files === undefined ? 10 : now.max_files, ( value ) => set( 'attachments.max_files', value ), {} ),
				] : null );
			};
			drawPolicy();
			const labels = object( map.labels );
			return frag(
				ui.Card( { title: __( 'What the fields mean', 'forms-notifier-for-elementor' ), desc: __( 'Used for replies and for the tags {name}, {email} and {phone}.', 'forms-notifier-for-elementor' ) }, h( 'div', { class: 'fnfe-grid' }, pick( 'name', __( 'Name', 'forms-notifier-for-elementor' ), automatic ), pick( 'email', __( 'Email', 'forms-notifier-for-elementor' ), automatic ), pick( 'phone', __( 'Phone', 'forms-notifier-for-elementor' ), automatic ), pick( 'country', __( 'Country of the phone number', 'forms-notifier-for-elementor' ), __( 'None', 'forms-notifier-for-elementor' ) ) ) ),
				ui.Card( { title: __( 'Privacy', 'forms-notifier-for-elementor' ) }, h( 'div', { class: 'fnfe-grid' }, many( 'sensitive', __( 'Sensitive fields', 'forms-notifier-for-elementor' ), __( 'Never saved and never sent.', 'forms-notifier-for-elementor' ) ), many( 'exclude', __( 'Left out of "all fields"', 'forms-notifier-for-elementor' ), __( 'Saved, but only sent when a text names them.', 'forms-notifier-for-elementor' ) ), fields.render( { key: 'quiet_hours', type: 'toggle', label: __( 'Ignore quiet hours', 'forms-notifier-for-elementor' ), desc: __( 'Messages of this form are sent at any time.', 'forms-notifier-for-elementor' ) }, config.quiet_hours === 'off', ( on ) => set( 'quiet_hours', on ? 'off' : 'inherit' ), {} ) ) ),
				ui.Card( { title: __( 'Uploaded files', 'forms-notifier-for-elementor' ) }, h( 'div', { class: 'fnfe-grid' }, fields.render( { key: 'mode', type: 'select', label: __( 'What to do with uploaded files', 'forms-notifier-for-elementor' ), width: 'half', options: modes }, policy.mode || 'inherit', ( value ) => {
					config.attachments = value === 'inherit' ? { mode: 'inherit' } : Object.assign( {}, object( config.attachments ), { mode: value } );
					bar();
					drawPolicy();
				}, {} ) ), more ),
				all.length ? ui.Card( { title: __( 'Names of the fields in messages', 'forms-notifier-for-elementor' ), desc: __( 'Empty: the label of the form is used.', 'forms-notifier-for-elementor' ) }, h( 'div', { class: 'fnfe-grid' }, data.fields.map( ( item ) => fields.render( { key: 'label_' + item.id, type: 'text', localized: true, label: item.label || item.id, width: 'half', placeholder: item.label || item.id }, labels[ item.id ], ( value ) => {
					const next = Object.assign( {}, object( config.fields.labels ) );
					if ( Object.values( value ).some( Boolean ) ) {
						next[ item.id ] = value;
					} else {
						delete next[ item.id ];
					}
					set( 'fields.labels', next );
				}, {} ) ) ) ) : null
			);
		}

		/* ---------------------------------------------------------- Page */

		const VIEWS = { overview: overview, channels: channelsTab, reply: replyTab, rules: rulesTab, fields: fieldsTab };
		const panel = h( 'div', null );
		let active = tab;

		function draw() {
			mount( panel, VIEWS[ active ]() );
		}

		function load() {
			api.get( 'forms/' + id ).then( ( answer ) => {
				if ( ! alive ) {
					return;
				}
				data = answer;
				saved = fields.clone( answer.config );
				config = fields.clone( answer.config );
				header();
				const strip = ui.Tabs( { class: 'fnfe-tabs--page', label: __( 'Sections', 'forms-notifier-for-elementor' ), tabs: TABS().map( ( item ) => Object.assign( item, item.id === 'rules' ? { count: config.rules.length } : {} ) ), active: active, panel: panel, onSelect( next ) {
					// Switching tabs keeps unsaved changes.
					active = next;
					window.history.replaceState( null, '', '#/forms/' + id + ( next === 'overview' ? '' : '/' + next ) );
					draw();
				} } );
				mount( body, frag( strip, panel ) );
				draw();
			}, ( error ) => {
				if ( alive ) {
					mount( body, error.status === 404 ? ui.Empty( { icon: 'forms', title: __( 'This form does not exist', 'forms-notifier-for-elementor' ), actions: ui.Button( { label: __( 'All forms', 'forms-notifier-for-elementor' ), href: '#/forms' } ) } ) : ui.ErrorState( error, load ) );
				}
			} );
		}
		load();

		return () => {
			alive = false;
		};
	}

	const def = { title: __( 'Form', 'forms-notifier-for-elementor' ), cap: 'forms', render: render };
	window.fnfe.screens.register( 'forms/:id', def );
	window.fnfe.screens.register( 'forms/:id/:tab', def );
}() );
