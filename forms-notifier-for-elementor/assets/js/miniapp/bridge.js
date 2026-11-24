/**
 * Host bridge of the mini app: talks to the Telegram or Bale client through the documented event protocol. No remote
 * script is needed. Everything degrades quietly: without a host the app still works, it just draws its own back button.
 */
( function () {
	'use strict';

	const fnfe = window.fnfe;
	const boot = fnfe.boot || {};
	const platform = boot.platform === 'bale' ? 'bale' : 'telegram';
	const STORE_KEY = 'fnfe_launch';
	const ORIGINS = /^https:\/\/([a-z0-9-]+\.)*(telegram\.org|bale\.ai)$/;
	const MAX_HASH = 16384;

	const listeners = {};
	const framed = ( function () {
		try {
			return !! window.parent && window.parent !== window;
		} catch ( e ) {
			return true;
		}
	}() );

	/* ---------------------------------------------------------- Launch data */

	function decode( text ) {
		try {
			return decodeURIComponent( String( text ).replace( /\+/g, '%20' ) );
		} catch ( e ) {
			return String( text );
		}
	}

	function parsePairs( text ) {
		const out = {};
		String( text || '' ).split( '&' ).forEach( ( part ) => {
			if ( part === '' ) {
				return;
			}
			const at = part.indexOf( '=' );
			const key = decode( at > -1 ? part.slice( 0, at ) : part );
			if ( key !== '__proto__' ) {
				out[ key ] = at > -1 ? decode( part.slice( at + 1 ) ) : '';
			}
		} );
		return out;
	}

	/* The address may carry its own fragment in front of the launch parameters. */
	function readHash() {
		const hash = String( window.location.hash || '' ).replace( /^#/, '' ).slice( 0, MAX_HASH );
		const at = hash.search( /tgWebApp[A-Za-z]+=/ );
		return {
			path: ( at > -1 ? hash.slice( 0, at ) : hash ).replace( /^[/?&]+|[/?&]+$/g, '' ),
			params: at > -1 ? parsePairs( hash.slice( at ) ) : {},
		};
	}

	function readStored() {
		try {
			const saved = JSON.parse( window.sessionStorage.getItem( STORE_KEY ) || 'null' );
			return saved && saved.platform === platform && saved.params && typeof saved.params === 'object' ? saved.params : {};
		} catch ( e ) {
			return {};
		}
	}

	function writeStored( params ) {
		try {
			window.sessionStorage.setItem( STORE_KEY, JSON.stringify( { platform: platform, params: params } ) );
		} catch ( e ) {
			// Storage can be blocked inside a frame; the data then lives until the next reload.
		}
	}

	/* Some clients encode the whole value twice. */
	function unwrap( text, looksRight ) {
		let value = String( text || '' );
		for ( let i = 0; i < 2 && value !== '' && ! looksRight( value ); i++ ) {
			value = decode( value );
		}
		return value;
	}

	const fragment = readHash();
	const hasFresh = !! fragment.params.tgWebAppData;
	const launch = hasFresh ? fragment.params : Object.assign( readStored(), fragment.params );
	if ( hasFresh ) {
		writeStored( {
			tgWebAppData: launch.tgWebAppData,
			tgWebAppVersion: launch.tgWebAppVersion,
			tgWebAppPlatform: launch.tgWebAppPlatform,
			tgWebAppThemeParams: launch.tgWebAppThemeParams,
			tgWebAppStartParam: launch.tgWebAppStartParam,
		} );
	}

	const initData = unwrap( launch.tgWebAppData, ( value ) => value.indexOf( 'hash=' ) > -1 && value.indexOf( '&' ) > -1 );

	function parseTheme( value ) {
		let theme = value;
		if ( typeof value === 'string' ) {
			try {
				theme = JSON.parse( unwrap( value, ( text ) => text.charAt( 0 ) === '{' ) );
			} catch ( e ) {
				theme = null;
			}
		}
		const out = {};
		if ( theme && typeof theme === 'object' ) {
			Object.keys( theme ).forEach( ( key ) => {
				const color = parseColor( theme[ key ] );
				if ( color && /^[a-z_]{3,40}$/.test( key ) ) {
					out[ key ] = color;
				}
			} );
		}
		return out;
	}

	/* '#rgb' or '#rrggbb' -> '#rrggbb', otherwise ''. */
	function parseColor( value ) {
		const match = /^\s*#([0-9a-f]{3}|[0-9a-f]{6})\s*$/i.exec( typeof value === 'string' ? value : '' );
		if ( ! match ) {
			return '';
		}
		const hex = match[ 1 ].toLowerCase();
		return '#' + ( hex.length === 3 ? hex.replace( /./g, '$&$&' ) : hex );
	}

	function schemeOf( theme ) {
		if ( theme.bg_color ) {
			const n = parseInt( theme.bg_color.slice( 1 ), 16 );
			const light = 0.299 * ( n >> 16 ) + 0.587 * ( ( n >> 8 ) & 255 ) + 0.114 * ( n & 255 );
			return light < 128 ? 'dark' : 'light';
		}
		return window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' ).matches ? 'dark' : 'light';
	}

	function findStartParam() {
		const search = new URLSearchParams( window.location.search );
		const value = launch.tgWebAppStartParam || search.get( 'tgWebAppStartParam' ) || parsePairs( initData ).start_param || search.get( 'startapp' ) || '';
		if ( /^[A-Za-z0-9_-]{1,512}$/.test( value ) ) {
			return value;
		}
		const match = /^(submission|form)s?\/(\d{1,12})$/.exec( fragment.path );
		return match ? ( match[ 1 ] === 'form' ? 'form_' : 'sub_' ) + match[ 2 ] : '';
	}

	/* ------------------------------------------------------------ Transport */

	function parentOrigin() {
		let origin = '';
		try {
			if ( window.location.ancestorOrigins && window.location.ancestorOrigins.length ) {
				origin = window.location.ancestorOrigins[ 0 ];
			} else if ( document.referrer ) {
				origin = new URL( document.referrer ).origin;
			}
		} catch ( e ) {
			origin = '';
		}
		// The messages hold nothing secret, so an unknown web client still gets them.
		return ORIGINS.test( origin ) ? origin : '*';
	}

	function transport() {
		const proxy = window.TelegramWebviewProxy;
		if ( proxy && typeof proxy.postEvent === 'function' ) {
			return 'proxy';
		}
		if ( window.BaleWebApp && typeof window.BaleWebApp.postEvent === 'function' ) {
			return 'bale';
		}
		if ( framed ) {
			return 'frame';
		}
		try {
			if ( window.external && typeof window.external.notify === 'function' ) {
				return 'external';
			}
		} catch ( e ) {
			return '';
		}
		return '';
	}

	function post( eventType, eventData ) {
		const data = eventData || {};
		try {
			switch ( transport() ) {
				case 'proxy':
					window.TelegramWebviewProxy.postEvent( eventType, JSON.stringify( data ) );
					return true;
				case 'bale':
					window.BaleWebApp.postEvent( eventType, JSON.stringify( data ) );
					return true;
				case 'frame':
					window.parent.postMessage( JSON.stringify( { eventType: eventType, eventData: data } ), parentOrigin() );
					return true;
				case 'external':
					window.external.notify( JSON.stringify( { eventType: eventType, eventData: data } ) );
					return true;
			}
		} catch ( e ) {
			return false;
		}
		return false;
	}

	/* Bale names its events in camel case: backButtonPressed. */
	function normalName( eventType ) {
		return String( eventType ).replace( /[A-Z]/g, ( letter ) => '_' + letter.toLowerCase() ).slice( 0, 64 );
	}

	function receiveEvent( eventType, eventData ) {
		let data = eventData;
		if ( typeof data === 'string' && data !== '' ) {
			try {
				data = JSON.parse( data );
			} catch ( e ) {
				data = {};
			}
		}
		data = data && typeof data === 'object' ? data : {};
		const name = normalName( eventType );

		if ( name === 'theme_changed' ) {
			const theme = parseTheme( data.theme_params );
			if ( Object.keys( theme ).length ) {
				host.themeParams = theme;
				host.colorScheme = schemeOf( theme );
			}
		} else if ( name === 'reload_iframe' && framed ) {
			post( 'iframe_will_reload' );
			window.location.reload();
			return true;
		}
		( listeners[ name ] || [] ).slice().forEach( ( fn ) => {
			try {
				fn( data );
			} catch ( e ) {
				window.setTimeout( () => {
					throw e;
				}, 0 );
			}
		} );
		return true;
	}

	/* Entry points that the native clients call. They may already exist. */
	function expose( name, value ) {
		try {
			window[ name ] = Object.assign( window[ name ] && typeof window[ name ] === 'object' ? window[ name ] : {}, value );
		} catch ( e ) {
			return false;
		}
		return true;
	}

	expose( 'Telegram', { WebView: { receiveEvent: receiveEvent } } );
	expose( 'TelegramGameProxy', { receiveEvent: receiveEvent } );
	expose( 'Bale', { receiveEvent: receiveEvent } );
	window.TelegramGameProxy_receiveEvent = receiveEvent;

	window.addEventListener( 'message', ( event ) => {
		if ( ! framed || event.source !== window.parent || typeof event.data !== 'string' || event.data.length > 65536 ) {
			return;
		}
		let message = null;
		try {
			message = JSON.parse( event.data );
		} catch ( e ) {
			return;
		}
		if ( message && typeof message.eventType === 'string' ) {
			receiveEvent( message.eventType, message.eventData );
		}
	} );

	/* ------------------------------------------------------------ Public API */

	function on( eventType, fn ) {
		( listeners[ eventType ] = listeners[ eventType ] || [] ).push( fn );
	}

	function off( eventType, fn ) {
		listeners[ eventType ] = ( listeners[ eventType ] || [] ).filter( ( item ) => item !== fn );
	}

	function httpUrl( url ) {
		try {
			const parsed = new URL( String( url ), window.location.href );
			return parsed.protocol === 'https:' || parsed.protocol === 'http:' ? parsed : null;
		} catch ( e ) {
			return null;
		}
	}

	function openWindow( url ) {
		window.open( url, '_blank', 'noopener,noreferrer' );
	}

	let backVisible = null;
	const mainState = { text: '', visible: false, active: true };
	const theme = parseTheme( launch.tgWebAppThemeParams );

	const host = {
		platform: platform,
		available: initData !== '',
		initData: initData,
		startParam: findStartParam(),
		version: /^[0-9.]{1,12}$/.test( launch.tgWebAppVersion || '' ) ? launch.tgWebAppVersion : '',
		clientPlatform: /^[a-z_]{1,24}$/i.test( launch.tgWebAppPlatform || '' ) ? launch.tgWebAppPlatform : '',
		colorScheme: schemeOf( theme ),
		themeParams: theme,
		transport: transport,
		parseColor: parseColor,
		post: post,
		on: on,
		off: off,
		ready() {
			post( 'web_app_ready' );
		},
		expand() {
			post( 'web_app_expand' );
		},
		close() {
			post( 'web_app_close' );
		},
		backButton: {
			show() {
				if ( backVisible !== true ) {
					backVisible = true;
					post( 'web_app_setup_back_button', { is_visible: true } );
				}
			},
			hide() {
				if ( backVisible !== false ) {
					backVisible = false;
					post( 'web_app_setup_back_button', { is_visible: false } );
				}
			},
			onClick( fn ) {
				on( 'back_button_pressed', fn );
			},
		},
		mainButton: {
			set( options ) {
				Object.assign( mainState, options || {} );
				post( 'web_app_setup_main_button', {
					is_visible: !! mainState.visible && mainState.text !== '',
					is_active: !! mainState.active,
					text: String( mainState.text ).slice( 0, 64 ),
					color: host.themeParams.button_color,
					text_color: host.themeParams.button_text_color,
				} );
			},
			onClick( fn ) {
				on( 'main_button_pressed', fn );
			},
		},
		openLink( url ) {
			const parsed = httpUrl( url );
			if ( parsed && ! post( 'web_app_open_link', { url: parsed.href } ) ) {
				openWindow( parsed.href );
			}
		},
		openTelegramLink( url ) {
			const parsed = httpUrl( url );
			if ( ! parsed ) {
				return;
			}
			const own = platform === 'telegram' && ( parsed.hostname === 't.me' || parsed.hostname === 'telegram.me' );
			if ( ! own || ! post( 'web_app_open_tg_link', { path_full: parsed.pathname + parsed.search } ) ) {
				host.openLink( parsed.href );
			}
		},
		haptic( type ) {
			if ( type === 'success' || type === 'error' ) {
				post( 'web_app_trigger_haptic_feedback', { type: 'notification', notification_type: type } );
			} else if ( type === 'selection' ) {
				post( 'web_app_trigger_haptic_feedback', { type: 'selection_change' } );
			} else {
				post( 'web_app_trigger_haptic_feedback', { type: 'impact', impact_style: 'light' } );
			}
		},
		setHeaderColor( color ) {
			const clean = parseColor( color );
			if ( clean ) {
				post( 'web_app_set_header_color', { color: clean } );
			}
		},
		setBackgroundColor( color ) {
			const clean = parseColor( color );
			if ( clean ) {
				post( 'web_app_set_background_color', { color: clean } );
			}
		},
	};

	fnfe.host = host;

	if ( framed ) {
		post( 'iframe_ready', { reload_supported: true } );
	}
	host.ready();
	host.expand();
	post( 'web_app_request_theme' );
}() );
