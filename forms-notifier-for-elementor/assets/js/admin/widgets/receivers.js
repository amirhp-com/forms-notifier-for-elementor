/**
 * Receivers picker.
 */
( function () {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const { h, mount, frag, icon, boot, ui, uid, fmt, fields } = window.fnfe;

	const PLACEHOLDER = 'secret:';

	const kindText = ( kind ) => ( {
		chat: [ __( 'Chat id or username', 'forms-notifier-for-elementor' ), '@channel, -1001234567890, -1001234567890/45', __( 'Add "/topic" for a topic of a group.', 'forms-notifier-for-elementor' ) ],
		phone: [ __( 'Phone number', 'forms-notifier-for-elementor' ), '+989120000000', __( 'With the country code, or in the local form of your default country.', 'forms-notifier-for-elementor' ) ],
		email: [ __( 'Email address', 'forms-notifier-for-elementor' ), 'name@example.com', '' ],
		endpoint: [ __( 'Endpoint', 'forms-notifier-for-elementor' ), '', '' ],
		secret: [ __( 'Address or key', 'forms-notifier-for-elementor' ), 'https://…', __( 'This address works like a password. It is stored encrypted and not shown again.', 'forms-notifier-for-elementor' ) ],
	}[ kind ] || [ __( 'Address', 'forms-notifier-for-elementor' ), '', '' ] );

	function check( kind, text ) {
		const digits = text.replace( /[۰-۹]/g, ( c ) => c.charCodeAt( 0 ) - 1776 ).replace( /[٠-٩]/g, ( c ) => c.charCodeAt( 0 ) - 1632 );
		if ( ! text.trim() ) {
			return __( 'Enter an address.', 'forms-notifier-for-elementor' );
		}
		if ( kind === 'email' && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( text.trim() ) ) {
			return __( 'Enter a valid email address.', 'forms-notifier-for-elementor' );
		}
		if ( kind === 'phone' && ! /^\+?[\d\s().-]{6,20}$/.test( digits.trim() ) ) {
			return __( 'Enter a valid phone number.', 'forms-notifier-for-elementor' );
		}
		return '';
	}

	const isField = ( address ) => /^(\{field:[^{}|]+\}|\[field id="[^"]+"\])$/.test( address );

	function glyphOf( receiver, kind ) {
		const type = receiver.meta && receiver.meta.type;
		if ( isField( receiver.address ) ) {
			return 'tag';
		}
		return { private: 'user', group: 'group', supergroup: 'users', channel: 'megaphone', topic: 'topic', forum: 'topic' }[ type ] || { chat: 'chat', phone: 'phone', email: 'mail', endpoint: 'link', secret: 'lock' }[ kind ] || 'send';
	}

	fields.register( 'receivers', ( field, value, onChange, ctx ) => {
		const channel = field.channel || ctx.channel;
		const meta = ui.channelMeta( channel ) || { capabilities: {} };
		const kind = meta.capabilities.receiver_kind || 'text';
		const discover = !! meta.capabilities.discover || kind === 'endpoint';
		const languages = ( boot.i18n && boot.i18n.languages ) || [];
		let rows = fields.list( fields.clone( value ) );
		const open = new Set();
		const list = h( 'ul', { class: 'fnfe-receivers' } );
		const commit = () => onChange( fields.clone( rows ) );

		const shownAddress = ( row ) => {
			if ( row.address.indexOf( PLACEHOLDER ) === 0 ) {
				const state = ctx.secrets && ctx.secrets[ 'channels.' + channel + '.receiver_secrets.' + row.address.slice( PLACEHOLDER.length ) ];
				return ( state && state.masked ) || '••••••••';
			}
			return kind === 'secret' && ! isField( row.address ) ? '••••••' + row.address.slice( -4 ) : row.address;
		};

		function rowNode( row, i ) {
			const details = h( 'div', { class: 'fnfe-receiver__more', hidden: ! open.has( row.id ) } );
			const title = h( 'span', { class: 'fnfe-receiver__label', dir: 'auto' }, row.label || ( isField( row.address ) ? __( 'Value of a form field', 'forms-notifier-for-elementor' ) : shownAddress( row ) ) );
			const toggle = ui.IconButton( { icon: 'edit', size: 's', label: __( 'Edit receiver', 'forms-notifier-for-elementor' ), attrs: { 'aria-expanded': open.has( row.id ) }, onClick() {
				details.hidden = ! details.hidden;
				toggle.setAttribute( 'aria-expanded', details.hidden ? 'false' : 'true' );
				if ( details.hidden ) {
					open.delete( row.id );
				} else {
					open.add( row.id );
					drawDetails();
				}
			} } );
			const drawDetails = () => {
				const grid = h( 'div', { class: 'fnfe-grid' } );
				grid.appendChild( fields.render( { key: 'label', type: 'text', label: __( 'Name', 'forms-notifier-for-elementor' ), width: 'half' }, row.label, ( text ) => {
					row.label = text;
					title.textContent = text || shownAddress( row );
					commit();
				}, { disabled: ctx.disabled } ) );
				grid.appendChild( fields.render( { key: 'address', type: 'text', label: kindText( kind )[ 0 ], width: 'half', attrs: { dir: 'ltr' }, placeholder: row.address.indexOf( PLACEHOLDER ) === 0 ? __( 'Enter a new one to replace it', 'forms-notifier-for-elementor' ) : '' }, row.address.indexOf( PLACEHOLDER ) === 0 ? '' : row.address, ( text ) => {
					if ( text.trim() ) {
						row.address = text.trim();
						commit();
					}
				}, { disabled: ctx.disabled } ) );
				if ( languages.length > 1 ) {
					grid.appendChild( fields.render( { key: 'lang', type: 'select', label: __( 'Message language', 'forms-notifier-for-elementor' ), width: 'half', options: [ { value: '', label: __( 'As in the general settings', 'forms-notifier-for-elementor' ) } ], options_source: 'languages' }, row.lang || '', ( code ) => {
						row.lang = code;
						commit();
					}, { disabled: ctx.disabled } ) );
					grid.appendChild( fields.render( { key: 'langs', type: 'multiselect', label: __( 'Only for submissions in', 'forms-notifier-for-elementor' ), width: 'half', options_source: 'languages', placeholder: __( 'All languages', 'forms-notifier-for-elementor' ) }, row.langs || [], ( codes ) => {
						row.langs = codes;
						commit();
					}, { disabled: ctx.disabled } ) );
				}
				mount( details, frag( grid, h( 'div', { class: 'fnfe-rep__tools' }, ui.MoveButtons( i, rows.length, ( from, to ) => {
					rows = ui.moved( rows, from, to );
					commit();
					draw();
				} ) ) ) );
			};
			if ( open.has( row.id ) ) {
				drawDetails();
			}
			return h(
				'li',
				{ class: [ 'fnfe-receiver', row.enabled === false ? 'is-off' : '' ] },
				h(
					'div',
					{ class: 'fnfe-receiver__row' },
					h( 'span', { class: 'fnfe-receiver__icon' }, icon( glyphOf( row, kind ), 18 ) ),
					h( 'div', { class: 'fnfe-receiver__text' }, title, h( 'span', { class: 'fnfe-receiver__sub' }, row.label || isField( row.address ) ? ui.mono( shownAddress( row ) ) : null, row.lang ? ui.Badge( row.lang.toUpperCase(), 'mute', 'globe' ) : null, ( row.langs || [] ).length ? ui.Badge( row.langs.map( ( code ) => code.toUpperCase() ).join( ' ' ), 'info', 'filter' ) : null ) ),
					ui.Switch( { checked: row.enabled !== false, disabled: ctx.disabled, /* translators: %s: name of the receiver */ ariaLabel: sprintf( __( 'Use receiver %s', 'forms-notifier-for-elementor' ), row.label || shownAddress( row ) ), onChange( on ) {
						row.enabled = on;
						commit();
						draw();
					} } ),
					toggle,
					ui.IconButton( { icon: 'trash', size: 's', danger: true, disabled: ctx.disabled, /* translators: %s: name of the receiver */ label: sprintf( __( 'Remove %s', 'forms-notifier-for-elementor' ), row.label || shownAddress( row ) ), onClick() {
						const before = rows.slice();
						rows = rows.filter( ( other ) => other.id !== row.id );
						commit();
						draw();
						window.fnfe.toast( __( 'Receiver removed.', 'forms-notifier-for-elementor' ), 'info', { action: { label: __( 'Undo', 'forms-notifier-for-elementor' ), run() {
							rows = before;
							commit();
							draw();
						} } } );
					} } )
				),
				details
			);
		}

		function draw() {
			mount( list, rows.length ? rows.map( rowNode ) : h( 'li', { class: 'fnfe-receivers__none' }, icon( 'users', 20 ), h( 'span', null, ctx.formId ? __( 'No receivers for this form yet.', 'forms-notifier-for-elementor' ) : __( 'Nobody gets messages from this channel yet. Add the first receiver.', 'forms-notifier-for-elementor' ) ) ) );
		}

		function add( row ) {
			if ( rows.some( ( other ) => other.address === row.address ) ) {
				window.fnfe.toast( __( 'This receiver is already in the list.', 'forms-notifier-for-elementor' ), 'info' );
				return false;
			}
			rows.push( Object.assign( { id: uid( 'r_' ), address: '', label: '', enabled: true, lang: '', langs: [], meta: {} }, row ) );
			commit();
			draw();
			return true;
		}

		/* Add dialog. */
		function dialog() {
			const body = h( 'div', null );
			const panel = h( 'div', null );
			const tabs = [];
			let added = 0;
			if ( discover ) {
				tabs.push( { id: 'pick', label: kind === 'endpoint' ? __( 'Choose an endpoint', 'forms-notifier-for-elementor' ) : __( 'Pick a chat', 'forms-notifier-for-elementor' ), icon: 'search' } );
			}
			if ( kind !== 'endpoint' ) {
				tabs.push( { id: 'type', label: __( 'Type it', 'forms-notifier-for-elementor' ), icon: 'edit' } );
			}
			if ( ctx.formFields && [ 'phone', 'email', 'text', 'chat' ].indexOf( kind ) > -1 ) {
				tabs.push( { id: 'field', label: __( 'From a form field', 'forms-notifier-for-elementor' ), icon: 'tag' } );
			}
			const note = h( 'span', { class: 'fnfe-muted fnfe-grow', role: 'status' } );
			const mark = () => {
				added++;
				/* translators: %s: number of receivers */
				note.textContent = sprintf( _n( '%s receiver added', '%s receivers added', added, 'forms-notifier-for-elementor' ), fmt.number( added ) );
			};

			function pick() {
				const results = h( 'div', { class: 'fnfe-picklist' }, ui.Skeleton( 'rows', 4 ) );
				const load = ( search ) => fields.runAction( { channel: channel, id: 'receiver_options' }, ctx, { search: search || '', type: '', page: 1 } ).then( ( result ) => {
					const options = result.rows || [];
					if ( ! options.length ) {
						mount( results, ui.Empty( {
							icon: kind === 'endpoint' ? 'link' : 'chat',
							small: true,
							title: search ? __( 'No matches', 'forms-notifier-for-elementor' ) : ( kind === 'endpoint' ? __( 'No endpoints yet', 'forms-notifier-for-elementor' ) : __( 'No chats found yet', 'forms-notifier-for-elementor' ) ),
							text: result.message || ( kind === 'endpoint' ? __( 'Add an endpoint first and save.', 'forms-notifier-for-elementor' ) : __( 'Send /start to the bot, or add it to a group and send /id there. Then search again.', 'forms-notifier-for-elementor' ) ),
							actions: kind === 'endpoint' ? null : ui.Button( { label: __( 'Search again', 'forms-notifier-for-elementor' ), icon: 'refresh', size: 's', onClick: () => poll() } ),
						} ) );
						return;
					}
					let group = null;
					const nodes = [];
					options.forEach( ( option ) => {
						if ( option.group && option.group !== group ) {
							group = option.group;
							nodes.push( h( 'div', { class: 'fnfe-list__group' }, group ) );
						}
						const has = rows.some( ( row ) => row.address === option.address );
						const button = ui.Button( { label: has ? __( 'Added', 'forms-notifier-for-elementor' ) : __( 'Add', 'forms-notifier-for-elementor' ), size: 's', disabled: has, icon: has ? 'check' : 'plus', onClick() {
							if ( add( { address: option.address, label: option.label, meta: option.meta || {} } ) ) {
								mark();
								button.disabled = true;
								button.setLabel( __( 'Added', 'forms-notifier-for-elementor' ) );
							}
						} } );
						nodes.push( h( 'div', { class: 'fnfe-picklist__row' }, h( 'span', { class: 'fnfe-receiver__icon' }, icon( glyphOf( { address: option.address, meta: option.meta }, kind ), 18 ) ), h( 'div', { class: 'fnfe-receiver__text' }, h( 'span', { class: 'fnfe-receiver__label', dir: 'auto' }, option.label ), h( 'span', { class: 'fnfe-receiver__sub' }, ui.mono( option.address ), option.hint ? h( 'span', { dir: 'auto' }, option.hint ) : null ) ), button ) );
					} );
					mount( results, nodes );
				}, ( error ) => mount( results, ui.ErrorState( error, () => load( search ) ) ) );
				const poll = () => window.fnfe.api.post( 'bots/' + channel + '/poll' ).then( () => load( '' ), () => load( '' ) );
				load( '' );
				return frag( h( 'div', { class: 'fnfe-toolbar' }, ui.Search( { onSearch: load, label: __( 'Search', 'forms-notifier-for-elementor' ) } ), kind === 'endpoint' ? null : ui.Button( { label: __( 'Refresh', 'forms-notifier-for-elementor' ), icon: 'refresh', size: 's', onClick: poll } ) ), results );
			}

			function typed() {
				const texts = kindText( kind );
				const draft = { address: '', label: '' };
				const grid = h( 'form', { class: 'fnfe-grid', onSubmit( event ) {
					event.preventDefault();
					const problem = check( kind, draft.address );
					address.setError( problem );
					if ( ! problem && add( { address: draft.address.trim(), label: draft.label.trim() } ) ) {
						mark();
						mount( panel, typed() );
						panel.querySelector( 'input' ).focus();
					}
				} } );
				const address = fields.render( { key: 'address', type: kind === 'email' ? 'email' : 'text', label: texts[ 0 ], desc: texts[ 2 ], placeholder: texts[ 1 ], required: true, attrs: { dir: 'ltr', inputmode: kind === 'phone' ? 'tel' : undefined } }, '', ( text ) => {
					draft.address = text;
				}, {} );
				const input = address.querySelector( 'input' );
				input.addEventListener( 'blur', () => draft.address && address.setError( check( kind, draft.address ) ) );
				grid.appendChild( address );
				grid.appendChild( fields.render( { key: 'label', type: 'text', label: __( 'Name in this panel', 'forms-notifier-for-elementor' ), desc: __( 'For example "Sales group". Only you see it.', 'forms-notifier-for-elementor' ) }, '', ( text ) => {
					draft.label = text;
				}, {} ) );
				grid.appendChild( h( 'div', { class: 'fnfe-field fnfe-field--full' }, ui.Button( { label: __( 'Add receiver', 'forms-notifier-for-elementor' ), icon: 'plus', variant: 'primary', attrs: { type: 'submit' } } ) ) );
				return grid;
			}

			function fromField() {
				const wanted = { phone: [ 'tel', 'text', 'number' ], email: [ 'email', 'text' ] }[ kind ];
				const options = ctx.formFields.filter( ( item ) => ! wanted || wanted.indexOf( item.type ) > -1 );
				return options.length ? h( 'div', { class: 'fnfe-picklist' }, options.map( ( item ) => {
					const address = '{field:' + item.id + '}';
					return h( 'div', { class: 'fnfe-picklist__row' }, h( 'span', { class: 'fnfe-receiver__icon' }, icon( 'tag', 18 ) ), h( 'div', { class: 'fnfe-receiver__text' }, h( 'span', { class: 'fnfe-receiver__label', dir: 'auto' }, item.label || item.id ), h( 'span', { class: 'fnfe-receiver__sub' }, ui.mono( address ) ) ), ui.Button( { label: __( 'Add', 'forms-notifier-for-elementor' ), icon: 'plus', size: 's', onClick() {
						if ( add( { address: address, label: item.label || item.id } ) ) {
							mark();
						}
					} } ) );
				} ) ) : ui.Empty( { icon: 'tag', small: true, text: __( 'This form has no field that fits.', 'forms-notifier-for-elementor' ) } );
			}

			const show = ( tab ) => mount( panel, { pick: pick, type: typed, field: fromField }[ tab ]() );
			if ( tabs.length > 1 ) {
				body.appendChild( ui.Tabs( { tabs: tabs, active: tabs[ 0 ].id, onSelect: show, panel: panel } ) );
			}
			body.appendChild( panel );
			if ( tabs.length ) {
				show( tabs[ 0 ].id );
			}
			const modal = ui.Sheet( {
				title: __( 'Add receivers', 'forms-notifier-for-elementor' ),
				desc: ui.channelLabel( channel ),
				lead: ui.Tile( channel ),
				body: body,
				footer: [ note, ui.Button( { label: __( 'Done', 'forms-notifier-for-elementor' ), variant: 'primary', onClick: () => modal.close() } ) ],
			} );
		}

		rows.forEach( ( row ) => {
			row.id = row.id || uid( 'r_' );
			row.address = String( row.address || '' );
		} );
		draw();
		return h( 'div', null, list, ui.Button( { label: __( 'Add receivers', 'forms-notifier-for-elementor' ), icon: 'plus', size: 's', disabled: ctx.disabled, onClick: dialog } ) );
	} );

}() );
