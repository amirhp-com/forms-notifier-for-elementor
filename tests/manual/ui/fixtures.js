/**
 * Sample data for looking at the admin panel without a busy site.
 *
 * Load this file before the scripts of the panel (DevTools "Page.addScriptToEvaluateOnNewDocument", or a local
 * override). It answers the read routes below with sample data; everything else goes to the real REST API.
 * window.fnfeFixtures.mode: 'data' (default), 'empty', 'error', 'slow'.
 */
( function () {
	'use strict';

	const config = ( window.fnfeFixtures = Object.assign( { mode: 'data', fa: false }, window.fnfeFixtures ) );
	const realFetch = window.fetch.bind( window );
	const t = ( en, fa ) => ( config.fa ? fa : en );
	const ago = ( minutes ) => new Date( Date.now() - minutes * 60000 ).toISOString().slice( 0, 19 ) + 'Z';
	const list = ( items, query ) => ( { items: items, total: items.length, page: Number( query.get( 'page' ) ) || 1, per_page: Number( query.get( 'per_page' ) ) || 20 } );

	const forms = () => [
		{ id: 1, form_key: '45:a1b2c3d', name: t( 'Contact us', 'تماس با ما' ), source: 'pro', post_id: 45, post_title: t( 'Contact', 'تماس' ), post_type: 'page', edit_url: '#', status: 'active', enabled: 'inherit', active: true, submissions_count: 128, last_submission: ago( 12 ), fields_count: 5, channels: [ 'telegram', 'email', 'sms' ] },
		{ id: 2, form_key: '61:b7c8d9e', name: t( 'Book an appointment', 'رزرو نوبت' ), source: 'pro', post_id: 61, post_title: t( 'Appointments', 'نوبت‌دهی' ), post_type: 'page', edit_url: '#', status: 'active', enabled: 'on', active: true, submissions_count: 74, last_submission: ago( 95 ), fields_count: 7, channels: [ 'telegram', 'bale', 'sms', 'whatsapp', 'email', 'webhook' ] },
		{ id: 3, form_key: '88:c0d1e2f', name: t( 'Newsletter', 'خبرنامه' ), source: 'pro', post_id: 88, post_title: t( 'Footer', 'پاورقی' ), post_type: 'elementor_library', edit_url: '#', status: 'active', enabled: 'off', active: false, submissions_count: 412, last_submission: ago( 1500 ), fields_count: 1, channels: [] },
		{ id: 4, form_key: '90:d3e4f5a', name: t( 'Job application', 'درخواست همکاری' ), source: 'pro', post_id: 90, post_title: t( 'Careers', 'همکاری با ما' ), post_type: 'page', edit_url: '#', status: 'active', enabled: 'inherit', active: true, submissions_count: 9, last_submission: ago( 4300 ), fields_count: 6, channels: [ 'telegram' ] },
	];

	const fields = () => [
		{ id: 'name', type: 'text', label: t( 'Full name', 'نام و نام خانوادگی' ) },
		{ id: 'email', type: 'email', label: t( 'Email', 'ایمیل' ) },
		{ id: 'phone', type: 'tel', label: t( 'Phone', 'شماره موبایل' ) },
		{ id: 'plan', type: 'select', label: t( 'Plan', 'طرح' ), options: [ t( 'Basic|basic', 'پایه|basic' ), t( 'VIP|vip', 'ویژه|vip' ) ] },
		{ id: 'message', type: 'textarea', label: t( 'Message', 'پیام' ) },
	];

	const submissions = () => [
		[ 981, 'new', 'sent', t( 'Full name: Sara Ahmadi, Phone: +989120000000, Plan: VIP', 'نام و نام خانوادگی: سارا احمدی، شماره موبایل: +989120000000، طرح: ویژه' ), 1, 12, 1 ],
		[ 980, 'new', 'partial', t( 'Full name: Daniel Novak, Email: daniel@example.com', 'نام و نام خانوادگی: دانیال نوری، ایمیل: daniel@example.com' ), 2, 95, 0 ],
		[ 979, 'read', 'sent', t( 'Full name: Mahsa Karimi, Message: Could you call me back on Sunday morning?', 'نام و نام خانوادگی: مهسا کریمی، پیام: لطفاً صبح یکشنبه تماس بگیرید.' ), 1, 240, 0 ],
		[ 978, 'done', 'failed', t( 'Full name: Omid Rahimi, Plan: Basic', 'نام و نام خانوادگی: امید رحیمی، طرح: پایه' ), 4, 1400, 2 ],
		[ 977, 'read', 'queued', t( 'Email: lena@example.com', 'ایمیل: lena@example.com' ), 3, 1520, 0 ],
	].map( ( row ) => ( { id: row[ 0 ], form_id: row[ 4 ], form_name: forms()[ row[ 4 ] - 1 ].name, status: row[ 1 ], lang: config.fa ? 'fa' : 'en', created: ago( row[ 5 ] ), created_local: t( 'Nov 25, 2026, 10:14', '۱۴۰۵/۰۹/۰۴ ۱۰:۱۴' ), preview: row[ 3 ], files_count: row[ 6 ], delivery_status: row[ 2 ] } ) );

	const messages = () => [
		[ 5012, 'telegram', 'sent', 1, t( 'Sales group', 'گروه فروش' ), '-1001234567890/45', '', 'admin', 12 ],
		[ 5011, 'sms', 'retry', 2, t( 'Reception', 'پذیرش' ), '+989120000000', t( 'The gateway answered with status 503.', 'سامانه پیامک پاسخ ۵۰۳ داد.' ), 'admin', 13 ],
		[ 5010, 'email', 'sent', 1, '', 'desk@example.com', '', 'admin', 95 ],
		[ 5009, 'bale', 'failed', 5, t( 'Managers', 'مدیران' ), '4567890123', t( 'Forbidden: bot was kicked from the group chat', 'ربات از گروه حذف شده است.' ), 'admin', 96 ],
		[ 5008, 'whatsapp', 'queued', 0, '', '+989120000001', '', 'submitter', 97 ],
		[ 5007, 'webhook', 'skipped', 0, 'n8n', 'e_1a2b3c4d', t( 'The channel is not ready.', 'کانال آماده نیست.' ), 'admin', 240 ],
		[ 5006, 'telegram', 'sent', 1, t( 'On call', 'کشیک' ), '@oncall_example', '', 'alert', 260 ],
	].map( ( row ) => ( { id: row[ 0 ], submission_id: 981, form_id: 1, form_name: forms()[ 0 ].name, channel: row[ 1 ], channel_label: { telegram: t( 'Telegram', 'تلگرام' ), sms: t( 'SMS', 'پیامک' ), email: t( 'Email', 'ایمیل' ), bale: t( 'Bale', 'بله' ), whatsapp: t( 'WhatsApp', 'واتس‌اپ' ), webhook: t( 'Webhook', 'وب‌هوک' ) }[ row[ 1 ] ], provider: row[ 1 ] === 'sms' ? 'kavenegar' : row[ 1 ], purpose: row[ 7 ], recipient: row[ 5 ], recipient_label: row[ 4 ], lang: '', status: row[ 2 ], attempts: row[ 3 ], max_attempts: 5, error: row[ 6 ], created: ago( row[ 8 ] ), sent: row[ 2 ] === 'sent' ? ago( row[ 8 ] ) : null, next_attempt: row[ 2 ] === 'retry' ? ago( -4 ) : null } ) );

	const stats = ( range ) => {
		const days = { today: 1, '7d': 7, '30d': 30 }[ range ] || 30;
		const series = Array.from( { length: days }, ( x, i ) => {
			const n = 4 + ( ( days - i ) * 7 ) % 11;
			return { date: new Date( Date.now() - ( days - 1 - i ) * 86400000 ).toISOString().slice( 0, 10 ), submissions: n, sent: n * 3, failed: i % 4 === 1 ? 2 : 0 };
		} );
		const sum = ( key ) => series.reduce( ( total, day ) => total + day[ key ], 0 );
		return { range: range, submissions: sum( 'submissions' ), messages: { sent: sum( 'sent' ), failed: sum( 'failed' ), queued: 3 }, by_channel: { telegram: { sent: 64, failed: 1 }, email: { sent: 42, failed: 0 }, sms: { sent: 31, failed: 5 }, bale: { sent: 3, failed: 7 }, webhook: { sent: 18, failed: 0 } }, by_form: [ { form_id: 1, name: forms()[ 0 ].name, submissions: 38, failed: 2 }, { form_id: 2, name: forms()[ 1 ].name, submissions: 21, failed: 1 }, { form_id: 4, name: forms()[ 3 ].name, submissions: 4, failed: 0 } ], series: series, queue: { pending: 3, next_run: ago( -1 ) }, health: [ { id: 'queue', label: t( 'Queue runs', 'صف ارسال کار می‌کند' ), status: 'good', message: '' }, { id: 'channels', label: t( 'Bale is not ready', 'کانال بله آماده نیست' ), status: 'warning', message: t( 'Enter the bot token.', 'توکن ربات را وارد کنید.' ), route: 'channels/bale' } ] };
	};

	const route = ( path, query ) => {
		const match = ( pattern ) => path.match( pattern );
		if ( path === 'stats' ) {
			return stats( query.get( 'range' ) );
		}
		if ( path === 'forms' ) {
			return Object.assign( list( /missing/.test( query.get( 'status' ) || '' ) ? [] : forms(), query ), { last_scan: ago( 30 ) } );
		}
		if ( match( /^forms\/\d+$/ ) ) {
			const form = forms()[ Number( path.split( '/' )[ 1 ] ) - 1 ];
			return { form: form, fields: fields(), editor: {}, effective: { channels: { telegram: { active: true, receivers: 2, source: 'form' }, email: { active: true, receivers: 1, source: 'global' }, sms: { active: true, receivers: 1, source: 'editor' } } }, config: { v: 1, link: 0, enabled: form.enabled, channels: { telegram: { enabled: 'on', receivers_mode: 'append', receivers: [ { id: 'r_k3j9x2ab', address: '-1001234567890/45', label: t( 'Sales group › Leads', 'گروه فروش › سرنخ‌ها' ), enabled: true, lang: '', langs: [], meta: { type: 'topic' } } ], template_mode: 'custom', template: { _: '<b>' + t( 'New lead', 'سرنخ تازه' ) + '</b>\n\n{all_fields}' }, subject: { _: '' }, buttons_mode: 'append', buttons: [ { id: 'b_11111111', text: { _: t( 'Open submission', 'نمایش ارسالی' ) }, type: 'submission', value: '', row: 0, style: 'primary' }, { id: 'b_22222222', text: { _: t( 'Done', 'انجام شد' ) }, type: 'callback', value: 'done', row: 1, style: 'success' }, { id: 'b_33333333', text: { _: t( 'Spam', 'هرزنامه' ) }, type: 'callback', value: 'spam', row: 1, style: 'danger' } ], options: {} } }, submitter: { enabled: true, consent_field: '', channels: { email: { enabled: true, field: '', template: { _: '' }, subject: { _: '' } } } }, rules: [ { id: 'q_1a2b3c4d', name: t( 'VIP plan to sales', 'طرح ویژه برای فروش' ), enabled: true, match: 'all', conditions: [ { field: 'plan', op: 'is', value: 'vip' } ], effect: 'add', channels: [ 'telegram' ], receivers: { telegram: [ { id: 'r_aaaaaaaa', address: '@sales_example', label: '', enabled: true, lang: '', langs: [], meta: {} } ] }, stop: false }, { id: 'q_2a2b3c4d', name: '', enabled: false, match: 'any', conditions: [ { field: 'email', op: 'ends', value: '@example.com' } ], effect: 'skip', channels: [], receivers: {}, stop: true } ], attachments: { mode: 'inherit' }, fields: { phone: '', email: '', name: '', country: '', sensitive: [], exclude: [], labels: {} }, quiet_hours: 'inherit' } };
		}
		if ( path === 'submissions' ) {
			return Object.assign( list( submissions(), query ), { counts: { new: 2, read: 31, done: 118, spam: 4, trash: 0 } } );
		}
		if ( match( /^submissions\/\d+$/ ) ) {
			const item = submissions().find( ( row ) => row.id === Number( path.split( '/' )[ 1 ] ) ) || submissions()[ 0 ];
			const values = [ t( 'Sara Ahmadi', 'سارا احمدی' ), 'sara@example.com', '+989120000000', 'vip', t( 'Hello, I would like to know more about the VIP plan.\nPlease call me in the morning.', 'سلام، دربارهٔ طرح ویژه اطلاعات بیشتری می‌خواهم.\nلطفاً صبح تماس بگیرید.' ) ];
			return { submission: Object.assign( {}, item, { uuid: '8f14e45f-ceea-4a5a-b6de-dd4bea254371', fields: fields().map( ( field, i ) => ( { id: field.id, type: field.type, label: field.label, value: values[ i ], values: [], is_file: false, redacted: false } ) ).concat( { id: 'code', type: 'text', label: t( 'National id', 'کد ملی' ), value: '', values: [], is_file: false, redacted: true } ), files: [ { id: 7, field_id: 'cv', name: 'resume-sara.pdf', size: 482113, mime: 'application/pdf', is_image: false, url: '#' } ], meta: { page_url: 'https://example.com/contact/', page_title: t( 'Contact', 'تماس' ), user_id: 0, user_login: '', user_ip: '203.0.113.5', user_agent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36', via: 'hook' } } ), messages: messages().slice( 0, 4 ) };
		}
		if ( path === 'messages' ) {
			const wanted = ( query.get( 'status' ) || '' ).split( ',' );
			return list( messages().filter( ( row ) => ! wanted[ 0 ] || wanted.indexOf( row.status ) > -1 ), query );
		}
		if ( match( /^messages\/\d+$/ ) ) {
			const item = messages().find( ( row ) => row.id === Number( path.split( '/' )[ 1 ] ) ) || messages()[ 0 ];
			return { message: Object.assign( {}, item, { subject: '', body: '<b>' + t( 'New submission: Contact us', 'ارسالی تازه: تماس با ما' ) + '</b>\n\n<b>' + t( 'Full name', 'نام' ) + '</b>: ' + t( 'Sara Ahmadi', 'سارا احمدی' ) + '\n<b>' + t( 'Phone', 'موبایل' ) + '</b>: +989120000000', response: '{"ok":false,"error_code":403,"description":"Forbidden: bot was kicked from the group chat"}', http_code: 403, remote_id: '', parent_id: 0, buttons: [ [ { text: t( 'Open submission', 'نمایش ارسالی' ), type: 'url', value: '#' } ] ], attachments: [], links: [ { name: 'resume-sara.pdf', url: '#', size: 482113 } ] } ) };
		}
		if ( match( /^bots\/\w+\/chats$/ ) ) {
			const chat = ( id, extra ) => Object.assign( { id: id, chat_id: '-100123456789' + id, thread_id: 0, type: 'supergroup', title: t( 'Sales group', 'گروه فروش' ), username: '', topic_name: '', is_forum: false, status: 'active', bot_role: 'member', address: '-100123456789' + id, last_seen: ago( id * 50 ) }, extra );
			return list( [ chat( 1, { is_forum: true } ), chat( 2, { chat_id: '-1001234567891', thread_id: 45, topic_name: t( 'Leads', 'سرنخ‌ها' ), address: '-1001234567891/45' } ), chat( 3, { type: 'private', title: t( 'Sara Ahmadi', 'سارا احمدی' ), username: 'sara_example', address: '123456789', chat_id: '123456789' } ), chat( 4, { type: 'channel', title: t( 'News', 'اخبار' ), status: 'left' } ) ], query );
		}
		if ( match( /^bots\/\w+\/admins$/ ) ) {
			return { items: [ { uid: '123456789', name: t( 'Sara Ahmadi', 'سارا احمدی' ), username: 'sara_example', wp_user: 1, wp_name: 'admin', perms: [ 'view', 'act' ] } ] };
		}
		if ( match( /^bots\/\w+\/status$/ ) ) {
			return { configured: true, me: { id: 1, username: 'forms_example_bot', name: 'Forms bot' }, mode: 'webhook', webhook: { url: 'https://example.com/wp-json/fnfe/v1/bot/telegram/hook/0123456789abcdef', set: true, pending: 2, last_error: 'Wrong response from the webhook: 502 Bad Gateway', last_error_date: ago( 400 ) }, polling: { last_run: null, offset: 0 }, can_webhook: true, reason: t( 'The site has a public HTTPS address.', 'سایت نشانی عمومی HTTPS دارد.' ), miniapp_url: 'https://example.com/fnfe-app/telegram/' };
		}
		return null;
	};

	const empty = ( path, query ) => ( path === 'stats' ? { range: query.get( 'range' ), submissions: 0, messages: { sent: 0, failed: 0, queued: 0 }, by_channel: {}, by_form: [], series: [], queue: { pending: 0, next_run: null }, health: [] } : /^(forms|submissions|messages|bots\/\w+\/(chats|admins))$/.test( path ) ? list( [], query ) : null );

	const answer = ( data, status ) => new Response( JSON.stringify( data ), { status: status || 200, headers: { 'Content-Type': 'application/json' } } );

	window.fetch = ( input, init ) => {
		const url = new URL( String( input && input.url ? input.url : input ), window.location.href );
		const at = url.href.indexOf( 'fnfe/v1/' );
		const method = ( ( init && init.method ) || 'GET' ).toUpperCase();
		if ( at === -1 || method !== 'GET' ) {
			return realFetch( input, init );
		}
		const path = decodeURIComponent( ( url.searchParams.get( 'rest_route' ) || url.pathname ).split( 'fnfe/v1/' )[ 1 ] || '' );
		if ( config.mode === 'error' && path !== '' ) {
			return Promise.resolve( answer( { code: 'fnfe_sample', message: t( 'The database did not answer.', 'پایگاه داده پاسخ نداد.' ), data: { status: 500 } }, 500 ) );
		}
		const data = config.mode === 'empty' ? empty( path, url.searchParams ) : route( path, url.searchParams );
		if ( config.mode === 'slow' ) {
			return new Promise( () => {} );
		}
		return data ? Promise.resolve( answer( data ) ) : realFetch( input, init );
	};
}() );
