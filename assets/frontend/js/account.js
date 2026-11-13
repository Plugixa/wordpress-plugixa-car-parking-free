/*
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 *
 * Plugixa Car Parking — the customer's own bookings (vanilla JS).
 *
 * A signed-in customer sees their list. A guest finds one booking by its
 * reference and the email it was made with.
 *
 * Every string a customer reads comes from PHP in `PlugixaCarParkingAccount.i18n`.
 * Other scripts add actions to a booking through `PlugixaCarParking.hooks`; see
 * the top of core.js.
 */
(function () {
  'use strict';

  var PCP = window.PlugixaCarParking;
  var CFG = window.PlugixaCarParkingAccount;
  if (!PCP || !PCP.util || !CFG) return;

  var U = PCP.util;
  var el = U.el;
  var hooks = PCP.hooks;
  var T = CFG.i18n || {};

  function initAccount(root) {
    // The email a guest looked a booking up with: each change to it is
    // authorised by sending the same one again.
    var guest = { ref: '', email: '' };
    var notice = null;
    var listEl = null;

    /* ------------------------------------------------------------------
     * Messages
     * ---------------------------------------------------------------- */
    /**
     * Say how something went, above the list.
     *
     * A success is a status a screen reader reads when it is free to; a failure
     * is an alert it reads at once. They look different too, so "cancelled" and
     * "could not cancel" cannot be mistaken for each other.
     *
     * @param {string} type 'success', 'error' or 'info'. Empty to clear.
     * @param {string} text The message.
     */
    function notify(type, text) {
      notice = type && text ? { type: type, text: text } : null;
      drawNotice();
    }

    function drawNotice() {
      var box = root.querySelector('.pcp-acc-notice');
      if (!box) return;
      box.textContent = '';
      if (!notice) return;
      box.appendChild(el('p', {
        class: 'pcp-alert pcp-alert-' + notice.type,
        role: notice.type === 'error' ? 'alert' : 'status',
        text: notice.text,
      }));
    }

    function shell(title, children) {
      root.textContent = '';
      root.appendChild(el('h2', { class: 'pcp-acc-title', text: title }));
      root.appendChild(el('div', { class: 'pcp-acc-notice' }));
      (children || []).forEach(function (child) { if (child) root.appendChild(child); });
      drawNotice();
    }

    /* ------------------------------------------------------------------
     * The list
     * ---------------------------------------------------------------- */
    function statusLabel(status) {
      return (CFG.statuses && CFG.statuses[status]) || String(status || '').replace(/_/g, ' ');
    }

    function button(label, variant) {
      return el('button', ({ type: 'button', class: 'pcp-btn pcp-btn-sm pcp-btn-' + (variant || 'secondary'), text: label }));
    }

    /**
     * One booking: a table row on a wide page, a card on a narrow one. The
     * markup is the same; the stylesheet decides which it looks like. The roles
     * are spelt out because a table restyled as blocks otherwise stops being
     * announced as one.
     */
    function bookingRow(b) {
      var panelCell = el('td', { class: 'pcp-acc-panel', role: 'cell', colspan: '5' });
      var panelRow = el('tr', { class: 'pcp-acc-panel-row', role: 'row', hidden: true }, [panelCell]);

      var context = {
        booking: b,
        /** Open something under this booking, in place of whatever was there. */
        panel: function (node) {
          closePanels();
          panelCell.textContent = '';
          panelCell.appendChild(node);
          panelRow.hidden = false;
          // Focus goes to the panel itself, not to its first control: a date
          // field that takes focus opens its calendar over everything else.
          if (node.setAttribute) { node.setAttribute('tabindex', '-1'); node.focus(); }
        },
        close: function () {
          panelRow.hidden = true;
          panelCell.textContent = '';
        },
      };

      var actions = [];
      if (b.summary_url) {
        actions.push({ label: T.view, variant: 'secondary', href: b.summary_url });
      }
      if (b.can_cancel) {
        actions.push({
          label: T.cancel,
          variant: 'danger',
          onClick: function (ctx, trigger) { confirmCancel(ctx, trigger); },
        });
      }
      hooks.emit('account.row', { account: account, booking: b, actions: actions });

      var actionNodes = actions.map(function (action) {
        if (action.href) {
          return el('a', { class: 'pcp-btn pcp-btn-sm pcp-btn-' + (action.variant || 'secondary'), href: action.href, text: action.label });
        }
        var btn = button(action.label, action.variant);
        btn.addEventListener('click', function () {
          if (typeof action.onClick === 'function') action.onClick(context, btn);
        });
        return btn;
      });

      var row = el('tr', { class: 'pcp-acc-row', role: 'row', 'data-ref': b.booking_ref }, [
        el('td', { class: 'pcp-acc-ref', role: 'cell', 'data-label': T.ref }, [el('strong', { text: b.booking_ref })]),
        el('td', { class: 'pcp-acc-stay', role: 'cell', 'data-label': T.dates }, [
          el('span', { class: 'pcp-acc-place', text: [b.location_name, b.space_type_name].filter(Boolean).join(' · ') }),
          el('span', { class: 'pcp-acc-when', text: U.formatDateTime(b.entry_datetime) + ' → ' + U.formatDateTime(b.exit_datetime) }),
        ]),
        el('td', { class: 'pcp-acc-status', role: 'cell', 'data-label': T.status }, [
          el('span', { class: 'pcp-pill pcp-pill-' + String(b.status || '').replace(/[^a-z0-9_-]/gi, ''), text: statusLabel(b.status) }),
        ]),
        el('td', { class: 'pcp-acc-total', role: 'cell', 'data-label': T.total, text: U.money(b.total) }),
        el('td', { class: 'pcp-acc-actions', role: 'cell' }, [el('div', { class: 'pcp-acc-buttons' }, actionNodes)]),
      ]);

      return [row, panelRow];
    }

    function closePanels() {
      Array.prototype.slice.call(root.querySelectorAll('.pcp-acc-panel-row')).forEach(function (row) {
        row.hidden = true;
        row.firstChild.textContent = '';
      });
    }

    function render(bookings) {
      var another = CFG.isLoggedIn ? null : button(T.lookupAnother, 'ghost');
      if (another) {
        another.addEventListener('click', function () { notify('', ''); lookupForm(); });
      }

      if (!bookings.length) {
        shell(T.myBookings, [el('p', { class: 'pcp-empty', text: T.none }), another]);
        return;
      }

      var body = el('tbody', { role: 'rowgroup' });
      bookings.forEach(function (b) {
        bookingRow(b).forEach(function (row) { body.appendChild(row); });
      });

      var head = el('thead', { role: 'rowgroup' }, [el('tr', { role: 'row' }, [
        el('th', { role: 'columnheader', scope: 'col', text: T.ref }),
        el('th', { role: 'columnheader', scope: 'col', text: T.dates }),
        el('th', { role: 'columnheader', scope: 'col', text: T.status }),
        el('th', { role: 'columnheader', scope: 'col', class: 'pcp-acc-total', text: T.total }),
        el('th', { role: 'columnheader', scope: 'col' }, [el('span', { class: 'pcp-sr', text: T.actions })]),
      ])]);

      listEl = el('table', { class: 'pcp-acc-table', role: 'table', 'aria-label': T.myBookings }, [head, body]);
      shell(T.myBookings, [el('div', { class: 'pcp-card pcp-acc-list' }, [listEl]), another]);
    }

    /* ------------------------------------------------------------------
     * Changing a booking
     * ---------------------------------------------------------------- */
    /**
     * Send a change to one booking, then draw the list again.
     *
     * @param {string} ref  Booking reference.
     * @param {string} verb The route's last segment.
     * @param {Object} body What the change needs, besides who is asking.
     * @param {string} done What to say when it worked.
     * @returns {Promise<boolean>} Whether it worked.
     */
    // The request alone: who is asking goes with it, and the answer comes back untouched. For a
    // question that changes nothing, such as what a change would cost.
    function send(ref, verb, body) {
      var payload = Object.assign({ security: CFG.nonce }, body || {});
      if (!CFG.isLoggedIn && guest.email) payload.email = guest.email;

      return PCP.request(CFG, 'POST', 'account/bookings/' + encodeURIComponent(ref) + '/' + verb, payload);
    }

    function act(ref, verb, body, done) {
      return send(ref, verb, body)
        .then(function (json) {
          notice = { type: 'success', text: done || (json && json.message) || '' };
          return load().then(function () { return true; });
        }, function (e) {
          notify('error', e.message || T.error);
          return false;
        });
    }

    // An inline question under the row, not window.confirm: that blocks the
    // page, cannot be styled, and is skipped by some browsers altogether.
    function confirmCancel(ctx, trigger) {
      var yes = button(T.confirmCancelYes, 'danger');
      var no = button(T.keep, 'ghost');
      var box = el('div', { class: 'pcp-acc-confirm', role: 'group', 'aria-label': T.confirmCancel }, [
        el('p', { text: T.confirmCancel }),
        el('div', { class: 'pcp-acc-buttons' }, [yes, no]),
      ]);

      no.addEventListener('click', function () { ctx.close(); if (trigger) trigger.focus(); });
      yes.addEventListener('click', function () {
        PCP.button.busy(yes, true, T.working);
        no.disabled = true;
        act(ctx.booking.booking_ref, 'cancel', {}, T.cancelled).then(function (ok) {
          if (!ok) { PCP.button.busy(yes, false); no.disabled = false; }
        });
      });

      ctx.panel(box);
    }

    /* ------------------------------------------------------------------
     * Finding a booking as a guest
     * ---------------------------------------------------------------- */
    function lookupForm() {
      var refInput = el('input', { type: 'text', class: 'pcp-input pcp-acc-ref-input', autocomplete: 'off', autocapitalize: 'characters', spellcheck: 'false', required: true });
      var emailInput = el('input', { type: 'email', class: 'pcp-input pcp-acc-email-input', autocomplete: 'email', required: true });
      refInput.value = guest.ref;
      emailInput.value = guest.email;

      function field(label, input) {
        return el('div', { class: 'pcp-field' }, [
          el('label', { class: 'pcp-label' }, [el('span', { class: 'pcp-label-text', text: label }), input]),
        ]);
      }

      var submit = el('button', { type: 'submit', class: 'pcp-btn pcp-btn-primary', text: T.find });
      var form = el('form', { class: 'pcp-card pcp-acc-lookup', novalidate: true }, [
        el('p', { class: 'pcp-acc-intro', text: T.lookupIntro }),
        el('div', { class: 'pcp-row' }, [field(T.refLabel, refInput), field(T.emailLabel, emailInput)]),
        el('div', { class: 'pcp-acc-submit' }, [submit]),
      ]);

      form.addEventListener('input', function (e) {
        if (e.target && e.target.getAttribute('aria-invalid') === 'true') PCP.field.clearError(e.target);
      });

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        notify('', '');
        var ref = refInput.value.trim();
        var email = emailInput.value.trim();
        var first = null;

        [refInput, emailInput].forEach(PCP.field.clearError);
        if (!ref) { PCP.field.setError(refInput, T.fieldRequired); first = first || refInput; }
        if (!email) { PCP.field.setError(emailInput, T.fieldRequired); first = first || emailInput; }
        else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { PCP.field.setError(emailInput, T.emailInvalid); first = first || emailInput; }
        if (first) { first.focus(); return; }

        guest = { ref: ref, email: email };
        PCP.button.busy(submit, true, T.searching);
        PCP.request(CFG, 'GET', 'account/bookings', { ref: ref, email: email }).then(function (json) {
          render((json.data && json.data.bookings) || []);
        }, function (e2) {
          // The server's words: "no booking matches", or "asked too often".
          PCP.button.busy(submit, false);
          notify('error', e2.message || T.error);
        });
      });

      shell(T.lookupTitle, [form]);
    }

    /* ------------------------------------------------------------------
     * Loading
     * ---------------------------------------------------------------- */
    function skeleton() {
      shell(T.myBookings, [
        el('div', { class: 'pcp-acc-skeleton', 'aria-hidden': 'true' }, [el('span'), el('span'), el('span')]),
        el('p', { class: 'pcp-sr', role: 'status', text: T.loading }),
      ]);
    }

    /** Draw the list again from the server. Resolves when it is on the page. */
    function load() {
      if (CFG.isLoggedIn) {
        if (!listEl) skeleton();
        return PCP.request(CFG, 'GET', 'account/bookings').then(function (json) {
          render((json.data && json.data.bookings) || []);
        }, function (e) {
          notice = { type: 'error', text: e.message || T.error };
          shell(T.myBookings, []);
        });
      }

      if (!guest.ref || !guest.email) {
        lookupForm();
        return Promise.resolve();
      }

      return PCP.request(CFG, 'GET', 'account/bookings', { ref: guest.ref, email: guest.email }).then(function (json) {
        render((json.data && json.data.bookings) || []);
      }, function (e) {
        notice = { type: 'error', text: e.message || T.error };
        lookupForm();
      });
    }

    var account = {
      root: root,
      config: CFG,
      /** Send a change to a booking: act(ref, verb, body, messageWhenDone). */
      act: act,
      /** Ask the same route something and get its answer, with nothing redrawn: send(ref, verb, body). */
      send: send,
      /** Show a message above the list: notify('success' | 'error' | 'info', text). */
      notify: notify,
      /** Draw the list again from the server. */
      reload: load,
    };

    hooks.emit('account.init', account);
    load();
  }

  function boot() {
    Array.prototype.slice.call(document.querySelectorAll('.pcp-account')).forEach(function (root) {
      if (root.getAttribute('data-pcp-init')) return;
      root.setAttribute('data-pcp-init', '1');
      initAccount(root);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
