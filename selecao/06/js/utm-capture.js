(function () {
  var keys = [
    'utm_source',
    'utm_medium',
    'utm_campaign',
    'utm_campaign_name',
    'utm_adgroup',
    'utm_ad',
    'utm_keyword',
    'utm_term',
    'utm_matchtype',
    'utm_device',
    'utm_network',
    'utm_content',
    'fbclid',
    'ttclid',
    'gclid'
  ];

  var params = new URLSearchParams(window.location.search);
  var captured = {};
  keys.forEach(function (key) {
    var fromUrl = params.get(key);
    if (fromUrl) {
      localStorage.setItem(key, fromUrl);
      captured[key] = fromUrl;
      return;
    }
    var saved = localStorage.getItem(key);
    if (saved) captured[key] = saved;
  });

  window.aventadorUtms = captured;

  function withUtms(href) {
    try {
      var url = new URL(href, window.location.origin);
      keys.forEach(function (key) {
        var value = captured[key];
        if (value && !url.searchParams.get(key)) url.searchParams.set(key, value);
      });
      return url.pathname + url.search + url.hash;
    } catch (e) {
      return href;
    }
  }

  function applyKeepUtms() {
    document.querySelectorAll('[data-keep-utms]').forEach(function (link) {
      link.setAttribute('href', withUtms(link.getAttribute('href') || ''));
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyKeepUtms);
  } else {
    applyKeepUtms();
  }

  var script = document.currentScript;
  if (!script || !script.hasAttribute('data-track-visit')) return;
  if (sessionStorage.getItem('tc_visit_tracked')) return;

  var sid = localStorage.getItem('tc_sid');
  if (!sid) {
    sid = 'sid_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    localStorage.setItem('tc_sid', sid);
  }

  sessionStorage.setItem('tc_visit_tracked', '1');
  fetch('/pagina/api/track.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      session_id: sid,
      page: script.getAttribute('data-track-visit') || 'noticia',
      utm: captured
    })
  }).catch(function () {});
})();
