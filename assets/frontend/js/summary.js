/*
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 *
 * Plugixa Car Parking — a booking's summary page: print it, and pay what is owed.
 *
 * Every string a customer reads comes from PHP in `PlugixaCarParkingSummary.i18n`.
 * Other scripts extend the page through `PlugixaCarParking.hooks`; see the top
 * of core.js.
 */
(function () {
  'use strict';

  var PCP = window.PlugixaCarParking;
  var CFG = window.PlugixaCarParkingSummary;
  if (!PCP || !PCP.util || !CFG) return;

  var T = CFG.i18n || {};

  function initSummary(root) {
    var message = root.querySelector('.pcp-pay-msg');

    /**
     * Say how starting a payment went.
     *
     * @param {string} type 'info' or 'error'. Empty to clear.
     * @param {string} text The message.
     */
    function say(type, text) {
      if (!message) return;
      message.textContent = '';
      if (!type || !text) return;
      message.appendChild(PCP.util.el('p', {
        class: 'pcp-alert pcp-alert-' + type,
        role: type === 'error' ? 'alert' : 'status',
        text: text,
      }));
    }

    var summary = {
      root: root,
      config: CFG,
      /** One of the empty containers the markup leaves for other scripts. */
      slot: function (name) { return root.querySelector('[data-pcp-slot="' + name + '"]'); },
      say: say,
    };

    var printBtn = root.querySelector('.pcp-print-btn');
    if (printBtn) {
      printBtn.addEventListener('click', function () { window.print(); });
    }

    var buttons = Array.prototype.slice.call(root.querySelectorAll('.pcp-pay-btn'));

    buttons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var others = buttons.filter(function (b) { return b !== btn; });
        PCP.button.busy(btn, true, T.starting);
        others.forEach(function (b) { b.disabled = true; });
        say('', '');

        var payment = PCP.hooks.emit('summary.paymentRequest', {
          summary: summary,
          // Whether this way of paying takes money now, or is settled in person.
          online: btn.getAttribute('data-online') === '1',
          body: { booking_ref: CFG.ref, booking_key: CFG.key, gateway: btn.getAttribute('data-gateway'), security: CFG.nonce },
        });

        PCP.request(CFG, 'POST', 'payment/start', payment.body).then(function (json) {
          var data = (json && json.data) || {};
          if (data.redirect_url) {
            window.location.href = data.redirect_url;
            return;
          }
          // Nothing to be sent to: this way of paying is settled in person.
          say('info', CFG.offline || T.follow);
          PCP.button.busy(btn, false);
          others.forEach(function (b) { b.disabled = false; });
        }, function (e) {
          // The server's own words when it gave any: "asked too often" included.
          say('error', e.message || T.failed);
          PCP.button.busy(btn, false);
          others.forEach(function (b) { b.disabled = false; });
        });
      });
    });

    PCP.hooks.emit('summary.init', summary);
  }

  function boot() {
    Array.prototype.slice.call(document.querySelectorAll('.pcp-summary[data-pcp-summary]')).forEach(function (root) {
      if (root.getAttribute('data-pcp-init')) return;
      root.setAttribute('data-pcp-init', '1');
      initSummary(root);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
