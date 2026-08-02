/**
 * The only JavaScript any plugin-drawn homepage section ships: the sale countdown.
 *
 * No dependencies, no build step, deferred. It reads a UTC timestamp from the markup and counts
 * in the visitor's own clock — the server cannot do that, because a public homepage is cached and
 * "3 hours left" would be frozen at whatever it said when the page was stored.
 */
( function () {
	'use strict';

	var PAD = function ( value ) {
		return value < 10 ? '0' + value : String( value );
	};

	function tick( element, endsAt ) {
		var remaining = endsAt - Math.floor( Date.now() / 1000 );

		if ( remaining <= 0 ) {
			// The band is already gone server-side by the next request; here we simply stop
			// pretending there is time left rather than counting into negatives.
			element.hidden = true;
			return false;
		}

		var days = Math.floor( remaining / 86400 );
		var hours = Math.floor( ( remaining % 86400 ) / 3600 );
		var minutes = Math.floor( ( remaining % 3600 ) / 60 );
		var seconds = remaining % 60;

		var set = function ( unit, value ) {
			var node = element.querySelector( '[data-unit="' + unit + '"]' );
			if ( node ) {
				node.textContent = PAD( value );
			}
		};

		set( 'days', days );
		set( 'hours', hours );
		set( 'minutes', minutes );
		set( 'seconds', seconds );

		return true;
	}

	function start() {
		var nodes = document.querySelectorAll( '[data-yzhp-countdown]' );

		Array.prototype.forEach.call( nodes, function ( element ) {
			var endsAt = parseInt( element.getAttribute( 'data-yzhp-countdown' ), 10 );

			if ( ! endsAt ) {
				return;
			}

			// Armed once. start() runs again after the builder patches a section, and a second
			// interval on the same element would tick the clock down twice per second.
			if ( element.getAttribute( 'data-yzhp-armed' ) ) {
				return;
			}

			element.setAttribute( 'data-yzhp-armed', '1' );

			if ( ! tick( element, endsAt ) ) {
				return;
			}

			var timer = setInterval( function () {
				if ( ! tick( element, endsAt ) ) {
					clearInterval( timer );
				}
			}, 1000 );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}

	// The preview agent calls these again after swapping a section in. Registered here rather
	// than exported one by one so adding a fourth behaviour needs no change anywhere else.
	( window.yzHomeInit = window.yzHomeInit || [] ).push( start );
} )();

/**
 * The hero slider.
 *
 * Vanilla, ~120 lines, no dependencies — a carousel library would be larger than every other
 * asset this module ships combined.
 *
 * What it refuses to do:
 *   · autoplay when the visitor has asked for reduced motion, or when the tab is hidden
 *   · keep advancing while someone is hovering, or has focus inside it
 *   · move without updating aria-hidden and aria-selected, which is what makes a carousel
 *     usable with a screen reader rather than an infinite loop of announcements
 */
( function () {
	'use strict';

	var REDUCED = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function Slider( root ) {
		var slides = root.querySelectorAll( '.yzhp-hero__slide' );
		var dots = root.querySelectorAll( '[data-slider-to]' );
		var index = 0;
		var timer = null;
		var paused = false;

		if ( slides.length < 2 ) {
			return;
		}

		var speed = parseInt( root.getAttribute( 'data-speed' ), 10 ) || 6000;
		var autoplay = '1' === root.getAttribute( 'data-autoplay' ) && ! REDUCED;

		function show( next ) {
			index = ( next + slides.length ) % slides.length;

			Array.prototype.forEach.call( slides, function ( slide, i ) {
				var active = i === index;
				slide.classList.toggle( 'is-active', active );
				// aria-hidden, not display:none — the transition needs the node painted, and a
				// screen reader needs to be told it is not the current one.
				if ( active ) {
					slide.removeAttribute( 'aria-hidden' );
				} else {
					slide.setAttribute( 'aria-hidden', 'true' );
				}
			} );

			Array.prototype.forEach.call( dots, function ( dot, i ) {
				dot.classList.toggle( 'is-active', i === index );
				dot.setAttribute( 'aria-selected', i === index ? 'true' : 'false' );
			} );
		}

		function stop() {
			if ( timer ) {
				clearInterval( timer );
				timer = null;
			}
		}

		function play() {
			stop();
			if ( ! autoplay || paused || document.hidden ) {
				return;
			}
			timer = setInterval( function () {
				show( index + 1 );
			}, speed );
		}

		function pause( state ) {
			paused = state;
			play();
		}

		var prev = root.querySelector( '[data-slider-prev]' );
		var next = root.querySelector( '[data-slider-next]' );

		if ( prev ) {
			prev.addEventListener( 'click', function () {
				show( index - 1 );
				play();
			} );
		}

		if ( next ) {
			next.addEventListener( 'click', function () {
				show( index + 1 );
				play();
			} );
		}

		Array.prototype.forEach.call( dots, function ( dot ) {
			dot.addEventListener( 'click', function () {
				show( parseInt( dot.getAttribute( 'data-slider-to' ), 10 ) || 0 );
				play();
			} );
		} );

		root.addEventListener( 'mouseenter', function () { pause( true ); } );
		root.addEventListener( 'mouseleave', function () { pause( false ); } );
		root.addEventListener( 'focusin', function () { pause( true ); } );
		root.addEventListener( 'focusout', function () { pause( false ); } );

		root.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowLeft' === event.key ) {
				show( index - 1 );
			} else if ( 'ArrowRight' === event.key ) {
				show( index + 1 );
			} else {
				return;
			}
			event.preventDefault();
			play();
		} );

		// A background tab must not burn a timer, and must not have advanced six slides while
		// nobody was looking.
		document.addEventListener( 'visibilitychange', play );

		// Touch: a horizontal drag moves a slide, a vertical one is left alone so the page can
		// still be scrolled with a finger on top of the hero.
		var startX = 0;
		var startY = 0;

		root.addEventListener( 'touchstart', function ( event ) {
			startX = event.touches[ 0 ].clientX;
			startY = event.touches[ 0 ].clientY;
		}, { passive: true } );

		root.addEventListener( 'touchend', function ( event ) {
			var dx = event.changedTouches[ 0 ].clientX - startX;
			var dy = event.changedTouches[ 0 ].clientY - startY;

			if ( Math.abs( dx ) > 45 && Math.abs( dx ) > Math.abs( dy ) ) {
				show( dx < 0 ? index + 1 : index - 1 );
				play();
			}
		}, { passive: true } );

		show( 0 );
		play();
	}

	function startSliders() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-yzhp-slider]' ), function ( root ) {
			// A second Slider on the same root would run a second autoplay timer against the same
			// slides — they would fight, and the carousel would jump.
			if ( root.getAttribute( 'data-yzhp-armed' ) ) {
				return;
			}

			root.setAttribute( 'data-yzhp-armed', '1' );
			Slider( root );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', startSliders );
	} else {
		startSliders();
	}

	( window.yzHomeInit = window.yzHomeInit || [] ).push( startSliders );
} )();

