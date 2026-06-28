( function ( $, window ) {
	'use strict';

	const editors = new Map();
	let activeEditor = null;
	let hintMenu = null;
	let dirty = false;

	function bemHint( editor ) {
		const cursor = editor.getCursor();
		const token = editor.getTokenAt( cursor );
		const beforeCursor = token.string.slice( 0, cursor.ch - token.start );
		const match = beforeCursor.match( /(?:^|\.)([co]-[\w-]*)$/ );

		if ( ! match ) {
			return null;
		}

		const query = match[ 1 ];
		const list = ( window.projectOverrides.classNames || [] )
			.filter( ( name ) => name.indexOf( query ) === 0 )
			.map( ( name ) => ( {
				text: name,
				displayText: '.' + name,
			} ) );

		if ( ! list.length ) {
			return null;
		}

		return {
			list,
			from: window.CodeMirror.Pos( cursor.line, cursor.ch - query.length ),
			to: cursor,
		};
	}

	function enableAutocomplete( editor ) {
		editor.on( 'inputRead', function ( instance, change ) {
			if ( change.text.length !== 1 || ! /[\w-]/.test( change.text[ 0 ] ) ) {
				return;
			}

			const completion = bemHint( instance );
			if ( completion && typeof instance.showHint === 'function' ) {
				instance.showHint( {
					hint: bemHint,
					completeSingle: false,
				} );
			} else if ( completion ) {
				showFallbackHints( instance, completion );
			}
		} );
		editor.on( 'blur', function () {
			window.setTimeout( closeFallbackHints, 150 );
		} );
	}

	function closeFallbackHints() {
		if ( hintMenu ) {
			hintMenu.remove();
			hintMenu = null;
		}
	}

	function showFallbackHints( editor, completion ) {
		closeFallbackHints();
		const coordinates = editor.cursorCoords( completion.from, 'page' );
		hintMenu = document.createElement( 'ul' );
		hintMenu.className = 'project-overrides-hints';
		hintMenu.style.left = coordinates.left + 'px';
		hintMenu.style.top = coordinates.bottom + 'px';

		completion.list.forEach( function ( item ) {
			const option = document.createElement( 'li' );
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.textContent = item.displayText;
			button.addEventListener( 'mousedown', function ( event ) {
				event.preventDefault();
				editor.replaceRange( item.text, completion.from, completion.to );
				closeFallbackHints();
				editor.focus();
			} );
			option.appendChild( button );
			hintMenu.appendChild( option );
		} );

		document.body.appendChild( hintMenu );
	}

	function initializeEditors() {
		if ( ! window.wp || ! wp.codeEditor ) {
			return;
		}

		$( '.project-overrides-editor' ).each( function () {
			const settings = $.extend( true, {}, window.projectOverrides.editorSettings || {} );
			settings.codemirror = settings.codemirror || {};
			settings.codemirror.lineNumbers = true;
			settings.codemirror.indentUnit = 2;
			settings.codemirror.tabSize = 2;
			settings.codemirror.lineWrapping = false;

			if ( this.readOnly ) {
				settings.codemirror.readOnly = true;
			}

			const instance = wp.codeEditor.initialize( this, settings );
			const editor = instance.codemirror;
			editors.set( this.id, editor );
			if ( ! activeEditor && ! this.readOnly ) {
				activeEditor = editor;
			}
			editor.on( 'focus', function () {
				if ( ! editor.getOption( 'readOnly' ) ) {
					activeEditor = editor;
				}
			} );
			editor.on( 'change', function () {
				dirty = true;
			} );
			enableAutocomplete( editor );
		} );
	}

	$( function () {
		initializeEditors();

		$( '.project-overrides-page-select' ).on( 'change', function () {
			const base = $( this ).data( 'base-url' );
			if ( dirty && ! window.confirm( window.projectOverrides.unsaved ) ) {
				this.value = new URLSearchParams( window.location.search ).get( 'post_id' ) || '';
				return;
			}
			dirty = false;
			window.location.href = this.value ? base + '&post_id=' + encodeURIComponent( this.value ) : base;
		} );

		$( '.project-overrides form' ).on( 'submit', function () {
			dirty = false;
		} );

		$( '.project-overrides-delete' ).on( 'click', function ( event ) {
			if ( ! window.confirm( window.projectOverrides.confirmDelete ) ) {
				event.preventDefault();
			}
		} );

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( ! dirty ) {
				return;
			}
			event.preventDefault();
			event.returnValue = '';
		} );

		$( '.project-overrides-token' ).on( 'click', function () {
			if ( ! activeEditor ) {
				return;
			}
			activeEditor.replaceSelection( $( this ).data( 'token' ) );
			activeEditor.focus();
		} );

		$( '.project-overrides-copy' ).on( 'click', async function () {
			const button = this;
			const target = document.getElementById( $( button ).data( 'target' ) );
			const editor = target ? editors.get( target.id ) : null;
			const value = editor ? editor.getValue() : ( target ? target.value : '' );

			try {
				await navigator.clipboard.writeText( value );
			} catch ( error ) {
				if ( target ) {
					target.focus();
					target.select();
					document.execCommand( 'copy' );
				}
			}

			button.textContent = window.projectOverrides.copied;
			window.setTimeout( function () {
				button.textContent = window.projectOverrides.copy;
			}, 1600 );
		} );
	} );
}( jQuery, window ) );
