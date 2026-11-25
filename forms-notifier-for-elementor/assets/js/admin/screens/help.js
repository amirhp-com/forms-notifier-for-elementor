/**
 * Help topics.
 */
( function () {
	'use strict';

	const { __ } = wp.i18n;
	const { h, mount, frag, ui, router } = window.fnfe;

	function render( container, params, shell ) {
		const nav = h( 'nav', { class: 'fnfe-subnav fnfe-subnav--help', 'aria-label': __( 'Help topics', 'forms-notifier-for-elementor' ) } );
		const article = h( 'div', { class: 'fnfe-split__main' }, ui.Skeleton( 'form', 6 ) );
		let topics = [];
		let query = '';
		shell.header( { title: __( 'Help', 'forms-notifier-for-elementor' ) } );
		mount( container, h( 'div', { class: 'fnfe-split' }, h( 'div', { class: 'fnfe-split__side' }, ui.Search( { wait: 100, placeholder: __( 'Search help', 'forms-notifier-for-elementor' ), onSearch( text ) {
			query = text.toLowerCase();
			drawNav();
		} } ), nav ), article ) );

		const plain = ( topic ) => ( topic.title + ' ' + ( topic.body || '' ).replace( /<[^>]*>/g, ' ' ) + ' ' + ( topic.steps || [] ).map( ( step ) => step.title + ' ' + step.body ).join( ' ' ) ).toLowerCase();

		function drawNav() {
			const groups = [];
			topics.filter( ( topic ) => ! query || plain( topic ).indexOf( query ) > -1 ).forEach( ( topic ) => {
				let group = groups.find( ( item ) => item.name === topic.group );
				if ( ! group ) {
					group = { name: topic.group, items: [] };
					groups.push( group );
				}
				group.items.push( topic );
			} );
			const active = params.topic || ( topics[ 0 ] && topics[ 0 ].id );
			mount( nav, groups.length ? groups.map( ( group ) => [ h( 'h3', null, group.name ), group.items.map( ( topic ) => h( 'a', { href: '#/help/' + topic.id, 'aria-current': topic.id === active ? 'page' : undefined }, h( 'span', null, topic.title ) ) ) ] ) : h( 'p', { class: 'fnfe-muted' }, __( 'No matches', 'forms-notifier-for-elementor' ) ) );
		}

		function load() {
			ui.helpTopics().then( ( list ) => {
				topics = list;
				drawNav();
				const topic = params.topic ? list.find( ( item ) => item.id === params.topic ) : list[ 0 ];
				if ( ! topic ) {
					mount( article, ui.Empty( { icon: 'help', title: __( 'This topic does not exist', 'forms-notifier-for-elementor' ), actions: ui.Button( { label: __( 'All topics', 'forms-notifier-for-elementor' ), onClick: () => router.go( 'help' ) } ) } ) );
					return;
				}
				shell.header( { title: topic.title, crumbs: [ { label: __( 'Help', 'forms-notifier-for-elementor' ), path: 'help' }, { label: topic.title } ] } );
				mount( article, h( 'section', { class: 'fnfe-card' }, h( 'div', { class: 'fnfe-card__body' }, ui.helpBody( topic ) ) ) );
			}, ( error ) => mount( article, frag( ui.ErrorState( error, load ) ) ) );
		}
		load();
		return null;
	}

	const def = { title: __( 'Help', 'forms-notifier-for-elementor' ), cap: 'any', render: render };
	window.fnfe.screens.register( 'help', def );
	window.fnfe.screens.register( 'help/:topic', def );
}() );
