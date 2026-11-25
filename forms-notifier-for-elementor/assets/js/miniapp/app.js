/**
 * Mini app: digest, forms, submissions, delivery problems.
 */
( function () {
	'use strict';

	const { __, _x, _n, sprintf } = wp.i18n;
	const fnfe = window.fnfe;
	const { h, mount, api, fmt } = fnfe;
	const host = fnfe.host;
	const boot = fnfe.boot || {};
	const rtl = document.documentElement.dir === 'rtl' || !! ( boot.site && boot.site.rtl );
	const SESSION_KEY = 'fnfe_app_session';

	const session = { token: '', expires: 0, perms: [], user: null, site: null };
	const stack = [];
	let root = null;
	let stage = null;
	let nav = null;
	let live = null;

	/* ---------------------------------------------------------------- Icons */

	const SHAPES = {
		home: [ 'M4 11.5 12 5l8 6.5', 'M6.5 10v9h11v-9' ],
		forms: [ 'M6 4h12v16H6z', 'M9 9h6', 'M9 13h6', 'M9 17h3' ],
		alert: [ 'M12 4.5 21 19.5H3z', 'M12 10v4.5', 'M12 17.2v.1' ],
		back: [ 'M14.5 6 8.5 12l6 6' ],
		next: [ 'M9.5 6l6 6-6 6' ],
		refresh: [ 'M19 12a7 7 0 1 1-2.1-5', 'M19 4v4h-4' ],
		search: [ 'M11 5a6 6 0 1 1 0 12 6 6 0 0 1 0-12z', 'M15.5 15.5 20 20' ],
		check: [ 'M5 12.5l4.5 4.5L19 7.5' ],
		done: [ 'M3 12.5l4 4 8-9', 'M12 15.5l1.5 1L21 8' ],
		spam: [ 'M12 4a8 8 0 1 1 0 16 8 8 0 0 1 0-16z', 'M6.4 6.4l11.2 11.2' ],
		clock: [ 'M12 4a8 8 0 1 1 0 16 8 8 0 0 1 0-16z', 'M12 8v4.5l3 2' ],
		close: [ 'M6.5 6.5l11 11', 'M17.5 6.5l-11 11' ],
		file: [ 'M7 4h7l4 4v12H7z', 'M14 4v4h4' ],
		image: [ 'M4 6h16v12H4z', 'M4 16l5-5 4 4 2.5-2.5L20 17', 'M15.5 9.5v.1' ],
		phone: [ 'M7 4h3l1.5 4-2 1.5a10 10 0 0 0 5 5l1.5-2 4 1.5v3a2 2 0 0 1-2.2 2A15.5 15.5 0 0 1 5 6.2 2 2 0 0 1 7 4z' ],
		mail: [ 'M4 6h16v12H4z', 'M4 7l8 6 8-6' ],
		link: [ 'M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1', 'M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1' ],
		clip: [ 'M18 11.5 11.5 18a4 4 0 0 1-5.7-5.7l7-7a2.7 2.7 0 0 1 3.9 3.9l-6.9 6.8a1.3 1.3 0 0 1-1.9-1.9l6-6' ],
		lock: [ 'M6 11h12v9H6z', 'M8.5 11V8a3.5 3.5 0 0 1 7 0v3' ],
		bot: [ 'M5 9h14v10H5z', 'M12 5v4', 'M9.5 13.5v.1', 'M14.5 13.5v.1', 'M9.5 16.3h5' ],
		inbox: [ 'M4 13l3-8h10l3 8v6H4z', 'M4 13h5l1.5 2.5h3L15 13h5' ],
		send: [ 'M4 12l16-7-5 15-3.5-6z', 'M11.5 14 20 5' ],
		undo: [ 'M8 6 4 10l4 4', 'M4 10h9a5 5 0 0 1 0 10h-2' ],
		dot: [ 'M12 9a3 3 0 1 1 0 6 3 3 0 0 1 0-6z' ],
	};

	/* Icons that point in the reading direction. */
	const MIRRORED = [ 'back', 'next', 'send', 'undo' ];

	function icon( name, size ) {
		return h(
			'svg',
			{
				viewBox: '0 0 24 24',
				width: size || 20,
				height: size || 20,
				fill: 'none',
				stroke: 'currentColor',
				'stroke-width': 1.8,
				'stroke-linecap': 'round',
				'stroke-linejoin': 'round',
				'aria-hidden': 'true',
				focusable: 'false',
				class: { 'fnfe-mini-icon': true, 'is-mirrored': MIRRORED.indexOf( name ) > -1 },
			},
			( SHAPES[ name ] || SHAPES.dot ).map( ( d ) => h( 'path', { d: d } ) )
		);
	}

	/* --------------------------------------------------------------- Colours */

	function rgb( hex ) {
		const n = parseInt( hex.slice( 1 ), 16 );
		return [ n >> 16, ( n >> 8 ) & 255, n & 255 ];
	}

	function mix( from, to, share ) {
		const a = rgb( from );
		const b = rgb( to );
		return '#' + a.map( ( value, i ) => Math.round( value + ( b[ i ] - value ) * share ).toString( 16 ).padStart( 2, '0' ) ).join( '' );
	}

	function luminance( hex ) {
		const parts = rgb( hex ).map( ( value ) => {
			const c = value / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
		} );
		return 0.2126 * parts[ 0 ] + 0.7152 * parts[ 1 ] + 0.0722 * parts[ 2 ];
	}

	function contrast( a, b ) {
		const x = luminance( a );
		const y = luminance( b );
		return ( Math.max( x, y ) + 0.05 ) / ( Math.min( x, y ) + 0.05 );
	}

	/* Moves a colour towards another one until it reads on every background. */
	function readable( color, backgrounds, towards ) {
		let out = color;
		for ( let i = 1; i <= 20 && backgrounds.some( ( bg ) => contrast( out, bg ) < 4.5 ); i++ ) {
			out = mix( color, towards, i / 20 );
		}
		return out;
	}

	function applyTheme() {
		const body = document.body;
		const theme = host.themeParams;
		const known = host.available || Object.keys( theme ).length > 0;
		body.dataset.theme = known ? host.colorScheme : 'auto';

		if ( ! theme.bg_color || ! theme.text_color ) {
			return;
		}
		const ink = theme.text_color;
		const bg = theme.bg_color;
		const page = theme.secondary_bg_color || mix( bg, ink, 0.05 );
		const card = theme.section_bg_color || ( page === bg ? mix( bg, ink, 0.05 ) : bg );
		// Cards must stand out from the page, whichever pair the client sends.
		const base = card === page ? ( bg === card ? mix( bg, ink, 0.06 ) : bg ) : page;
		const grounds = [ card, base ];
		const accent = readable( theme.link_color || theme.accent_text_color || theme.button_color || ink, grounds, ink );
		const button = theme.button_color || accent;
		const black = '#000000';
		const white = '#ffffff';
		const onButton = theme.button_text_color || ( contrast( button, white ) > contrast( button, black ) ? white : black );
		const tokens = {
			'--fnfe-bg': bg,
			'--fnfe-surface': card,
			'--fnfe-surface-2': base,
			'--fnfe-surface-3': mix( card, ink, 0.08 ),
			'--fnfe-ink': ink,
			'--fnfe-ink-2': readable( mix( ink, theme.hint_color || mix( ink, card, 0.5 ), 0.6 ), grounds, ink ),
			'--fnfe-ink-3': readable( theme.hint_color || mix( ink, card, 0.45 ), grounds, ink ),
			'--fnfe-line': mix( card, ink, 0.12 ),
			'--fnfe-line-strong': mix( card, ink, 0.24 ),
			'--fnfe-accent': accent,
			'--fnfe-accent-hover': mix( accent, ink, 0.2 ),
			'--fnfe-accent-soft': mix( card, accent, 0.14 ),
			'--fnfe-accent-ink': onButton,
			'--fnfe-mini-button': readable( button, [ onButton ], luminance( onButton ) > 0.5 ? black : white ),
			'--fnfe-focus': '0 0 0 3px ' + mix( card, accent, 0.5 ),
		};
		if ( theme.destructive_text_color ) {
			tokens[ '--fnfe-danger' ] = readable( theme.destructive_text_color, grounds, ink );
			tokens[ '--fnfe-danger-soft' ] = mix( card, theme.destructive_text_color, 0.14 );
		}
		Object.keys( tokens ).forEach( ( name ) => body.style.setProperty( name, tokens[ name ] ) );
		host.setHeaderColor( base );
		host.setBackgroundColor( base );
	}

	/* --------------------------------------------------------------- Session */

	function launchKey() {
		const match = /(?:^|&)hash=([a-f0-9]{16})/i.exec( host.initData );
		return match ? match[ 1 ] : '';
	}

	function restoreSession() {
		try {
			const saved = JSON.parse( window.sessionStorage.getItem( SESSION_KEY ) || 'null' );
			if ( saved && saved.key === launchKey() && saved.platform === host.platform && saved.expires * 1000 > Date.now() + 30000 && Array.isArray( saved.perms ) ) {
				useSession( saved );
				return true;
			}
		} catch ( e ) {
			return false;
		}
		return false;
	}

	function useSession( data ) {
		session.token = data.token;
		session.expires = data.expires;
		session.perms = data.perms || [];
		session.user = data.user || null;
		session.site = data.site || null;
		api.setToken( data.token );
	}

	function saveSession() {
		try {
			window.sessionStorage.setItem( SESSION_KEY, JSON.stringify( Object.assign( { key: launchKey(), platform: host.platform }, session ) ) );
		} catch ( e ) {
			// Blocked storage: the session lives in memory.
		}
	}

	function dropSession() {
		session.token = '';
		api.setToken( '' );
		try {
			window.sessionStorage.removeItem( SESSION_KEY );
		} catch ( e ) {
			// Nothing to remove.
		}
	}

	let signingIn = null;

	function signIn() {
		if ( ! signingIn ) {
			dropSession();
			signingIn = api.post( 'app/auth', { platform: host.platform, init_data: host.initData } ).then( ( data ) => {
				useSession( data );
				saveSession();
				signingIn = null;
				return data;
			}, ( error ) => {
				signingIn = null;
				throw error;
			} );
		}
		return signingIn;
	}

	/* One silent sign-in when the session ended; after that the person has to reopen the app. */
	function call( run ) {
		return run().catch( ( error ) => {
			if ( error && error.status === 401 ) {
				return signIn().then( run ).catch( ( second ) => {
					if ( second && ( second.status === 401 || second.code === 'fnfe_app_not_allowed' || second.code === 'fnfe_app_disabled' ) ) {
						gateFor( second );
						throw Object.assign( {}, second, { handled: true } );
					}
					throw second;
				} );
			}
			if ( error && error.code === 'fnfe_app_disabled' ) {
				gateFor( error );
				throw Object.assign( {}, error, { handled: true } );
			}
			throw error;
		} );
	}

	const get = ( path, params ) => call( () => api.get( path, params ) );
	const send = ( path, body ) => call( () => api.post( path, body ) );
	const can = ( perm ) => session.perms.indexOf( perm ) > -1;

	/* --------------------------------------------------------------- Helpers */

	function timeLabel( iso ) {
		const date = new Date( iso );
		if ( ! iso || isNaN( date.getTime() ) ) {
			return '';
		}
		return Date.now() - date.getTime() < 6 * 86400000 ? fmt.relative( iso ) : fmt.date( iso, { day: 'numeric', month: 'short', year: 'numeric' } );
	}

	function dayLabel( day, long ) {
		// Noon keeps the calendar day in every time zone.
		const date = new Date( day + 'T12:00:00Z' );
		return isNaN( date.getTime() ) ? String( day ) : fmt.date( date, Object.assign( { day: 'numeric', month: 'short', timeZone: 'UTC' }, long ? { weekday: 'long' } : {} ) );
	}

	function announce( text ) {
		if ( live ) {
			live.textContent = text;
		}
	}

	function problem( error ) {
		return ( error && error.message ) || __( 'Something went wrong.', 'forms-notifier-for-elementor' );
	}

	let lastToast = null;

	/* One message at a time: the space above the navigation is small. */
	function notify( text, type, options ) {
		if ( lastToast ) {
			lastToast.close();
		}
		lastToast = fnfe.toast( text, type, options );
	}

	function copyText( text ) {
		const done = () => notify( __( 'Copied', 'forms-notifier-for-elementor' ), 'success', { timeout: 2000 } );
		const fallback = () => {
			const area = h( 'textarea', { value: text, readonly: true, class: 'fnfe-mini-offscreen' } );
			document.body.appendChild( area );
			area.select();
			let copied = false;
			try {
				copied = document.execCommand( 'copy' );
			} catch ( e ) {
				copied = false;
			}
			area.remove();
			if ( copied ) {
				done();
			}
		};
		if ( window.navigator.clipboard && window.navigator.clipboard.writeText ) {
			window.navigator.clipboard.writeText( text ).then( done, fallback );
		} else {
			fallback();
		}
	}

	function external( url, props ) {
		return Object.assign( {
			href: url,
			target: '_blank',
			rel: 'noopener noreferrer',
			onClick( event ) {
				event.preventDefault();
				host.openLink( url );
			},
		}, props || {} );
	}

	/* Addresses, numbers and codes read left to right in every language. */
	function technical( text, className ) {
		return h( 'bdi', { dir: /^[\x20-\x7e]+$/.test( text ) ? 'ltr' : 'auto', class: className || '' }, text );
	}

	/* Keeps phone numbers inside a sentence in their own order: the plus sign stays in front. */
	function isolateNumbers( text ) {
		return String( text ).replace( /\+?\d[\d ()-]{5,}\d/g, '\u2066$&\u2069' );
	}

	/* First letter of a name, for the tile in front of it. */
	function initial( name ) {
		return Array.from( String( name ).replace( /^[^\p{L}\p{N}]+/u, '' ) )[ 0 ] || '#';
	}

	function setBusy( button, busy ) {
		button.disabled = busy;
		button.classList.toggle( 'is-busy', busy );
		button.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
	}

	const STATUS = {
		new: { icon: 'dot', tone: 'accent', label: () => _x( 'New', 'submission status', 'forms-notifier-for-elementor' ) },
		read: { icon: 'check', tone: 'muted', label: () => _x( 'Read', 'submission status', 'forms-notifier-for-elementor' ) },
		done: { icon: 'done', tone: 'ok', label: () => _x( 'Done', 'submission status', 'forms-notifier-for-elementor' ) },
		spam: { icon: 'spam', tone: 'warn', label: () => _x( 'Spam', 'submission status', 'forms-notifier-for-elementor' ) },
	};

	const DELIVERY = {
		none: { icon: 'dot', tone: 'muted', label: () => __( 'Nothing to send', 'forms-notifier-for-elementor' ) },
		queued: { icon: 'clock', tone: 'info', label: () => __( 'In queue', 'forms-notifier-for-elementor' ) },
		sent: { icon: 'check', tone: 'ok', label: () => _x( 'Sent', 'message status', 'forms-notifier-for-elementor' ) },
		partial: { icon: 'alert', tone: 'warn', label: () => __( 'Partly sent', 'forms-notifier-for-elementor' ) },
		failed: { icon: 'close', tone: 'danger', label: () => _x( 'Failed', 'message status', 'forms-notifier-for-elementor' ) },
	};

	const MESSAGE = {
		queued: DELIVERY.queued,
		sending: { icon: 'send', tone: 'info', label: () => __( 'Sending', 'forms-notifier-for-elementor' ) },
		sent: DELIVERY.sent,
		retry: { icon: 'clock', tone: 'warn', label: () => __( 'Will try again', 'forms-notifier-for-elementor' ) },
		failed: DELIVERY.failed,
		skipped: { icon: 'dot', tone: 'muted', label: () => _x( 'Skipped', 'message status', 'forms-notifier-for-elementor' ) },
		cancelled: { icon: 'spam', tone: 'muted', label: () => _x( 'Cancelled', 'message status', 'forms-notifier-for-elementor' ) },
	};

	function badge( map, key ) {
		const def = map[ key ] || { icon: 'dot', tone: 'muted', label: () => key };
		return h( 'span', { class: 'fnfe-mini-badge', dataset: { tone: def.tone } }, icon( def.icon, 14 ), h( 'span', null, def.label() ) );
	}

	/* A small status mark whose shape carries the meaning as well. */
	function mark( map, key ) {
		const def = map[ key ] || { icon: 'dot', tone: 'muted', label: () => key };
		return h( 'span', { class: 'fnfe-mini-mark', dataset: { tone: def.tone }, role: 'img', 'aria-label': def.label() }, icon( def.icon, 16 ) );
	}

	function button( label, options ) {
		const o = options || {};
		return h(
			'button',
			{ type: 'button', class: [ 'fnfe-mini-button', o.kind ? 'is-' + o.kind : '' ], onClick: o.onClick, 'aria-pressed': o.pressed },
			o.icon ? icon( o.icon, 18 ) : null,
			h( 'span', null, label )
		);
	}

	function iconButton( name, label, onClick ) {
		return h( 'button', { type: 'button', class: 'fnfe-mini-iconbutton', 'aria-label': label, onClick: onClick }, icon( name, 22 ) );
	}

	function card( title, content, action ) {
		return h(
			'section',
			{ class: 'fnfe-mini-section' },
			title ? h( 'div', { class: 'fnfe-mini-section__head' }, h( 'h2', null, title ), action || null ) : null,
			h( 'div', { class: 'fnfe-mini-card' }, content )
		);
	}

	function skeleton( rows, kind ) {
		const out = [];
		for ( let i = 0; i < rows; i++ ) {
			out.push( h( 'div', { class: [ 'fnfe-mini-skeleton', kind ? 'is-' + kind : '' ] }, h( 'span', null ), h( 'span', null ) ) );
		}
		return h( 'div', { class: 'fnfe-mini-loading', 'aria-busy': 'true' }, h( 'span', { class: 'fnfe-mini-sr', role: 'status' }, __( 'Loading', 'forms-notifier-for-elementor' ) ), out );
	}

	function emptyState( name, text ) {
		return h( 'div', { class: 'fnfe-mini-empty' }, h( 'span', { class: 'fnfe-mini-empty__icon' }, icon( name, 28 ) ), h( 'p', null, text ) );
	}

	function errorState( error, retry ) {
		return h(
			'div',
			{ class: 'fnfe-mini-empty', role: 'alert' },
			h( 'span', { class: 'fnfe-mini-empty__icon', dataset: { tone: 'danger' } }, icon( 'alert', 28 ) ),
			h( 'p', null, problem( error ) ),
			retry ? button( __( 'Try again', 'forms-notifier-for-elementor' ), { icon: 'refresh', onClick: retry } ) : null
		);
	}

	function header( title, options ) {
		const o = options || {};
		return h(
			'header',
			{ class: 'fnfe-mini-header' },
			ownBack() && stack.length > 0 && ! o.home ? iconButton( 'back', __( 'Back', 'forms-notifier-for-elementor' ), pop ) : null,
			o.lead || null,
			h( 'div', { class: 'fnfe-mini-header__text' }, h( 'h1', { tabindex: '-1', dir: 'auto' }, title ), o.sub || null ),
			o.tail || null
		);
	}

	function segmented( options, value, label, onChange ) {
		const buttons = options.map( ( option ) => h( 'button', {
			type: 'button',
			'aria-pressed': option.value === value ? 'true' : 'false',
			dataset: { value: option.value },
			onClick() {
				buttons.forEach( ( item ) => item.setAttribute( 'aria-pressed', item.dataset.value === option.value ? 'true' : 'false' ) );
				host.haptic( 'selection' );
				onChange( option.value );
			},
		}, option.label ) );
		return buttons;
	}

	/* ----------------------------------------------------------- Paged lists */

	/**
	 * Infinite list. load( page ) resolves with the list envelope, row( item ) returns a node.
	 */
	function pagedList( options ) {
		const list = h( 'ul', { class: 'fnfe-mini-list' } );
		const tail = h( 'div', { class: 'fnfe-mini-tail' } );
		const el = h( 'div', null, list, tail );
		let page = 0;
		let total = 0;
		let count = 0;
		let busy = false;
		let run = 0;
		let observer = null;

		function finish() {
			if ( observer ) {
				observer.disconnect();
				observer = null;
			}
		}

		function more() {
			if ( busy ) {
				return;
			}
			busy = true;
			const mine = run;
			const next = page + 1;
			finish();
			mount( tail, skeleton( next === 1 ? 5 : 2, 'row' ) );
			list.hidden = count === 0;
			options.load( next ).then( ( data ) => {
				if ( mine !== run ) {
					return;
				}
				busy = false;
				page = next;
				total = data.total;
				count += data.items.length;
				data.items.forEach( ( item ) => list.appendChild( h( 'li', null, options.row( item ) ) ) );
				list.hidden = count === 0;
				if ( options.onLoad ) {
					options.onLoad( data );
				}
				if ( count === 0 ) {
					mount( tail, options.empty() );
				} else if ( count < total && data.items.length ) {
					const trigger = button( __( 'Load more', 'forms-notifier-for-elementor' ), { onClick: more } );
					mount( tail, trigger );
					if ( 'IntersectionObserver' in window ) {
						observer = new window.IntersectionObserver( ( entries ) => {
							if ( entries.some( ( entry ) => entry.isIntersecting ) ) {
								more();
							}
						}, { rootMargin: '240px' } );
						observer.observe( trigger );
					}
				} else {
					mount( tail, null );
				}
			}, ( error ) => {
				if ( mine !== run || error.handled ) {
					return;
				}
				busy = false;
				mount( tail, errorState( error, more ) );
			} );
		}

		function reload() {
			run++;
			busy = false;
			page = 0;
			total = 0;
			count = 0;
			finish();
			mount( list, null );
			more();
		}

		return { el: el, reload: reload, destroy: finish };
	}

	function searchField( label, onChange ) {
		const run = fnfe.debounce( ( value ) => onChange( value ), 350 );
		return h( 'label', { class: 'fnfe-mini-search' }, icon( 'search', 18 ), h( 'input', {
			type: 'search',
			placeholder: label,
			'aria-label': label,
			enterkeyhint: 'search',
			autocomplete: 'off',
			dir: 'auto',
			maxlength: 100,
			onInput: ( event ) => run( event.target.value.trim() ),
		} ) );
	}

	/* --------------------------------------------------------- Screen: home */

	function siteHost() {
		try {
			return new URL( ( session.site && session.site.url ) || window.location.href ).hostname;
		} catch ( e ) {
			return '';
		}
	}

	function homeScreen() {
		const body = h( 'div', null );
		const pill = h( 'span', { class: 'fnfe-mini-pillwrap' } );
		const name = ( session.site && session.site.name ) || ( boot.site && boot.site.name ) || siteHost();
		let range = '7d';
		let run = 0;

		function load( quiet ) {
			const mine = ++run;
			if ( ! quiet || ! body.firstChild ) {
				mount( body, fnfe.frag( skeleton( 1, 'hero' ), skeleton( 4, 'row' ) ) );
			}
			return Promise.all( [ get( 'app/digest', { range: range } ), get( 'app/forms', { page: 1 } ) ] ).then( ( data ) => {
				if ( mine === run ) {
					mount( pill, statusPill( data[ 0 ].health, data[ 0 ].stats ) );
					mount( body, homeBody( data[ 0 ], data[ 1 ] ) );
				}
			}, ( error ) => {
				if ( mine === run && ! error.handled ) {
					mount( body, errorState( error, () => load() ) );
				}
			} );
		}

		const el = h(
			'div',
			{ class: 'fnfe-mini-view' },
			header( name, {
				home: true,
				lead: h( 'span', { class: 'fnfe-mini-avatar', 'aria-hidden': 'true' }, initial( name ) ),
				sub: h( 'p', { class: 'fnfe-mini-sub' }, h( 'bdi', { dir: 'ltr' }, siteHost() ) ),
				tail: pill,
			} ),
			h( 'div', { class: 'fnfe-mini-segmented', role: 'group', 'aria-label': __( 'Period', 'forms-notifier-for-elementor' ) }, segmented( [
				{ value: 'today', label: __( 'Today', 'forms-notifier-for-elementor' ) },
				{ value: '7d', label: __( '7 days', 'forms-notifier-for-elementor' ) },
				{ value: '30d', label: __( '30 days', 'forms-notifier-for-elementor' ) },
			], range, '', ( value ) => {
				range = value;
				load();
			} ) ),
			body
		);
		load();
		return { el: el, title: name, tab: 'home', refresh: () => load( true ) };
	}

	function worst( health, failed ) {
		if ( health.some( ( item ) => item.status === 'critical' ) ) {
			return 'critical';
		}
		return failed || health.some( ( item ) => item.status === 'warning' ) ? 'warning' : 'good';
	}

	function statusPill( health, stats ) {
		const state = worst( health, stats.messages.failed > 0 );
		const tone = { good: 'ok', warning: 'warn', critical: 'danger' }[ state ];
		const text = {
			good: __( 'All good', 'forms-notifier-for-elementor' ),
			warning: __( 'Check', 'forms-notifier-for-elementor' ),
			critical: __( 'Problem', 'forms-notifier-for-elementor' ),
		}[ state ];
		return h( 'span', { class: 'fnfe-mini-badge', dataset: { tone: tone } }, icon( state === 'good' ? 'check' : 'alert', 14 ), h( 'span', null, text ) );
	}

	function homeBody( digest, forms ) {
		return fnfe.frag(
			heroCard( digest ),
			h(
				'div',
				{ class: 'fnfe-mini-tiles' },
				tile( _x( 'Sent', 'message status', 'forms-notifier-for-elementor' ), digest.stats.messages.sent, 'ok', 'check' ),
				tile( _x( 'Failed', 'message status', 'forms-notifier-for-elementor' ), digest.stats.messages.failed, 'danger', 'close', digest.stats.messages.failed > 0 ? () => push( 'problems' ) : null ),
				tile( __( 'In queue', 'forms-notifier-for-elementor' ), digest.queue.pending, 'info', 'clock' )
			),
			attentionCard( digest ),
			formsCard( forms ),
			channelsCard( digest ),
			siteCard( digest.site )
		);
	}

	function tile( label, value, tone, name, onClick ) {
		const content = [
			h( 'span', { class: 'fnfe-mini-tile__label' }, h( 'span', { class: 'fnfe-mini-tile__icon', dataset: { tone: value ? tone : 'muted' } }, icon( name, 14 ) ), label ),
			h( 'strong', null, fmt.number( value ) ),
		];
		return onClick
			? h( 'button', { type: 'button', class: 'fnfe-mini-tile', onClick: onClick }, content )
			: h( 'div', { class: 'fnfe-mini-tile' }, content );
	}

	function heroCard( digest ) {
		const total = digest.stats.submissions;
		const unread = digest.site.unread;
		return h(
			'section',
			{ class: 'fnfe-mini-card fnfe-mini-hero' },
			h(
				'div',
				{ class: 'fnfe-mini-hero__top' },
				h( 'div', null, h( 'h2', null, __( 'Submissions', 'forms-notifier-for-elementor' ) ), h( 'strong', { class: 'fnfe-mini-hero__value' }, fmt.number( total ) ) ),
				unread > 0 ? h( 'span', { class: 'fnfe-mini-badge', dataset: { tone: 'accent' } }, sprintf(
					/* translators: %s: number of unread submissions */
					_n( '%s unread', '%s unread', unread, 'forms-notifier-for-elementor' ),
					fmt.number( unread )
				) ) : null
			),
			sparkline( digest.stats.series )
		);
	}

	/* Bars per day. One series, so the title of the card names it. */
	function sparkline( series ) {
		if ( ! Array.isArray( series ) || series.length < 2 ) {
			return null;
		}
		const width = 320;
		const height = 64;
		const band = width / series.length;
		const bar = Math.min( 24, Math.max( 3, band - ( series.length > 14 ? 3 : 10 ) ) );
		const max = Math.max( 1, Math.max.apply( null, series.map( ( day ) => day.submissions ) ) );
		const readout = h( 'span', { class: 'fnfe-mini-chart__value' } );
		const groups = series.map( ( day, i ) => {
			const x = ( rtl ? series.length - 1 - i : i ) * band + ( band - bar ) / 2;
			const size = day.submissions > 0 ? Math.max( 4, day.submissions / max * height ) : 2;
			const r = Math.min( 4, bar / 2, size );
			const y = height - size;
			const shape = 'M' + x + ' ' + height + 'V' + ( y + r ) + 'Q' + x + ' ' + y + ' ' + ( x + r ) + ' ' + y + 'H' + ( x + bar - r ) + 'Q' + ( x + bar ) + ' ' + y + ' ' + ( x + bar ) + ' ' + ( y + r ) + 'V' + height + 'Z';
			return h(
				'g',
				{ class: { 'fnfe-mini-bar': true, 'is-zero': ! day.submissions } },
				h( 'path', { d: shape } ),
				h( 'rect', { x: ( rtl ? series.length - 1 - i : i ) * band, y: 0, width: band, height: height, fill: 'transparent', onClick: () => pick( i ), onPointerEnter: () => pick( i ) } )
			);
		} );

		function pick( index ) {
			groups.forEach( ( group, i ) => group.classList.toggle( 'is-active', i === index ) );
			readout.textContent = sprintf(
				/* translators: 1: day, 2: number of submissions */
				__( '%1$s: %2$s', 'forms-notifier-for-elementor' ),
				dayLabel( series[ index ].date ),
				fmt.number( series[ index ].submissions )
			);
		}

		const spoken = series.map( ( day ) => sprintf(
			/* translators: 1: day, 2: number of submissions */
			__( '%1$s: %2$s', 'forms-notifier-for-elementor' ),
			dayLabel( day.date, true ),
			fmt.number( day.submissions )
		) ).join( '; ' );

		return h(
			'div',
			{ class: 'fnfe-mini-chart', onPointerLeave() {
				groups.forEach( ( group ) => group.classList.remove( 'is-active' ) );
				readout.textContent = '';
			} },
			h( 'svg', { viewBox: '0 0 ' + width + ' ' + height, role: 'img', 'aria-label': __( 'Submissions per day', 'forms-notifier-for-elementor' ) + '. ' + spoken }, groups ),
			h( 'div', { class: 'fnfe-mini-chart__axis' }, h( 'span', null, dayLabel( series[ 0 ].date ) ), readout, h( 'span', null, dayLabel( series[ series.length - 1 ].date ) ) )
		);
	}

	function attentionCard( digest ) {
		const failed = digest.stats.messages.failed;
		const rows = [];
		if ( failed > 0 ) {
			rows.push( h( 'li', null, h(
				'button',
				{ type: 'button', class: 'fnfe-mini-row', onClick: () => push( 'problems' ) },
				h( 'span', { class: 'fnfe-mini-mark', dataset: { tone: 'danger' } }, icon( 'close', 16 ) ),
				h( 'span', { class: 'fnfe-mini-row__main' }, h( 'span', { class: 'fnfe-mini-row__title' }, sprintf(
					/* translators: %s: number of messages */
					_n( '%s message failed', '%s messages failed', failed, 'forms-notifier-for-elementor' ),
					fmt.number( failed )
				) ) ),
				icon( 'next', 18 )
			) ) );
		}
		// The row above already says it and opens the list.
		digest.health.filter( ( item ) => item.status !== 'good' && ! ( failed > 0 && item.id === 'failures' ) ).forEach( ( item ) => {
			rows.push( h( 'li', null, h(
				'div',
				{ class: 'fnfe-mini-row' },
				h( 'span', { class: 'fnfe-mini-mark', dataset: { tone: item.status === 'critical' ? 'danger' : 'warn' }, role: 'img', 'aria-label': item.status === 'critical' ? __( 'Problem', 'forms-notifier-for-elementor' ) : __( 'Warning', 'forms-notifier-for-elementor' ) }, icon( 'alert', 16 ) ),
				h( 'span', { class: 'fnfe-mini-row__main' }, h( 'span', { class: 'fnfe-mini-row__title', dir: 'auto' }, item.label ), item.message ? h( 'span', { class: 'fnfe-mini-row__text' }, h( 'bdi', null, item.message ) ) : null )
			) ) );
		} );
		if ( ! rows.length ) {
			rows.push( h( 'li', null, h(
				'div',
				{ class: 'fnfe-mini-row' },
				h( 'span', { class: 'fnfe-mini-mark', dataset: { tone: 'ok' } }, icon( 'check', 16 ) ),
				h( 'span', { class: 'fnfe-mini-row__main' }, h( 'span', { class: 'fnfe-mini-row__title' }, __( 'Everything works', 'forms-notifier-for-elementor' ) ), h( 'span', { class: 'fnfe-mini-row__text' }, __( 'No failed messages and no warnings.', 'forms-notifier-for-elementor' ) ) )
			) ) );
		}
		return card( __( 'Needs attention', 'forms-notifier-for-elementor' ), h( 'ul', { class: 'fnfe-mini-list' }, rows ) );
	}

	function formsCard( forms ) {
		const content = forms.items.length
			? h( 'ul', { class: 'fnfe-mini-list' }, forms.items.slice( 0, 5 ).map( ( form ) => h( 'li', null, formRow( form ) ) ) )
			: emptyState( 'forms', __( 'No form has been found on this site yet.', 'forms-notifier-for-elementor' ) );
		const all = forms.items.length ? h( 'button', { type: 'button', class: 'fnfe-mini-textbutton', onClick: () => push( 'forms' ) }, __( 'All forms', 'forms-notifier-for-elementor' ) ) : null;
		return card( __( 'Forms', 'forms-notifier-for-elementor' ), content, all );
	}

	function channelsCard( digest ) {
		const labels = {};
		digest.site.channels.forEach( ( item ) => {
			labels[ item.id ] = item;
		} );
		const counts = digest.stats.by_channel || {};
		const ids = Object.keys( Object.assign( {}, labels, counts ) );
		if ( ! ids.length ) {
			return null;
		}
		return card( __( 'Delivery', 'forms-notifier-for-elementor' ), h( 'ul', { class: 'fnfe-mini-list' }, ids.map( ( id ) => {
			const item = counts[ id ] || { sent: 0, failed: 0 };
			const sum = item.sent + item.failed;
			const broken = labels[ id ] && ! labels[ id ].ready;
			return h( 'li', null, h(
				'div',
				{ class: 'fnfe-mini-row is-channel' },
				h(
					'span',
					{ class: 'fnfe-mini-row__main' },
					h(
						'span',
						{ class: 'fnfe-mini-row__line' },
						h( 'span', { class: 'fnfe-mini-row__title' }, labels[ id ] ? labels[ id ].label : id ),
						broken ? h( 'span', { class: 'fnfe-mini-badge', dataset: { tone: 'warn' } }, icon( 'alert', 14 ), h( 'span', null, __( 'Not ready', 'forms-notifier-for-elementor' ) ) ) : null
					),
					sum ? h(
						'span',
						{ class: 'fnfe-mini-meter', 'aria-hidden': 'true' },
						item.sent ? h( 'i', { dataset: { tone: 'ok' }, style: { flexGrow: item.sent } } ) : null,
						item.failed ? h( 'i', { dataset: { tone: 'danger' }, style: { flexGrow: item.failed } } ) : null
					) : null,
					h(
						'span',
						{ class: 'fnfe-mini-row__text' },
						sum ? h( 'span', null, sprintf(
							/* translators: %s: number of messages */
							__( '%s sent', 'forms-notifier-for-elementor' ),
							fmt.number( item.sent )
						) ) : h( 'span', null, __( 'Nothing sent in this period', 'forms-notifier-for-elementor' ) ),
						item.failed ? h( 'span', { class: 'fnfe-mini-tone', dataset: { tone: 'danger' } }, sprintf(
							/* translators: %s: number of messages */
							__( '%s failed', 'forms-notifier-for-elementor' ),
							fmt.number( item.failed )
						) ) : null
					)
				)
			) );
		} ) ) );
	}

	function siteCard( site ) {
		const facts = [
			[ __( 'Saved submissions', 'forms-notifier-for-elementor' ), fmt.number( site.total ) ],
			[ __( 'Forms', 'forms-notifier-for-elementor' ), fmt.number( site.forms ) ],
			[ __( 'Pages', 'forms-notifier-for-elementor' ), fmt.number( site.pages ) ],
			[ __( 'Posts', 'forms-notifier-for-elementor' ), fmt.number( site.posts ) ],
			[ __( 'Users', 'forms-notifier-for-elementor' ), fmt.number( site.users ) ],
			[ __( 'Comments to review', 'forms-notifier-for-elementor' ), fmt.number( site.comments ) ],
			[ __( 'Updates available', 'forms-notifier-for-elementor' ), fmt.number( site.updates ) ],
			[ __( 'Theme', 'forms-notifier-for-elementor' ), site.theme ],
			[ 'WordPress', h( 'bdi', { dir: 'ltr' }, site.wp ) ],
			[ 'PHP', h( 'bdi', { dir: 'ltr' }, site.php ) ],
			[ __( 'Site time', 'forms-notifier-for-elementor' ), site.time ],
			[ 'Forms Notifier', h( 'bdi', { dir: 'ltr' }, site.plugin ) ],
		];
		return card( __( 'Site', 'forms-notifier-for-elementor' ), h( 'dl', { class: 'fnfe-mini-facts' }, facts.map( ( fact ) => h( 'div', null, h( 'dt', null, fact[ 0 ] ), h( 'dd', { dir: 'auto' }, fact[ 1 ] ) ) ) ) );
	}

	/* -------------------------------------------------------- Screen: forms */

	function formRow( form ) {
		const title = form.name || __( 'Untitled form', 'forms-notifier-for-elementor' );
		return h(
			'button',
			{ type: 'button', class: 'fnfe-mini-row', onClick: () => push( 'submissions', { id: form.id, name: title } ) },
			h( 'span', { class: [ 'fnfe-mini-glyph', form.active ? '' : 'is-off' ], 'aria-hidden': 'true' }, initial( title ) ),
			h(
				'span',
				{ class: 'fnfe-mini-row__main' },
				h( 'span', { class: 'fnfe-mini-row__title is-cut', dir: 'auto' }, title ),
				h(
					'span',
					{ class: 'fnfe-mini-row__text' },
					form.post_title ? h( 'span', { class: 'is-cut', dir: 'auto' }, form.post_title ) : null,
					form.last_submission ? h( 'span', { class: 'is-fixed' }, timeLabel( form.last_submission ) ) : null,
					form.active ? null : h( 'span', { class: 'is-fixed' }, _x( 'Off', 'form state', 'forms-notifier-for-elementor' ) )
				)
			),
			h(
				'span',
				{ class: 'fnfe-mini-row__end' },
				form.unread > 0 ? h( 'span', { class: 'fnfe-mini-count', 'aria-label': sprintf(
					/* translators: %s: number of unread submissions */
					_n( '%s unread', '%s unread', form.unread, 'forms-notifier-for-elementor' ),
					fmt.number( form.unread )
				) }, fmt.number( form.unread ) ) : null,
				h( 'span', { class: 'fnfe-mini-total', title: __( 'Submissions', 'forms-notifier-for-elementor' ) }, fmt.number( form.submissions_count ) )
			),
			icon( 'next', 18 )
		);
	}

	function formsScreen() {
		let search = '';
		const list = pagedList( {
			load: ( page ) => get( 'app/forms', { page: page, search: search } ),
			row: formRow,
			empty: () => emptyState( search ? 'search' : 'forms', search ? __( 'No form matches your search.', 'forms-notifier-for-elementor' ) : __( 'No form has been found on this site yet.', 'forms-notifier-for-elementor' ) ),
		} );
		const title = __( 'Forms', 'forms-notifier-for-elementor' );
		const el = h(
			'div',
			{ class: 'fnfe-mini-view' },
			header( title, { tail: iconButton( 'refresh', __( 'Refresh', 'forms-notifier-for-elementor' ), list.reload ) } ),
			searchField( __( 'Search forms', 'forms-notifier-for-elementor' ), ( value ) => {
				search = value;
				list.reload();
			} ),
			h( 'div', { class: 'fnfe-mini-card' }, list.el )
		);
		list.reload();
		return { el: el, title: title, tab: 'forms', refresh: list.reload, destroy: list.destroy };
	}

	/* -------------------------------------------------- Screen: submissions */

	function submissionRow( item ) {
		const el = h(
			'button',
			{ type: 'button', class: 'fnfe-mini-row is-submission', onClick: () => push( 'submission', { id: item.id } ) },
			h( 'span', { class: 'fnfe-mini-unread', role: 'img' } ),
			h(
				'span',
				{ class: 'fnfe-mini-row__main' },
				h( 'span', { class: 'fnfe-mini-row__title is-clamped', dir: 'auto' }, isolateNumbers( item.preview ) || __( 'Empty submission', 'forms-notifier-for-elementor' ) ),
				h(
					'span',
					{ class: 'fnfe-mini-row__text' },
					h( 'span', { class: 'is-fixed' }, timeLabel( item.created ) ),
					item.files_count ? h( 'span', { class: 'fnfe-mini-files' }, icon( 'clip', 14 ), h( 'span', null, fmt.number( item.files_count ) ) ) : null,
					h( 'span', { class: 'fnfe-mini-slot' } )
				)
			),
			item.delivery_status === 'none' ? null : mark( DELIVERY, item.delivery_status ),
			icon( 'next', 18 )
		);
		const paint = ( status ) => {
			const dot = el.querySelector( '.fnfe-mini-unread' );
			const label = ( STATUS[ status ] || STATUS.read ).label();
			el.dataset.status = status;
			dot.setAttribute( 'aria-label', label );
			mount( el.querySelector( '.fnfe-mini-slot' ), status === 'done' || status === 'spam' ? badge( STATUS, status ) : null );
		};
		el.dataset.id = item.id;
		el.fnfePaint = paint;
		paint( item.status );
		return el;
	}

	function submissionsScreen( params ) {
		let status = '';
		let search = '';
		const sub = h( 'p', { class: 'fnfe-mini-sub' } );
		const list = pagedList( {
			load: ( page ) => get( 'app/forms/' + params.id + '/submissions', { page: page, status: status, search: search } ),
			row: submissionRow,
			onLoad( data ) {
				sub.textContent = sprintf(
					/* translators: %s: number of submissions */
					_n( '%s submission', '%s submissions', data.total, 'forms-notifier-for-elementor' ),
					fmt.number( data.total )
				);
				if ( ! params.name && data.items.length && data.items[ 0 ].form_name ) {
					params.name = data.items[ 0 ].form_name;
					el.querySelector( 'h1' ).textContent = params.name;
				}
			},
			empty: () => emptyState( search ? 'search' : 'inbox', search || status ? __( 'Nothing matches this filter.', 'forms-notifier-for-elementor' ) : __( 'Nobody has sent this form yet.', 'forms-notifier-for-elementor' ) ),
		} );
		const title = params.name || __( 'Submissions', 'forms-notifier-for-elementor' );
		const el = h(
			'div',
			{ class: 'fnfe-mini-view' },
			header( title, { sub: sub, tail: iconButton( 'refresh', __( 'Refresh', 'forms-notifier-for-elementor' ), list.reload ) } ),
			searchField( __( 'Search submissions', 'forms-notifier-for-elementor' ), ( value ) => {
				search = value;
				list.reload();
			} ),
			h( 'div', { class: 'fnfe-mini-chips', role: 'group', 'aria-label': __( 'Status', 'forms-notifier-for-elementor' ) }, segmented( [
				{ value: '', label: _x( 'All', 'submissions', 'forms-notifier-for-elementor' ) },
				{ value: 'new', label: STATUS.new.label() },
				{ value: 'done', label: STATUS.done.label() },
				{ value: 'spam', label: STATUS.spam.label() },
			], status, '', ( value ) => {
				status = value;
				list.reload();
			} ) ),
			h( 'div', { class: 'fnfe-mini-card' }, list.el )
		);
		const off = fnfe.bus.on( 'miniapp:status', ( change ) => {
			const row = el.querySelector( '.is-submission[data-id="' + Number( change.id ) + '"]' );
			if ( row ) {
				row.fnfePaint( change.status );
			}
		} );
		list.reload();
		return { el: el, title: title, tab: 'forms', refresh: list.reload, destroy() {
			off();
			list.destroy();
		} };
	}

	/* --------------------------------------------------- Screen: submission */

	function longText( text ) {
		const value = h( 'div', { class: [ 'fnfe-mini-value', text.length < 60 && text.indexOf( '\n' ) < 0 ? 'is-short' : '' ], dir: 'auto' }, text );
		if ( text.length < 320 && text.split( '\n' ).length < 8 ) {
			return value;
		}
		value.classList.add( 'is-folded' );
		const toggle = h( 'button', { type: 'button', class: 'fnfe-mini-textbutton', 'aria-expanded': 'false', onClick() {
			const open = value.classList.toggle( 'is-folded' ) === false;
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			toggle.textContent = open ? __( 'Show less', 'forms-notifier-for-elementor' ) : __( 'Show all', 'forms-notifier-for-elementor' );
		} }, __( 'Show all', 'forms-notifier-for-elementor' ) );
		return fnfe.frag( value, toggle );
	}

	function fieldValue( field ) {
		const text = String( field.value || '' ).trim();
		if ( field.redacted ) {
			return h( 'div', { class: 'fnfe-mini-value is-muted' }, icon( 'lock', 14 ), h( 'span', null, __( 'Hidden', 'forms-notifier-for-elementor' ) ) );
		}
		if ( text === '' ) {
			return h( 'div', { class: 'fnfe-mini-value is-muted' }, _x( 'Empty', 'field value', 'forms-notifier-for-elementor' ) );
		}
		if ( field.type === 'email' && /^[^\s@]+@[^\s@]+$/.test( text ) ) {
			return h( 'a', { class: 'fnfe-mini-value is-link', href: 'mailto:' + encodeURIComponent( text ).replace( /%40/g, '@' ) }, icon( 'mail', 18 ), h( 'bdi', { dir: 'ltr' }, text ) );
		}
		const digits = text.replace( /[^\d+]/g, '' );
		if ( field.type === 'tel' && digits.length > 4 ) {
			return h( 'a', { class: 'fnfe-mini-value is-link', href: 'tel:' + digits }, icon( 'phone', 18 ), h( 'bdi', { dir: 'ltr' }, text ) );
		}
		if ( /^https?:\/\/\S+$/i.test( text ) ) {
			return h( 'a', external( text, { class: 'fnfe-mini-value is-link' } ), icon( 'link', 18 ), h( 'bdi', { dir: 'ltr' }, text ) );
		}
		if ( field.values.length > 1 ) {
			return h( 'ul', { class: 'fnfe-mini-tags' }, field.values.map( ( value ) => h( 'li', { dir: 'auto' }, String( value ) ) ) );
		}
		return longText( text );
	}

	function fileRow( file ) {
		const content = [
			h( 'span', { class: 'fnfe-mini-glyph', 'aria-hidden': 'true' }, icon( file.is_image ? 'image' : 'file', 20 ) ),
			h(
				'span',
				{ class: 'fnfe-mini-row__main' },
				h( 'span', { class: 'fnfe-mini-row__title is-cut', dir: 'auto' }, file.name ),
				h( 'span', { class: 'fnfe-mini-row__text' }, file.url ? fmt.bytes( file.size ) : __( 'This file is not stored any more.', 'forms-notifier-for-elementor' ) )
			),
		];
		return file.url
			? h( 'a', external( file.url, { class: 'fnfe-mini-row' } ), content, icon( 'next', 18 ) )
			: h( 'div', { class: 'fnfe-mini-row is-off' }, content );
	}

	function messageRow( message ) {
		return h(
			'div',
			{ class: 'fnfe-mini-problem' },
			messageHead( message, message.sent ? timeLabel( message.sent ) : '' ),
			message.error && message.status !== 'sent' ? h( 'p', { class: 'fnfe-mini-error', dir: 'auto' }, message.error ) : null
		);
	}

	function messageHead( message, time ) {
		return h(
			'div',
			{ class: 'fnfe-mini-problem__head' },
			h(
				'div',
				{ class: 'fnfe-mini-row__main' },
				h( 'span', { class: 'fnfe-mini-row__title' }, message.channel_label ),
				h( 'span', { class: 'fnfe-mini-row__text' }, technical( message.recipient_label || message.recipient, 'is-cut' ), time ? h( 'span', { class: 'is-fixed' }, time ) : null )
			),
			badge( MESSAGE, message.status )
		);
	}

	function submissionScreen( params ) {
		const body = h( 'div', null, skeleton( 6, 'field' ) );
		const bar = h( 'div', { class: 'fnfe-mini-actions', hidden: true, role: 'group', 'aria-label': __( 'Status', 'forms-notifier-for-elementor' ) } );
		const sub = h( 'div', { class: 'fnfe-mini-sub' } );
		const pill = h( 'span', { class: 'fnfe-mini-pillwrap' } );
		const el = h( 'div', { class: 'fnfe-mini-view has-actions' }, header( sprintf(
			/* translators: %s: submission number */
			__( 'Submission %s', 'forms-notifier-for-elementor' ),
			fmt.number( params.id )
		), { sub: sub, tail: pill } ), body, bar );
		let current = null;

		function load( quiet ) {
			if ( ! quiet ) {
				mount( body, skeleton( 6, 'field' ) );
			}
			return get( 'app/submissions/' + params.id ).then( ( data ) => {
				current = data.submission;
				paintHead();
				mount( body, content( data ) );
				paintActions();
			}, ( error ) => {
				if ( error.handled ) {
					return;
				}
				bar.hidden = true;
				mount( body, errorState( error, error.status === 404 ? null : () => load() ) );
			} );
		}

		function paintHead() {
			mount( sub, fnfe.frag( h( 'bdi', { class: 'is-cut' }, current.form_name ), h( 'span', { class: 'is-fixed' }, current.created_local ) ) );
			mount( pill, badge( STATUS, current.status ) );
		}

		function content( data ) {
			const s = data.submission;
			const fields = s.fields.filter( ( field ) => ! ( field.is_file && s.files.some( ( file ) => file.field_id === field.id ) ) );
			const failed = data.messages.filter( ( message ) => message.status === 'failed' || message.status === 'retry' );
			const meta = [
				s.meta.page_url && /^https?:\/\//i.test( s.meta.page_url ) ? [ __( 'Page', 'forms-notifier-for-elementor' ), h( 'a', external( s.meta.page_url, { class: 'fnfe-mini-value is-link', dir: 'auto' } ), s.meta.page_title || s.meta.page_url ) ] : null,
				[ _x( 'Time', 'submission', 'forms-notifier-for-elementor' ), s.created_local ],
				s.lang ? [ __( 'Language', 'forms-notifier-for-elementor' ), languageName( s.lang ) ] : null,
				s.meta.user_login ? [ _x( 'User', 'submission', 'forms-notifier-for-elementor' ), h( 'bdi', null, s.meta.user_login ) ] : null,
				s.meta.user_ip ? [ __( 'IP address', 'forms-notifier-for-elementor' ), h( 'bdi', { dir: 'ltr' }, s.meta.user_ip ) ] : null,
			].filter( Boolean );

			const resend = failed.length && can( 'act' ) ? button( sprintf(
				/* translators: %s: number of messages */
				_n( 'Send %s failed message again', 'Send %s failed messages again', failed.length, 'forms-notifier-for-elementor' ),
				fmt.number( failed.length )
			), { icon: 'refresh', kind: 'wide', onClick: () => resendAll( resend, failed ) } ) : null;

			return fnfe.frag(
				fields.length
					? h( 'section', { class: 'fnfe-mini-card' }, h( 'dl', { class: 'fnfe-mini-fields' }, fields.map( ( field ) => h( 'div', null, h( 'dt', { dir: 'auto' }, field.label ), h( 'dd', null, fieldValue( field ) ) ) ) ) )
					: h( 'section', { class: 'fnfe-mini-card' }, emptyState( 'inbox', __( 'This submission has no fields.', 'forms-notifier-for-elementor' ) ) ),
				s.files.length ? card( __( 'Files', 'forms-notifier-for-elementor' ), h( 'ul', { class: 'fnfe-mini-list' }, s.files.map( ( file ) => h( 'li', null, fileRow( file ) ) ) ) ) : null,
				card( __( 'Details', 'forms-notifier-for-elementor' ), h( 'dl', { class: 'fnfe-mini-meta' }, meta.map( ( row ) => h( 'div', null, h( 'dt', null, row[ 0 ] ), h( 'dd', null, row[ 1 ] ) ) ) ) ),
				card(
					__( 'Messages', 'forms-notifier-for-elementor' ),
					data.messages.length
						? h( 'ul', { class: 'fnfe-mini-list' }, data.messages.map( ( message ) => h( 'li', null, messageRow( message ) ) ) )
						: emptyState( 'send', __( 'No message was sent for this submission.', 'forms-notifier-for-elementor' ) )
				),
				resend
			);
		}

		function resendAll( control, failed ) {
			setBusy( control, true );
			let ok = 0;
			let last = null;
			failed.reduce( ( chain, message ) => chain.then( () => send( 'app/messages/' + message.id + '/retry' ) ).then( ( data ) => {
				ok += data.message.status === 'sent' ? 1 : 0;
			}, ( error ) => {
				last = error;
				if ( error.handled ) {
					throw error;
				}
			} ), Promise.resolve() ).then( () => {
				dirtyBelow();
				reportRetry( ok, failed.length, last );
				return load( true );
			} ).catch( () => setBusy( control, false ) );
		}

		function paintActions() {
			bar.hidden = ! can( 'act' );
			const item = ( status, off, label ) => h( 'button', {
				type: 'button',
				'aria-pressed': current.status === status ? 'true' : 'false',
				dataset: { tone: STATUS[ status ].tone },
				onClick: () => change( current.status === status ? off : status ),
			}, icon( STATUS[ status ].icon, 22 ), h( 'span', null, label ) );
			mount( bar, fnfe.frag(
				item( 'read', 'new', _x( 'Read', 'submission status', 'forms-notifier-for-elementor' ) ),
				item( 'done', 'read', _x( 'Done', 'submission status', 'forms-notifier-for-elementor' ) ),
				item( 'spam', 'read', _x( 'Spam', 'submission status', 'forms-notifier-for-elementor' ) )
			) );
		}

		function change( status, undoing ) {
			const before = current.status;
			Array.prototype.forEach.call( bar.querySelectorAll( 'button' ), ( item ) => {
				item.disabled = true;
			} );
			send( 'app/submissions/' + params.id + '/status', { status: status } ).then( ( data ) => {
				current = data.submission;
				paintHead();
				paintActions();
				dirtyBelow( true );
				fnfe.bus.emit( 'miniapp:status', { id: current.id, status: current.status } );
				host.haptic( 'success' );
				const text = sprintf(
					/* translators: %s: status of a submission */
					__( 'Status: %s', 'forms-notifier-for-elementor' ),
					STATUS[ status ].label()
				);
				notify( text, 'success', undoing ? { timeout: 2000 } : { timeout: 5000, action: { label: __( 'Undo', 'forms-notifier-for-elementor' ), run: () => change( before, true ) } } );
			}, ( error ) => {
				if ( ! error.handled ) {
					paintActions();
					host.haptic( 'error' );
					notify( problem( error ), 'danger', { timeout: 6000 } );
				}
			} );
		}

		load();
		return { el: el, title: __( 'Submission', 'forms-notifier-for-elementor' ), tab: '', refresh: () => load( true ) };
	}

	function languageName( code ) {
		try {
			return new Intl.DisplayNames( [ document.documentElement.lang || 'en' ], { type: 'language' } ).of( code ) || code;
		} catch ( e ) {
			return code;
		}
	}

	function reportRetry( ok, count, error ) {
		if ( ok === count ) {
			host.haptic( 'success' );
			notify( _n( 'The message was sent.', 'The messages were sent.', count, 'forms-notifier-for-elementor' ), 'success' );
			return;
		}
		host.haptic( 'error' );
		notify( error ? problem( error ) : __( 'Sending did not work again. The reason is shown next to the message.', 'forms-notifier-for-elementor' ), 'warning', { timeout: 6000 } );
	}

	/* ----------------------------------------------------- Screen: problems */

	function problemsScreen() {
		let status = '';
		const list = pagedList( {
			load: ( page ) => get( 'app/messages', { page: page, status: status } ),
			row: problemRow,
			empty: () => emptyState( 'check', __( 'No message failed. Nothing to do here.', 'forms-notifier-for-elementor' ) ),
		} );

		function problemRow( message ) {
			const when = message.status === 'retry' && message.next_attempt ? sprintf(
				/* translators: %s: relative time, for example "in 5 minutes" */
				__( 'Next attempt %s', 'forms-notifier-for-elementor' ),
				fmt.relative( message.next_attempt )
			) : timeLabel( message.created );
			const retry = can( 'act' ) ? button( _x( 'Retry', 'message', 'forms-notifier-for-elementor' ), { icon: 'refresh', kind: 'small', onClick: () => {
				setBusy( retry, true );
				send( 'app/messages/' + message.id + '/retry' ).then( ( data ) => {
					dirtyBelow();
					reportRetry( data.message.status === 'sent' ? 1 : 0, 1, null );
					const next = problemRow( data.message );
					item.replaceWith( next );
				}, ( error ) => {
					setBusy( retry, false );
					if ( ! error.handled ) {
						reportRetry( 0, 1, error );
					}
				} );
			} } ) : null;
			const item = h(
				'div',
				{ class: 'fnfe-mini-problem' },
				messageHead( message, '' ),
				message.error && message.status !== 'sent' ? h( 'p', { class: 'fnfe-mini-error', dir: 'auto' }, message.error ) : null,
				h(
					'div',
					{ class: 'fnfe-mini-row__text' },
					message.form_name ? h( 'bdi', { class: 'is-cut' }, message.form_name ) : null,
					h( 'span', { class: 'is-fixed' }, when ),
					message.attempts ? h( 'span', { class: 'is-fixed' }, sprintf(
						/* translators: 1: attempts made, 2: most attempts */
						__( 'Attempt %1$s of %2$s', 'forms-notifier-for-elementor' ),
						fmt.number( message.attempts ),
						fmt.number( Math.max( message.attempts, message.max_attempts ) )
					) ) : null
				),
				( retry && message.status !== 'sent' ) || message.submission_id ? h(
					'div',
					{ class: 'fnfe-mini-problem__actions' },
					message.status === 'sent' ? null : retry,
					message.submission_id ? button( __( 'Open submission', 'forms-notifier-for-elementor' ), { kind: 'small', onClick: () => push( 'submission', { id: message.submission_id } ) } ) : null
				) : null
			);
			return item;
		}

		const title = __( 'Problems', 'forms-notifier-for-elementor' );
		const el = h(
			'div',
			{ class: 'fnfe-mini-view' },
			header( title, { tail: iconButton( 'refresh', __( 'Refresh', 'forms-notifier-for-elementor' ), list.reload ) } ),
			h( 'div', { class: 'fnfe-mini-chips', role: 'group', 'aria-label': __( 'Status', 'forms-notifier-for-elementor' ) }, segmented( [
				{ value: '', label: _x( 'All', 'problems', 'forms-notifier-for-elementor' ) },
				{ value: 'failed', label: MESSAGE.failed.label() },
				{ value: 'retry', label: MESSAGE.retry.label() },
			], status, '', ( value ) => {
				status = value;
				list.reload();
			} ) ),
			h( 'div', { class: 'fnfe-mini-card' }, list.el )
		);
		list.reload();
		return { el: el, title: title, tab: 'problems', refresh: list.reload, destroy: list.destroy };
	}

	/* ---------------------------------------------------------------- Router */

	const SCREENS = { home: homeScreen, forms: formsScreen, submissions: submissionsScreen, submission: submissionScreen, problems: problemsScreen };

	function ownBack() {
		const old = host.platform === 'telegram' && /^([0-5]\.|6\.0$)/.test( host.version );
		return host.transport() === '' || old;
	}

	function top() {
		return stack[ stack.length - 1 ];
	}

	function show( entry, direction ) {
		mount( stage, entry.view.el );
		stage.dataset.direction = direction;
		document.title = entry.view.title;
		Array.prototype.forEach.call( nav.children, ( item ) => {
			if ( item.dataset.tab === entry.view.tab ) {
				item.setAttribute( 'aria-current', 'page' );
			} else {
				item.removeAttribute( 'aria-current' );
			}
		} );
		nav.hidden = entry.view.tab === '' && can( 'act' );
		if ( stack.length > 1 ) {
			host.backButton.show();
		} else {
			host.backButton.hide();
		}
		window.scrollTo( 0, entry.scroll || 0 );
		const heading = entry.view.el.querySelector( 'h1' );
		if ( heading ) {
			heading.focus( { preventScroll: true } );
		}
		announce( entry.view.title );
	}

	function push( name, params ) {
		if ( top() ) {
			top().scroll = window.scrollY;
		}
		const entry = { name: name, scroll: 0, dirty: false, view: null };
		entry.view = SCREENS[ name ]( params || {} );
		stack.push( entry );
		show( entry, 'forward' );
	}

	function pop() {
		if ( stack.length < 2 ) {
			return;
		}
		const gone = stack.pop();
		if ( gone.view.destroy ) {
			gone.view.destroy();
		}
		const entry = top();
		show( entry, 'back' );
		if ( entry.dirty ) {
			entry.dirty = false;
			entry.view.refresh();
		}
	}

	/* Lists below the current screen are out of date. A submission list updates its own row, so it keeps its place. */
	function dirtyBelow( statusOnly ) {
		stack.slice( 0, -1 ).forEach( ( entry ) => {
			if ( ! statusOnly || entry.name !== 'submissions' ) {
				entry.dirty = true;
			}
		} );
	}

	function goTab( tab ) {
		host.haptic( 'selection' );
		if ( top() && top().view.tab === tab && top().name === tab ) {
			window.scrollTo( { top: 0, behavior: 'smooth' } );
			top().view.refresh();
			return;
		}
		while ( stack.length > 1 ) {
			const gone = stack.pop();
			if ( gone.view.destroy ) {
				gone.view.destroy();
			}
		}
		if ( tab === 'home' ) {
			show( top(), 'back' );
			top().view.refresh();
		} else {
			push( tab );
		}
	}

	function tabButton( tab, name, label ) {
		return h( 'button', { type: 'button', dataset: { tab: tab }, onClick: () => goTab( tab ) }, icon( name, 24 ), h( 'span', null, label ) );
	}

	/* ----------------------------------------------------------------- Gates */

	function gate( name, title, text, extra ) {
		stack.splice( 0 ).forEach( ( entry ) => {
			if ( entry.view.destroy ) {
				entry.view.destroy();
			}
		} );
		nav.hidden = true;
		host.backButton.hide();
		mount( stage, h(
			'div',
			{ class: 'fnfe-mini-gate' },
			h( 'span', { class: 'fnfe-mini-gate__icon', 'aria-hidden': 'true' }, icon( name, 36 ) ),
			h( 'h1', { tabindex: '-1' }, title ),
			h( 'p', null, text ),
			extra || null
		) );
		announce( title );
	}

	function botButton() {
		return boot.bot ? h( 'a', { class: 'fnfe-mini-button is-primary', href: boot.bot, onClick( event ) {
			if ( host.transport() !== '' ) {
				event.preventDefault();
				host.openTelegramLink( boot.bot );
			}
		} }, icon( 'bot', 18 ), h( 'span', null, __( 'Open the bot', 'forms-notifier-for-elementor' ) ) ) : null;
	}

	function gateFor( error ) {
		if ( error.code === 'fnfe_app_not_allowed' ) {
			const id = String( ( error.fields && error.fields.user_id ) || '' );
			gate(
				'lock',
				__( 'You do not have access', 'forms-notifier-for-elementor' ),
				__( 'Send this id to the owner of the site. After you are added, open the app again.', 'forms-notifier-for-elementor' ),
				h( 'div', { class: 'fnfe-mini-gate__id' }, h( 'bdi', { dir: 'ltr' }, id ), button( __( 'Copy', 'forms-notifier-for-elementor' ), { kind: 'small', onClick: () => copyText( id ) } ), button( __( 'Try again', 'forms-notifier-for-elementor' ), { kind: 'small', icon: 'refresh', onClick: start } ) )
			);
		} else if ( error.code === 'fnfe_app_disabled' ) {
			gate( 'lock', __( 'The app is turned off', 'forms-notifier-for-elementor' ), __( 'The owner of the site has turned the mini app off.', 'forms-notifier-for-elementor' ) );
		} else if ( error.code === 'fnfe_app_invalid' ) {
			gate( 'lock', __( 'Sign-in failed', 'forms-notifier-for-elementor' ), problem( error ), botButton() );
		} else if ( error.status === 401 ) {
			gate( 'clock', __( 'Session expired', 'forms-notifier-for-elementor' ), __( 'Close the app and open it again from the bot.', 'forms-notifier-for-elementor' ), h( 'div', { class: 'fnfe-mini-gate__id' }, botButton() || button( __( 'Close', 'forms-notifier-for-elementor' ), { kind: 'primary', onClick: host.close } ) ) );
		} else {
			gate( 'alert', __( 'The app could not start', 'forms-notifier-for-elementor' ), problem( error ), button( __( 'Try again', 'forms-notifier-for-elementor' ), { kind: 'primary', icon: 'refresh', onClick: start } ) );
		}
	}

	/* ----------------------------------------------------------------- Start */

	function openStart() {
		stack.splice( 0 );
		nav.hidden = false;
		const match = /^(sub|form)_(\d{1,12})$/.exec( host.startParam );
		push( 'home' );
		if ( match ) {
			push( match[ 1 ] === 'sub' ? 'submission' : 'submissions', { id: Number( match[ 2 ] ) } );
		}
	}

	function start() {
		if ( ! host.available ) {
			gate( 'bot', __( 'Open this page from the bot', 'forms-notifier-for-elementor' ), __( 'This app works inside the messenger. Open the chat with the bot and start the app there.', 'forms-notifier-for-elementor' ), botButton() );
			return;
		}
		if ( restoreSession() ) {
			openStart();
			return;
		}
		nav.hidden = true;
		mount( stage, h( 'div', { class: 'fnfe-mini-view' }, skeleton( 1, 'head' ), skeleton( 1, 'hero' ), skeleton( 4, 'row' ) ) );
		signIn().then( openStart, gateFor );
	}

	function init() {
		root = document.getElementById( 'fnfe-miniapp' );
		if ( ! root ) {
			return;
		}
		stage = h( 'main', { class: 'fnfe-mini-stage' } );
		live = h( 'div', { class: 'fnfe-mini-sr', role: 'status', 'aria-live': 'polite' } );
		nav = h(
			'nav',
			{ class: 'fnfe-mini-nav', 'aria-label': __( 'Sections', 'forms-notifier-for-elementor' ), hidden: true },
			tabButton( 'home', 'home', __( 'Overview', 'forms-notifier-for-elementor' ) ),
			tabButton( 'forms', 'forms', __( 'Forms', 'forms-notifier-for-elementor' ) ),
			tabButton( 'problems', 'alert', __( 'Problems', 'forms-notifier-for-elementor' ) )
		);
		mount( root, fnfe.frag( stage, nav, live ) );
		applyTheme();
		host.on( 'theme_changed', applyTheme );
		host.backButton.onClick( pop );
		start();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
