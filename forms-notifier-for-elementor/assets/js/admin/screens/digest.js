/**
 * Digest: settings section and own route.
 */
( function () {
	'use strict';

	const { __ } = wp.i18n;
	const { ui } = window.fnfe;

	ui.settingsExtras = ui.settingsExtras || {};
	ui.settingsExtras.digest = {
		help: 'digest',
		sections: ( sections, values ) => ui.splitChannelSection( sections, values ),
		actions: ( panel ) => ui.sendNow( panel, { label: __( 'Send digest now', 'forms-notifier-for-elementor' ), path: 'digest/send' } ),
	};

	window.fnfe.screens.register( 'digest', { title: __( 'Digest', 'forms-notifier-for-elementor' ), cap: 'manage', render: ( container, params, shell ) => ui.settingsScreen( 'digest', container, shell, [ { label: __( 'Channels', 'forms-notifier-for-elementor' ), path: 'channels' }, { label: __( 'Digest', 'forms-notifier-for-elementor' ) } ] ) } );
}() );
