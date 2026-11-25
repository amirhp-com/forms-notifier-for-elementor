/**
 * Inline keyboard editor.
 */
( function () {
	'use strict';

	const { __, _x, sprintf } = wp.i18n;
	const { h, mount, frag, icon, boot, ui, uid, fmt, fields } = window.fnfe;

	const MAX_PER_ROW = 8;

	const typeLabel = ( type ) => ( {
		submission: __( 'Open the submission', 'forms-notifier-for-elementor' ),
		url: __( 'Open a link', 'forms-notifier-for-elementor' ),
		callback: __( 'Do something in the chat', 'forms-notifier-for-elementor' ),
		web_app: __( 'Open the mini app', 'forms-notifier-for-elementor' ),
		copy: __( 'Copy a text', 'forms-notifier-for-elementor' ),
	}[ type ] );

	const GLYPHS = { submission: 'inbox', url: 'external', callback: 'bolt', web_app: 'app', copy: 'copy' };

	fields.register( 'buttons', ( field, value, onChange, ctx ) => {
		const channel = field.channel || ctx.channel;
		const meta = ui.channelMeta( channel );
		const supported = ( meta && meta.capabilities.buttons ) || [ 'url' ];
		const types = [ 'submission' ].concat( [ 'url', 'callback', 'web_app', 'copy' ].filter( ( type ) => supported.indexOf( type ) > -1 ) );
		let rows = toRows( fields.list( fields.clone( value ) ) );
		let grabbed = '';
		const board = h( 'div', { class: 'fnfe-keys' } );
		const help = h( 'p', { class: 'fnfe-sr', id: ui.id( 'keys' ) }, __( 'Press Enter to edit. Press Space to grab a button, then move it with the arrow keys and press Space again to drop it.', 'forms-notifier-for-elementor' ) );

		function toRows( list ) {
			const map = {};
			list.forEach( ( button ) => {
				const row = Number( button.row ) || 0;
				button.id = button.id || uid( 'b_' );
				button.text = Object.assign( { _: '' }, fields.localized( button.text ) );
				( map[ row ] = map[ row ] || [] ).push( button );
			} );
			return Object.keys( map ).map( Number ).sort( ( a, b ) => a - b ).map( ( row ) => map[ row ] );
		}

		function commit() {
			rows = rows.filter( ( row ) => row.length );
			onChange( fields.clone( rows.reduce( ( out, row, i ) => out.concat( row.map( ( button ) => Object.assign( {}, button, { row: i } ) ) ), [] ) ) );
		}

		function find( id ) {
			for ( let r = 0; r < rows.length; r++ ) {
				const c = rows[ r ].findIndex( ( button ) => button.id === id );
				if ( c > -1 ) {
					return [ r, c ];
				}
			}
			return null;
		}

		/** Move a button to a row and position; r may be one past either end to open a new row. */
		function moveTo( id, r, c ) {
			const at = find( id );
			if ( ! at ) {
				return;
			}
			const button = rows[ at[ 0 ] ][ at[ 1 ] ];
			if ( r >= 0 && r < rows.length && r !== at[ 0 ] && rows[ r ].length >= MAX_PER_ROW ) {
				return;
			}
			rows[ at[ 0 ] ].splice( at[ 1 ], 1 );
			if ( r < 0 ) {
				rows.unshift( [ button ] );
			} else if ( r >= rows.length ) {
				rows.push( [ button ] );
			} else {
				rows[ r ].splice( Math.max( 0, Math.min( c, rows[ r ].length ) ), 0, button );
			}
			commit();
			draw( id );
		}

		function onKey( event, button ) {
			if ( event.key === ' ' ) {
				event.preventDefault();
				grabbed = grabbed === button.id ? '' : button.id;
				ui.announce( grabbed ? __( 'Grabbed. Use the arrow keys to move it.', 'forms-notifier-for-elementor' ) : __( 'Dropped', 'forms-notifier-for-elementor' ) );
				draw( button.id );
				return;
			}
			if ( event.key === 'Escape' && grabbed ) {
				event.preventDefault();
				event.stopPropagation();
				grabbed = '';
				draw( button.id );
				return;
			}
			if ( event.key === 'Delete' || event.key === 'Backspace' ) {
				event.preventDefault();
				remove( button );
				return;
			}
			const rtl = ui.isRtl();
			const step = { ArrowRight: [ 0, rtl ? -1 : 1 ], ArrowLeft: [ 0, rtl ? 1 : -1 ], ArrowUp: [ -1, 0 ], ArrowDown: [ 1, 0 ] }[ event.key ];
			if ( ! step ) {
				return;
			}
			event.preventDefault();
			const at = find( button.id );
			if ( grabbed === button.id ) {
				if ( step[ 0 ] ) {
					moveTo( button.id, at[ 0 ] + step[ 0 ], 99 );
				} else {
					moveTo( button.id, at[ 0 ], at[ 1 ] + step[ 1 ] );
				}
				return;
			}
			const row = rows[ at[ 0 ] + step[ 0 ] ];
			const next = row && row[ step[ 0 ] ? Math.min( at[ 1 ], row.length - 1 ) : at[ 1 ] + step[ 1 ] ];
			if ( next ) {
				board.querySelector( '[data-id="' + next.id + '"]' ).focus();
			}
		}

		function remove( button ) {
			const before = fields.clone( rows );
			rows = rows.map( ( row ) => row.filter( ( other ) => other.id !== button.id ) );
			commit();
			draw();
			window.fnfe.toast( __( 'Button removed.', 'forms-notifier-for-elementor' ), 'info', { action: { label: __( 'Undo', 'forms-notifier-for-elementor' ), run() {
				rows = before;
				commit();
				draw();
			} } } );
		}

		function dropZone( r ) {
			return h( 'div', { class: 'fnfe-keys__gap', onDragover: ( event ) => event.preventDefault(), onDragenter: ( event ) => event.target.classList.add( 'is-over' ), onDragleave: ( event ) => event.target.classList.remove( 'is-over' ), onDrop( event ) {
				event.preventDefault();
				const id = event.dataTransfer.getData( 'text/plain' );
				const at = find( id );
				if ( at ) {
					const button = rows[ at[ 0 ] ].splice( at[ 1 ], 1 )[ 0 ];
					rows.splice( r, 0, [ button ] );
					commit();
					draw( id );
				}
			} } );
		}

		function pill( button, r, c ) {
			return h(
				'button',
				{
					type: 'button',
					class: [ 'fnfe-key', button.style ? 'is-' + button.style : '', grabbed === button.id ? 'is-grabbed' : '' ],
					dataset: { id: button.id },
					draggable: ctx.disabled ? undefined : 'true',
					disabled: ctx.disabled || undefined,
					'aria-describedby': help.id,
					onClick: () => edit( button ),
					onKeydown: ( event ) => onKey( event, button ),
					onDragstart( event ) {
						event.dataTransfer.effectAllowed = 'move';
						event.dataTransfer.setData( 'text/plain', button.id );
						board.classList.add( 'is-dragging' );
					},
					onDragend: () => board.classList.remove( 'is-dragging' ),
					onDragover: ( event ) => event.preventDefault(),
					onDrop( event ) {
						event.preventDefault();
						event.stopPropagation();
						const id = event.dataTransfer.getData( 'text/plain' );
						if ( id && id !== button.id ) {
							moveTo( id, r, c );
						}
					},
				},
				icon( GLYPHS[ button.type ] || 'link', 16 ),
				h( 'span', { dir: 'auto' }, button.text._ || typeLabel( button.type ) )
			);
		}

		function draw( focus ) {
			board.classList.remove( 'is-dragging' );
			const nodes = [];
			rows.forEach( ( row, r ) => {
				nodes.push( dropZone( r ) );
				nodes.push( h( 'div', { class: 'fnfe-keys__row', role: 'group', /* translators: %s: number of the row */ 'aria-label': sprintf( __( 'Row %s', 'forms-notifier-for-elementor' ), fmt.number( r + 1 ) ) }, row.map( ( button, c ) => pill( button, r, c ) ), ! ctx.disabled && row.length < MAX_PER_ROW ? ui.IconButton( { icon: 'plus', size: 's', label: __( 'Add a button to this row', 'forms-notifier-for-elementor' ), onClick: () => create( r ) } ) : null ) );
			} );
			nodes.push( dropZone( rows.length ) );
			mount( board, frag(
				h( 'div', { class: 'fnfe-keys__bubble', 'aria-hidden': 'true' }, h( 'i' ), h( 'i' ), h( 'i' ) ),
				nodes,
				ctx.disabled ? null : h( 'button', { type: 'button', class: 'fnfe-keys__add', onClick: () => create( rows.length ) }, icon( 'plus', 16 ), rows.length ? __( 'New row', 'forms-notifier-for-elementor' ) : __( 'Add the first button', 'forms-notifier-for-elementor' ) )
			) );
			if ( focus ) {
				const el = board.querySelector( '[data-id="' + focus + '"]' );
				if ( el ) {
					el.focus();
				}
			}
		}

		function create( r ) {
			edit( { id: uid( 'b_' ), text: { _: __( 'Open submission', 'forms-notifier-for-elementor' ) }, type: 'submission', value: '', style: '' }, r );
		}

		function edit( button, newRow ) {
			const draft = fields.clone( button );
			const form = h( 'div', null );
			const isNew = newRow !== undefined;
			const actions = [
				{ value: 'read', label: __( 'Mark as read', 'forms-notifier-for-elementor' ) },
				{ value: 'done', label: __( 'Mark as done', 'forms-notifier-for-elementor' ) },
				{ value: 'spam', label: __( 'Mark as spam', 'forms-notifier-for-elementor' ) },
				{ value: 'retry', label: __( 'Send again', 'forms-notifier-for-elementor' ) },
			].concat( ( boot.options && boot.options.callback_actions ) || [] );
			const valueField = () => {
				switch ( draft.type ) {
					case 'url':
						return { key: 'value', type: 'text', label: __( 'Link', 'forms-notifier-for-elementor' ), placeholder: 'https://example.com/?id={submission_id}', required: true, attrs: { dir: 'ltr' }, tags: true };
					case 'copy':
						return { key: 'value', type: 'text', label: __( 'Text to copy', 'forms-notifier-for-elementor' ), placeholder: '{field:phone}', required: true, attrs: { maxlength: 256 }, tags: true };
					case 'callback':
						return { key: 'value', type: 'select', label: __( 'Action', 'forms-notifier-for-elementor' ), options: actions, desc: __( 'Works for saved submissions. Only people you allow can press it.', 'forms-notifier-for-elementor' ) };
					case 'web_app':
						return { key: 'value', type: 'select', label: __( 'Open', 'forms-notifier-for-elementor' ), options: [ { value: '', label: __( 'Home of the mini app', 'forms-notifier-for-elementor' ) }, { value: 'submission', label: __( 'This submission', 'forms-notifier-for-elementor' ) } ], desc: boot.features && boot.features.miniapp ? '' : __( 'The mini app is off. The button is left out until you switch it on.', 'forms-notifier-for-elementor' ) };
				}
				return null;
			};
			let valueNode = null;
			const build = () => {
				const grid = h( 'div', { class: 'fnfe-grid' } );
				const text = fields.render( { key: 'text', type: 'text', label: __( 'Text on the button', 'forms-notifier-for-elementor' ), localized: true, required: true, attrs: { maxlength: 64 } }, draft.text, ( next ) => {
					draft.text = next;
				}, {} );
				grid.appendChild( text );
				grid.appendChild( fields.render( { key: 'type', type: 'radio', label: __( 'What it does', 'forms-notifier-for-elementor' ), options: types.map( ( type ) => ( { value: type, label: typeLabel( type ) } ) ) }, draft.type, ( next ) => {
					draft.type = next;
					draft.value = next === 'callback' ? 'done' : '';
					build();
				}, {} ) );
				const spec = valueField();
				valueNode = null;
				if ( spec ) {
					valueNode = fields.render( spec, draft.value, ( next ) => {
						draft.value = next;
					}, {} );
					if ( spec.tags ) {
						const input = valueNode.querySelector( 'input' );
						const wrap = h( 'div', { class: 'fnfe-inputgroup' } );
						input.replaceWith( wrap );
						wrap.appendChild( input );
						wrap.appendChild( ui.TagButton( input, { formId: ctx.formId, purpose: 'admin' } ) );
					}
					grid.appendChild( valueNode );
				}
				if ( channel === 'telegram' || channel === 'bale' ) {
					grid.appendChild( fields.render( { key: 'style', type: 'radio', label: __( 'Colour', 'forms-notifier-for-elementor' ), desc: __( 'Newer versions of the messenger show it. Older ones ignore it.', 'forms-notifier-for-elementor' ), options: [
						{ value: '', label: _x( 'Normal', 'button colour', 'forms-notifier-for-elementor' ) },
						{ value: 'primary', label: _x( 'Primary', 'button colour', 'forms-notifier-for-elementor' ) },
						{ value: 'success', label: _x( 'Success', 'button colour', 'forms-notifier-for-elementor' ) },
						{ value: 'danger', label: _x( 'Danger', 'button colour', 'forms-notifier-for-elementor' ) },
					] }, draft.style || '', ( next ) => {
						draft.style = next;
					}, {} ) );
				}
				mount( form, grid );
				form.textField = text;
			};
			build();
			const save = () => {
				const empty = ! String( draft.text._ || '' ).trim();
				form.textField.setError( empty ? __( 'Enter a text.', 'forms-notifier-for-elementor' ) : '' );
				const spec = valueField();
				let bad = '';
				if ( spec && spec.required && ! String( draft.value || '' ).trim() ) {
					bad = __( 'This field is required.', 'forms-notifier-for-elementor' );
				} else if ( draft.type === 'url' && ! /^(https?:\/\/|tg:\/\/|\{)/i.test( String( draft.value ).trim() ) ) {
					bad = __( 'Start the link with https:// or a tag.', 'forms-notifier-for-elementor' );
				}
				if ( valueNode ) {
					valueNode.setError( bad );
				}
				if ( empty || bad ) {
					return;
				}
				if ( isNew ) {
					if ( rows[ newRow ] ) {
						rows[ newRow ].push( draft );
					} else {
						rows.push( [ draft ] );
					}
				} else {
					const at = find( button.id );
					rows[ at[ 0 ] ][ at[ 1 ] ] = draft;
				}
				modal.close();
				commit();
				draw( draft.id );
			};
			const modal = ui.Sheet( {
				title: isNew ? __( 'New button', 'forms-notifier-for-elementor' ) : __( 'Edit button', 'forms-notifier-for-elementor' ),
				size: 's',
				body: form,
				footer: [
					isNew ? null : ui.Button( { label: __( 'Remove', 'forms-notifier-for-elementor' ), icon: 'trash', variant: 'ghost', class: 'is-danger', onClick() {
						modal.close();
						remove( button );
					} } ),
					h( 'span', { class: 'fnfe-grow' } ),
					ui.Button( { label: __( 'Cancel', 'forms-notifier-for-elementor' ), variant: 'ghost', onClick: () => modal.close() } ),
					ui.Button( { label: isNew ? __( 'Add button', 'forms-notifier-for-elementor' ) : __( 'Apply', 'forms-notifier-for-elementor' ), variant: 'primary', onClick: save } ),
				],
			} );
		}

		draw();
		return h( 'div', null, help, board );
	} );
}() );