/**
 * Product carousel arrows.
 *
 * The rail itself is CSS scroll-snap and already works — swipe, trackpad, keyboard, RTL — with no
 * JavaScript at all. This only adds the two arrows, and hides them when there is nothing to scroll
 * to, so they never sit there doing nothing.
 */
( function () {
	'use strict';

	function Carousel( root ) {
		var track = root.querySelector( '.yzhp-carousel__track' );
		var prev = root.querySelector( '[data-carousel-prev]' );
		var next = root.querySelector( '[data-carousel-next]' );

		if ( ! track || ! prev || ! next ) {
			return;
		}

		function step() {
			var card = track.querySelector( 'li.product' );
			return card ? card.getBoundingClientRect().width + 20 : track.clientWidth * 0.8;
		}

		function update() {
			var max = track.scrollWidth - track.clientWidth;

			// Nothing overflows: no arrows at all, rather than two disabled ones.
			if ( max < 8 ) {
				prev.hidden = true;
				next.hidden = true;
				return;
			}

			// scrollLeft is NEGATIVE in a right-to-left container in most browsers, so the ends
			// have to be compared on the absolute value or the arrows swap meaning on the Arabic
			// version of the site.
			var position = Math.abs( track.scrollLeft );

			prev.hidden = position < 8;
			next.hidden = position > max - 8;
		}

		function scrollBy( direction ) {
			// getComputedStyle rather than a hardcoded sign: the same button must move "forward"
			// in reading order whichever direction that is.
			var rtl = 'rtl' === getComputedStyle( track ).direction;
			var delta = step() * direction * ( rtl ? -1 : 1 );

			track.scrollBy( { left: delta, behavior: 'smooth' } );
		}

		prev.addEventListener( 'click', function () { scrollBy( -1 ); } );
		next.addEventListener( 'click', function () { scrollBy( 1 ); } );

		track.addEventListener( 'scroll', update, { passive: true } );
		window.addEventListener( 'resize', update );

		update();
	}

	function startCarousels() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-yzhp-carousel]' ), Carousel );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', startCarousels );
	} else {
		startCarousels();
	}
} )();

/**
 * Section entrance animations.
 *
 * Two rules decide the whole design:
 *
 *   1. The section starts VISIBLE in the markup. The script arms it (hides it) and then reveals
 *      it. If the script is blocked, fails, or never loads, the page reads normally — the opposite
 *      arrangement is how a JavaScript error turns into a blank homepage.
 *   2. A visitor who asked for reduced motion is not armed at all.
 */
( function () {
	'use strict';

	function startAnimations() {
		var nodes = document.querySelectorAll( '.yzhp-anim[data-yzhp-anim]' );

		if ( ! nodes.length ) {
			return;
		}

		var reduced = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		if ( reduced || ! ( 'IntersectionObserver' in window ) ) {
			return;
		}

		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( ! entry.isIntersecting ) {
						return;
					}

					entry.target.classList.add( 'is-inview' );
					// Once revealed, stop watching: a section that re-animates every time it
					// scrolls past is a section nobody can read on the way back up.
					observer.unobserve( entry.target );
				} );
			},
			{ rootMargin: '0px 0px -10% 0px', threshold: 0.05 }
		);

		Array.prototype.forEach.call( nodes, function ( node ) {
			// Already watched, or already revealed: re-arming would hide a section the reader is
			// looking at and wait for it to scroll into a view it never left.
			if ( node.classList.contains( 'is-armed' ) || node.classList.contains( 'is-inview' ) ) {
				return;
			}

			node.classList.add( 'is-armed' );
			observer.observe( node );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', startAnimations );
	} else {
		startAnimations();
	}

	( window.yzHomeInit = window.yzHomeInit || [] ).push( startAnimations );
} )();
