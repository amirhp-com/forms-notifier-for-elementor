/**
 * Components of the admin panel: fnfe.ui.
 */
( function () {
	'use strict';

	const { __, _x, _n, sprintf } = wp.i18n;
	const { h, mount, icon, boot, fmt } = window.fnfe;

	let counter = 0;
	const nextId = ( prefix ) => 'fnfe-' + ( prefix || 'x' ) + '-' + ( ++counter );
	const root = () => document.getElementById( 'fnfe-app' ) || document.querySelector( '.fnfe-root' ) || document.body;
	const isRtl = () => getComputedStyle( root() ).direction === 'rtl';
	const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

	const focusables = ( el ) => Array.from( el.querySelectorAll( FOCUSABLE ) ).filter( ( item ) => item.offsetParent !== null || item === document.activeElement );

	/* ------------------------------------------------------------ Buttons */

	/**
	 * Button. opts: label, icon, iconEnd, variant (primary|secondary|ghost|danger), size (m|s), href, onClick, disabled, title, attrs.
	 * onClick may return a promise: the button shows progress until it settles.
	 */
	function Button( opts ) {
		const o = Object.assign( { variant: 'secondary', size: 'm' }, opts );
		const glyph = o.icon ? icon( o.icon, o.size === 's' ? 16 : 18 ) : null;
		const el = h(
			o.href ? 'a' : 'button',
			Object.assign(
				{
					class: [ 'fnfe-btn', 'fnfe-btn--' + o.variant, o.size === 's' ? 'fnfe-btn--s' : '', o.class || '' ],
					type: o.href ? undefined : 'button',
					href: o.href || undefined,
					target: o.target || undefined,
					rel: o.target === '_blank' ? 'noopener noreferrer' : undefined,
					disabled: o.disabled || undefined,
					title: o.title || undefined,
				},
				o.attrs || {}
			),
			glyph ? h( 'span', { class: 'fnfe-btn__icon' }, glyph ) : null,
			h( 'span', { class: 'fnfe-btn__label' }, o.label ),
			o.iconEnd ? h( 'span', { class: 'fnfe-btn__icon' }, icon( o.iconEnd, 16 ) ) : null
		);
		el.setLoading = ( on ) => {
			el.classList.toggle( 'is-loading', !! on );
			if ( on ) {
				el.setAttribute( 'aria-busy', 'true' );
				el.setAttribute( 'aria-disabled', 'true' );
			} else {
				el.removeAttribute( 'aria-busy' );
				el.removeAttribute( 'aria-disabled' );
			}
		};
		el.setLabel = ( text ) => {
			el.querySelector( '.fnfe-btn__label' ).textContent = text;
		};
		if ( o.onClick ) {
			el.addEventListener( 'click', ( event ) => {
				if ( el.classList.contains( 'is-loading' ) ) {
					event.preventDefault();
					return;
				}
				const out = o.onClick( event );
				if ( out && typeof out.then === 'function' ) {
					el.setLoading( true );
					const done = () => el.setLoading( false );
					out.then( done, done );
				}
			} );
		}
		return el;
	}

	/** Icon-only button; label is required and becomes the tooltip. */
	function IconButton( opts ) {
		const el = h(
			opts.href ? 'a' : 'button',
			Object.assign(
				{
					class: [ 'fnfe-iconbtn', opts.danger ? 'fnfe-iconbtn--danger' : '', opts.size === 's' ? 'fnfe-iconbtn--s' : '', opts.class || '' ],
					type: opts.href ? undefined : 'button',
					href: opts.href || undefined,
					target: opts.target || undefined,
					rel: opts.target === '_blank' ? 'noopener noreferrer' : undefined,
					'aria-label': opts.label,
					title: opts.label,
					disabled: opts.disabled || undefined,
				},
				opts.attrs || {}
			),
			icon( opts.icon, opts.size === 's' ? 16 : 18 )
		);
		if ( opts.onClick ) {
			el.addEventListener( 'click', opts.onClick );
		}
		return el;
	}

	function CopyButton( text, label ) {
		const read = () => ( typeof text === 'function' ? text() : String( text ) );
		const title = label || __( 'Copy', 'forms-notifier-for-elementor' );
		const el = IconButton( {
			icon: 'copy',
			size: 's',
			label: title,
			onClick( event ) {
				event.preventDefault();
				event.stopPropagation();
				copyText( read() ).then( () => {
					mount( el, icon( 'check', 16 ) );
					el.classList.add( 'is-done' );
					announce( __( 'Copied', 'forms-notifier-for-elementor' ) );
					window.setTimeout( () => {
						mount( el, icon( 'copy', 16 ) );
						el.classList.remove( 'is-done' );
					}, 1500 );
				} );
			},
		} );
		return el;
	}

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text ).catch( () => legacyCopy( text ) );
		}
		return Promise.resolve( legacyCopy( text ) );
	}

	function legacyCopy( text ) {
		const active = document.activeElement;
		const area = h( 'textarea', { class: 'fnfe-sr', readonly: true, value: text } );
		root().appendChild( area );
		area.select();
		document.execCommand( 'copy' );
		area.remove();
		if ( active && active.focus ) {
			active.focus();
		}
	}

	let live = null;
	function announce( text ) {
		if ( ! live || ! live.isConnected ) {
			live = h( 'div', { class: 'fnfe-sr', role: 'status', 'aria-live': 'polite' } );
			root().appendChild( live );
		}
		live.textContent = '';
		window.setTimeout( () => {
			live.textContent = text;
		}, 50 );
	}

	/** Switch. opts: checked, onChange( bool ), label (visible) or ariaLabel, desc, disabled, id, describedBy. */
	function Switch( opts ) {
		const el = h(
			'button',
			{
				type: 'button',
				role: 'switch',
				class: 'fnfe-switch',
				id: opts.id || undefined,
				'aria-checked': !! opts.checked,
				'aria-label': opts.ariaLabel || undefined,
				'aria-labelledby': opts.labelledBy || undefined,
				'aria-describedby': opts.describedBy || undefined,
				disabled: opts.disabled || undefined,
			},
			h( 'span', { class: 'fnfe-switch__knob' } )
		);
		el.set = ( on ) => el.setAttribute( 'aria-checked', on ? 'true' : 'false' );
		el.get = () => el.getAttribute( 'aria-checked' ) === 'true';
		el.addEventListener( 'click', ( event ) => {
			event.stopPropagation();
			const next = ! el.get();
			el.set( next );
			if ( opts.onChange && opts.onChange( next ) === false ) {
				el.set( ! next );
			}
		} );
		return el;
	}

	/** Segmented control. opts: options [ { value, label, icon } ], value, onChange, label. */
	function Segmented( opts ) {
		const buttons = [];
		const select = ( value, focus ) => {
			buttons.forEach( ( button ) => {
				const on = button.dataset.value === String( value );
				button.setAttribute( 'aria-checked', on ? 'true' : 'false' );
				button.tabIndex = on ? 0 : -1;
				if ( on && focus ) {
					button.focus();
				}
			} );
		};
		const pick = ( value, focus ) => {
			select( value, focus );
			opts.onChange( value );
		};
		opts.options.forEach( ( option, i ) => {
			buttons.push( h(
				'button',
				{
					type: 'button',
					role: 'radio',
					class: [ 'fnfe-seg__item', option.tone ? 'fnfe-seg__item--' + option.tone : '' ],
					dataset: { value: option.value },
					'aria-label': option.ariaLabel || undefined,
					title: option.ariaLabel || undefined,
					disabled: opts.disabled || undefined,
					onClick: () => pick( option.value ),
					onKeydown( event ) {
						const step = { ArrowDown: 1, ArrowUp: -1, ArrowRight: isRtl() ? -1 : 1, ArrowLeft: isRtl() ? 1 : -1 }[ event.key ];
						if ( step ) {
							event.preventDefault();
							const next = opts.options[ ( i + step + opts.options.length ) % opts.options.length ];
							pick( next.value, true );
						}
					},
				},
				option.icon ? icon( option.icon, 16 ) : null,
				option.label ? h( 'span', null, option.label ) : null
			) );
		} );
		const el = h( 'div', { class: [ 'fnfe-seg', opts.class || '' ], role: 'radiogroup', 'aria-label': opts.label || undefined, 'aria-labelledby': opts.labelledBy || undefined }, buttons );
		select( opts.value );
		if ( ! buttons.some( ( button ) => button.tabIndex === 0 ) && buttons[ 0 ] ) {
			buttons[ 0 ].tabIndex = 0;
		}
		el.set = select;
		return el;
	}

	/* --------------------------------------------------------------- Tabs */

	/**
	 * Tabs. opts: tabs [ { id, label, icon, count, dot } ], active, onSelect( id ), label, panel (element the tabs control).
	 */
	function Tabs( opts ) {
		const id = nextId( 'tabs' );
		const items = opts.tabs.map( ( tab, i ) => h(
			'button',
			{
				type: 'button',
				role: 'tab',
				id: id + '-' + tab.id,
				class: 'fnfe-tabs__tab',
				dataset: { tab: tab.id },
				onClick: () => choose( tab.id ),
				onKeydown( event ) {
					const step = { ArrowRight: isRtl() ? -1 : 1, ArrowLeft: isRtl() ? 1 : -1 }[ event.key ];
					if ( step ) {
						event.preventDefault();
						choose( opts.tabs[ ( i + step + opts.tabs.length ) % opts.tabs.length ].id, true );
					}
				},
			},
			tab.icon ? icon( tab.icon, 16 ) : null,
			h( 'span', null, tab.label ),
			tab.count ? h( 'span', { class: 'fnfe-tabs__count' }, fmt.number( tab.count ) ) : null,
			tab.dot ? h( 'span', { class: 'fnfe-tabs__dot', 'aria-hidden': 'true' } ) : null
		) );
		const set = ( active, focus ) => items.forEach( ( item ) => {
			const on = item.dataset.tab === active;
			item.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			item.tabIndex = on ? 0 : -1;
			if ( on ) {
				if ( focus ) {
					item.focus();
				}
				if ( opts.panel ) {
					opts.panel.setAttribute( 'aria-labelledby', item.id );
				}
			}
		} );
		const choose = ( tab, focus ) => {
			set( tab, focus );
			opts.onSelect( tab );
		};
		if ( opts.panel ) {
			opts.panel.setAttribute( 'role', 'tabpanel' );
			if ( ! opts.panel.id ) {
				opts.panel.id = id + '-panel';
			}
			items.forEach( ( item ) => item.setAttribute( 'aria-controls', opts.panel.id ) );
		}
		const el = h( 'div', { class: [ 'fnfe-tabs', opts.class || '' ], role: 'tablist', 'aria-label': opts.label || undefined }, items );
		set( opts.active );
		el.set = set;
		return el;
	}

	/** Language tabs: '_' is the default text. onSelect( code ). has( code ) tells whether a tab holds a text. */
	function LangTabs( opts ) {
		const languages = ( boot.i18n && boot.i18n.languages ) || [];
		const tabs = [ { id: '_', label: __( 'Default', 'forms-notifier-for-elementor' ) } ].concat( languages.map( ( lang ) => ( { id: lang.code, label: lang.name } ) ) );
		const el = Tabs( {
			class: 'fnfe-tabs--lang',
			label: __( 'Language', 'forms-notifier-for-elementor' ),
			tabs: tabs.map( ( tab ) => Object.assign( { dot: tab.id !== '_' && opts.has && opts.has( tab.id ) }, tab ) ),
			active: opts.active || '_',
			onSelect: opts.onSelect,
		} );
		el.mark = ( code, on ) => {
			const tab = el.querySelector( '[data-tab="' + code + '"]' );
			const dot = tab && tab.querySelector( '.fnfe-tabs__dot' );
			if ( tab && on && ! dot ) {
				tab.appendChild( h( 'span', { class: 'fnfe-tabs__dot', 'aria-hidden': 'true' } ) );
			} else if ( dot && ! on ) {
				dot.remove();
			}
		};
		return el;
	}

	const multilingual = () => ( ( boot.i18n && boot.i18n.languages ) || [] ).length > 1;
	const langDir = ( code ) => {
		const found = ( ( boot.i18n && boot.i18n.languages ) || [] ).find( ( lang ) => lang.code === ( code === '_' ? boot.i18n.default : code ) );
		return found ? ( found.rtl ? 'rtl' : 'ltr' ) : 'auto';
	};

	/* ----------------------------------------------------- Small elements */

	function Badge( label, tone, glyph ) {
		return h( 'span', { class: [ 'fnfe-badge', 'fnfe-badge--' + ( tone || 'mute' ) ] }, glyph ? icon( glyph, 14 ) : null, h( 'span', null, label ) );
	}

	const STATUS = () => ( {
		sent: [ _x( 'Sent', 'message status', 'forms-notifier-for-elementor' ), 'ok', 'check-circle' ],
		failed: [ _x( 'Failed', 'message status', 'forms-notifier-for-elementor' ), 'danger', 'x-circle' ],
		retry: [ _x( 'Retrying', 'message status', 'forms-notifier-for-elementor' ), 'warn', 'refresh' ],
		queued: [ _x( 'Queued', 'message status', 'forms-notifier-for-elementor' ), 'info', 'clock' ],
		sending: [ _x( 'Sending', 'message status', 'forms-notifier-for-elementor' ), 'info', 'send' ],
		skipped: [ _x( 'Skipped', 'message status', 'forms-notifier-for-elementor' ), 'mute', 'minus' ],
		cancelled: [ _x( 'Cancelled', 'message status', 'forms-notifier-for-elementor' ), 'mute', 'x-circle' ],
		partial: [ _x( 'Partly sent', 'delivery status', 'forms-notifier-for-elementor' ), 'warn', 'alert' ],
		none: [ _x( 'Nothing to send', 'delivery status', 'forms-notifier-for-elementor' ), 'mute', 'minus' ],
		new: [ _x( 'New', 'submission status', 'forms-notifier-for-elementor' ), 'info', 'sparkle' ],
		read: [ _x( 'Read', 'submission status', 'forms-notifier-for-elementor' ), 'mute', 'eye' ],
		done: [ _x( 'Done', 'submission status', 'forms-notifier-for-elementor' ), 'ok', 'check' ],
		spam: [ _x( 'Spam', 'submission status', 'forms-notifier-for-elementor' ), 'warn', 'flag' ],
		trash: [ _x( 'Trash', 'submission status', 'forms-notifier-for-elementor' ), 'mute', 'trash' ],
	} );

	const statusLabel = ( status ) => ( STATUS()[ status ] || [ status ] )[ 0 ];

	function Status( status ) {
		const def = STATUS()[ status ] || [ status, 'mute', 'minus' ];
		return Badge( def[ 0 ], def[ 1 ], def[ 2 ] );
	}

	/** Coloured lamp with a text for screen readers. */
	function Dot( status, label ) {
		const def = STATUS()[ status ] || [ label || status, status, '' ];
		return h( 'span', { class: [ 'fnfe-dot', 'fnfe-dot--' + def[ 1 ] ], title: label || def[ 0 ], role: 'img', 'aria-label': label || def[ 0 ] } );
	}

	/** Attempts as pips: filled for used ones. */
	function Pips( used, total, status ) {
		const max = Math.min( Math.max( total, used ), 8 );
		const tone = ( STATUS()[ status ] || [ '', 'mute' ] )[ 1 ];
		/* translators: %1$s: attempts used, %2$s: attempts allowed */
		const label = sprintf( __( 'Attempt %1$s of %2$s', 'forms-notifier-for-elementor' ), fmt.number( used ), fmt.number( total ) );
		return h( 'span', { class: 'fnfe-pips', role: 'img', 'aria-label': label, title: label }, Array.from( { length: max }, ( x, i ) => h( 'i', { class: i < used ? ( i === used - 1 ? 'is-' + tone : 'is-used' ) : '' } ) ) );
	}

	const mono = ( text ) => h( 'bdi', { class: 'fnfe-mono', dir: 'ltr' }, text );

	/* In the Persian panel digits are drawn as Persian ones; words such as "n8n" keep theirs. */
	const CODE = /[A-Za-z][A-Za-z0-9._+-]*\d[A-Za-z0-9._+-]*/g;
	const persian = () => root().dataset.font === 'fa';

	function keepCodes( node ) {
		Array.from( node.childNodes ).forEach( ( child ) => {
			if ( child.nodeType === 1 ) {
				keepCodes( child );
			} else if ( child.nodeType === 3 && child.nodeValue.search( CODE ) > -1 ) {
				const out = document.createDocumentFragment();
				let at = 0;
				child.nodeValue.replace( CODE, ( word, index ) => {
					out.appendChild( document.createTextNode( child.nodeValue.slice( at, index ) ) );
					out.appendChild( h( 'bdi', { class: 'fnfe-latin', dir: 'ltr' }, word ) );
					at = index + word.length;
					return word;
				} );
				out.appendChild( document.createTextNode( child.nodeValue.slice( at ) ) );
				node.replaceChild( out, child );
			}
		} );
		return node;
	}

	/** Text of the interface as nodes. */
	const tx = ( text ) => {
		const out = document.createDocumentFragment();
		out.appendChild( document.createTextNode( String( text === null || text === undefined ? '' : text ) ) );
		return persian() ? keepCodes( out ) : out;
	};
	const baseHtml = window.fnfe.safeHtml;
	window.fnfe.safeHtml = ( html ) => ( persian() ? keepCodes( baseHtml( html ) ) : baseHtml( html ) );

	/** Text people typed or sent: digits stay as they are. */
	const raw = ( text, tag ) => h( tag || 'bdi', { class: 'fnfe-latin' }, text );

	const MONOGRAMS = { telegram: 'TG', bale: 'BL', sms: 'SM', whatsapp: 'WA', email: 'EM', webhook: 'WH', slack: 'SL', discord: 'DC', googlechat: 'GC', teams: 'MT', mattermost: 'MM', rocketchat: 'RC', ntfy: 'NT', pushover: 'PO', gotify: 'GO', eitaa: 'EI', rubika: 'RB', soroush: 'SR', gap: 'GP' };
	const HUES = { telegram: 205, bale: 150, sms: 262, whatsapp: 138, email: 222, webhook: 18, slack: 320, discord: 236, googlechat: 45, teams: 250, mattermost: 212, rocketchat: 2, ntfy: 170, pushover: 198, gotify: 188, eitaa: 28, rubika: 340, soroush: 190, gap: 285 };

	const channelMeta = ( id ) => ( boot.channels || [] ).find( ( item ) => item.id === id ) || null;
	const channelLabel = ( id ) => ( channelMeta( id ) || { label: id } ).label;

	/** Monogram tile of a channel. size: s | m | l. */
	function Tile( id, size ) {
		let hue = HUES[ id ];
		if ( hue === undefined ) {
			hue = Array.from( String( id ) ).reduce( ( sum, char ) => ( sum * 31 + char.charCodeAt( 0 ) ) % 360, 7 );
		}
		const text = MONOGRAMS[ id ] || String( id ).replace( /[^a-z0-9]/gi, '' ).slice( 0, 2 ).toUpperCase() || '··';
		return h( 'span', { class: [ 'fnfe-tile', 'fnfe-tile--' + ( size || 'm' ) ], style: { '--fnfe-hue': hue }, 'aria-hidden': 'true' }, text );
	}

	function ChannelChip( id ) {
		return h( 'span', { class: 'fnfe-channelchip' }, Tile( id, 's' ), h( 'span', null, channelLabel( id ) ) );
	}

	function Callout( type, body, opts ) {
		const o = opts || {};
		const glyphs = { info: 'info', success: 'check-circle', warning: 'alert', danger: 'x-circle' };
		return h(
			'div',
			{ class: [ 'fnfe-callout', 'fnfe-callout--' + type ], role: type === 'danger' ? 'alert' : undefined },
			h( 'span', { class: 'fnfe-callout__icon' }, icon( glyphs[ type ] || 'info', 18 ) ),
			h( 'div', { class: 'fnfe-callout__body' }, o.title ? h( 'strong', { class: 'fnfe-callout__title' }, o.title ) : null, body ),
			o.actions ? h( 'div', { class: 'fnfe-callout__actions' }, o.actions ) : null
		);
	}

	/** Card. opts: title, desc, actions, id, class, flush (no padding), foot. */
	function Card( opts, ...children ) {
		const head = opts.title || opts.actions ? h(
			'header',
			{ class: 'fnfe-card__head' },
			h( 'div', { class: 'fnfe-card__titles' }, opts.title ? h( 'h2', { class: 'fnfe-card__title' }, opts.title ) : null, opts.desc ? h( 'p', { class: 'fnfe-card__desc' }, opts.desc ) : null ),
			opts.actions ? h( 'div', { class: 'fnfe-card__actions' }, opts.actions ) : null
		) : null;
		return h(
			'section',
			{ class: [ 'fnfe-card', opts.class || '' ], id: opts.id || undefined },
			head,
			h( 'div', { class: [ 'fnfe-card__body', opts.flush ? 'fnfe-card__body--flush' : '' ] }, children ),
			opts.foot ? h( 'footer', { class: 'fnfe-card__foot' }, opts.foot ) : null
		);
	}

	function Empty( opts ) {
		return h(
			'div',
			{ class: [ 'fnfe-empty', opts.small ? 'fnfe-empty--small' : '' ] },
			h( 'span', { class: 'fnfe-empty__icon' }, icon( opts.icon || 'inbox', opts.small ? 20 : 26 ) ),
			opts.title ? h( 'strong', { class: 'fnfe-empty__title' }, opts.title ) : null,
			opts.text ? h( 'p', { class: 'fnfe-empty__text' }, opts.text ) : null,
			opts.actions ? h( 'div', { class: 'fnfe-empty__actions' }, opts.actions ) : null
		);
	}

	/** Skeleton. kind: rows | cards | form | detail. */
	function Skeleton( kind, n ) {
		const bar = ( w, tall ) => h( 'span', { class: [ 'fnfe-skel__bar', tall ? 'fnfe-skel__bar--tall' : '' ], style: { width: w } } );
		const count = n || 6;
		const make = {
			rows: ( i ) => h( 'div', { class: 'fnfe-skel__row' }, h( 'span', { class: 'fnfe-skel__box' } ), bar( 30 + ( i * 7 ) % 22 + '%' ), bar( '14%' ), bar( '10%' ) ),
			cards: () => h( 'div', { class: 'fnfe-skel__card' }, h( 'span', { class: 'fnfe-skel__box' } ), bar( '60%' ), bar( '85%' ) ),
			form: ( i ) => h( 'div', { class: 'fnfe-skel__field' }, bar( 18 + ( i * 5 ) % 14 + '%' ), bar( '100%', true ) ),
		};
		return h(
			'div',
			{ class: [ 'fnfe-skel', 'fnfe-skel--' + kind ], role: 'status', 'aria-label': __( 'Loading', 'forms-notifier-for-elementor' ) },
			Array.from( { length: count }, ( x, i ) => ( make[ kind ] || make.rows )( i ) )
		);
	}

	/** Error state of a screen or card. */
	function ErrorState( error, retry ) {
		const e = error || {};
		let hint = '';
		if ( e.code === 'rest_cookie_invalid_nonce' || e.status === 401 ) {
			hint = __( 'Your session is out of date. Reload the page.', 'forms-notifier-for-elementor' );
		} else if ( e.status === 403 ) {
			hint = __( 'Your account may not open this. Ask an administrator for access.', 'forms-notifier-for-elementor' );
		} else if ( e.status === 404 && e.code === 'rest_no_route' ) {
			hint = __( 'This part of the plugin is not available on this site.', 'forms-notifier-for-elementor' );
		}
		return Callout( 'danger', h( 'div', null, h( 'p', null, e.message || __( 'Something went wrong.', 'forms-notifier-for-elementor' ) ), hint ? h( 'p', null, hint ) : null ), {
			title: __( 'This could not be loaded', 'forms-notifier-for-elementor' ),
			actions: retry ? Button( { label: __( 'Try again', 'forms-notifier-for-elementor' ), icon: 'refresh', size: 's', onClick: retry } ) : null,
		} );
	}

	const fail = ( error ) => window.fnfe.toast( ( error && error.message ) || __( 'Something went wrong.', 'forms-notifier-for-elementor' ), 'danger' );

	/** Time: relative text, absolute on hover. */
	function Time( iso ) {
		return iso ? h( 'time', { datetime: iso, title: fmt.date( iso ) }, fmt.relative( iso ) ) : h( 'span', { class: 'fnfe-muted' }, '–' );
	}

	/** Code block with a copy button. */
	function Code( text, lang ) {
		return h( 'div', { class: 'fnfe-code', dir: 'ltr' }, h( 'pre', { tabindex: 0, 'aria-label': lang ? lang.toUpperCase() : undefined }, h( 'code', null, text ) ), CopyButton( text ) );
	}

	/** ActionResult of the REST API. */
	function Result( result ) {
		if ( ! result ) {
			return null;
		}
		const level = [ 'success', 'info', 'warning', 'danger' ].indexOf( result.level ) > -1 ? result.level : ( result.ok ? 'success' : 'danger' );
		const rows = result.rows || [];
		const columns = result.columns && result.columns.length ? result.columns : ( rows[ 0 ] ? Object.keys( rows[ 0 ] ).map( ( key ) => ( { key: key, label: key } ) ) : [] );
		return h(
			'div',
			{ class: 'fnfe-result', role: 'status' },
			Callout( level, h( 'div', null, result.message ? h( 'p', null, result.message ) : null, result.html ? h( 'div', { class: 'fnfe-prose' }, window.fnfe.safeHtml( result.html ) ) : null ) ),
			rows.length ? h( 'div', { class: 'fnfe-tablewrap' }, h(
				'table',
				{ class: 'fnfe-table fnfe-table--plain' },
				h( 'thead', null, h( 'tr', null, columns.map( ( col ) => h( 'th', { scope: 'col' }, col.label ) ) ) ),
				h( 'tbody', null, rows.map( ( row ) => h( 'tr', null, columns.map( ( col ) => h( 'td', { dir: 'auto' }, row[ col.key ] === undefined || typeof row[ col.key ] === 'object' ? '' : String( row[ col.key ] ) ) ) ) ) )
			) ) : null
		);
	}

	/* ------------------------------------------------------------ Dialogs */

	const stack = [];

	function trap( event, el ) {
		if ( event.key !== 'Tab' ) {
			return;
		}
		const items = focusables( el );
		if ( ! items.length ) {
			event.preventDefault();
			return;
		}
		const first = items[ 0 ];
		const last = items[ items.length - 1 ];
		if ( event.shiftKey && ( document.activeElement === first || document.activeElement === el ) ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	/**
	 * Modal or side sheet. opts: title, desc, body, footer, size (s|m|l), side, onClose, lead (node before the title).
	 * Returns { el, body, footer, close, setFooter }.
	 */
	function Modal( opts ) {
		const opener = document.activeElement;
		const titleId = nextId( 'dialog' );
		const body = h( 'div', { class: 'fnfe-dialog__body' }, opts.body );
		const footer = h( 'footer', { class: 'fnfe-dialog__foot', hidden: ! opts.footer }, opts.footer );
		const panel = h(
			'div',
			{ class: [ 'fnfe-dialog', opts.side ? 'fnfe-dialog--side' : '', 'fnfe-dialog--' + ( opts.size || 'm' ) ], role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId, tabindex: -1 },
			h(
				'header',
				{ class: 'fnfe-dialog__head' },
				opts.lead || null,
				h( 'div', { class: 'fnfe-dialog__titles' }, h( 'h2', { id: titleId, class: 'fnfe-dialog__title' }, opts.title ), opts.desc ? h( 'p', { class: 'fnfe-dialog__desc' }, opts.desc ) : null ),
				IconButton( { icon: 'x', label: __( 'Close', 'forms-notifier-for-elementor' ), onClick: () => close() } )
			),
			body,
			footer
		);
		let pressed = false;
		const el = h(
			'div',
			{
				class: [ 'fnfe-overlay', opts.side ? 'fnfe-overlay--side' : '' ],
				onMousedown( event ) {
					pressed = event.target === el;
				},
				onClick( event ) {
					if ( pressed && event.target === el ) {
						close();
					}
				},
				onKeydown( event ) {
					if ( event.key === 'Escape' && ! event.defaultPrevented ) {
						event.preventDefault();
						event.stopPropagation();
						close();
					}
					trap( event, panel );
				},
			},
			panel
		);
		let open = true;
		function close( silent ) {
			if ( ! open ) {
				return;
			}
			if ( ! silent && opts.canClose && opts.canClose() === false ) {
				return;
			}
			open = false;
			stack.splice( stack.indexOf( api ), 1 );
			el.remove();
			if ( ! stack.length ) {
				document.documentElement.classList.remove( 'fnfe-locked' );
			}
			if ( opener && opener.isConnected && opener.focus ) {
				opener.focus();
			}
			if ( opts.onClose ) {
				opts.onClose();
			}
		}
		const api = {
			el: panel,
			body: body,
			footer: footer,
			close: close,
			setFooter( nodes ) {
				footer.hidden = ! nodes;
				mount( footer, nodes );
			},
		};
		stack.push( api );
		document.documentElement.classList.add( 'fnfe-locked' );
		root().appendChild( el );
		window.setTimeout( () => {
			if ( open && ! panel.contains( document.activeElement ) ) {
				const target = panel.querySelector( '[data-autofocus]' ) || body.querySelector( 'input:not([type="hidden"]),textarea,select' ) || panel;
				target.focus();
			}
		}, 30 );
		return api;
	}

	const Sheet = ( opts ) => Modal( Object.assign( { side: true }, opts ) );

	const closeAll = () => stack.slice().forEach( ( dialog ) => dialog.close( true ) );

	/** Confirmation dialog. Resolves to true or false. opts: title, text, confirm, cancel, danger. */
	function confirm( opts ) {
		return new Promise( ( resolve ) => {
			let answer = false;
			const dialog = Modal( {
				title: opts.title,
				size: 's',
				body: h( 'div', { class: 'fnfe-prose' }, opts.text ? h( 'p', null, opts.text ) : null, opts.body || null ),
				onClose: () => resolve( answer ),
				footer: [
					Button( { label: opts.cancel || __( 'Cancel', 'forms-notifier-for-elementor' ), variant: 'ghost', onClick: () => dialog.close(), attrs: opts.danger ? { 'data-autofocus': '' } : {} } ),
					Button( {
						label: opts.confirm || __( 'Continue', 'forms-notifier-for-elementor' ),
						variant: opts.danger ? 'danger' : 'primary',
						onClick() {
							answer = true;
							dialog.close();
						},
						attrs: opts.danger ? {} : { 'data-autofocus': '' },
					} ),
				],
			} );
		} );
	}

	/** Small floating panel next to an element. Returns close(). */
	function popover( anchor, content, opts ) {
		const o = opts || {};
		const el = h( 'div', { class: [ 'fnfe-pop', o.class || '' ], role: o.role || undefined }, content );
		const host = anchor.closest( '.fnfe-overlay' ) || root();
		host.appendChild( el );
		const place = () => {
			const a = anchor.getBoundingClientRect();
			if ( o.matchWidth ) {
				el.style.minWidth = a.width + 'px';
			}
			const w = el.offsetWidth;
			const height = el.offsetHeight;
			const rtl = isRtl();
			let left = ( o.align === 'end' ) !== rtl ? a.right - w : a.left;
			left = Math.max( 8, Math.min( left, window.innerWidth - w - 8 ) );
			let top = a.bottom + 4;
			if ( top + height > window.innerHeight - 8 && a.top - height - 4 > 40 ) {
				top = a.top - height - 4;
			}
			el.style.left = left + 'px';
			el.style.top = top + 'px';
		};
		let open = true;
		const close = ( focus ) => {
			if ( ! open ) {
				return;
			}
			open = false;
			el.remove();
			document.removeEventListener( 'mousedown', outside, true );
			window.removeEventListener( 'scroll', place, true );
			window.removeEventListener( 'resize', place );
			if ( focus && anchor.focus ) {
				anchor.focus();
			}
			if ( o.onClose ) {
				o.onClose();
			}
		};
		const outside = ( event ) => {
			if ( ! el.contains( event.target ) && ! anchor.contains( event.target ) ) {
				close();
			}
		};
		el.addEventListener( 'keydown', ( event ) => {
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				event.stopPropagation();
				close( true );
			}
		} );
		document.addEventListener( 'mousedown', outside, true );
		window.addEventListener( 'scroll', place, true );
		window.addEventListener( 'resize', place );
		place();
		return { el: el, close: close, place: place };
	}

	/** Menu button. items: [ { label, icon, onSelect, danger, href, disabled } ]. */
	function Menu( opts ) {
		let pop = null;
		const button = opts.button || IconButton( { icon: 'more', label: opts.label || __( 'More actions', 'forms-notifier-for-elementor' ), size: opts.size } );
		button.setAttribute( 'aria-haspopup', 'menu' );
		button.setAttribute( 'aria-expanded', 'false' );
		const open = () => {
			const items = ( typeof opts.items === 'function' ? opts.items() : opts.items ).filter( Boolean );
			const nodes = items.map( ( item ) => h(
				item.href ? 'a' : 'button',
				{
					type: item.href ? undefined : 'button',
					href: item.href || undefined,
					target: item.target || undefined,
					role: 'menuitem',
					tabindex: -1,
					class: [ 'fnfe-menu__item', item.danger ? 'is-danger' : '' ],
					disabled: item.disabled || undefined,
					onClick( event ) {
						event.stopPropagation();
						pop.close( true );
						if ( item.onSelect ) {
							item.onSelect();
						}
					},
				},
				item.icon ? icon( item.icon, 16 ) : null,
				h( 'span', null, item.label )
			) );
			pop = popover( button, h( 'div', {
				class: 'fnfe-menu',
				role: 'menu',
				onKeydown( event ) {
					const usable = nodes.filter( ( node ) => ! node.disabled );
					const at = usable.indexOf( document.activeElement );
					const to = { ArrowDown: at + 1, ArrowUp: at - 1, Home: 0, End: usable.length - 1 }[ event.key ];
					if ( to !== undefined ) {
						event.preventDefault();
						usable[ ( to + usable.length ) % usable.length ].focus();
					} else if ( event.key === 'Tab' ) {
						pop.close();
					}
				},
			}, nodes ), {
				align: opts.align || 'end',
				onClose() {
					pop = null;
					button.setAttribute( 'aria-expanded', 'false' );
				},
			} );
			button.setAttribute( 'aria-expanded', 'true' );
			const first = nodes.find( ( node ) => ! node.disabled );
			if ( first ) {
				first.focus();
			}
		};
		button.addEventListener( 'click', ( event ) => {
			event.preventDefault();
			event.stopPropagation();
			if ( pop ) {
				pop.close();
			} else {
				open();
			}
		} );
		return button;
	}

	/* ----------------------------------------------------------- Combobox */

	/**
	 * Select with search. opts: options [ { value, label, group, desc } ] or a function, value (string or string[]),
	 * multiple, onChange, placeholder, label, id, describedBy, disabled, ordered (chips can be moved).
	 */
	function Combobox( opts ) {
		const list = () => ( typeof opts.options === 'function' ? opts.options() : opts.options ) || [];
		let value = opts.multiple ? ( opts.value || [] ).slice() : ( opts.value || '' );
		let pop = null;
		let active = 0;
		let shown = [];
		const listId = nextId( 'list' );
		const find = ( v ) => list().find( ( option ) => option.value === v );
		const chips = h( 'span', { class: 'fnfe-combo__chips' } );
		const input = h( 'input', {
			type: 'text',
			class: 'fnfe-combo__input',
			id: opts.id || undefined,
			role: 'combobox',
			'aria-expanded': false,
			'aria-controls': listId,
			'aria-autocomplete': 'list',
			'aria-label': opts.label || undefined,
			'aria-describedby': opts.describedBy || undefined,
			autocomplete: 'off',
			placeholder: opts.placeholder || '',
			disabled: opts.disabled || undefined,
		} );
		const box = h( 'div', { class: [ 'fnfe-combo', opts.multiple ? 'fnfe-combo--multi' : '', opts.disabled ? 'is-disabled' : '' ] }, chips, input, icon( 'chevron-down', 16 ) );

		const change = () => opts.onChange( opts.multiple ? value.slice() : value );

		function drawChips() {
			if ( ! opts.multiple ) {
				const option = find( value );
				input.value = option ? option.label : '';
				return;
			}
			mount( chips, value.map( ( v, i ) => h(
				'span',
				{ class: 'fnfe-chip' },
				opts.ordered ? h( 'span', { class: 'fnfe-chip__n' }, fmt.number( i + 1 ) ) : null,
				h( 'span', null, ( find( v ) || { label: v } ).label ),
				opts.ordered && i > 0 ? h( 'button', { type: 'button', class: 'fnfe-chip__x', 'aria-label': __( 'Move earlier', 'forms-notifier-for-elementor' ), title: __( 'Move earlier', 'forms-notifier-for-elementor' ), onClick() {
					value.splice( i - 1, 0, value.splice( i, 1 )[ 0 ] );
					drawChips();
					change();
				} }, icon( 'arrow-start', 14 ) ) : null,
				/* translators: %s: name of the item */
				h( 'button', { type: 'button', class: 'fnfe-chip__x', disabled: opts.disabled || undefined, 'aria-label': sprintf( __( 'Remove %s', 'forms-notifier-for-elementor' ), ( find( v ) || { label: v } ).label ), onClick( event ) {
					event.stopPropagation();
					value = value.filter( ( x ) => x !== v );
					drawChips();
					change();
					input.focus();
				} }, icon( 'x', 14 ) )
			) ) );
		}

		function choose( option ) {
			if ( opts.multiple ) {
				value = value.indexOf( option.value ) > -1 ? value.filter( ( x ) => x !== option.value ) : value.concat( option.value );
				input.value = '';
				drawChips();
				drawList();
			} else {
				value = option.value;
				drawChips();
				close();
			}
			change();
		}

		function drawList() {
			const query = ( opts.multiple || input.dataset.typed ? input.value : '' ).trim().toLowerCase();
			shown = list().filter( ( option ) => ! query || ( option.label + ' ' + option.value ).toLowerCase().indexOf( query ) > -1 ).slice( 0, 300 );
			active = Math.min( active, Math.max( 0, shown.length - 1 ) );
			let group = null;
			const nodes = [];
			shown.forEach( ( option, i ) => {
				if ( option.group && option.group !== group ) {
					group = option.group;
					nodes.push( h( 'div', { class: 'fnfe-list__group', role: 'presentation' }, groupLabel( group ) ) );
				}
				const on = opts.multiple ? value.indexOf( option.value ) > -1 : value === option.value;
				nodes.push( h(
					'div',
					{
						class: [ 'fnfe-list__item', i === active ? 'is-active' : '' ],
						role: 'option',
						id: listId + '-' + i,
						'aria-selected': on,
						onMousedown( event ) {
							event.preventDefault();
							choose( option );
						},
					},
					h( 'span', { class: 'fnfe-list__label' }, option.label, option.desc ? h( 'small', null, option.desc ) : null ),
					on ? icon( 'check', 16 ) : null
				) );
			} );
			mount( pop.el, h( 'div', { class: 'fnfe-list', role: 'listbox', id: listId, 'aria-multiselectable': opts.multiple || undefined, 'aria-label': opts.label || undefined }, nodes.length ? nodes : h( 'div', { class: 'fnfe-list__none' }, __( 'No matches', 'forms-notifier-for-elementor' ) ) ) );
			input.setAttribute( 'aria-activedescendant', shown.length ? listId + '-' + active : '' );
			const node = pop.el.querySelector( '.is-active' );
			if ( node ) {
				node.scrollIntoView( { block: 'nearest' } );
			}
			pop.place();
		}

		function open() {
			if ( pop || opts.disabled ) {
				return;
			}
			pop = popover( box, null, { class: 'fnfe-pop--list', matchWidth: true, onClose() {
				pop = null;
				delete input.dataset.typed;
				input.setAttribute( 'aria-expanded', 'false' );
				input.removeAttribute( 'aria-activedescendant' );
				if ( opts.multiple ) {
					input.value = '';
				} else {
					drawChips();
				}
			} } );
			input.setAttribute( 'aria-expanded', 'true' );
			active = opts.multiple ? 0 : Math.max( 0, list().findIndex( ( option ) => option.value === value ) );
			drawList();
		}

		function close() {
			if ( pop ) {
				pop.close();
			}
		}

		box.addEventListener( 'mousedown', ( event ) => {
			if ( event.target !== input && ! event.target.closest( 'button' ) ) {
				event.preventDefault();
				input.focus();
				open();
			}
		} );
		input.addEventListener( 'click', open );
		input.addEventListener( 'input', () => {
			input.dataset.typed = '1';
			active = 0;
			open();
			if ( pop ) {
				drawList();
			}
		} );
		input.addEventListener( 'blur', () => window.setTimeout( () => {
			if ( document.activeElement !== input ) {
				close();
			}
		}, 120 ) );
		input.addEventListener( 'keydown', ( event ) => {
			if ( event.key === 'ArrowDown' || event.key === 'ArrowUp' ) {
				event.preventDefault();
				if ( ! pop ) {
					open();
					return;
				}
				active = ( active + ( event.key === 'ArrowDown' ? 1 : -1 ) + shown.length ) % Math.max( 1, shown.length );
				drawList();
			} else if ( event.key === 'Enter' ) {
				if ( pop && shown[ active ] ) {
					event.preventDefault();
					choose( shown[ active ] );
				}
			} else if ( event.key === 'Escape' && pop ) {
				event.preventDefault();
				event.stopPropagation();
				close();
			} else if ( event.key === 'Backspace' && opts.multiple && ! input.value && value.length ) {
				value.pop();
				drawChips();
				change();
			}
		} );
		drawChips();
		box.set = ( next ) => {
			value = opts.multiple ? ( next || [] ).slice() : ( next || '' );
			drawChips();
		};
		box.input = input;
		return box;
	}

	function groupLabel( group ) {
		const known = {
			iran: __( 'Iran', 'forms-notifier-for-elementor' ),
			international: __( 'International', 'forms-notifier-for-elementor' ),
			passthrough: __( 'Other plugins and own gateway', 'forms-notifier-for-elementor' ),
			custom: __( 'Own gateway', 'forms-notifier-for-elementor' ),
		};
		return known[ group ] || group;
	}

	/** Native select. options [ { value, label, group } ]. */
	function Select( opts ) {
		const groups = {};
		const nodes = [];
		opts.options.forEach( ( option ) => {
			const node = h( 'option', { value: option.value }, option.label );
			if ( option.group ) {
				if ( ! groups[ option.group ] ) {
					groups[ option.group ] = h( 'optgroup', { label: groupLabel( option.group ) } );
					nodes.push( groups[ option.group ] );
				}
				groups[ option.group ].appendChild( node );
			} else {
				nodes.push( node );
			}
		} );
		const select = h( 'select', {
			class: [ 'fnfe-input', 'fnfe-select', opts.small ? 'fnfe-input--s' : '' ],
			id: opts.id || undefined,
			'aria-label': opts.label || undefined,
			'aria-describedby': opts.describedBy || undefined,
			disabled: opts.disabled || undefined,
			value: opts.value === undefined ? '' : opts.value,
			onChange: ( event ) => opts.onChange( event.target.value ),
		}, nodes );
		return select;
	}

	/** Search input with a debounce. */
	function Search( opts ) {
		const run = window.fnfe.debounce( ( value ) => opts.onSearch( value ), opts.wait === undefined ? 350 : opts.wait );
		const input = h( 'input', { type: 'search', class: 'fnfe-input', value: opts.value || '', placeholder: opts.placeholder || __( 'Search', 'forms-notifier-for-elementor' ), 'aria-label': opts.label || opts.placeholder || __( 'Search', 'forms-notifier-for-elementor' ), onInput: () => run( input.value.trim() ) } );
		return h( 'div', { class: 'fnfe-search' }, icon( 'search', 16 ), input );
	}

	/** Filter chips: one value out of options. */
	function Chips( opts ) {
		return h( 'div', { class: 'fnfe-chips', role: 'group', 'aria-label': opts.label }, opts.options.map( ( option ) => h(
			'button',
			{ type: 'button', class: 'fnfe-chips__item', 'aria-pressed': String( option.value ) === String( opts.value ), onClick: () => opts.onChange( option.value ) },
			option.tone ? h( 'span', { class: [ 'fnfe-dot', 'fnfe-dot--' + option.tone ], 'aria-hidden': 'true' } ) : null,
			h( 'span', null, option.label ),
			option.count !== undefined && option.count !== null ? h( 'span', { class: 'fnfe-chips__count' }, fmt.number( option.count ) ) : null
		) ) );
	}

	/** Range of dates. */
	function DateRange( opts ) {
		const input = ( name, label ) => h( 'input', { type: 'date', class: 'fnfe-input fnfe-input--s', dir: 'ltr', value: opts[ name ] || '', 'aria-label': label, title: label, onChange: ( event ) => opts.onChange( name, event.target.value ) } );
		return h( 'div', { class: 'fnfe-daterange' }, input( 'from', __( 'From date', 'forms-notifier-for-elementor' ) ), h( 'span', { 'aria-hidden': 'true' }, '–' ), input( 'to', __( 'To date', 'forms-notifier-for-elementor' ) ) );
	}

	/* -------------------------------------------------------------- Table */

	/**
	 * Table. opts: columns [ { key, label, render( row ), class, primary } ], rows, rowKey, onRow( row ), selectable,
	 * selected (Set), onSelect( Set ), actions( row ) -> nodes, label, rowClass( row ).
	 */
	function Table( opts ) {
		const key = opts.rowKey || ( ( row ) => row.id );
		const selected = opts.selected || new Set();
		const boxes = [];
		let master = null;
		const sync = () => {
			if ( master ) {
				const n = opts.rows.filter( ( row ) => selected.has( key( row ) ) ).length;
				master.checked = n > 0 && n === opts.rows.length;
				master.indeterminate = n > 0 && n < opts.rows.length;
			}
			boxes.forEach( ( box ) => {
				box.input.checked = selected.has( box.key );
				box.tr.classList.toggle( 'is-selected', box.input.checked );
			} );
			if ( opts.onSelect ) {
				opts.onSelect( selected );
			}
		};
		const head = h(
			'tr',
			null,
			opts.selectable ? h( 'th', { class: 'fnfe-table__check', scope: 'col' }, ( master = h( 'input', { type: 'checkbox', class: 'fnfe-check', 'aria-label': __( 'Select all', 'forms-notifier-for-elementor' ), onChange() {
				opts.rows.forEach( ( row ) => ( master.checked ? selected.add( key( row ) ) : selected.delete( key( row ) ) ) );
				sync();
			} } ) ) ) : null,
			opts.columns.map( ( col ) => h(
				'th',
				{ scope: 'col', class: col.class || undefined, 'aria-sort': opts.orderby && col.sort === opts.orderby ? ( opts.order === 'asc' ? 'ascending' : 'descending' ) : undefined },
				col.sort && opts.onSort ? h( 'button', { type: 'button', class: 'fnfe-table__sort', onClick: () => opts.onSort( col.sort, opts.orderby === col.sort && opts.order === 'desc' ? 'asc' : 'desc' ) }, h( 'span', null, col.label ), icon( opts.orderby === col.sort && opts.order === 'asc' ? 'chevron-up' : 'chevron-down', 14 ) ) : col.label
			) ),
			opts.actions ? h( 'th', { class: 'fnfe-table__actions', scope: 'col' }, h( 'span', { class: 'fnfe-sr' }, __( 'Actions', 'forms-notifier-for-elementor' ) ) ) : null
		);
		const rows = opts.rows.map( ( row ) => {
			const tr = h(
				'tr',
				{
					class: [ opts.onRow ? 'is-link' : '', opts.rowClass ? opts.rowClass( row ) : '' ],
					onClick( event ) {
						if ( opts.onRow && ! event.target.closest( 'a,button,input,label,select' ) && ! String( window.getSelection() ) ) {
							opts.onRow( row );
						}
					},
				},
				opts.selectable ? h( 'td', { class: 'fnfe-table__check' }, ( () => {
					const input = h( 'input', { type: 'checkbox', class: 'fnfe-check', 'aria-label': __( 'Select row', 'forms-notifier-for-elementor' ), checked: selected.has( key( row ) ), onChange() {
						if ( input.checked ) {
							selected.add( key( row ) );
						} else {
							selected.delete( key( row ) );
						}
						sync();
					} } );
					window.setTimeout( () => boxes.push( { input: input, key: key( row ), tr: tr } ) );
					return input;
				} )() ) : null,
				opts.columns.map( ( col ) => h( 'td', { class: [ col.class || '', col.primary ? 'fnfe-table__primary' : '' ], 'data-label': col.label || undefined }, col.render ? col.render( row ) : row[ col.key ] ) ),
				opts.actions ? h( 'td', { class: 'fnfe-table__actions' }, h( 'div', { class: 'fnfe-rowactions' }, opts.actions( row ) ) ) : null
			);
			return tr;
		} );
		return h( 'div', { class: 'fnfe-tablewrap' }, h( 'table', { class: 'fnfe-table', 'aria-label': opts.label || undefined }, h( 'thead', null, head ), h( 'tbody', null, rows ) ) );
	}

	/** Bar that appears while rows are selected. */
	function BulkBar( count, actions, onClear ) {
		return h(
			'div',
			{ class: 'fnfe-bulk', role: 'region', 'aria-label': __( 'Selected items', 'forms-notifier-for-elementor' ) },
			/* translators: %s: number of selected items */
			h( 'strong', null, sprintf( _n( '%s selected', '%s selected', count, 'forms-notifier-for-elementor' ), fmt.number( count ) ) ),
			h( 'div', { class: 'fnfe-bulk__actions' }, actions ),
			Button( { label: __( 'Clear', 'forms-notifier-for-elementor' ), variant: 'ghost', size: 's', onClick: onClear } )
		);
	}

	function Pagination( opts ) {
		const pages = Math.max( 1, Math.ceil( opts.total / opts.perPage ) );
		if ( opts.total === 0 ) {
			return null;
		}
		const from = ( opts.page - 1 ) * opts.perPage + 1;
		const to = Math.min( opts.total, opts.page * opts.perPage );
		return h(
			'nav',
			{ class: 'fnfe-pages', 'aria-label': __( 'Pages', 'forms-notifier-for-elementor' ) },
			/* translators: %1$s: first item, %2$s: last item, %3$s: number of items */
			h( 'span', { class: 'fnfe-muted' }, sprintf( __( '%1$s to %2$s of %3$s', 'forms-notifier-for-elementor' ), fmt.number( from ), fmt.number( to ), fmt.number( opts.total ) ) ),
			pages > 1 ? h(
				'div',
				{ class: 'fnfe-pages__nav' },
				IconButton( { icon: 'chevron-start', label: __( 'Previous page', 'forms-notifier-for-elementor' ), disabled: opts.page <= 1, onClick: () => opts.onPage( opts.page - 1 ) } ),
				/* translators: %1$s: current page, %2$s: number of pages */
				h( 'span', null, sprintf( __( 'Page %1$s of %2$s', 'forms-notifier-for-elementor' ), fmt.number( opts.page ), fmt.number( pages ) ) ),
				IconButton( { icon: 'chevron-end', label: __( 'Next page', 'forms-notifier-for-elementor' ), disabled: opts.page >= pages, onClick: () => opts.onPage( opts.page + 1 ) } )
			) : null
		);
	}

	/** Collapsible block. opts: title, open, badge, desc. */
	function Fold( opts, ...children ) {
		const id = nextId( 'fold' );
		const body = h( 'div', { class: 'fnfe-fold__body', id: id, hidden: ! opts.open }, children );
		const button = h(
			'button',
			{
				type: 'button',
				class: 'fnfe-fold__head',
				'aria-expanded': !! opts.open,
				'aria-controls': id,
				onClick() {
					const open = body.hidden;
					body.hidden = ! open;
					button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
					if ( opts.onToggle ) {
						opts.onToggle( open, body );
					}
				},
			},
			opts.lead || null,
			h( 'span', { class: 'fnfe-fold__titles' }, h( 'span', { class: 'fnfe-fold__title' }, opts.title ), opts.desc ? h( 'span', { class: 'fnfe-fold__desc' }, opts.desc ) : null ),
			opts.badge || null,
			h( 'span', { class: 'fnfe-fold__arrow' }, icon( 'chevron-down', 18 ) )
		);
		const el = h( 'div', { class: [ 'fnfe-fold', opts.class || '' ] }, button, body );
		el.body = body;
		el.open = () => {
			if ( body.hidden ) {
				button.click();
			}
		};
		return el;
	}

	/** Reorder helper: up and down buttons for a list. */
	function MoveButtons( index, length, move ) {
		return [
			IconButton( { icon: 'chevron-up', size: 's', label: __( 'Move up', 'forms-notifier-for-elementor' ), disabled: index === 0, onClick: () => move( index, index - 1 ) } ),
			IconButton( { icon: 'chevron-down', size: 's', label: __( 'Move down', 'forms-notifier-for-elementor' ), disabled: index === length - 1, onClick: () => move( index, index + 1 ) } ),
		];
	}

	/** Make the children of a list draggable by a handle. move( from, to ). */
	function sortable( list, selector, handle, move ) {
		let from = -1;
		const items = () => Array.from( list.children ).filter( ( child ) => child.matches( selector ) );
		items().forEach( ( item, i ) => {
			const grip = item.querySelector( handle );
			if ( ! grip ) {
				return;
			}
			grip.addEventListener( 'mousedown', () => {
				item.draggable = true;
			} );
			item.addEventListener( 'mouseup', () => {
				item.draggable = false;
			} );
			item.addEventListener( 'dragstart', ( event ) => {
				from = i;
				item.classList.add( 'is-dragging' );
				event.dataTransfer.effectAllowed = 'move';
				event.dataTransfer.setData( 'text/plain', String( i ) );
			} );
			item.addEventListener( 'dragend', () => {
				item.draggable = false;
				item.classList.remove( 'is-dragging' );
				items().forEach( ( other ) => other.classList.remove( 'is-over' ) );
				from = -1;
			} );
			item.addEventListener( 'dragover', ( event ) => {
				if ( from > -1 ) {
					event.preventDefault();
					items().forEach( ( other ) => other.classList.toggle( 'is-over', other === item && i !== from ) );
				}
			} );
			item.addEventListener( 'drop', ( event ) => {
				if ( from > -1 && from !== i ) {
					event.preventDefault();
					move( from, i );
				}
			} );
		} );
	}

	const moved = ( array, from, to ) => {
		const out = array.slice();
		out.splice( to, 0, out.splice( from, 1 )[ 0 ] );
		return out;
	};

	/** Speech bubble crossed by an arrow. */
	const Mark = ( size ) => h( 'svg', { viewBox: '0 0 32 32', width: size || 28, height: size || 28, fill: 'none', 'aria-hidden': 'true', focusable: 'false' }, h( 'path', { d: 'M27 16.5V8a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v11a4 4 0 0 0 4 4h1v5l5.5-5', stroke: 'currentColor', 'stroke-width': 2.4, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' } ), h( 'path', { d: 'M11.5 13.5 28 24m0 0-6.5.8M28 24l-2.8-6', class: 'fnfe-mark__arrow', 'stroke-width': 2.4, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' } ) );

	Object.assign( window.fnfe.ui, {
		id: nextId,
		root, isRtl, focusables,
		Button, IconButton, CopyButton, copyText, Switch, Segmented, Tabs, LangTabs, multilingual, langDir,
		Badge, Status, statusLabel, Dot, Pips, mono, tx, raw, Tile, ChannelChip, channelMeta, channelLabel,
		Callout, Card, Empty, Skeleton, ErrorState, fail, Time, Code, Result,
		Modal, Sheet, closeAll, confirm, popover, Menu,
		Combobox, Select, Search, Chips, DateRange,
		Table, BulkBar, Pagination, Fold, MoveButtons, sortable, moved, announce, Mark,
	} );
}() );
