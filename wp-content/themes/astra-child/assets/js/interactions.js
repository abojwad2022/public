/**
 * Yazan — UI interactions (Motion Blueprint Parts 9, 12).
 * Vanilla JS, no dependencies, deferred. Two small behaviours:
 *   1. Sticky mobile Add-to-Cart bar (reveal once the real buy form scrolls away).
 *   2. Add-to-Cart button feedback ("Adding…").
 * (Header scroll behaviour lives in assets/js/header.js.)
 */
(function () {
	'use strict';

	/* ------------------------------------------------------------------ */
	/* 1. Sticky mobile Add-to-Cart bar (Part 12)                         */
	/* ------------------------------------------------------------------ */
	function initStickyAtc() {
		var bar = document.querySelector('[data-yz-sticky-atc]');
		if (!bar) {
			return;
		}
		var form = document.querySelector('form.cart');
		var realBtn = form ? form.querySelector('.single_add_to_cart_button') : null;
		if (!form || !realBtn) {
			return;
		}

		bar.hidden = false;
		bar.setAttribute('aria-hidden', 'false');

		// Clicking the sticky button submits the real product form.
		bar.querySelector('.yz-sticky-atc__btn').addEventListener('click', function () {
			if (typeof realBtn.click === 'function') {
				realBtn.click();
			} else {
				form.requestSubmit ? form.requestSubmit(realBtn) : form.submit();
			}
		});

		if (!('IntersectionObserver' in window)) {
			return;
		}

		// Show the bar only while the real add-to-cart form is out of view.
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				bar.classList.toggle('is-visible', !entry.isIntersecting);
			});
		}, { rootMargin: '0px 0px -20% 0px' });
		io.observe(form);
	}

	/* ------------------------------------------------------------------ */
	/* 3. Add-to-Cart feedback (Part 9)                                   */
	/* ------------------------------------------------------------------ */
	function initCartFeedback() {
		document.body.addEventListener('click', function (e) {
			var btn = e.target.closest('.add_to_cart_button, .single_add_to_cart_button');
			if (!btn || btn.classList.contains('yz-adding')) {
				return;
			}
			btn.classList.add('yz-adding');
			btn.dataset.yzLabel = btn.textContent;
			btn.textContent = 'Adding…';
		});

		// WooCommerce fires this on the body after an AJAX add (loop buttons).
		if (window.jQuery) {
			window.jQuery(document.body).on('added_to_cart', function () {
				document.querySelectorAll('.add_to_cart_button.yz-adding').forEach(function (btn) {
					btn.classList.remove('yz-adding');
					btn.textContent = btn.dataset.yzLabel || 'Add to Cart';
				});
			});
		}
	}

	/* ------------------------------------------------------------------ */
	/* 3. Shop filter sidebar: mobile drawer toggle + collapsible groups  */
	/* ------------------------------------------------------------------ */
	function initShopFilters() {
		var shop = document.querySelector('.yz-shop');
		if (!shop) {
			return;
		}

		// Filters toggle (commafootball behaviour): hide the sidebar and let the product grid
		// expand to fill the freed width. Works on every screen — the difference is only the
		// default: desktop remembers the shopper's last choice (starts shown); mobile always
		// starts collapsed so the grid leads. State is a single class, `yz-shop--nofilters`.
		var toggle = shop.querySelector('[data-yz-filter-toggle]');
		if (toggle) {
			var toggleLabel = toggle.querySelector('.yz-shop__filter-toggle-label');

			// hidden === true  → sidebar collapsed, grid full-width, label "Close filters".
			// hidden === false → sidebar shown (default on desktop), grid 4-up, label "Filters".
			var reflectFilters = function (hidden) {
				shop.classList.toggle('yz-shop--nofilters', hidden);
				toggle.setAttribute('aria-expanded', hidden ? 'false' : 'true');
				if (toggleLabel) {
					toggleLabel.textContent = hidden
						? (toggleLabel.getAttribute('data-label-hidden') || 'Close filters')
						: (toggleLabel.getAttribute('data-label-shown') || 'Filters');
				}
			};

			// The inline no-FOUC script set the initial class before paint; sync label + aria to it.
			reflectFilters(shop.classList.contains('yz-shop--nofilters'));

			toggle.addEventListener('click', function () {
				reflectFilters(!shop.classList.contains('yz-shop--nofilters'));
			});
		}

		// Instant filtering: on any filter change, update ONLY the results region via AJAX (like
		// AliExpress) instead of reloading the whole page. Falls back to a normal submit if the
		// browser can't do a partial fetch. The Apply button is hidden under JS (CSS).
		var filterForm = shop.querySelector('.yz-filters');
		var canAjax = !!(window.fetch && window.history && window.history.pushState && window.DOMParser);

		function buildUrlFromForm() {
			var base = (filterForm.getAttribute('action') || window.location.pathname).split('?')[0];
			var params = new URLSearchParams();
			var data = new FormData(filterForm);
			data.forEach(function (value, key) {
				if (value === '' || value === null) {
					return;
				}
				params.append(key, value);
			});
			var qs = params.toString();
			return qs ? base + '?' + qs : base;
		}

		// Set the sidebar form (checkboxes + price) to match a target URL's params — used when a
		// removable chip or "Clear all" is clicked, so the sidebar stays in sync with the results.
		function syncFormFromUrl(url) {
			var qs = url.indexOf('?') > -1 ? url.split('?')[1] : '';
			var p = new URLSearchParams(qs);
			filterForm.querySelectorAll('input[type="checkbox"]').forEach(function (cb) { cb.checked = false; });
			p.forEach(function (value, key) {
				if (key.slice(-2) === '[]') {
					var cb = filterForm.querySelector('input[name="' + key + '"][value="' + (window.CSS && CSS.escape ? CSS.escape(value) : value) + '"]');
					if (cb) { cb.checked = true; }
				}
			});
			var minEl = filterForm.querySelector('input[name="yz_min"]');
			var maxEl = filterForm.querySelector('input[name="yz_max"]');
			if (minEl) { minEl.value = p.get('yz_min') || ''; }
			if (maxEl) { maxEl.value = p.get('yz_max') || ''; }
		}

		var currentAbort = null;
		function applyResults(url) {
			var results = shop.querySelector('.yz-shop__results');
			if (!results) {
				window.location.href = url;
				return;
			}
			// Abort any in-flight request so the LATEST filter/price state always wins (no dropped keystrokes).
			if (currentAbort) {
				currentAbort.abort();
			}
			currentAbort = ('AbortController' in window) ? new AbortController() : null;
			results.classList.add('is-loading');
			window.fetch(url, { credentials: 'same-origin', signal: currentAbort ? currentAbort.signal : undefined })
				.then(function (r) { return r.text(); })
				.then(function (html) {
					currentAbort = null;
					var doc = new DOMParser().parseFromString(html, 'text/html');
					var fresh = doc.querySelector('.yz-shop__results');
					if (!fresh) {
						window.location.href = url;
						return;
					}
					// Reveal AJAX-inserted cards immediately (the scroll observer only sees load-time nodes).
					fresh.querySelectorAll('.yz-reveal').forEach(function (el) { el.classList.add('is-inview'); });
					results.replaceWith(fresh);
					// Sync the result count in the (untouched) toolbar.
					var newCount = doc.querySelector('.yz-shop__tools .woocommerce-result-count');
					var curCount = shop.querySelector('.yz-shop__tools .woocommerce-result-count');
					if (newCount && curCount) { curCount.textContent = newCount.textContent; }
					window.history.pushState({ yzShop: 1 }, '', url);
				})
				.catch(function (e) {
					if (!e || e.name !== 'AbortError') {
						window.location.href = url;
					}
				});
		}

		function triggerFilter() {
			if (canAjax) {
				applyResults(buildUrlFromForm());
			} else if (filterForm.requestSubmit) {
				filterForm.requestSubmit();
			} else {
				filterForm.submit();
			}
		}

		if (filterForm) {
			// Checkboxes / radios: filter the instant they toggle.
			filterForm.addEventListener('change', function (e) {
				var t = e.target;
				if (t && (t.type === 'checkbox' || t.type === 'radio')) {
					triggerFilter();
				}
			});

			// Price fields: filter WHILE typing (debounced) — not only after leaving the field.
			var priceTimer = null;
			filterForm.addEventListener('input', function (e) {
				var t = e.target;
				if (!t || t.type !== 'number') {
					return;
				}
				window.clearTimeout(priceTimer);
				priceTimer = window.setTimeout(triggerFilter, 500);
			});

			// Removable chips + "Clear all" (delegated on .yz-shop, since .yz-shop__results is swapped).
			shop.addEventListener('click', function (e) {
				var link = e.target.closest ? e.target.closest('.yz-applied a') : null;
				if (!link || !canAjax) {
					return;
				}
				e.preventDefault();
				syncFormFromUrl(link.href);
				applyResults(link.href);
			});

			// Back/forward: reload to render the previous filter state from the server.
			window.addEventListener('popstate', function () {
				if (shop.querySelector('.yz-shop__results')) {
					window.location.reload();
				}
			});
		}

		// "View more / View less" for long filter groups (AliExpress-style).
		shop.querySelectorAll('[data-yz-filter-more]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var group = btn.closest('[data-yz-filter-group]');
				if (!group) {
					return;
				}
				var expanded = group.classList.toggle('is-expanded');
				btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
			});
		});

		// Any screen: collapse/expand individual filter groups.
		shop.querySelectorAll('[data-yz-filter-group] .yz-filter-group__head').forEach(function (head) {
			head.addEventListener('click', function () {
				var group = head.closest('[data-yz-filter-group]');
				if (!group) {
					return;
				}
				var collapsed = group.getAttribute('aria-collapsed') === 'true';
				group.setAttribute('aria-collapsed', collapsed ? 'false' : 'true');
				head.setAttribute('aria-expanded', collapsed ? 'true' : 'false');
			});
		});
	}

	/* ------------------------------------------------------------------ */
	/* 4. Live (instant) product search in the shop toolbar               */
	/* ------------------------------------------------------------------ */
	function initLiveSearch() {
		var form = document.querySelector('.yz-shop__search');
		if (!form || typeof window.YazanLiveSearch === 'undefined' || !window.fetch) {
			return;
		}
		var input = form.querySelector('input[type="search"]');
		if (!input) {
			return;
		}

		input.setAttribute('autocomplete', 'off');
		var panel = document.createElement('div');
		panel.className = 'yz-live-search';
		panel.setAttribute('role', 'listbox');
		panel.hidden = true;
		form.appendChild(panel);

		var timer = null;
		var controller = null;
		var lastQuery = '';

		function esc(str) {
			var d = document.createElement('div');
			d.textContent = str == null ? '' : str;
			return d.innerHTML;
		}

		function close() {
			panel.hidden = true;
			panel.innerHTML = '';
		}

		function render(data, query) {
			if (!data || !data.items || !data.items.length) {
				panel.hidden = false;
				panel.innerHTML = '<div class="yz-live-search__state">' + 'No matches for “' + esc(query) + '”' + '</div>';
				return;
			}
			var html = '<ul class="yz-live-search__list">';
			data.items.forEach(function (it) {
				html += '<a class="yz-live-search__item" href="' + esc(it.url) + '" role="option">'
					+ '<span class="yz-live-search__thumb"' + (it.thumb ? ' style="background-image:url(' + esc(it.thumb) + ')"' : '') + '></span>'
					+ '<span class="yz-live-search__meta">'
					+ '<span class="yz-live-search__title">' + esc(it.title) + '</span>'
					+ '<span class="yz-live-search__price">' + (it.price || '') + '</span>'
					+ '</span></a>';
			});
			html += '</ul>';
			if (data.more) {
				html += '<a class="yz-live-search__all" href="' + esc(data.more) + '">'
					+ 'See all results for “' + esc(query) + '”' + '</a>';
			}
			panel.hidden = false;
			panel.innerHTML = html;
		}

		function run(query) {
			if (controller) {
				controller.abort();
			}
			controller = ('AbortController' in window) ? new AbortController() : null;
			var url = window.YazanLiveSearch.ajax
				+ '?action=yazan_live_search'
				+ '&nonce=' + encodeURIComponent(window.YazanLiveSearch.nonce)
				+ '&q=' + encodeURIComponent(query);
			panel.hidden = false;
			panel.innerHTML = '<div class="yz-live-search__state">' + 'Searching…' + '</div>';
			window.fetch(url, { credentials: 'same-origin', signal: controller ? controller.signal : undefined })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (input.value.trim() === query) {
						render(d, query);
					}
				})
				.catch(function (e) {
					if (!e || e.name !== 'AbortError') {
						close();
					}
				});
		}

		input.addEventListener('input', function () {
			var query = input.value.trim();
			window.clearTimeout(timer);
			if (query.length < 2) {
				lastQuery = '';
				close();
				return;
			}
			if (query === lastQuery) {
				return;
			}
			lastQuery = query;
			timer = window.setTimeout(function () { run(query); }, 220);
		});

		input.addEventListener('focus', function () {
			if (panel.innerHTML && input.value.trim().length >= 2) {
				panel.hidden = false;
			}
		});
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				close();
			}
		});
		document.addEventListener('click', function (e) {
			if (!form.contains(e.target)) {
				close();
			}
		});
	}

	// Convert WooCommerce's product tabs into a Comma-style accordion. Progressive: with no JS the
	// tabs render as normal WooCommerce tabs. We reuse the existing panels + their titles, hide the
	// tab nav, and give each panel its own toggle header. First section opens by default.
	function initProductAccordion() {
		var wrap = document.querySelector('.woocommerce-tabs.wc-tabs-wrapper');
		if (!wrap) {
			return;
		}
		var nav = wrap.querySelector('ul.wc-tabs, ul.tabs');
		var panels = wrap.querySelectorAll('.woocommerce-Tabs-panel');
		if (!nav || !panels.length) {
			return;
		}

		// Map panel id -> human title from the (about-to-be-hidden) tab links.
		var titles = {};
		nav.querySelectorAll('a').forEach(function (a) {
			var href = a.getAttribute('href') || '';
			if (href.charAt(0) === '#') {
				titles[href.slice(1)] = (a.textContent || '').trim();
			}
		});

		wrap.classList.add('yz-accordion');

		panels.forEach(function (panel, idx) {
			// WooCommerce's tab script sets inline display on inactive panels — clear it so `hidden`
			// is the single source of truth for open/closed.
			panel.style.removeProperty('display');
			panel.classList.add('yz-acc__panel');

			var head = document.createElement('button');
			head.type = 'button';
			head.className = 'yz-acc__head';
			head.setAttribute('aria-expanded', idx === 0 ? 'true' : 'false');
			head.innerHTML = '<span class="yz-acc__title">' + (titles[panel.id] || 'Details') +
				'</span><span class="yz-acc__icon" aria-hidden="true"></span>';

			panel.parentNode.insertBefore(head, panel);
			panel.hidden = idx !== 0;

			head.addEventListener('click', function () {
				var open = head.getAttribute('aria-expanded') === 'true';
				head.setAttribute('aria-expanded', open ? 'false' : 'true');
				panel.hidden = open;
			});
		});
	}

	function init() {
		initStickyAtc();
		initCartFeedback();
		initShopFilters();
		initLiveSearch();
		initProductAccordion();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
