/**
 * Failure alerts: settings section and own route.
 */
( function () {
	'use strict';

	const { __ } = wp.i18n;
	const { api, ui, bus } = window.fnfe;

	ui.settingsExtras = ui.settingsExtras || {};

	/** Put channels that are in use first and fold the others away. */
	ui.splitChannelSection = ( sections, values ) => sections.reduce( ( out, section ) => {
		if ( ! /_channels$/.test( section.id ) ) {
			return out.concat( section );
		}
		const idOf = ( field ) => ( field.key.match( /\.channels\.([a-z0-9_]+)\./ ) || [] )[ 1 ];
		const used = ( field ) => {
			const meta = ui.channelMeta( idOf( field ) );
			const on = values.values[ field.key.replace( /\.[a-z]+$/, '.enabled' ) ];
			return !! on || ! meta || meta.enabled;
		};
		const first = section.fields.filter( used );
		const rest = section.fields.filter( ( field ) => ! used( field ) );
		if ( ! first.length ) {
			return out.concat( section );
		}
		out.push( Object.assign( {}, section, { fields: first } ) );
		if ( rest.length ) {
			out.push( Object.assign( {}, section, { id: section.id + '_more', title: __( 'Channels that are switched off', 'forms-notifier-for-elementor' ), desc: '', collapsed: true, fields: rest } ) );
		}
		return out;
	}, [] );

	/** Button that posts the unsaved values to a route and shows the answer. */
	ui.sendNow = ( panel, opts ) => ui.Button( { label: opts.label, icon: 'send', onClick: () => api.post( opts.path, panel.overlay() ).then( ( result ) => {
		const dialog = ui.Modal( { title: opts.label, size: 's', body: ui.Result( result ), footer: ui.Button( { label: __( 'Close', 'forms-notifier-for-elementor' ), onClick: () => dialog.close() } ) } );
		bus.emit( 'changed' );
	}, ui.fail ) } );

	ui.settingsExtras.alerts = {
		help: 'alerts',
		sections: ui.splitChannelSection,
		actions: ( panel ) => ui.sendNow( panel, { label: __( 'Send test alert', 'forms-notifier-for-elementor' ), path: 'alerts/test' } ),
	};

	window.fnfe.screens.register( 'alerts', { title: __( 'Failure alerts', 'forms-notifier-for-elementor' ), cap: 'manage', render: ( container, params, shell ) => ui.settingsScreen( 'alerts', container, shell, [ { label: __( 'Channels', 'forms-notifier-for-elementor' ), path: 'channels' }, { label: __( 'Failure alerts', 'forms-notifier-for-elementor' ) } ] ) } );
}() );
