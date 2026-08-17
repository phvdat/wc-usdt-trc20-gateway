
(function () {
  function setStatus(paid, txid) {
    var box = document.querySelector('.wc-usdt-trc20-status');
    if (!box) return;

    var title = box.querySelector('.wc-usdt-trc20-status-title');
    var message = box.querySelector('.wc-usdt-trc20-status-message');

    if (paid) {
      box.dataset.state = 'paid';
      if (title) title.textContent = 'Payment successful!';
      if (message) message.textContent = 'Your payment has been detected and verified. Your order is being processed.';
      if (txid && !box.querySelector('.wc-usdt-trc20-txid')) {
        var code = document.createElement('code');
        code.className = 'wc-usdt-trc20-txid';
        code.textContent = txid;
        box.appendChild(code);
      }
    } else {
      box.dataset.state = 'waiting';
      if (title) title.textContent = 'Waiting for payment';
      if (message) message.textContent = 'We will automatically detect your payment.';
    }
  }

  function initUsdtGateway() {
    // Copy buttons.
    document.querySelectorAll('.wc-usdt-trc20-payment [data-copy]').forEach(function (button) {
      if (button.dataset.copyReady === '1') return;
      button.addEventListener('click', function () {
        var value = button.getAttribute('data-copy') || '';
        if (!value) return;
        var done = function () {
          var original = button.textContent;
          button.textContent = 'Copied!';
          setTimeout(function () { button.textContent = original; }, 1400);
        };
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(value).then(done).catch(function () {
            fallbackCopy(value, done);
          });
        } else {
          fallbackCopy(value, done);
        }
      });
      button.dataset.copyReady = '1';
    });

    // Tabs.
    document.querySelectorAll('.wc-usdt-trc20-payment .wc-usdt-trc20-tabs').forEach(function (tabs) {
      var root = tabs.closest('.wc-usdt-trc20-card');
      if (!root) return;
      tabs.querySelectorAll('.wc-usdt-trc20-tab').forEach(function (tab) {
        if (tab.dataset.tabReady === '1') return;
        tab.addEventListener('click', function (event) {
          event.preventDefault();
          var name = tab.getAttribute('data-tab');
          tabs.querySelectorAll('.wc-usdt-trc20-tab').forEach(function (t) {
            var active = t === tab;
            t.classList.toggle('is-active', active);
            t.setAttribute('aria-selected', active ? 'true' : 'false');
          });
          root.querySelectorAll('.wc-usdt-trc20-tab-panel').forEach(function (panel) {
            panel.classList.toggle('is-active', panel.getAttribute('data-panel') === name);
          });
        });
        tab.dataset.tabReady = '1';
      });
    });

    // Customer-supplied TXID verification. The server re-checks the blockchain;
    // the TXID is never trusted by itself.
    var verifyButton = document.querySelector('.wc-usdt-trc20-verify-button');
    var verifyInput = document.querySelector('#wc-usdt-trc20-txid-input');
    var verifyResult = document.querySelector('.wc-usdt-trc20-verify-result');
    if (verifyButton && verifyInput && verifyResult && window.WCUSDTTRC20 && WCUSDTTRC20.verifyNonce) {
      verifyButton.addEventListener('click', function () {
        var txid = verifyInput.value.trim();
        if (!/^[a-fA-F0-9]{64}$/.test(txid)) {
          verifyResult.textContent = 'Please enter a valid TRON Transaction ID (TXID).';
          verifyResult.dataset.state = 'error';
          return;
        }

        verifyButton.disabled = true;
        verifyButton.textContent = 'Verifying…';
        verifyResult.textContent = 'Checking the TRON blockchain…';
        verifyResult.dataset.state = 'checking';

        var body = new URLSearchParams();
        body.set('action', 'wc_usdt_trc20_verify_txid');
        body.set('nonce', WCUSDTTRC20.verifyNonce);
        body.set('order_id', WCUSDTTRC20.orderId);
        body.set('order_key', WCUSDTTRC20.orderKey);
        body.set('txid', txid);

        fetch(WCUSDTTRC20.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
          body: body.toString()
        })
        .then(function (response) {
          return response.text().then(function (text) {
            try { return JSON.parse(text); }
            catch (e) { return {success:false, data:{message:'Server returned an invalid response (' + response.status + '). Please try again.'}}; }
          });
        })
        .then(function (json) {
          if (json && json.success) {
            setStatus(true, (json.data && json.data.txid) || txid);
            verifyResult.textContent = (json.data && json.data.message) || 'Payment successful!';
            verifyResult.dataset.state = 'success';
            verifyInput.disabled = true;
            verifyButton.style.display = 'none';
          } else {
            var msg = json && json.data && json.data.message ? json.data.message : 'Unable to verify this transaction. Please check the TXID and try again.';
            verifyResult.textContent = msg;
            verifyResult.dataset.state = 'error';
            verifyButton.disabled = false;
            verifyButton.textContent = 'Verify payment';
          }
        })
        .catch(function () {
          verifyResult.textContent = 'Unable to contact the payment verifier. Please try again.';
          verifyResult.dataset.state = 'error';
          verifyButton.disabled = false;
          verifyButton.textContent = 'Verify payment';
        });
      });
    }

    // Poll WooCommerce order status so the payment page updates automatically
    // when the cron detector changes the order to processing/completed.
    if (window.WCUSDTTRC20 && WCUSDTTRC20.ajaxUrl && WCUSDTTRC20.orderId && WCUSDTTRC20.orderKey) {
      var poll = function () {
        var body = new URLSearchParams();
        body.set('action', 'wc_usdt_trc20_payment_status');
        body.set('nonce', WCUSDTTRC20.nonce);
        body.set('order_id', WCUSDTTRC20.orderId);
        body.set('order_key', WCUSDTTRC20.orderKey);

        fetch(WCUSDTTRC20.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
          body: body.toString()
        })
        .then(function (response) {
          return response.text().then(function (text) {
            try { return JSON.parse(text); }
            catch (e) { return {success:false, data:{message:'Server returned an invalid response (' + response.status + '). Please try again.'}}; }
          });
        })
        .then(function (json) {
          if (!json || !json.success || !json.data) return;
          var paid = !!json.data.paid ||
            ['processing', 'completed'].indexOf(json.data.status) !== -1 ||
            !!json.data.txid;
          setStatus(paid, json.data.txid || '');
        })
        .catch(function () {});
      };

      poll();
      window.setInterval(poll, Number(WCUSDTTRC20.interval || 8000));
    }
  }

  function fallbackCopy(text, done) {
    var input = document.createElement('textarea');
    input.value = text;
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.focus();
    input.select();
    try { document.execCommand('copy'); } finally {
      document.body.removeChild(input);
      done();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initUsdtGateway);
  } else {
    initUsdtGateway();
  }
})();
