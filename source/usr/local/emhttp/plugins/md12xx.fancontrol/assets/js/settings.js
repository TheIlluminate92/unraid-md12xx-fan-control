(function () {
  "use strict";

  var boot = window.MD12xxBootstrap || {};
  var endpoint = boot.endpoint || "/plugins/md12xx.fancontrol/include/api.php";
  var config = JSON.parse(JSON.stringify(boot.config || {}));
  var discovery = { serialPorts: [], sesDevices: [], disks: [] };
  var stateById = {};

  function byId(id) { return document.getElementById(id); }
  function esc(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (ch) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch];
    });
  }
  function message(text, bad) {
    var node = byId("md12xx-message");
    node.hidden = !text;
    node.textContent = text || "";
    node.style.borderLeftColor = bad ? "#ff6b6b" : "#70df9b";
  }
  function option(value, label, selected) {
    return '<option value="' + esc(value) + '"' + (selected ? " selected" : "") + ">" + esc(label) + "</option>";
  }
  function slug(value) {
    var result = String(value || "shelf").toLowerCase().replace(/[^a-z0-9_-]+/g, "-").replace(/^-+|-+$/g, "");
    return (result || "shelf").slice(0, 48);
  }

  function serialOptions(selected) {
    var values = discovery.serialPorts.map(function (item) {
      return typeof item === "string" ? { path: item, probeState: "passive-only" } : item;
    });
    if (selected && !values.some(function (item) { return item.path === selected; })) values.unshift({ path: selected, probeState: "saved" });
    return option("", "Select persistent serial adapter…", !selected) + values.map(function (item) {
      var suffix = item.consoleVerified ? " · MD12xx verified" : item.knownFtdiCandidate ? " · FTDI candidate" : " · " + (item.probeState || "detected");
      return option(item.path, item.path + suffix, item.path === selected);
    }).join("");
  }

  function renderDiscoverySummary() {
    var ports = Array.isArray(discovery.serialPorts) ? discovery.serialPorts : [];
    var ses = Array.isArray(discovery.sesDevices) ? discovery.sesDevices : [];
    var verified = ports.filter(function (item) { return item && typeof item === "object" && item.consoleVerified; }).length;
    var blocked = Array.isArray(discovery.blockedBy) ? discovery.blockedBy : [];
    var text = ports.length + " serial adapter" + (ports.length === 1 ? "" : "s") + ", " + ses.length + " SES enclosure" + (ses.length === 1 ? "" : "s") + ", " + verified + " verified MD12xx console" + (verified === 1 ? "" : "s") + ".";
    if (blocked.length) text += " Active probing blocked by: " + blocked.join(", ") + ".";
    else if (discovery.autoProbeKnownFtdi && !discovery.activeProbeAllowed) text += " Active probing is paused while fan control is enabled.";
    byId("md12xx-discovery-summary").textContent = text;
  }
  function sesOptions(shelf) {
    var selected = (shelf.sesAddress || "") + "|" + (shelf.sesDevice || "");
    var values = discovery.sesDevices.slice();
    if (shelf.sesAddress && !values.some(function (item) { return item.address === shelf.sesAddress; })) {
      values.unshift({ address: shelf.sesAddress, device: shelf.sesDevice, vendor: "Saved", model: "mapping" });
    }
    return option("|", "Select SES enclosure…", !shelf.sesAddress && !shelf.sesDevice) + values.map(function (item) {
      var value = String(item.address || "") + "|" + String(item.device || "");
      var label = [item.device, item.address, item.vendor, item.model].filter(Boolean).join(" · ");
      return option(value, label, value === selected);
    }).join("");
  }
  function diskOptions(selected) {
    selected = Array.isArray(selected) ? selected : [];
    var values = discovery.disks.map(function (disk) { return disk.name; });
    selected.forEach(function (name) { if (values.indexOf(name) < 0) values.push(name); });
    return values.map(function (name) { return option(name, name, selected.indexOf(name) >= 0); }).join("");
  }

  function renderShelves() {
    var root = byId("md12xx-shelves");
    var shelves = Array.isArray(config.shelves) ? config.shelves : [];
    if (!shelves.length) {
      root.innerHTML = '<p class="md12xx-empty">No shelves configured. Discovery is read-only; add a shelf to begin mapping hardware.</p>';
      return;
    }
    root.innerHTML = shelves.map(function (shelf, index) {
      var status = stateById[shelf.id] || {};
      var commissioned = !!shelf.commissioned;
      return '<article class="md12xx-shelf" data-index="' + index + '" data-id="' + esc(shelf.id) + '" data-commissioned="' + (commissioned ? "1" : "0") + '">' +
        '<div class="md12xx-shelf-head"><strong>' + esc(shelf.name || shelf.model || "Shelf") + '</strong><button type="button" class="md12xx-remove">Remove</button></div>' +
        '<div class="md12xx-shelf-grid">' +
          '<label><span>Name</span><input class="md12xx-name" maxlength="80" value="' + esc(shelf.name || "") + '"></label>' +
          '<label><span>Model</span><select class="md12xx-model">' + option("MD1200", "Dell PowerVault MD1200", shelf.model !== "MD1220") + option("MD1220", "Dell PowerVault MD1220", shelf.model === "MD1220") + '</select></label>' +
          '<label><span>Serial adapter</span><select class="md12xx-port">' + serialOptions(shelf.serialPort || "") + '</select><small>Must be a /dev/serial/by-id path</small></label>' +
          '<label><span>SES enclosure</span><select class="md12xx-ses">' + sesOptions(shelf) + '</select><small>The stable SCSI address survives /dev/sg renumbering</small></label>' +
          '<label><span>Assigned Unraid disks</span><select class="md12xx-disks" multiple>' + diskOptions(shelf.disks) + '</select><small>Ctrl/Cmd-click to select multiple disks</small></label>' +
          '<label><span>Shelf enabled</span><input class="md12xx-shelf-enabled" type="checkbox"' + (shelf.enabled !== false ? " checked" : "") + '><small>Commissioned: ' + (commissioned ? "yes" : "no") + '</small></label>' +
        '</div>' +
        '<div class="md12xx-status">' +
          '<span>RPM<b>' + esc(status.averageRpm == null ? "—" : status.averageRpm) + '</b></span>' +
          '<span>Temperature<b>' + esc(status.temperatureC == null ? "—" : status.temperatureC + "°C") + '</b></span>' +
          '<span>Target<b>' + esc(status.targetPercent == null ? "—" : status.targetPercent + "%") + '</b></span>' +
          '<span>State<b>' + esc(status.writeState || status.telemetryState || "—") + '</b></span>' +
        '</div>' +
        '<div class="md12xx-commission">Commission: /usr/local/emhttp/plugins/md12xx.fancontrol/scripts/commission.sh ' + esc(shelf.id) + '</div>' +
      '</article>';
    }).join("");
    Array.prototype.forEach.call(root.querySelectorAll(".md12xx-remove"), function (button) {
      button.addEventListener("click", function () {
        var card = button.closest(".md12xx-shelf");
        config.shelves.splice(Number(card.getAttribute("data-index")), 1);
        renderShelves();
      });
    });
  }

  function renderCurve() {
    var curve = Array.isArray(config.curve) && config.curve.length ? config.curve : [
      { temperatureC: 0, speed: 20 }, { temperatureC: 35, speed: 25 }, { temperatureC: 45, speed: 30 }, { temperatureC: 50, speed: 50 }
    ];
    byId("md12xx-curve").innerHTML = curve.map(function (step) {
      return '<div class="md12xx-curve-step"><label><span>At °C</span><input class="md12xx-curve-temp" type="number" min="0" max="100" step="0.5" value="' + esc(step.temperatureC) + '"></label>' +
        '<label><span>Speed %</span><input class="md12xx-curve-speed" type="number" min="20" max="100" step="5" value="' + esc(step.speed) + '"></label></div>';
    }).join("");
  }

  function loadGlobals() {
    byId("md12xx-enabled").checked = !!config.enabled;
    byId("md12xx-mode").value = config.mode === "manual" ? "manual" : "auto";
    byId("md12xx-manual").value = String(config.manualSpeed || 20);
    byId("md12xx-poll").value = String(config.pollSeconds || 30);
    byId("md12xx-reassert").value = String(config.reassertSeconds || 900);
    byId("md12xx-failsafe").value = String(config.sensorFailureSpeed || 50);
    var discoveryConfig = config.discovery || {};
    byId("md12xx-probe-ftdi").checked = !!discoveryConfig.autoProbeKnownFtdi;
    byId("md12xx-discovery-interval").value = String(discoveryConfig.intervalSeconds || 300);
    byId("md12xx-response-seconds").value = String(discoveryConfig.responseSeconds || 3);
    byId("md12xx-hysteresis").value = String(config.hysteresisC == null ? 1 : config.hysteresisC);
    renderCurve();
    renderShelves();
  }

  function collect() {
    var next = {
      enabled: byId("md12xx-enabled").checked,
      mode: byId("md12xx-mode").value,
      manualSpeed: Number(byId("md12xx-manual").value),
      pollSeconds: Number(byId("md12xx-poll").value),
      reassertSeconds: Number(byId("md12xx-reassert").value),
      sensorFailureSpeed: Number(byId("md12xx-failsafe").value),
      hysteresisC: Number(byId("md12xx-hysteresis").value),
      discovery: {
        autoProbeKnownFtdi: byId("md12xx-probe-ftdi").checked,
        intervalSeconds: Number(byId("md12xx-discovery-interval").value),
        responseSeconds: Number(byId("md12xx-response-seconds").value)
      },
      legacyContainerNames: Array.isArray(config.legacyContainerNames) ? config.legacyContainerNames : ["MD1200-Fan-Controller"],
      curve: [], shelves: []
    };
    var temps = document.querySelectorAll(".md12xx-curve-temp");
    var speeds = document.querySelectorAll(".md12xx-curve-speed");
    for (var i = 0; i < temps.length; i++) next.curve.push({ temperatureC: Number(temps[i].value), speed: Number(speeds[i].value) });
    Array.prototype.forEach.call(document.querySelectorAll(".md12xx-shelf"), function (card, index) {
      var ses = card.querySelector(".md12xx-ses").value.split("|");
      var disks = Array.prototype.map.call(card.querySelector(".md12xx-disks").selectedOptions, function (item) { return item.value; });
      var name = card.querySelector(".md12xx-name").value.trim();
      next.shelves.push({
        id: card.getAttribute("data-id") || slug(name || "shelf-" + (index + 1)),
        name: name,
        model: card.querySelector(".md12xx-model").value,
        enabled: card.querySelector(".md12xx-shelf-enabled").checked,
        commissioned: card.getAttribute("data-commissioned") === "1",
        serialPort: card.querySelector(".md12xx-port").value,
        sesAddress: ses[0] || "",
        sesDevice: ses[1] || "",
        disks: disks
      });
    });
    return next;
  }

  async function discover() {
    var response = await fetch(endpoint + "?action=discover&_=" + Date.now(), { cache: "no-store", credentials: "same-origin" });
    var payload = await response.json();
    if (!response.ok) throw new Error(payload.error || "Discovery failed");
    discovery = payload;
    renderDiscoverySummary();
    renderShelves();
  }
  async function refreshStatus() {
    try {
      var response = await fetch(endpoint + "?_=" + Date.now(), { cache: "no-store", credentials: "same-origin" });
      var payload = await response.json();
      stateById = {};
      (payload.shelves || []).forEach(function (shelf) { stateById[shelf.id] = shelf; });
      var controller = payload.controller || {};
      var health = byId("md12xx-health");
      var state = payload.enabled ? (controller.state || (payload.stale ? "fault" : "normal")) : "disabled";
      health.className = "md12xx-pill is-" + (state === "normal" ? "normal" : state === "fault" ? "fault" : "attention");
      health.textContent = String(state).toUpperCase();
      config = collect();
      renderShelves();
    } catch (error) {
      byId("md12xx-health").className = "md12xx-pill is-fault";
      byId("md12xx-health").textContent = "UNAVAILABLE";
    }
  }
  async function save() {
    try {
      var next = collect();
      var token = String(window.csrf_token || "");
      if (!token) throw new Error("The current Unraid session token is unavailable; reload this page");
      var body = new URLSearchParams({ action: "save", csrf_token: token, config: JSON.stringify(next) });
      var response = await fetch(endpoint, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" }, body: body.toString() });
      var payload = await response.json();
      if (!response.ok) throw new Error(payload.error || "Save failed");
      config = payload.config;
      loadGlobals();
      message("Configuration saved. Hardware remapping clears commissioning until the guarded test passes.", false);
      await refreshStatus();
    } catch (error) { message(error.message || String(error), true); }
  }

  byId("md12xx-add").addEventListener("click", function () {
    config = collect();
    var number = config.shelves.length + 1;
    var id = "shelf-" + number;
    while (config.shelves.some(function (item) { return item.id === id; })) { number++; id = "shelf-" + number; }
    config.shelves.push({ id: id, name: "MD1200 Shelf " + number, model: "MD1200", enabled: true, commissioned: false, serialPort: "", sesDevice: "", sesAddress: "", disks: [] });
    renderShelves();
  });
  byId("md12xx-refresh").addEventListener("click", function () { config = collect(); discover().then(function () { message("Discovery refreshed.", false); }).catch(function (error) { message(error.message, true); }); });
  byId("md12xx-save").addEventListener("click", save);

  loadGlobals();
  discover().catch(function (error) { message(error.message, true); });
  refreshStatus();
  window.setInterval(refreshStatus, 5000);
}());
