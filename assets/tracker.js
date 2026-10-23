(function () {
  // Three Seven Gravity Forms Source Capture: records where each visit came from in first-party cookies.
  // The plugin replaces this object with the domain and ignore list from Settings > Source Capture.
  var CFG = /*TSSC_CONFIG*/{"domain":"example.com","ignore":["accounts.google.com","login.microsoftonline.com","checkout.stripe.com","paypal.com"]}/*END_TSSC_CONFIG*/;
  var DAYS = 90, MAX_VISITS = 8, MAX_COOKIE = 3800;

  function hostRe(list) {
    if (!list.length) return /$^/;
    return new RegExp('(^|\\.)(' + list.map(function (d) { return d.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }).join('|') + ')$', 'i');
  }
  var SELF = hostRe([CFG.domain]);
  var IGNORE = hostRe(CFG.ignore);
  var SEARCH = /(^|\.)(google\.[a-z.]+|bing\.com|duckduckgo\.com|yahoo\.com|ecosia\.org)$/i;
  var AI = /(^|\.)(chatgpt\.com|perplexity\.ai|claude\.ai|gemini\.google\.com|copilot\.microsoft\.com)$/i;
  var SOCIAL = /(^|\.)(linkedin\.com|lnkd\.in|facebook\.com|fb\.com|instagram\.com|x\.com|twitter\.com|t\.co|youtube\.com|reddit\.com|bsky\.app)$/i;

  // Share cookies across subdomains on the live site; fall back to host-only cookies on staging.
  var ATTRS = '; path=/' + (SELF.test(location.hostname) ? '; domain=.' + CFG.domain : '') +
    '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');

  function get(n) { var m = document.cookie.match('(?:^|; )' + n + '=([^;]*)'); return m ? decodeURIComponent(m[1]) : null; }
  function set(n, v, secs) { document.cookie = n + '=' + encodeURIComponent(v) + (secs ? '; max-age=' + secs : '') + ATTRS; }
  function del(n) { document.cookie = n + '=; max-age=0' + ATTRS; }
  function cut(s, n) { return String(s).slice(0, n); }

  // One-time move from the earlier msb_ cookie names. The first visit keeps its original 90-day expiry.
  var oldFt = get('msb_ft'), oldPath = get('msb_path'), oldSession = get('msb_s');
  if (oldFt !== null) {
    if (get('tssc_ft') === null) {
      var age = 0;
      try { age = (Date.now() - Date.parse(JSON.parse(oldFt).t + 'Z')) / 1000; } catch (e) {}
      var left = Math.round(DAYS * 86400 - (age > 0 ? age : 0));
      if (left > 0) set('tssc_ft', oldFt, left);
    }
    del('msb_ft');
  }
  if (oldPath !== null) {
    if (get('tssc_path') === null) set('tssc_path', oldPath, DAYS * 86400);
    del('msb_path');
  }
  if (oldSession !== null) del('msb_s');

  var q = new URLSearchParams(location.search);
  var ref = '';
  try { ref = document.referrer ? new URL(document.referrer).hostname : ''; } catch (e) {}
  var hasParams = q.get('gclid') || q.get('gbraid') || q.get('wbraid') || q.get('utm_source');
  var external = ref && !SELF.test(ref) && !IGNORE.test(ref);
  var inSession = get('tssc_s') || oldSession;

  set('tssc_s', '1', 1800); // 30-minute session marker, refreshed on every page
  if (!hasParams && !external && inSession) return; // internal click or reload
  if (!hasParams && ref && (SELF.test(ref) || IGNORE.test(ref))) return;

  var src, med;
  if (q.get('utm_source')) { src = q.get('utm_source'); med = q.get('utm_medium') || ''; }
  else if (q.get('gclid') || q.get('gbraid') || q.get('wbraid')) { src = 'google'; med = 'cpc'; }
  else if (external && AI.test(ref)) { src = ref; med = 'ai-assistant'; }
  else if (external && SOCIAL.test(ref)) { src = ref; med = 'social'; }
  else if (external && SEARCH.test(ref)) { src = ref; med = 'organic'; }
  else if (external) { src = ref; med = 'referral'; }
  else { src = '(direct)'; med = '(none)'; }

  var v = { t: new Date().toISOString().slice(0, 16), s: cut(src, 80), m: cut(med, 40), lp: cut(location.pathname, 150) };
  if (q.get('utm_campaign')) v.c = cut(q.get('utm_campaign'), 100);
  if (q.get('utm_term')) v.k = cut(q.get('utm_term'), 100);
  if (q.get('gclid')) v.g = cut(q.get('gclid'), 200);
  if (q.get('gbraid')) v.gb = cut(q.get('gbraid'), 200);
  if (q.get('wbraid')) v.wb = cut(q.get('wbraid'), 200);

  var path = [];
  try { path = JSON.parse(get('tssc_path') || '[]'); } catch (e) {}
  if (!Array.isArray(path)) path = [];

  // Reloading an ad or UTM landing page keeps its parameters; don't count it as a second visit.
  var last = path[path.length - 1];
  if (inSession && last && last.s === v.s && last.m === v.m && last.lp === v.lp &&
      (last.c || '') === (v.c || '') && (last.g || '') === (v.g || '')) return;

  if (!get('tssc_ft')) set('tssc_ft', JSON.stringify(v), DAYS * 86400); // first visit: written once

  path.push(v);
  if (path.length > MAX_VISITS) path = [path[0]].concat(path.slice(-(MAX_VISITS - 1)));
  // Long campaign names can push the cookie past the browser's 4 KB limit; drop the oldest middle visits.
  while (path.length > 2 && encodeURIComponent(JSON.stringify(path)).length > MAX_COOKIE) path.splice(1, 1);
  set('tssc_path', JSON.stringify(path), DAYS * 86400);
})();
