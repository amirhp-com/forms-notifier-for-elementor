/**
 * Core of the admin panel and the mini app: window.fnfe.
 */
( function () {
	'use strict';

	const { __ } = wp.i18n;
	const SVG_NS = 'http://www.w3.org/2000/svg';
	const SVG_TAGS = [ 'svg', 'g', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse', 'defs', 'use', 'text', 'tspan', 'title', 'clipPath', 'mask', 'linearGradient', 'stop' ];
	const HTML_ONLY = [ 'title' ];
	const SAFE_TAGS = [ 'A', 'CODE', 'STRONG', 'EM', 'B', 'I', 'BR', 'UL', 'OL', 'LI', 'P', 'KBD' ];

	const boot = window.fnfeBoot && typeof window.fnfeBoot === 'object' ? window.fnfeBoot : {};
	let token = '';

	/* ---------------------------------------------------------------- DOM */

	function classNames( value ) {
		if ( Array.isArray( value ) ) {
			return value.map( classNames ).filter( Boolean ).join( ' ' );
		}
		if ( value && typeof value === 'object' ) {
			return Object.keys( value ).filter( ( key ) => value[ key ] ).join( ' ' );
		}
		return value ? String( value ) : '';
	}

	function append( parent, child ) {
		if ( child === null || child === undefined || child === false || child === true ) {
			return;
		}
		if ( Array.isArray( child ) ) {
			child.forEach( ( item ) => append( parent, item ) );
			return;
		}
		parent.appendChild( child instanceof Node ? child : document.createTextNode( String( child ) ) );
	}

	function setStyle( el, style ) {
		Object.keys( style ).forEach( ( name ) => {
			const value = style[ name ];
			if ( value === null || value === undefined || value === false ) {
				return;
			}
			if ( name.indexOf( '-' ) > -1 ) {
				el.style.setProperty( name, String( value ) );
			} else {
				el.style[ name ] = String( value );
			}
		} );
	}

	function setProp( el, name, value ) {
		if ( /^(html|(inner|outer)html)$/i.test( name ) || value === undefined ) {
			return;
		}
		if ( name === 'class' || name === 'className' ) {
			const text = classNames( value );
			if ( text ) {
				el.setAttribute( 'class', text );
			}
			return;
		}
		if ( name === 'style' ) {
			if ( value && typeof value === 'object' ) {
				setStyle( el, value );
			}
			return;
		}
		if ( name === 'dataset' ) {
			Object.keys( value || {} ).forEach( ( key ) => {
				if ( value[ key ] !== null && value[ key ] !== undefined ) {
					el.dataset[ key ] = String( value[ key ] );
				}
			} );
			return;
		}
		if ( /^on/i.test( name ) ) {
			// Listeners only as functions, so no text can ever become a handler.
			if ( typeof value === 'function' ) {
				el.addEventListener( name.slice( 2 ).toLowerCase(), value );
			}
			return;
		}
		if ( name === 'ref' ) {
			if ( typeof value === 'function' ) {
				value( el );
			}
			return;
		}
		if ( ( name === 'value' || name === 'checked' || name === 'selected' || name === 'indeterminate' ) && name in el ) {
			el[ name ] = value === null ? '' : value;
			return;
		}
		if ( value === null || value === false ) {
			// aria-* and data-* attributes keep an explicit "false".
			if ( value === false && /^(aria-|data-)/.test( name ) ) {
				el.setAttribute( name, 'false' );
			} else {
				el.removeAttribute( name );
			}
			return;
		}
		if ( ( name === 'href' || name === 'src' || name === 'action' || name === 'formaction' || name === 'xlink:href' ) && ! safeUrl( String( value ) ) ) {
			return;
		}
		el.setAttribute( name, value === true ? ( /^(aria-|data-)/.test( name ) ? 'true' : '' ) : String( value ) );
	}

	function safeUrl( url ) {
		const clean = Array.from( url ).filter( ( char ) => char.charCodeAt( 0 ) > 32 && char.charCodeAt( 0 ) !== 127 ).join( '' ).toLowerCase();
		const scheme = clean.match( /^([a-z][a-z0-9+.-]*):/ );
		if ( ! scheme ) {
			return true;
		}
		return [ 'http', 'https', 'mailto', 'tel', 'tg', 'blob' ].indexOf( scheme[ 1 ] ) > -1 || /^data:image\/(png|gif|jpe?g|webp);/.test( clean );
	}

	function h( tag, props ) {
		const children = Array.prototype.slice.call( arguments, 2 );
		const isSvg = SVG_TAGS.indexOf( tag ) > -1 && HTML_ONLY.indexOf( tag ) === -1;
		const el = isSvg ? document.createElementNS( SVG_NS, tag ) : document.createElement( tag );
		const attributes = props || {};
		const late = [];
		Object.keys( attributes ).forEach( ( name ) => {
			// A select can only take its value once its options exist.
			if ( name === 'value' && tag === 'select' ) {
				late.push( name );
			} else {
				setProp( el, name, attributes[ name ] );
			}
		} );
		append( el, children );
		late.forEach( ( name ) => setProp( el, name, attributes[ name ] ) );
		return el;
	}

	function frag() {
		const node = document.createDocumentFragment();
		append( node, Array.prototype.slice.call( arguments ) );
		return node;
	}

	function mount( container, node ) {
		while ( container.firstChild ) {
			container.removeChild( container.firstChild );
		}
		append( container, node );
		return container;
	}

	function copySafe( source, target ) {
		Array.prototype.forEach.call( source.childNodes, ( node ) => {
			if ( node.nodeType === 3 ) {
				target.appendChild( document.createTextNode( node.nodeValue ) );
				return;
			}
			if ( node.nodeType !== 1 ) {
				return;
			}
			if ( SAFE_TAGS.indexOf( node.tagName ) === -1 ) {
				// Unknown elements become their text.
				target.appendChild( document.createTextNode( node.textContent ) );
				return;
			}
			const el = document.createElement( node.tagName.toLowerCase() );
			if ( node.tagName === 'A' ) {
				const href = node.getAttribute( 'href' ) || '';
				if ( href && safeUrl( href ) ) {
					el.setAttribute( 'href', href );
				}
				if ( node.getAttribute( 'target' ) === '_blank' ) {
					el.setAttribute( 'target', '_blank' );
					el.setAttribute( 'rel', 'noopener noreferrer' );
				} else if ( node.getAttribute( 'rel' ) ) {
					el.setAttribute( 'rel', node.getAttribute( 'rel' ) );
				}
			}
			copySafe( node, el );
			target.appendChild( el );
		} );
	}

	function safeHtml( html ) {
		const out = document.createDocumentFragment();
		// The parsed document is inert: no scripts run and no resources load.
		const doc = new DOMParser().parseFromString( String( html === null || html === undefined ? '' : html ), 'text/html' );
		copySafe( doc.body, out );
		return out;
	}

	/* --------------------------------------------------------------- REST */

	function buildUrl( path, params ) {
		const root = ( boot.rest && boot.rest.root ) || '';
		const clean = String( path ).replace( /^\/+/, '' );
		const hash = clean.indexOf( '?' );
		let url = root + ( hash > -1 ? clean.slice( 0, hash ) : clean );
		const query = [];
		if ( hash > -1 ) {
			query.push( clean.slice( hash + 1 ) );
		}
		const add = ( name, value ) => {
			if ( value === undefined || value === null || value === '' ) {
				return;
			}
			if ( Array.isArray( value ) ) {
				value.forEach( ( item ) => add( name + '[]', item ) );
			} else if ( typeof value === 'object' ) {
				Object.keys( value ).forEach( ( key ) => add( name + '[' + key + ']', value[ key ] ) );
			} else {
				query.push( encodeURIComponent( name ) + '=' + encodeURIComponent( value === true ? '1' : value === false ? '0' : value ) );
			}
		};
		Object.keys( params || {} ).forEach( ( name ) => add( name, params[ name ] ) );
		if ( query.length ) {
			// Without pretty permalinks the root already holds a query string.
			url += ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + query.join( '&' );
		}
		return url;
	}

	function headers( extra ) {
		const out = Object.assign( { Accept: 'application/json' }, extra || {} );
		if ( token ) {
			out[ 'X-FNFE-Token' ] = token;
		} else if ( boot.rest && boot.rest.nonce ) {
			out[ 'X-WP-Nonce' ] = boot.rest.nonce;
		}
		return out;
	}

	function failure( code, message, status, fields ) {
		return { code: code, message: message, status: status, fields: fields || {} };
	}

	function networkError() {
		return failure( 'fnfe_network', __( 'The site could not be reached. Check your connection and try again.', 'forms-notifier-for-elementor' ), 0 );
	}

	function parse( response ) {
		return response.text().then( ( text ) => {
			let data = null;
			try {
				data = text ? JSON.parse( text ) : null;
			} catch ( e ) {
				data = undefined;
			}
			if ( response.ok && data !== undefined ) {
				return data;
			}
			if ( data && typeof data === 'object' ) {
				throw failure( data.code || 'fnfe_error', data.message || __( 'Something went wrong.', 'forms-notifier-for-elementor' ), response.status, data.data && data.data.fields );
			}
			throw failure( 'fnfe_bad_response', __( 'The site sent an unexpected answer.', 'forms-notifier-for-elementor' ), response.status );
		} );
	}

	function request( method, path, params, body ) {
		const options = { method: method, credentials: 'same-origin', headers: headers() };
		if ( method !== 'GET' && method !== 'POST' ) {
			// Some hosts block these methods.
			options.method = 'POST';
			options.headers[ 'X-HTTP-Method-Override' ] = method;
		}
		if ( body !== undefined ) {
			options.headers[ 'Content-Type' ] = 'application/json';
			options.body = JSON.stringify( body );
		}
		return window.fetch( buildUrl( path, localized( params ) ), options ).catch( () => {
			throw networkError();
		} ).then( parse );
	}

	/**
	 * Answers in the language of the logged-in user: without _locale=user WordPress answers REST requests in the site
	 * language. The mini app (token) sends its own language.
	 *
	 * @param {Object} params Query parameters.
	 * @return {Object} Parameters.
	 */
	function localized( params ) {
		return token ? params : Object.assign( { _locale: 'user' }, params || {} );
	}

	const api = {
		get: ( path, params ) => request( 'GET', path, params ),
		post: ( path, body ) => request( 'POST', path, null, body === undefined ? {} : body ),
		put: ( path, body ) => request( 'PUT', path, null, body === undefined ? {} : body ),
		patch: ( path, body ) => request( 'PATCH', path, null, body === undefined ? {} : body ),
		del: ( path, body ) => request( 'DELETE', path, null, body ),
		setToken( value ) {
			token = value ? String( value ) : '';
		},
		download( path, params, filename ) {
			return window.fetch( buildUrl( path, localized( params ) ), { credentials: 'same-origin', headers: headers( { Accept: '*/*' } ) } ).catch( () => {
				throw networkError();
			} ).then( ( response ) => {
				if ( ! response.ok ) {
					return parse( response );
				}
				return response.blob().then( ( blob ) => {
					const url = URL.createObjectURL( blob );
					const link = h( 'a', { href: url, download: filename || 'download', style: { display: 'none' } } );
					document.body.appendChild( link );
					link.click();
					link.remove();
					window.setTimeout( () => URL.revokeObjectURL( url ), 10000 );
					return blob;
				} );
			} );
		},
	};

	/* -------------------------------------------------------------- State */

	function store( initial ) {
		let state = Object.assign( {}, initial );
		const listeners = new Set();
		return {
			get: () => state,
			set( patch ) {
				const next = typeof patch === 'function' ? patch( state ) : patch;
				if ( ! next || next === state ) {
					return;
				}
				state = Object.assign( {}, state, next );
				Array.from( listeners ).forEach( ( fn ) => fn( state ) );
			},
			subscribe( fn ) {
				listeners.add( fn );
				return () => listeners.delete( fn );
			},
		};
	}

	const handlers = {};
	const bus = {
		on( evt, fn ) {
			( handlers[ evt ] = handlers[ evt ] || [] ).push( fn );
			return () => bus.off( evt, fn );
		},
		off( evt, fn ) {
			handlers[ evt ] = ( handlers[ evt ] || [] ).filter( ( item ) => item !== fn );
		},
		emit( evt, data ) {
			( handlers[ evt ] || [] ).slice().forEach( ( fn ) => fn( data ) );
		},
	};

	/* ------------------------------------------------------------- Router */

	const routes = [];
	let guardFn = null;
	let started = false;
	let currentHash = '';

	function parseQuery( text ) {
		const out = {};
		new URLSearchParams( text ).forEach( ( value, key ) => {
			out[ key ] = value;
		} );
		return out;
	}

	function parseHash( hash ) {
		const raw = String( hash || '' ).replace( /^#?\/?/, '' );
		const mark = raw.indexOf( '?' );
		const path = ( mark > -1 ? raw.slice( 0, mark ) : raw ).replace( /\/+$/, '' );
		return { path: path, query: parseQuery( mark > -1 ? raw.slice( mark + 1 ) : '' ) };
	}

	function decode( text ) {
		try {
			return decodeURIComponent( text );
		} catch ( e ) {
			return text;
		}
	}

	function match( pattern, path ) {
		const left = pattern.split( '/' );
		const right = path.split( '/' );
		if ( left.length !== right.length ) {
			return null;
		}
		const params = {};
		for ( let i = 0; i < left.length; i++ ) {
			if ( left[ i ].charAt( 0 ) === ':' ) {
				if ( right[ i ] === '' ) {
					return null;
				}
				params[ left[ i ].slice( 1 ) ] = decode( right[ i ] );
			} else if ( left[ i ] !== right[ i ] ) {
				return null;
			}
		}
		return params;
	}

	function currentRoute() {
		const parsed = parseHash( window.location.hash );
		for ( let i = 0; i < routes.length; i++ ) {
			const params = match( routes[ i ].pattern, parsed.path );
			if ( params ) {
				return { path: parsed.path, pattern: routes[ i ].pattern, params: params, query: parsed.query };
			}
		}
		return { path: parsed.path, pattern: '', params: {}, query: parsed.query };
	}

	function dispatch() {
		const route = currentRoute();
		const entry = routes.find( ( item ) => item.pattern === route.pattern );
		bus.emit( 'route', route );
		if ( entry ) {
			entry.handler( route.params, route.query, route );
		}
	}

	function onHashChange() {
		const next = window.location.hash;
		if ( next === currentHash ) {
			return;
		}
		if ( ! guardFn ) {
			currentHash = next;
			dispatch();
			return;
		}
		// Stay on the old address until the guard lets the change through.
		window.history.replaceState( null, '', currentHash || '#/' );
		guardFn( () => {
			window.history.replaceState( null, '', next );
			currentHash = next;
			dispatch();
		} );
	}

	const router = {
		add( pattern, handler ) {
			const clean = String( pattern ).replace( /^\/+|\/+$/g, '' );
			const existing = routes.find( ( item ) => item.pattern === clean );
			if ( existing ) {
				existing.handler = handler;
				return;
			}
			routes.push( { pattern: clean, handler: handler } );
			// Literal segments win over parameters.
			routes.sort( ( a, b ) => ( a.pattern.match( /:/g ) || [] ).length - ( b.pattern.match( /:/g ) || [] ).length );
		},
		start() {
			if ( started ) {
				return;
			}
			started = true;
			window.addEventListener( 'hashchange', onHashChange );
			currentHash = window.location.hash;
			dispatch();
		},
		go( path, replace ) {
			const next = '#/' + String( path ).replace( /^#?\/*/, '' );
			const run = () => {
				currentHash = next;
				if ( replace ) {
					window.history.replaceState( null, '', next );
				} else if ( window.location.hash !== next ) {
					window.history.pushState( null, '', next );
				}
				dispatch();
			};
			if ( guardFn && next !== currentHash ) {
				guardFn( run );
			} else {
				run();
			}
		},
		current: currentRoute,
		guard( fn ) {
			guardFn = typeof fn === 'function' ? fn : null;
		},
	};

	/* -------------------------------------------------------------- Toast */

	function toastRegion() {
		let region = document.querySelector( '.fnfe-toasts' );
		if ( ! region ) {
			region = h( 'div', { class: 'fnfe-toasts', role: 'region', 'aria-label': __( 'Messages', 'forms-notifier-for-elementor' ) } );
			( document.querySelector( '.fnfe-root' ) || document.body ).appendChild( region );
		}
		return region;
	}

	function toast( message, type, opts ) {
		const kind = [ 'success', 'info', 'warning', 'danger' ].indexOf( type ) > -1 ? type : 'info';
		const options = opts || {};
		const timeout = options.timeout === undefined ? ( kind === 'danger' ? 0 : 5000 ) : options.timeout;
		let el = null;
		const close = () => {
			if ( el && el.parentNode ) {
				el.remove();
			}
		};
		const action = options.action && typeof options.action.run === 'function' ? h( 'button', {
			type: 'button',
			onClick() {
				close();
				options.action.run();
			},
		}, options.action.label ) : null;
		el = h(
			'div',
			{ class: 'fnfe-toast', dataset: { type: kind }, role: kind === 'danger' ? 'alert' : 'status', 'aria-live': kind === 'danger' ? 'assertive' : 'polite' },
			h( 'span', { class: 'fnfe-toast__text' }, message ),
			action,
			h( 'button', { type: 'button', 'aria-label': __( 'Close', 'forms-notifier-for-elementor' ), onClick: close }, '×' )
		);
		toastRegion().appendChild( el );
		if ( timeout > 0 ) {
			window.setTimeout( close, timeout );
		}
		return { close: close };
	}

	/* ------------------------------------------------------------ Helpers */

	const BLOCKED = [ '__proto__', 'constructor', 'prototype' ];

	const path = {
		get( obj, key, dflt ) {
			let node = obj;
			const parts = String( key ).split( '.' );
			for ( let i = 0; i < parts.length; i++ ) {
				if ( node === null || typeof node !== 'object' || ! Object.prototype.hasOwnProperty.call( node, parts[ i ] ) ) {
					return dflt;
				}
				node = node[ parts[ i ] ];
			}
			return node === undefined ? dflt : node;
		},
		set( obj, key, value ) {
			const parts = String( key ).split( '.' );
			if ( parts.some( ( part ) => BLOCKED.indexOf( part ) > -1 ) ) {
				return obj;
			}
			let node = obj;
			parts.slice( 0, -1 ).forEach( ( part ) => {
				if ( node[ part ] === null || typeof node[ part ] !== 'object' ) {
					node[ part ] = {};
				}
				node = node[ part ];
			} );
			node[ parts[ parts.length - 1 ] ] = value;
			return obj;
		},
		del( obj, key ) {
			const parts = String( key ).split( '.' );
			const last = parts.pop();
			const parent = parts.length ? path.get( obj, parts.join( '.' ) ) : obj;
			if ( parent && typeof parent === 'object' ) {
				delete parent[ last ];
			}
			return obj;
		},
	};

	function uid( prefix ) {
		const alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
		const bytes = new Uint8Array( 8 );
		window.crypto.getRandomValues( bytes );
		return ( prefix || '' ) + Array.from( bytes, ( byte ) => alphabet[ byte % 36 ] ).join( '' );
	}

	function debounce( fn, ms ) {
		let timer = 0;
		const run = function () {
			const args = arguments;
			window.clearTimeout( timer );
			timer = window.setTimeout( () => fn.apply( this, args ), ms );
		};
		run.cancel = () => window.clearTimeout( timer );
		return run;
	}

	function locale() {
		const raw = ( boot.user && boot.user.locale ) || ( boot.site && ( boot.site.locale || boot.site.lang ) ) || document.documentElement.lang || 'en';
		const tag = String( raw ).replace( /_/g, '-' ).split( '-' ).slice( 0, 2 ).join( '-' );
		try {
			return Intl.getCanonicalLocales( tag )[ 0 ] || 'en';
		} catch ( e ) {
			return 'en';
		}
	}

	function toDate( value ) {
		const date = value instanceof Date ? value : new Date( value );
		return value && ! isNaN( date.getTime() ) ? date : null;
	}

	const fmt = {
		date( iso, opts ) {
			const date = toDate( iso );
			if ( ! date ) {
				return '';
			}
			const options = Object.assign( {}, opts && Object.keys( opts ).length ? opts : { dateStyle: 'medium', timeStyle: 'short' } );
			const calendar = boot.site && boot.site.calendar === 'jalali' ? 'persian' : 'gregory';
			// Offsets such as "+03:30" are not accepted as time zone names everywhere.
			const zone = boot.site && boot.site.timezone;
			if ( zone && ! options.timeZone ) {
				options.timeZone = zone;
			}
			try {
				return new Intl.DateTimeFormat( locale() + '-u-ca-' + calendar, options ).format( date );
			} catch ( e ) {
				delete options.timeZone;
				return new Intl.DateTimeFormat( locale() + '-u-ca-' + calendar, options ).format( date );
			}
		},
		number( n ) {
			const value = Number( n );
			return isNaN( value ) ? '' : new Intl.NumberFormat( locale() ).format( value );
		},
		bytes( n ) {
			const units = [ 'byte', 'kilobyte', 'megabyte', 'gigabyte' ];
			let value = Math.max( 0, Number( n ) || 0 );
			let unit = 0;
			while ( value >= 1024 && unit < units.length - 1 ) {
				value /= 1024;
				unit++;
			}
			return new Intl.NumberFormat( locale(), { style: 'unit', unit: units[ unit ], unitDisplay: locale().indexOf( 'fa' ) === 0 ? 'long' : 'short', maximumFractionDigits: value < 10 && unit ? 1 : 0 } ).format( value );
		},
		relative( iso ) {
			const date = toDate( iso );
			if ( ! date ) {
				return '';
			}
			const seconds = Math.round( ( date.getTime() - Date.now() ) / 1000 );
			const steps = [ [ 'year', 31536000 ], [ 'month', 2592000 ], [ 'day', 86400 ], [ 'hour', 3600 ], [ 'minute', 60 ], [ 'second', 1 ] ];
			const step = steps.find( ( item ) => Math.abs( seconds ) >= item[ 1 ] ) || steps[ steps.length - 1 ];
			return new Intl.RelativeTimeFormat( locale(), { numeric: 'auto' } ).format( Math.trunc( seconds / step[ 1 ] ), step[ 0 ] );
		},
	};

	/* --------------------------------------------- Admin extension points */

	const renderers = {};
	const fields = {
		register( type, renderer ) {
			renderers[ type ] = renderer;
		},
		render( field, value, onChange, ctx ) {
			const all = wp.hooks.applyFilters( 'fnfe.fieldRenderers', renderers );
			const renderer = all[ field.type ] || all.text;
			return renderer ? renderer( field, value, onChange, ctx || {} ) : h( 'span', null, field.label || field.key );
		},
		form() {
			return { el: h( 'div', null ), validate: () => true, destroy() {} };
		},
	};

	const screenList = [];
	const screens = {
		register( pattern, def ) {
			const existing = screenList.find( ( item ) => item.pattern === pattern );
			if ( existing ) {
				existing.def = def;
			} else {
				screenList.push( { pattern: pattern, def: def } );
			}
		},
		all: () => screenList.slice(),
		get count() {
			return screenList.length;
		},
	};

	function icon( name, size ) {
		const px = size || 20;
		return h( 'svg', { viewBox: '0 0 24 24', width: px, height: px, fill: 'currentColor', 'aria-hidden': 'true', focusable: 'false' }, h( 'circle', { cx: 12, cy: 12, r: 3 } ) );
	}

	window.fnfe = {
		version: '1.0.0',
		boot: boot,
		h: h,
		frag: frag,
		mount: mount,
		safeHtml: safeHtml,
		api: api,
		store: store,
		bus: bus,
		router: router,
		i18n: wp.i18n,
		toast: toast,
		path: path,
		uid: uid,
		debounce: debounce,
		fmt: fmt,
		hooks: wp.hooks,
		ui: {},
		icon: icon,
		fields: fields,
		screens: screens,
	};

	/* Shown while no screen is registered. */
	function placeholder() {
		const root = document.getElementById( 'fnfe-app' );
		if ( ! root || window.fnfe.screens.count ) {
			return;
		}
		mount(
			root,
			h(
				'div',
				{ style: { maxWidth: '640px', margin: '48px auto', padding: '32px', background: 'var(--fnfe-surface)', color: 'var(--fnfe-ink)', border: '1px solid var(--fnfe-line)', borderRadius: 'var(--fnfe-radius-l)', fontFamily: 'var(--fnfe-font)' } },
				h( 'h1', null, __( 'Forms Notifier', 'forms-notifier-for-elementor' ) ),
				h( 'p', null, __( 'The panel is not available yet.', 'forms-notifier-for-elementor' ) ),
				h( 'p', { dir: 'ltr', style: { fontFamily: 'var(--fnfe-font-mono)' } }, root.dataset.route || '' )
			)
		);
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', placeholder );
	} else {
		window.setTimeout( placeholder, 0 );
	}
}() );
