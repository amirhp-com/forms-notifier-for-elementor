/*
 * Paste into the browser console on a plugin screen. Prints one line per missing or wrong member of window.fnfe.
 */
( function () {
	'use strict';
	const shape = {
		version: 'string', boot: 'object', h: 'function', frag: 'function', mount: 'function', safeHtml: 'function',
		'api.get': 'function', 'api.post': 'function', 'api.put': 'function', 'api.patch': 'function', 'api.del': 'function',
		'api.setToken': 'function', 'api.download': 'function', store: 'function', 'bus.on': 'function', 'bus.off': 'function',
		'bus.emit': 'function', 'router.add': 'function', 'router.start': 'function', 'router.go': 'function',
		'router.current': 'function', 'router.guard': 'function', i18n: 'object', toast: 'function', 'path.get': 'function',
		'path.set': 'function', 'path.del': 'function', uid: 'function', debounce: 'function', 'fmt.date': 'function',
		'fmt.bytes': 'function', 'fmt.number': 'function', 'fmt.relative': 'function', hooks: 'object', ui: 'object',
		icon: 'function', 'fields.register': 'function', 'fields.render': 'function', 'fields.form': 'function',
		'screens.register': 'function',
	};
	const problems = [];
	Object.keys( shape ).forEach( ( key ) => {
		const value = key.split( '.' ).reduce( ( node, part ) => ( node ? node[ part ] : undefined ), window.fnfe );
		if ( typeof value !== shape[ key ] ) {
			problems.push( key + ': expected ' + shape[ key ] + ', found ' + typeof value );
		}
	} );

	const { h, safeHtml, path, store, uid, api } = window.fnfe;
	const check = ( name, ok ) => ok || problems.push( name );
	check( 'h builds text nodes', h( 'p', null, '<b>x</b>' ).children.length === 0 );
	check( 'h ignores html', h( 'p', { html: '<b>x</b>' } ).childNodes.length === 0 );
	check( 'h drops script urls', ! h( 'a', { href: 'java\nscript:alert(1)' } ).hasAttribute( 'href' ) );
	check( 'h creates svg nodes', h( 'path', { d: 'M0 0' } ).namespaceURI === 'http://www.w3.org/2000/svg' );
	check( 'h joins classes', h( 'i', { class: [ 'a', { b: true, c: false } ] } ).className === 'a b' );
	check( 'select takes its value', h( 'select', { value: '2' }, h( 'option', { value: '1' } ), h( 'option', { value: '2' } ) ).value === '2' );
	const box = h( 'div', null, safeHtml( '<strong>a</strong><img src=x onerror=alert(1)><script>1</' + 'script><a href="javascript:1" onclick="1">l</a>' ) );
	check( 'safeHtml drops images and handlers', ! box.querySelector( 'img,script,[onclick],a[href]' ) && !! box.querySelector( 'strong' ) );
	const object = path.set( {}, 'a.b.c', 1 );
	check( 'path', path.get( object, 'a.b.c' ) === 1 && path.get( path.del( object, 'a.b.c' ), 'a.b.c', 'x' ) === 'x' );
	check( 'path blocks prototypes', path.set( {}, '__proto__.polluted', 1 ) && ( {} ).polluted === undefined );
	const state = store( { a: 1 } );
	let seen = 0;
	const stop = state.subscribe( () => seen++ );
	state.set( { b: 2 } );
	stop();
	state.set( { b: 3 } );
	check( 'store', seen === 1 && state.get().a === 1 && state.get().b === 3 );
	check( 'uid', /^r_[a-z0-9]{8}$/.test( uid( 'r_' ) ) );

	return api.get( 'bootstrap' ).then( ( data ) => {
		check( 'bootstrap', data.version === window.fnfe.version );
		return api.get( 'channels/does_not_exist' ).then( () => problems.push( 'missing channel did not fail' ), ( error ) => check( 'error shape', error.status === 404 && error.code === 'fnfe_unknown_channel' && typeof error.fields === 'object' ) );
	} ).then( () => {
		const result = problems.length ? problems.join( '\n' ) : 'window.fnfe is complete';
		window.console.log( result );
		return result;
	} );
}() );
