/**
 * Yazan — storefront AI concierge.
 *
 * Vanilla, dependency-free, deferred. Talks to yazan-core's public REST endpoint
 * (yazan/v1/ai/chat), which is retrieval-grounded, so any product shown is a real, in-stock piece.
 * Keeps a short in-memory history and a per-tab session id; mirrors the promo-popup fetch pattern.
 */
(function () {
  'use strict';

  var cfg = window.YazanChat;
  if (!cfg || !cfg.rest) return;

  var root = document.querySelector('[data-yz-chat]');
  if (!root) return;

  var S = cfg.strings || {};
  var panel = root.querySelector('[data-yz-chat-panel]');
  var log = root.querySelector('[data-yz-chat-log]');
  var form = root.querySelector('[data-yz-chat-form]');
  var input = root.querySelector('[data-yz-chat-input]');
  var mic = root.querySelector('[data-yz-chat-mic]');
  var toggles = root.querySelectorAll('[data-yz-chat-toggle]');

  var history = [];
  var sending = false;
  var opened = false;

  var sessionId = getSession();

  root.hidden = false;

  toggles.forEach(function (btn) {
    btn.addEventListener('click', toggle);
  });
  form.addEventListener('submit', onSubmit);

  setupNudge();
  setupVoice();

  function toggle() {
    var isOpen = !panel.hasAttribute('hidden');
    if (isOpen) {
      panel.setAttribute('hidden', '');
    } else {
      hideNudge();
      panel.removeAttribute('hidden');
      if (!opened) {
        opened = true;
        if (S.greeting) addMessage('assistant', S.greeting);
        renderChips();
        refreshNonce(); // grab a live nonce so the first message isn't rejected by a stale page nonce
      }
      window.setTimeout(function () { input.focus(); }, 60);
    }
    toggles.forEach(function (b) {
      b.setAttribute('aria-expanded', String(!isOpen));
    });
  }

  function onSubmit(event) {
    event.preventDefault();
    if (sending) return;
    var text = (input.value || '').trim();
    if (!text) return;

    addMessage('user', text);
    history.push({ role: 'user', content: text });
    input.value = '';
    send(text);
  }

  function send(text, isRetry) {
    sending = true;
    var typing = addTyping();

    window
      .fetch(cfg.rest, {
        method: 'POST',
        credentials: 'same-origin',
        // X-WP-Nonce lets WP honor the login cookie → a signed-in shopper is recognised for a
        // personalised reply. Empty/stale simply falls back to anonymous (no error).
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce || '' },
        body: JSON.stringify({
          nonce: cfg.nonce,
          session_id: sessionId,
          message: text,
          history: history.slice(-8)
        })
      })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        // Stale page nonce (caching / expiry / changed login) — fetch a fresh one and retry once, silently.
        if (res && res.error && res.error.code === 'bad_nonce' && !isRetry) {
          typing.remove();
          sending = false;
          refreshNonce().then(function () { send(text, true); });
          return;
        }
        typing.remove();
        sending = false;
        if (!res || res.ok === false) {
          addMessage('assistant', (res && res.message) || S.error);
          return;
        }
        if (res.reply) {
          addMessage('assistant', res.reply);
          history.push({ role: 'assistant', content: res.reply });
        }
        if (Array.isArray(res.products) && res.products.length) {
          addProducts(res.products);
        }
      })
      .catch(function () {
        typing.remove();
        sending = false;
        addMessage('assistant', S.error);
      });
  }

  /* ---------- quick replies ---------- */

  // Tasteful suggestion chips under the greeting. A chip either sends a `prompt` or runs a client `action`.
  function renderChips() {
    var chips = S.chips;
    if (!chips || !chips.length) return;
    var wrap = document.createElement('div');
    wrap.className = 'yz-chat__chips';
    chips.forEach(function (c) {
      if (!c || (!c.prompt && !c.action)) return;
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'yz-chat__chip';
      b.textContent = c.label || c.prompt;
      b.addEventListener('click', function () {
        wrap.remove();
        onChip(c);
      });
      wrap.appendChild(b);
    });
    log.appendChild(wrap);
    scroll();
  }

  function onChip(c) {
    if (c.action === 'size') return sizeAdvisor();
    if (c.action === 'handoff') return handoff();
    if (sending || !c.prompt) return;
    addMessage('user', c.prompt);
    history.push({ role: 'user', content: c.prompt });
    send(c.prompt);
  }

  /* ---------- advisors & handoff ---------- */

  // Ring-size help — answered instantly client-side (no LLM/quota), then invites a follow-up.
  function sizeAdvisor() {
    if (S.sizeGuide) {
      addMessage('assistant', S.sizeGuide);
      history.push({ role: 'assistant', content: S.sizeGuide });
    }
  }

  // Escalate to a human: POST the transcript (owner email + optional CRM), then open WhatsApp if configured.
  function handoff() {
    if (sending || !cfg.handoff) return;
    sending = true;
    var typing = addTyping();
    window
      .fetch(cfg.handoff, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce || '' },
        body: JSON.stringify({ nonce: cfg.nonce, session_id: sessionId, history: history.slice(-20) })
      })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        typing.remove();
        sending = false;
        if (res && res.error && res.error.code === 'bad_nonce') {
          return refreshNonce().then(function () { /* one silent retry */ handoff(); });
        }
        addMessage('assistant', (res && res.message) || S.error);
        if (res && res.whatsapp_url) window.open(res.whatsapp_url, '_blank', 'noopener');
      })
      .catch(function () {
        typing.remove();
        sending = false;
        addMessage('assistant', S.error);
      });
  }

  /* ---------- voice input (progressive enhancement) ---------- */

  function setupVoice() {
    if (!mic) return;
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) return; // unsupported browser → leave the mic hidden
    mic.removeAttribute('hidden');

    var rec = new SR();
    rec.lang = cfg.rtl ? 'ar-SA' : 'en-US';
    rec.interimResults = true;
    rec.maxAlternatives = 1;
    var listening = false;

    rec.addEventListener('result', function (e) {
      var t = '';
      for (var i = 0; i < e.results.length; i++) t += e.results[i][0].transcript;
      input.value = t;
    });
    rec.addEventListener('end', function () {
      listening = false;
      mic.classList.remove('is-listening');
      if ((input.value || '').trim()) form.requestSubmit ? form.requestSubmit() : onSubmit(new Event('submit'));
    });
    rec.addEventListener('error', function () { listening = false; mic.classList.remove('is-listening'); });

    mic.addEventListener('click', function () {
      if (listening) { rec.stop(); return; }
      try {
        input.value = '';
        listening = true;
        mic.classList.add('is-listening');
        rec.start();
      } catch (err) { listening = false; mic.classList.remove('is-listening'); }
    });
  }

  /* ---------- attention nudge ---------- */

  // A gentle, once-per-session invitation above the launcher — never re-shows after open/dismiss.
  function setupNudge() {
    var nudge = root.querySelector('[data-yz-chat-nudge]');
    if (!nudge) return;
    var openEl = nudge.querySelector('[data-yz-chat-nudge-open]');
    var closeEl = nudge.querySelector('[data-yz-chat-nudge-close]');
    if (openEl) openEl.addEventListener('click', function () { if (panel.hasAttribute('hidden')) toggle(); });
    if (closeEl) closeEl.addEventListener('click', dismissNudge);

    if (nudgeDismissed()) return;
    window.setTimeout(function () {
      if (!opened && !nudgeDismissed()) nudge.removeAttribute('hidden');
    }, 7000);
  }

  function hideNudge() {
    var nudge = root.querySelector('[data-yz-chat-nudge]');
    if (nudge) nudge.setAttribute('hidden', '');
  }

  function dismissNudge() {
    hideNudge();
    try { window.sessionStorage.setItem('yzChatNudge', '1'); } catch (e) { /* ignore */ }
  }

  function nudgeDismissed() {
    try { return window.sessionStorage.getItem('yzChatNudge') === '1'; } catch (e) { return false; }
  }

  /* ---------- rendering ---------- */

  function addMessage(role, text) {
    var el = document.createElement('div');
    el.className = 'yz-chat__msg yz-chat__msg--' + role;
    el.textContent = text;
    log.appendChild(el);
    scroll();
    return el;
  }

  function addTyping() {
    var el = document.createElement('div');
    el.className = 'yz-chat__msg yz-chat__msg--assistant yz-chat__typing';
    el.innerHTML = '<span></span><span></span><span></span>';
    log.appendChild(el);
    scroll();
    return el;
  }

  function addProducts(products) {
    var wrap = document.createElement('div');
    wrap.className = 'yz-chat__cards';
    products.forEach(function (p) {
      var a = document.createElement('a');
      a.className = 'yz-chat__card';
      a.href = p.url;
      a.rel = 'noopener';

      if (p.thumb) {
        var img = document.createElement('img');
        img.className = 'yz-chat__card-img';
        img.src = p.thumb;
        img.alt = '';
        img.loading = 'lazy';
        a.appendChild(img);
      }

      var body = document.createElement('span');
      body.className = 'yz-chat__card-body';

      var name = document.createElement('span');
      name.className = 'yz-chat__card-name';
      name.textContent = p.name;
      body.appendChild(name);

      if (p.price) {
        var price = document.createElement('span');
        price.className = 'yz-chat__card-price';
        price.innerHTML = p.price; // Server-built price HTML (wc_price), already sanitized.
        body.appendChild(price);
      }

      var cta = document.createElement('span');
      cta.className = 'yz-chat__card-cta';
      cta.textContent = S.view || 'View';
      body.appendChild(cta);

      a.appendChild(body);
      wrap.appendChild(a);
    });
    log.appendChild(wrap);
    scroll();
  }

  function scroll() {
    log.scrollTop = log.scrollHeight;
  }

  /* ---------- nonce ---------- */

  // Fetch a fresh chat nonce (best-effort). The page-embedded one can go stale from caching, the
  // 12–24h nonce lifetime, or a changed login session; this keeps the concierge from dead-ending.
  function refreshNonce() {
    if (!cfg.nonceRest) return Promise.resolve();
    return window
      .fetch(cfg.nonceRest, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d && d.nonce) cfg.nonce = d.nonce; })
      .catch(function () {});
  }

  /* ---------- session ---------- */

  function getSession() {
    var key = 'yzChatSession';
    try {
      var existing = window.sessionStorage.getItem(key);
      if (existing) return existing;
      var id = 's_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
      window.sessionStorage.setItem(key, id);
      return id;
    } catch (e) {
      return 's_' + Date.now().toString(36);
    }
  }
})();
