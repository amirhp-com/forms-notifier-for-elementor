/**
 * Stepped guide with code blocks, copy buttons and a check per step.
 */
( function () {
	'use strict';

	const { __ } = wp.i18n;
	const { h, boot, api, ui, icon, fields, safeHtml } = window.fnfe;

	const dismissed = () => ( boot.user && boot.user.ui && boot.user.ui.dismissed ) || [];

	function remember( id, on ) {
		const next = dismissed().filter( ( item ) => item !== id ).concat( on ? [ id ] : [] );
		boot.user.ui.dismissed = next;
		api.post( 'bootstrap/ui', { dismissed: next } ).catch( () => {} );
	}

	/** opts: id, steps [ { title, body, code, lang, copy } ]. */
	function Guide( opts ) {
		const base = 'g_' + String( opts.id ).toLowerCase().replace( /[^a-z0-9]+/g, '_' );
		return h( 'ol', { class: 'fnfe-guide' }, fields.list( opts.steps ).map( ( step, i ) => {
			const id = base + '_' + ( i + 1 );
			const done = dismissed().indexOf( id ) > -1;
			const item = h( 'li', { class: [ 'fnfe-guide__step', done ? 'is-done' : '' ] } );
			const check = h( 'button', { type: 'button', class: 'fnfe-guide__check', 'aria-pressed': done, title: __( 'Mark this step as done', 'forms-notifier-for-elementor' ), 'aria-label': __( 'Mark this step as done', 'forms-notifier-for-elementor' ), onClick() {
				const on = check.getAttribute( 'aria-pressed' ) !== 'true';
				check.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
				item.classList.toggle( 'is-done', on );
				remember( id, on );
			} }, h( 'span', { class: 'fnfe-guide__n', 'aria-hidden': 'true' }, window.fnfe.fmt.number( i + 1 ) ), icon( 'check', 16 ) );
			item.appendChild( check );
			item.appendChild( h( 'div', { class: 'fnfe-guide__body' }, h( 'h4', null, step.title ), step.body ? h( 'div', { class: 'fnfe-prose' }, safeHtml( step.body ) ) : null, step.code ? ui.Code( step.code, step.lang || '' ) : null ) );
			return item;
		} ) );
	}

	ui.Guide = Guide;
	ui.dismissed = dismissed;
	ui.remember = remember;

	fields.register( 'guide', ( field ) => ui.Fold( { title: field.placeholder || __( 'Show the steps', 'forms-notifier-for-elementor' ), class: 'fnfe-fold--inset', lead: h( 'span', { class: 'fnfe-fold__lead' }, icon( 'list', 18 ) ) }, Guide( { id: field.key, steps: field.steps } ) ) );
}() );
