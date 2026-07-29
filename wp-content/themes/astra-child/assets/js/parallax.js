/**
 * Yazan — parallax (Motion Blueprint Part 8).
 * GSAP ScrollTrigger, front page only, DESKTOP + no-reduced-motion only.
 * Add class `data-parallax` (as an attribute) to the two intended elements:
 * the brand-statement background and the craftsmanship-strip images.
 * Keep drift small (±8%) — big parallax on jewelry photography reads gimmicky.
 */
(function () {
	'use strict';

	if (typeof window.gsap === 'undefined' || typeof window.ScrollTrigger === 'undefined') {
		return;
	}

	var ok = window.matchMedia('(min-width: 1024px) and (prefers-reduced-motion: no-preference)').matches;
	if (!ok) {
		return; // Disabled on mobile: fights momentum scrolling and costs battery.
	}

	window.gsap.registerPlugin(window.ScrollTrigger);

	window.gsap.utils.toArray('[data-parallax]').forEach(function (el) {
		window.gsap.fromTo(
			el,
			{ yPercent: -8 },
			{
				yPercent: 8,
				ease: 'none',
				scrollTrigger: {
					trigger: el.parentElement,
					scrub: 0.6,
					start: 'top bottom',
					end: 'bottom top'
				}
			}
		);
	});
})();
