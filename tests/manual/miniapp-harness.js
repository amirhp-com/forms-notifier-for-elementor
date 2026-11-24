/* Mini app harness, see miniapp-harness.html. */
( function () {
	'use strict';

	const query = new URLSearchParams( window.location.search );
	const run = query.get( 'run' ) || 'bridge';
	const assets = '../../forms-notifier-for-elementor/assets/';
	const platform = query.get( 'platform' ) || 'telegram';
	const LIGHT = { bg_color: '#ffffff', secondary_bg_color: '#f1f1f4', section_bg_color: '#ffffff', text_color: '#000000', hint_color: '#999999', link_color: '#2481cc', button_color: '#40a7e3', button_text_color: '#ffffff', destructive_text_color: '#d14e4e' };
	const DARK = { bg_color: '#17212b', secondary_bg_color: '#232e3c', section_bg_color: '#17212b', text_color: '#f5f5f5', hint_color: '#708499', link_color: '#6ab3f3', button_color: '#5288c1', button_text_color: '#ffffff', destructive_text_color: '#ec3942' };
	const INIT = 'query_id=AAE&user=' + encodeURIComponent( '{"id":111111,"first_name":"Sara & Co","username":"sara_example"}' ) + '&auth_date=1767261600&start_param=sub_7&signature=abc&hash=' + 'a1'.repeat( 32 );

	/* Stand-in for the WordPress packages. */
	const format = ( text, args ) => {
		let next = 0;
		return String( text ).replace( /%(?:(\d+)\$)?[sd]/g, ( m, index ) => args[ index ? index - 1 : next++ ] );
	};
	window.wp = {
		i18n: {
			__: ( text ) => text,
			_x: ( text ) => text,
			_n: ( one, many, count ) => ( count === 1 ? one : many ),
			sprintf: ( text, ...args ) => format( text, args ),
		},
		hooks: { applyFilters: ( name, value ) => value, doAction() {} },
	};

	function load( file ) {
		return new Promise( ( resolve, reject ) => {
			const el = document.createElement( 'script' );
			el.src = file.indexOf( '/' ) > -1 ? assets + file : file;
			el.async = false;
			el.onload = resolve;
			el.onerror = reject;
			document.body.appendChild( el );
		} );
	}

	function launchHash( data, theme ) {
		return '#tgWebAppData=' + encodeURIComponent( data ) + '&tgWebAppVersion=8.0&tgWebAppPlatform=ios&tgWebAppThemeParams=' + encodeURIComponent( JSON.stringify( theme ) );
	}

	/* ---------------------------------------------------------------- Bridge checks */

	const results = { passed: 0, failed: 0, done: false, lines: [] };
	window.harness = results;

	function check( name, ok, detail ) {
		results[ ok ? 'passed' : 'failed' ]++;
		results.lines.push( ( ok ? 'ok    ' : 'FAIL  ' ) + name + ( ok || detail === undefined ? '' : ' -> ' + JSON.stringify( detail ) ) );
		document.getElementById( 'log' ).textContent = results.lines.join( '\n' );
	}

	/* One bridge in a fresh frame. setup( win ) runs before the bridge loads. */
	function frame( options ) {
		return new Promise( ( resolve ) => {
			const el = document.createElement( 'iframe' );
			const messages = [];
			const listener = ( event ) => {
				if ( event.source === el.contentWindow && typeof event.data === 'string' ) {
					try {
						messages.push( JSON.parse( event.data ) );
					} catch ( e ) {
						messages.push( { eventType: 'unparsable' } );
					}
				}
			};
			window.addEventListener( 'message', listener );
			el.style.cssText = 'width:320px;height:40px;border:0';
			el.onload = () => resolve( { win: el.contentWindow, el: el, messages: messages, close() {
				window.removeEventListener( 'message', listener );
				el.remove();
			} } );
			el.src = 'miniapp-harness.html?run=inner&platform=' + ( options.platform || 'telegram' ) + '&inner=' + ( options.inner || 'frame' ) + ( options.search || '' ) + ( options.hash || '' );
			document.body.appendChild( el );
		} );
	}

	const pause = ( ms ) => new Promise( ( resolve ) => window.setTimeout( resolve, ms ) );

	async function ready( f ) {
		for ( let i = 0; i < 80 && ! ( f.win.innerReady ); i++ ) {
			await pause( 25 );
		}
		await pause( 60 );
		return f.win.fnfe.host;
	}

	async function bridge() {
		const hash = launchHash( INIT, LIGHT );

		let f = await frame( { hash: hash, inner: 'proxy' } );
		let host = await ready( f );
		let sent = f.win.sent;
		check( 'launch data is read unchanged', host.initData === INIT, host.initData );
		check( 'available, version, client platform', host.available && host.version === '8.0' && host.clientPlatform === 'ios' );
		check( 'start parameter from the launch data', host.startParam === 'sub_7', host.startParam );
		check( 'theme and colour scheme', host.themeParams.bg_color === '#ffffff' && host.colorScheme === 'light' );
		check( 'proxy transport is found', host.transport() === 'proxy', host.transport() );
		check( 'ready, expand and theme request are sent', [ 'web_app_ready', 'web_app_expand', 'web_app_request_theme' ].every( ( name ) => sent.some( ( item ) => item[ 0 ] === name ) ), sent );
		check( 'event data is a JSON string', sent.every( ( item ) => typeof item[ 1 ] === 'string' && typeof JSON.parse( item[ 1 ] ) === 'object' ) );
		check( 'no postMessage next to the proxy', f.messages.filter( ( m ) => m.eventType === 'web_app_ready' ).length === 0 );
		sent.length = 0;
		host.backButton.show();
		host.backButton.show();
		check( 'back button is announced once', JSON.stringify( sent ) === JSON.stringify( [ [ 'web_app_setup_back_button', '{"is_visible":true}' ] ] ), sent );
		sent.length = 0;
		host.openLink( 'https://example.com/a?b=1' );
		host.openLink( 'javascript:alert(1)' );
		check( 'openLink sends http links only', sent.length === 1 && JSON.parse( sent[ 0 ][ 1 ] ).url === 'https://example.com/a?b=1', sent );
		sent.length = 0;
		host.openTelegramLink( 'https://t.me/sample_bot?start=x' );
		check( 'openTelegramLink sends the path', sent[ 0 ][ 0 ] === 'web_app_open_tg_link' && JSON.parse( sent[ 0 ][ 1 ] ).path_full === '/sample_bot?start=x', sent );
		sent.length = 0;
		host.setHeaderColor( '#abc' );
		host.setHeaderColor( 'red;x' );
		check( 'colours are validated and normalised', sent.length === 1 && JSON.parse( sent[ 0 ][ 1 ] ).color === '#aabbcc', sent );
		sent.length = 0;
		host.haptic( 'success' );
		host.haptic( 'selection' );
		host.haptic( 'impact' );
		check( 'haptic shapes', sent.map( ( item ) => item[ 1 ] ).join() === '{"type":"notification","notification_type":"success"},{"type":"selection_change"},{"type":"impact","impact_style":"light"}', sent );
		sent.length = 0;
		host.mainButton.set( { text: 'Go', visible: true } );
		check( 'main button', JSON.parse( sent[ 0 ][ 1 ] ).text === 'Go' && JSON.parse( sent[ 0 ][ 1 ] ).is_visible === true, sent );
		host.close();
		check( 'close', sent[ 1 ][ 0 ] === 'web_app_close' );

		let hits = 0;
		host.backButton.onClick( () => hits++ );
		f.win.Telegram.WebView.receiveEvent( 'back_button_pressed', null );
		f.win.TelegramGameProxy.receiveEvent( 'back_button_pressed' );
		f.win.TelegramGameProxy_receiveEvent( 'back_button_pressed', {} );
		f.win.Bale.receiveEvent( 'backButtonPressed', {} );
		check( 'every native entry point delivers events', hits === 4, hits );
		f.win.Telegram.WebView.receiveEvent( 'theme_changed', { theme_params: DARK } );
		check( 'theme_changed updates theme and scheme', host.themeParams.bg_color === '#17212b' && host.colorScheme === 'dark' );
		f.win.Telegram.WebView.receiveEvent( 'theme_changed', { theme_params: { bg_color: 'url(x)' } } );
		check( 'bad theme values are ignored', host.themeParams.bg_color === '#17212b' );
		f.win.postMessage( JSON.stringify( { eventType: 'back_button_pressed', eventData: {} } ), '*' );
		await pause( 60 );
		check( 'messages of the parent are accepted', hits === 5, hits );
		f.win.eval( 'window.postMessage( JSON.stringify( { eventType: "back_button_pressed" } ), "*" )' );
		await pause( 60 );
		check( 'messages of other windows are ignored', hits === 5, hits );
		f.win.postMessage( '{broken', '*' );
		f.win.postMessage( { eventType: 'back_button_pressed' }, '*' );
		await pause( 60 );
		check( 'broken messages are ignored', hits === 5, hits );
		f.close();

		f = await frame( { hash: '', inner: 'proxy' } );
		host = await ready( f );
		check( 'launch data survives a reload without the hash', host.initData === INIT );
		f.close();
		window.sessionStorage.clear();

		f = await frame( { hash: hash, inner: 'frame' } );
		host = await ready( f );
		check( 'frame transport is found', host.transport() === 'frame', host.transport() );
		check( 'frame: iframe_ready and web_app_ready reach the parent', f.messages.some( ( m ) => m.eventType === 'iframe_ready' && m.eventData.reload_supported === true ) && f.messages.some( ( m ) => m.eventType === 'web_app_ready' ), f.messages );
		f.close();
		window.sessionStorage.clear();

		f = await frame( { hash: hash, inner: 'bale', platform: 'bale' } );
		host = await ready( f );
		check( 'bale: native object is used', host.platform === 'bale' && host.transport() === 'bale' && f.win.sent.some( ( item ) => item[ 0 ] === 'web_app_ready' ), f.win.sent );
		f.close();
		window.sessionStorage.clear();

		f = await frame( { hash: '#tgWebAppData=' + encodeURIComponent( encodeURIComponent( INIT ) ) + '&tgWebAppThemeParams=' + encodeURIComponent( encodeURIComponent( JSON.stringify( DARK ) ) ), inner: 'frame', platform: 'bale', search: '&tgWebAppStartParam=form_3' } );
		host = await ready( f );
		check( 'bale: data that was encoded twice', host.initData === INIT && host.themeParams.bg_color === '#17212b', host.initData );
		check( 'start parameter from the address', host.startParam === 'form_3', host.startParam );
		f.close();
		window.sessionStorage.clear();

		f = await frame( { hash: '#/submission/9' + '?' + hash.slice( 1 ).replace( 'start_param%3Dsub_7%26', '' ), inner: 'frame' } );
		host = await ready( f );
		check( 'a path in front of the launch data', host.available && host.startParam === 'sub_9', host.startParam );
		f.close();
		window.sessionStorage.clear();

		f = await frame( { hash: '', inner: 'none' } );
		host = await ready( f );
		check( 'no launch data', ! host.available && host.initData === '' && host.startParam === '' );
		check( 'no transport: calls do nothing and do not throw', host.transport() === '' && host.post( 'web_app_ready' ) === false );
		f.close();

		results.done = true;
	}

	function inner() {
		const kind = query.get( 'inner' );
		window.sent = [];
		const record = ( type, data ) => window.sent.push( [ type, data ] );
		if ( kind === 'proxy' ) {
			window.TelegramWebviewProxy = { postEvent: record };
		} else if ( kind === 'bale' ) {
			window.BaleWebApp = { postEvent: record };
		} else if ( kind === 'none' ) {
			// Looks like a top level page.
			Object.defineProperty( window, 'parent', { value: window } );
		}
		window.fnfeBoot = { platform: platform };
		load( 'js/core/fnfe.js' ).then( () => load( 'js/miniapp/bridge.js' ) ).then( () => {
			window.innerReady = true;
		} );
	}

	/* ---------------------------------------------------------------- App with canned answers */

	const state = query.get( 'state' ) || '';
	const rtl = query.get( 'dir' ) === 'rtl';
	const ago = ( minutes ) => new Date( Date.now() - minutes * 60000 ).toISOString().slice( 0, 19 ) + 'Z';
	const FORMS = [ [ 'Contact us', 'Contact' ], [ rtl ? 'درخواست مشاوره رایگان' : 'Free consultation request', rtl ? 'صفحه اصلی' : 'Home page' ], [ 'Newsletter', 'Footer' ], [ 'Job application with a rather long name that does not fit', 'Careers and open positions' ], [ rtl ? 'رزرو وقت' : 'Booking', 'Booking' ], [ 'Support', 'Help center' ] ]
		.map( ( item, i ) => ( { id: i + 1, form_key: '1:a', name: item[ 0 ], source: 'pro', post_id: 1, post_title: item[ 1 ], post_type: 'page', edit_url: '', status: 'active', enabled: 'inherit', active: i !== 4, submissions_count: [ 128, 64, 1290, 7, 0, 31 ][ i ], last_submission: i === 4 ? null : ago( [ 3, 95, 1500, 9000, 0, 40000 ][ i ] ), fields_count: 5, channels: [ 'telegram' ], unread: [ 4, 1, 0, 0, 0, 12 ][ i ] } ) );
	const PREVIEWS = rtl
		? [ 'نام: سارا محمدی، موبایل: 09120000000، پیام: سلام، برای ساعت کاری سوال داشتم', 'نام: Reza، ایمیل: reza@example.com', 'نام: نگار، پیام: ممنون از پاسخ سریع شما' ]
		: [ 'Name: Sara Example, Phone: +989120000000, Message: Hello, I have a question about opening hours', 'Name: Reza, Email: reza@example.com', 'Name: Negar, Message: Thanks for the quick answer' ];
	const submissions = Array.from( { length: 43 }, ( v, i ) => ( { id: 100 - i, form_id: 1, form_name: FORMS[ 1 ].name, status: [ 'new', 'new', 'read', 'done', 'spam', 'read' ][ i % 6 ], lang: rtl ? 'fa' : 'en', created: ago( 5 + i * 190 ), created_local: rtl ? '۱۴۰۵/۰۹/۰۴ ۱۲:۳۰' : 'November 25, 2026 12:30 pm', preview: PREVIEWS[ i % 3 ], files_count: i % 4 === 0 ? 2 : 0, delivery_status: [ 'sent', 'failed', 'queued', 'partial', 'none' ][ i % 5 ] } ) );
	const messages = [
		{ id: 1, submission_id: 100, form_id: 1, form_name: FORMS[ 0 ].name, channel: 'telegram', channel_label: 'Telegram', provider: '', purpose: 'admin', recipient: '-1001234567890', recipient_label: rtl ? 'گروه فروش' : 'Sales group', lang: 'en', status: 'sent', attempts: 1, max_attempts: 5, error: '', created: ago( 5 ), sent: ago( 5 ), next_attempt: null },
		{ id: 2, submission_id: 100, form_id: 1, form_name: FORMS[ 0 ].name, channel: 'sms', channel_label: 'SMS', provider: 'x', purpose: 'admin', recipient: '+989120000000', recipient_label: '', lang: 'en', status: 'failed', attempts: 5, max_attempts: 5, error: 'The gateway answered: not enough credit (code 418). Charge the account and try again.', created: ago( 65 ), sent: null, next_attempt: null },
		{ id: 3, submission_id: 100, form_id: 2, form_name: FORMS[ 1 ].name, channel: 'email', channel_label: 'Email', provider: '', purpose: 'submitter', recipient: 'sara@example.com', recipient_label: 'Sara', lang: 'en', status: 'retry', attempts: 2, max_attempts: 5, error: 'SMTP connect() failed.', created: ago( 15 ), sent: null, next_attempt: new Date( Date.now() + 300000 ).toISOString() },
	];
	const field = ( id, type, label, value, values, extra ) => Object.assign( { id: id, type: type, label: label, value: value, values: values || [ value ], is_file: false, redacted: false }, extra || {} );
	const LONG = rtl ? 'سلام و وقت بخیر. برای یک پروژه فروشگاهی به مشاوره نیاز دارم. '.repeat( 12 ) : 'Hello, I am planning a shop and I would like to know how long it takes and what it costs. '.repeat( 9 );
	const detail = Object.assign( {}, submissions[ 0 ], {
		uuid: 'a'.repeat( 32 ),
		fields: [ field( 'name', 'text', rtl ? 'نام و نام خانوادگی' : 'Name', rtl ? 'سارا محمدی' : 'Sara <b>Example</b>' ), field( 'phone', 'tel', rtl ? 'موبایل' : 'Phone', '+989120000000' ), field( 'email', 'email', 'Email', 'sara@example.com' ), field( 'site', 'url', 'Website', 'https://example.com/a/very/long/path/that/has/to/wrap/somewhere?with=query&and=more' ), field( 'topics', 'checkbox', 'Topics', 'sales, support', [ 'sales', 'support', rtl ? 'طراحی سایت' : 'design' ] ), field( 'msg', 'textarea', rtl ? 'پیام' : 'Message', LONG ), field( 'pw', 'password', 'Password', '', [], { redacted: true } ), field( 'opt', 'text', 'Optional', '', [] ) ],
		files: [ { id: 1, field_id: 'cv', name: 'curriculum-vitae-with-a-long-name-2026-final.pdf', size: 483210, mime: 'application/pdf', is_image: false, url: 'https://example.com/file/1?t=x' }, { id: 2, field_id: 'photo', name: 'photo.jpg', size: 1893210, mime: 'image/jpeg', is_image: true, url: '' } ],
		meta: { page_url: 'https://example.com/contact/', page_title: rtl ? 'تماس با ما' : 'Contact us', user_id: 0, user_login: '', user_ip: '203.0.113.5', user_agent: '', via: 'auto' },
	} );

	function answer( path, params, body ) {
		const list = ( items ) => {
			const page = Number( params.get( 'page' ) || 1 );
			return { items: items.slice( ( page - 1 ) * 20, page * 20 ), total: items.length, page: page, per_page: 20 };
		};
		const fail = ( status, code, message, fields ) => ( { status: status, data: { code: code, message: message, data: { status: status, fields: fields || {} } } } );
		if ( path === 'app/auth' ) {
			if ( state === 'denied' ) {
				return fail( 403, 'fnfe_app_not_allowed', 'You do not have access to this app. Ask the site owner to add your id: 111111', { user_id: '111111' } );
			}
			if ( state === 'expired' ) {
				return fail( 401, 'fnfe_app_expired', 'The session expired. Open the app again from the bot.' );
			}
			if ( state === 'disabled' ) {
				return fail( 403, 'fnfe_app_disabled', 'The mini app is turned off on this site.' );
			}
			return { token: 't.s', expires: Math.round( Date.now() / 1000 ) + 7200, user: { id: '111111', name: 'Sara', username: 'sara_example', photo: '' }, perms: state === 'readonly' ? [ 'view' ] : [ 'view', 'act' ], site: { name: rtl ? 'فروشگاه نمونه' : 'Sample studio', url: 'https://example.com/', lang: rtl ? 'fa' : 'en', rtl: rtl } };
		}
		if ( state === 'error' ) {
			return fail( 500, 'fnfe_error', 'Something went wrong.' );
		}
		const none = state === 'empty';
		if ( path === 'app/digest' ) {
			const days = params.get( 'range' ) === '30d' ? 30 : params.get( 'range' ) === 'today' ? 1 : 7;
			const series = Array.from( { length: days }, ( v, i ) => ( { date: new Date( Date.now() - ( days - 1 - i ) * 86400000 ).toISOString().slice( 0, 10 ), submissions: none ? 0 : [ 3, 9, 0, 6, 12, 4, 8 ][ i % 7 ], sent: 0, failed: 0 } ) );
			return {
				stats: { range: '7d', submissions: series.reduce( ( sum, day ) => sum + day.submissions, 0 ), messages: none ? { sent: 0, failed: 0, queued: 0 } : { sent: 84, failed: 2, queued: 3 }, by_channel: none ? {} : { telegram: { sent: 42, failed: 0 }, sms: { sent: 30, failed: 2 }, email: { sent: 12, failed: 0 } }, by_form: [], series: series },
				health: none ? [] : [ { id: 'a', label: 'Elementor Pro forms', status: 'good', message: '' }, { id: 'b', label: 'Scheduled tasks are late', status: 'warning', message: 'The queue last ran 45 minutes ago.' } ],
				queue: { pending: none ? 0 : 3 },
				site: { name: 'Sample studio', url: 'https://example.com/', lang: 'en', rtl: rtl, forms: none ? 0 : 6, unread: none ? 0 : 17, total: none ? 0 : 1520, pages: 14, posts: 32, users: 5, comments: 2, updates: 3, wp: '6.9', php: '8.3', plugin: '1.0.0', theme: 'Hello Elementor', time: rtl ? '۱۴۰۵/۰۹/۰۴ ۱۲:۳۰' : 'November 25, 2026 12:30 pm', channels: none ? [] : [ { id: 'telegram', label: 'Telegram', ready: true }, { id: 'sms', label: 'SMS', ready: true }, { id: 'email', label: 'Email', ready: true }, { id: 'bale', label: 'Bale', ready: false } ] },
			};
		}
		if ( path === 'app/forms' ) {
			const search = ( params.get( 'search' ) || '' ).toLowerCase();
			return list( none ? [] : FORMS.filter( ( form ) => form.name.toLowerCase().indexOf( search ) > -1 ) );
		}
		if ( /^app\/forms\/\d+\/submissions$/.test( path ) ) {
			const status = params.get( 'status' );
			return list( none ? [] : submissions.filter( ( item ) => ! status || item.status === status ) );
		}
		if ( /\/status$/.test( path ) ) {
			detail.status = body.status;
			submissions[ 0 ].status = body.status;
			return { ok: true, submission: detail };
		}
		if ( /^app\/submissions\/\d+$/.test( path ) ) {
			return none ? fail( 404, 'fnfe_not_found', 'This submission does not exist any more.' ) : { submission: detail, messages: messages };
		}
		if ( path === 'app/messages' ) {
			const status = params.get( 'status' );
			return list( none ? [] : messages.filter( ( item ) => ( status ? item.status === status : item.status !== 'sent' ) ) );
		}
		const retry = path.match( /^app\/messages\/(\d+)\/retry$/ );
		if ( retry ) {
			const message = messages.find( ( item ) => item.id === Number( retry[ 1 ] ) );
			Object.assign( message, { status: 'sent', sent: ago( 0 ), error: '' } );
			return { ok: true, message: message };
		}
		return fail( 404, 'rest_no_route', 'No route.' );
	}

	function app() {
		const theme = query.get( 'theme' ) === '0' ? {} : query.get( 'dark' ) ? DARK : LIGHT;
		if ( rtl ) {
			document.documentElement.dir = 'rtl';
			document.documentElement.lang = 'fa-IR';
			document.body.dataset.font = 'fa';
			const font = document.createElement( 'link' );
			font.rel = 'stylesheet';
			font.href = assets + 'css/fonts-fa.css';
			document.head.appendChild( font );
		}
		document.getElementById( 'log' ).remove();
		window.sent = [];
		if ( query.get( 'transport' ) !== 'none' ) {
			window.TelegramWebviewProxy = { postEvent: ( type, data ) => window.sent.push( [ type, data ] ) };
		}
		if ( query.get( 'launch' ) !== '0' ) {
			const start = query.get( 'start' );
			window.history.replaceState( null, '', window.location.search + launchHash( INIT.replace( 'start_param=sub_7&', start ? 'start_param=' + start + '&' : '' ), theme ) );
		}
		window.fnfeBoot = { app: true, platform: platform, rest: { root: '/mock/' }, site: { name: rtl ? 'فروشگاه نمونه' : 'Sample studio', lang: rtl ? 'fa' : 'en', locale: rtl ? 'fa_IR' : 'en_US', rtl: rtl, calendar: rtl ? 'jalali' : 'gregorian' }, bot: 'https://t.me/sample_bot', version: '1.0.0', page_size: 20, strings: {} };
		window.calls = [];
		window.fetch = ( url, options ) => {
			const parsed = new URL( url, window.location.href );
			const path = parsed.pathname.replace( /^\/mock\//, '' );
			window.calls.push( { method: options.method, path: path, search: parsed.search, token: options.headers[ 'X-FNFE-Token' ] || '' } );
			let result = answer( path, parsed.searchParams, options.body ? JSON.parse( options.body ) : null );
			const status = result && result.status && result.data ? result.status : 200;
			if ( status !== 200 ) {
				result = result.data;
			}
			return pause( state === 'slow' ? 600000 : 120 ).then( () => new Response( JSON.stringify( result ), { status: status } ) );
		};
		load( 'js/core/fnfe.js' ).then( () => load( 'js/miniapp/bridge.js' ) ).then( () => load( 'js/miniapp/app.js' ) );
	}

	( { bridge: bridge, inner: inner, app: app }[ run ] || bridge )();
}() );
