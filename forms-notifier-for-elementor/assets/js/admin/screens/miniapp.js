/**
 * Mini app settings: what is still missing, and the addresses.
 */
( function () {
	'use strict';

	const { __ } = wp.i18n;
	const { h, mount, icon, api, ui } = window.fnfe;

	ui.settingsExtras = ui.settingsExtras || {};

	const checkText = ( key ) => ( {
		enabled: __( 'The mini app is switched on', 'forms-notifier-for-elementor' ),
		https: __( 'The site uses HTTPS', 'forms-notifier-for-elementor' ),
		bot: __( 'The bot is connected', 'forms-notifier-for-elementor' ),
		admins: __( 'At least one admin', 'forms-notifier-for-elementor' ),
	}[ key ] || key );

	function platform( item ) {
		const line = ( label, url ) => ( url ? h( 'div', { class: 'fnfe-urlline' }, h( 'span', { class: 'fnfe-muted' }, label ), h( 'div', null, ui.mono( url ), ui.CopyButton( url ) ) ) : null );
		return ui.Card( { title: item.label, actions: item.ready ? ui.Badge( __( 'Ready', 'forms-notifier-for-elementor' ), 'ok', 'check-circle' ) : ui.Badge( __( 'Not ready', 'forms-notifier-for-elementor' ), 'warn', 'alert' ) },
			h( 'ul', { class: 'fnfe-checks' }, Object.keys( item.checks ).map( ( key ) => h( 'li', { class: item.checks[ key ] ? 'is-ok' : '' }, icon( item.checks[ key ] ? 'check-circle' : 'x-circle', 18 ), h( 'span', null, checkText( key ) ), key === 'bot' && ! item.checks[ key ] ? h( 'a', { href: '#/channels/' + item.id }, __( 'Connect', 'forms-notifier-for-elementor' ) ) : null, key === 'admins' ? h( 'a', { href: '#/bots/' + item.id + '/admins' }, item.checks[ key ] ? __( 'Manage', 'forms-notifier-for-elementor' ) : __( 'Add an admin', 'forms-notifier-for-elementor' ) ) : null ) ) ),
			line( __( 'App address', 'forms-notifier-for-elementor' ), item.url ),
			line( __( 'Direct link', 'forms-notifier-for-elementor' ), item.direct_link )
		);
	}

	function setup() {
		const box = h( 'div', { class: 'fnfe-duo' } );
		api.get( 'app/setup' ).then( ( data ) => mount( box, ( data.platforms || [] ).map( platform ) ), () => {} );
		return box;
	}

	let slot = null;
	ui.settingsExtras.miniapp = {
		help: 'miniapp',
		before: () => ( slot = h( 'div', null, setup() ) ),
		onSaved: () => slot && mount( slot, setup() ),
	};
}() );
