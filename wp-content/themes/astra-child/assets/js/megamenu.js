/**
 * Yazan — mega menu accessibility layer.
 *
 * Desktop hover/focus is handled entirely in CSS (megamenu.css). This adds the bits CSS can't:
 *  - Touch: first tap on a parent opens its panel; a second tap follows the link.
 *  - Escape closes any open panel; a click/tap outside the header closes them too.
 *
 * Deferred, dependency-free, and inert on mobile menus (where panels are stacked by the builder).
 */
( function () {
	'use strict';

	var header = document.querySelector( '.yz-header' );
	if ( ! header ) {
		return;
	}

	var parents = Array.prototype.slice.call( header.querySelectorAll( '.menu-item-has-children' ) );
	if ( ! parents.length ) {
		return;
	}

	function isTouch() {
		return window.matchMedia( '(hover: none)' ).matches;
	}

	function closeAll( except ) {
		parents.forEach( function ( li ) {
			if ( li !== except ) {
				li.classList.remove( 'yz-open' );
			}
		} );
	}

	parents.forEach( function ( li ) {
		var link = li.querySelector( 'a' );
		if ( ! link || link.parentNode !== li ) {
			return; // Only the item's own top link, not descendants.
		}

		link.addEventListener( 'click', function ( e ) {
			if ( ! isTouch() ) {
				return; // Desktop uses hover; let the click navigate.
			}
			if ( ! li.classList.contains( 'yz-open' ) ) {
				e.preventDefault();
				closeAll( li );
				li.classList.add( 'yz-open' );
			}
			// Already open → allow the navigation on this second tap.
		} );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key || 'Esc' === e.key ) {
			closeAll( null );
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		if ( ! header.contains( e.target ) ) {
			closeAll( null );
		}
	} );
}() );
