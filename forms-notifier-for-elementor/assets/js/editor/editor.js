/**
 * Elementor editor helpers: copy a template tag on click, open the settings of the edited form.
 */
( function () {
	'use strict';

	var __ = window.wp.i18n.__;
	var toastTimer = 0;

	function toast( message ) {
		var node = document.getElementById( 'fnfe-editor-toast' );
		if ( ! node ) {
			node = document.createElement( 'div' );
			node.id = 'fnfe-editor-toast';
			node.setAttribute( 'role', 'status' );
			document.body.appendChild( node );
		}
		node.textContent = message;
		node.classList.add( 'is-visible' );
		window.clearTimeout( toastTimer );
		toastTimer = window.setTimeout( function () {
			node.classList.remove( 'is-visible' );
		}, 1800 );
	}

	/** Clipboard for pages without a secure context. */
	function copyBySelection( text ) {
		var area = document.createElement( 'textarea' );
		var done = false;
		area.value = text;
		area.className = 'fnfe-editor-copy';
		document.body.appendChild( area );
		area.select();
		try {
			done = document.execCommand( 'copy' );
		} catch ( error ) {
			done = false;
		}
		area.remove();
		return done;
	}

	function copy( text ) {
		var copied = function () {
			toast( __( 'Copied.', 'forms-notifier-for-elementor' ) );
		};
		var fallback = function () {
			if ( copyBySelection( text ) ) {
				copied();
			} else {
				toast( __( 'Copying is not possible in this browser.', 'forms-notifier-for-elementor' ) );
			}
		};
		if ( window.navigator.clipboard && window.isSecureContext ) {
			window.navigator.clipboard.writeText( text ).then( copied, fallback );
		} else {
			fallback();
		}
	}

	/** "{document id}:{element id}" of the form whose panel is open, '' when it cannot be told. */
	function formKey() {
		var elementor = window.elementor;
		var postId;
		var elementId;
		try {
			postId = elementor.documents.getCurrent().id;
			elementId = elementor.getPanelView().getCurrentPageView().getOption( 'editedElementView' ).model.get( 'id' );
		} catch ( error ) {
			return '';
		}
		return postId && elementId ? postId + ':' + elementId : '';
	}

	function chipOf( event ) {
		return event.target && event.target.closest ? event.target.closest( '[class*="elementor-control-fnfe_"] .fnfe-chip' ) : null;
	}

	document.addEventListener( 'click', function ( event ) {
		var chip = chipOf( event );
		var link = event.target && event.target.closest ? event.target.closest( 'a.fnfe-open-form' ) : null;
		var key;
		if ( chip ) {
			event.preventDefault();
			copy( chip.getAttribute( 'data-fnfe-copy' ) || chip.textContent );
		} else if ( link ) {
			key = formKey();
			if ( key ) {
				// The browser follows the link; a form that was never saved ends on the list of forms.
				link.setAttribute( 'href', link.getAttribute( 'data-fnfe-base' ) + '/' + key );
			}
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		var chip = event.key === 'Enter' || event.key === ' ' ? chipOf( event ) : null;
		if ( chip ) {
			event.preventDefault();
			copy( chip.getAttribute( 'data-fnfe-copy' ) || chip.textContent );
		}
	} );
}() );
