/**
 * Dashboard.
 */
( function () {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const { h, mount, frag, icon, boot, api, ui, fmt, router, bus } = window.fnfe;

	/* --------------------------------------------------------- Checklist */

	function checklist( data ) {
		const channels = boot.channels || [];
		const ready = channels.filter( ( item ) => item.ready );
		const steps = [
			[ __( 'Find the forms of this site', 'forms-notifier-for-elementor' ), data.forms > 0, 'forms', data.forms ? /* translators: %s: number of forms */ sprintf( _n( '%s form found', '%s forms found', data.forms, 'forms-notifier-for-elementor' ), fmt.number( data.forms ) ) : __( 'Scan your Elementor pages once.', 'forms-notifier-for-elementor' ) ],
			[ __( 'Connect a channel', 'forms-notifier-for-elementor' ), channels.some( ( item ) => item.enabled ), 'channels', __( 'Telegram, SMS, email and more.', 'forms-notifier-for-elementor' ) ],
			[ __( 'Add receivers', 'forms-notifier-for-elementor' ), channels.some( ( item ) => item.enabled && ( item.receivers_count || item.capabilities.receivers === 'none' ) ), ready[ 0 ] ? 'channels/' + ready[ 0 ].id : 'channels', __( 'Choose who gets the messages.', 'forms-notifier-for-elementor' ) ],
			[ __( 'Send a test', 'forms-notifier-for-elementor' ), data.sent > 0, ready[ 0 ] ? 'channels/' + ready[ 0 ].id : 'channels', __( 'Make sure a message arrives.', 'forms-notifier-for-elementor' ) ],
			[ __( 'Open the mini app (optional)', 'forms-notifier-for-elementor' ), !! ( boot.features && boot.features.miniapp ), 'settings/miniapp', __( 'Read submissions inside Telegram or Bale.', 'forms-notifier-for-elementor' ) ],
		];
		const done = steps.filter( ( step ) => step[ 1 ] ).length;
		if ( ui.dismissed().indexOf( 'checklist' ) > -1 || steps.slice( 0, 4 ).every( ( step ) => step[ 1 ] ) ) {
			return null;
		}
		const next = steps.findIndex( ( step ) => ! step[ 1 ] );
		const card = ui.Card(
			{
				class: 'fnfe-start',
				title: __( 'Get your first message', 'forms-notifier-for-elementor' ),
				/* translators: %1$s: finished steps, %2$s: all steps */
				desc: sprintf( __( '%1$s of %2$s done', 'forms-notifier-for-elementor' ), fmt.number( done ), fmt.number( steps.length ) ),
				actions: ui.Button( { label: __( 'Hide', 'forms-notifier-for-elementor' ), variant: 'ghost', size: 's', onClick() {
					ui.remember( 'checklist', true );
					card.remove();
				} } ),
			},
			h( 'ol', { class: 'fnfe-steps' }, steps.map( ( step, i ) => h(
				'li',
				{ class: [ 'fnfe-steps__item', step[ 1 ] ? 'is-done' : '', i === next ? 'is-next' : '' ] },
				h( 'a', { href: '#/' + step[ 2 ] }, h( 'span', { class: 'fnfe-steps__lamp' }, step[ 1 ] ? icon( 'check', 16 ) : fmt.number( i + 1 ) ), h( 'span', null, h( 'strong', null, step[ 0 ], step[ 1 ] ? h( 'span', { class: 'fnfe-sr' }, ' ' + __( '(done)', 'forms-notifier-for-elementor' ) ) : null ), h( 'small', null, step[ 3 ] ) ) )
			) ) )
		);
		return card;
	}

	/* ------------------------------------------------------------- Board */

	function spark( series, key, label ) {
		const values = series.map( ( day ) => Number( day[ key ] ) || 0 );
		const max = Math.max.apply( null, values.concat( 1 ) );
		return h( 'span', { class: 'fnfe-spark', role: 'img', 'aria-label': label, dir: 'ltr' }, values.slice( -30 ).map( ( value, i ) => h( 'i', { class: value ? '' : 'is-zero', style: { height: Math.max( 6, Math.round( value / max * 100 ) ) + '%' }, title: series[ i ] ? fmt.date( series[ i ].date + 'T12:00:00Z', { month: 'short', day: 'numeric' } ) + ': ' + fmt.number( value ) : '' } ) ) );
	}

	function delta( now, before ) {
		if ( before === null || ( ! before && ! now ) ) {
			return null;
		}
		const diff = now - before;
		return h( 'span', { class: 'fnfe-stat__delta', title: __( 'Compared with the period before', 'forms-notifier-for-elementor' ) }, h( 'bdi', { dir: 'ltr' }, ( diff > 0 ? '+' : diff < 0 ? '−' : '±' ) + fmt.number( Math.abs( diff ) ) ) );
	}

	function stat( opts ) {
		return h( 'a', { class: [ 'fnfe-stat', opts.tone ? 'fnfe-stat--' + opts.tone : '' ], href: opts.href }, h( 'span', { class: 'fnfe-stat__label' }, opts.label ), h( 'span', { class: 'fnfe-stat__line' }, h( 'strong', null, fmt.number( opts.value ) ), delta( opts.value, opts.before === undefined ? null : opts.before ) ), opts.series && opts.series.length > 1 ? spark( opts.series, opts.key, opts.label ) : h( 'span', { class: 'fnfe-stat__note' }, opts.note || '' ) );
	}

	const sum = ( days, key ) => days.reduce( ( total, day ) => total + ( Number( day[ key ] ) || 0 ), 0 );

	/** Sums of the period before the shown one, from the 30 day series. */
	function previous( range, long ) {
		const days = ( long && long.series ) || [];
		const n = { today: 1, '7d': 7 }[ range ];
		if ( ! n || days.length < n * 2 ) {
			return {};
		}
		const part = days.slice( -2 * n, -n );
		return { submissions: sum( part, 'submissions' ), sent: sum( part, 'sent' ), failed: sum( part, 'failed' ) };
	}

	/** Forms on one side, channels on the other, the queue between them. */
	function wire( data, counts ) {
		const byChannel = data.by_channel || {};
		const formRows = ( Array.isArray( data.by_form ) ? data.by_form : Object.values( data.by_form || {} ) ).slice().sort( ( a, b ) => b.submissions - a.submissions ).slice( 0, 5 );
		const channelRows = ( boot.channels || [] ).filter( ( item ) => item.enabled || byChannel[ item.id ] ).map( ( item ) => Object.assign( { sent: 0, failed: 0 }, byChannel[ item.id ], { meta: item } ) ).sort( ( a, b ) => ( b.sent + b.failed ) - ( a.sent + a.failed ) ).slice( 0, 6 );
		const pending = counts.pending;
		if ( ! channelRows.length ) {
			return h( 'div', { class: 'fnfe-wire fnfe-wire--empty' }, h( 'p', null, __( 'No channel is on yet. Connect one and the route of every submission appears here.', 'forms-notifier-for-elementor' ) ), ui.Button( { label: __( 'Choose a channel', 'forms-notifier-for-elementor' ), variant: 'primary', href: '#/channels' } ) );
		}
		const formNodes = formRows.length ? formRows.map( ( row ) => h( 'a', { class: 'fnfe-wire__node', href: '#/forms/' + row.form_id }, h( 'span', { class: 'fnfe-wire__name', dir: 'auto' }, row.name || __( 'Form', 'forms-notifier-for-elementor' ) ), h( 'span', { class: 'fnfe-wire__num' }, fmt.number( row.submissions ) ) ) ) : [ h( 'span', { class: 'fnfe-wire__node is-quiet' }, h( 'span', { class: 'fnfe-wire__name' }, __( 'No submissions in this period', 'forms-notifier-for-elementor' ) ) ) ];
		const hub = h( 'a', { class: [ 'fnfe-wire__hub', pending ? 'is-busy' : '' ], href: '#/log?status=queued', title: __( 'In queue', 'forms-notifier-for-elementor' ) }, ui.Mark( 30 ), pending ? h( 'span', null, fmt.number( pending ) ) : null );
		const channelNodes = channelRows.map( ( row ) => {
			const total = row.sent + row.failed;
			return h(
				'a',
				{ class: [ 'fnfe-wire__node', 'fnfe-wire__node--channel', row.meta.ready ? '' : 'is-broken' ], href: total ? '#/log?channel=' + row.meta.id : '#/channels/' + row.meta.id },
				ui.Tile( row.meta.id, 's' ),
				h( 'span', { class: 'fnfe-wire__body' }, h( 'span', { class: 'fnfe-wire__name' }, row.meta.label ), h( 'span', { class: 'fnfe-wire__bar', 'aria-hidden': 'true' }, total ? [ h( 'i', { class: 'is-ok', style: { width: row.sent / total * 100 + '%' } } ), h( 'i', { class: 'is-bad', style: { width: row.failed / total * 100 + '%' } } ) ] : null ) ),
				h( 'span', { class: 'fnfe-wire__num' }, fmt.number( row.sent ), row.failed ? h( 'em', { title: ui.statusLabel( 'failed' ) }, icon( 'x-circle', 14 ), fmt.number( row.failed ), h( 'span', { class: 'fnfe-sr' }, ui.statusLabel( 'failed' ) ) ) : null )
			);
		} );
		const svg = h( 'svg', { class: 'fnfe-wire__lines', 'aria-hidden': 'true', focusable: 'false' } );
		const el = h( 'div', { class: 'fnfe-wire' }, svg, h( 'div', { class: 'fnfe-wire__col' }, h( 'h3', null, __( 'Forms', 'forms-notifier-for-elementor' ) ), formNodes ), hub, h( 'div', { class: 'fnfe-wire__col' }, h( 'h3', null, __( 'Delivery by channel', 'forms-notifier-for-elementor' ) ), channelNodes ) );

		const maxForm = Math.max.apply( null, formRows.map( ( row ) => row.submissions ).concat( 1 ) );
		const maxChannel = Math.max.apply( null, channelRows.map( ( row ) => row.sent + row.failed ).concat( 1 ) );
		const draw = () => {
			const box = el.getBoundingClientRect();
			mount( svg, null );
			if ( ! box.width || getComputedStyle( svg ).display === 'none' ) {
				return;
			}
			svg.setAttribute( 'viewBox', '0 0 ' + box.width + ' ' + box.height );
			const c = hub.getBoundingClientRect();
			const cx = c.left + c.width / 2 - box.left;
			const cy = c.top + c.height / 2 - box.top;
			const link = ( node, weight, kind ) => {
				const r = node.getBoundingClientRect();
				const left = r.left + r.width / 2 - box.left < cx;
				const x = ( left ? r.right : r.left ) - box.left;
				const y = r.top + r.height / 2 - box.top;
				const edge = cx + ( left ? -1 : 1 ) * ( c.width / 2 );
				const bend = ( edge - x ) * 0.55;
				const d = 'M' + x + ' ' + y + 'C' + ( x + bend ) + ' ' + y + ' ' + ( edge - bend ) + ' ' + cy + ' ' + edge + ' ' + cy;
				const width = 1.5 + weight * 4;
				svg.appendChild( h( 'path', { d: d, class: 'fnfe-wire__line is-' + kind, 'stroke-width': width } ) );
				if ( weight > 0 ) {
					svg.appendChild( h( 'path', { d: d, class: 'fnfe-wire__pulse is-' + kind + ( left ? '' : ' is-out' ), 'stroke-width': Math.max( 1.5, width * 0.5 ) } ) );
				}
			};
			formNodes.forEach( ( node, i ) => link( node, formRows[ i ] ? formRows[ i ].submissions / maxForm : 0, formRows[ i ] && formRows[ i ].submissions ? 'live' : 'idle' ) );
			channelNodes.forEach( ( node, i ) => {
				const total = channelRows[ i ].sent + channelRows[ i ].failed;
				link( node, total / maxChannel, ! total ? 'idle' : channelRows[ i ].failed > channelRows[ i ].sent ? 'bad' : 'live' );
			} );
		};
		el.draw = draw;
		return el;
	}

	/* ------------------------------------------------------------ Screen */

	function render( container, params, shell ) {
		const ranges = [ { value: 'today', label: __( 'Today', 'forms-notifier-for-elementor' ) }, { value: '7d', label: __( '7 days', 'forms-notifier-for-elementor' ) }, { value: '30d', label: __( '30 days', 'forms-notifier-for-elementor' ) } ];
		let range = ranges.some( ( item ) => item.value === shell.query.range ) ? shell.query.range : '7d';
		let observer = null;
		const start = h( 'div', null );
		const board = h( 'section', { class: 'fnfe-board', 'aria-label': __( 'Numbers', 'forms-notifier-for-elementor' ) } );
		const attention = h( 'div', null );
		const recent = h( 'div', null );
		const health = h( 'div', { class: 'fnfe-health' } );
		const extra = { sent: 0, forms: 0 };

		shell.header( {
			title: __( 'Dashboard', 'forms-notifier-for-elementor' ),
			sub: boot.site && boot.site.name ? h( 'bdi', null, boot.site.name ) : null,
			actions: ui.Segmented( { options: ranges, value: range, label: __( 'Period', 'forms-notifier-for-elementor' ), onChange: ( value ) => shell.setQuery( { range: value === '7d' ? '' : value } ) } ),
		} );
		mount( container, frag( start, board, health, h( 'div', { class: 'fnfe-cols' }, recent, attention ) ) );
		mount( board, h( 'div', { class: 'fnfe-board__load' } ) );
		mount( recent, ui.Skeleton( 'rows', 5 ) );

		function drawBoard( data, long ) {
			const messages = data.messages || {};
			const before = previous( range, long );
			const series = range === 'today' ? [] : data.series || [];
			const pending = ( data.queue && data.queue.pending ) || messages.queued || 0;
			const map = wire( data, { pending: pending } );
			mount( board, frag(
				h( 'div', { class: 'fnfe-board__stats' },
					stat( { label: __( 'Submissions', 'forms-notifier-for-elementor' ), value: data.submissions || 0, before: before.submissions, series: series, key: 'submissions', href: '#/submissions' } ),
					stat( { label: __( 'Delivered', 'forms-notifier-for-elementor' ), value: messages.sent || 0, before: before.sent, series: series, key: 'sent', tone: 'ok', href: '#/log?status=sent' } ),
					stat( { label: __( 'Failed', 'forms-notifier-for-elementor' ), value: messages.failed || 0, before: before.failed, series: series, key: 'failed', tone: messages.failed ? 'bad' : '', href: '#/log?status=failed' } ),
					stat( { label: __( 'In queue', 'forms-notifier-for-elementor' ), value: pending, href: '#/log?status=queued', note: queueNote( data.queue, pending ) } )
				),
				map
			) );
			if ( map.draw ) {
				map.draw();
				if ( observer ) {
					observer.disconnect();
				}
				observer = 'ResizeObserver' in window ? new ResizeObserver( () => map.draw() ) : null;
				if ( observer ) {
					observer.observe( map );
				}
			}
			const items = data.health || [];
			const bad = items.filter( ( item ) => item.status !== 'good' );
			mount( health, items.length ? bad.map( ( item ) => healthPill( item ) ).concat( items.length > bad.length ? healthSummary( items.filter( ( item ) => item.status === 'good' ) ) : [] ) : null );
			extra.sent = Math.max( extra.sent, messages.sent || 0 );
		}

		function queueNote( queue, pending ) {
			if ( ! pending ) {
				return __( 'Nothing is waiting', 'forms-notifier-for-elementor' );
			}
			return queue && queue.next_run && new Date( queue.next_run ) > new Date()
				? sprintf( /* translators: %s: relative time */ __( 'Next run %s', 'forms-notifier-for-elementor' ), fmt.relative( queue.next_run ) )
				: __( 'Waiting for the next run', 'forms-notifier-for-elementor' );
		}

		/** Checks without findings share one pill. */
		function healthSummary( good ) {
			const button = h( 'button', { type: 'button', class: 'fnfe-health__pill', 'aria-haspopup': 'dialog', onClick() {
				ui.popover( button, h( 'div', { class: 'fnfe-health__pop' }, h( 'ul', null, good.map( ( item ) => h( 'li', null, icon( 'check', 14 ), item.label ) ) ) ), { role: 'dialog' } );
			} }, h( 'span', { class: 'fnfe-dot fnfe-dot--ok', 'aria-hidden': 'true' } ), /* translators: %s: number of checks */ sprintf( _n( '%s check is fine', '%s checks are fine', good.length, 'forms-notifier-for-elementor' ), fmt.number( good.length ) ) );
			return button;
		}

		function healthPill( item ) {
			const tone = { good: 'ok', warning: 'warn', critical: 'danger' }[ item.status ] || 'mute';
			const button = h( 'button', { type: 'button', class: 'fnfe-health__pill', 'aria-haspopup': 'dialog', onClick() {
				ui.popover( button, h( 'div', { class: 'fnfe-health__pop' }, h( 'strong', null, item.label ), h( 'p', null, item.message || __( 'Everything is in order.', 'forms-notifier-for-elementor' ) ), item.route ? h( 'a', { href: '#/' + String( item.route ).replace( /^#?\/*/, '' ) }, __( 'Open', 'forms-notifier-for-elementor' ) ) : null ), { role: 'dialog' } );
			} }, h( 'span', { class: 'fnfe-dot fnfe-dot--' + tone, 'aria-hidden': 'true' } ), item.label, item.status === 'good' ? null : h( 'span', { class: 'fnfe-sr' }, item.message ) );
			return button;
		}

		function loadBoard() {
			ui.cached( 'stats:' + range, () => Promise.all( [ api.get( 'stats', { range: range } ), range === '30d' ? null : api.get( 'stats', { range: '30d' } ).catch( () => null ) ] ), ( pair ) => drawBoard( pair[ 0 ], pair[ 1 ] ), ( error ) => mount( board, h( 'div', { class: 'fnfe-board__error' }, ui.ErrorState( error, loadBoard ) ) ) );
		}

		function loadRecent() {
			if ( ! shell.can( 'submissions' ) ) {
				mount( recent, null );
				return;
			}
			ui.cached( 'recent', () => api.get( 'submissions', { per_page: 5 } ), ( data ) => mount( recent, ui.Card(
				{ title: __( 'Recent submissions', 'forms-notifier-for-elementor' ), flush: true, actions: h( 'a', { class: 'fnfe-more', href: '#/submissions' }, __( 'All', 'forms-notifier-for-elementor' ), icon( 'chevron-end', 16 ) ) },
				data.items.length ? h( 'ul', { class: 'fnfe-feed' }, data.items.map( ( item ) => h( 'li', null, h( 'a', { href: '#/submissions/' + item.id }, ui.Dot( item.delivery_status ), h( 'span', { class: 'fnfe-feed__text' }, h( 'strong', { class: 'fnfe-latin', dir: 'auto' }, item.preview || item.form_name ), h( 'small', null, h( 'bdi', null, item.form_name ) ) ), ui.Time( item.created ) ) ) ) ) : ui.Empty( { icon: 'inbox', small: true, text: __( 'Submissions appear here as soon as someone sends a form.', 'forms-notifier-for-elementor' ) } )
			) ), ( error ) => mount( recent, ui.Card( { title: __( 'Recent submissions', 'forms-notifier-for-elementor' ) }, ui.ErrorState( error, loadRecent ) ) ) );
		}

		function drawAttention( failed ) {
			const items = [];
			( boot.channels || [] ).filter( ( item ) => item.enabled && ! item.ready ).forEach( ( item ) => items.push( h( 'li', null, h( 'a', { href: '#/channels/' + item.id }, ui.Tile( item.id, 's' ), h( 'span', { class: 'fnfe-feed__text' }, h( 'strong', null, item.label ), h( 'small', null, item.problems[ 0 ] || __( 'Needs setup', 'forms-notifier-for-elementor' ) ) ), icon( 'chevron-end', 16 ) ) ) ) );
			( failed || [] ).forEach( ( item ) => items.push( h( 'li', null, h( 'a', { href: '#/log/' + item.id }, ui.Tile( item.channel, 's' ), h( 'span', { class: 'fnfe-feed__text' }, h( 'strong', { dir: 'auto' }, item.error || ui.statusLabel( 'failed' ) ), h( 'small', null, ui.raw( item.recipient_label || item.recipient ) ) ), ui.Time( item.created ) ) ) ) );
			mount( attention, ui.Card( { title: __( 'Needs attention', 'forms-notifier-for-elementor' ), flush: true, actions: items.length && shell.can( 'logs' ) ? h( 'a', { class: 'fnfe-more', href: '#/log?status=failed' }, __( 'Log', 'forms-notifier-for-elementor' ), icon( 'chevron-end', 16 ) ) : null }, items.length ? h( 'ul', { class: 'fnfe-feed' }, items.slice( 0, 6 ) ) : ui.Empty( { icon: 'check-circle', small: true, text: __( 'Nothing to fix. Every channel that is on can send.', 'forms-notifier-for-elementor' ) } ) ) );
		}

		function loadAttention() {
			drawAttention( [] );
			if ( shell.can( 'logs' ) ) {
				ui.cached( 'failed', () => api.get( 'messages', { status: 'failed', per_page: 5 } ), ( data ) => drawAttention( data.items ) );
			}
		}

		function loadStart() {
			Promise.all( [
				shell.can( 'forms' ) ? api.get( 'forms', { per_page: 1 } ).catch( () => ( { total: 0 } ) ) : { total: 1 },
				shell.can( 'logs' ) ? api.get( 'messages', { status: 'sent', per_page: 1 } ).catch( () => ( { total: 0 } ) ) : { total: 1 },
			] ).then( ( pair ) => {
				extra.forms = pair[ 0 ].total;
				extra.sent = pair[ 1 ].total;
				mount( start, shell.can( 'manage' ) ? checklist( extra ) : null );
				if ( ! extra.forms && shell.can( 'forms' ) && ui.dismissed().indexOf( 'checklist' ) > -1 ) {
					mount( start, ui.Callout( 'info', __( 'No Elementor forms found yet.', 'forms-notifier-for-elementor' ), { actions: [ ui.Button( { label: __( 'Scan for forms', 'forms-notifier-for-elementor' ), size: 's', onClick: () => router.go( 'forms' ) } ), ui.Button( { label: __( 'Quick start', 'forms-notifier-for-elementor' ), size: 's', variant: 'ghost', onClick: () => shell.help( 'quick-start' ) } ) ] } ) );
				}
			} );
		}

		const off = bus.on( 'channels', loadAttention );
		loadStart();
		loadBoard();
		loadRecent();
		loadAttention();

		const cleanup = () => {
			off();
			if ( observer ) {
				observer.disconnect();
			}
		};
		cleanup.update = ( query ) => {
			range = ranges.some( ( item ) => item.value === query.range ) ? query.range : '7d';
			loadBoard();
		};
		return cleanup;
	}

	window.fnfe.screens.register( 'dashboard', { title: __( 'Dashboard', 'forms-notifier-for-elementor' ), cap: 'any', render: render } );
}() );
