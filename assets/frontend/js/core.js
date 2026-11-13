/*
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 *
 * Plugixa Car Parking — what the public scripts share, and how other scripts
 * extend them.
 *
 * Everything hangs off one global, `window.PlugixaCarParking`:
 *
 *   .hooks      a small event registry (see below)
 *   .util       formatting and parsing helpers
 *   .request    a fetch wrapper for the plugin's public REST routes
 *   .datepicker turns a date field into a themed calendar, when one is loaded
 *   .field      inline field errors with the right ARIA wiring
 *   .button     a button's busy state
 *
 * EXTENDING A PAGE
 * ----------------
 * A script that adds something to the booking form, the account page or the
 * summary page lists the page's own script as a dependency and subscribes:
 *
 *   PlugixaCarParking.hooks.on('wizard.init', function (wizard) { ... });
 *
 * A handler receives one payload object and changes it in place; there is no
 * return value to pass on. Handlers run in the order of their priority (lower
 * first, 10 by default), then in the order they were added.
 *
 * The events:
 *
 *   wizard.init            (wizard)                        once per form on the page *
 *   wizard.step            ({ wizard, step, panel })       a step has just been shown
 *   wizard.gateway         ({ wizard, gateway })           the customer picked another way to pay
 *   wizard.availability    ({ wizard, data })              the availability answer arrived
 *   wizard.quoteRequest    ({ wizard, body })              add fields to a quote request
 *   wizard.quote           ({ wizard, quote, lines, notes }) the lines and notes about to be drawn
 *   wizard.bookingRequest  ({ wizard, body })              add fields to the booking
 *   wizard.paymentRequest  ({ wizard, body, booking })     add fields to the payment that follows
 *   wizard.success         ({ wizard, booking, panel })    the confirmation screen was drawn
 *   account.init           (account)                       once per account page *
 *   account.row            ({ account, booking, actions }) add actions to one booking
 *   summary.init           (summary)                       once per summary page *
 *   summary.paymentRequest ({ summary, body, online })     add fields to a payment; `online` says
 *                                                          whether the way chosen takes money now
 *
 * The three marked * are remembered: a handler added after the page has started
 * is called straight away for every form, account page or summary already there,
 * so the order two scripts happen to load in does not matter.
 *
 * `lines` is a list of { label, amount, type, adjustments } and `notes` a list
 * of { label, amount }. The page draws whatever is in them and knows nothing
 * about what a line is for. `adjustments` is a list of { label, amount }: what
 * changed the line's price, each drawn under the line by name. `actions` is a list of
 * { label, variant, onClick(context) }, where context has `panel(node)` to open
 * something under the booking and `close()` to shut it.
 *
 * A page also leaves empty, hidden containers in its markup for other scripts to
 * fill: `wizard.slot('location')` (above the location list, only when the form
 * offers more than one), `wizard.slot('options')`, `wizard.slot('details')`,
 * `wizard.slot('payment')`, `wizard.slot('success')` and
 * `summary.slot('payment')`. Filling one means appending to it and clearing its
 * `hidden` property.
 */
