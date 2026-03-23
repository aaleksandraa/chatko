(function () {
  var script = document.currentScript;
  if (!script) {
    return;
  }

  var publicKey = script.getAttribute('data-key');
  if (!publicKey) {
    console.error('Chatko Widget: missing data-key attribute.');
    return;
  }

  var apiBase = (script.getAttribute('data-api-base') || '').replace(/\/$/, '');
  if (!apiBase) {
    apiBase = window.location.origin;
  }

  var VISITOR_UUID_STORAGE_KEY = 'wizsales_visitor_uuid';
  var storedVisitorUuid = localStorage.getItem(VISITOR_UUID_STORAGE_KEY);
  var initialVisitorUuid = isUuid(storedVisitorUuid) ? storedVisitorUuid : createId();

  var state = {
    conversationId: null,
    sessionId: null,
    sessionToken: null,
    visitorUuid: initialVisitorUuid,
    config: null,
    checkout: null,
    challenge: {
      enabled: false,
      provider: null,
      siteKey: null,
      action: 'widget_session_start',
      scriptUrl: null,
      widgetId: null,
      scriptPromise: null,
      pending: null,
    },
  };

  localStorage.setItem(VISITOR_UUID_STORAGE_KEY, state.visitorUuid);

  function createId() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }

    // RFC4122-ish v4 fallback for older browsers.
    var template = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx';
    return template.replace(/[xy]/g, function (char) {
      var random = Math.floor(Math.random() * 16);
      var value = char === 'x' ? random : ((random & 0x3) | 0x8);
      return value.toString(16);
    });
  }

  function isUuid(value) {
    var text = String(value || '').trim();
    return /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(text);
  }

  function endpoint(path) {
    return apiBase + path;
  }

  function createUI() {
    var root = document.createElement('div');
    root.id = 'wizsales-root';
    root.innerHTML =
      '<style>' +
      '#wizsales-root{position:fixed;right:12px;bottom:12px;z-index:99999;font-family:Arial,sans-serif;max-width:calc(100vw - 24px);}' +
      '#wizsales-toggle{background:#0E9F6E;color:#fff;border:none;padding:12px 16px;border-radius:999px;cursor:pointer;font-weight:600;}' +
      '#wizsales-panel{display:none;flex-direction:column;width:min(380px,calc(100vw - 24px));height:min(680px,calc(100vh - 24px));margin-top:8px;background:#fff;border:1px solid #ddd;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.18);overflow:hidden;}' +
      '#wizsales-header{background:#063F2B;color:#fff;padding:12px 14px;font-weight:700;}' +
      '#wizsales-messages{flex:1;min-height:140px;overflow:auto;padding:12px;background:#f7f8f8;}' +
      '.wiz-msg{margin:8px 0;max-width:88%;padding:8px 10px;border-radius:10px;line-height:1.4;font-size:14px;}' +
      '.wiz-user{background:#dff6eb;margin-left:auto;}' +
      '.wiz-ai{background:#fff;border:1px solid #e6e6e6;}' +
      '.wiz-product{margin-top:8px;padding:8px;border:1px solid #e6e6e6;border-radius:8px;background:#fff;}' +
      '.wiz-order{margin-top:8px;padding:9px;border:1px solid #d8eadf;border-radius:8px;background:#f3fcf7;}' +
      '.wiz-order a{display:inline-block;margin-top:6px;}' +
      '#wizsales-checkout{padding:10px;border-top:1px solid #efefef;background:#fafafa;max-height:min(220px,38vh);overflow:auto;}' +
      '.wiz-checkout-title{font-size:12px;font-weight:700;color:#063F2B;margin-bottom:6px;}' +
      '.wiz-checkout-row{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:6px;}' +
      '.wiz-checkout-row.single{grid-template-columns:1fr;}' +
      '.wiz-checkout-row input,.wiz-checkout-row select,.wiz-checkout-row textarea{width:100%;padding:6px;border:1px solid #cfd7d4;border-radius:6px;font-size:12px;}' +
      '.wiz-checkout-actions{display:flex;gap:6px;margin-top:6px;}' +
      '.wiz-btn{border:none;border-radius:6px;padding:7px 9px;font-size:12px;cursor:pointer;}' +
      '.wiz-btn-primary{background:#0E9F6E;color:#fff;}' +
      '.wiz-btn-secondary{background:#fff;color:#063F2B;border:1px solid #95b3a7;}' +
      '.wiz-checkout-meta{font-size:12px;color:#444;margin-bottom:6px;}' +
      '#wizsales-input-wrap{display:grid;grid-template-columns:1fr;gap:8px;padding:10px;border-top:1px solid #eee;background:#fff;}' +
      '#wizsales-input{width:100%;box-sizing:border-box;padding:10px;border:1px solid #ccc;border-radius:8px;}' +
      '#wizsales-send{width:100%;box-sizing:border-box;background:#0E9F6E;color:#fff;border:none;border-radius:8px;padding:10px 12px;cursor:pointer;font-weight:600;}' +
      '@media (max-width:520px){#wizsales-root{left:10px;right:10px;bottom:10px;max-width:none;}#wizsales-toggle{width:100%;border-radius:12px;}#wizsales-panel{width:100%;height:min(78vh,620px);} .wiz-checkout-row{grid-template-columns:1fr;}}' +
      '</style>' +
      '<button id="wizsales-toggle">Pomoc pri izboru</button>' +
      '<div id="wizsales-panel">' +
      '  <div id="wizsales-header">Pomoc pri izboru</div>' +
      '  <div id="wizsales-messages"></div>' +
      '  <div id="wizsales-checkout" style="display:none;"></div>' +
      '  <div id="wizsales-challenge-anchor" style="display:none;"></div>' +
      '  <div id="wizsales-input-wrap">' +
      '    <input id="wizsales-input" type="text" placeholder="Npr: Treba mi serum do 40 KM" />' +
      '    <button id="wizsales-send">Posalji</button>' +
      '  </div>' +
      '</div>';

    document.body.appendChild(root);

    var toggle = root.querySelector('#wizsales-toggle');
    var panel = root.querySelector('#wizsales-panel');
    var messages = root.querySelector('#wizsales-messages');
    var checkoutWrap = root.querySelector('#wizsales-checkout');
    var challengeAnchor = root.querySelector('#wizsales-challenge-anchor');
    var input = root.querySelector('#wizsales-input');
    var send = root.querySelector('#wizsales-send');

    function addMessage(text, role) {
      var el = document.createElement('div');
      el.className = 'wiz-msg ' + (role === 'user' ? 'wiz-user' : 'wiz-ai');
      el.textContent = text;
      messages.appendChild(el);
      messages.scrollTop = messages.scrollHeight;
      return el;
    }

    function addProducts(items) {
      if (!items || !items.length) {
        return;
      }

      items.forEach(function (item) {
        var wrap = document.createElement('div');
        wrap.className = 'wiz-product';

        var title = document.createElement('div');
        title.style.fontWeight = '700';
        title.textContent = item.name || 'Proizvod';

        var price = document.createElement('div');
        price.textContent = (item.price || 0) + ' ' + (item.currency || 'BAM');

        wrap.appendChild(title);
        wrap.appendChild(price);

        if (item.url) {
          var link = document.createElement('a');
          link.href = item.url;
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
          link.textContent = 'Otvori proizvod';
          wrap.appendChild(link);
        }

        messages.appendChild(wrap);
      });

      messages.scrollTop = messages.scrollHeight;
    }

    function addOrderConfirmation(order) {
      if (!order) {
        return;
      }

      var wrap = document.createElement('div');
      wrap.className = 'wiz-order';
      var id = order.external_order_id ? ' #' + order.external_order_id : '';
      wrap.innerHTML =
        '<div><strong>Narudzba kreirana' + id + '</strong></div>' +
        '<div>Ukupno: ' + (order.total || 0) + ' ' + (order.currency || 'BAM') + '</div>';

      if (order.checkout_url) {
        var link = document.createElement('a');
        link.href = order.checkout_url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = 'Nastavi na placanje';
        wrap.appendChild(link);
      }

      messages.appendChild(wrap);
      messages.scrollTop = messages.scrollHeight;
    }

    function friendlyErrorMessage(rawMessage, fallback) {
      var message = String(rawMessage || '').trim();
      if (!message) {
        return fallback || 'Doslo je do greske pri komunikaciji.';
      }

      if (message.indexOf('Widget origin is not allowed') !== -1) {
        return 'Widget nije dozvoljen na ovoj domeni. Dodajte trenutni domen u Allowed Domains (npr. http://localhost:5173) ili testirajte na dozvoljenoj domeni.';
      }
      if (message.indexOf('Invalid widget key') !== -1 || message.indexOf('Widget not found') !== -1) {
        return 'Neispravan widget public key. Provjerite data-key u skripti.';
      }
      if (message.indexOf('Invalid widget session token') !== -1) {
        return 'Chat sesija je istekla ili nije validna. Osvjezite stranicu i pokusajte ponovo.';
      }
      if (message.indexOf('Challenge verification failed') !== -1 || message.indexOf('missing_challenge_token') !== -1) {
        return 'Anti-bot provjera nije prosla. Provjerite Turnstile/hCaptcha podesavanja.';
      }
      if (message.indexOf('Too Many Requests') !== -1) {
        return 'Previse zahtjeva u kratkom periodu. Pokusajte ponovo za nekoliko sekundi.';
      }

      return message;
    }

    async function extractApiErrorMessage(response, fallback) {
      try {
        var payload = await response.json();
        if (payload && typeof payload.message === 'string' && payload.message.trim()) {
          return friendlyErrorMessage(payload.message, fallback);
        }
        var nested = payload && payload.error && payload.error.message ? String(payload.error.message) : '';
        if (nested.trim()) {
          return friendlyErrorMessage(nested, fallback);
        }
      } catch (error) {
        // Non-JSON response body. Fallback to status text below.
      }

      var statusText = String(response.statusText || '').trim();
      if (statusText) {
        return friendlyErrorMessage(statusText, fallback);
      }

      return fallback || 'Doslo je do greske pri komunikaciji.';
    }

    function paymentOptions(methods) {
      var list = methods && methods.length ? methods : ['cod', 'online'];
      return list
        .map(function (method) {
          var label = method === 'cod' ? 'Pouzece' : 'Online';
          return '<option value="' + escapeHtml(method) + '">' + escapeHtml(label) + '</option>';
        })
        .join('');
    }

    function renderCheckoutPanel(checkout) {
      state.checkout = checkout || null;

      if (!checkout || checkout.status === 'cancelled') {
        checkoutWrap.style.display = 'none';
        checkoutWrap.innerHTML = '';
        return;
      }

      checkoutWrap.style.display = 'block';

      var customer = checkout.customer || {};
      var canConfirm = Boolean(checkout.can_confirm) && checkout.status !== 'placed';
      var missing = Array.isArray(checkout.missing_fields) ? checkout.missing_fields.join(', ') : '';
      var placed = checkout.status === 'placed';

      checkoutWrap.innerHTML =
        '<div class="wiz-checkout-title">Chat Checkout</div>' +
        '<div class="wiz-checkout-meta">Status: ' + escapeHtml(String(checkout.status || 'collecting_customer')) + '</div>' +
        (missing ? '<div class="wiz-checkout-meta">Nedostaje: ' + escapeHtml(missing) + '</div>' : '') +
        '<div class="wiz-checkout-meta">Ukupno: ' + escapeHtml(String(checkout.estimated_total || 0)) + ' ' + escapeHtml(String(checkout.currency || 'BAM')) + '</div>' +
        '<div class="wiz-checkout-row">' +
        '  <input id="wiz-checkout-name" type="text" placeholder="Ime i prezime" value="' + escapeHtml(customer.name || '') + '"' + (placed ? ' disabled' : '') + ' />' +
        '  <input id="wiz-checkout-phone" type="text" placeholder="Telefon" value="' + escapeHtml(customer.phone || '') + '"' + (placed ? ' disabled' : '') + ' />' +
        '</div>' +
        '<div class="wiz-checkout-row">' +
        '  <input id="wiz-checkout-email" type="email" placeholder="Email (opciono za pouzece)" value="' + escapeHtml(customer.email || '') + '"' + (placed ? ' disabled' : '') + ' />' +
        '  <select id="wiz-checkout-payment"' + (placed ? ' disabled' : '') + '>' + paymentOptions(checkout.available_payment_methods) + '</select>' +
        '</div>' +
        '<div class="wiz-checkout-row single">' +
        '  <input id="wiz-checkout-address" type="text" placeholder="Adresa dostave" value="' + escapeHtml(customer.delivery_address || '') + '"' + (placed ? ' disabled' : '') + ' />' +
        '</div>' +
        '<div class="wiz-checkout-row">' +
        '  <input id="wiz-checkout-city" type="text" placeholder="Grad" value="' + escapeHtml(customer.delivery_city || '') + '"' + (placed ? ' disabled' : '') + ' />' +
        '  <input id="wiz-checkout-postal" type="text" placeholder="Postanski broj" value="' + escapeHtml(customer.delivery_postal_code || '') + '"' + (placed ? ' disabled' : '') + ' />' +
        '</div>' +
        '<div class="wiz-checkout-row">' +
        '  <input id="wiz-checkout-country" type="text" placeholder="Drzava (BA)" value="' + escapeHtml(customer.delivery_country || 'BA') + '"' + (placed ? ' disabled' : '') + ' />' +
        '  <input id="wiz-checkout-note" type="text" placeholder="Napomena" value="' + escapeHtml(customer.note || '') + '"' + (placed ? ' disabled' : '') + ' />' +
        '</div>' +
        '<div class="wiz-checkout-actions">' +
        '  <button id="wiz-checkout-save" class="wiz-btn wiz-btn-secondary" type="button"' + (placed ? ' disabled' : '') + '>Sacuvaj</button>' +
        '  <button id="wiz-checkout-confirm" class="wiz-btn wiz-btn-primary" type="button"' + (canConfirm ? '' : ' disabled') + '>Potvrdi narudzbu</button>' +
        '</div>';

      var paymentSelect = checkoutWrap.querySelector('#wiz-checkout-payment');
      if (paymentSelect) {
        paymentSelect.value = checkout.payment_method || 'cod';
      }

      var saveButton = checkoutWrap.querySelector('#wiz-checkout-save');
      if (saveButton) {
        saveButton.addEventListener('click', async function () {
          try {
            await ensureSession();

            var payload = {
              public_key: publicKey,
              conversation_id: state.conversationId,
              widget_session_token: state.sessionToken,
              customer_name: checkoutWrap.querySelector('#wiz-checkout-name').value.trim(),
              customer_phone: checkoutWrap.querySelector('#wiz-checkout-phone').value.trim(),
              customer_email: checkoutWrap.querySelector('#wiz-checkout-email').value.trim(),
              payment_method: checkoutWrap.querySelector('#wiz-checkout-payment').value,
              delivery_address: checkoutWrap.querySelector('#wiz-checkout-address').value.trim(),
              delivery_city: checkoutWrap.querySelector('#wiz-checkout-city').value.trim(),
              delivery_postal_code: checkoutWrap.querySelector('#wiz-checkout-postal').value.trim(),
              delivery_country: checkoutWrap.querySelector('#wiz-checkout-country').value.trim() || 'BA',
              customer_note: checkoutWrap.querySelector('#wiz-checkout-note').value.trim(),
              items: state.checkout && state.checkout.items ? state.checkout.items.map(function (item) {
                return {
                  product_id: item.product_id,
                  quantity: item.quantity || 1,
                };
              }) : [],
            };

            var saveResponse = await fetch(endpoint('/api/widget/checkout'), {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
              body: JSON.stringify(payload),
            });

            if (!saveResponse.ok) {
              addMessage(await extractApiErrorMessage(saveResponse, 'Nisam uspio sacuvati checkout podatke.'), 'ai');
              return;
            }

            var saveData = await saveResponse.json();
            if (saveData && saveData.data && saveData.data.message) {
              addMessage(saveData.data.message, 'ai');
            }
            renderCheckoutPanel(saveData && saveData.data ? saveData.data.checkout : null);
          } catch (error) {
            addMessage('Doslo je do greske pri cuvanju checkout podataka.', 'ai');
          }
        });
      }

      var confirmButton = checkoutWrap.querySelector('#wiz-checkout-confirm');
      if (confirmButton) {
        confirmButton.addEventListener('click', async function () {
          try {
            await ensureSession();

            var response = await fetch(endpoint('/api/widget/checkout/confirm'), {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
              body: JSON.stringify({
                public_key: publicKey,
                conversation_id: state.conversationId,
                widget_session_token: state.sessionToken,
              }),
            });

            if (!response.ok) {
              addMessage(await extractApiErrorMessage(response, 'Potvrda narudzbe nije uspjela. Provjeri podatke i pokusaj opet.'), 'ai');
              return;
            }

            var data = await response.json();
            if (data && data.data && data.data.message) {
              addMessage(data.data.message, 'ai');
            }

            if (data && data.data && data.data.order) {
              addOrderConfirmation(data.data.order);
            }

            renderCheckoutPanel(data && data.data ? data.data.checkout : null);
          } catch (error) {
            addMessage('Doslo je do greske pri potvrdi narudzbe.', 'ai');
          }
        });
      }
    }

    function challengeApiObject(provider) {
      if (provider === 'turnstile') {
        return window.turnstile || null;
      }
      if (provider === 'hcaptcha') {
        return window.hcaptcha || null;
      }
      return null;
    }

    function challengeDefaultScript(provider) {
      if (provider === 'hcaptcha') {
        return 'https://js.hcaptcha.com/1/api.js?render=explicit';
      }
      return 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
    }

    function resolveChallengeScriptUrl() {
      var configured = state.challenge.scriptUrl;
      if (configured) {
        return configured;
      }
      return challengeDefaultScript(state.challenge.provider);
    }

    function ensureChallengeScriptLoaded() {
      if (!state.challenge.enabled) {
        return Promise.resolve();
      }

      if (challengeApiObject(state.challenge.provider)) {
        return Promise.resolve();
      }

      if (state.challenge.scriptPromise) {
        return state.challenge.scriptPromise;
      }

      var scriptUrl = resolveChallengeScriptUrl();
      state.challenge.scriptPromise = new Promise(function (resolve, reject) {
        var tag = document.createElement('script');
        tag.src = scriptUrl;
        tag.async = true;
        tag.defer = true;
        tag.onload = function () {
          if (challengeApiObject(state.challenge.provider)) {
            resolve();
            return;
          }
          reject(new Error('Challenge script loaded but provider API missing.'));
        };
        tag.onerror = function () {
          reject(new Error('Challenge script failed to load.'));
        };
        document.head.appendChild(tag);
      }).catch(function (error) {
        state.challenge.scriptPromise = null;
        throw error;
      });

      return state.challenge.scriptPromise;
    }

    function resolveChallengeSuccess(token) {
      if (!state.challenge.pending) {
        return;
      }
      var pending = state.challenge.pending;
      state.challenge.pending = null;
      window.clearTimeout(pending.timeoutId);

      var normalized = String(token || '').trim();
      if (!normalized) {
        pending.reject(new Error('Challenge returned an empty token.'));
        return;
      }

      pending.resolve(normalized);
    }

    function resolveChallengeFailure(message) {
      if (!state.challenge.pending) {
        return;
      }
      var pending = state.challenge.pending;
      state.challenge.pending = null;
      window.clearTimeout(pending.timeoutId);
      pending.reject(new Error(message || 'Challenge verification failed.'));
    }

    function ensureChallengeWidget(api) {
      if (state.challenge.widgetId !== null && state.challenge.widgetId !== undefined) {
        return;
      }

      if (!challengeAnchor) {
        throw new Error('Challenge anchor missing in widget UI.');
      }

      var provider = state.challenge.provider;
      var options = {
        sitekey: state.challenge.siteKey,
        size: 'invisible',
        callback: function (token) {
          resolveChallengeSuccess(token);
        },
        'error-callback': function () {
          resolveChallengeFailure('Challenge provider returned an error.');
        },
        'expired-callback': function () {
          resolveChallengeFailure('Challenge token expired before verification.');
        },
      };

      if (provider === 'turnstile') {
        if (state.challenge.action) {
          options.action = state.challenge.action;
        }
        state.challenge.widgetId = api.render(challengeAnchor, options);
        return;
      }

      if (provider === 'hcaptcha') {
        state.challenge.widgetId = api.render(challengeAnchor, options);
        return;
      }

      throw new Error('Unsupported challenge provider: ' + provider);
    }

    function executeChallenge() {
      var provider = state.challenge.provider;
      var api = challengeApiObject(provider);
      if (!api) {
        return Promise.reject(new Error('Challenge API is not available.'));
      }

      ensureChallengeWidget(api);

      if (state.challenge.pending) {
        resolveChallengeFailure('Challenge interrupted.');
      }

      return new Promise(function (resolve, reject) {
        var timeoutId = window.setTimeout(function () {
          resolveChallengeFailure('Challenge timed out. Please try again.');
        }, 12000);

        state.challenge.pending = {
          resolve: resolve,
          reject: reject,
          timeoutId: timeoutId,
        };

        try {
          if (typeof api.reset === 'function') {
            api.reset(state.challenge.widgetId);
          }
          api.execute(state.challenge.widgetId);
        } catch (error) {
          resolveChallengeFailure('Challenge execution failed.');
        }
      });
    }

    async function getChallengeTokenIfNeeded() {
      if (!state.challenge.enabled) {
        return null;
      }

      if (!state.challenge.provider || !state.challenge.siteKey) {
        throw new Error('Challenge is enabled but provider/site key is missing.');
      }

      await ensureChallengeScriptLoaded();
      return executeChallenge();
    }

    async function ensureSession() {
      if (state.conversationId) {
        return;
      }

      var challengeToken = null;
      try {
        challengeToken = await getChallengeTokenIfNeeded();
      } catch (error) {
        throw new Error('Challenge verification failed before session start.');
      }

      var startPayload = {
        public_key: publicKey,
        visitor_uuid: state.visitorUuid,
        source_url: window.location.href,
        locale: 'bs',
      };
      if (challengeToken) {
        startPayload.challenge_token = challengeToken;
      }

      var res = await fetch(endpoint('/api/widget/session/start'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(startPayload),
      });

      if (!res.ok) {
        throw new Error(await extractApiErrorMessage(res, 'Pokretanje chat sesije nije uspjelo.'));
      }

      var data = await res.json();
      state.conversationId = data.data.conversation_id;
      state.sessionId = data.data.session_id;
      state.sessionToken = data.data.widget_session_token || state.sessionToken;
      state.visitorUuid = data.data.visitor_uuid || state.visitorUuid;
      if (isUuid(state.visitorUuid)) {
        localStorage.setItem(VISITOR_UUID_STORAGE_KEY, state.visitorUuid);
      }
    }

    async function sendMessage() {
      var text = input.value.trim();
      if (!text) {
        return;
      }

      input.value = '';
      addMessage(text, 'user');
      var typing = addMessage('Pisemo odgovor...', 'ai');

      try {
        await ensureSession();

        var res = await fetch(endpoint('/api/widget/message'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({
            public_key: publicKey,
            conversation_id: state.conversationId,
            session_id: state.sessionId,
            visitor_uuid: state.visitorUuid,
            widget_session_token: state.sessionToken,
            source_url: window.location.href,
            locale: 'bs',
            message: text,
          }),
        });

        typing.remove();

        if (!res.ok) {
          addMessage(await extractApiErrorMessage(res, 'Trenutno ne mogu odgovoriti. Pokusaj ponovo.'), 'ai');
          return;
        }

        var data = await res.json();
        state.conversationId = data.data.conversation_id;
        state.sessionId = data.data.session_id;
        state.sessionToken = data.data.widget_session_token || state.sessionToken;

        addMessage(data.data.answer_text || 'Nisam siguran, mozes li malo detaljnije?', 'ai');
        addProducts(data.data.recommended_products || []);

        if (data.data.order) {
          addOrderConfirmation(data.data.order);
        }

        renderCheckoutPanel(data.data.checkout || null);
      } catch (err) {
        typing.remove();
        addMessage(friendlyErrorMessage(err && err.message ? err.message : '', 'Doslo je do greske pri komunikaciji.'), 'ai');
      }
    }

    toggle.addEventListener('click', function () {
      var visible = panel.style.display === 'flex';
      panel.style.display = visible ? 'none' : 'flex';
      toggle.textContent = visible ? 'Pomoc pri izboru' : 'Zatvori chat';
      if (!visible && !messages.children.length) {
        addMessage('Zdravo! Tu sam da pomognem oko izbora proizvoda i da zavrsimo narudzbu kroz chat.', 'ai');
      }
    });

    send.addEventListener('click', sendMessage);
    input.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') {
        sendMessage();
      }
    });
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  async function loadConfig() {
    try {
      var res = await fetch(endpoint('/api/widget/config/' + encodeURIComponent(publicKey)));
      if (res.ok) {
        var data = await res.json();
        state.config = data.data;
        var challenge = state.config && state.config.challenge ? state.config.challenge : null;
        state.challenge.enabled = Boolean(challenge && challenge.enabled);
        state.challenge.provider = challenge && challenge.provider ? String(challenge.provider) : null;
        state.challenge.siteKey = challenge && challenge.site_key ? String(challenge.site_key) : null;
        state.challenge.action = challenge && challenge.action ? String(challenge.action) : 'widget_session_start';
        state.challenge.scriptUrl = challenge && challenge.script_url ? String(challenge.script_url) : null;
      }
    } catch (err) {
      // Widget can run with fallback styles even if config fails.
    }

    createUI();
  }

  loadConfig();
})();
