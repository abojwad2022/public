/**
 * The preview agent — loaded ONLY inside the builder's preview iframe, never on a public page.
 *
 * Before this, every autosave reloaded the whole homepage: 300+ queries, a white flash, and the
 * scroll position thrown away, all to change one heading. Now the builder sends the HTML of just
 * the sections that changed and this swaps them in place.
 *
 * The section boundaries are HTML comments printed by ThemeBridge in preview mode
 * (`<!--yzhp:s:ID-->` … `<!--yzhp:e:ID-->`). Comments rather than a wrapper element, because the
 * preview has to be the same DOM the visitor gets — a helper div would change the very layout it
 * is meant to be showing faithfully.
 *
 * Anything it cannot do safely, it refuses and asks for a reload. A patch that is sometimes wrong
 * is worse than a reload that is always right.
 */
( function () {
	'use strict';

	var START = 'yzhp:s:';
	var END = 'yzhp:e:';

	/**
	 * Every comment node in the document, walked once.
	 *
	 * @return {Comment[]} Comments.
	 */
	function comments() {
		var walker = document.createTreeWalker( document.body, NodeFilter.SHOW_COMMENT, null, false );
		var found = [];
		var node;

		while ( ( node = walker.nextNode() ) ) {
			found.push( node );
		}

		return found;
	}

	/**
	 * Locate one section's start and end markers.
	 *
	 * @param {string} id Section id.
	 * @return {{start:Comment,end:Comment}|null} Range, or null when the markers are not both there.
	 */
	function range( id ) {
		var all = comments();
		var start = null;
		var end = null;

		all.forEach( function ( node ) {
			var text = node.nodeValue.trim();

			if ( text === START + id ) {
				start = node;
			} else if ( text === END + id ) {
				end = node;
			}
		} );

		if ( ! start || ! end || start.parentNode !== end.parentNode ) {
			// Different parents means the markers are not a flat range and removing between them
			// would tear the tree. Refuse.
			return null;
		}

		return { start: start, end: end };
	}

	/**
	 * Replace everything between (and including) a section's markers.
	 *
	 * @param {string} id   Section id.
	 * @param {string} html Replacement markup, markers included.
	 * @return {boolean} True when the swap happened.
	 */
	function replaceSection( id, html ) {
		var found = range( id );

		if ( ! found ) {
			return false;
		}

		var parent = found.start.parentNode;
		var fragment = document.createRange().createContextualFragment( html );

		// Collect first, remove after: removing while walking `nextSibling` loses the thread.
		var doomed = [];
		var node = found.start;

		while ( node ) {
			doomed.push( node );

			if ( node === found.end ) {
				break;
			}

			node = node.nextSibling;
		}

		parent.insertBefore( fragment, found.start );

		doomed.forEach( function ( item ) {
			if ( item.parentNode ) {
				item.parentNode.removeChild( item );
			}
		} );

		return true;
	}

	/**
	 * Re-run the section scripts over the new markup.
	 *
	 * sections.js registers each of its starters on `window.yzHomeInit`, and each one skips nodes
	 * it has already armed — so calling them again wires the patched section without doubling the
	 * timers on the sections that were left alone.
	 *
	 * @return {void}
	 */
	function rearm() {
		var starters = window.yzHomeInit;

		if ( ! starters || ! starters.length ) {
			return;
		}

		starters.forEach( function ( start ) {
			try {
				start();
			} catch ( error ) {
				// One broken section must not stop the others being wired.
				if ( window.console && window.console.warn ) {
					window.console.warn( '[yazan] preview re-init failed', error );
				}
			}
		} );
	}

	function reply( message ) {
		if ( window.parent && window.parent !== window ) {
			window.parent.postMessage( message, window.location.origin );
		}
	}

	window.addEventListener( 'message', function ( event ) {
		// Same origin and the parent frame only. The preview renders an operator's unpublished
		// draft; nothing else on the page gets to drive it.
		if ( event.origin !== window.location.origin || event.source !== window.parent ) {
			return;
		}

		var data = event.data;

		if ( ! data || 'yzhp:patch' !== data.type || ! data.sections ) {
			return;
		}

		var missed = [];

		Object.keys( data.sections ).forEach( function ( id ) {
			if ( ! replaceSection( id, data.sections[ id ] ) ) {
				missed.push( id );
			}
		} );

		rearm();

		// Told, not guessed: the builder reloads the frame when a section could not be found,
		// which is what happens the first time a brand-new section appears.
		reply( { type: 'yzhp:patched', missed: missed, token: data.token || '' } );
	} );

	// Announce readiness, so the builder knows patching is available rather than assuming it.
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			reply( { type: 'yzhp:ready' } );
		} );
	} else {
		reply( { type: 'yzhp:ready' } );
	}
} )();
