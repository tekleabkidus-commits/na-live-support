// Installs the snippet exactly as host.html does (no inline script — the CSP forbids it).
(function () {
  var q = new URLSearchParams(location.search);
  var key = q.get('key');
  var platform = q.get('platform') || 'https://platform.harness.localhost:8443';
  if (!key) return;
  var s = document.createElement('script');
  s.src = platform + '/w/' + encodeURIComponent(key) + '.js';
  document.head.appendChild(s);
})();
