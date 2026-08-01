/**
 * Yazan — sign-in / create-account card.
 *
 * Folds the two plain WooCommerce forms the PHP renders into a two-step flow (email → password, or
 * email → register), submits them over AJAX, and drives the dialog that the header account icon
 * opens. Everything here is an enhancement: with this file absent the forms still post to
 * /my-account/ and WooCommerce handles them exactly as before.
 *
 * Vanilla, no dependencies, deferred.
 */
(function () {
	'use strict';

	var cfg = window.YazanSignin;
	if (!cfg || !cfg.ajax) {
		return;
	}

	var i18n = cfg.i18n || {};
	var TWO_STEP = cfg.twoStep === '1';
	var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

	/* ------------------------------------------------------------------ */
	/* Transport                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * POST an action to admin-ajax, renewing the nonce once if the page has been open too long.
	 *
	 * The card is printed into the footer of every page, so its nonce is as old as the tab. Rather
	 * than tell a shopper their sign-in "went wrong" because they left the tab open overnight, take
	 * the server's `bad_nonce` at face value, mint a new one, and replay. Nothing was processed on
	 * the rejected attempt, so replaying is safe. Same self-healing shape as chat.js.
	 *
	 * @param {string} action Admin-ajax action name, minus the yazan_signin_ prefix.
	 * @param {Object} fields Form fields to send.
	 * @param {boolean} [isReplay] Internal: true on the second attempt.
	 * @returns {Promise<Object>} The decoded response.
	 */
	function post(action, fields, isReplay) {
		var body = new URLSearchParams();
		body.set('action', 'yazan_signin_' + action);
		body.set('nonce', cfg.nonce || '');
		body.set('redirect', cfg.redirect || '');

		Object.keys(fields).forEach(function (key) {
			body.set(key, fields[key]);
		});

		return window.fetch(cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		})
			.then(function (response) { return response.json(); })
			.then(function (res) {
				var expired = res && !res.success && res.data && res.data.code === 'bad_nonce';
				if (expired && !isReplay) {
					return renewNonce().then(function (ok) {
						return ok ? post(action, fields, true) : res;
					});
				}
				return res;
			});
	}

	/**
	 * Fetch a fresh nonce for this session.
	 *
	 * @returns {Promise<boolean>} True when a usable nonce was stored.
	 */
	function renewNonce() {
		var body = new URLSearchParams();
		body.set('action', 'yazan_signin_nonce');

		return window.fetch(cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		})
			.then(function (response) { return response.json(); })
			.then(function (res) {
				if (res && res.success && res.data && res.data.nonce) {
					cfg.nonce = res.data.nonce;
					return true;
				}
				return false;
			})
			.catch(function () { return false; });
	}

	/**
	 * Where a successful sign-in sends the browser. An empty redirect means "stay here" — reloading
	 * repaints the header, the cart count and any price shown to members, in place.
	 *
	 * @param {Object} data Response payload.
	 */
	function finish(data) {
		if (data && data.redirect) {
			window.location.assign(data.redirect);
			return;
		}
		window.location.reload();
	}

	/* ------------------------------------------------------------------ */
	/* One card                                                           */
	/* ------------------------------------------------------------------ */

	function initCard(card) {
		var views = {
			signin: card.querySelector('[data-yz-signin-view="signin"]'),
			register: card.querySelector('[data-yz-signin-view="register"]')
		};
		var loginForm = card.querySelector('[data-yz-signin-form="login"]');
		var registerForm = card.querySelector('[data-yz-signin-form="register"]');
		var emailStep = card.querySelector('[data-yz-signin-step="email"]');
		var passwordStep = card.querySelector('[data-yz-signin-step="password"]');
		var identity = card.querySelector('[data-yz-signin-identity]');
		var identityEmail = card.querySelector('[data-yz-signin-identity-email]');

		var loginEmail = loginForm ? loginForm.querySelector('input[name="username"]') : null;
		var loginPassword = loginForm ? loginForm.querySelector('input[name="password"]') : null;
		var registerEmail = registerForm ? registerForm.querySelector('input[name="email"]') : null;
		var registerFirst = registerForm ? registerForm.querySelector('input[name="first_name"]') : null;
		var registerPassword = registerForm ? registerForm.querySelector('input[name="password"]') : null;

		var step = 'email';

		/**
		 * The honeypot's value — empty for a human, filled by a bot that autocompletes every field.
		 *
		 * @param {HTMLFormElement} form Form to read from.
		 * @returns {string}
		 */
		function trap(form) {
			var field = form ? form.querySelector('input[name="yz_hp"]') : null;
			return field ? field.value : '';
		}

		/* -- view + step ------------------------------------------------ */

		function showView(name) {
			if (!views[name]) {
				return;
			}
			Object.keys(views).forEach(function (key) {
				if (views[key]) {
					views[key].hidden = (key !== name);
				}
			});
			card.setAttribute('data-view', name);
			clearErrors();
		}

		function showStep(next) {
			step = next;
			card.setAttribute('data-step', next);

			if (emailStep) { emailStep.hidden = (next !== 'email'); }
			if (passwordStep) { passwordStep.hidden = (next !== 'password'); }

			if (next === 'password') {
				if (identity && identityEmail && loginEmail) {
					identityEmail.textContent = loginEmail.value.trim();
					identity.hidden = false;
				}
				focus(loginPassword);
			} else {
				if (identity) { identity.hidden = true; }
				if (loginPassword) { loginPassword.value = ''; }
			}
		}

		function focus(el) {
			if (!el) { return; }
			setTimeout(function () { try { el.focus(); } catch (e) {} }, 60);
		}

		/* -- errors + busy state ---------------------------------------- */

		function errorBox(form) {
			return form ? form.querySelector('[data-yz-signin-error]') : null;
		}

		function showError(form, message) {
			var box = errorBox(form);
			if (!box) { return; }
			box.textContent = message || i18n.generic || 'Something went wrong.';
			box.hidden = false;
		}

		function clearErrors() {
			card.querySelectorAll('[data-yz-signin-error]').forEach(function (box) {
				box.hidden = true;
				box.textContent = '';
			});
		}

		/**
		 * The submit button the shopper is actually looking at.
		 *
		 * The login form carries two — "Continue with email" and "Sign in" — and only one of them
		 * is on screen at a time. They are hidden by their step wrapper rather than by an attribute
		 * of their own, so ask the layout which one is rendered instead of matching on :not([hidden]).
		 *
		 * @param {HTMLFormElement} form Form to look in.
		 * @returns {HTMLElement|null}
		 */
		function activeSubmit(form) {
			if (!form) { return null; }
			var buttons = form.querySelectorAll('.yz-signin__submit');
			for (var i = 0; i < buttons.length; i++) {
				if (buttons[i].offsetParent !== null) {
					return buttons[i];
				}
			}
			return buttons.length ? buttons[buttons.length - 1] : null;
		}

		function busy(form, on) {
			var button = activeSubmit(form);
			if (!button) { return; }

			if (on) {
				button.dataset.label = button.textContent;
				button.textContent = i18n.working || '…';
				button.disabled = true;
				return;
			}

			button.disabled = false;
			if (button.dataset.label) {
				button.textContent = button.dataset.label;
			}
		}

		function fail(form, res) {
			var message = (res && res.data && res.data.message) ? res.data.message : i18n.generic;
			showError(form, message);
			busy(form, false);
		}

		/* -- step one: look the address up ------------------------------ */

		function lookup() {
			var email = loginEmail ? loginEmail.value.trim() : '';

			if (!EMAIL_RE.test(email)) {
				showError(loginForm, i18n.email);
				focus(loginEmail);
				return;
			}

			clearErrors();
			busy(loginForm, true);

			post('lookup', { email: email })
				.then(function (res) {
					busy(loginForm, false);

					if (!res || !res.success) {
						fail(loginForm, res);
						return;
					}

					if (res.data.step === 'register' && views.register) {
						if (registerEmail) { registerEmail.value = email; }
						showView('register');
						focus(registerFirst);
						return;
					}

					showStep('password');
				})
				.catch(function () {
					showError(loginForm, i18n.network);
					busy(loginForm, false);
				});
		}

		/* -- submits ---------------------------------------------------- */

		if (loginForm) {
			loginForm.addEventListener('submit', function (e) {
				e.preventDefault();

				if (TWO_STEP && step === 'email') {
					lookup();
					return;
				}

				clearErrors();
				busy(loginForm, true);

				post('login', {
					username: loginEmail ? loginEmail.value.trim() : '',
					password: loginPassword ? loginPassword.value : '',
					rememberme: loginForm.querySelector('input[name="rememberme"]:checked') ? 'forever' : '',
					yz_hp: trap(loginForm)
				})
					.then(function (res) {
						if (res && res.success) {
							finish(res.data);
							return;
						}
						fail(loginForm, res);
					})
					.catch(function () {
						showError(loginForm, i18n.network);
						busy(loginForm, false);
					});
			});
		}

		if (registerForm) {
			registerForm.addEventListener('submit', function (e) {
				e.preventDefault();

				var email = registerEmail ? registerEmail.value.trim() : '';
				if (!EMAIL_RE.test(email)) {
					showError(registerForm, i18n.email);
					focus(registerEmail);
					return;
				}

				clearErrors();
				busy(registerForm, true);

				post('register', {
					email: email,
					first_name: registerFirst ? registerFirst.value.trim() : '',
					password: registerPassword ? registerPassword.value : '',
					yz_hp: trap(registerForm)
				})
					.then(function (res) {
						if (res && res.success) {
							finish(res.data);
							return;
						}
						fail(registerForm, res);
					})
					.catch(function () {
						showError(registerForm, i18n.network);
						busy(registerForm, false);
					});
			});
		}

		/* -- switches --------------------------------------------------- */

		card.querySelectorAll('[data-yz-signin-view-to]').forEach(function (button) {
			button.addEventListener('click', function () {
				var target = button.getAttribute('data-yz-signin-view-to');

				// Carry whatever they already typed across, so nobody types their address twice.
				if (target === 'register' && registerEmail && loginEmail && loginEmail.value.trim()) {
					registerEmail.value = loginEmail.value.trim();
				}
				if (target === 'signin' && loginEmail && registerEmail && registerEmail.value.trim()) {
					loginEmail.value = registerEmail.value.trim();
				}

				showView(target);
				focus(target === 'register' ? registerEmail : loginEmail);
			});
		});

		card.querySelectorAll('[data-yz-signin-restart]').forEach(function (button) {
			button.addEventListener('click', function () {
				showStep('email');
				focus(loginEmail);
			});
		});

		/* -- password reveal -------------------------------------------- */

		card.querySelectorAll('[data-yz-signin-eye]').forEach(function (button) {
			button.addEventListener('click', function () {
				var input = button.parentNode.querySelector('input');
				if (!input) { return; }

				var reveal = (input.type === 'password');
				input.type = reveal ? 'text' : 'password';
				button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
				input.focus();
			});
		});

		// Start clean: the server renders the login view with the email step showing.
		showView('signin');
		showStep('email');

		return {
			reset: function () {
				clearErrors();
				showView('signin');
				showStep('email');
			},
			firstField: function () {
				return loginEmail;
			}
		};
	}

	/* ------------------------------------------------------------------ */
	/* The dialog                                                         */
	/* ------------------------------------------------------------------ */

	function initModal(root, card) {
		var dialog = root.querySelector('.yz-signin-modal__dialog');
		var lastFocus = null;
		var isOpen = false;

		function open() {
			if (isOpen) { return; }
			isOpen = true;
			lastFocus = document.activeElement;

			root.hidden = false;
			root.setAttribute('aria-hidden', 'false');
			requestAnimationFrame(function () { root.classList.add('is-open'); });

			if (card) {
				card.reset();
				setTimeout(function () {
					var field = card.firstField();
					if (field) { try { field.focus(); } catch (e) {} }
				}, 80);
			}

			document.addEventListener('keydown', onKeydown);
		}

		function close() {
			if (!isOpen) { return; }
			isOpen = false;
			root.classList.remove('is-open');
			document.removeEventListener('keydown', onKeydown);

			var done = function () {
				root.hidden = true;
				root.setAttribute('aria-hidden', 'true');
				if (dialog) { dialog.removeEventListener('transitionend', done); }
				if (lastFocus && typeof lastFocus.focus === 'function') { lastFocus.focus(); }
			};

			if (dialog) { dialog.addEventListener('transitionend', done); }
			setTimeout(done, 420);   // Fallback for a transition that never fires.
		}

		/**
		 * Escape closes; Tab is kept inside the dialog.
		 *
		 * The trap matters more here than anywhere else in the theme: this dialog owns a password
		 * field, and tabbing out of it lands on a page the shopper cannot see.
		 */
		function onKeydown(e) {
			if (e.key === 'Escape') {
				close();
				return;
			}
			if (e.key !== 'Tab' || !dialog) {
				return;
			}

			var focusable = dialog.querySelectorAll('a[href], button:not([disabled]), input:not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])');
			var visible = Array.prototype.filter.call(focusable, function (el) {
				return el.offsetParent !== null;
			});
			if (!visible.length) { return; }

			var first = visible[0];
			var last = visible[visible.length - 1];

			if (e.shiftKey && document.activeElement === first) {
				e.preventDefault();
				last.focus();
			} else if (!e.shiftKey && document.activeElement === last) {
				e.preventDefault();
				first.focus();
			}
		}

		root.querySelectorAll('[data-yz-signin-close]').forEach(function (el) {
			el.addEventListener('click', function (e) {
				e.preventDefault();
				close();
			});
		});

		/*
		 * The account icon is Astra header-builder markup — no PHP filter reaches the anchor itself,
		 * and it appears twice (desktop bar and the mobile drawer), so intercept it by delegation.
		 * The dialog only renders for signed-out visitors, so its presence IS the "not signed in"
		 * test. Anything else that wants the dialog can carry data-yz-signin-open.
		 */
		document.addEventListener('click', function (e) {
			var trigger = e.target.closest('.ast-header-account-link, [data-yz-signin-open]');
			if (!trigger) { return; }

			e.preventDefault();
			open();
		});

		window.YazanSignin = window.YazanSignin || {};
		window.YazanSignin.open = open;
		window.YazanSignin.close = close;
	}

	/* ------------------------------------------------------------------ */

	function init() {
		var modalRoot = document.querySelector('[data-yz-signin-modal]');
		var cards = document.querySelectorAll('[data-yz-signin-card]');
		var modalCard = null;

		cards.forEach(function (el) {
			var card = initCard(el);
			if (modalRoot && modalRoot.contains(el)) {
				modalCard = card;
			}
		});

		if (modalRoot) {
			initModal(modalRoot, modalCard);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
