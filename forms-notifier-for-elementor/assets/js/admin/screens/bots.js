/**
 * Bots: discovered chats, linked admins, connection.
 */
( function () {
	'use strict';

	const { __, _x, sprintf } = wp.i18n;
	const { h, mount, frag, icon, api, ui, fmt, fields, uid, router } = window.fnfe;

	const PLATFORMS = [ 'telegram', 'bale' ];
	const TYPE_ICON = { private: 'user', group: 'group', supergroup: 'users', channel: 'megaphone' };
	const typeName = ( type ) => ( {
		private: __( 'Private chat', 'forms-notifier-for-elementor' ),
		group: __( 'Group', 'forms-notifier-for-elementor' ),
		supergroup: __( 'Group', 'forms-notifier-for-elementor' ),
		channel: _x( 'Channel', 'messenger chat type', 'forms-notifier-for-elementor' ),
	}[ type ] || type );

	const botLink = ( platform, name ) => ( platform === 'bale' ? 'https://ble.ir/' : 'https://t.me/' ) + name;

	/* -------------------------------------------------------------- Chats */

	function chats( container, platform, shell ) {
		const list = h( 'div', { class: 'fnfe-gap' } );
		const state = { type: shell.query.type || '', search: shell.query.q || '', page: Number( shell.query.page ) || 1 };
		const how = ui.Fold( { class: 'fnfe-fold--inset', title: __( 'How chats get here', 'forms-notifier-for-elementor' ), lead: h( 'span', { class: 'fnfe-fold__lead' }, icon( 'info', 18 ) ) }, h( 'div', { class: 'fnfe-prose' },
			h( 'p', null, __( 'A messenger does not hand out a list of chats. The bot only knows chats it heard from.', 'forms-notifier-for-elementor' ) ),
			h( 'ul', null,
				h( 'li', null, __( 'Person: they open the bot and press Start.', 'forms-notifier-for-elementor' ) ),
				h( 'li', null, __( 'Group: add the bot, then send /id in the group. In a group with topics, send /id once in each topic.', 'forms-notifier-for-elementor' ) ),
				platform === 'telegram'
					? h( 'li', null, __( 'Channel: add the bot as an admin and post once.', 'forms-notifier-for-elementor' ) )
					: h( 'li', null, __( 'Bale channels must be typed by hand: @username or the numeric id.', 'forms-notifier-for-elementor' ) )
			)
		) );
		const filters = h( 'div', null );
		const drawFilters = () => mount( filters, ui.Chips( { label: __( 'Type', 'forms-notifier-for-elementor' ), value: state.type, options: [ { value: '', label: __( 'All', 'forms-notifier-for-elementor' ) }, { value: 'private', label: __( 'People', 'forms-notifier-for-elementor' ) }, { value: 'group', label: __( 'Groups', 'forms-notifier-for-elementor' ) }, { value: 'channel', label: __( 'Channels', 'forms-notifier-for-elementor' ) } ], onChange: ( type ) => shell.setQuery( { type: type, page: '' } ) } ) );
		const poll = ui.Button( { label: __( 'Find chats', 'forms-notifier-for-elementor' ), icon: 'refresh', variant: 'primary', size: 's', onClick: () => api.post( 'bots/' + platform + '/poll' ).then( ( data ) => {
			/* translators: %s: number of chats */
			window.fnfe.toast( data.message || sprintf( __( 'Checked. New or changed chats: %s.', 'forms-notifier-for-elementor' ), fmt.number( data.chats || 0 ) ), data.ok === false ? 'danger' : 'success' );
			load();
		}, ui.fail ) } );
		mount( container, frag( h( 'div', { class: 'fnfe-toolbar' }, filters, ui.Search( { value: state.search, onSearch: ( text ) => shell.setQuery( { q: text, page: '' }, true ) } ), h( 'span', { class: 'fnfe-grow' } ), poll ), how, list ) );

		function use( chat ) {
			const key = 'channels.' + platform + '.receivers';
			const label = chat.topic_name ? chat.title + ' › ' + chat.topic_name : chat.title;
			return api.get( 'settings', { tab: platform } ).then( ( data ) => {
				const rows = fields.list( data.values[ key ] );
				if ( rows.some( ( row ) => row.address === chat.address ) ) {
					window.fnfe.toast( __( 'This receiver is already in the list.', 'forms-notifier-for-elementor' ), 'info' );
					return null;
				}
				return api.post( 'settings', { values: { [ key ]: rows.concat( { id: uid( 'r_' ), address: chat.address, label: label, enabled: true, lang: '', langs: [], meta: { type: chat.thread_id ? 'topic' : chat.type } } ) }, secrets: {} } ).then( ( result ) => {
					const error = ( result.errors || {} )[ key ];
					if ( error ) {
						window.fnfe.toast( error, 'danger' );
						return;
					}
					/* translators: %s: chat name */
					window.fnfe.toast( sprintf( __( '%s gets the messages of every form now.', 'forms-notifier-for-elementor' ), label ), 'success' );
					ui.refreshChannels();
				} );
			} ).catch( ui.fail );
		}

		function rename( chat ) {
			const topic = !! chat.thread_id;
			let name = topic ? chat.topic_name : chat.title;
			const save = () => api.patch( 'bots/' + platform + '/chats/' + chat.id, topic ? { topic_name: name } : { title: name } ).then( () => {
				dialog.close();
				load();
			}, ui.fail );
			const dialog = ui.Modal( { title: __( 'Rename', 'forms-notifier-for-elementor' ), size: 's', desc: __( 'Only the name in this panel changes.', 'forms-notifier-for-elementor' ), body: fields.render( { key: 'name', type: 'text', label: __( 'Name', 'forms-notifier-for-elementor' ) }, name, ( text ) => {
				name = text;
			}, {} ), footer: [ ui.Button( { label: __( 'Cancel', 'forms-notifier-for-elementor' ), variant: 'ghost', onClick: () => dialog.close() } ), ui.Button( { label: __( 'Rename', 'forms-notifier-for-elementor' ), variant: 'primary', onClick: save } ) ] } );
		}

		function forget( chat ) {
			ui.confirm( { /* translators: %s: chat name */ title: sprintf( __( 'Forget %s?', 'forms-notifier-for-elementor' ), chat.topic_name || chat.title || chat.chat_id ), text: __( 'It disappears from this list until the bot hears from it again. Receivers you already added keep working.', 'forms-notifier-for-elementor' ), confirm: __( 'Forget', 'forms-notifier-for-elementor' ), danger: true } ).then( ( yes ) => yes && api.del( 'bots/' + platform + '/chats/' + chat.id ).then( load, ui.fail ) );
		}

		const name = ( chat ) => h( 'div', { class: [ 'fnfe-chatcell', chat.thread_id ? 'is-topic' : '' ] }, h( 'span', { class: 'fnfe-receiver__icon' }, icon( chat.thread_id ? 'topic' : TYPE_ICON[ chat.type ] || 'chat', 18 ) ), h( 'span', null, h( 'strong', { class: 'fnfe-latin', dir: 'auto' }, chat.thread_id ? chat.topic_name || chat.title : chat.title || chat.username || chat.chat_id ), h( 'small', null, chat.thread_id ? h( 'bdi', null, chat.title ) : typeName( chat.type ), chat.username ? ui.mono( '@' + chat.username ) : null ) ) );

		function load() {
			mount( list, ui.Skeleton( 'rows', 5 ) );
			const read = ( type ) => api.get( 'bots/' + platform + '/chats', { type: type, search: state.search, page: state.page, per_page: 50, with_topics: 1 } );
			// Messengers have two kinds of groups; people see one.
			return Promise.all( state.type === 'group' ? [ read( 'supergroup' ), read( 'group' ) ] : [ read( state.type ) ] ).then( ( pages ) => {
				const data = Object.assign( {}, pages[ 0 ], { total: Math.max.apply( null, pages.map( ( page ) => page.total ) ) } );
				const all = pages.reduce( ( out, page ) => out.concat( page.items ), [] );
				// Topics follow their group.
				const rows = [];
				all.filter( ( chat ) => ! chat.thread_id ).forEach( ( chat ) => rows.push( chat, ...all.filter( ( topic ) => topic.thread_id && topic.chat_id === chat.chat_id ) ) );
				all.forEach( ( chat ) => rows.indexOf( chat ) === -1 && rows.push( chat ) );
				if ( ! rows.length ) {
					how.open();
					mount( list, ui.Empty( state.search || state.type
						? { icon: 'search', title: __( 'No chats match these filters', 'forms-notifier-for-elementor' ), actions: ui.Button( { label: __( 'Clear filters', 'forms-notifier-for-elementor' ), onClick: () => shell.setQuery( { q: '', type: '', page: '' } ) } ) }
						: { icon: 'chat', title: __( 'No chats found yet', 'forms-notifier-for-elementor' ), text: __( 'Send /start to the bot from your own account, then press "Find chats".', 'forms-notifier-for-elementor' ) } ) );
					return;
				}
				mount( list, ui.Card( { flush: true }, ui.Table( {
					label: __( 'Chats', 'forms-notifier-for-elementor' ),
					rows: rows,
					columns: [
						{ label: __( 'Chat', 'forms-notifier-for-elementor' ), primary: true, render: name },
						{ label: __( 'Address', 'forms-notifier-for-elementor' ), render: ( chat ) => h( 'span', { class: 'fnfe-nowrap' }, ui.mono( chat.address ), ui.CopyButton( chat.address ) ) },
						{ label: __( 'Last seen', 'forms-notifier-for-elementor' ), render: ( chat ) => ui.Time( chat.last_seen ) },
						{ label: __( 'Status', 'forms-notifier-for-elementor' ), render: ( chat ) => ( chat.status === 'active' || ! chat.status ? ui.Badge( __( 'Active', 'forms-notifier-for-elementor' ), 'ok', 'check' ) : ui.Badge( { left: __( 'Bot left', 'forms-notifier-for-elementor' ), kicked: __( 'Bot blocked', 'forms-notifier-for-elementor' ), closed: __( 'Closed', 'forms-notifier-for-elementor' ) }[ chat.status ] || chat.status, 'warn', 'alert' ) ) },
					],
					actions: ( chat ) => [ ui.Button( { label: __( 'Use as receiver', 'forms-notifier-for-elementor' ), size: 's', onClick: () => use( chat ) } ), ui.Menu( { size: 's', items: [ { label: __( 'Rename', 'forms-notifier-for-elementor' ), icon: 'edit', onSelect: () => rename( chat ) }, { label: __( 'Forget', 'forms-notifier-for-elementor' ), icon: 'trash', danger: true, onSelect: () => forget( chat ) } ] } ) ],
				} ), ui.Pagination( { page: data.page, perPage: data.per_page, total: data.total, onPage: ( page ) => shell.setQuery( { page: page } ) } ) ) );
			}, ( error ) => mount( list, ui.ErrorState( error, load ) ) );
		}

		drawFilters();
		load();
		return { update( query ) {
			Object.assign( state, { type: query.type || '', search: query.q || '', page: Number( query.page ) || 1 } );
			drawFilters();
			load();
		} };
	}

	/* ------------------------------------------------------------- Admins */

	function admins( container, platform ) {
		const list = h( 'div', null );
		let known = [];

		function remove( item ) {
			ui.confirm( { /* translators: %s: name of a person */ title: sprintf( __( 'Remove %s?', 'forms-notifier-for-elementor' ), item.name || item.uid ), text: __( 'They can no longer use admin commands, buttons or the mini app. Open sessions end at once.', 'forms-notifier-for-elementor' ), confirm: __( 'Remove', 'forms-notifier-for-elementor' ), danger: true } ).then( ( yes ) => yes && api.del( 'bots/' + platform + '/admins/' + encodeURIComponent( item.uid ) ).then( load, ui.fail ) );
		}

		function link() {
			let timer = 0;
			const body = h( 'div', null, ui.Skeleton( 'form', 2 ) );
			const before = known.map( ( item ) => String( item.uid ) );
			const dialog = ui.Modal( {
				title: __( 'Link your account', 'forms-notifier-for-elementor' ),
				lead: ui.Tile( platform ),
				body: body,
				onClose: () => window.clearInterval( timer ),
				footer: [ ui.Button( { label: __( 'Close', 'forms-notifier-for-elementor' ), variant: 'ghost', onClick: () => dialog.close() } ) ],
			} );
			api.post( 'bots/' + platform + '/link', {} ).then( ( data ) => {
				const status = h( 'div', { class: 'fnfe-waiting', role: 'status' }, h( 'i', { 'aria-hidden': 'true' } ), __( 'Waiting for you to press Start in the bot…', 'forms-notifier-for-elementor' ) );
				mount( body, frag(
					h( 'p', null, __( 'Open this link on the device where you use the messenger and press Start. It works once and for 15 minutes.', 'forms-notifier-for-elementor' ) ),
					h( 'div', { class: 'fnfe-copyline' }, ui.mono( data.url ), ui.CopyButton( data.url ) ),
					h( 'p', null, ui.Button( { label: __( 'Open the bot', 'forms-notifier-for-elementor' ), icon: 'external', href: data.url, target: '_blank', variant: 'primary' } ) ),
					status
				) );
				timer = window.setInterval( () => api.get( 'bots/' + platform + '/admins' ).then( ( now ) => {
					const fresh = now.items.find( ( item ) => before.indexOf( String( item.uid ) ) === -1 );
					if ( fresh ) {
						window.clearInterval( timer );
						/* translators: %s: name of a person */
						mount( body, ui.Callout( 'success', sprintf( __( '%s is linked and can use the bot now.', 'forms-notifier-for-elementor' ), fresh.name || fresh.uid ) ) );
						load();
					}
				}, () => {} ), 3000 );
			}, ( error ) => mount( body, ui.ErrorState( error ) ) );
		}

		function load() {
			mount( list, ui.Skeleton( 'rows', 3 ) );
			api.get( 'bots/' + platform + '/admins' ).then( ( data ) => {
				known = data.items;
				if ( ! known.length ) {
					mount( list, ui.Empty( { icon: 'users', title: __( 'No admins yet', 'forms-notifier-for-elementor' ), text: __( 'Admins can read submissions in the chat, press action buttons and open the mini app.', 'forms-notifier-for-elementor' ), actions: ui.Button( { label: __( 'Link your account', 'forms-notifier-for-elementor' ), icon: 'plus', variant: 'primary', onClick: link } ) } ) );
					return;
				}
				mount( list, ui.Card( { flush: true }, ui.Table( {
					label: __( 'Admins', 'forms-notifier-for-elementor' ),
					rows: known,
					rowKey: ( row ) => row.uid,
					columns: [
						{ label: __( 'Person', 'forms-notifier-for-elementor' ), primary: true, render: ( row ) => h( 'div', { class: 'fnfe-chatcell' }, h( 'span', { class: 'fnfe-receiver__icon' }, icon( 'user', 18 ) ), h( 'span', null, h( 'strong', { class: 'fnfe-latin', dir: 'auto' }, row.name || row.uid ), row.username ? h( 'small', null, ui.mono( '@' + row.username ) ) : null ) ) },
						{ label: __( 'Id', 'forms-notifier-for-elementor' ), render: ( row ) => ui.mono( row.uid ) },
						{ label: __( 'WordPress user', 'forms-notifier-for-elementor' ), render: ( row ) => row.wp_name || '–' },
						{ label: __( 'May', 'forms-notifier-for-elementor' ), render: ( row ) => ( row.perms || [] ).indexOf( 'act' ) > -1 ? __( 'View and act', 'forms-notifier-for-elementor' ) : __( 'View', 'forms-notifier-for-elementor' ) },
					],
					actions: ( row ) => ui.IconButton( { icon: 'trash', danger: true, size: 's', label: __( 'Remove', 'forms-notifier-for-elementor' ), onClick: () => remove( row ) } ),
				} ) ) );
			}, ( error ) => mount( list, ui.ErrorState( error, load ) ) );
		}

		mount( container, frag(
			h( 'div', { class: 'fnfe-toolbar' }, h( 'p', { class: 'fnfe-muted fnfe-grow' }, __( 'People without a WordPress account can be added by their numeric id on the settings tab.', 'forms-notifier-for-elementor' ) ), ui.Button( { label: __( 'Add admin', 'forms-notifier-for-elementor' ), icon: 'plus', variant: 'primary', size: 's', onClick: link } ) ),
			list
		) );
		load();
		return {};
	}

	/* --------------------------------------------------------- Connection */

	function connection( container, platform ) {
		const body = h( 'div', null );
		const guide = h( 'div', { class: 'fnfe-gap' } );
		mount( container, frag( body, guide ) );
		const modeName = ( mode ) => ( { webhook: __( 'Webhook', 'forms-notifier-for-elementor' ), polling: __( 'Regular checks', 'forms-notifier-for-elementor' ), off: _x( 'Off', 'channel state', 'forms-notifier-for-elementor' ), auto: __( 'Automatic', 'forms-notifier-for-elementor' ) }[ mode ] || mode || '–' );

		function draw( data ) {
			const me = data.me || {};
			const hook = data.webhook || {};
			const polling = data.polling || {};
			const line = ( label, value ) => ( value === null || value === undefined || value === '' ? null : [ h( 'dt', null, label ), h( 'dd', null, value ) ] );
			const connect = ( mode ) => api.post( 'bots/' + platform + '/connect', { mode: mode, values: {}, secrets: {} } ).then( ( next ) => {
				draw( next );
				if ( next.error ) {
					window.fnfe.toast( next.error, 'danger' );
					return;
				}
				window.fnfe.toast( __( 'Connection updated.', 'forms-notifier-for-elementor' ), 'success' );
				ui.refreshChannels();
			}, ui.fail );
			if ( ! data.configured ) {
				mount( body, ui.Empty( { icon: 'robot', title: __( 'No bot token yet', 'forms-notifier-for-elementor' ), text: __( 'Enter the token on the settings tab first.', 'forms-notifier-for-elementor' ), actions: ui.Button( { label: __( 'Open settings', 'forms-notifier-for-elementor' ), variant: 'primary', href: '#/channels/' + platform } ) } ) );
				return;
			}
			const failed = hook.last_error || '';
			mount( body, frag(
				failed ? ui.Callout( 'danger', h( 'div', null, h( 'p', { dir: 'auto' }, failed ), hook.last_error_date ? h( 'p', null, ui.Time( hook.last_error_date ) ) : null ), { title: __( 'The messenger reported a webhook error', 'forms-notifier-for-elementor' ) } ) : null,
				ui.Card( {
					title: me.name || __( 'Bot', 'forms-notifier-for-elementor' ),
					desc: me.username ? h( 'a', { href: botLink( platform, me.username ), target: '_blank', rel: 'noopener noreferrer' }, ui.mono( '@' + me.username ) ) : null,
					actions: ui.Button( { label: __( 'Reconnect', 'forms-notifier-for-elementor' ), icon: 'refresh', size: 's', variant: 'primary', onClick: () => connect( 'auto' ) } ),
					foot: [ ui.Button( { label: __( 'Use a webhook', 'forms-notifier-for-elementor' ), size: 's', disabled: data.can_webhook === false, onClick: () => connect( 'webhook' ) } ), ui.Button( { label: __( 'Use regular checks', 'forms-notifier-for-elementor' ), size: 's', onClick: () => connect( 'polling' ) } ), h( 'span', { class: 'fnfe-grow' } ), ui.Button( { label: __( 'Check now', 'forms-notifier-for-elementor' ), size: 's', variant: 'ghost', icon: 'play', onClick: () => api.post( 'bots/' + platform + '/poll' ).then( load, ui.fail ) } ) ],
				}, h( 'dl', { class: 'fnfe-dl' },
					line( __( 'Receives messages by', 'forms-notifier-for-elementor' ), modeName( data.mode ) ),
					line( __( 'Why', 'forms-notifier-for-elementor' ), data.reason ),
					line( __( 'Webhook address', 'forms-notifier-for-elementor' ), hook.url ? h( 'span', { class: 'fnfe-copyline' }, ui.mono( hook.url ), ui.CopyButton( hook.url ) ) : '' ),
					line( __( 'Webhook set', 'forms-notifier-for-elementor' ), data.mode === 'webhook' ? ( hook.set ? ui.Badge( __( 'Yes', 'forms-notifier-for-elementor' ), 'ok', 'check' ) : ui.Badge( __( 'No', 'forms-notifier-for-elementor' ), 'warn', 'alert' ) ) : '' ),
					line( __( 'Waiting updates', 'forms-notifier-for-elementor' ), hook.pending ? fmt.number( hook.pending ) : '' ),
					line( __( 'Last check', 'forms-notifier-for-elementor' ), polling.last_run ? ui.Time( polling.last_run ) : '' ),
					line( __( 'Mini app address', 'forms-notifier-for-elementor' ), data.miniapp_url ? h( 'span', { class: 'fnfe-copyline' }, ui.mono( data.miniapp_url ), ui.CopyButton( data.miniapp_url ) ) : '' )
				) )
			) );
		}

		function load() {
			return api.get( 'bots/' + platform + '/status' ).then( draw, ( error ) => mount( body, ui.ErrorState( error, load ) ) );
		}
		mount( body, ui.Skeleton( 'form', 4 ) );
		load();
		// The guide for hosts that cannot reach the messenger comes with the channel schema.
		api.get( 'channels/' + platform ).then( ( data ) => {
			const all = data.sections.reduce( ( out, section ) => out.concat( section.fields ), [] );
			const steps = all.find( ( field ) => field.type === 'guide' );
			if ( steps ) {
				mount( guide, ui.Card( { title: steps.label, desc: steps.desc }, ui.Guide( { id: steps.key, steps: steps.steps } ) ) );
			}
		}, () => {} );
		return {};
	}

	const TABS = { chats: chats, admins: admins, connection: connection };

	window.fnfe.screens.register( 'bots/:platform', { title: __( 'Bot', 'forms-notifier-for-elementor' ), cap: 'manage', render( container, params ) {
		window.setTimeout( () => router.go( 'bots/' + params.platform + '/chats', true ) );
		return null;
	} } );

	window.fnfe.screens.register( 'bots/:platform/:tab', {
		title: __( 'Bot', 'forms-notifier-for-elementor' ),
		cap: 'manage',
		render( container, params, shell ) {
			const meta = ui.channelMeta( params.platform );
			if ( ! meta || PLATFORMS.indexOf( params.platform ) === -1 || ! TABS[ params.tab ] ) {
				shell.header( { title: __( 'Bot', 'forms-notifier-for-elementor' ) } );
				mount( container, ui.Empty( { icon: 'robot', title: __( 'There is no such bot', 'forms-notifier-for-elementor' ), actions: ui.Button( { label: __( 'All channels', 'forms-notifier-for-elementor' ), href: '#/channels' } ) } ) );
				return null;
			}
			ui.channelHeader( shell, params.platform, null );
			const body = h( 'div', null );
			mount( container, frag( ui.botTabs( params.platform, params.tab ), body ) );
			const screen = TABS[ params.tab ]( body, params.platform, shell );
			const cleanup = () => ui.closeAll();
			if ( screen.update ) {
				cleanup.update = screen.update;
			}
			return cleanup;
		},
	} );
}() );
