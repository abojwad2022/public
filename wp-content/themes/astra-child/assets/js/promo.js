/**
 * Yazan — first-order promo popup.
 * The full popup appears on EVERY visit until the visitor subscribes. Once per session it opens after
 * a delay (so internal page-to-page navigation in the same visit doesn't re-pop it); a new visit
 * (new browser session) shows it again. Closing it minimises to a floating "Get X% off" launcher for
 * the rest of that session; the launcher reopens it. A successful signup removes it for good.
 * Vanilla JS, no dependencies, deferred.
 */
(function () {
	'use strict';

	var cfg = window.YazanPromo;
	if (!cfg || !cfg.ajax) {
		return;
	}

	// Permanent "subscribed" flag (never show again) vs per-session "already shown this visit".
	function isDone() {
		try { return localStorage.getItem('yzPromoDone') === '1'; } catch (e) { return false; }
	}
	function markDone() {
		try { localStorage.setItem('yzPromoDone', '1'); } catch (e) {}
	}
	function shownThisSession() {
		try { return sessionStorage.getItem('yzPromoShown') === '1'; } catch (e) { return false; }
	}
	function markShown() {
		try { sessionStorage.setItem('yzPromoShown', '1'); } catch (e) {}
	}

	function init() {
		var root = document.querySelector('[data-yz-promo]');
		if (!root) {
			return;
		}

		var dialog = root.querySelector('.yz-promo__dialog');
		var launcher = document.querySelector('[data-yz-promo-open]');
		var form = root.querySelector('[data-yz-promo-form]');
		var emailInput = form ? form.querySelector('input[type="email"]') : null;
		var hpInput = form ? form.querySelector('input[name="yz_hp"]') : null;
		var submitBtn = form ? form.querySelector('.yz-promo__submit') : null;
		var errorEl = root.querySelector('[data-yz-promo-error]');
		var offerEl = root.querySelector('[data-yz-promo-offer]');
		var successEl = root.querySelector('[data-yz-promo-success]');
		var codeEl = root.querySelector('[data-yz-promo-code]');
		var copyBtn = root.querySelector('[data-yz-promo-copy]');
		var lastFocus = null;
		var isOpen = false;

		function showLauncher() {
			if (!launcher) { return; }
			launcher.hidden = false;
			launcher.setAttribute('aria-hidden', 'false');
			requestAnimationFrame(function () { launcher.classList.add('is-in'); });
		}
		function hideLauncher() {
			if (!launcher) { return; }
			launcher.classList.remove('is-in');
			launcher.hidden = true;
			launcher.setAttribute('aria-hidden', 'true');
		}

		function open() {
			if (isOpen || isDone()) {
				return;
			}
			isOpen = true;
			markShown();
			hideLauncher();
			lastFocus = document.activeElement;
			root.hidden = false;
			root.setAttribute('aria-hidden', 'false');
			requestAnimationFrame(function () { root.classList.add('is-open'); });
			if (emailInput) {
				setTimeout(function () { try { emailInput.focus(); } catch (e) {} }, 60);
			}
			document.addEventListener('keydown', onKeydown);
		}

		// Close = minimise to the launcher for the rest of this session (unless already subscribed).
		function close() {
			if (!isOpen) {
				return;
			}
			isOpen = false;
			root.classList.remove('is-open');
			document.removeEventListener('keydown', onKeydown);
			var done = function () {
				root.hidden = true;
				root.setAttribute('aria-hidden', 'true');
				dialog && dialog.removeEventListener('transitionend', done);
				if (lastFocus && typeof lastFocus.focus === 'function') { lastFocus.focus(); }
			};
			if (dialog) { dialog.addEventListener('transitionend', done); }
			setTimeout(done, 420);
			if (!isDone()) { showLauncher(); }
		}

		function onKeydown(e) {
			if (e.key === 'Escape') { close(); }
		}

		function showError(msg) {
			if (!errorEl) { return; }
			errorEl.textContent = msg;
			errorEl.hidden = false;
		}
		function clearError() {
			if (errorEl) { errorEl.hidden = true; errorEl.textContent = ''; }
		}
		function validEmail(v) {
			return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
		}

		// Close triggers (X, overlay, "Continue shopping").
		root.querySelectorAll('[data-yz-promo-close]').forEach(function (el) {
			el.addEventListener('click', function (e) { e.preventDefault(); close(); });
		});

		// Launcher reopens the full popup.
		if (launcher) {
			launcher.addEventListener('click', function () { open(); });
		}

		// Submit.
		if (form) {
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				clearError();
				var email = emailInput ? emailInput.value.trim() : '';
				if (!validEmail(email)) {
					showError('Please enter a valid email address.');
					if (emailInput) { emailInput.focus(); }
					return;
				}
				if (submitBtn) { submitBtn.disabled = true; submitBtn.dataset.label = submitBtn.textContent; submitBtn.textContent = '…'; }

				var body = new URLSearchParams();
				body.set('action', 'yazan_promo_subscribe');
				body.set('nonce', cfg.nonce || '');
				body.set('email', email);
				body.set('yz_hp', hpInput ? hpInput.value : '');

				window.fetch(cfg.ajax, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: body.toString()
				})
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (res && res.success) {
							if (res.data && res.data.code && codeEl) { codeEl.textContent = res.data.code; }
							if (offerEl) { offerEl.hidden = true; }
							if (successEl) { successEl.hidden = false; }
							markDone();          // Subscribed — never show again on this browser.
							hideLauncher();
						} else {
							var m = (res && res.data && res.data.message) ? res.data.message : 'Something went wrong. Please try again.';
							showError(m);
							if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = submitBtn.dataset.label || 'Continue'; }
						}
					})
					.catch(function () {
						showError('Network error. Please try again.');
						if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = submitBtn.dataset.label || 'Continue'; }
					});
			});
		}

		// Copy code.
		if (copyBtn && codeEl) {
			copyBtn.addEventListener('click', function () {
				var code = codeEl.textContent.trim();
				var mark = function () {
					copyBtn.classList.add('is-copied');
					var t = copyBtn.textContent;
					copyBtn.textContent = 'Copied';
					setTimeout(function () { copyBtn.classList.remove('is-copied'); copyBtn.textContent = t; }, 1600);
				};
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(code).then(mark).catch(mark);
				} else {
					mark();
				}
			});
		}

		// Decide what to show on load.
		if (isDone()) {
			return;                       // Subscribed: nothing, ever.
		}
		if (shownThisSession()) {
			showLauncher();               // Already shown this visit: keep the small launcher only.
			return;
		}
		// New visit: show the full popup after the delay.
		var delay = typeof cfg.delayMs === 'number' ? cfg.delayMs : 5000;
		setTimeout(open, Math.max(0, delay));
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
