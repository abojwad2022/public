/**
 * Yazan — wishlist (favourites) hearts.
 *
 * Delegated click handling so it covers hearts anywhere on the page (single product, shop cards,
 * account grid). Signed-in shoppers toggle via the /wishlist/toggle REST route; guests are sent to
 * sign in. Vanilla, dependency-free, deferred.
 */
(function () {
  'use strict';

  var cfg = window.YazanWish;
  if (!cfg || !cfg.toggle) return;

  // wp_localize_script() casts every value to a STRING, so a signed-out visitor arrives as
  // loggedIn:"0" — and "0" is truthy in JS. Testing !cfg.loggedIn therefore never caught guests:
  // they fell through to the fetch, got a 401 whose body carries no `ok` key, and the handler
  // silently did nothing instead of sending them to sign in. Compare against the string.
  var loggedIn = cfg.loggedIn === '1' || cfg.loggedIn === 1 || cfg.loggedIn === true;

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-yz-wish]') : null;
    if (!btn) return;
    e.preventDefault();

    // Guests can't persist a wishlist — invite them to sign in.
    if (!loggedIn) {
      if (cfg.account) window.location.href = cfg.account;
      return;
    }
    if (btn.classList.contains('is-busy')) return;

    var id = parseInt(btn.getAttribute('data-yz-wish'), 10);
    if (!id) return;

    btn.classList.add('is-busy');
    window
      .fetch(cfg.toggle, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
        body: JSON.stringify({ product_id: id })
      })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        btn.classList.remove('is-busy');
        if (!res || res.ok === false) return;
        var on = !!res.in;

        // Reflect the new state on every heart for this product (loop + single + account can co-exist).
        var all = document.querySelectorAll('[data-yz-wish="' + id + '"]');
        for (var i = 0; i < all.length; i++) {
          all[i].classList.toggle('is-active', on);
          all[i].setAttribute('aria-pressed', on ? 'true' : 'false');
        }

        // On the account grid, un-favouriting removes the card.
        if (!on) {
          var card = btn.closest ? btn.closest('.yz-wish-card') : null;
          if (card && card.parentNode) card.parentNode.removeChild(card);
        }
      })
      .catch(function () { btn.classList.remove('is-busy'); });
  });
})();