(function () {
  'use strict';

  var PCP = window.PlugixaCarParking = window.PlugixaCarParking || {};
  var CFG = window.PlugixaCarParkingCore || {};
  var T = CFG.i18n || {};

  /* ---------------------------------------------------------------------
   * Hooks
   * ------------------------------------------------------------------- */
  var STICKY = { 'wizard.init': 1, 'account.init': 1, 'summary.init': 1 };
  var handlers = {};
  var remembered = {};

  PCP.hooks = {
    /**
     * Subscribe to an event.
     *
     * @param {string}   name     Event name.
     * @param {Function} fn       Called with the event's payload.
     * @param {number}   priority Lower runs first. 10 when omitted.
     */
    on: function (name, fn, priority) {
      if (typeof fn !== 'function') return;
      var list = handlers[name] = handlers[name] || [];
      list.push({ fn: fn, priority: typeof priority === 'number' ? priority : 10 });
      list.sort(function (a, b) { return a.priority - b.priority; });
      (remembered[name] || []).forEach(function (payload) { call(fn, payload); });
    },

    /** Unsubscribe a handler added with on(). */
    off: function (name, fn) {
      handlers[name] = (handlers[name] || []).filter(function (h) { return h.fn !== fn; });
    },

    /**
     * Fire an event. Returns the payload, after every handler has had it.
     *
     * A handler that throws is reported to the console and skipped: one broken
     * extension must not stop a customer from booking.
     */
    emit: function (name, payload) {
      if (STICKY[name]) (remembered[name] = remembered[name] || []).push(payload);
      (handlers[name] || []).slice().forEach(function (h) { call(h.fn, payload); });
      return payload;
    },
  };

  function call(fn, payload) {
    try {
      fn(payload);
    } catch (e) {
      if (window.console && console.error) console.error(e);
    }
  }

  /* ---------------------------------------------------------------------
   * Helpers
   * ------------------------------------------------------------------- */
  // The site's language, as the page declares it. Falls back to the browser's.
  var LOCALE = String(CFG.locale || document.documentElement.getAttribute('lang') || '').replace('_', '-') || undefined;
  // Settings arrive through wp_localize_script, which turns a top-level false
  // into an empty string and a number into its digits.
  var TIME_24 = !(CFG.time24 === false || CFG.time24 === '' || CFG.time24 === '0');
  var WEEK_START = parseInt(CFG.weekStart, 10);
  // The site's own date and time formats, written the way PHP's date() reads them.
  var DATE_FORMAT = String(CFG.dateFormat || 'Y-m-d');
  var TIME_FORMAT = String(CFG.timeFormat || (TIME_24 ? 'H:i' : 'g:i a'));

  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  /**
   * Build an element. `props` are attributes, except `text` (text content)
   * and `class`. There is deliberately no way to pass markup.
   */
  function el(tag, props, children) {
    var node = document.createElement(tag);
    Object.keys(props || {}).forEach(function (key) {
      var value = props[key];
      if (value == null || value === false) return;
      if (key === 'text') node.textContent = value;
      else if (key === 'class') node.className = value;
      else node.setAttribute(key, value === true ? '' : value);
    });
    (children || []).forEach(function (child) {
      if (child == null) return;
      node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    });
    return node;
  }

  /** Group the digits of a whole number with the site's thousands separator. */
  function group(digits, separator) {
    return separator ? digits.replace(/\B(?=(\d{3})+(?!\d))/g, separator) : digits;
  }

  /**
   * An amount as the site writes money: its symbol, its side, its separators.
   *
   * @param {number|string} amount
   * @returns {string}
   */
  function money(amount) {
    var m = CFG.money || {};
    var decimals = m.decimals != null ? m.decimals : 2;
    var value = Number(amount || 0);
    var parts = Math.abs(value).toFixed(decimals).split('.');
    var n = group(parts[0], m.thousand != null ? m.thousand : ',')
      + (parts[1] ? (m.decimal != null ? m.decimal : '.') + parts[1] : '');
    var symbol = m.symbol != null ? m.symbol : '$';
    // Decided on the rounded amount, so that -0.001 is written "0.00" and not "−0.00".
    var sign = value < 0 && Number(parts.join('.')) !== 0 ? '−' : '';
    switch (m.position) {
      case 'right': return sign + n + symbol;
      case 'left_space': return sign + symbol + ' ' + n;
      case 'right_space': return sign + n + ' ' + symbol;
      default: return sign + symbol + n;
    }
  }

  /**
   * Parse a MySQL datetime as LOCAL time.
   *
   * `new Date('2026-08-04 08:00:00')` is not portable, and the ISO form with a
   * Z would be read as UTC. Both shift the time shown. Build the parts instead.
   *
   * @param {string} mysql
   * @returns {Date|null}
   */
  function parseMysql(mysql) {
    var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/.exec(String(mysql || '').trim());
    if (!m) return null;
    return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0));
  }

  /**
   * 'YYYY-MM-DDTHH:MM' (a native field) or 'YYYY-MM-DD HH:MM' (the calendar)
   * as the 'YYYY-MM-DD HH:MM:SS' the REST routes expect.
   */
  function toMysql(value) {
    if (!value) return '';
    var v = String(value).replace('T', ' ').trim();
    return /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(v) ? v + ':00' : v;
  }

  /** A Date as the value of a native datetime-local field. */
  function toLocalInput(date) {
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
      + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
  }

  /** A month's or a weekday's name in the site's language. */
  function nameOf(date, part, length) {
    var opts = {};
    opts[part] = length;
    try {
      return new Intl.DateTimeFormat(LOCALE, opts).format(date);
    } catch (e) {
      return '';
    }
  }

  /**
   * A Date written in a PHP date() format, which is how the site's date and
   * time format settings are stored.
   *
   * The characters a date or time format realistically uses are understood.
   * Any other character is written as it is, and so is one after a backslash.
   *
   * @param {Date}   d
   * @param {string} format e.g. 'j M Y' or 'g:i a'
   * @returns {string}
   */
  function formatPhp(d, format) {
    var hours = d.getHours();
    var twelve = hours % 12 === 0 ? 12 : hours % 12;
    var day = d.getDate();
    var tokens = {
      d: function () { return pad(day); },
      j: function () { return String(day); },
      D: function () { return nameOf(d, 'weekday', 'short'); },
      l: function () { return nameOf(d, 'weekday', 'long'); },
      S: function () { return day % 10 === 1 && day !== 11 ? 'st' : (day % 10 === 2 && day !== 12 ? 'nd' : (day % 10 === 3 && day !== 13 ? 'rd' : 'th')); },
      m: function () { return pad(d.getMonth() + 1); },
      n: function () { return String(d.getMonth() + 1); },
      M: function () { return nameOf(d, 'month', 'short'); },
      F: function () { return nameOf(d, 'month', 'long'); },
      Y: function () { return String(d.getFullYear()); },
      y: function () { return String(d.getFullYear()).slice(-2); },
      H: function () { return pad(hours); },
      G: function () { return String(hours); },
      h: function () { return pad(twelve); },
      g: function () { return String(twelve); },
      i: function () { return pad(d.getMinutes()); },
      s: function () { return pad(d.getSeconds()); },
      a: function () { return hours < 12 ? 'am' : 'pm'; },
      A: function () { return hours < 12 ? 'AM' : 'PM'; },
    };
    var out = '';
    for (var i = 0; i < format.length; i += 1) {
      var c = format.charAt(i);
      if (c === '\\') { i += 1; out += format.charAt(i); continue; }
      out += tokens[c] ? tokens[c]() : c;
    }
    return out;
  }

  /**
   * A PHP date() format as the calendar's own format string.
   *
   * The two share most of their letters. The ones that differ are swapped, and
   * a literal letter the calendar would read as one of its own is escaped.
   */
  function calendarFormat(format) {
    var same = 'djDlmnMFYyHi';
    var swap = { G: 'H', h: 'G', g: 'h', s: 'S', a: 'K', A: 'K', S: '' };
    var out = '';
    for (var i = 0; i < format.length; i += 1) {
      var c = format.charAt(i);
      var literal = false;
      if (c === '\\') { i += 1; c = format.charAt(i); literal = true; }
      if (!literal && same.indexOf(c) > -1) out += c;
      else if (!literal && Object.prototype.hasOwnProperty.call(swap, c)) out += swap[c];
      else out += /[a-zA-Z]/.test(c) ? '\\' + c : c;
    }
    return out;
  }

  /**
   * A datetime for a person to read. Never send this to the API.
   *
   * Written in the site's own date format and time format, the two settings
   * every other page and the calendar follow, so "06/10/2026" on an invoice is
   * not "Tue 6 Oct" on the form that made it.
   *
   * @param {string} mysql 'YYYY-MM-DD HH:MM:SS'
   * @returns {string} e.g. "6 Oct 2026 08:00"
   */
  function formatDateTime(mysql) {
    var d = parseMysql(mysql);
    if (!d) return String(mysql || '');
    return formatPhp(d, DATE_FORMAT + ' ' + TIME_FORMAT);
  }

  /** A date alone, in the site's date format. */
  function formatDate(mysql) {
    var d = parseMysql(String(mysql || '').length === 10 ? mysql + ' 00:00:00' : mysql);
    return d ? formatPhp(d, DATE_FORMAT) : String(mysql || '');
  }

  /** Replace %1$s, %2$s … in a translated string. */
  function sprintf(template) {
    var args = arguments;
    return String(template || '').replace(/%(\d+)\$s/g, function (all, n) {
      return args[+n] != null ? args[+n] : '';
    });
  }

  var counter = 0;
  /** An id nothing else on the page has. */
  function uid(prefix) {
    counter += 1;
    return (prefix || 'pcp') + '-' + counter + '-' + Math.random().toString(36).slice(2, 7);
  }

  PCP.util = {
    escapeHtml: escapeHtml,
    el: el,
    money: money,
    parseMysql: parseMysql,
    toMysql: toMysql,
    toLocalInput: toLocalInput,
    formatDateTime: formatDateTime,
    formatDate: formatDate,
    sprintf: sprintf,
    uid: uid,
    locale: LOCALE,
  };

  /* ---------------------------------------------------------------------
   * Requests
   * ------------------------------------------------------------------- */
  /**
   * Call one of the plugin's public REST routes.
   *
   * Resolves with the decoded answer. Rejects with an Error whose message is the
   * server's own wording when it gave one — that is how "you are asking too
   * often" reaches the customer — and whose `status` is the HTTP status.
   *
   * @param {Object} cfg    The page's settings: apiUrl and, when signed in, restNonce.
   * @param {string} method GET or POST.
   * @param {string} path   The route, relative to the plugin's namespace.
   * @param {Object} body   Query arguments for a GET, the JSON body otherwise.
   */
  PCP.request = function (cfg, method, path, body) {
    var headers = { Accept: 'application/json' };
    // The wp_rest nonce makes WordPress resolve the same user for the request
    // as it did when the page's own nonce was made.
    if (cfg.restNonce) headers['X-WP-Nonce'] = cfg.restNonce;

    var opts = { method: method, headers: headers, credentials: 'same-origin' };
    var url = cfg.apiUrl + path;

    if (method === 'GET' && body) {
      var qs = Object.keys(body)
        .filter(function (k) { return body[k] !== undefined && body[k] !== null && body[k] !== ''; })
        .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(body[k]); })
        .join('&');
      if (qs) url += (url.indexOf('?') > -1 ? '&' : '?') + qs;
    } else if (body) {
      headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    return fetch(url, opts).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (json) {
        if (!res.ok || (json && json.success === false)) {
          var error = new Error((json && json.message) || T.genericError || '');
          error.status = res.status;
          throw error;
        }
        return json || {};
      });
    }, function () {
      var error = new Error(T.networkError || T.genericError || '');
      error.status = 0;
      throw error;
    });
  };

  /* ---------------------------------------------------------------------
   * Field errors
   * ------------------------------------------------------------------- */
  PCP.field = {
    /**
     * Show a message under a field and mark the field invalid.
     *
     * The message lives in the `.pcp-field-error` element of the field's
     * `.pcp-field` wrapper, and the control points at it with aria-describedby
     * so a screen reader reads the reason with the field.
     *
     * @param {Element} control The input, select or textarea.
     * @param {string}  message Why it is refused.
     */
    setError: function (control, message) {
      var wrap = control.closest ? control.closest('.pcp-field') : null;
      var target = visibleControl(control);
      target.setAttribute('aria-invalid', 'true');
      if (!wrap) return;
      wrap.classList.add('has-error');
      var box = wrap.querySelector('.pcp-field-error');
      if (!box) {
        box = el('p', { class: 'pcp-field-error' });
        wrap.appendChild(box);
      }
      if (!box.id) box.id = uid('pcp-err');
      box.textContent = message;
      box.hidden = false;
      target.setAttribute('aria-describedby', box.id);
    },

    /** Take a field's message away again. */
    clearError: function (control) {
      var wrap = control.closest ? control.closest('.pcp-field') : null;
      var target = visibleControl(control);
      target.removeAttribute('aria-invalid');
      target.removeAttribute('aria-describedby');
      if (!wrap) return;
      wrap.classList.remove('has-error');
      var box = wrap.querySelector('.pcp-field-error');
      if (box) { box.hidden = true; box.textContent = ''; }
    },

    /** The control a person actually sees: the calendar's text field stands in for a hidden one. */
    visible: visibleControl,
  };

  function visibleControl(control) {
    return (control._flatpickr && control._flatpickr.altInput) || control;
  }

  /* ---------------------------------------------------------------------
   * Buttons
   * ------------------------------------------------------------------- */
  PCP.button = {
    /**
     * Put a button into, or take it out of, its busy state.
     *
     * @param {Element} btn   The button.
     * @param {boolean} busy  Whether a request is in flight.
     * @param {string}  label What it says meanwhile. Its own label when omitted.
     */
    busy: function (btn, busy, label) {
      if (!btn) return;
      if (busy) {
        if (btn.getAttribute('data-pcp-label') == null) btn.setAttribute('data-pcp-label', btn.textContent);
        if (label) btn.textContent = label;
        btn.classList.add('is-loading');
        btn.setAttribute('aria-busy', 'true');
        btn.disabled = true;
      } else {
        var original = btn.getAttribute('data-pcp-label');
        if (original != null) { btn.textContent = original; btn.removeAttribute('data-pcp-label'); }
        btn.classList.remove('is-loading');
        btn.removeAttribute('aria-busy');
        btn.disabled = false;
      }
    },
  };

  /* ---------------------------------------------------------------------
   * Calendar
   * ------------------------------------------------------------------- */
  // The custom properties the calendar reads. It is appended to <body>, outside
  // the page's own wrapper, so the ones a page sets on its wrapper are copied.
  var THEME_PROPS = ['--pcp-primary', '--pcp-primary-contrast', '--pcp-primary-ink-light', '--pcp-primary-ink-dark'];

  /**
   * Upgrade a datetime field to the calendar, when the calendar is loaded.
   *
   * Progressive enhancement: the field is a real `datetime-local` input, so
   * without the library the browser's own picker does the job and this returns
   * null. With it, the visible field becomes a text input in a format people
   * read, and the original keeps the machine value that toMysql() understands.
   *
   * @param {Element} input The datetime-local field.
   * @param {Object}  opts  root (element whose colours to follow), minDate, onChange.
   * @returns {Object|null} The calendar instance.
   */
  PCP.datepicker = function (input, opts) {
    if (typeof window.flatpickr !== 'function' || !input) return null;
    opts = opts || {};

    var l10ns = window.flatpickr.l10ns || {};
    var locale = CFG.datepickerLocale && l10ns[CFG.datepickerLocale] ? Object.assign({}, l10ns[CFG.datepickerLocale]) : {};
    if (WEEK_START >= 0 && WEEK_START <= 6) locale.firstDayOfWeek = WEEK_START;

    return window.flatpickr(input, {
      enableTime: true,
      dateFormat: 'Y-m-d H:i',
      altInput: true,
      altFormat: calendarFormat(DATE_FORMAT + ' ' + TIME_FORMAT),
      // Left unset, the library copies the original field's classes onto the
      // visible one, which would put a second copy of each on the page.
      altInputClass: 'pcp-input pcp-input-date',
      time_24hr: TIME_24,
      minuteIncrement: 15,
      minDate: opts.minDate || 'today',
      disableMobile: true,
      locale: locale,
      onReady: function (dates, str, fp) {
        fp.calendarContainer.classList.add('pcp-fp');
        var source = opts.root || (input.closest ? input.closest('.pcp-ui') : null);
        if (source) {
          THEME_PROPS.forEach(function (prop) {
            var value = source.style.getPropertyValue(prop);
            if (value) fp.calendarContainer.style.setProperty(prop, value);
          });
          // And the page's design, which the calendar's own rules follow.
          var design = source.getAttribute('data-pcp-design');
          if (design) fp.calendarContainer.setAttribute('data-pcp-design', design);
        }
        // The visible field is the one a person tabs to and a label names.
        if (fp.altInput) {
          if (input.required) fp.altInput.setAttribute('aria-required', 'true');
          var label = input.getAttribute('aria-label');
          if (label) fp.altInput.setAttribute('aria-label', label);
        }
      },
      onChange: function (dates, str, fp) {
        if (typeof opts.onChange === 'function') opts.onChange(dates, str, fp);
      },
    });
  };
})();
