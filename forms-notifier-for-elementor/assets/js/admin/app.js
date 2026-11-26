/**
 * Shell of the admin panel: navigation, routing, theme, save bar.
 */
( function () {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const { h, mount, frag, icon, boot, api, ui, fmt, router, bus, screens } = window.fnfe;

	const root = document.getElementById( 'fnfe-app' );
	if ( ! root ) {
		return;
	}

	const caps = ( boot.user && boot.user.caps ) || {};
	const can = ( cap ) => ! cap || ( cap === 'any' ? Object.values( caps ).some( Boolean ) : String( cap ).split( '|' ).some( ( item ) => caps[ item ] ) );
	const routes = wp.hooks.applyFilters( 'fnfe.routes', ( boot.routes || [] ).slice() ).filter( ( item ) => can( item.cap ) );

	/* Section of the rail and the submenu slug a path belongs to. */
	const SECTION = { bots: 'channels', alerts: 'channels', digest: 'channels', tools: 'settings' };
	const SLUG = { dashboard: 'fnfe', channels: 'fnfe-channels', forms: 'fnfe-forms', submissions: 'fnfe-submissions', log: 'fnfe-log', settings: 'fnfe-settings', help: 'fnfe-help' };
	const HELP = { dashboard: 'quick-start', channels: 'channels', channel: 'channels', forms: 'forms', form: 'forms', submissions: 'submissions', log: 'log', settings: 'quick-start', tools: 'troubleshooting', bots: 'bots-connect' };
	const sectionOf = ( path ) => {
		const first = String( path ).split( '/' )[ 0 ];
		return SECTION[ first ] || first;
	};

	let current = null;
	let saving = null;
	let first = true;

	/* --------------------------------------------------------- Rail, top */

	const badges = {};
	const links = {};
	const queueText = h( 'span', null, '' );
	const queuePill = h( 'a', { class: 'fnfe-rail__queue', href: '#/log?status=queued', hidden: ! caps.logs }, h( 'i', { 'aria-hidden': 'true' } ), queueText );
	const rail = h(
		'nav',
		{ class: 'fnfe-rail', 'aria-label': __( 'Forms Notifier', 'forms-notifier-for-elementor' ) },
		h( 'a', { class: 'fnfe-rail__brand', href: '#/dashboard' }, ui.Mark( 28 ), h( 'span', null, __( 'Forms Notifier', 'forms-notifier-for-elementor' ) ) ),
		h( 'ul', null, routes.map( ( item ) => {
			badges[ item.path ] = h( 'span', { class: 'fnfe-rail__badge', hidden: true } );
			links[ item.path ] = h( 'a', { class: 'fnfe-rail__link', href: '#/' + item.path, title: item.label }, icon( item.icon, 20 ), h( 'span', { class: 'fnfe-rail__label' }, item.label ), badges[ item.path ] );
			return h( 'li', null, links[ item.path ] );
		} ) ),
		h( 'div', { class: 'fnfe-rail__foot' }, queuePill, h( 'bdi', { class: 'fnfe-rail__version', dir: 'ltr' }, 'v' + ( boot.version || '' ) ) )
	);

	const burger = ui.IconButton( { icon: 'list', label: __( 'Menu', 'forms-notifier-for-elementor' ), class: 'fnfe-top__burger', attrs: { 'aria-expanded': false }, onClick: () => drawer( ! root.classList.contains( 'is-drawer' ) ) } );
	const shade = h( 'div', { class: 'fnfe-shade', onClick: () => drawer( false ) } );
	function drawer( open ) {
		root.classList.toggle( 'is-drawer', open );
		burger.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		if ( open ) {
			const link = rail.querySelector( '[aria-current]' ) || rail.querySelector( 'a' );
			link.focus();
		}
	}

	const crumbs = h( 'nav', { class: 'fnfe-crumbs', 'aria-label': __( 'Breadcrumb', 'forms-notifier-for-elementor' ) } );
	const THEMES = () => [ [ 'auto', 'monitor', __( 'Same as the device', 'forms-notifier-for-elementor' ) ], [ 'light', 'sun', __( 'Light', 'forms-notifier-for-elementor' ) ], [ 'dark', 'moon', __( 'Dark', 'forms-notifier-for-elementor' ) ] ];
	const themeButton = ui.IconButton( { icon: 'sun', label: __( 'Theme', 'forms-notifier-for-elementor' ) } );
	const showTheme = () => mount( themeButton, icon( ( THEMES().find( ( item ) => item[ 0 ] === root.dataset.theme ) || THEMES()[ 0 ] )[ 1 ], 18 ) );
	ui.Menu( { button: themeButton, items: () => THEMES().map( ( item ) => ( { label: item[ 2 ], icon: root.dataset.theme === item[ 0 ] ? 'check' : item[ 1 ], onSelect() {
		root.dataset.theme = item[ 0 ];
		showTheme();
		api.post( 'bootstrap/ui', { theme: item[ 0 ] } ).catch( ui.fail );
	} } ) ) } );
	let helpTopic = '';
	const helpButton = ui.IconButton( { icon: 'help', label: __( 'Help for this screen', 'forms-notifier-for-elementor' ), onClick: () => help( helpTopic ) } );

	const title = h( 'h1', { class: 'fnfe-title', tabindex: -1 } );
	const lead = h( 'div', { class: 'fnfe-pagehead__lead' } );
	const sub = h( 'p', { class: 'fnfe-pagehead__sub' } );
	const actions = h( 'div', { class: 'fnfe-pagehead__actions' } );
	const content = h( 'div', { class: 'fnfe-view' } );
	const main = h( 'main', { class: 'fnfe-main' },
		h( 'div', { class: 'fnfe-top' }, burger, crumbs, h( 'span', { class: 'fnfe-grow' } ), themeButton, helpButton ),
		h( 'header', { class: 'fnfe-pagehead' }, lead, h( 'div', { class: 'fnfe-pagehead__titles' }, title, sub ), actions ),
		content
	);

	/* ----------------------------------------------------------- Save bar */

	const saveText = h( 'span', { class: 'fnfe-savebar__text', role: 'status' } );
	const saveButton = ui.Button( { label: __( 'Save changes', 'forms-notifier-for-elementor' ), variant: 'primary', onClick: () => save() } );
	const savebar = h( 'div', { class: 'fnfe-savebar', hidden: true, role: 'region', 'aria-label': __( 'Unsaved changes', 'forms-notifier-for-elementor' ) }, h( 'span', { class: 'fnfe-savebar__dot', 'aria-hidden': 'true' } ), saveText, ui.Button( { label: __( 'Discard', 'forms-notifier-for-elementor' ), variant: 'ghost', onClick: () => saving && saving.discard() } ), saveButton );

	function save() {
		if ( ! saving ) {
			return Promise.resolve( true );
		}
		return Promise.resolve( saving.save() );
	}

	function setSavebar( state ) {
		saving = state;
		savebar.hidden = ! state;
		root.classList.toggle( 'has-savebar', !! state );
		if ( state ) {
			saveText.textContent = state.count
				? sprintf( /* translators: %s: number of changes */ _n( '%s unsaved change', '%s unsaved changes', state.count, 'forms-notifier-for-elementor' ), fmt.number( state.count ) )
				: __( 'Unsaved changes', 'forms-notifier-for-elementor' );
		}
		router.guard( state ? leave : null );
	}

	function leave( next ) {
		ui.confirm( { title: __( 'Leave without saving?', 'forms-notifier-for-elementor' ), text: __( 'Your changes on this screen are lost.', 'forms-notifier-for-elementor' ), confirm: __( 'Leave', 'forms-notifier-for-elementor' ), cancel: __( 'Keep editing', 'forms-notifier-for-elementor' ), danger: true } ).then( ( yes ) => {
			if ( yes ) {
				setSavebar( null );
				next();
			}
		} );
	}

	window.addEventListener( 'beforeunload', ( event ) => {
		if ( saving ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );
	document.addEventListener( 'keydown', ( event ) => {
		if ( ( event.ctrlKey || event.metaKey ) && event.key === 's' && saving ) {
			event.preventDefault();
			saveButton.click();
		}
		if ( event.key === 'Escape' && root.classList.contains( 'is-drawer' ) ) {
			drawer( false );
			burger.focus();
		}
	} );

	/* --------------------------------------------------------------- Data */

	const cache = {};
	/** Show the cached answer at once, refetch when it is older than 30 seconds. */
	ui.cached = ( key, load, show, error ) => {
		const hit = cache[ key ];
		if ( hit ) {
			show( hit.data, true );
			if ( Date.now() - hit.at < 30000 ) {
				return Promise.resolve( hit.data );
			}
		}
		return load().then( ( data ) => {
			cache[ key ] = { data: data, at: Date.now() };
			show( data, false );
			return data;
		}, ( failure ) => {
			if ( ! hit && error ) {
				error( failure );
			}
		} );
	};
	ui.forget = ( prefix ) => Object.keys( cache ).forEach( ( key ) => ( ! prefix || key.indexOf( prefix ) === 0 ) && delete cache[ key ] );

	let index = null;
	/** Whether the site has a REST route. The pattern is matched against paths like "/forms/scan". */
	ui.hasRoute = ( pattern ) => {
		index = index || api.get( '' ).then( ( data ) => Object.keys( data.routes || {} ).map( ( path ) => path.replace( /^\/fnfe\/v1/, '' ) ), () => [] );
		return index.then( ( list ) => list.find( ( path ) => ( pattern instanceof RegExp ? pattern.test( path ) : path === pattern ) ) || '' );
	};

	/** Read the channels again after a change of settings. */
	ui.refreshChannels = () => api.get( 'channels' ).then( ( data ) => {
		boot.channels = data.items;
		bus.emit( 'channels', data.items );
		return data.items;
	}, () => boot.channels );

	function badge( path, count, label ) {
		if ( badges[ path ] ) {
			badges[ path ].hidden = ! count;
			badges[ path ].textContent = count ? fmt.number( Math.min( count, 999 ) ) : '';
			links[ path ].title = count ? links[ path ].querySelector( '.fnfe-rail__label' ).textContent + ': ' + label : links[ path ].querySelector( '.fnfe-rail__label' ).textContent;
			badges[ path ].setAttribute( 'aria-label', label );
		}
	}

	function poll() {
		if ( document.hidden ) {
			return;
		}
		if ( can( 'any' ) ) {
			api.get( 'stats', { range: 'today' } ).then( ( data ) => {
				const pending = ( data.queue && data.queue.pending ) || 0;
				/* translators: %s: number of messages */
				queueText.textContent = pending ? sprintf( __( 'Queue: %s', 'forms-notifier-for-elementor' ), fmt.number( pending ) ) : __( 'Queue idle', 'forms-notifier-for-elementor' );
				queuePill.classList.toggle( 'is-busy', pending > 0 );
				const failed = ( data.messages && data.messages.failed ) || 0;
				/* translators: %s: number of messages */
				badge( 'log', failed, sprintf( _n( '%s failed message today', '%s failed messages today', failed, 'forms-notifier-for-elementor' ), fmt.number( failed ) ) );
			}, () => {
				queueText.textContent = __( 'Queue idle', 'forms-notifier-for-elementor' );
			} );
		}
		if ( caps.submissions ) {
			api.get( 'submissions', { status: 'new', per_page: 1 } ).then( ( data ) => {
				/* translators: %s: number of submissions */
				badge( 'submissions', data.total, sprintf( _n( '%s new submission', '%s new submissions', data.total, 'forms-notifier-for-elementor' ), fmt.number( data.total ) ) );
			}, () => {} );
		}
	}
	bus.on( 'changed', () => poll() );

	/* --------------------------------------------------------------- Help */

	let topics = null;
	ui.helpTopics = () => {
		topics = topics || api.get( 'help' ).then( ( data ) => data.items || [], ( error ) => {
			topics = null;
			throw error;
		} );
		return topics;
	};

	/** Body of a help topic. */
	ui.helpBody = ( topic ) => h( 'div', { class: 'fnfe-article' }, topic.body ? h( 'div', { class: 'fnfe-prose' }, window.fnfe.safeHtml( topic.body ) ) : null, ( topic.steps || [] ).length ? ui.Guide( { id: 'help_' + topic.id, steps: topic.steps } ) : null );

	function help( id ) {
		const body = h( 'div', null, ui.Skeleton( 'form', 4 ) );
		const sheet = ui.Sheet( { title: __( 'Help', 'forms-notifier-for-elementor' ), body: body, footer: [ ui.Button( { label: __( 'All topics', 'forms-notifier-for-elementor' ), iconEnd: 'arrow-end', variant: 'ghost', onClick() {
			sheet.close();
			router.go( 'help' );
		} } ) ] } );
		ui.helpTopics().then( ( list ) => {
			const topic = list.find( ( item ) => item.id === id ) || list.find( ( item ) => item.id === 'quick-start' ) || list[ 0 ];
			if ( topic ) {
				sheet.el.querySelector( '.fnfe-dialog__title' ).textContent = topic.title;
				mount( body, ui.helpBody( topic ) );
			} else {
				mount( body, ui.Empty( { icon: 'help', text: __( 'No help topics yet.', 'forms-notifier-for-elementor' ) } ) );
			}
		}, ( error ) => mount( body, ui.ErrorState( error ) ) );
	}
	ui.help = help;

	/* ------------------------------------------------------------ Routing */

	/** Write a hash from a path and query. Empty values are left out. */
	const toHash = ( path, query ) => {
		const clean = Object.keys( query || {} ).filter( ( key ) => query[ key ] !== '' && query[ key ] !== undefined && query[ key ] !== null );
		return path + ( clean.length ? '?' + clean.map( ( key ) => encodeURIComponent( key ) + '=' + encodeURIComponent( query[ key ] ) ).join( '&' ) : '' );
	};

	function makeShell( route ) {
		return {
			query: route.query,
			can: can,
			go: ( path ) => router.go( path ),
			help: help,
			/** Change query values; the screen gets update( query ) instead of a new render. */
			setQuery( patch, replace ) {
				const now = router.current();
				router.go( toHash( now.path, Object.assign( {}, now.query, patch ) ), replace );
			},
			/** opts: title, sub, lead (node), crumbs [ { label, path } ], actions (nodes), help (topic id). */
			header( opts ) {
				title.textContent = opts.title;
				document.title = opts.title + ' ‹ ' + __( 'Forms Notifier', 'forms-notifier-for-elementor' );
				mount( lead, opts.lead || null );
				lead.hidden = ! opts.lead;
				mount( sub, opts.sub || null );
				sub.hidden = ! opts.sub;
				mount( actions, opts.actions || null );
				if ( opts.help ) {
					helpTopic = opts.help;
				}
				mount( crumbs, opts.crumbs ? h( 'ol', null, opts.crumbs.map( ( crumb ) => h( 'li', null, crumb.path ? h( 'a', { href: '#/' + crumb.path }, crumb.label ) : h( 'span', { 'aria-current': 'page' }, crumb.label ), crumb.path ? icon( 'chevron-end', 14 ) : null ) ) ) : null );
			},
			actions: ( nodes ) => mount( actions, nodes ),
			savebar: setSavebar,
			/** Full width content. */
			wide: ( on ) => content.classList.toggle( 'is-wide', on !== false ),
		};
	}

	function show( entry, params, query, route ) {
		const key = entry.pattern + '|' + JSON.stringify( params );
		if ( current && current.key === key && current.cleanup && current.cleanup.update ) {
			current.shell.query = query;
			current.cleanup.update( query );
			return;
		}
		if ( current && current.cleanup ) {
			try {
				current.cleanup();
			} catch ( error ) {
				window.console.error( error );
			}
		}
		ui.closeAll();
		setSavebar( null );
		drawer( false );
		mount( content, null );
		content.className = 'fnfe-view';
		const shell = makeShell( route );
		const section = sectionOf( route.path );
		helpTopic = HELP[ route.path.split( '/' )[ 0 ] ] || 'quick-start';
		Object.keys( links ).forEach( ( path ) => ( path === section ? links[ path ].setAttribute( 'aria-current', 'page' ) : links[ path ].removeAttribute( 'aria-current' ) ) );
		syncMenu( section );
		shell.header( { title: entry.def.title || '' } );

		current = { key: key, shell: shell, cleanup: null };
		if ( ! can( entry.def.cap ) ) {
			mount( content, ui.Empty( { icon: 'lock', title: __( 'You do not have access to this screen', 'forms-notifier-for-elementor' ), text: __( 'Ask an administrator of this site.', 'forms-notifier-for-elementor' ), actions: ui.Button( { label: __( 'Dashboard', 'forms-notifier-for-elementor' ), href: '#/dashboard' } ) } ) );
		} else {
			current.cleanup = entry.def.render( content, params, shell ) || null;
			wp.hooks.doAction( 'fnfe.screen.rendered', entry.pattern, content );
		}
		window.scrollTo( 0, 0 );
		if ( first ) {
			first = false;
		} else {
			title.focus( { preventScroll: true } );
			ui.announce( title.textContent );
		}
	}

	function notFound() {
		setSavebar( null );
		if ( current && current.cleanup ) {
			current.cleanup();
		}
		current = null;
		const shell = makeShell( router.current() );
		shell.header( { title: __( 'Page not found', 'forms-notifier-for-elementor' ) } );
		mount( content, ui.Empty( { icon: 'search', title: __( 'There is nothing at this address', 'forms-notifier-for-elementor' ), text: __( 'The link may be old or incomplete.', 'forms-notifier-for-elementor' ), actions: ui.Button( { label: __( 'Go to the dashboard', 'forms-notifier-for-elementor' ), variant: 'primary', href: '#/dashboard' } ) } ) );
	}

	/** Keep the highlighted item of the WordPress menu in step with the route. */
	function syncMenu( section ) {
		const menu = document.querySelector( '#toplevel_page_fnfe .wp-submenu' );
		if ( ! menu || ! SLUG[ section ] ) {
			return;
		}
		menu.querySelectorAll( 'li' ).forEach( ( item ) => {
			const link = item.querySelector( 'a' );
			const on = !! link && new RegExp( '[?&]page=' + SLUG[ section ] + '$' ).test( link.getAttribute( 'href' ) || '' );
			item.classList.toggle( 'current', on );
			if ( link ) {
				link.classList.toggle( 'current', on );
				if ( on ) {
					link.setAttribute( 'aria-current', 'page' );
				} else {
					link.removeAttribute( 'aria-current' );
				}
			}
		} );
	}

	document.addEventListener( 'click', ( event ) => {
		const link = event.target.closest && event.target.closest( 'a[href]' );
		if ( ! link || event.defaultPrevented || event.button || event.metaKey || event.ctrlKey || event.shiftKey || link.target === '_blank' ) {
			return;
		}
		const href = link.getAttribute( 'href' );
		const inside = root.contains( link );
		if ( inside && href.indexOf( '#/' ) === 0 ) {
			event.preventDefault();
			router.go( href );
			return;
		}
		// Submenu of WordPress: stay in the panel.
		const slug = ! inside && link.closest( '#toplevel_page_fnfe' ) && ( href.match( /[?&]page=(fnfe[a-z-]*)$/ ) || [] )[ 1 ];
		const section = slug && Object.keys( SLUG ).find( ( key ) => SLUG[ key ] === slug );
		if ( section && routes.some( ( item ) => item.path === section ) ) {
			event.preventDefault();
			router.go( section );
		} else if ( inside && boot.urls && boot.urls.admin && href.indexOf( boot.urls.admin.split( '#' )[ 0 ] + '#/' ) === 0 ) {
			event.preventDefault();
			router.go( href.slice( href.indexOf( '#' ) ) );
		}
	} );

	/* -------------------------------------------------------------- Start */

	mount( root, frag( h( 'a', { class: 'fnfe-skip', href: '#fnfe-main', onClick( event ) {
		event.preventDefault();
		event.stopPropagation();
		title.focus();
	} }, __( 'Skip to content', 'forms-notifier-for-elementor' ) ), h( 'div', { class: 'fnfe-shell' }, rail, shade, main ), savebar ) );
	showTheme();

	screens.all().forEach( ( entry ) => router.add( entry.pattern, ( params, query, route ) => show( entry, params, query, route ) ) );
	bus.on( 'route', ( route ) => {
		if ( ! route.pattern ) {
			notFound();
		}
	} );
	if ( ! /^#\/./.test( window.location.hash ) ) {
		window.history.replaceState( null, '', '#/' + ( root.dataset.route || 'dashboard' ) );
	}
	router.start();
	poll();
	window.setInterval( poll, 60000 );
}() );
