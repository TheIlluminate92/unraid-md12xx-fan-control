(function () {
  'use strict';
  var endpoint = '/plugins/md12xx.fancontrol/include/ipmi-api.php';
  var node = function (id) { return document.getElementById(id); };
  var current = null, initialized = false, show = node('cooling-show-unavailable');
  try { show.checked = window.localStorage.getItem('waz.cooling.showUnavailable') === 'yes'; } catch (_) {}
  function visibility() {
    node('cooling-ipmi').hidden = !show.checked && !(current && current.available);
    var md = window.MD12xxBootstrap && window.MD12xxBootstrap.config;
    // Saved shelves remain visible even when disconnected so faults can be reviewed.
    var saved = md && Array.isArray(md.shelves) && md.shelves.length > 0;
    node('cooling-md12xx').hidden = !show.checked && !saved && !(current && current.md12xxAvailable);
  }
  show.addEventListener('change', function () {
    try { window.localStorage.setItem('waz.cooling.showUnavailable', show.checked ? 'yes' : 'no'); } catch (_) {}
    visibility();
  });
  function render(data) {
    current = data;
    visibility();
    node('cooling-ipmi-description').textContent = (data.model || 'Unknown host') + ' · ' + data.message;
    node('cooling-ipmi-health').textContent = !data.available ? 'UNAVAILABLE' : data.stale ? 'STALE' : (data.state || 'normal').toUpperCase();
    node('cooling-ipmi-readings').textContent = 'CPU ' + (data.cpuMaxC == null ? '—' : data.cpuMaxC + '°C') + ' · Inlet ' + (data.inletC == null ? '—' : data.inletC + '°C') + ' · Fans ' + (data.averageRpm == null ? '—' : data.averageRpm + ' RPM') + ' · ' + (data.targetReason || 'iDRAC automatic');
    node('cooling-ipmi-save').disabled = !data.available || data.stale;
    if (!initialized && data.config) {
      node('cooling-ipmi-enabled').checked = data.config.enabled;
      node('cooling-ipmi-mode').value = data.config.mode;
      node('cooling-ipmi-speed').value = String(data.config.manualSpeed);
      node('cooling-ipmi-curve-source').value = data.config.curveMode;
      window.WAZCoolingCurves.mount(node('cooling-ipmi-curve'), data.config.curve || data.globalCurve);
      node('cooling-ipmi-curve').hidden = data.config.curveMode !== 'individual';
      initialized = true;
    }
  }
  async function refresh() {
    try {
      var responses = await Promise.all([fetch(endpoint, { cache: 'no-store', credentials: 'same-origin' }), fetch('/plugins/md12xx.fancontrol/include/api.php', { cache: 'no-store', credentials: 'same-origin' })]);
      if (!responses[0].ok || !responses[1].ok) throw new Error('Cooling status unavailable');
      var data = await responses[0].json(), shelves = await responses[1].json();
      data.md12xxAvailable = shelves.md12xxAvailable;
      render(data);
    } catch (error) { node('cooling-ipmi-message').textContent = error.message; node('cooling-ipmi-save').disabled = true; }
    window.setTimeout(refresh, 5000);
  }
  async function save(config) {
    var token = String(window.csrf_token || '');
    if (!token) { node('cooling-ipmi-message').textContent = 'Reload the page to obtain the current session token'; return; }
    if (config.enabled && config.mode !== 'firmware' && !window.confirm('Apply chassis fan control with thermal guards? iDRAC automatic cooling will resume on a detected fault.')) return;
    try {
      var body = new URLSearchParams(); body.set('csrf_token', token); body.set('config', JSON.stringify(config));
      var response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
      var payload = await response.json();
      if (!response.ok) throw new Error(payload.error || 'Could not save cooling settings');
      node('cooling-ipmi-message').textContent = payload.message;
      node('cooling-ipmi-enabled').checked = config.enabled; node('cooling-ipmi-mode').value = config.mode;
    } catch (error) { node('cooling-ipmi-message').textContent = error.message; }
  }
  node('cooling-ipmi-curve-source').addEventListener('change', function () { node('cooling-ipmi-curve').hidden = this.value !== 'individual'; });
  function draft() { return { enabled: node('cooling-ipmi-enabled').checked, mode: node('cooling-ipmi-mode').value, manualSpeed: Number(node('cooling-ipmi-speed').value), curveMode: node('cooling-ipmi-curve-source').value, curve: window.WAZCoolingCurves.read(node('cooling-ipmi-curve')) }; }
  node('cooling-ipmi-save').addEventListener('click', function () { save(draft()); });
  node('cooling-ipmi-restore').addEventListener('click', function () { var settings = current && current.config ? JSON.parse(JSON.stringify(current.config)) : { enabled: false, mode: 'firmware', manualSpeed: 60 }; settings.enabled = false; settings.mode = 'firmware'; save(settings); });
  visibility(); refresh();
})();
