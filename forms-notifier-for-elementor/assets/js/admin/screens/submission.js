/**
 * One submission.
 */
( function () {
	'use strict';

	const { __, sprintf, _n } = wp.i18n;
	const { h, mount, icon, api, ui, fmt, bus, router } = window.fnfe;

	/** Name of a language code, as the site lists it; the code when the site does not know it. */
	function languageName( code ) {
		const found = ( ( window.fnfe.boot.i18n && window.fnfe.boot.i18n.languages ) || [] ).find( ( lang ) => lang.code === code );
		return found ? found.name : ui.mono( code );
	}

	function valueNode( field ) {
		if ( field.redacted ) {
			return h( 'span', { class: 'fnfe-muted' }, __( 'Not saved (sensitive field)', 'forms-notifier-for-elementor' ) );
		}
		if ( ! field.value ) {
			return h( 'span', { class: 'fnfe-muted' }, '–' );
		}
		const text = field.value;
		if ( field.values && field.values.length > 1 ) {
			return h( 'span', { class: 'fnfe-badges' }, field.values.map( ( item ) => ui.Badge( ui.raw( item ) ) ) );
		}
		if ( field.type === 'email' ) {
			return h( 'a', { class: 'fnfe-latin', href: 'mailto:' + text, dir: 'ltr' }, text );
		}
		if ( field.type === 'tel' ) {
			return h( 'a', { class: 'fnfe-latin', href: 'tel:' + text.replace( /[^+\d]/g, '' ), dir: 'ltr' }, text );
		}
		if ( field.type === 'url' && /^https?:\/\//i.test( text ) ) {
			return h( 'a', { href: text, target: '_blank', rel: 'noopener noreferrer', class: 'fnfe-latin', dir: 'ltr' }, text );
		}
		return h( 'span', { class: 'fnfe-value fnfe-latin', dir: 'auto' }, text );
	}

	function render( container, params, shell ) {
		const id = Number( params.id );
		const body = h( 'div', null, ui.Skeleton( 'form', 6 ) );
		let alive = true;
		mount( container, body );
		shell.header( { title: sprintf( /* translators: %s: submission id */ __( 'Submission %s', 'forms-notifier-for-elementor' ), '#' + id ), crumbs: [ { label: __( 'Submissions', 'forms-notifier-for-elementor' ), path: 'submissions' }, { label: '#' + id } ] } );

		function setStatus( status ) {
			return api.patch( 'submissions/' + id, { status: status } ).then( () => {
				bus.emit( 'changed' );
				ui.forget( 'recent' );
				return load();
			}, ui.fail );
		}

		function resend() {
			return ui.confirm( { title: __( 'Send the notifications again?', 'forms-notifier-for-elementor' ), text: __( 'All current receivers of this form get the submission once more.', 'forms-notifier-for-elementor' ), confirm: __( 'Send again', 'forms-notifier-for-elementor' ) } ).then( ( yes ) => ( yes ? api.post( 'submissions/' + id + '/resend', {} ).then( ( data ) => {
				/* translators: %s: number of messages */
				window.fnfe.toast( sprintf( _n( '%s message queued.', '%s messages queued.', data.queued, 'forms-notifier-for-elementor' ), fmt.number( data.queued ) ), data.queued ? 'success' : 'warning' );
				bus.emit( 'changed' );
				window.setTimeout( load, 1500 );
			}, ui.fail ) : null ) );
		}

		function remove() {
			ui.confirm( { title: __( 'Delete this submission?', 'forms-notifier-for-elementor' ), text: __( 'The submission and its files are gone for good.', 'forms-notifier-for-elementor' ), confirm: __( 'Delete', 'forms-notifier-for-elementor' ), danger: true } ).then( ( yes ) => yes && api.del( 'submissions/' + id ).then( () => {
				bus.emit( 'changed' );
				router.go( 'submissions', true );
			}, ui.fail ) );
		}

		function draw( data ) {
			const s = data.submission;
			const at = ui.lastSubmissions.indexOf( id );
			const near = ( step ) => ( at > -1 ? ui.lastSubmissions[ at + step ] : 0 );
			const tools = [
				s.status === 'done' ? null : ui.Button( { label: __( 'Mark as done', 'forms-notifier-for-elementor' ), icon: 'check', variant: 'primary', onClick: () => setStatus( 'done' ) } ),
				shell.can( 'logs' ) ? ui.Button( { label: __( 'Resend notifications', 'forms-notifier-for-elementor' ), icon: 'send', onClick: resend } ) : null,
				ui.Menu( { items: [
					s.status !== 'new' ? { label: __( 'Mark as new', 'forms-notifier-for-elementor' ), icon: 'sparkle', onSelect: () => setStatus( 'new' ) } : null,
					s.status !== 'read' ? { label: __( 'Mark as read', 'forms-notifier-for-elementor' ), icon: 'eye', onSelect: () => setStatus( 'read' ) } : null,
					s.status !== 'spam' ? { label: __( 'Mark as spam', 'forms-notifier-for-elementor' ), icon: 'flag', onSelect: () => setStatus( 'spam' ) } : null,
					s.status !== 'trash' ? { label: __( 'Move to trash', 'forms-notifier-for-elementor' ), icon: 'trash', onSelect: () => setStatus( 'trash' ) } : null,
					shell.can( 'manage' ) ? { label: __( 'Delete for good', 'forms-notifier-for-elementor' ), icon: 'trash', danger: true, onSelect: remove } : null,
				] } ),
				near( -1 ) ? ui.IconButton( { icon: 'chevron-start', label: __( 'Previous submission', 'forms-notifier-for-elementor' ), href: '#/submissions/' + near( -1 ) } ) : null,
				near( 1 ) ? ui.IconButton( { icon: 'chevron-end', label: __( 'Next submission', 'forms-notifier-for-elementor' ), href: '#/submissions/' + near( 1 ) } ) : null,
			];
			shell.header( {
				title: s.form_name || __( 'Submission', 'forms-notifier-for-elementor' ),
				sub: h( 'span', { class: 'fnfe-inline' }, ui.Status( s.status ), h( 'time', { datetime: s.created, title: s.created_local }, fmt.date( s.created ) ) ),
				crumbs: [ { label: __( 'Submissions', 'forms-notifier-for-elementor' ), path: 'submissions' }, { label: h( 'bdi', { class: 'fnfe-latin', dir: 'ltr' }, '#' + id ) } ],
				help: 'submissions',
				actions: tools,
			} );
			const meta = s.meta || {};
			const line = ( label, value ) => ( value ? [ h( 'dt', null, label ), h( 'dd', null, value ) ] : null );
			mount( body, h( 'div', { class: 'fnfe-cols fnfe-cols--wide' },
				h( 'div', null,
					ui.Card( { title: __( 'What was sent', 'forms-notifier-for-elementor' ), flush: true }, h( 'dl', { class: 'fnfe-answers' }, s.fields.filter( ( field ) => ! field.is_file ).map( ( field ) => h( 'div', null, h( 'dt', { dir: 'auto' }, field.label || field.id ), h( 'dd', null, valueNode( field ), field.value && ! field.redacted ? ui.CopyButton( field.value ) : null ) ) ) ) ),
					s.files.length ? ui.Card( { title: __( 'Files', 'forms-notifier-for-elementor' ), flush: true }, h( 'ul', { class: 'fnfe-feed fnfe-feed--plain' }, s.files.map( ( file ) => h( 'li', null, h( 'span', { class: 'fnfe-receiver__icon' }, icon( file.is_image ? 'image' : 'file', 18 ) ), h( 'span', { class: 'fnfe-feed__text' }, h( 'strong', { class: 'fnfe-latin', dir: 'auto' }, file.name ), h( 'small', null, fmt.bytes( file.size ) ) ), file.url ? ui.Button( { label: __( 'Download', 'forms-notifier-for-elementor' ), icon: 'download', size: 's', href: file.url } ) : h( 'span', { class: 'fnfe-muted' }, __( 'No longer on the server', 'forms-notifier-for-elementor' ) ) ) ) ) ) : null,
					ui.Card( { title: __( 'Deliveries', 'forms-notifier-for-elementor' ), flush: true }, data.messages.length ? ui.deliveries( data.messages, load ) : ui.Empty( { icon: 'send', small: true, text: __( 'No message was made for this submission.', 'forms-notifier-for-elementor' ) } ) )
				),
				ui.Card( { title: __( 'Where it came from', 'forms-notifier-for-elementor' ) }, h( 'dl', { class: 'fnfe-dl fnfe-dl--stack' },
					line( __( 'Form', 'forms-notifier-for-elementor' ), s.form_id && shell.can( 'forms' ) ? h( 'a', { href: '#/forms/' + s.form_id, dir: 'auto' }, s.form_name ) : h( 'bdi', null, s.form_name ) ),
					line( __( 'Page', 'forms-notifier-for-elementor' ), /^https?:\/\//i.test( meta.page_url || '' ) ? h( 'a', { href: meta.page_url, target: '_blank', rel: 'noopener noreferrer', dir: 'auto' }, meta.page_title || meta.page_url ) : meta.page_title ),
					line( __( 'Language', 'forms-notifier-for-elementor' ), s.lang ? languageName( s.lang ) : '' ),
					line( __( 'WordPress user', 'forms-notifier-for-elementor' ), meta.user_login ),
					line( __( 'IP address', 'forms-notifier-for-elementor' ), meta.user_ip ? ui.mono( meta.user_ip ) : '' ),
					line( __( 'Browser', 'forms-notifier-for-elementor' ), meta.user_agent ? h( 'span', { class: 'fnfe-ua fnfe-latin', dir: 'ltr' }, meta.user_agent ) : '' ),
					line( __( 'Id', 'forms-notifier-for-elementor' ), ui.mono( s.uuid ) )
				) )
			) );
		}

		function load() {
			return api.get( 'submissions/' + id ).then( ( data ) => {
				if ( ! alive ) {
					return;
				}
				draw( data );
				if ( data.submission.status === 'new' && ! load.seen ) {
					// Opening it is reading it.
					api.patch( 'submissions/' + id, { status: 'read' } ).then( () => bus.emit( 'changed' ), () => {} );
				}
				load.seen = true;
			}, ( error ) => {
				if ( alive ) {
					mount( body, error.status === 404 ? ui.Empty( { icon: 'inbox', title: __( 'This submission does not exist', 'forms-notifier-for-elementor' ), text: __( 'It may have been deleted.', 'forms-notifier-for-elementor' ), actions: ui.Button( { label: __( 'All submissions', 'forms-notifier-for-elementor' ), href: '#/submissions' } ) } ) : ui.ErrorState( error, load ) );
				}
			} );
		}
		load();
		return () => {
			alive = false;
		};
	}

	window.fnfe.screens.register( 'submissions/:id', { title: __( 'Submission', 'forms-notifier-for-elementor' ), cap: 'submissions', render: render } );
}() );
