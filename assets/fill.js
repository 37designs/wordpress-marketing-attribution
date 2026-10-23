(function () {
  // Three Seven Gravity Forms Source Capture: copies the source cookies into the hidden fields in the
  // visitor's browser. Runs per visitor, so cached page HTML never carries anyone's values. The cookie
  // script can run after this (GTM waits for consent), so it fills again on focus and on submit.
  function cookie(n) { var m = document.cookie.match('(?:^|; )' + n + '=([^;]*)'); return m ? decodeURIComponent(m[1]) : null; }
  // Falls back to the earlier msb_ names until the cookie script has moved them.
  function get(n) { var v = cookie('tssc_' + n); return v !== null ? v : (cookie('msb_' + n) || ''); }
  function parse(s) { try { return JSON.parse(s); } catch (e) { return null; } }

  function fill() {
    var ft = get('ft'), path = get('path');
    var first = parse(ft), visits = parse(path);
    if (!Array.isArray(visits)) visits = [];

    var gclid = '';
    for (var i = visits.length - 1; i >= 0; i--) {
      if (visits[i] && visits[i].g) { gclid = visits[i].g; break; }
    }
    var lp = (first && first.lp) || (visits[0] && visits[0].lp) || '';

    var values = { 'first-touch': ft, 'touch-path': path, 'gclid': gclid, 'landing-page': lp };
    for (var name in values) {
      var inputs = document.querySelectorAll('.tssc-' + name + ' input');
      for (var j = 0; j < inputs.length; j++) inputs[j].value = values[name];
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fill);
  else fill();
  window.addEventListener('load', fill);
  document.addEventListener('focusin', fill, true);
  document.addEventListener('submit', fill, true);
  // Gravity Forms re-renders AJAX forms after a validation error.
  if (window.jQuery) window.jQuery(document).on('gform_post_render', fill);
})();
