(function () {
  'use strict';
  // Three Seven Gravity Forms Source Capture: adds source data to email and phone link clicks,
  // and optionally rewrites email links (source lines in the body, a separate address for ad visitors).
  var CFG = window.tsscContact || {};
  var DAY = 86400000;
  var PAID = /^(cpc|ppc|paid|paidsearch|paid_search|paid[-_ ]?social|display|cpm)$/i;

  function get(n) { var m = document.cookie.match('(?:^|; )' + n + '=([^;]*)'); return m ? decodeURIComponent(m[1]) : null; }
  function readJson(name) {
    var raw = get('tssc_' + name);
    if (raw === null) raw = get('msb_' + name); // 1.x visitors not yet migrated by the tracker
    try { return raw ? JSON.parse(raw) : null; } catch (e) { return null; }
  }
  function cut(v) { return v === undefined || v === null ? undefined : String(v).slice(0, 100); }
  function visitTime(v) { var t = v && v.t ? Date.parse(v.t + 'Z') : NaN; return isNaN(t) ? null : t; }
  function isPaid(v) { return !!(v && (v.g || v.gb || v.wb || PAID.test(v.m || ''))); }

  /** Source data from the tracker's cookies. Missing cookies give an empty object. */
  function source() {
    var ft = readJson('ft');
    var path = readJson('path');
    if (!Array.isArray(path)) path = [];
    var last = path.length ? path[path.length - 1] : null;
    var out = {};
    if (ft) {
      out.first_source = cut(ft.s);
      out.first_medium = cut(ft.m);
      out.first_landing_page = cut(ft.lp);
      var t = visitTime(ft);
      if (t) out.days_since_first_visit = Math.max(0, Math.floor((Date.now() - t) / DAY));
    }
    if (last) out.session_landing_page = cut(last.lp);
    if (path.length) out.visit_count = path.length;
    out.ad_visitor = adVisitor(path) ? 'yes' : 'no';
    return out;
  }

  /** True when any visit in the last N days (default 30, the Google Ads default click window) came from an ad. */
  function adVisitor(path) {
    var days = parseInt(CFG.adWindowDays, 10) || 30;
    var since = Date.now() - days * DAY;
    for (var i = path.length - 1; i >= 0; i--) {
      var t = visitTime(path[i]);
      if (isPaid(path[i]) && (t === null || t >= since)) return true;
    }
    return false;
  }

  function linkLocation(el) {
    var tagged = el.closest('[data-tssc-location]');
    if (tagged) return cut(tagged.getAttribute('data-tssc-location'));
    var rules = CFG.locations || [];
    for (var i = 0; i < rules.length; i++) {
      try { if (el.closest(rules[i][0])) return cut(rules[i][1]); } catch (e) {}
    }
    return 'content';
  }

  function clickNumber() {
    try {
      var n = (parseInt(localStorage.getItem('tssc_contact_clicks'), 10) || 0) + 1;
      localStorage.setItem('tssc_contact_clicks', String(n));
      return n;
    } catch (e) { return undefined; }
  }

  /* ---------------------------------------------------------------- */
  /* Email link rewriting                                             */
  /* ---------------------------------------------------------------- */

  var MAIN = String(CFG.mainEmail || '').toLowerCase();
  var AD = String(CFG.adEmail || '');

  /** Splits "mailto:addr?query" by hand; URL() and URLSearchParams would turn spaces into "+". */
  function parseMailto(href) {
    var rest = href.slice(7);
    var q = rest.indexOf('?');
    var addr = q === -1 ? rest : rest.slice(0, q);
    var params = [], body = '';
    (q === -1 ? '' : rest.slice(q + 1)).split('&').forEach(function (pair) {
      if (!pair) return;
      var eq = pair.indexOf('=');
      var key = (eq === -1 ? pair : pair.slice(0, eq)).toLowerCase();
      if (key === 'body') {
        try { body = decodeURIComponent(pair.slice(eq + 1)); } catch (e) { body = pair.slice(eq + 1); }
      } else {
        params.push(pair);
      }
    });
    try { addr = decodeURIComponent(addr); } catch (e) {}
    return { addr: addr, params: params, body: body };
  }

  function bodyLines(src) {
    var lines = [];
    if (src.first_source) lines.push('Source: ' + src.first_source + (src.first_medium ? ' / ' + src.first_medium : ''));
    if (src.session_landing_page) lines.push('Landing page: ' + src.session_landing_page);
    return lines.join('\r\n');
  }

  /** Rebuilds every email link from its original href, so running it again never stacks lines. */
  function rewriteEmailLinks() {
    if (!CFG.bodyLines && !AD) return;
    var src = source();
    var swap = AD && MAIN && src.ad_visitor === 'yes';
    var links = document.querySelectorAll('a[href^="mailto:"], a[data-tssc-href]');
    for (var i = 0; i < links.length; i++) {
      var a = links[i];
      if (!a.hasAttribute('data-tssc-href')) a.setAttribute('data-tssc-href', a.getAttribute('href'));
      var orig = a.getAttribute('data-tssc-href');
      if (orig.toLowerCase().indexOf('mailto:') !== 0) continue;

      var m = parseMailto(orig);
      var isMain = MAIN && m.addr.toLowerCase() === MAIN;
      var addr = swap && isMain ? AD : m.addr;
      var params = m.params.slice();
      var body = m.body;
      var extra = CFG.bodyLines ? bodyLines(src) : '';
      if (extra) body = (body ? body + '\r\n\r\n' : '\r\n\r\n\r\n') + extra;
      if (body) params.push('body=' + encodeURIComponent(body));
      a.setAttribute('href', 'mailto:' + addr + (params.length ? '?' + params.join('&') : ''));

      // Swap the visible address too, so people who copy it get the ad address.
      if (isMain && a.childNodes.length) {
        var shown = swap ? AD : m.addr;
        var nodes = a.querySelectorAll('*').length ? a.querySelectorAll('*') : [a];
        for (var j = 0; j < nodes.length; j++) {
          var el = nodes[j];
          if (el.children.length === 0 && el.textContent.trim().toLowerCase().indexOf('@') !== -1) {
            var text = el.textContent.trim().toLowerCase();
            if (text === MAIN || text === AD.toLowerCase()) el.textContent = shown;
          }
        }
      }
    }
  }

  /* ---------------------------------------------------------------- */
  /* Click events                                                     */
  /* ---------------------------------------------------------------- */

  function onClick(e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) return;
    var href = (a.getAttribute('href') || '').toLowerCase();
    var method = href.indexOf('mailto:') === 0 ? 'email' : href.indexOf('tel:') === 0 ? 'phone' : '';
    if (!method || !CFG.trackClicks) return;

    var data = source();
    data.contact_method = method;
    data.link_location = linkLocation(a);
    var n = clickNumber();
    if (n !== undefined) data.click_number = n;

    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({ tssc: null }); // clear values from an earlier click
    window.dataLayer.push({ event: 'tssc_contact_click', tssc: data });
  }

  /** Refreshes a link just before it's used, in case the cookies changed after the page loaded. */
  function beforeUse(e) {
    var a = e.target && e.target.closest ? e.target.closest('a[data-tssc-href]') : null;
    if (a) rewriteEmailLinks();
  }

  document.addEventListener('click', onClick, true);
  document.addEventListener('pointerdown', beforeUse, true);
  document.addEventListener('keydown', function (e) { if (e.key === 'Enter') beforeUse(e); }, true);

  // The cookie script often loads through GTM after this file, so run again once the page settles.
  function run() { try { rewriteEmailLinks(); } catch (e) {} }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
  window.addEventListener('load', function () { run(); setTimeout(run, 1500); });
})();
