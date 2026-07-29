/**
 * Yazan — header controller (Comma Football style).
 *
 * Resolves the active header (Astra native OR Header Footer Elementor), tags it with a stable
 * `.yz-header` class, then:
 *   1. Adds `.is-scrolled` shortly after scrolling begins → compresses the (solid) header.
 *   2. Smart hide on scroll-down, show on scroll-up.
 *   3. Rotates the announcement-bar messages.
 *
 * Pure vanilla JS, deferred, passive scroll listener, rAF-throttled. Honors
 * prefers-reduced-motion. RTL-agnostic.
 */
(function () {
	'use strict';

	var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/* ------------------------------------------------------------------ */
	/* Header scroll behaviour                                            */
	/* ------------------------------------------------------------------ */
	function initHeader() {
		var header = document.querySelector('.elementor-location-header')
			|| document.querySelector('.site-header')
			|| document.querySelector('#masthead');

		if (!header) {
			return;
		}

		header.classList.add('yz-header');
		document.documentElement.style.setProperty('--yz-header-h', header.offsetHeight + 'px');

		var lastY   = window.scrollY;
		var ticking = false;

		function apply() {
			var y = window.scrollY;

			// Compress once the user starts scrolling.
			header.classList.toggle('is-scrolled', y > 20);

			// Smart hide/show (kept pinned under reduced motion).
			if (!reduce) {
				header.classList.toggle('is-hidden', y > 300 && y > lastY);
			}

			lastY = y;
			ticking = false;
		}

		window.addEventListener('scroll', function () {
			if (!ticking) {
				window.requestAnimationFrame(apply);
				ticking = true;
			}
		}, { passive: true });

		window.addEventListener('resize', function () {
			document.documentElement.style.setProperty('--yz-header-h', header.offsetHeight + 'px');
		}, { passive: true });

		apply();
	}

	/* ------------------------------------------------------------------ */
	/* Announcement bar rotation                                          */
	/* ------------------------------------------------------------------ */
	function initAnnouncement() {
		var bar = document.querySelector('.yz-announce');
		if (!bar) {
			return;
		}
		var items = bar.querySelectorAll('.yz-announce__item');
		if (items.length < 2 || reduce) {
			return; // one message, or reduced motion → leave the first showing.
		}

		var idx = 0;
		window.setInterval(function () {
			items[idx].classList.remove('is-active');
			idx = (idx + 1) % items.length;
			items[idx].classList.add('is-active');
		}, 4000);
	}

	/* ------------------------------------------------------------------ */
	/* Promo countdown (D/H/M/S), reference's shipping timer              */
	/* ------------------------------------------------------------------ */
	function initCountdown() {
		var el = document.querySelector('[data-yz-countdown]');
		if (!el) {
			return;
		}

		var deadline = parseInt(el.getAttribute('data-deadline'), 10) * 1000; // ms
		var window_ = parseInt(el.getAttribute('data-window'), 10) * 1000;     // ms (0 = fixed)
		if (!deadline) {
			return;
		}

		var out = {
			d: el.querySelector('[data-d]'),
			h: el.querySelector('[data-h]'),
			m: el.querySelector('[data-m]'),
			s: el.querySelector('[data-s]')
		};

		function pad(n) {
			return (n < 10 ? '0' : '') + n;
		}

		function tick() {
			var diff = deadline - Date.now();

			// Rolling window: when the timer elapses, roll to the next window and keep ticking.
			if (diff <= 0) {
				if (window_ > 0) {
					while (deadline - Date.now() <= 0) {
						deadline += window_;
					}
					diff = deadline - Date.now();
				} else {
					diff = 0;
				}
			}

			var s = Math.floor(diff / 1000);
			out.d.textContent = pad(Math.floor(s / 86400));
			out.h.textContent = pad(Math.floor((s % 86400) / 3600));
			out.m.textContent = pad(Math.floor((s % 3600) / 60));
			out.s.textContent = pad(s % 60);
			el.classList.add('is-ready');
		}

		tick();
		window.setInterval(tick, 1000);
	}

	function init() {
		initHeader();
		initAnnouncement();
		initCountdown();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
