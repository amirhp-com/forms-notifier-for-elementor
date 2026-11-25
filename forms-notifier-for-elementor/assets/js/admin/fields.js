/**
 * Field renderers, forms built from a schema, and the settings panel that saves them.
 */
( function () {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const { h, mount, frag, icon, boot, api, ui, uid, fmt, fields, safeHtml } = window.fnfe;

	const renderers = {};
	const register = fields.register;
	fields.register = ( type, renderer ) => {
		renderers[ type ] = renderer;
		register( type, renderer );
	};

	const clone = ( value ) => ( value === undefined ? undefined : JSON.parse( JSON.stringify( value ) ) );
	const same = ( a, b ) => JSON.stringify( a ) === JSON.stringify( b );
	const localized = ( value ) => ( value && typeof value === 'object' && ! Array.isArray( value ) ? value : { _: value ? String( value ) : '' } );
	const listOf = ( value ) => ( Array.isArray( value ) ? value : ( value && typeof value === 'object' ? Object.values( value ) : [] ) );

	/* ------------------------------------------------------------ Options */

	let formOptions = null;

	/** Forms as options; loaded once per page view. */
	function loadForms() {
		if ( ! formOptions ) {
			formOptions = api.get( 'forms', { per_page: 100 } ).then( ( data ) => data.items.map( ( form ) => ( { value: String( form.id ), label: form.name } ) ), () => [] );
		}
		return formOptions;
	}

	function sourceOptions( source ) {
		const options = boot.options || {};
		switch ( source ) {
			case 'channels':
				return ( boot.channels || [] ).map( ( item ) => ( { value: item.id, label: item.label } ) );
			case 'ready_channels':
				return ( boot.channels || [] ).filter( ( item ) => item.ready ).map( ( item ) => ( { value: item.id, label: item.label } ) );
			case 'languages':
				return ( ( boot.i18n && boot.i18n.languages ) || [] ).map( ( lang ) => ( { value: lang.code, label: lang.name } ) );
			case 'countries':
			case 'roles':
			case 'sms_providers':
			case 'whatsapp_providers':
				return options[ source ] || [];
		}
		return [];
	}

	function optionsOf( field, ctx ) {
		const fixed = listOf( field.options ).map( ( option ) => ( typeof option === 'object' ? option : { value: String( option ), label: String( option ) } ) );
		let dynamic = field.options_source ? sourceOptions( field.options_source ) : [];
		if ( field.options_source === 'channels' && /\.fallback$/.test( field.key ) && ctx && ctx.channel ) {
			dynamic = dynamic.filter( ( option ) => option.value !== ctx.channel );
		}
		return fixed.concat( dynamic.filter( ( option ) => ! fixed.some( ( item ) => item.value === option.value ) ) );
	}

	/* --------------------------------------------------------- Conditions */

	function test( condition, get ) {
		const actual = get( condition.key );
		const wanted = condition.value;
		const text = ( value ) => ( value === true ? '1' : value === false || value === null || value === undefined ? '' : String( value ) );
		const items = Array.isArray( wanted ) ? wanted.map( text ) : text( wanted ).split( ',' ).map( ( item ) => item.trim() );
		const has = Array.isArray( actual ) ? actual.some( ( item ) => items.indexOf( text( item ) ) > -1 ) : items.indexOf( text( actual ) ) > -1;
		const equal = Array.isArray( actual ) ? actual.map( text ).indexOf( text( wanted ) ) > -1 : text( actual ) === text( wanted );
		const truthy = Array.isArray( actual ) ? actual.length > 0 : !! actual && actual !== '0';
		switch ( condition.op ) {
			case 'is_not':
				return ! equal;
			case 'in':
				return has;
			case 'not_in':
				return ! has;
			case 'truthy':
				return truthy;
			case 'falsy':
				return ! truthy;
		}
		return equal;
	}

	const visible = ( field, get ) => listOf( field.show_if ).every( ( condition ) => test( condition, get ) );

	/* ------------------------------------------------------ Simple inputs */

	const LTR_TYPES = [ 'url', 'email', 'tel', 'number', 'time', 'code' ];

	function inputProps( field, ctx ) {
		const attrs = field.attrs || {};
		return {
			id: ctx.id,
			'aria-describedby': ctx.describedBy || undefined,
			'aria-required': field.required || undefined,
			placeholder: field.placeholder || undefined,
			disabled: ctx.disabled || undefined,
			dir: attrs.dir || ( LTR_TYPES.indexOf( field.type ) > -1 ? 'ltr' : 'auto' ),
			inputmode: attrs.inputmode || undefined,
			maxlength: attrs.maxlength || undefined,
			min: attrs.min,
			max: attrs.max,
			step: attrs.step,
		};
	}

	function textInput( type ) {
		return ( field, value, onChange, ctx ) => {
			const technical = LTR_TYPES.indexOf( field.type ) > -1 || ( field.attrs && field.attrs.dir === 'ltr' );
			return h( 'input', Object.assign( inputProps( field, ctx ), {
				type: type,
				class: [ 'fnfe-input', technical ? 'fnfe-input--mono' : '' ],
				value: value === null || value === undefined ? '' : value,
				autocomplete: 'off',
				onInput: ( event ) => onChange( type === 'number' ? ( event.target.value === '' ? '' : Number( event.target.value ) ) : event.target.value ),
			} ) );
		};
	}

	function autoGrow( area ) {
		const fit = () => {
			if ( ! area.offsetParent ) {
				return;
			}
			area.style.height = 'auto';
			area.style.height = Math.min( area.scrollHeight + 2, 520 ) + 'px';
		};
		area.addEventListener( 'input', fit );
		window.setTimeout( fit );
		return area;
	}

	function textarea( field, value, onChange, ctx ) {
		const code = field.type === 'code';
		const el = h( 'textarea', Object.assign( inputProps( field, ctx ), {
			class: [ 'fnfe-input', 'fnfe-textarea', code ? 'fnfe-input--mono' : '' ],
			rows: ( field.attrs && field.attrs.rows ) || ( code ? 8 : 4 ),
			spellcheck: code ? 'false' : undefined,
			value: value || '',
			onInput: ( event ) => onChange( event.target.value ),
		} ) );
		return code ? el : autoGrow( el );
	}

	/** Text with one value per language. */
	function localizedInput( base ) {
		return ( field, value, onChange, ctx ) => {
			let current = Object.assign( { _: '' }, localized( value ) );
			if ( ! ui.multilingual() ) {
				return base( Object.assign( {}, field, { localized: false } ), current._, ( text ) => onChange( Object.assign( {}, current, { _: text } ) ), ctx );
			}
			let lang = '_';
			const slot = h( 'div', null );
			const tabs = ui.LangTabs( { active: lang, has: ( code ) => !! current[ code ], onSelect( code ) {
				lang = code;
				draw();
			} } );
			const draw = () => {
				const input = base(
					Object.assign( {}, field, { localized: false, placeholder: lang === '_' ? field.placeholder : ( current._ || field.placeholder ), attrs: Object.assign( {}, field.attrs, { dir: ( field.attrs && field.attrs.dir ) || ui.langDir( lang ) } ) } ),
					current[ lang ] || '',
					( text ) => {
						current = Object.assign( {}, current, { [ lang ]: text } );
						if ( ! text && lang !== '_' ) {
							delete current[ lang ];
						}
						tabs.mark( lang, !! text && lang !== '_' );
						onChange( current );
					},
					Object.assign( {}, ctx, { lang: lang } )
				);
				if ( lang !== '_' ) {
					input.setAttribute( 'lang', lang );
				}
				mount( slot, input );
			};
			draw();
			return h( 'div', { class: 'fnfe-localized' }, h( 'div', { class: 'fnfe-localized__bar' }, tabs ), slot );
		};
	}

	fields.register( 'text', textInput( 'text' ) );
	fields.register( 'url', textInput( 'url' ) );
	fields.register( 'email', textInput( 'email' ) );
	fields.register( 'tel', textInput( 'tel' ) );
	fields.register( 'time', textInput( 'time' ) );
	fields.register( 'number', textInput( 'number' ) );
	fields.register( 'textarea', textarea );
	fields.register( 'code', textarea );

	fields.register( 'toggle', ( field, value, onChange, ctx ) => ui.Switch( { checked: !! value, onChange: ( on ) => onChange( on ), id: ctx.id, labelledBy: ctx.labelId, ariaLabel: ctx.labelId ? undefined : field.label, describedBy: ctx.describedBy, disabled: ctx.disabled } ) );

	fields.register( 'select', ( field, value, onChange, ctx ) => {
		const build = ( options ) => {
			const all = options.slice();
			const current = value === null || value === undefined ? '' : String( value );
			if ( ! all.some( ( option ) => option.value === current ) ) {
				all.unshift( { value: current, label: current || __( 'Choose…', 'forms-notifier-for-elementor' ) } );
			}
			return all.length > 14
				? ui.Combobox( { options: all, value: current, id: ctx.id, describedBy: ctx.describedBy, disabled: ctx.disabled, onChange: onChange } )
				: ui.Select( { options: all, value: current, id: ctx.id, describedBy: ctx.describedBy, disabled: ctx.disabled, onChange: onChange } );
		};
		if ( field.options_source === 'forms' ) {
			const slot = h( 'div', null, build( optionsOf( field, ctx ) ) );
			loadForms().then( ( list ) => mount( slot, build( optionsOf( field, ctx ).concat( list ) ) ) );
			return slot;
		}
		return build( optionsOf( field, ctx ) );
	} );

	fields.register( 'radio', ( field, value, onChange, ctx ) => {
		const name = ui.id( 'radio' );
		const options = optionsOf( field, ctx );
		const cards = options.some( ( option ) => option.desc );
		return h( 'div', { class: cards ? 'fnfe-choices' : 'fnfe-radios', role: 'radiogroup', 'aria-labelledby': ctx.labelId || undefined }, options.map( ( option ) => h(
			'label',
			{ class: cards ? 'fnfe-choice' : 'fnfe-radio' },
			h( 'input', { type: 'radio', class: 'fnfe-check fnfe-check--radio', name: name, value: option.value, checked: String( value ) === option.value, disabled: ctx.disabled || undefined, onChange: () => onChange( option.value ) } ),
			h( 'span', null, h( 'span', { class: 'fnfe-choice__label' }, option.label ), option.desc ? h( 'span', { class: 'fnfe-choice__desc' }, option.desc ) : null )
		) ) );
	} );

	fields.register( 'checkboxes', ( field, value, onChange, ctx ) => {
		let current = listOf( value ).slice();
		return h( 'div', { class: 'fnfe-radios', role: 'group', 'aria-labelledby': ctx.labelId || undefined }, optionsOf( field, ctx ).map( ( option ) => h(
			'label',
			{ class: 'fnfe-radio' },
			h( 'input', { type: 'checkbox', class: 'fnfe-check', checked: current.indexOf( option.value ) > -1, disabled: ctx.disabled || undefined, onChange( event ) {
				current = event.target.checked ? current.concat( option.value ) : current.filter( ( item ) => item !== option.value );
				onChange( current.slice() );
			} } ),
			h( 'span', null, option.label )
		) ) );
	} );

	fields.register( 'multiselect', ( field, value, onChange, ctx ) => {
		let options = optionsOf( field, ctx );
		const box = ui.Combobox( { multiple: true, ordered: !! ( field.attrs && field.attrs.ordered ) || /fallbacks$/.test( field.key ), options: () => options, value: listOf( value ), id: ctx.id, describedBy: ctx.describedBy, disabled: ctx.disabled, onChange: onChange, placeholder: field.placeholder || __( 'Add…', 'forms-notifier-for-elementor' ) } );
		if ( field.options_source === 'forms' ) {
			loadForms().then( ( list ) => {
				options = optionsOf( field, ctx ).concat( list );
				box.set( listOf( value ) );
			} );
		}
		return box;
	} );

	/** Chips typed by hand. */
	fields.register( 'tags', ( field, value, onChange, ctx ) => {
		let current = listOf( value ).map( String );
		const chips = h( 'span', { class: 'fnfe-combo__chips' } );
		const add = () => {
			const parts = input.value.split( /[,،;\n]/ ).map( ( part ) => part.trim() ).filter( ( part ) => part && current.indexOf( part ) === -1 );
			input.value = '';
			if ( parts.length ) {
				current = current.concat( parts );
				draw();
				onChange( current.slice() );
			}
		};
		const input = h( 'input', { type: 'text', class: 'fnfe-combo__input', id: ctx.id, 'aria-describedby': ctx.describedBy || undefined, dir: 'ltr', disabled: ctx.disabled || undefined, placeholder: field.placeholder || __( 'Type and press Enter', 'forms-notifier-for-elementor' ), onBlur: add, onKeydown( event ) {
			if ( event.key === 'Enter' || event.key === ',' ) {
				event.preventDefault();
				add();
			} else if ( event.key === 'Backspace' && ! input.value && current.length ) {
				current.pop();
				draw();
				onChange( current.slice() );
			}
		} } );
		const draw = () => mount( chips, current.map( ( item ) => h( 'span', { class: 'fnfe-chip' }, h( 'bdi', { class: 'fnfe-latin', dir: 'ltr' }, item ), h( 'button', {
			type: 'button',
			class: 'fnfe-chip__x',
			disabled: ctx.disabled || undefined,
			/* translators: %s: name of the item */
			'aria-label': sprintf( __( 'Remove %s', 'forms-notifier-for-elementor' ), item ),
			onClick() {
				current = current.filter( ( other ) => other !== item );
				draw();
				onChange( current.slice() );
			},
		}, icon( 'x', 14 ) ) ) ) );
		draw();
		return h( 'div', { class: 'fnfe-combo fnfe-combo--multi', onClick: ( event ) => event.target === event.currentTarget && input.focus() }, chips, input );
	} );

	fields.register( 'keyvalue', ( field, value, onChange, ctx ) => {
		const rows = Object.entries( value && typeof value === 'object' ? value : {} );
		const body = h( 'div', { class: 'fnfe-kv' } );
		const commit = () => onChange( rows.reduce( ( out, row ) => ( row[ 0 ].trim() ? Object.assign( out, { [ row[ 0 ].trim() ]: row[ 1 ] } ) : out ), {} ) );
		const draw = ( focus ) => {
			mount( body, rows.map( ( row, i ) => h(
				'div',
				{ class: 'fnfe-kv__row' },
				h( 'input', { type: 'text', class: 'fnfe-input fnfe-input--mono', dir: 'ltr', value: row[ 0 ], placeholder: __( 'Name', 'forms-notifier-for-elementor' ), 'aria-label': __( 'Name', 'forms-notifier-for-elementor' ), disabled: ctx.disabled || undefined, onInput( event ) {
					row[ 0 ] = event.target.value;
					commit();
				} } ),
				h( 'input', { type: 'text', class: 'fnfe-input fnfe-input--mono', dir: 'ltr', value: row[ 1 ], placeholder: __( 'Value', 'forms-notifier-for-elementor' ), 'aria-label': __( 'Value', 'forms-notifier-for-elementor' ), disabled: ctx.disabled || undefined, onInput( event ) {
					row[ 1 ] = event.target.value;
					commit();
				} } ),
				ui.IconButton( { icon: 'trash', label: __( 'Remove', 'forms-notifier-for-elementor' ), danger: true, disabled: ctx.disabled, onClick() {
					rows.splice( i, 1 );
					commit();
					draw();
				} } )
			) ) );
			if ( focus && body.lastChild ) {
				body.lastChild.querySelector( 'input' ).focus();
			}
		};
		draw();
		return h( 'div', null, body, ui.Button( { label: __( 'Add a row', 'forms-notifier-for-elementor' ), icon: 'plus', size: 's', disabled: ctx.disabled, onClick() {
			rows.push( [ '', '' ] );
			draw( true );
		} } ) );
	} );

	/* ------------------------------------------------------------- Secret */

	fields.register( 'secret', ( field, value, onChange, ctx ) => {
		const path = ctx.secretPath || field.key;
		const state = ( ctx.secrets && ctx.secrets[ path ] ) || { set: false, masked: '', constant: false };
		const slot = h( 'div', { class: 'fnfe-secret' } );
		const set = ( next ) => ctx.setSecret && ctx.setSecret( path, next );

		const locked = () => h( 'div', { class: 'fnfe-secret__shown' }, icon( 'lock', 16 ), ui.mono( state.masked || '••••••' ), h( 'span', { class: 'fnfe-muted' }, __( 'Set in wp-config.php', 'forms-notifier-for-elementor' ) ), field.constant ? ui.mono( field.constant ) : null );

		const shown = () => h(
			'div',
			{ class: 'fnfe-secret__shown' },
			icon( 'key', 16 ),
			ui.mono( state.masked || '••••••' ),
			h( 'span', { class: 'fnfe-grow' } ),
			ui.Button( { label: __( 'Change', 'forms-notifier-for-elementor' ), size: 's', disabled: ctx.disabled, onClick: () => edit( true ) } ),
			ui.Button( { label: __( 'Remove', 'forms-notifier-for-elementor' ), size: 's', variant: 'ghost', disabled: ctx.disabled, onClick: () => ui.confirm( {
				/* translators: %s: name of the secret, for example "Bot token" */
				title: sprintf( __( 'Remove %s?', 'forms-notifier-for-elementor' ), field.label ),
				text: __( 'The stored value is deleted when you save.', 'forms-notifier-for-elementor' ),
				confirm: __( 'Remove', 'forms-notifier-for-elementor' ),
				danger: true,
			} ).then( ( yes ) => {
				if ( yes ) {
					set( null );
					mount( slot, h( 'div', { class: 'fnfe-secret__shown' }, icon( 'trash', 16 ), h( 'span', null, __( 'Will be removed when you save', 'forms-notifier-for-elementor' ) ), h( 'span', { class: 'fnfe-grow' } ), ui.Button( { label: __( 'Undo', 'forms-notifier-for-elementor' ), size: 's', onClick() {
						set( undefined );
						mount( slot, shown() );
					} } ) ) );
				}
			} ) } )
		);

		const edit = ( focus, initial ) => {
			const input = h( 'input', Object.assign( inputProps( field, ctx ), { type: 'password', class: 'fnfe-input fnfe-input--mono', dir: 'ltr', autocomplete: 'new-password', spellcheck: 'false', value: initial || '', onInput: () => set( input.value === '' ? undefined : input.value ) } ) );
			const eye = ui.IconButton( { icon: 'eye', label: __( 'Show or hide', 'forms-notifier-for-elementor' ), onClick() {
				input.type = input.type === 'password' ? 'text' : 'password';
				mount( eye, icon( input.type === 'password' ? 'eye' : 'eye-off', 18 ) );
			} } );
			mount( slot, h( 'div', { class: 'fnfe-secret__edit' }, input, eye, state.set ? ui.Button( { label: __( 'Cancel', 'forms-notifier-for-elementor' ), variant: 'ghost', size: 's', onClick() {
				set( undefined );
				mount( slot, shown() );
			} } ) : null ) );
			if ( focus ) {
				input.focus();
			}
		};

		const pending = ctx.pendingSecret ? ctx.pendingSecret( path ) : undefined;
		if ( state.constant ) {
			mount( slot, locked() );
		} else if ( typeof pending === 'string' ) {
			edit( false, pending );
		} else if ( state.set ) {
			mount( slot, shown() );
		} else {
			edit( false );
		}
		return slot;
	} );

	/* ----------------------------------------------------------- Repeater */

	fields.register( 'repeater', ( field, value, onChange, ctx ) => {
		let rows = listOf( clone( value ) );
		const prefix = ( field.key.split( '.' ).pop().charAt( 0 ) || 'r' ).toLowerCase() + '_';
		const open = new Set();
		const list = h( 'div', { class: 'fnfe-rep' } );
		const commit = () => onChange( clone( rows ) );
		const titleOf = ( row, i ) => {
			const raw = field.item_label ? row[ field.item_label ] : '';
			const sub = ( field.fields || [] ).find( ( item ) => item.key === field.item_label );
			let text = raw && typeof raw === 'object' && ! Array.isArray( raw ) ? raw._ : raw;
			if ( sub && ( sub.options_source || listOf( sub.options ).length ) ) {
				const options = optionsOf( sub, ctx );
				text = listOf( Array.isArray( raw ) ? raw : [ raw ] ).map( ( item ) => ( options.find( ( option ) => option.value === item ) || { label: item } ).label ).filter( Boolean ).slice( 0, 4 ).join( __( ', ', 'forms-notifier-for-elementor' ) );
			}
			/* translators: %s: position of the item */
			return text || sprintf( __( 'Item %s', 'forms-notifier-for-elementor' ), fmt.number( i + 1 ) );
		};
		const hint = ( row ) => {
			const sub = ( field.fields || [] ).find( ( item ) => item.type === 'url' && row[ item.key ] );
			return sub ? row[ sub.key ].replace( /^(https?:\/\/[^/?#]+).*$/, '$1' ) : '';
		};
		const draw = () => {
			mount( list, rows.map( ( row, i ) => {
				const get = ( path ) => {
					const own = String( path ).split( '.' ).pop();
					return Object.prototype.hasOwnProperty.call( row, path ) ? row[ path ] : ( ( field.fields || [] ).some( ( sub ) => sub.key === own ) ? row[ own ] : ctx.get( path ) );
				};
				const grid = h( 'div', { class: 'fnfe-grid' } );
				const head = h( 'span', null, titleOf( row, i ) );
				const items = ( field.fields || [] ).map( ( sub ) => {
					const el = fields.render( sub, row[ sub.key ] === undefined ? clone( sub.default ) : row[ sub.key ], ( next ) => {
						row[ sub.key ] = next;
						head.textContent = titleOf( row, i );
						refresh();
						commit();
					}, Object.assign( {}, ctx, { get: get, secretPath: sub.type === 'secret' ? field.key + '.' + row.id + '.' + sub.key : undefined, row: row } ) );
					grid.appendChild( el );
					return { sub: sub, el: el };
				} );
				const refresh = () => items.forEach( ( item ) => {
					item.el.hidden = ! visible( item.sub, get );
				} );
				refresh();
				const enabled = Object.prototype.hasOwnProperty.call( row, 'enabled' ) || ( field.fields || [] ).some( ( sub ) => sub.key === 'enabled' );
				const fold = ui.Fold( {
					class: 'fnfe-rep__item',
					title: head,
					desc: hint( row ) ? ui.mono( hint( row ) ) : null,
					badge: enabled && row.enabled === false ? ui.Badge( __( 'Off', 'forms-notifier-for-elementor' ) ) : null,
					open: open.has( row.id ) || rows.length === 1,
					onToggle: ( on ) => ( on ? open.add( row.id ) : open.delete( row.id ) ),
				}, grid, h( 'div', { class: 'fnfe-rep__tools' }, ui.MoveButtons( i, rows.length, ( from, to ) => {
					rows = ui.moved( rows, from, to );
					commit();
					draw();
				} ), h( 'span', { class: 'fnfe-grow' } ), ui.Button( { label: __( 'Duplicate', 'forms-notifier-for-elementor' ), icon: 'copy', size: 's', variant: 'ghost', onClick() {
					const copy = Object.assign( clone( row ), { id: uid( prefix ) } );
					rows.splice( i + 1, 0, copy );
					open.add( copy.id );
					commit();
					draw();
				} } ), ui.Button( { label: __( 'Remove', 'forms-notifier-for-elementor' ), icon: 'trash', size: 's', variant: 'ghost', class: 'is-danger', onClick: () => ui.confirm( {
					/* translators: %s: name of the item */
					title: sprintf( __( 'Remove "%s"?', 'forms-notifier-for-elementor' ), titleOf( row, i ) ),
					text: __( 'It is deleted when you save.', 'forms-notifier-for-elementor' ),
					confirm: __( 'Remove', 'forms-notifier-for-elementor' ),
					danger: true,
				} ).then( ( yes ) => {
					if ( yes ) {
						rows = rows.filter( ( other ) => other.id !== row.id );
						commit();
						draw();
					}
				} ) } ) ) );
				return fold;
			} ) );
			if ( ! rows.length ) {
				mount( list, h( 'p', { class: 'fnfe-rep__none' }, field.placeholder || __( 'Nothing added yet.', 'forms-notifier-for-elementor' ) ) );
			}
		};
		rows.forEach( ( row ) => {
			row.id = row.id || uid( prefix );
		} );
		draw();
		return h( 'div', null, list, ui.Button( { label: ( field.attrs && field.attrs.add_label ) || __( 'Add', 'forms-notifier-for-elementor' ), icon: 'plus', size: 's', disabled: ctx.disabled, onClick() {
			const row = { id: uid( prefix ) };
			( field.fields || [] ).forEach( ( sub ) => {
				if ( sub.type !== 'secret' && sub.default !== undefined && sub.default !== null ) {
					row[ sub.key ] = clone( sub.default );
				}
			} );
			if ( ( field.fields || [] ).some( ( sub ) => sub.key === 'enabled' ) ) {
				row.enabled = true;
			}
			rows.push( row );
			open.add( row.id );
			commit();
			draw();
			const last = list.lastElementChild;
			const input = last && last.querySelector( 'input,select,textarea' );
			if ( input ) {
				input.focus();
			}
		} } ) );
	} );

	/* ------------------------------------------------ Fields without value */

	fields.register( 'heading', ( field ) => h( 'div', { class: 'fnfe-subhead' }, h( 'h3', null, field.label ), field.desc ? h( 'p', null, safeHtml( field.desc ) ) : null ) );

	fields.register( 'notice', ( field ) => ui.Callout( field.level || 'info', h( 'div', null, safeHtml( field.desc || field.label ) ), field.label && field.desc ? { title: field.label } : {} ) );

	/** Run the action a field describes, with the unsaved values it asks for. */
	fields.runAction = ( action, ctx, params ) => {
		const overlay = ctx.overlay ? ctx.overlay( action.send || [] ) : { values: {}, secrets: {} };
		const path = action.route || ( action.provider
			? 'providers/' + encodeURIComponent( action.channel || ctx.channel ) + '/' + encodeURIComponent( action.provider ) + '/' + encodeURIComponent( action.id )
			: 'channels/' + encodeURIComponent( action.channel || ctx.channel ) + '/action/' + encodeURIComponent( action.id ) );
		return api.post( path, { params: Object.assign( {}, action.params, params ), values: overlay.values, secrets: overlay.secrets } );
	};

	fields.register( 'action', ( field, value, onChange, ctx ) => {
		const action = field.action || {};
		const out = h( 'div', null );
		const button = ui.Button( {
			label: action.label || field.label,
			icon: action.icon || 'play',
			size: 's',
			disabled: ctx.disabled,
			onClick: () => ( action.confirm ? ui.confirm( { title: action.label || field.label, text: action.confirm } ) : Promise.resolve( true ) ).then( ( yes ) => {
				if ( ! yes ) {
					return null;
				}
				mount( out, null );
				return fields.runAction( action, ctx ).then( ( result ) => {
					mount( out, ui.Result( result ) );
					if ( ctx.onAction ) {
						ctx.onAction( action, result );
					}
				}, ( error ) => mount( out, ui.Result( { ok: false, message: error.message } ) ) );
			} ),
		} );
		return h( 'div', { class: 'fnfe-actionfield' }, button, out );
	} );

	fields.register( 'status', ( field, value, onChange, ctx ) => {
		const action = field.action || {};
		const line = h( 'span', { class: 'fnfe-statusline__text' }, '…' );
		const el = h( 'div', { class: 'fnfe-statusline', role: 'status' } );
		const load = () => {
			el.dataset.level = 'info';
			line.textContent = __( 'Checking…', 'forms-notifier-for-elementor' );
			return fields.runAction( action, ctx ).then( ( result ) => {
				el.dataset.level = result.level || ( result.ok ? 'success' : 'danger' );
				line.textContent = result.message;
			}, ( error ) => {
				el.dataset.level = 'danger';
				line.textContent = error.message;
			} );
		};
		mount( el, frag( h( 'span', { class: 'fnfe-statusline__lamp', 'aria-hidden': 'true' } ), line, ui.IconButton( { icon: 'refresh', size: 's', label: __( 'Check again', 'forms-notifier-for-elementor' ), onClick: load } ) ) );
		if ( action.id || action.route ) {
			window.setTimeout( () => el.isConnected && load(), 50 );
		} else {
			line.textContent = field.desc || '';
		}
		return el;
	} );

	/* ------------------------------------------------------- Field wrapper */

	const BLOCKS = [ 'heading', 'notice' ];
	const GROUPS = [ 'radio', 'checkboxes', 'repeater', 'receivers', 'buttons', 'rules', 'keyvalue', 'guide', 'status', 'action', 'secret', 'template' ];

	/** Complete field: label, control, help, error. Has setError( text ). */
	fields.render = ( field, value, onChange, context ) => {
		const ctx = Object.assign( { values: {}, get: () => undefined }, context );
		const all = wp.hooks.applyFilters( 'fnfe.fieldRenderers', renderers );
		let renderer = Object.prototype.hasOwnProperty.call( all, field.type ) ? all[ field.type ] : all.text;
		const id = ui.id( 'f' );
		const type = field.type;
		const descId = field.desc && type !== 'notice' ? id + '-d' : '';
		const errorId = id + '-e';
		const labelId = id + '-l';
		ctx.id = id;
		ctx.labelId = field.label ? labelId : '';
		ctx.describedBy = [ descId, errorId ].filter( Boolean ).join( ' ' );
		if ( field.localized && ( type === 'text' || type === 'textarea' ) ) {
			renderer = localizedInput( renderer );
		}

		const error = h( 'p', { class: 'fnfe-field__error', id: errorId, hidden: true } );
		const control = renderer( field, value === undefined ? clone( field.default ) : value, ( next ) => {
			el.setError( '' );
			onChange( next );
		}, ctx );

		const single = GROUPS.indexOf( type ) === -1 && ! field.localized;
		const label = field.label && type !== 'action' ? h( single ? 'label' : 'div', { class: 'fnfe-field__label', id: labelId, for: single ? id : undefined }, field.label, field.required ? h( 'span', { class: 'fnfe-field__req', title: __( 'Required', 'forms-notifier-for-elementor' ) }, ' *' ) : null ) : null;
		const desc = field.desc ? h( 'p', { class: 'fnfe-field__desc', id: descId }, safeHtml( field.desc ) ) : null;

		let el;
		if ( BLOCKS.indexOf( type ) > -1 ) {
			el = h( 'div', { class: 'fnfe-field fnfe-field--full' }, control );
		} else if ( type === 'toggle' ) {
			el = h( 'div', { class: [ 'fnfe-field', 'fnfe-field--toggle', 'fnfe-field--' + ( field.width || 'full' ) ] }, h( 'div', { class: 'fnfe-field__text' }, label, desc, error ), control );
		} else {
			el = h( 'div', { class: [ 'fnfe-field', 'fnfe-field--' + ( field.width || 'full' ), 'fnfe-field--t-' + type ] }, label, control, type === 'action' && ! field.desc ? null : desc, error );
		}
		el.dataset.key = field.key;
		el.setError = ( text ) => {
			error.hidden = ! text;
			error.textContent = text || '';
			el.classList.toggle( 'has-error', !! text );
			const input = document.getElementById( id );
			if ( input ) {
				input.setAttribute( 'aria-invalid', text ? 'true' : 'false' );
			}
		};
		return el;
	};

	/* --------------------------------------------------------------- Form */

	/**
	 * Sections with show_if logic. values: flat map path => value.
	 * Returns { el, validate(), destroy(), setErrors( map ), refresh(), sections: [ { id, title, el } ] }.
	 */
	fields.form = ( sections, values, onChange, context ) => {
		const state = Object.assign( {}, values );
		const items = [];
		const ctx = Object.assign( {
			values: state,
			get: ( path ) => ( Object.prototype.hasOwnProperty.call( state, path ) ? state[ path ] : window.fnfe.path.get( state, path ) ),
		}, context );
		const shown = [];
		const el = h( 'div', { class: 'fnfe-form' } );

		const refresh = () => {
			items.forEach( ( item ) => {
				item.el.hidden = ! visible( item.field, ctx.get ) || ( ctx.hide ? ctx.hide( item.field, ctx.get ) : false );
			} );
			shown.forEach( ( section ) => {
				section.el.hidden = section.items.length > 0 && section.items.every( ( item ) => item.el.hidden );
			} );
		};

		sections.forEach( ( section ) => {
			const grid = h( 'div', { class: 'fnfe-grid' } );
			const own = [];
			listOf( section.fields ).forEach( ( field ) => {
				const node = fields.render( field, state[ field.key ], ( next ) => {
					state[ field.key ] = next;
					onChange( field.key, next );
					refresh();
				}, ctx );
				own.push( { field: field, el: node } );
				grid.appendChild( node );
			} );
			items.push( ...own );
			const id = 'fnfe-s-' + String( section.id ).replace( /[^a-z0-9_-]/gi, '' );
			let node;
			if ( ctx.bare ) {
				node = h( 'div', { id: id }, section.title && sections.length > 1 ? h( 'div', { class: 'fnfe-subhead' }, h( 'h3', null, section.title ), section.desc ? h( 'p', null, safeHtml( section.desc ) ) : null ) : null, grid );
			} else if ( section.collapsed ) {
				node = h( 'section', { class: 'fnfe-card', id: id }, ui.Fold( { class: 'fnfe-fold--card', title: section.title, desc: section.desc ? h( 'span', null, safeHtml( section.desc ) ) : null }, grid ) );
			} else {
				node = ui.Card( { title: section.title, desc: section.desc ? safeHtml( section.desc ) : null, id: id }, grid );
			}
			shown.push( { id: id, title: section.title, el: node, items: own } );
			el.appendChild( node );
		} );
		refresh();

		const setErrors = ( errors ) => {
			let first = null;
			items.forEach( ( item ) => {
				const text = ( errors || {} )[ item.field.key ] || '';
				item.el.setError( text );
				if ( text && ! first ) {
					first = item.el;
				}
			} );
			if ( first ) {
				const fold = first.closest( '.fnfe-fold' );
				if ( fold && fold.open ) {
					fold.open();
				}
				first.scrollIntoView( { block: 'center', behavior: 'smooth' } );
				const input = first.querySelector( 'input,select,textarea,button' );
				if ( input ) {
					input.focus( { preventScroll: true } );
				}
			}
			return ! first;
		};

		return {
			el: el,
			sections: shown,
			refresh: refresh,
			setErrors: setErrors,
			validate() {
				const errors = {};
				items.forEach( ( item ) => {
					if ( item.el.hidden || ! item.field.required || item.field.type === 'secret' ) {
						return;
					}
					const value = ctx.get( item.field.key );
					const text = item.field.localized ? localized( value )._ : value;
					if ( text === '' || text === null || text === undefined || ( Array.isArray( text ) && ! text.length ) ) {
						errors[ item.field.key ] = __( 'This field is required.', 'forms-notifier-for-elementor' );
					}
				} );
				return setErrors( errors );
			},
			destroy() {
				mount( el, null );
			},
		};
	};

	/* ----------------------------------------------------- Settings panel */

	/**
	 * Form over /settings with a draft, a save bar and unsaved values for tests.
	 * opts: shell, sections, data ( { values, secrets, defaults } ), channel, hide( field, get ), onSaved( response ), onAction.
	 */
	fields.panel = ( opts ) => {
		let base = Object.assign( {}, opts.data.defaults, opts.data.values );
		let secrets = Object.assign( {}, opts.data.secrets );
		let draft = {};
		let pending = {};
		// Choices that only steer the form, such as which gateway is shown, are not settings to save.
		const LOCAL = /\.edit_provider$/;
		let form = null;
		let errors = {};
		let ready = false;
		const el = h( 'div', { class: 'fnfe-panel' } );
		const note = h( 'div', null );

		const dirty = () => Object.keys( draft ).length > 0 || Object.keys( pending ).length > 0;
		const merged = () => Object.assign( {}, base, draft );
		const get = ( path ) => merged()[ path ];
		const overlay = () => ( { values: clone( draft ), secrets: Object.keys( pending ).reduce( ( out, key ) => Object.assign( out, { [ key ]: pending[ key ] === null ? '' : pending[ key ] } ), {} ) } );

		const bar = () => opts.shell.savebar( dirty() ? { save: save, discard: discard, count: Object.keys( draft ).length + Object.keys( pending ).length } : null );

		const ctx = {
			get: get,
			channel: opts.channel || '',
			overlay: overlay,
			hide: opts.hide,
			onAction: opts.onAction,
			get secrets() {
				return secrets;
			},
			pendingSecret: ( path ) => pending[ path ],
			setSecret( path, value ) {
				if ( value === undefined ) {
					delete pending[ path ];
				} else {
					pending[ path ] = value;
				}
				bar();
				window.fnfe.bus.emit( 'draft', { channel: opts.channel } );
			},
		};

		function write( key, value ) {
			if ( LOCAL.test( key ) ) {
				base[ key ] = value;
			} else if ( same( value, base[ key ] ) ) {
				delete draft[ key ];
			} else {
				draft[ key ] = clone( value );
			}
		}

		function draw() {
			form = fields.form( opts.sections, merged(), ( key, value ) => {
				write( key, value );
				bar();
				window.fnfe.bus.emit( 'draft', { channel: opts.channel, key: key } );
			}, ctx );
			mount( el, frag( note, form.el ) );
			if ( opts.onDraw && ready ) {
				opts.onDraw();
			}
		}

		function discard() {
			draft = {};
			pending = {};
			mount( note, null );
			draw();
			bar();
		}

		function save() {
			return api.post( 'settings', { values: draft, secrets: pending } ).then( ( data ) => {
				errors = data.errors || {};
				const failed = Object.keys( errors );
				base = Object.assign( {}, base, data.values );
				secrets = Object.assign( {}, secrets, data.secrets );
				Object.keys( draft ).forEach( ( key ) => failed.indexOf( key ) === -1 && delete draft[ key ] );
				Object.keys( pending ).forEach( ( key ) => failed.indexOf( key ) === -1 && delete pending[ key ] );
				if ( ! failed.length ) {
					mount( note, null );
					draw();
					window.fnfe.toast( __( 'Settings saved.', 'forms-notifier-for-elementor' ), 'success' );
				} else {
					showErrors();
				}
				bar();
				if ( opts.onSaved ) {
					opts.onSaved( data );
				}
				return ! failed.length;
			}, ( error ) => {
				errors = error.fields || {};
				if ( Object.keys( errors ).length ) {
					showErrors();
				} else {
					ui.fail( error );
				}
				return false;
			} );
		}

		function showErrors() {
			const list = Object.keys( errors );
			mount( note, ui.Callout( 'danger', h( 'ul', { class: 'fnfe-list-plain' }, list.map( ( key ) => h( 'li', null, errors[ key ] ) ) ), {
				/* translators: %s: number of fields */
				title: sprintf( _n( '%s field was not saved', '%s fields were not saved', list.length, 'forms-notifier-for-elementor' ), fmt.number( list.length ) ),
			} ) );
			form.setErrors( errors );
		}

		draw();
		ready = true;
		return {
			el: el,
			get: get,
			overlay: overlay,
			dirty: dirty,
			save: save,
			/** Change a value from outside the form. */
			set( key, value ) {
				write( key, value );
				draw();
				bar();
			},
			/** A value that was saved elsewhere. */
			sync( key, value ) {
				base[ key ] = clone( value );
				delete draft[ key ];
				bar();
			},
			context: ctx,
			get sections() {
				return form.sections;
			},
			destroy() {
				form.destroy();
			},
		};
	};

	Object.assign( fields, { visible: visible, options: optionsOf, source: sourceOptions, localized: localized, list: listOf, clone: clone, same: same, autoGrow: autoGrow, forms: loadForms } );
}() );
