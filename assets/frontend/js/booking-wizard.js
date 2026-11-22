/*
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 *
 * Plugixa Car Parking — the public booking form (vanilla JS).
 * Drives the public REST flow: availability -> quote -> booking.
 *
 * Every string a customer reads comes from PHP in `PlugixaCarParkingFront.i18n`.
 * Nothing here is written in English for display.
 *
 * Other scripts extend the form through `PlugixaCarParking.hooks`; the events
 * and the slots are described at the top of core.js.
 */
(function () {
  'use strict';

  var PCP = window.PlugixaCarParking;
  if (!PCP || !PCP.util) return;

  var U = PCP.util;
  var el = U.el;
  var money = U.money;
  var hooks = PCP.hooks;
  var CFG = window.PlugixaCarParkingFront || {};
  var T = CFG.i18n || {};

  /**
   * Describe the length of a stay for the order summary.
   *
   * ── DECISION POINT — this one is yours ────────────────────────────────────
   * This string sits directly above the price, so it sets the customer's
   * expectation of what they are paying for. It has to stay honest against the
   * server's pricing, which rounds partial units UP with a minimum of 1: a
   * 26-hour stay in a day-billed car park is charged as 2 days.
   *
   * There are two different things it can describe, and they disagree:
   *
   *   BILLED UNITS   what the price is worked out from. `quote` is
   *                  AUTHORITATIVE for this: once the server has priced the stay
   *                  it hands back `units` (the billed count) and
   *                  `billing_type`, and a string built from those can never
   *                  contradict the total shown beneath it.
   *   ELAPSED TIME   how long the car is actually there. Before a quote exists
   *                  (while the customer is still picking dates) the raw
   *                  interval is all there is, so anything shown then is a
   *                  preview and may round differently once priced.
   *
   * Options, roughly:
   *   a) Billed units     "2 days"                — matches the total exactly.
   *   b) Elapsed time     "1 day 2 hours"         — reads naturally, contradicts the price.
   *   c) Both             "1 day 2 hours · billed as 2 days"
   *   d) Vary the noun by billing_type ("2 nights" for a hotel-style car park,
   *      "2 days" for an airport one).
   *
   * Whatever this returns is shown as it is on the "Duration" row; an empty
   * string hides the row. New wording needs new strings in the `i18n` list in
   * includes/Modules/Frontend/Shortcodes.php, not English written here.
   *
   * @param {string}      entry       MySQL datetime.
   * @param {string}      exit        MySQL datetime.
   * @param {Object|null} quote       Server quote when priced: {units, billing_type}.
   * @param {string}      billingType How the location bills when there is no quote yet.
   * @returns {string} Display string, or '' to hide the row.
   */
  function formatDuration(entry, exit, quote, billingType) {
    var a = U.parseMysql(entry);
    var b = U.parseMysql(exit);
    if (!a || !b || b <= a) return '';

    var billing = (quote && quote.billing_type) || billingType || 'day';
    if (!UNIT_SECONDS[billing]) billing = 'day';
    var units = quote && quote.units != null
      ? Number(quote.units)                                       // billed, authoritative
      : billing === 'month'
        ? calendarMonths(a, b)
        : Math.max(1, Math.ceil((b - a) / 1000 / UNIT_SECONDS[billing]));

    // Option (a), billed units, in the unit the location bills by: it can
    // never contradict the total on screen.
    var names = UNIT_NAMES[billing];
    return units + ' ' + (units === 1 ? names[0] : names[1]);
  }

  /**
   * Calendar months from one date to another, a part month counted as one:
   * the count the server bills a month by (Pricing::calendar_months()).
   */
  function calendarMonths(a, b) {
    var months = (b.getFullYear() - a.getFullYear()) * 12 + (b.getMonth() - a.getMonth());
    // Stepped from the entry, a month that would overshoot the exit is not a whole one yet.
    var stepped = new Date(a.getTime());
    stepped.setMonth(stepped.getMonth() + months);
    if (stepped > b) months -= 1;
    stepped = new Date(a.getTime());
    stepped.setMonth(stepped.getMonth() + months);
    return Math.max(1, stepped < b ? months + 1 : months);
  }

  // At or under this many free spaces, a space type's badge says it is running out.
  var LOW_STOCK = 3;

  // How long one billing unit is, as the server counts it. A month is
  // counted on the calendar instead: see calendarMonths().
  var UNIT_SECONDS = { minute: 60, hour: 3600, day: 86400, week: 604800, month: 2592000 };
  // What one, and more than one, of each is called.
  var UNIT_NAMES = {
    minute: [T.unitMinute, T.unitMinutes],
    hour: [T.unitHour, T.unitHours],
    day: [T.unitDay, T.unitDays],
    week: [T.unitWeek, T.unitWeeks],
    month: [T.unitMonth, T.unitMonths],
  };

  // The stay lengths a quick pick offers, by how the location bills: each is
  // [how many, of which unit]. One tap sets the exit that far after the entry.
  var QUICK_STAYS = {
    minute: [[1, 'hour'], [2, 'hour'], [4, 'hour']],
    hour: [[2, 'hour'], [4, 'hour'], [8, 'hour'], [1, 'day']],
    day: [[1, 'day'], [3, 'day'], [1, 'week'], [2, 'week']],
    week: [[1, 'week'], [2, 'week'], [4, 'week']],
    month: [[1, 'month'], [3, 'month'], [6, 'month']],
  };

  /** A date moved on by a number of units; a month on the calendar, as the server bills it. */
  function addUnits(date, n, unit) {
    var out = new Date(date.getTime());
    if (unit === 'month') out.setMonth(out.getMonth() + n);
    else out.setTime(out.getTime() + n * UNIT_SECONDS[unit] * 1000);
    return out;
  }

  /** A change to a price with its direction written out: "−€8.00" or "+€8.00". */
  function signedMoney(amount) {
    var text = money(amount);
    return Number(amount) > 0 && text !== money(0) ? '+' + text : text;
  }

  // Characters valid in a CSS colour, so a value can never break out of where
  // it is used. Values are administrator-controlled; this is a second lock.
  function safeColor(c) {
    return (c == null ? '' : String(c)).replace(/[^#0-9a-zA-Z(),.% ]/g, '').slice(0, 32) || 'transparent';
  }

  function initWizard(root) {
    var state = {
      step: '',
      locationId: parseInt(root.getAttribute('data-default-location'), 10) || 0,
      entry: '',
      exit: '',
      spaceTypeId: 0,
      spaceTypeName: '',
      spaceId: null,
      quantity: 1,
      coupon: '',
      gateway: '',
      quote: null,
      lines: [],
      notes: [],
      quoting: false,
      booking: null,
    };

    var $ = function (sel) { return root.querySelector(sel); };
    var $all = function (sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); };

    var errorBox = $('.pcp-error');
    var entryInput = $('.pcp-entry');
    var exitInput = $('.pcp-exit');
    var locationEl = $('.pcp-location');

    // A link can name the location to start on: ?pcp_location=<id or slug>. Read here rather
    // than on the server, so a cached copy of the page still honours it. A value that is not one
    // of the form's own locations is ignored.
    (function () {
      var wanted = '';
      try { wanted = new URLSearchParams(window.location.search).get('pcp_location') || ''; } catch (e) { wanted = ''; }
      if (!wanted || !locationEl || locationEl.tagName !== 'SELECT') return;
      Array.prototype.forEach.call(locationEl.options, function (option) {
        if (option.value === wanted || option.getAttribute('data-slug') === wanted) {
          locationEl.value = option.value;
          state.locationId = parseInt(option.value, 10) || state.locationId;
        }
      });
    })();

    var orderEl = $('.pcp-order');
    var orderBody = $('.pcp-order-body');
    var orderLive = $('.pcp-order-live');
    var orderToggle = $('.pcp-order-toggle');
    var orderToggleTotal = $('.pcp-order-toggle-total');
    var lastTotal = null;
    var groupName = U.uid('pcp-space');
    var gatewayName = U.uid('pcp-gateway');

    // Default dates: the next full hour the site will take, then one day. Most people book for
    // now, and "tomorrow 08:00" made every one of them change it. The site's minimum notice
    // counts, or the default itself would be refused.
    var lead = Math.max(0, parseInt(CFG.minAdvanceMinutes, 10) || 0);
    var start = new Date(Date.now() + lead * 60000);
    start.setMinutes(0, 0, 0);
    start.setHours(start.getHours() + 1);
    var end = new Date(start.getTime()); end.setDate(end.getDate() + 1);
    if (entryInput && !entryInput.value) entryInput.value = U.toLocalInput(start);
    if (exitInput && !exitInput.value) exitInput.value = U.toLocalInput(end);

    /* ------------------------------------------------------------------
     * Messages
     * ---------------------------------------------------------------- */
    function showError(msg) {
      if (!errorBox) return;
      errorBox.textContent = msg || T.genericError;
      errorBox.hidden = false;
      // The alert sits at the top of the card while the button that caused it
      // is at the bottom, so on a tall step it can land off-screen.
      try {
        errorBox.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      } catch (e) {
        errorBox.scrollIntoView(false);
      }
    }
    function clearError() { if (errorBox) { errorBox.hidden = true; errorBox.textContent = ''; } }

    /** Refuse a field, and remember the first one refused so it can take focus. */
    var firstInvalid = null;
    function refuse(control, message) {
      PCP.field.setError(control, message);
      if (!firstInvalid) firstInvalid = PCP.field.visible(control);
    }
    function focusFirstInvalid() {
      if (firstInvalid && firstInvalid.focus) firstInvalid.focus();
      var failed = !!firstInvalid;
      firstInvalid = null;
      return failed;
    }

    /* ------------------------------------------------------------------
     * The steps a saved form asks for
     * ---------------------------------------------------------------- */
    // Each form on a page prints its own settings on its markup. The shared
    // settings carry a form too, for a page cached before forms printed theirs.
    var formCfg = readConfig() || CFG.form || null;
    var PANELS = { dates: 1, space: 2, details: 3, payment: 'payment', confirm: 4 };
    var STEP_LABELS = {
      dates: T.stepDates, space: T.stepSpace, details: T.stepDetails, payment: T.stepPayment, confirm: T.stepConfirm,
    };
    var enabled = {};
    var featCoupon = !!CFG.couponsEnabled;
    var flow = [];

    if (formCfg && formCfg.steps && formCfg.steps.length) {
      formCfg.steps.forEach(function (s) {
        if (!s || !s.key) return;
        enabled[s.key] = !!s.enabled;
        if (s.key === 'space' && s.options && s.options.coupon === false) featCoupon = false;
      });
    }
    if (formCfg && formCfg.coupons === false) featCoupon = false;

    function readConfig() {
      var raw = root.getAttribute('data-pcp-config');
      if (!raw) return null;
      try { return JSON.parse(raw); } catch (e) { return null; }
    }

    /** The form every request is made on, so the server holds it to this form's choices. */
    function formId() { return formCfg && formCfg.id ? formCfg.id : undefined; }

    // THE ORDER OF THE SCREENS IS THE ORDER THE PAGE PRINTED THEM IN. The
    // server works out which screens this form shows and in what order, and
    // prints the progress bar from that. Reading it back from the markup means
    // the bar a customer sees and the path they walk cannot disagree, and that
    // each form on a page follows its own.
    $all('.pcp-steps .pcp-step[data-step]').forEach(function (li) {
      var key = li.getAttribute('data-step');
      var label = li.querySelector('.pcp-step-label');
      var desc = li.querySelector('.pcp-step-desc');
      if (!PANELS[key] || !$('.pcp-panel[data-panel="' + PANELS[key] + '"]')) return;
      flow.push({ key: key, panel: PANELS[key], label: label ? label.textContent : STEP_LABELS[key], desc: desc ? desc.textContent : '' });
    });
    if (!flow.length) {
      flow = ['dates', 'space', 'details', 'confirm'].map(function (key) {
        return { key: key, panel: PANELS[key], label: STEP_LABELS[key] };
      });
    }

    function inFlow(key) { return flow.some(function (f) { return f.key === key; }); }

    /**
     * Whether the saved form has a step switched on. A step the form does not
     * mention, and every step of the standard form, counts as on.
     */
    function stepEnabled(key) { return enabled[key] !== false; }

    var currentIdx = 0;
    // Filled by initDatePickers(); empty when the calendar is not loaded.
    var pickers = [];

    /* ------------------------------------------------------------------
     * Moving between steps
     * ---------------------------------------------------------------- */
    function showPanel(panel) {
      $all('.pcp-panel').forEach(function (p) {
        p.classList.toggle('is-active', p.getAttribute('data-panel') === String(panel));
      });
      // The calendar is attached to <body>, so hiding the dates panel does not
      // hide it. Close it on every move.
      pickers.forEach(function (fp) { if (fp && fp.close) fp.close(); });
      clearError();
    }

    /**
     * Bring the progress up to date: the numbered list, and the bar and its
     * "Step 2 of 4" that the minimal design shows instead.
     *
     * The list is updated in place rather than drawn again, so a step being
     * finished is a transition the stylesheet can animate. It is drawn again
     * only when it does not hold one entry per screen of the flow.
     */
    function renderSteps() {
      var ol = $('.pcp-steps');
      if (!ol) return;

      var items = Array.prototype.slice.call(ol.children);
      var same = items.length === flow.length && flow.every(function (f, i) { return items[i].getAttribute('data-step') === f.key; });
      if (!same) {
        ol.textContent = '';
        items = flow.map(function (f, i) {
          var li = el('li', { class: 'pcp-step', 'data-step': f.key }, [
            el('span', { class: 'pcp-step-marker', 'aria-hidden': 'true', text: String(i + 1) }),
            el('span', { class: 'pcp-step-text' }, [
              el('span', { class: 'pcp-step-label', text: f.label }),
              el('span', { class: 'pcp-step-desc', text: f.desc || '' }),
            ]),
          ]);
          ol.appendChild(li);
          return li;
        });
      }

      items.forEach(function (li, i) {
        var done = i < currentIdx;
        li.classList.toggle('is-active', i === currentIdx);
        li.classList.toggle('is-done', done);
        if (i === currentIdx) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');

        // "(completed)" for a screen reader, on the steps behind the customer only.
        var sr = li.querySelector('.pcp-step-done');
        if (done && !sr) li.appendChild(el('span', { class: 'pcp-sr pcp-step-done', text: ' ' + T.stepDone }));
        if (!done && sr) sr.parentNode.removeChild(sr);
      });

      var nav = $('.pcp-stepper');
      if (nav) nav.style.setProperty('--pcp-progress', String((currentIdx + 1) / flow.length));
      var text = $('.pcp-progress-text');
      if (text && T.stepOf) text.textContent = U.sprintf(T.stepOf, currentIdx + 1, flow.length);
    }

    /**
     * Show a step.
     *
     * @param {number}  idx   Its place in the flow.
     * @param {boolean} focus Move keyboard focus to the step's heading. False on
     *                        first load, so the page does not jump to the form.
     */
    function goToFlow(idx, focus) {
      currentIdx = idx;
      var f = flow[idx];
      state.step = f.key;
      root.setAttribute('data-step', f.key);
      if (f.key === 'payment') buildPayment();
      if (f.key === 'confirm') buildReview();
      showPanel(f.panel);
      renderSteps();
      renderOrder();
      hooks.emit('wizard.step', { wizard: wizard, step: f.key, panel: $('.pcp-panel[data-panel="' + f.panel + '"]') });
      if (focus) { focusHeading(f.panel); revealTop(); }
    }

    /**
     * After a move, bring the top of the form back into view when the customer
     * has scrolled past it: the new step starts there. A form already in view
     * is left where it is, so the page does not jump for nothing.
     */
    function revealTop() {
      var box = root.getBoundingClientRect();
      if (box.top >= 0 && box.top < window.innerHeight * 0.4) return;
      var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      try {
        root.scrollIntoView({ block: 'start', behavior: reduce ? 'auto' : 'smooth' });
      } catch (e) {
        root.scrollIntoView(true);
      }
    }

    function focusHeading(panel) {
      var heading = root.querySelector('.pcp-panel[data-panel="' + panel + '"] .pcp-h, .pcp-panel[data-panel="' + panel + '"] .pcp-success-title');
      if (!heading) return;
      heading.setAttribute('tabindex', '-1');
      // Without moving the page: revealTop() decides whether it moves.
      try { heading.focus({ preventScroll: true }); } catch (e) { heading.focus(); }
    }

    function advance() { if (currentIdx < flow.length - 1) goToFlow(currentIdx + 1, true); }
    function back() { if (currentIdx > 0) goToFlow(currentIdx - 1, true); }

    /* ------------------------------------------------------------------
     * Location
     * ---------------------------------------------------------------- */
    function locationOption() {
      if (locationEl && locationEl.tagName === 'SELECT') return locationEl.options[locationEl.selectedIndex] || null;
      return locationEl;
    }

    function locationName() {
      if (locationEl && locationEl.tagName === 'SELECT') {
        var opt = locationOption();
        return opt ? opt.textContent.trim() : '';
      }
      var p = $('.pcp-location-name');
      return p ? p.textContent.trim() : '';
    }

    function locationBilling() {
      var opt = locationOption();
      return (opt && opt.getAttribute('data-billing')) || 'day';
    }

    /* ------------------------------------------------------------------
     * The dates step's helpers: quick stay lengths and the stay's length
     * ---------------------------------------------------------------- */
    var quickBox = $('.pcp-quick');
    var durationEl = $('.pcp-duration');

    function entryDate() { return U.parseMysql(U.toMysql(entryInput ? entryInput.value : '')); }
    function exitDate() { return U.parseMysql(U.toMysql(exitInput ? exitInput.value : '')); }

    /** Set the exit, through the calendar when it is loaded so its own field follows. */
    function setExit(date) {
      var fp = pickers[1];
      if (fp && fp.setDate) {
        fp.setDate(date, true);
      } else if (exitInput) {
        exitInput.value = U.toLocalInput(date);
        exitInput.dispatchEvent(new Event('input', { bubbles: true }));
      }
      datesChanged();
    }

    /** One chip per quick stay length the location's billing unit offers. */
    function renderQuick() {
      if (!quickBox) return;
      var stays = QUICK_STAYS[locationBilling()] || QUICK_STAYS.day;
      var key = stays.map(function (q) { return q.join(':'); }).join(',');

      if (quickBox.getAttribute('data-stays') !== key) {
        quickBox.setAttribute('data-stays', key);
        quickBox.textContent = '';
        stays.forEach(function (stay) {
          var names = UNIT_NAMES[stay[1]] || [];
          var chip = el('button', {
            type: 'button',
            class: 'pcp-chip',
            'aria-pressed': 'false',
            'data-n': String(stay[0]),
            'data-unit': stay[1],
            text: stay[0] + ' ' + (stay[0] === 1 ? names[0] : names[1]),
          });
          chip.addEventListener('click', function () {
            var from = entryDate();
            if (from) setExit(addUnits(from, stay[0], stay[1]));
          });
          quickBox.appendChild(chip);
        });
      }

      // The chip for the stay already chosen shows as pressed.
      var a = entryDate();
      var b = exitDate();
      Array.prototype.slice.call(quickBox.children).forEach(function (chip) {
        var n = parseInt(chip.getAttribute('data-n'), 10);
        var on = !!(a && b) && addUnits(a, n, chip.getAttribute('data-unit')).getTime() === b.getTime();
        chip.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      quickBox.hidden = !stays.length;
    }

    /** The stay's length under the dates, worked out as the price will count it. */
    function renderDuration() {
      if (!durationEl) return;
      var text = formatDuration(U.toMysql(entryInput ? entryInput.value : ''), U.toMysql(exitInput ? exitInput.value : ''), null, locationBilling());
      durationEl.textContent = text;
      // A pressed quick choice already says how long the stay is.
      var pressed = quickBox && quickBox.querySelector('[aria-pressed="true"]');
      durationEl.hidden = !text || !!pressed;
    }

    /** Everything that follows the two dates and the location. */
    function datesChanged() {
      renderOrder();
      renderQuick();
      renderDuration();
    }

    function getLocationId() {
      if (locationEl && locationEl.value) return parseInt(locationEl.value, 10);
      return state.locationId;
    }

    /* ------------------------------------------------------------------
     * The order summary
     * ---------------------------------------------------------------- */
    function factRow(label, value, kind) {
      return el('div', { class: 'pcp-order-row' + (kind ? ' is-' + kind : '') }, [
        el('dt', { text: label }),
        el('dd', { text: String(value) }),
      ]);
    }

    function spaceLabel() {
      return T.spaceCount
        ? U.sprintf(T.spaceCount, state.spaceTypeName, state.quantity)
        : state.spaceTypeName + ' × ' + state.quantity;
    }

    /**
     * Redraw the order summary from `state`. Cheap enough for every keystroke.
     *
     * Reads the live date fields rather than `state.entry`/`state.exit`, so the
     * dates shown follow the customer's typing before any request is made.
     */
    function renderOrder() {
      if (!orderEl || !orderBody) return;

      var entry = U.toMysql(entryInput ? entryInput.value : state.entry);
      var exit = U.toMysql(exitInput ? exitInput.value : state.exit);
      var q = state.quote;
      var nodes = [];

      var place = locationName();
      if (place) nodes.push(el('p', { class: 'pcp-order-place', text: place }));

      var facts = [];
      if (entry && exit) {
        facts.push(factRow(T.labelEntry, U.formatDateTime(entry), 'entry'));
        facts.push(factRow(T.labelExit, U.formatDateTime(exit), 'exit'));
        var duration = formatDuration(entry, exit, q, locationBilling());
        if (duration) facts.push(factRow(T.labelDuration, duration, 'duration'));
      }
      if (facts.length) nodes.push(el('dl', { class: 'pcp-order-facts' }, facts));

      if (q) {
        // The quote's own lines are the price. Each is a label and an amount,
        // and that is all this needs to know about any of them.
        // A line whose price something changed says so underneath, by name:
        // the amount beside the line is already the changed one.
        var rows = state.lines.map(function (line) {
          var changes = (line.adjustments || []).map(function (change) {
            return el('span', { class: 'pcp-order-adjustment', text: signedMoney(change.amount) + ' ' + (change.label || '') });
          });
          return el('div', { class: 'pcp-order-row' + (line.amount < 0 ? ' is-credit' : '') }, [
            el('dt', {}, [line.label].concat(changes)),
            el('dd', { text: money(line.amount) }),
          ]);
        });
        if (rows.length) nodes.push(el('dl', { class: 'pcp-order-lines' }, rows));

        var totalText = money(q.total);
        var totalEl = el('strong', { text: totalText });
        // A total that is new since the last drawing arrives with a small lift.
        if (!state.quoting && lastTotal !== null && lastTotal !== totalText) totalEl.className = 'is-bump';
        if (!state.quoting) lastTotal = totalText;
        nodes.push(el('div', { class: 'pcp-order-total' }, [
          el('span', { text: T.total }),
          totalEl,
        ]));

        if (state.notes.length) {
          nodes.push(el('ul', { class: 'pcp-order-notes' }, state.notes.map(function (note) {
            return el('li', {}, [
              el('span', { text: note.label }),
              note.amount != null ? el('strong', { text: money(note.amount) }) : null,
            ]);
          })));
        }
      } else if (state.spaceTypeName) {
        nodes.push(el('dl', { class: 'pcp-order-lines' }, [
          el('div', { class: 'pcp-order-row' }, [
            el('dt', { text: spaceLabel() }),
            el('dd', {}, [el('span', { class: 'pcp-skeleton', 'aria-hidden': 'true' })]),
          ]),
        ]));
      }

      orderEl.classList.toggle('is-loading', state.quoting);
      if (state.quoting) orderEl.setAttribute('aria-busy', 'true'); else orderEl.removeAttribute('aria-busy');

      if (!nodes.length) { orderEl.hidden = true; root.classList.remove('has-order'); return; }

      // What the folded summary shows on its bar: the total once there is one,
      // the stay's length before that.
      if (orderToggleTotal) {
        orderToggleTotal.textContent = q ? money(q.total) : (entry && exit ? formatDuration(entry, exit, null, locationBilling()) : '');
      }

      orderBody.textContent = '';
      orderBody.appendChild(el('h3', { class: 'pcp-order-title', text: T.yourBooking }));
      nodes.forEach(function (node) { orderBody.appendChild(node); });
      orderEl.hidden = false;
      root.classList.add('has-order');

      // Announce the total once per change, not the whole card on every redraw.
      if (orderLive) {
        var spoken = q && !state.quoting ? T.total + ': ' + money(q.total) : '';
        if (orderLive.textContent !== spoken) orderLive.textContent = spoken;
      }
    }

    /* ------------------------------------------------------------------
     * Step 1: dates
     * ---------------------------------------------------------------- */
    function validateDates() {
      [entryInput, exitInput].forEach(function (input) { if (input) PCP.field.clearError(input); });
      if (locationEl && locationEl.tagName === 'SELECT') PCP.field.clearError(locationEl);

      state.locationId = getLocationId();
      state.entry = U.toMysql(entryInput ? entryInput.value : '');
      state.exit = U.toMysql(exitInput ? exitInput.value : '');

      if (!state.locationId && locationEl && locationEl.tagName === 'SELECT') refuse(locationEl, T.fieldRequired);
      if (!state.entry && entryInput) refuse(entryInput, T.fieldRequired);
      if (!state.exit && exitInput) refuse(exitInput, T.fieldRequired);

      var a = U.parseMysql(state.entry);
      var b = U.parseMysql(state.exit);
      if (a && b && b <= a && exitInput) refuse(exitInput, T.exitAfterEntry);

      return !focusFirstInvalid() && !!state.locationId;
    }

    function checkAvailability() {
      clearError();
      if (!validateDates()) return;

      var btn = $('.pcp-check');
      PCP.button.busy(btn, true, T.checking);

      PCP.request(CFG, 'GET', 'availability', { location_id: state.locationId, entry: state.entry, exit: state.exit, form_id: formId() })
        .then(function (json) {
          var data = json.data || {};
          var rows = data.availability || [];
          state.spaceTypeId = 0;
          state.spaceTypeName = '';
          state.spaceId = null;
          state.quote = null;
          state.lines = [];
          state.notes = [];
          renderSpaceTypes(rows);
          hooks.emit('wizard.availability', { wizard: wizard, data: data });
          if (!rows.some(function (r) { return r.available > 0; })) {
            // Stay on the dates: moving on would leave the customer looking at
            // a list of sold-out cards with the reason left behind.
            showError(T.noAvailability);
            renderOrder();
            return;
          }
          advance();
        })
        .catch(function (e) { showError(e.message); })
        .then(function () { PCP.button.busy(btn, false); });
    }

    /* ------------------------------------------------------------------
     * Step 2: space
     * ---------------------------------------------------------------- */
    /**
     * Draw the space types as one radio group.
     *
     * Each card is a label around a real radio button, so the arrow keys, the
     * single tab stop and the "1 of 4" a screen reader announces all come from
     * the browser rather than from anything written here.
     */
    function renderSpaceTypes(rows) {
      var container = $('.pcp-space-types');
      if (!container) return;
      container.textContent = '';

      rows.forEach(function (r) {
        var soldOut = !(r.available > 0);
        var name = r.space_type_name || '';
        var availability = soldOut ? T.soldOut : U.sprintf(T.availableCount, r.available);

        var radio = el('input', {
          type: 'radio',
          class: 'pcp-space-radio',
          name: groupName,
          value: String(r.space_type_id),
          disabled: soldOut,
        });

        // The type's own colour, for the stylesheet to tint the card's tile with.
        var dot = el('span', { class: 'pcp-space-dot', 'aria-hidden': 'true' });
        if (r.color) dot.style.setProperty('--pcp-swatch', safeColor(r.color));

        var card = el('label', { class: 'pcp-space-card' + (soldOut ? ' is-disabled' : '') }, [
          radio,
          dot,
          el('span', { class: 'pcp-space-info' }, [
            el('span', { class: 'pcp-space-name' }, [
              name,
              // The owner's own highlight for this type, if they wrote one.
              r.badge ? el('span', { class: 'pcp-space-badge', text: String(r.badge) }) : null,
            ]),
            el('span', { class: 'pcp-space-meta' + (soldOut ? ' is-out' : (r.available <= LOW_STOCK ? ' is-low' : '')), text: availability }),
          ]),
          el('span', { class: 'pcp-space-price', text: money(r.price_preview) }),
          el('span', { class: 'pcp-space-tick', 'aria-hidden': 'true' }),
        ]);

        radio.addEventListener('change', function () {
          if (!radio.checked) return;
          // "Please choose a space type" is answered by this choice.
          clearError();
          $all('.pcp-space-card').forEach(function (c) { c.classList.remove('is-selected'); });
          card.classList.add('is-selected');
          state.spaceTypeId = parseInt(r.space_type_id, 10);
          state.spaceTypeName = name;
          state.spaceId = null;
          renderSpacePicker(r.free_spaces || []);
          requote();
        });

        container.appendChild(card);
      });

      // With one kind of space on offer there is nothing to choose: it is chosen, and its card
      // stays so the customer sees what they are getting and what it costs.
      var open = $all('.pcp-space-radio').filter(function (r) { return !r.disabled; });
      if (open.length === 1 && rows.length === 1) {
        open[0].checked = true;
        open[0].dispatchEvent(new Event('change'));
      }
    }

    function renderSpacePicker(spaces) {
      var box = $('.pcp-space-picker');
      if (!box) return;
      box.textContent = '';
      if (!spaces.length) { box.hidden = true; return; }

      var select = el('select', { class: 'pcp-input pcp-space-select' }, [el('option', { value: '', text: T.anySpace })]);
      spaces.forEach(function (s) {
        select.appendChild(el('option', { value: String(s.id), text: s.label || ('#' + s.id) }));
      });
      select.addEventListener('change', function () {
        state.spaceId = select.value ? parseInt(select.value, 10) : null;
        requote();
      });

      box.appendChild(el('div', { class: 'pcp-field' }, [
        el('label', { class: 'pcp-label' }, [el('span', { class: 'pcp-label-text', text: T.pickSpace }), select]),
      ]));
      box.hidden = false;
    }

    var quoteSeq = 0;

    /**
     * Ask the server what the current selection costs, and redraw.
     *
     * Answers can come back out of order when the customer changes something
     * quickly, so each request is numbered and only the newest one is drawn.
     */
    function requote() {
      if (!state.spaceTypeId) { renderOrder(); return; }

      var qty = $('.pcp-qty');
      state.quantity = Math.max(1, parseInt(qty ? qty.value : 1, 10) || 1);

      var seq = ++quoteSeq;
      state.quoting = true;
      renderOrder();

      var payload = hooks.emit('wizard.quoteRequest', {
        wizard: wizard,
        body: {
          location_id: state.locationId, space_type_id: state.spaceTypeId,
          entry: state.entry, exit: state.exit, quantity: state.quantity,
          coupon: state.coupon, space_id: state.spaceId, form_id: formId(),
        },
      });

      PCP.request(CFG, 'POST', 'quote', payload.body).then(function (json) {
        if (seq !== quoteSeq) return;
        var q = json.data || {};
        var drawn = hooks.emit('wizard.quote', {
          wizard: wizard,
          quote: q,
          lines: (q.line_items || []).map(function (item) {
            return {
              // The stay itself reads better under the name the customer chose.
              label: item.type === 'parking' ? spaceLabel() : (item.label || ''),
              amount: Number(item.total || 0),
              type: item.type || '',
              adjustments: (item.adjustments || []).map(function (change) {
                return { label: String(change.label || ''), amount: Number(change.amount || 0) };
              }),
            };
          }).filter(function (line) {
            // A line reduced to nothing still shows, because the reduction is on it.
            return line.amount !== 0 || line.type === 'parking' || line.adjustments.length > 0;
          }),
          notes: [],
        });
        state.quote = q;
        state.lines = drawn.lines || [];
        state.notes = drawn.notes || [];
        state.quoting = false;
        renderCouponMsg(q);
        renderOrder();
      }).catch(function (e) {
        if (seq !== quoteSeq) return;
        // A total that may no longer be right is worse than none.
        state.quote = null;
        state.lines = [];
        state.notes = [];
        state.quoting = false;
        renderOrder();
        showError(e.message);
      });
    }

    function renderCouponMsg(q) {
      var msg = $('.pcp-coupon-msg');
      if (!msg) return;
      if (state.coupon && Number(q.discount_total) > 0) {
        msg.hidden = false; msg.textContent = T.couponApplied; msg.className = 'pcp-coupon-msg is-ok';
      } else if (state.coupon) {
        msg.hidden = false; msg.textContent = T.couponInvalid; msg.className = 'pcp-coupon-msg is-bad';
      } else {
        msg.hidden = true; msg.textContent = '';
      }
    }

    function applyCoupon() {
      if (!state.spaceTypeId) { showError(T.selectSpace); return; }
      var input = $('.pcp-coupon');
      state.coupon = input ? (input.value || '').trim() : '';
      requote();
    }

    function toDetails() {
      if (!state.spaceTypeId) {
        showError(T.selectSpace);
        var first = $('.pcp-space-radio:not(:disabled)');
        if (first) first.focus();
        return;
      }
      if (state.quoting) return;
      // No price yet: the last request failed. Ask again rather than move on
      // to a review with no total on it.
      if (!state.quote) { requote(); return; }
      advance();
    }

    /* ------------------------------------------------------------------
     * Step 3: details
     * ---------------------------------------------------------------- */
    function value(sel) {
      var node = $(sel);
      return node ? (node.value || '').trim() : '';
    }

    function validateDetails() {
      var nameEl = $('.pcp-name');
      var emailEl = $('.pcp-email');
      $all('.pcp-panel[data-panel="3"] .pcp-input, .pcp-panel[data-panel="3"] .pcp-cf').forEach(PCP.field.clearError);

      if (nameEl && !nameEl.value.trim()) refuse(nameEl, T.fieldRequired);
      if (emailEl) {
        var email = emailEl.value.trim();
        if (!email) refuse(emailEl, T.fieldRequired);
        else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) refuse(emailEl, T.emailInvalid);
      }
      $all('.pcp-cf').forEach(function (field) {
        if (!field.required) return;
        var filled = field.type === 'checkbox' ? field.checked : (field.value || '').trim();
        if (!filled) refuse(field, T.fieldRequired);
      });

      return !focusFirstInvalid();
    }

    function toReview() {
      clearError();
      if (validateDetails()) advance();
    }

    function renderCustomFields() {
      var box = $('.pcp-custom-fields');
      if (!box) return;
      var fields = (formCfg && formCfg.fields) || [];
      box.textContent = '';
      if (!fields.length) { box.hidden = true; return; }

      fields.forEach(function (f) {
        if (!f || !f.key) return;
        var required = !!f.required;
        var control;

        if (f.type === 'checkbox') {
          control = el('input', { type: 'checkbox', class: 'pcp-cf pcp-checkbox-input', 'data-key': f.key, required: required });
          box.appendChild(el('div', { class: 'pcp-field' }, [
            el('label', { class: 'pcp-checkbox' }, [control, el('span', { text: f.label + (required ? ' *' : '') })]),
          ]));
          return;
        }

        if (f.type === 'textarea') {
          control = el('textarea', { class: 'pcp-input pcp-cf', 'data-key': f.key, required: required, rows: '3' });
        } else if (f.type === 'select') {
          control = el('select', { class: 'pcp-input pcp-cf', 'data-key': f.key, required: required },
            (f.options || []).map(function (o) { return el('option', { text: o }); }));
        } else {
          var type = f.type === 'email' ? 'email' : (f.type === 'tel' ? 'tel' : 'text');
          control = el('input', { type: type, class: 'pcp-input pcp-cf', 'data-key': f.key, required: required });
        }

        box.appendChild(el('div', { class: 'pcp-field' }, [
          el('label', { class: 'pcp-label' }, [
            el('span', { class: 'pcp-label-text' }, [
              f.label,
              required ? el('span', { class: 'pcp-req', 'aria-hidden': 'true', text: ' *' }) : null,
            ]),
            control,
          ]),
        ]));
      });
      box.hidden = false;
    }

    function answerOf(field) {
      return field.type === 'checkbox' ? (field.checked ? T.yes : T.no) : (field.value || '').trim();
    }

    /** The answers as sent: a box as true or false, which the server writes in the site's language. */
    function collectMeta() {
      var meta = {};
      $all('.pcp-cf').forEach(function (field) {
        meta[field.getAttribute('data-key')] = field.type === 'checkbox' ? !!field.checked : answerOf(field);
      });
      return meta;
    }

    /** What the customer answered in the form's own fields: [label, answer] for each one filled in. */
    function customAnswers() {
      var labels = {};
      ((formCfg && formCfg.fields) || []).forEach(function (f) { if (f && f.key) labels[f.key] = f.label; });
      return $all('.pcp-cf').map(function (field) {
        var key = field.getAttribute('data-key');
        return [labels[key] || key, answerOf(field)];
      }).filter(function (row) { return row[1]; });
    }

    /* ------------------------------------------------------------------
     * Step 4: review and pay
     * ---------------------------------------------------------------- */
    function gateways() { return featPayment() ? (CFG.gateways || []) : []; }
    function featPayment() { return stepEnabled('payment'); }

    function getSelectedGateway() {
      var list = gateways();
      var id = state.gateway || (list[0] ? list[0].id : 'offline');
      var meta = list.filter(function (g) { return g.id === id; })[0];
      return { id: id, online: meta ? !!meta.online : false };
    }

    function instructions() {
      return CFG.offlineInstructions
        ? el('p', { class: 'pcp-alert pcp-alert-info pcp-instructions', text: CFG.offlineInstructions })
        : null;
    }

    function buildReview() {
      var box = $('.pcp-review');
      if (!box) return;
      box.textContent = '';

      var list = gateways();
      settleGateway();
      var chosen = list.filter(function (g) { return g.id === state.gateway; })[0];

      var rows = [
        [T.labelName, value('.pcp-name')],
        [T.labelEmail, value('.pcp-email')],
        [T.labelPhone, value('.pcp-phone')],
        [T.labelPlate, value('.pcp-plate')],
      ].concat(customAnswers()).concat([
        // Named here only where the customer had a choice to make.
        [T.labelPayment, list.length > 1 && chosen ? (chosen.label || chosen.id) : ''],
      ]).filter(function (r) { return r[1]; }).map(function (r) {
        return el('div', { class: 'pcp-review-row' }, [el('dt', { text: r[0] }), el('dd', { text: r[1] })]);
      });
      if (rows.length) box.appendChild(el('dl', { class: 'pcp-review-list' }, rows));

      // A form whose steps leave out the payment screen still offers the choice, here.
      if (list.length > 1 && !inFlow('payment')) box.appendChild(methodChoice(list));

      var how = getSelectedGateway().online ? null : instructions();
      if (how) box.appendChild(el('div', { class: 'pcp-pay-note' }, [how]));
    }

    /** Make sure the method remembered is one the form still offers. */
    function settleGateway() {
      var list = gateways();
      if (!state.gateway || !list.some(function (g) { return g.id === state.gateway; })) {
        state.gateway = list[0] ? list[0].id : 'offline';
      }
    }

    /**
     * The ways to pay, as one radio group, with the instructions for a way
     * that is settled in person shown under it.
     */
    function methodChoice(list) {
      var note = el('div', { class: 'pcp-pay-note' });
      function drawNote() {
        note.textContent = '';
        var how = getSelectedGateway().online ? null : instructions();
        if (how) note.appendChild(how);
      }

      var options = list.map(function (g) {
        var radio = el('input', { type: 'radio', class: 'pcp-pay-radio', name: gatewayName, value: g.id });
        radio.checked = g.id === state.gateway;
        // A neutral glyph for what kind of payment it is, and any logos the site supplied
        // (the plugixa_carparking_payment_logos filter; the plugin ships no brand marks).
        var logos = (g.logos || []).map(function (src) {
          return el('img', { class: 'pcp-pay-logo', src: src, alt: '', loading: 'lazy', decoding: 'async' });
        });
        var option = el('label', { class: 'pcp-pay-option' + (radio.checked ? ' is-selected' : '') }, [
          radio,
          el('span', { class: 'pcp-pay-icon', 'data-icon': g.icon || (g.online ? 'card' : 'cash'), 'aria-hidden': 'true' }),
          el('span', { class: 'pcp-pay-name', text: g.label || g.id }),
          logos.length ? el('span', { class: 'pcp-pay-logos' }, logos) : null,
        ]);
        radio.addEventListener('change', function () {
          if (!radio.checked) return;
          state.gateway = g.id;
          options.forEach(function (o) { o.classList.toggle('is-selected', o === option); });
          drawNote();
          hooks.emit('wizard.gateway', { wizard: wizard, gateway: getSelectedGateway() });
        });
        return option;
      });

      drawNote();
      return el('div', { class: 'pcp-pay-box' }, [
        el('fieldset', { class: 'pcp-pay-methods' },
          [el('legend', { class: 'pcp-label-text', text: T.payWith })].concat(options)),
        note,
      ]);
    }

    /** The payment screen: shown only where there is more than one way to pay. */
    function buildPayment() {
      var box = $('.pcp-pay-choice');
      if (!box) return;
      box.textContent = '';
      settleGateway();
      box.appendChild(methodChoice(gateways()));
    }

    function gcalLink(b) {
      var stamp = function (s) { return String(s || '').replace(/[-:]/g, '').replace(' ', 'T'); };
      var title = U.sprintf(T.calendarTitle, b.booking_ref);
      var details = title + (state.spaceTypeName ? ' (' + state.spaceTypeName + ')' : '');
      return 'https://calendar.google.com/calendar/render?action=TEMPLATE'
        + '&text=' + encodeURIComponent(title)
        + '&dates=' + stamp(state.entry) + '/' + stamp(state.exit)
        + '&details=' + encodeURIComponent(details)
        + '&location=' + encodeURIComponent(locationName());
    }

    /**
     * The confirmation screen: the reference, what was booked, what happens next.
     *
     * @param {Object} b       The booking the server created.
     * @param {string} warning Something that went wrong after it was saved, if anything.
     */
    function showSuccess(b, warning) {
      var panel = $('.pcp-success');
      if (!panel) return;
      state.booking = b;
      panel.textContent = '';

      var facts = [
        [T.labelLocation, locationName()],
        [T.labelEntry, U.formatDateTime(state.entry)],
        [T.labelExit, U.formatDateTime(state.exit)],
        [T.labelSpace, spaceLabel()],
        [T.total, money(b.total)],
      ].filter(function (r) { return r[1]; }).map(function (r) {
        return el('div', { class: 'pcp-review-row' }, [el('dt', { text: r[0] }), el('dd', { text: r[1] })]);
      });

      var calendar = el('a', {
        class: 'pcp-btn pcp-btn-secondary',
        href: gcalLink(b),
        target: '_blank',
        rel: 'noopener noreferrer',
        text: T.addToCalendar,
      });
      // Only a site with a summary page sends the link. Anything that is not a web address is
      // not made into one.
      var view = b.summary_url && /^https?:/i.test(String(b.summary_url))
        ? el('a', { class: 'pcp-btn pcp-btn-primary', href: b.summary_url, text: T.viewBooking })
        : null;
      var again = el('button', { type: 'button', class: 'pcp-btn pcp-btn-ghost', text: T.bookAnother });
      again.addEventListener('click', function () { window.location.reload(); });

      [
        el('div', { class: 'pcp-success-icon', 'aria-hidden': 'true' }),
        el('h3', { class: 'pcp-success-title', text: b.status === 'pending' ? T.received : T.confirmed }),
        el('p', { class: 'pcp-success-lead', text: T.successLead }),
        el('p', { class: 'pcp-success-ref' }, [
          el('span', { class: 'pcp-success-ref-label', text: T.reference }),
          el('strong', { class: 'pcp-success-ref-value', text: b.booking_ref }),
          copyButton(b.booking_ref),
        ]),
        warning ? el('p', { class: 'pcp-alert pcp-alert-warning', role: 'alert', text: warning }) : null,
        el('dl', { class: 'pcp-review-list' }, facts),
        getSelectedGateway().online ? null : instructions(),
        el('div', { class: 'pcp-slot', 'data-pcp-slot': 'success', hidden: true }),
        el('div', { class: 'pcp-success-actions' }, [view, calendar, again]),
      ].forEach(function (node) { if (node) panel.appendChild(node); });

      showPanel('success');
      // The confirmation is its own screen: no stepper, no order summary.
      root.classList.add('is-complete');
      root.setAttribute('data-step', 'success');
      hooks.emit('wizard.success', { wizard: wizard, booking: b, panel: panel });
      focusHeading('success');
    }

    /** A button that copies the reference, where the browser lets a page write to the clipboard. */
    function copyButton(text) {
      if (!text || !navigator.clipboard || !navigator.clipboard.writeText || !T.copy) return null;
      var btn = el('button', { type: 'button', class: 'pcp-btn pcp-btn-secondary pcp-btn-sm pcp-copy', text: T.copy });
      var timer = 0;
      btn.addEventListener('click', function () {
        navigator.clipboard.writeText(String(text)).then(function () {
          btn.textContent = T.copied;
          btn.classList.add('is-copied');
          clearTimeout(timer);
          timer = setTimeout(function () { btn.textContent = T.copy; btn.classList.remove('is-copied'); }, 2000);
        }, function () { /* Refused: the reference is still there to select by hand. */ });
      });
      return btn;
    }

    function confirmBooking() {
      var btn = $('.pcp-confirm');
      var gw = getSelectedGateway();
      clearError();
      PCP.button.busy(btn, true, T.processing);

      var booking = hooks.emit('wizard.bookingRequest', {
        wizard: wizard,
        body: {
          security: CFG.nonce,
          location_id: state.locationId,
          space_type_id: state.spaceTypeId,
          entry: state.entry,
          exit: state.exit,
          quantity: state.quantity,
          gateway: gw.id,
          coupon: state.coupon,
          space_id: state.spaceId,
          meta: collectMeta(),
          // Which form this is: the server checks the booking against it, and the booking
          // keeps the labels its answers were given under.
          form_id: formId(),
          customer_name: value('.pcp-name'),
          customer_email: value('.pcp-email'),
          customer_phone: value('.pcp-phone'),
          license_plate: value('.pcp-plate'),
          // The field a person never fills; the server refuses a request that does.
          pcp_hp: ($('.pcp-hp-field') || {}).value || '',
        },
      });

      PCP.request(CFG, 'POST', 'booking', booking.body).then(function (json) {
        var b = json.data || {};
        if (!gw.online) { showSuccess(b); return null; }

        var payment = hooks.emit('wizard.paymentRequest', {
          wizard: wizard,
          booking: b,
          body: { security: CFG.nonce, booking_ref: b.booking_ref, booking_key: b.payment_key, gateway: gw.id },
        });

        // The booking exists from here on. A payment that will not start is
        // reported on the confirmation, never by offering "Confirm" again:
        // that would book the space a second time.
        return PCP.request(CFG, 'POST', 'payment/start', payment.body).then(function (pj) {
          if (pj.data && pj.data.redirect_url) {
            window.location.href = pj.data.redirect_url;
            return;
          }
          showSuccess(b);
        }, function (e) {
          showSuccess(b, T.paymentNotStarted + (e.message ? ' ' + e.message : ''));
        });
      }, function (e) {
        showError(e.message);
        PCP.button.busy(btn, false);
      });
    }

    /* ------------------------------------------------------------------
     * Calendar
     * ---------------------------------------------------------------- */
    function initDatePickers() {
      if (!entryInput || !exitInput) return;

      var exitPicker = null;
      var entryPicker = PCP.datepicker(entryInput, {
        root: root,
        onChange: function (dates) {
          // Exit can never come before entry. Keep the two fields consistent
          // rather than leave it to the server to refuse.
          if (exitPicker && dates[0]) {
            exitPicker.set('minDate', dates[0]);
            var out = exitPicker.selectedDates[0];
            if (!out || out <= dates[0]) {
              exitPicker.setDate(new Date(dates[0].getTime() + 864e5), false);
            }
          }
          PCP.field.clearError(entryInput);
          datesChanged();
        },
      });
      if (!entryPicker) return;

      exitPicker = PCP.datepicker(exitInput, {
        root: root,
        onChange: function () { PCP.field.clearError(exitInput); datesChanged(); },
      });
      if (exitPicker && entryPicker.selectedDates[0]) exitPicker.set('minDate', entryPicker.selectedDates[0]);

      pickers = [entryPicker, exitPicker];
    }

    /* ------------------------------------------------------------------
     * What other scripts are handed
     * ---------------------------------------------------------------- */
    var wizard = {
      root: root,
      config: CFG,
      state: state,
      /** One of the empty containers the markup leaves for other scripts. */
      slot: function (name) { return root.querySelector('[data-pcp-slot="' + name + '"]'); },
      stepEnabled: stepEnabled,
      /** Price the current selection again, after something that changes it. */
      requote: requote,
      /** Redraw the order summary without asking the server. */
      render: renderOrder,
      /** The payment method chosen so far: { id, online }. */
      gateway: getSelectedGateway,
      /** The screens this form shows, in order. */
      steps: function () { return flow.map(function (f) { return f.key; }); },
      showError: showError,
      clearError: clearError,
    };

    /* ------------------------------------------------------------------
     * Events
     * ---------------------------------------------------------------- */
    var checkBtn = $('.pcp-check'); if (checkBtn) checkBtn.addEventListener('click', checkAvailability);

    var qtyEl = $('.pcp-qty');
    if (qtyEl) {
      qtyEl.addEventListener('change', requote);
      // The count shows in the summary at once; the price follows on change.
      qtyEl.addEventListener('input', function () {
        state.quantity = Math.max(1, parseInt(qtyEl.value, 10) || 1);
        renderOrder();
      });
    }

    if (featCoupon) {
      var couponField = $('.pcp-coupon-field'); if (couponField) couponField.hidden = false;
      var applyBtn = $('.pcp-apply-coupon'); if (applyBtn) applyBtn.addEventListener('click', applyCoupon);
    }

    var toDetailsBtn = $('.pcp-to-details'); if (toDetailsBtn) toDetailsBtn.addEventListener('click', toDetails);
    var toReviewBtn = $('.pcp-to-review'); if (toReviewBtn) toReviewBtn.addEventListener('click', toReview);
    var toNextBtn = $('.pcp-to-next'); if (toNextBtn) toNextBtn.addEventListener('click', function () { clearError(); advance(); });

    // The button that leaves a step says where it leads: to the review when
    // that is the next screen, onward otherwise. The steps can be in any order,
    // so this is worked out from the order and not written into the markup.
    flow.forEach(function (f, i) {
      var next = flow[i + 1];
      var button = $('.pcp-panel[data-panel="' + f.panel + '"] .pcp-to-details, '
        + '.pcp-panel[data-panel="' + f.panel + '"] .pcp-to-review, '
        + '.pcp-panel[data-panel="' + f.panel + '"] .pcp-to-next');
      if (!button || !next) return;
      var label = next.key === 'confirm' ? T.review : T['continue'];
      if (label) button.textContent = label;
    });
    var confirmBtn = $('.pcp-confirm'); if (confirmBtn) confirmBtn.addEventListener('click', confirmBooking);
    $all('.pcp-back').forEach(function (b) { b.addEventListener('click', back); });

    // Enter in a field does what the step's main button does. There is no
    // <form> to do it: the steps are not one submission.
    root.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' || e.defaultPrevented) return;
      var target = e.target;
      if (!target || target.tagName !== 'INPUT' || /^(checkbox|radio|button|submit)$/.test(target.type)) return;
      var panel = target.closest('.pcp-panel');
      if (!panel) return;
      e.preventDefault();
      if (target.classList.contains('pcp-coupon')) { applyCoupon(); return; }
      var primary = panel.querySelector('.pcp-actions .pcp-btn-primary, .pcp-check');
      if (primary && !primary.disabled) primary.click();
    });

    // A field that was refused stops being marked as soon as it is corrected.
    root.addEventListener('input', function (e) {
      var target = e.target;
      if (target && target.getAttribute && target.getAttribute('aria-invalid') === 'true') PCP.field.clearError(target);
    });

    // Keep the summary live while the customer is still choosing dates.
    [entryInput, exitInput, locationEl].forEach(function (field) {
      if (!field) return;
      field.addEventListener('change', datesChanged);
      field.addEventListener('input', datesChanged);
    });

    // "From $8.00 / day" beside the first button follows the chosen location.
    var fromPriceEl = $('.pcp-from-price');
    function syncFromPrice() {
      if (!fromPriceEl || !locationEl) return;
      var option = locationEl.options ? locationEl.options[locationEl.selectedIndex] : locationEl;
      var label = option ? (option.getAttribute('data-from') || '') : '';
      fromPriceEl.textContent = label;
      fromPriceEl.hidden = '' === label;
    }
    if (locationEl) locationEl.addEventListener('change', syncFromPrice);
    syncFromPrice();

    // The counter's minus and plus. They change the number field and tell it
    // so, and its own listeners above do the rest.
    $all('.pcp-counter-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!qtyEl) return;
        var next = Math.max(1, (parseInt(qtyEl.value, 10) || 1) + parseInt(btn.getAttribute('data-step'), 10));
        if (String(next) === qtyEl.value) return;
        qtyEl.value = String(next);
        qtyEl.dispatchEvent(new Event('input', { bubbles: true }));
        qtyEl.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });
    function syncCounter() {
      var less = $('.pcp-counter-less');
      if (less && qtyEl) less.disabled = (parseInt(qtyEl.value, 10) || 1) <= 1;
    }
    if (qtyEl) qtyEl.addEventListener('input', syncCounter);
    syncCounter();

    // The folded summary opens and closes on its bar.
    if (orderToggle) {
      orderToggle.addEventListener('click', function () {
        var open = !orderEl.classList.contains('is-open');
        orderEl.classList.toggle('is-open', open);
        orderToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    }

    renderCustomFields();
    initDatePickers();
    renderQuick();
    renderDuration();
    hooks.emit('wizard.init', wizard);
    goToFlow(0, false);
  }

  function boot() {
    Array.prototype.slice.call(document.querySelectorAll('.pcp-wizard')).forEach(function (root) {
      if (root.getAttribute('data-pcp-init')) return;
      if (!root.querySelector('.pcp-panel')) return; // a notice or a payment result, not a form
      root.setAttribute('data-pcp-init', '1');
      initWizard(root);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
