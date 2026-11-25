/**
 * Routing rules editor.
 */
( function () {
	'use strict';

	const { __, _x, _n, sprintf } = wp.i18n;
	const { h, mount, frag, icon, boot, ui, uid, fmt, fields } = window.fnfe;

	const OPS = () => ( {
		is: __( 'is', 'forms-notifier-for-elementor' ),
		is_not: __( 'is not', 'forms-notifier-for-elementor' ),
		contains: __( 'contains', 'forms-notifier-for-elementor' ),
		not_contains: __( 'does not contain', 'forms-notifier-for-elementor' ),
		starts: __( 'starts with', 'forms-notifier-for-elementor' ),
		ends: __( 'ends with', 'forms-notifier-for-elementor' ),
		empty: __( 'is empty', 'forms-notifier-for-elementor' ),
		not_empty: __( 'is filled', 'forms-notifier-for-elementor' ),
		gt: __( 'is more than', 'forms-notifier-for-elementor' ),
		lt: __( 'is less than', 'forms-notifier-for-elementor' ),
		in: __( 'is one of', 'forms-notifier-for-elementor' ),
		not_in: __( 'is none of', 'forms-notifier-for-elementor' ),
		matches: __( 'matches the pattern', 'forms-notifier-for-elementor' ),
	} );

	const EFFECTS = () => [
		{ value: 'add', label: __( 'Also notify', 'forms-notifier-for-elementor' ), desc: __( 'Adds receivers to the ones that already get a message.', 'forms-notifier-for-elementor' ) },
		{ value: 'replace', label: __( 'Notify instead', 'forms-notifier-for-elementor' ), desc: __( 'Replaces the receivers of the chosen channels.', 'forms-notifier-for-elementor' ) },
		{ value: 'only', label: __( 'Use only these channels', 'forms-notifier-for-elementor' ), desc: __( 'Other channels stay silent.', 'forms-notifier-for-elementor' ) },
		{ value: 'skip', label: __( 'Send nothing', 'forms-notifier-for-elementor' ), desc: __( 'The submission is saved without notifications.', 'forms-notifier-for-elementor' ) },
	];

	const META = () => [
		{ value: 'meta:lang', label: __( 'Language', 'forms-notifier-for-elementor' ), group: __( 'About the submission', 'forms-notifier-for-elementor' ) },
		{ value: 'meta:page_url', label: __( 'Page address', 'forms-notifier-for-elementor' ), group: __( 'About the submission', 'forms-notifier-for-elementor' ) },
		{ value: 'meta:user_id', label: __( 'WordPress user id', 'forms-notifier-for-elementor' ), group: __( 'About the submission', 'forms-notifier-for-elementor' ) },
		{ value: 'meta:utm.source', label: __( 'Campaign source (utm_source)', 'forms-notifier-for-elementor' ), group: __( 'About the submission', 'forms-notifier-for-elementor' ) },
		{ value: 'tag:files_count', label: __( 'Number of files', 'forms-notifier-for-elementor' ), group: __( 'About the submission', 'forms-notifier-for-elementor' ) },
	];

	/** Option of a form field; Elementor writes "Label|value". */
	function choice( option ) {
		if ( option && typeof option === 'object' ) {
			return { value: String( option.value ), label: option.label || String( option.value ) };
		}
		const parts = String( option ).split( '|' );
		return { value: ( parts[ 1 ] === undefined ? parts[ 0 ] : parts[ 1 ] ).trim(), label: parts[ 0 ].trim() };
	}

	fields.register( 'rules', ( field, value, onChange, ctx ) => {
		let rules = fields.list( fields.clone( value ) );
		const list = h( 'div', { class: 'fnfe-rules' } );
		const open = new Set();
		const commit = () => onChange( fields.clone( rules ) );
		const formFields = ctx.formFields || [];
		const subjects = () => formFields.map( ( item ) => ( { value: item.id, label: item.label || item.id, group: __( 'Form fields', 'forms-notifier-for-elementor' ) } ) ).concat( META() );
		const subjectLabel = ( id ) => ( subjects().find( ( item ) => item.value === id ) || { label: id || '…' } ).label;
		const channels = () => ( boot.channels || [] ).map( ( item ) => ( { value: item.id, label: item.label } ) );
		const and = ( items, any ) => items.join( any ? ' ' + _x( 'or', 'between conditions', 'forms-notifier-for-elementor' ) + ' ' : ' ' + _x( 'and', 'between conditions', 'forms-notifier-for-elementor' ) + ' ' );

		/** One plain sentence about a rule. */
		function sentence( rule ) {
			const when = rule.conditions.length
				/* translators: %s: conditions such as: Plan is "vip" */
				? sprintf( __( 'When %s', 'forms-notifier-for-elementor' ), and( rule.conditions.map( ( c ) => subjectLabel( c.field ) + ' ' + ( OPS()[ c.op ] || c.op ) + ( c.op === 'empty' || c.op === 'not_empty' ? '' : /* translators: %s: value of a condition */ ' ' + sprintf( __( '"%s"', 'forms-notifier-for-elementor' ), c.value || '?' ) ) ), rule.match === 'any' ) )
				: __( 'Always', 'forms-notifier-for-elementor' );
			const names = rule.channels.map( ui.channelLabel ).join( __( ', ', 'forms-notifier-for-elementor' ) );
			const count = Object.keys( rule.receivers || {} ).reduce( ( sum, id ) => sum + ( rule.channels.indexOf( id ) > -1 ? rule.receivers[ id ].length : 0 ), 0 );
			switch ( rule.effect ) {
				case 'skip':
					/* translators: %s: the condition part of the sentence */
					return sprintf( __( '%s: send nothing.', 'forms-notifier-for-elementor' ), when );
				case 'only':
					/* translators: %1$s: the condition part of the sentence, %2$s: channel names */
					return sprintf( __( '%1$s: use only %2$s.', 'forms-notifier-for-elementor' ), when, names || '?' );
				case 'replace':
					/* translators: %1$s: the condition part of the sentence, %2$s: channel names, %3$s: number of receivers */
					return sprintf( _n( '%1$s: notify %3$s receiver on %2$s instead.', '%1$s: notify %3$s receivers on %2$s instead.', count, 'forms-notifier-for-elementor' ), when, names || '?', fmt.number( count ) );
			}
			/* translators: %1$s: the condition part of the sentence, %2$s: channel names, %3$s: number of receivers */
			return sprintf( _n( '%1$s: also notify %3$s receiver on %2$s.', '%1$s: also notify %3$s receivers on %2$s.', count, 'forms-notifier-for-elementor' ), when, names || '?', fmt.number( count ) );
		}

		function conditionRow( rule, condition, i, redraw ) {
			const options = subjects();
			if ( condition.field && ! options.some( ( item ) => item.value === condition.field ) ) {
				options.unshift( { value: condition.field, label: condition.field } );
			}
			if ( ! condition.field && options[ 0 ] ) {
				condition.field = options[ 0 ].value;
			}
			const free = [ 'empty', 'not_empty' ].indexOf( condition.op ) === -1;
			const spec = formFields.find( ( item ) => item.id === condition.field );
			const choices = spec && fields.list( spec.options ).length && [ 'is', 'is_not' ].indexOf( condition.op ) > -1 ? fields.list( spec.options ).map( choice ) : null;
			if ( choices && ! choices.some( ( option ) => option.value === condition.value ) ) {
				choices.unshift( { value: condition.value, label: condition.value || __( 'Choose…', 'forms-notifier-for-elementor' ) } );
			}
			return h(
				'div',
				{ class: 'fnfe-cond' },
				h( 'span', { class: 'fnfe-cond__join' }, i === 0 ? __( 'When', 'forms-notifier-for-elementor' ) : ( rule.match === 'any' ? _x( 'or', 'between conditions', 'forms-notifier-for-elementor' ) : _x( 'and', 'between conditions', 'forms-notifier-for-elementor' ) ) ),
				ui.Select( { options: options, value: condition.field, label: __( 'Field', 'forms-notifier-for-elementor' ), onChange( next ) {
					condition.field = next;
					redraw();
				} } ),
				ui.Select( { options: Object.keys( OPS() ).map( ( op ) => ( { value: op, label: OPS()[ op ] } ) ), value: condition.op, label: __( 'Comparison', 'forms-notifier-for-elementor' ), onChange( next ) {
					condition.op = next;
					redraw();
				} } ),
				free ? ( choices ? ui.Select( { options: choices, value: condition.value, label: __( 'Value', 'forms-notifier-for-elementor' ), onChange( next ) {
					condition.value = next;
					redraw( true );
				} } ) : h( 'input', { type: 'text', class: 'fnfe-input', dir: 'auto', value: condition.value, placeholder: condition.op === 'in' || condition.op === 'not_in' ? 'a, b, c' : '', 'aria-label': __( 'Value', 'forms-notifier-for-elementor' ), onInput( event ) {
					condition.value = event.target.value;
					redraw( true );
				} } ) ) : h( 'span', null ),
				ui.IconButton( { icon: 'x', size: 's', label: __( 'Remove condition', 'forms-notifier-for-elementor' ), onClick() {
					rule.conditions.splice( i, 1 );
					redraw();
				} } )
			);
		}

		function body( rule, summary ) {
			const el = h( 'div', null );
			const redraw = ( light ) => {
				summary.textContent = sentence( rule );
				commit();
				if ( light !== true ) {
					build();
				}
			};
			const build = () => {
				const needs = rule.effect !== 'skip';
				const picks = rule.effect === 'add' || rule.effect === 'replace';
				mount( el, frag(
					h( 'div', { class: 'fnfe-grid' }, fields.render( { key: 'name', type: 'text', label: __( 'Name', 'forms-notifier-for-elementor' ), width: 'half' }, rule.name, ( text ) => {
						rule.name = text;
						redraw( true );
					}, {} ), rule.conditions.length > 1 ? fields.render( { key: 'match', type: 'select', label: __( 'Conditions', 'forms-notifier-for-elementor' ), width: 'half', options: [ { value: 'all', label: __( 'All must hold', 'forms-notifier-for-elementor' ) }, { value: 'any', label: __( 'One is enough', 'forms-notifier-for-elementor' ) } ] }, rule.match, ( next ) => {
						rule.match = next;
						redraw();
					}, {} ) : null ),
					h( 'div', { class: 'fnfe-conds' }, rule.conditions.map( ( condition, i ) => conditionRow( rule, condition, i, redraw ) ), ui.Button( { label: __( 'Add a condition', 'forms-notifier-for-elementor' ), icon: 'plus', size: 's', variant: 'ghost', onClick() {
						rule.conditions.push( { field: '', op: 'is', value: '' } );
						redraw();
					} } ) ),
					h( 'div', { class: 'fnfe-grid' },
						fields.render( { key: 'effect', type: 'radio', label: __( 'Then', 'forms-notifier-for-elementor' ), options: EFFECTS() }, rule.effect, ( next ) => {
							rule.effect = next;
							redraw();
						}, {} ),
						needs ? fields.render( { key: 'channels', type: 'multiselect', label: __( 'Channels', 'forms-notifier-for-elementor' ), options: channels() }, rule.channels, ( next ) => {
							rule.channels = next;
							redraw();
						}, {} ) : null,
						picks ? rule.channels.filter( ( id ) => ( ui.channelMeta( id ) || { capabilities: {} } ).capabilities.receivers !== 'none' ).map( ( id ) => fields.render( { key: 'receivers.' + id, type: 'receivers', channel: id, label: ui.channelLabel( id ) }, rule.receivers[ id ] || [], ( next ) => {
							rule.receivers = Object.assign( {}, rule.receivers, { [ id ]: next } );
							redraw( true );
						}, { formId: ctx.formId, formFields: formFields, channel: id, secrets: ctx.secrets, overlay: ctx.overlay } ) ) : null,
						fields.render( { key: 'stop', type: 'toggle', label: __( 'Stop here', 'forms-notifier-for-elementor' ), desc: __( 'Skip the rules below when this one matches.', 'forms-notifier-for-elementor' ) }, rule.stop, ( next ) => {
							rule.stop = next;
							redraw( true );
						}, {} )
					)
				) );
			};
			build();
			return el;
		}

		function draw() {
			mount( list, rules.map( ( rule, i ) => {
				const summary = h( 'span', null, sentence( rule ) );
				const fold = ui.Fold( { class: 'fnfe-rule__fold', title: rule.name || /* translators: %s: position of the rule */ sprintf( __( 'Rule %s', 'forms-notifier-for-elementor' ), fmt.number( i + 1 ) ), desc: summary, open: open.has( rule.id ), onToggle( on, el ) {
					if ( on ) {
						open.add( rule.id );
						if ( ! el.firstChild ) {
							mount( el, body( rule, summary ) );
						}
					} else {
						open.delete( rule.id );
					}
				} } );
				if ( open.has( rule.id ) ) {
					mount( fold.body, body( rule, summary ) );
				}
				return h( 'div', { class: [ 'fnfe-rule', rule.enabled === false ? 'is-off' : '' ] },
					h( 'div', { class: 'fnfe-rule__side' }, h( 'span', { class: 'fnfe-rule__grip', title: __( 'Drag to reorder', 'forms-notifier-for-elementor' ), 'aria-hidden': 'true' }, icon( 'drag', 18 ) ), ui.Switch( { checked: rule.enabled !== false, /* translators: %s: name of the rule */ ariaLabel: sprintf( __( 'Use rule %s', 'forms-notifier-for-elementor' ), rule.name || fmt.number( i + 1 ) ), onChange( on ) {
						rule.enabled = on;
						commit();
						draw();
					} } ) ),
					fold,
					h( 'div', { class: 'fnfe-rule__tools' }, ui.MoveButtons( i, rules.length, move ), ui.IconButton( { icon: 'trash', size: 's', danger: true, label: __( 'Remove rule', 'forms-notifier-for-elementor' ), onClick: () => ui.confirm( { title: __( 'Remove this rule?', 'forms-notifier-for-elementor' ), text: sentence( rule ), confirm: __( 'Remove', 'forms-notifier-for-elementor' ), danger: true } ).then( ( yes ) => {
						if ( yes ) {
							rules = rules.filter( ( other ) => other.id !== rule.id );
							commit();
							draw();
						}
					} ) } ) )
				);
			} ) );
			if ( ! rules.length ) {
				mount( list, ui.Empty( { icon: 'branch', small: true, title: __( 'No rules', 'forms-notifier-for-elementor' ), text: __( 'Rules send a submission to other people depending on what was entered. Without rules everyone in the receiver lists gets it.', 'forms-notifier-for-elementor' ) } ) );
			}
			ui.sortable( list, '.fnfe-rule', '.fnfe-rule__grip', move );
		}

		function move( from, to ) {
			rules = ui.moved( rules, from, to );
			commit();
			draw();
		}

		rules.forEach( ( rule ) => {
			rule.id = rule.id || uid( 'q_' );
			rule.conditions = fields.list( rule.conditions );
			rule.channels = fields.list( rule.channels );
			rule.receivers = rule.receivers && ! Array.isArray( rule.receivers ) ? rule.receivers : {};
		} );
		draw();
		return h( 'div', null, list, ui.Button( { label: __( 'Add a rule', 'forms-notifier-for-elementor' ), icon: 'plus', size: 's', disabled: ctx.disabled, onClick() {
			const rule = { id: uid( 'q_' ), name: '', enabled: true, match: 'all', conditions: [ { field: '', op: 'is', value: '' } ], effect: 'add', channels: [], receivers: {}, stop: false };
			rules.push( rule );
			open.add( rule.id );
			commit();
			draw();
			const input = list.lastElementChild.querySelector( '.fnfe-fold__body input' );
			if ( input ) {
				input.focus();
			}
		} } ) );
	} );
}() );
