/**
 * Settings.
 */
( function () {
	'use strict';

	const { __ } = wp.i18n;
	const { h, mount, frag, icon, boot, api, ui, fields, router } = window.fnfe;

	/* Parts other files add to a section: id -> { actions( panel ), before( panel ), after( panel ), sections( list ) }. */
	ui.settingsExtras = ui.settingsExtras || {};

	/** Side navigation of settings and tools. */
	ui.settingsNav = ( active ) => {
		const tabs = boot.tabs || {};
		const item = ( path, label, glyph, on ) => h( 'a', { href: '#/' + path, 'aria-current': on ? 'page' : undefined }, icon( glyph, 18 ), h( 'span', null, label ) );
		return h( 'nav', { class: 'fnfe-subnav', 'aria-label': __( 'Settings', 'forms-notifier-for-elementor' ) }, Object.keys( tabs ).map( ( id ) => item( 'settings/' + id, tabs[ id ].label, tabs[ id ].icon, active === id ) ), h( 'hr' ), item( 'tools', __( 'Tools', 'forms-notifier-for-elementor' ), 'database', active === 'tools' ) );
	};

	function render( section, container, shell, crumb ) {
		const tabs = boot.tabs || {};
		const extra = ui.settingsExtras[ section ] || {};
		if ( ! tabs[ section ] ) {
			shell.header( { title: __( 'Settings', 'forms-notifier-for-elementor' ) } );
			mount( container, ui.Empty( { icon: 'settings', title: __( 'There is no such section', 'forms-notifier-for-elementor' ), actions: ui.Button( { label: __( 'General settings', 'forms-notifier-for-elementor' ), href: '#/settings/general' } ) } ) );
			return null;
		}
		let alive = true;
		const body = h( 'div', null, ui.Skeleton( 'form', 5 ) );
		shell.header( { title: tabs[ section ].label, crumbs: crumb || [ { label: __( 'Settings', 'forms-notifier-for-elementor' ), path: 'settings' }, { label: tabs[ section ].label } ], help: extra.help || { attachments: 'uploaded-files', queue: 'log', privacy: 'submissions' }[ section ] || 'quick-start' } );
		mount( container, crumb ? body : h( 'div', { class: 'fnfe-split' }, h( 'div', { class: 'fnfe-split__side' }, ui.settingsNav( section ) ), h( 'div', { class: 'fnfe-split__main' }, body ) ) );

		function load() {
			mount( body, ui.Skeleton( 'form', 5 ) );
			Promise.all( [ api.get( 'schema', { tab: section } ), api.get( 'settings', { tab: section } ) ] ).then( ( pair ) => {
				if ( ! alive ) {
					return;
				}
				const list = extra.sections ? extra.sections( pair[ 0 ].sections, pair[ 1 ] ) : pair[ 0 ].sections;
				if ( ! list.length ) {
					mount( body, ui.Empty( { icon: 'settings', title: __( 'Nothing to set here', 'forms-notifier-for-elementor' ), text: __( 'This part of the plugin is not available on this site.', 'forms-notifier-for-elementor' ) } ) );
					return;
				}
				const panel = fields.panel( { shell: shell, sections: list, data: pair[ 1 ], onSaved() {
					if ( section === 'miniapp' && boot.features ) {
						boot.features.miniapp = !! panel.get( 'miniapp.enabled' );
					}
					if ( extra.onSaved ) {
						extra.onSaved( panel );
					}
				} } );
				if ( extra.actions ) {
					shell.actions( extra.actions( panel, shell ) );
				}
				mount( body, frag( extra.before ? extra.before( panel, shell ) : null, panel.el, extra.after ? extra.after( panel, shell ) : null ) );
			}, ( error ) => alive && mount( body, ui.ErrorState( error, load ) ) );
		}
		load();
		return () => {
			alive = false;
		};
	}

	ui.settingsScreen = render;

	window.fnfe.screens.register( 'settings', { title: __( 'Settings', 'forms-notifier-for-elementor' ), cap: 'manage', render() {
		window.setTimeout( () => router.go( 'settings/general', true ) );
		return null;
	} } );
	window.fnfe.screens.register( 'settings/:section', { title: __( 'Settings', 'forms-notifier-for-elementor' ), cap: 'manage', render: ( container, params, shell ) => render( params.section, container, shell ) } );
}() );
