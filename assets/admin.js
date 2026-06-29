( function ( $, window ) {
	'use strict';

	const editors = new Map();
	let activeEditor = null;
	let hintMenu = null;
	let dirty = false;
	let activeScope = null;
	let selectedBlockClass = '';
	let previewTimer = null;

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
			settings.codemirror.theme = 'project-overrides-dark';

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
				editor.save();
				editor.getTextArea().dispatchEvent( new Event( 'input', { bubbles: true } ) );
				dirty = true;
				schedulePreviewUpdate();
			} );
			enableAutocomplete( editor );
		} );
	}

	function syncEditors() {
		editors.forEach( function ( editor ) {
			editor.save();
			editor.getTextArea().dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
	}

	function editorData() {
		if ( window.wp?.data ) {
			return window.wp.data;
		}
		if ( window.parent !== window && window.parent.wp?.data ) {
			return window.parent.wp.data;
		}
		return null;
	}

	function injectPreviewCss( css ) {
		const apply = function ( doc ) {
			if ( ! doc?.head ) {
				return;
			}
			let style = doc.getElementById( 'project-overrides-editor-preview-css' );
			if ( ! style ) {
				style = doc.createElement( 'style' );
				style.id = 'project-overrides-editor-preview-css';
				doc.head.appendChild( style );
			}
			style.textContent = css || '';
		};

		apply( document );
		document.querySelectorAll( 'iframe' ).forEach( function ( frame ) {
			try {
				apply( frame.contentDocument );
			} catch ( error ) {
				// Ignore unrelated cross-origin frames.
			}
		} );
	}

	function buildPreviewCss() {
		const scopeSelect = document.getElementById( 'project-overrides-scope' );
		const editor = editors.get( 'project-overrides-meta-css' );
		const status = document.querySelector( '[name="project_overrides_status"]' )?.value || 'temporary';
		const scope = scopeSelect?.value || '';
		const parts = [ window.projectOverrides.previewCss || '' ];

		if ( scope && editor && 'migrated' !== status ) {
			parts.push( '/* Project Overrides preview: unsaved ' + scope + ' */\n' + editor.getValue() );
		}

		return parts.filter( Boolean ).join( '\n\n' );
	}

	function schedulePreviewUpdate() {
		window.clearTimeout( previewTimer );
		previewTimer = window.setTimeout( function () {
			const css = buildPreviewCss();
			injectPreviewCss( css );
			if ( window.parent !== window ) {
				window.parent.postMessage( { type: 'project-overrides-preview-css', css }, window.location.origin );
			}
		}, 120 );
	}

	function observePreviewTargets() {
		new MutationObserver( function () {
			injectPreviewCss( buildPreviewCss() );
		} ).observe( document.body, { childList: true, subtree: true } );
	}

	function focusCssEditor() {
		const localEditor = editors.get( 'project-overrides-meta-css' );
		if ( localEditor ) {
			localEditor.focus();
			localEditor.scrollIntoView();
			return true;
		}

		for ( const frame of document.querySelectorAll( 'iframe' ) ) {
			try {
				if ( frame.contentDocument?.querySelector( '#project-overrides-meta-css' ) ) {
					frame.scrollIntoView( { behavior: 'smooth', block: 'center' } );
					frame.contentWindow.dispatchEvent( new Event( 'project-overrides-focus' ) );
					return true;
				}
			} catch ( error ) {
				// Ignore unrelated cross-origin frames.
			}
		}
		return false;
	}

	function addBlockEditorTools() {
		if ( ! document.body.classList.contains( 'block-editor-page' ) || ! window.wp?.data ) {
			return;
		}

		const insertButton = function () {
			const publishButton = document.querySelector( '.editor-post-publish-button, .editor-post-publish-button__button, .editor-post-save-draft' );
			if ( ! publishButton || document.querySelector( '.project-overrides-open-css' ) ) {
				return;
			}

			const button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'components-button is-secondary project-overrides-open-css';
			button.textContent = window.projectOverrides.openCss;
			button.addEventListener( 'click', focusCssEditor );

			const status = document.createElement( 'span' );
			status.className = 'project-overrides-save-status';
			status.setAttribute( 'aria-live', 'polite' );
			publishButton.parentNode.insertBefore( button, publishButton );
			publishButton.parentNode.insertBefore( status, publishButton );
		};

		insertButton();
		new MutationObserver( insertButton ).observe( document.body, { childList: true, subtree: true } );

		let wasSaving = false;
		wp.data.subscribe( function () {
			const selector = wp.data.select( 'core/editor' );
			const saving = selector.isSavingPost() && ! selector.isAutosavingPost();
			const status = document.querySelector( '.project-overrides-save-status' );
			if ( ! status ) {
				return;
			}
			if ( saving ) {
				status.textContent = window.projectOverrides.saving;
			} else if ( wasSaving ) {
				const failed = typeof selector.didPostSaveRequestFail === 'function' && selector.didPostSaveRequestFail();
				status.textContent = failed ? window.projectOverrides.saveError : window.projectOverrides.saved;
				status.classList.toggle( 'is-error', failed );
				dirty = failed;
				document.querySelectorAll( 'iframe' ).forEach( function ( frame ) {
					frame.contentWindow?.postMessage( { type: 'project-overrides-save-result', failed }, window.location.origin );
				} );
			}
			wasSaving = saving;
		} );
	}

	function enableKeyboardSave() {
		document.addEventListener( 'keydown', function ( event ) {
			if ( ! ( event.metaKey || event.ctrlKey ) || 's' !== event.key.toLowerCase() ) {
				return;
			}
			const data = editorData();
			if ( ! data || ( ! document.querySelector( '.project-overrides-editor' ) && ! document.body.classList.contains( 'block-editor-page' ) ) ) {
				return;
			}
			event.preventDefault();
			syncEditors();
			data.dispatch( 'core/editor' ).savePost();
		} );
	}

	function enableLiveClassDiscovery() {
		if ( ! document.body.classList.contains( 'block-editor-page' ) || ! window.wp?.data ) {
			return;
		}
		let previous = '';
		let discovered = [];
		const sendClasses = function () {
			document.querySelectorAll( 'iframe' ).forEach( function ( frame ) {
				frame.contentWindow?.postMessage( { type: 'project-overrides-classes', classes: discovered, selectedClass: selectedBlockClass }, window.location.origin );
			} );
		};
		window.addEventListener( 'message', function ( event ) {
			if ( event.origin === window.location.origin && 'project-overrides-ready' === event.data?.type ) {
				sendClasses();
			}
		} );
		wp.data.subscribe( function () {
			const blocks = wp.data.select( 'core/block-editor' )?.getBlocks() || [];
			const selected = wp.data.select( 'core/block-editor' )?.getSelectedBlock();
			const classes = [];
			const walk = function ( items ) {
				items.forEach( function ( block ) {
					String( block.attributes?.className || '' ).split( /\s+/ ).forEach( function ( className ) {
						if ( /^[co]-[\w-]+$/.test( className ) ) {
							classes.push( className );
						}
					} );
					walk( block.innerBlocks || [] );
				} );
			};
			walk( blocks );
			const unique = [ ...new Set( classes ) ].sort();
			selectedBlockClass = '';
			String( selected?.attributes?.className || '' ).split( /\s+/ ).some( function ( className ) {
				if ( /^[co]-[\w-]+$/.test( className ) ) {
					selectedBlockClass = className;
					return true;
				}
				return false;
			} );
			const signature = unique.join( ',' ) + '|' + selectedBlockClass;
			if ( signature === previous ) {
				return;
			}
			previous = signature;
			discovered = unique;
			sendClasses();
		} );
	}

	$( function () {
		initializeEditors();
		addBlockEditorTools();
		enableKeyboardSave();
		enableLiveClassDiscovery();
		injectPreviewCss( window.projectOverrides.previewCss || '' );
		schedulePreviewUpdate();
		observePreviewTargets();
		activeScope = $( '#project-overrides-scope' ).val() || null;

		window.addEventListener( 'project-overrides-focus', function () {
			focusCssEditor();
		} );
		window.addEventListener( 'message', function ( event ) {
			if ( event.origin !== window.location.origin || 'project-overrides-classes' !== event.data?.type ) {
				if ( event.origin === window.location.origin && 'project-overrides-save-result' === event.data?.type ) {
					dirty = Boolean( event.data.failed );
				} else if ( event.origin === window.location.origin && 'project-overrides-preview-css' === event.data?.type ) {
					injectPreviewCss( event.data.css || '' );
				}
				return;
			}
			const select = document.getElementById( 'project-overrides-scope' );
			( event.data.classes || [] ).forEach( function ( className ) {
				if ( ! window.projectOverrides.classNames.includes( className ) ) {
					window.projectOverrides.classNames.push( className );
				}
				if ( select && ! select.querySelector( 'option[value="class:' + CSS.escape( className ) + '"]' ) ) {
					const option = document.createElement( 'option' );
					option.value = 'class:' + className;
					option.textContent = 'Block class: ' + className;
					select.appendChild( option );
				}
			} );
			selectedBlockClass = event.data.selectedClass || '';
			$( '.project-overrides-use-selected-class' )
				.prop( 'hidden', ! selectedBlockClass )
				.text( selectedBlockClass ? window.projectOverrides.useBlockClass + ': .' + selectedBlockClass : window.projectOverrides.useBlockClass );
		} );
		if ( window.parent !== window ) {
			window.parent.postMessage( { type: 'project-overrides-ready' }, window.location.origin );
		}

		$( '.project-overrides-page-select' ).on( 'change', function () {
			const base = $( this ).data( 'base-url' );
			if ( dirty && ! window.confirm( window.projectOverrides.unsaved ) ) {
				this.value = new URLSearchParams( window.location.search ).get( 'post_id' ) || '';
				return;
			}
			dirty = false;
			window.location.href = this.value ? base + '&post_id=' + encodeURIComponent( this.value ) : base;
		} );

		$( '.project-overrides-general-scope-select' ).on( 'change', function () {
			if ( dirty && ! window.confirm( window.projectOverrides.unsaved ) ) {
				this.value = new URLSearchParams( window.location.search ).get( 'scope' ) || '';
				return;
			}
			const url = new URL( window.location.href );
			if ( this.value ) {
				url.searchParams.set( 'scope', this.value );
			} else {
				url.searchParams.delete( 'scope' );
			}
			url.searchParams.delete( 'updated' );
			dirty = false;
			window.location.href = url.toString();
		} );

		$( '.project-overrides form' ).on( 'submit', function () {
			dirty = false;
		} );

		$( '.project-overrides__metadata input' ).on( 'input', function () {
			dirty = true;
		} );

		$( '#project-overrides-scope' ).on( 'change', function () {
			if ( dirty && activeScope && ! window.confirm( window.projectOverrides.unsaved ) ) {
				this.value = activeScope;
				return;
			}
			const editor = editors.get( 'project-overrides-meta-css' );
			const data = window.projectOverrides.scopeData[ this.value ] || {
				css: '',
				status: 'temporary',
				reason: '',
			};
			if ( editor ) {
				editor.setValue( data.css );
			}
			$( '[name="project_overrides_status"]' ).val( data.status );
			$( '[name="project_overrides_reason"]' ).val( data.reason );
			$( '[data-scope-indicator]' ).text( 'Editing: ' + $( this ).find( 'option:selected' ).text() );
			activeScope = this.value;
			dirty = true;
			schedulePreviewUpdate();
		} );

		$( '.project-overrides-use-selected-class' ).on( 'click', function () {
			if ( ! selectedBlockClass ) {
				return;
			}
			const select = document.getElementById( 'project-overrides-scope' );
			if ( ! select ) {
				return;
			}
			const value = 'class:' + selectedBlockClass;
			if ( ! select.querySelector( 'option[value="' + CSS.escape( value ) + '"]' ) ) {
				const option = document.createElement( 'option' );
				option.value = value;
				option.textContent = 'Block class: ' + selectedBlockClass;
				select.appendChild( option );
			}
			select.value = value;
			$( select ).trigger( 'change' );
		} );

		$( '[name="project_overrides_status"]' ).on( 'change', schedulePreviewUpdate );

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
