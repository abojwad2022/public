/**
 * Yazan — Theme Switcher controller.
 *
 * Client-side, per-visitor theme selection with:
 *   • instant switching (no page reload)
 *   • persistence in localStorage (restored on every future visit / every page)
 *   • a smooth 400ms colour crossfade (no layout shift)
 *   • no flash of the wrong theme (the pre-paint head script sets the attribute
 *     BEFORE this file runs — see inc/theme-switcher.php)
 *   • cross-tab sync
 *
 * Scalable: add a theme by extending THEMES here + a token block in
 * theme-tokens.css (+ an override sheet if it inverts light/dark). No other JS.
 *
 * This file is deferred; the attribute is already correct at parse time, so the
 * page is never blocked on it. The active option highlight is CSS-driven from
 * the <html data-yz-theme> attribute, so it too is correct on first paint.
 */
(function () {
	'use strict';

	// Registry is provided by PHP (wp_localize_script → window.YazanThemes) so a
	// theme added server-side flows through with no JS edit. Fall back to sane
	// defaults if the localized data is ever missing.
	var CFG = window.YazanThemes || {};
	var STORAGE_KEY = CFG.storageKey || 'yazan-theme';
	var ATTR = CFG.attr || 'data-yz-theme';
	var ANIM_CLASS = 'yz-theme-animating';
	var THEMES = (CFG.themes && CFG.themes.length) ? CFG.themes : ['black', 'burgundy'];
	var DEFAULT = CFG.default || THEMES[0];
	var LABELS = CFG.labels || {};
	var SWITCH_TPL = CFG.switchTpl || 'Switch to %s';
	var ANIM_MS = 480;

	// Exactly two themes → a single sun/moon switch that flips on click.
	// Three or more → the labelled dropdown of options.
	var IS_BINARY = THEMES.length === 2;

	var root = document.documentElement;
	var animTimer = null;

	function isValid(theme) {
		return THEMES.indexOf(theme) !== -1;
	}

	function current() {
		var t = root.getAttribute(ATTR);
		return isValid(t) ? t : DEFAULT;
	}

	function persist(theme) {
		try { window.localStorage.setItem(STORAGE_KEY, theme); } catch (e) { /* private mode */ }
	}

	/**
	 * Apply a theme.
	 * @param {string}  theme
	 * @param {boolean} animate  Add the crossfade class (only for user actions).
	 */
	function apply(theme, animate) {
		if (!isValid(theme)) { theme = DEFAULT; }
		if (theme === current() && root.getAttribute(ATTR)) {
			syncControls(theme);
			return;
		}

		if (animate && !prefersReducedMotion()) {
			root.classList.add(ANIM_CLASS);
			window.clearTimeout(animTimer);
			animTimer = window.setTimeout(function () {
				root.classList.remove(ANIM_CLASS);
			}, ANIM_MS);
		}

		root.setAttribute(ATTR, theme);
		persist(theme);
		syncControls(theme);

		// Let the rest of the site react (e.g. re-tint a canvas/chart if ever added).
		try {
			root.dispatchEvent(new CustomEvent('yazan:themechange', { detail: { theme: theme } }));
		} catch (e) { /* old browsers: no-op */ }
	}

	function prefersReducedMotion() {
		return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	}

	/** The theme a click on the binary switch will move to (the other one). */
	function nextTheme() {
		var i = THEMES.indexOf(current());
		return THEMES[(i + 1) % THEMES.length];
	}

	/** Reflect the active theme onto the switcher controls (aria + state). */
	function syncControls(theme) {
		var opts = document.querySelectorAll('[data-yz-theme-option]');
		for (var i = 0; i < opts.length; i++) {
			var on = opts[i].getAttribute('data-yz-theme-option') === theme;
			opts[i].setAttribute('aria-checked', on ? 'true' : 'false');
			opts[i].tabIndex = on ? 0 : -1; // roving tabindex within the radiogroup
		}

		// Sun/moon switch: keep the aria-label describing the *action* (what a
		// click will do). The icon shown is CSS-driven from <html data-yz-theme>.
		if (IS_BINARY) {
			var toggle = document.querySelector('.yz-theme-switcher__toggle');
			if (toggle) {
				var nt = nextTheme();
				var label = SWITCH_TPL.replace('%s', LABELS[nt] || nt);
				toggle.setAttribute('aria-label', label);
				toggle.setAttribute('title', label);
			}
		}
	}

	/**
	 * Relocate the switcher into the site header's right icon cluster (next to
	 * search / cart / account) so it reads as a native header control rather than
	 * a floating badge. Falls back to its in-place (floating) rendering when no
	 * header is found. Astra's header markup is generated server-side from a
	 * DB-driven layout, so this is done client-side.
	 *
	 * @param {HTMLElement} sw The switcher root.
	 * @return {boolean} True when placed inside the header.
	 */
	function placeInHeader(sw) {
		if (sw.closest('.site-header-primary-section-right')) { return true; }

		var header = document.querySelector('.site-header')
			|| document.querySelector('#masthead')
			|| document.querySelector('.elementor-location-header');
		if (!header) { return false; }

		var right = header.querySelector('.site-header-primary-section-right');
		if (!right) { return false; }

		// Wrap in an Astra header-item slot so it aligns with its siblings.
		var slot = document.createElement('div');
		slot.className = 'ast-builder-layout-element site-header-focus-item yz-theme-slot';

		// Sit just before the search icon (theme · search · cart · account).
		var anchor = right.querySelector('.ast-header-search') || right.firstChild;
		right.insertBefore(slot, anchor);
		slot.appendChild(sw);
		sw.classList.add('yz-theme-switcher--in-header');
		return true;
	}

	function init() {
		// Attribute is already set pre-paint; make sure controls agree.
		syncControls(current());

		var sw = document.querySelector('.yz-theme-switcher');
		if (!sw) { return; }

		// Move into the header if possible; reveal once positioned (no flash of
		// the old floating spot). The switcher is JS-only anyway, so gating its
		// visibility on this init is safe.
		placeInHeader(sw);
		sw.classList.add('is-ready');

		var toggle = sw.querySelector('.yz-theme-switcher__toggle');
		var panel = sw.querySelector('.yz-theme-switcher__panel');

		function openPanel(open) {
			sw.classList.toggle('is-open', open);
			if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
		}

		// Binary switch: no panel — a click flips straight to the other theme.
		if (IS_BINARY && toggle) {
			toggle.setAttribute('aria-haspopup', 'false');
			toggle.removeAttribute('aria-expanded');
			toggle.addEventListener('click', function () {
				apply(nextTheme(), true);
			});
		} else if (toggle) {
			toggle.addEventListener('click', function () {
				openPanel(!sw.classList.contains('is-open'));
			});
		}

		// Choose a theme.
		sw.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('[data-yz-theme-option]') : null;
			if (!btn) { return; }
			e.preventDefault();
			apply(btn.getAttribute('data-yz-theme-option'), true);
		});

		// Keyboard: arrow keys move within the radiogroup, Enter/Space selects.
		sw.addEventListener('keydown', function (e) {
			var opts = Array.prototype.slice.call(sw.querySelectorAll('[data-yz-theme-option]'));
			var idx = opts.indexOf(document.activeElement);
			if (e.key === 'ArrowDown' || e.key === 'ArrowRight') {
				e.preventDefault();
				var next = opts[(idx + 1 + opts.length) % opts.length] || opts[0];
				next.focus();
			} else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') {
				e.preventDefault();
				var prev = opts[(idx - 1 + opts.length) % opts.length] || opts[0];
				prev.focus();
			} else if (e.key === 'Escape') {
				openPanel(false);
				if (toggle) { toggle.focus(); }
			}
		});

		// Close on outside click.
		document.addEventListener('click', function (e) {
			if (sw.classList.contains('is-open') && !sw.contains(e.target)) {
				openPanel(false);
			}
		});
	}

	// Cross-tab sync: reflect a change made in another tab.
	window.addEventListener('storage', function (e) {
		if (e.key === STORAGE_KEY && e.newValue) { apply(e.newValue, true); }
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
