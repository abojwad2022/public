/**
 * Yazan — scroll reveal observer (Motion Blueprint Part 7).
 * One IntersectionObserver runs the whole site. Zero dependencies. ~1KB.
 * Authoring convention: add class `yz-reveal` (or attribute `data-reveal`) in the editor.
 */
(function () {
	'use strict';

	var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var selector = '.yz-reveal, [data-reveal], [data-img-reveal]';
	var nodes = document.querySelectorAll(selector);

	if (!nodes.length) {
		return;
	}

	// Reduced motion or no observer support: show everything immediately.
	if (reduce || !('IntersectionObserver' in window)) {
		nodes.forEach(function (el) { el.classList.add('is-inview'); });
		return;
	}

	// Viewports are short on mobile — trigger reveals a little sooner there (Part 12).
	var margin = window.matchMedia('(max-width: 767px)').matches
		? '0px 0px -8% 0px'
		: '0px 0px -12% 0px';

	var io = new IntersectionObserver(function (entries) {
		entries.forEach(function (entry) {
			if (entry.isIntersecting) {
				entry.target.classList.add('is-inview');
				io.unobserve(entry.target); // animate once, then release
			}
		});
	}, { rootMargin: margin });

	nodes.forEach(function (el) { io.observe(el); });
})();
