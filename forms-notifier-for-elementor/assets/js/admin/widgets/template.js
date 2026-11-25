/**
 * Template editor with tag palette and live preview.
 */
( function () {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const { h, mount, frag, icon, api, ui, fmt, fields, debounce } = window.fnfe;

	/* ---------------------------------------------------------- Tag list */

	const catalogs = {};

	function loadTags( formId, purpose ) {
		const key = ( formId || 0 ) + ':' + purpose;
		if ( ! catalogs[ key ] ) {
			catalogs[ key ] = api.get( 'template/tags', { form_id: formId || undefined, purpose: purpose } ).then( ( data ) => data.items || [], ( error ) => {
				delete catalogs[ key ];
				throw error;
			} );
		}
		return catalogs[ key ];
	}

	const groupName = ( id ) => ( {
		fields: __( 'Form fields', 'forms-notifier-for-elementor' ),
		form: __( 'Form', 'forms-notifier-for-elementor' ),
		submission: __( 'Submission', 'forms-notifier-for-elementor' ),
		links: __( 'Links and files', 'forms-notifier-for-elementor' ),
		submitter: __( 'Person who sent the form', 'forms-notifier-for-elementor' ),
		page: __( 'Page', 'forms-notifier-for-elementor' ),
		user: __( 'Visitor', 'forms-notifier-for-elementor' ),
		site: __( 'Site', 'forms-notifier-for-elementor' ),
		date: __( 'Date and time', 'forms-notifier-for-elementor' ),
		system: __( 'Alerts and digest', 'forms-notifier-for-elementor' ),
	}[ id ] || id );

	/** Popover with a searchable list of tags. */
	function palette( anchor, opts ) {
		const list = h( 'div', { class: 'fnfe-palette__list' }, ui.Skeleton( 'rows', 4 ) );
		let items = [];
		const draw = ( query ) => {
			const q = ( query || '' ).trim().toLowerCase();
			const groups = {};
			items.filter( ( item ) => ! q || ( item.tag + ' ' + item.label + ' ' + ( item.desc || '' ) ).toLowerCase().indexOf( q ) > -1 ).forEach( ( item ) => ( groups[ item.group ] = groups[ item.group ] || [] ).push( item ) );
			const order = Object.keys( groups ).sort( ( a, b ) => ( b === 'fields' ) - ( a === 'fields' ) );
			mount( list, order.length ? order.map( ( group ) => h( 'div', { role: 'group', 'aria-label': groupName( group ) }, h( 'div', { class: 'fnfe-list__group' }, groupName( group ) ), groups[ group ].map( ( item ) => h( 'button', { type: 'button', class: 'fnfe-palette__item', title: item.desc || '', onClick() {
				pop.close();
				opts.onPick( item.tag );
			} }, h( 'span', { dir: 'auto' }, item.label ), ui.mono( item.tag ) ) ) ) ) : h( 'div', { class: 'fnfe-list__none' }, __( 'No matches', 'forms-notifier-for-elementor' ) ) );
		};
		const pop = ui.popover( anchor, h( 'div', { class: 'fnfe-palette' }, ui.Search( { wait: 0, onSearch: draw, placeholder: __( 'Search tags', 'forms-notifier-for-elementor' ) } ), list ), { class: 'fnfe-pop--palette', role: 'dialog' } );
		pop.el.querySelector( 'input' ).focus();
		pop.el.addEventListener( 'keydown', ( event ) => {
			const buttons = Array.from( list.querySelectorAll( 'button' ) );
			const at = buttons.indexOf( document.activeElement );
			if ( event.key === 'ArrowDown' || event.key === 'ArrowUp' ) {
				event.preventDefault();
				const to = at + ( event.key === 'ArrowDown' ? 1 : -1 );
				( buttons[ to ] || ( to < 0 ? pop.el.querySelector( 'input' ) : null ) || document.activeElement ).focus();
			} else if ( event.key === 'Enter' && event.target.tagName === 'INPUT' && buttons[ 0 ] ) {
				event.preventDefault();
				buttons[ 0 ].click();
			}
		} );
		loadTags( opts.formId, opts.purpose ).then( ( all ) => {
			items = all;
			if ( opts.only ) {
				items = all.filter( opts.only );
			}
			draw( '' );
			pop.place();
		}, ( error ) => mount( list, h( 'div', { class: 'fnfe-list__none' }, error.message ) ) );
		return pop;
	}

	/** Insert text at the caret of an input and tell its listeners. */
	function insert( input, before, after ) {
		const start = input.selectionStart === null ? input.value.length : input.selectionStart;
		const end = input.selectionEnd === null ? start : input.selectionEnd;
		const middle = input.value.slice( start, end );
		input.focus();
		input.setRangeText( before + middle + ( after || '' ), start, end, 'end' );
		if ( after && ! middle ) {
			input.setSelectionRange( start + before.length, start + before.length );
		}
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}

	/** Button that inserts a tag into an input. */
	ui.TagButton = ( input, opts ) => {
		const button = ui.IconButton( { icon: 'tag', size: 's', label: __( 'Insert a tag', 'forms-notifier-for-elementor' ), onClick: () => palette( button, Object.assign( { onPick: ( tag ) => insert( typeof input === 'function' ? input() : input, tag ) }, opts ) ) } );
		return button;
	};

	/* ----------------------------------------------------------- Preview */

	const RICH = { B: 'b', STRONG: 'b', I: 'i', EM: 'i', U: 'u', INS: 'u', S: 's', DEL: 's', STRIKE: 's', CODE: 'code', PRE: 'pre', BLOCKQUOTE: 'blockquote', 'TG-SPOILER': 'span', A: 'a', BR: 'br', P: 'p', TABLE: 'table', TBODY: 'tbody', TR: 'tr', TD: 'td', TH: 'th' };

	/** Copy of message markup with known elements only. */
	function rich( html ) {
		const doc = new DOMParser().parseFromString( String( html ), 'text/html' );
		const copy = ( source, target ) => Array.prototype.forEach.call( source.childNodes, ( node ) => {
			if ( node.nodeType === 3 ) {
				target.appendChild( document.createTextNode( node.nodeValue ) );
			} else if ( node.nodeType === 1 ) {
				const name = RICH[ node.tagName ];
				if ( [ 'SCRIPT', 'STYLE', 'HEAD', 'TITLE' ].indexOf( node.tagName ) > -1 ) {
					return;
				}
				const el = name ? h( name, name === 'a' ? { class: 'fnfe-bubble__link' } : node.tagName === 'TG-SPOILER' ? { class: 'fnfe-bubble__spoiler' } : null ) : target;
				copy( node, el );
				if ( el !== target ) {
					target.appendChild( el );
				}
			}
		} );
		const out = document.createDocumentFragment();
		copy( doc.body, out );
		return out;
	}

	/** Chat bubble. data: preview answer. format: format of the channel. */
	function bubble( data, format ) {
		const isHtml = format === 'telegram_html' || format === 'email_html';
		const parts = data.parts && data.parts.length ? data.parts : [ data.text || '' ];
		const rows = data.buttons || [];
		return h(
			'div',
			{ class: [ 'fnfe-preview__chat', format === 'email_html' ? 'fnfe-preview__chat--mail' : '' ] },
			data.subject ? h( 'div', { class: 'fnfe-preview__subject', dir: 'auto' }, data.subject ) : null,
			parts.map( ( part ) => h( 'div', { class: 'fnfe-bubble', dir: 'auto' }, isHtml ? rich( part ) : part ) ),
			rows.length ? h( 'div', { class: 'fnfe-bubble__keys' }, rows.map( ( row ) => h( 'div', null, row.map( ( button ) => h( 'span', { class: [ 'fnfe-bubble__key', button.style ? 'is-' + button.style : '' ], dir: 'auto' }, button.text ) ) ) ) ) : null
		);
	}

	ui.bubble = bubble;
	ui.rich = rich;

	function preview( opts ) {
		const out = h( 'div', { class: 'fnfe-preview__out', 'aria-live': 'polite' } );
		const meter = h( 'div', { class: 'fnfe-preview__meter' } );
		const warnings = h( 'div', null );
		let raw = false;
		let last = null;
		const rawButton = ui.IconButton( { icon: 'code', size: 's', label: __( 'Show the raw text', 'forms-notifier-for-elementor' ), attrs: { 'aria-pressed': false }, onClick() {
			raw = ! raw;
			rawButton.setAttribute( 'aria-pressed', raw ? 'true' : 'false' );
			draw();
		} } );
		const el = h( 'div', { class: 'fnfe-preview' }, h( 'div', { class: 'fnfe-preview__head' }, opts.channel ? ui.Tile( opts.channel, 's' ) : icon( 'eye', 16 ), h( 'span', null, __( 'Preview with sample data', 'forms-notifier-for-elementor' ) ), h( 'span', { class: 'fnfe-grow' } ), rawButton ), out, meter, warnings );
		let visible = false;
		let wanted = false;
		let turn = 0;

		function draw() {
			if ( ! last ) {
				return;
			}
			const meta = ui.channelMeta( opts.channel );
			mount( out, raw ? h( 'pre', { class: 'fnfe-preview__raw', dir: 'auto' }, last.text ) : bubble( last, meta ? meta.format : 'text' ) );
			const parts = ( last.parts || [] ).length;
			const ratio = last.limit ? Math.min( 1, last.length / last.limit ) : 0;
			mount( meter, frag(
				last.limit ? h( 'span', { class: [ 'fnfe-meter', ratio >= 1 ? 'is-over' : ratio > 0.8 ? 'is-near' : '' ], 'aria-hidden': 'true' }, h( 'i', { style: { width: Math.round( ratio * 100 ) + '%' } } ) ) : null,
				h( 'span', null, last.limit
					/* translators: %1$s: used characters, %2$s: allowed characters */
					? sprintf( __( '%1$s of %2$s characters', 'forms-notifier-for-elementor' ), fmt.number( last.length ), fmt.number( last.limit ) )
					/* translators: %s: number of characters */
					: sprintf( _n( '%s character', '%s characters', last.length, 'forms-notifier-for-elementor' ), fmt.number( last.length ) ) ),
				/* translators: %s: number of messages */
				parts > 1 ? ui.Badge( sprintf( _n( '%s message', '%s messages', parts, 'forms-notifier-for-elementor' ), fmt.number( parts ) ), 'info' ) : null
			) );
			mount( warnings, ( last.warnings || [] ).length ? ui.Callout( 'warning', h( 'ul', { class: 'fnfe-list-plain' }, last.warnings.map( ( text ) => h( 'li', null, text ) ) ) ) : null );
		}

		function load() {
			if ( ! visible ) {
				wanted = true;
				return;
			}
			wanted = false;
			const mine = ++turn;
			el.classList.add( 'is-loading' );
			api.post( 'template/preview', { template: opts.read(), subject: opts.readSubject ? opts.readSubject() : undefined, channel: opts.channel, purpose: opts.purpose, form_id: opts.formId || 0, lang: opts.lang() === '_' ? '' : opts.lang(), buttons: opts.buttons ? opts.buttons() : undefined } ).then( ( data ) => {
				if ( mine === turn ) {
					last = data;
					draw();
				}
			}, ( error ) => {
				if ( mine === turn ) {
					last = null;
					mount( out, h( 'p', { class: 'fnfe-preview__none' }, error.status === 404 ? __( 'The preview is not available.', 'forms-notifier-for-elementor' ) : error.message ) );
					mount( meter, null );
				}
			} ).then( () => mine === turn && el.classList.remove( 'is-loading' ) );
		}

		mount( out, h( 'div', { class: 'fnfe-preview__none' }, ui.Skeleton( 'form', 2 ) ) );
		const seen = () => {
			visible = true;
			if ( wanted ) {
				load();
			}
		};
		if ( 'IntersectionObserver' in window ) {
			const observer = new IntersectionObserver( ( entries ) => {
				if ( entries.some( ( entry ) => entry.isIntersecting ) ) {
					observer.disconnect();
					seen();
				}
			} );
			observer.observe( el );
		} else {
			seen();
		}
		el.load = debounce( load, 400 );
		load();
		return el;
	}

	/* ------------------------------------------------------------ Editor */

	function editor( field, value, onChange, ctx ) {
		const many = ui.multilingual() && field.localized;
		const channel = field.channel || ctx.channel || '';
		const purpose = field.purpose || 'admin';
		let current = field.localized ? Object.assign( { _: '' }, fields.localized( value ) ) : { _: String( value || '' ) };
		let lang = '_';

		const area = fields.autoGrow( h( 'textarea', {
			id: ctx.id,
			class: 'fnfe-input fnfe-textarea fnfe-tpl__area',
			rows: 8,
			spellcheck: 'false',
			'aria-describedby': ctx.describedBy || undefined,
			'aria-labelledby': ctx.labelId || undefined,
			disabled: ctx.disabled || undefined,
			placeholder: field.placeholder || __( 'Empty: the built-in text is used, as the preview shows.', 'forms-notifier-for-elementor' ),
			onInput() {
				current = Object.assign( {}, current, { [ lang ]: area.value } );
				if ( ! area.value && lang !== '_' ) {
					delete current[ lang ];
				}
				if ( tabs ) {
					tabs.mark( lang, lang !== '_' && !! area.value );
				}
				onChange( field.localized ? current : current._ );
				pane.load();
			},
			onKeydown( event ) {
				if ( ( event.ctrlKey || event.metaKey ) && ( event.key === 'b' || event.key === 'i' ) ) {
					event.preventDefault();
					insert( area, '<' + event.key + '>', '</' + event.key + '>' );
				}
			},
		} ) );

		const copyDefault = ui.Button( { label: __( 'Copy from default', 'forms-notifier-for-elementor' ), icon: 'copy', size: 's', variant: 'ghost', onClick() {
			area.value = current._ || '';
			area.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} } );

		const show = () => {
			area.value = current[ lang ] || '';
			const dir = ui.langDir( lang );
			area.setAttribute( 'dir', dir );
			if ( lang === '_' ) {
				area.removeAttribute( 'lang' );
			} else {
				area.setAttribute( 'lang', lang );
			}
			copyDefault.hidden = lang === '_' || ! current._ || !! current[ lang ];
		};

		const tabs = many ? ui.LangTabs( { active: lang, has: ( code ) => !! current[ code ], onSelect( code ) {
			lang = code;
			show();
			pane.load();
		} } ) : null;

		const tool = ( glyph, label, before, after ) => ui.IconButton( { icon: glyph, size: 's', label: label, disabled: ctx.disabled, onClick: () => insert( area, before, after ) } );
		const tagButton = ui.Button( { label: __( 'Insert a tag', 'forms-notifier-for-elementor' ), icon: 'tag', size: 's', disabled: ctx.disabled, onClick: () => palette( tagButton, { formId: ctx.formId, purpose: purpose, onPick: ( tag ) => insert( area, tag ) } ) } );

		const pane = preview( {
			channel: channel,
			purpose: purpose,
			formId: ctx.formId,
			lang: () => lang,
			read: () => current[ lang ] || ( lang === '_' ? '' : current._ || '' ),
			readSubject: () => {
				const subject = ctx.get( field.key.replace( 'template', 'subject' ) );
				const texts = field.key.indexOf( 'template' ) > -1 && subject ? fields.localized( subject ) : {};
				return texts[ lang ] || texts._ || '';
			},
			buttons: ctx.previewButtons,
		} );

		show();
		return h(
			'div',
			{ class: 'fnfe-tpl' },
			h(
				'div',
				{ class: 'fnfe-tpl__main' },
				tabs ? h( 'div', { class: 'fnfe-localized__bar' }, tabs, copyDefault ) : null,
				h(
					'div',
					{ class: 'fnfe-tpl__box' },
					h(
						'div',
						{ class: 'fnfe-tpl__bar', role: 'toolbar', 'aria-label': __( 'Formatting', 'forms-notifier-for-elementor' ) },
						tagButton,
						h( 'span', { class: 'fnfe-grow' } ),
						tool( 'bold', __( 'Bold', 'forms-notifier-for-elementor' ), '<b>', '</b>' ),
						tool( 'italic', __( 'Italic', 'forms-notifier-for-elementor' ), '<i>', '</i>' ),
						tool( 'code', __( 'Code', 'forms-notifier-for-elementor' ), '<code>', '</code>' ),
						tool( 'link', __( 'Link', 'forms-notifier-for-elementor' ), '<a href="https://">', '</a>' ),
						tool( 'branch', __( 'Show a part only if a field is filled', 'forms-notifier-for-elementor' ), '{if field:ID}', '{/if}' )
					),
					area
				)
			),
			pane
		);
	}

	fields.register( 'template', editor );

	ui.palette = palette;
	ui.insertText = insert;
}() );
