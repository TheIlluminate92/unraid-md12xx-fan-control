(function () {
  "use strict";

  var boot = window.MD12xxBootstrap || {};
  var endpoint = boot.endpoint || "/plugins/md12xx.fancontrol/include/api.php";
  var downloadEndpoint = endpoint.replace(/api\.php(?:\?.*)?$/, "download.php");
  var config = JSON.parse(JSON.stringify(boot.config || {}));
  var discovery = { serialPorts: [], sesDevices: [], disks: [] };
  var stateById = {};
  var controllerState = {};
  var commissionJobs = {};
  var commissionTimers = {};

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
    var shelves = Array.isArray(config.shelves) ? config.shelves : [];
    var setupComplete = shelves.length > 0 && shelves.every(function (shelf) { return !!shelf.commissioned; });
    var text = ports.length + " serial adapter" + (ports.length === 1 ? "" : "s") + ", " + ses.length + " SES enclosure" + (ses.length === 1 ? "" : "s") + ", " + verified + " verified MD12xx console" + (verified === 1 ? "" : "s") + ".";
    if (blocked.length) text += " Active probing blocked by: " + blocked.join(", ") + ".";
    else if (discovery.autoProbeKnownFtdi && !discovery.activeProbeAllowed) text += " Active probing is paused while fan control is enabled.";
    if (setupComplete && discovery.autoProbeKnownFtdi) text += " Setup is complete; turn off Test likely FTDI adapters unless you are troubleshooting.";
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
  function assignmentMode(shelf) {
    if (shelf.diskAssignment === "automatic" || shelf.diskAssignment === "manual") return shelf.diskAssignment;
    return Array.isArray(shelf.disks) && shelf.disks.length ? "manual" : "automatic";
  }
  function selectedSes(shelf) {
    var devices = Array.isArray(discovery.sesDevices) ? discovery.sesDevices : [];
    return devices.find(function (item) {
      return (shelf.sesAddress && item.address === shelf.sesAddress) || (!shelf.sesAddress && shelf.sesDevice && item.device === shelf.sesDevice);
    }) || null;
  }
  function assignmentSummary(shelf) {
    if (assignmentMode(shelf) === "manual") {
      var manual = Array.isArray(shelf.disks) ? shelf.disks : [];
      return manual.length ? "Manual override: " + manual.join(", ") : "Manual override selected; choose at least one Unraid disk below.";
    }
    var ses = selectedSes(shelf);
    var disks = ses && Array.isArray(ses.disks) && ses.disks.length ? ses.disks : (Array.isArray(shelf.disks) ? shelf.disks : []);
    if (disks.length) return "Automatically detected: " + disks.join(", ");
    if (!shelf.sesAddress && !shelf.sesDevice) return "Select a verified serial adapter, save, then run Identify & test to find its enclosure and disks.";
    return (ses && ses.diskMappingMessage) || "No automatic disk mapping was found. Open Manual mapping only if the identification test cannot resolve it.";
  }
  function mappedDisks(shelf) {
    var ses = selectedSes(shelf);
    var disks = assignmentMode(shelf) === "automatic" && ses && Array.isArray(ses.disks) && ses.disks.length ? ses.disks : shelf.disks;
    return Array.isArray(disks) ? disks : [];
  }

  function commissionPhaseLabel(phase) {
    return {
      "not-started": "Ready",
      "starting": "Starting…",
      "verifying-console": "Verifying console…",
      "testing-20": "Recording 20% baseline…",
      "testing-50": "Testing 50% response…",
      "restoring": "Returning to 20%…",
      "verifying-restore": "Verifying final 20% state…",
      "passed": "Passed",
      "failed": "Failed"
    }[phase] || phase || "Ready";
  }

  function updateCommissionCard(id) {
    var card = Array.prototype.find.call(document.querySelectorAll(".md12xx-shelf"), function (item) { return item.getAttribute("data-id") === id; });
    if (!card) return;
    var job = commissionJobs[id] || { phase: "not-started", running: false, output: "" };
    var button = card.querySelector(".md12xx-commission-start");
    var phase = card.querySelector(".md12xx-commission-phase");
    var output = card.querySelector(".md12xx-commission-output");
    var result = card.querySelector(".md12xx-commission-result");
    button.disabled = !!job.running || !!config.enabled || !card.querySelector(".md12xx-port").value;
    button.textContent = job.running ? "Identify & test running…" : "Identify & test";
    phase.textContent = commissionPhaseLabel(job.phase);
    phase.className = "md12xx-commission-phase is-" + (job.phase === "passed" ? "passed" : job.phase === "failed" ? "failed" : job.running ? "running" : "ready");
    output.textContent = job.output || "";
    output.hidden = !job.output;
    if (!output.hidden) output.scrollTop = output.scrollHeight;
    result.hidden = !job.resultFile;
    if (job.resultFile) result.href = downloadEndpoint + "?type=commissioning&file=" + encodeURIComponent(job.resultFile);
  }

  function updateShelfStatus() {
    Array.prototype.forEach.call(document.querySelectorAll(".md12xx-shelf"), function (card) {
      var id = card.getAttribute("data-id");
      var status = stateById[id] || {};
      var values = {
        rpm: status.averageRpm == null ? "—" : status.averageRpm,
        temperature: status.temperatureC == null ? "—" : status.temperatureC + "°C",
        target: status.targetPercent == null ? "—" : status.targetPercent + "%",
        state: status.writeState || status.telemetryState || "—"
      };
      Object.keys(values).forEach(function (key) {
        var node = card.querySelector('[data-status="' + key + '"]');
        if (node) node.textContent = values[key];
      });
      var stateNode = card.querySelector('[data-status="state"]');
      if (stateNode) stateNode.title = status.writeMessage || status.telemetryMessage || "";
      updateCommissionCard(id);
    });
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
      var assignment = assignmentMode(shelf);
      return '<article class="md12xx-shelf" data-index="' + index + '" data-id="' + esc(shelf.id) + '" data-commissioned="' + (commissioned ? "1" : "0") + '">' +
        '<div class="md12xx-shelf-head"><strong class="md12xx-shelf-title">' + esc(shelf.name || shelf.model || "Shelf") + '</strong><button type="button" class="md12xx-remove">Remove</button></div>' +
        '<div class="md12xx-shelf-grid">' +
          '<label><span>Name</span><input class="md12xx-name" maxlength="80" value="' + esc(shelf.name || "") + '"></label>' +
          '<label><span>Model</span><select class="md12xx-model">' + option("MD1200", "Dell PowerVault MD1200", shelf.model !== "MD1220") + option("MD1220", "Dell PowerVault MD1220", shelf.model === "MD1220") + '</select></label>' +
          '<label><span>Serial adapter</span><select class="md12xx-port">' + serialOptions(shelf.serialPort || "") + '</select><small>This is the only hardware choice needed for automatic setup</small></label>' +
          '<label><span>Disk assignment</span><select class="md12xx-assignment">' + option("automatic", "Automatic from detected SES enclosure", assignment === "automatic") + option("manual", "Manual override", assignment === "manual") + '</select><small class="md12xx-assignment-summary">' + esc(assignmentSummary(shelf)) + '</small></label>' +
          '<label><span>Shelf enabled</span><input class="md12xx-shelf-enabled" type="checkbox"' + (shelf.enabled !== false ? " checked" : "") + '><small>Commissioned: ' + (commissioned ? "yes" : "no") + '</small></label>' +
        '</div>' +
        '<div class="md12xx-mapped-disks"><strong>Associated Unraid disks</strong>' + esc(mappedDisks(shelf).length ? mappedDisks(shelf).join(", ") : "None detected yet") + '</div>' +
        '<details class="md12xx-manual"' + (assignment === "manual" ? " open" : "") + '><summary>Manual mapping fallback</summary><div class="md12xx-shelf-grid">' +
          '<label><span>SES enclosure</span><select class="md12xx-ses">' + sesOptions(shelf) + '</select><small>Normally filled by Identify & test</small></label>' +
          '<label><span>Assigned Unraid disks</span><select class="md12xx-disks" multiple>' + diskOptions(shelf.disks) + '</select><small>Used only in Manual override mode; Ctrl/Cmd-click for multiple disks</small></label>' +
        '</div></details>' +
        '<div class="md12xx-status">' +
          '<span>RPM<b data-status="rpm">' + esc(status.averageRpm == null ? "—" : status.averageRpm) + '</b></span>' +
          '<span>Temperature<b data-status="temperature">' + esc(status.temperatureC == null ? "—" : status.temperatureC + "°C") + '</b></span>' +
          '<span>Target<b data-status="target">' + esc(status.targetPercent == null ? "—" : status.targetPercent + "%") + '</b></span>' +
          '<span>State<b data-status="state" title="' + esc(status.writeMessage || status.telemetryMessage || "") + '">' + esc(status.writeState || status.telemetryState || "—") + '</b></span>' +
        '</div>' +
        '<div class="md12xx-commission"><div class="md12xx-commission-head"><button type="button" class="md12xx-commission-start">Identify &amp; test</button><strong class="md12xx-commission-phase">Ready</strong></div>' +
          '<p>Verifies the console, runs 20% → 50% → 20%, identifies the responding SES enclosure, saves its disks, and proves the final 20% state.</p>' +
          '<pre class="md12xx-commission-output" hidden></pre><a class="md12xx-commission-result" href="#" hidden>Download test results</a></div>' +
      '</article>';
    }).join("");
    Array.prototype.forEach.call(root.querySelectorAll(".md12xx-remove"), function (button) {
      button.addEventListener("click", function () {
        var card = button.closest(".md12xx-shelf");
        config.shelves.splice(Number(card.getAttribute("data-index")), 1);
        renderShelves();
      });
    });
    Array.prototype.forEach.call(root.querySelectorAll(".md12xx-assignment"), function (select) {
      select.addEventListener("change", function () {
        var card = select.closest(".md12xx-shelf");
        var details = card.querySelector(".md12xx-manual");
        if (select.value === "manual") details.open = true;
        var disks = Array.prototype.map.call(card.querySelector(".md12xx-disks").selectedOptions, function (item) { return item.value; });
        var sesValue = card.querySelector(".md12xx-ses").value.split("|");
        var preview = { diskAssignment: select.value, disks: disks, sesAddress: sesValue[0] || "", sesDevice: sesValue[1] || "" };
        card.querySelector(".md12xx-assignment-summary").textContent = assignmentSummary(preview);
      });
    });
    Array.prototype.forEach.call(root.querySelectorAll(".md12xx-name"), function (input) {
      var timer = null;
      var updateTitle = function () {
        var card = input.closest(".md12xx-shelf");
        var model = card.querySelector(".md12xx-model").value;
        card.querySelector(".md12xx-shelf-title").textContent = input.value.trim() || model || "Shelf";
      };
      input.addEventListener("input", function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(updateTitle, 400);
      });
      input.addEventListener("blur", function () { window.clearTimeout(timer); updateTitle(); });
    });
    Array.prototype.forEach.call(root.querySelectorAll(".md12xx-commission-start"), function (button) {
      button.addEventListener("click", function () {
        var id = button.closest(".md12xx-shelf").getAttribute("data-id");
        startCommission(id);
      });
    });
    updateShelfStatus();
  }

  function renderCurve() {
    var curve = Array.isArray(config.curve) && config.curve.length ? config.curve : [
      { temperatureC: 0, speed: 20 }, { temperatureC: 35, speed: 25 }, { temperatureC: 45, speed: 30 }, { temperatureC: 50, speed: 50 }
    ];
    byId("md12xx-curve-points").value = String(curve.length);
    byId("md12xx-curve").style.setProperty("--md12xx-curve-columns", String(Math.min(curve.length, 6)));
    byId("md12xx-curve").innerHTML = curve.map(function (step, index) {
      return '<div class="md12xx-curve-step"><strong>Point ' + (index + 1) + '</strong><label><span>°C</span><input class="md12xx-curve-temp" type="number" min="0" max="100" step="0.5" value="' + esc(step.temperatureC) + '"></label>' +
        '<label><span>%</span><input class="md12xx-curve-speed" type="number" min="20" max="100" step="5" value="' + esc(step.speed) + '"></label></div>';
    }).join("");
  }
  function resizeCurve(count) {
    config = collect();
    count = Math.max(2, Math.min(10, Number(count) || 4));
    var curve = Array.isArray(config.curve) ? config.curve.slice(0, count) : [];
    while (curve.length < count) {
      var previous = curve.length ? curve[curve.length - 1] : { temperatureC: 0, speed: 20 };
      if (Number(previous.temperatureC) >= 100 && curve.length > 1) {
        var before = curve[curve.length - 2];
        curve.splice(curve.length - 1, 0, { temperatureC: (Number(before.temperatureC) + Number(previous.temperatureC)) / 2, speed: Number(previous.speed) });
      } else {
        curve.push({ temperatureC: Math.min(100, Number(previous.temperatureC) + 5), speed: Number(previous.speed) });
      }
    }
    config.curve = curve;
    renderCurve();
  }

  function loadGlobals() {
    byId("md12xx-enabled").checked = !!config.enabled;
    byId("md12xx-mode").value = config.mode === "manual" ? "manual" : "auto";
    byId("md12xx-manual").value = String(config.manualSpeed || 20);
    byId("md12xx-poll").value = String(config.pollSeconds || 5);
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
        diskAssignment: card.querySelector(".md12xx-assignment").value,
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
      controllerState = controller;
      var health = byId("md12xx-health");
      var state = payload.enabled ? (controller.state || (payload.stale ? "fault" : "normal")) : "disabled";
      health.className = "md12xx-pill is-" + (state === "normal" ? "normal" : state === "fault" ? "fault" : "attention");
      health.textContent = String(state).toUpperCase();
      config = collect();
      updateShelfStatus();
    } catch (error) {
      byId("md12xx-health").className = "md12xx-pill is-fault";
      byId("md12xx-health").textContent = "UNAVAILABLE";
    }
  }
  async function save(showConfirmation) {
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
      if (showConfirmation !== false) message("Configuration saved. Hardware remapping clears commissioning until Identify & test passes.", false);
      await refreshStatus();
      return true;
    } catch (error) { message(error.message || String(error), true); return false; }
  }
  async function diagnostics() {
    try {
      var token = String(window.csrf_token || "");
      if (!token) throw new Error("The current Unraid session token is unavailable; reload this page");
      var body = new URLSearchParams({ action: "diagnostics", csrf_token: token });
      var response = await fetch(endpoint, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" }, body: body.toString() });
      var payload = await response.json();
      if (!response.ok) throw new Error(payload.error || "Diagnostics failed");
      message("Local redacted diagnostics created and downloaded. Nothing was uploaded.", false);
      window.location.assign(downloadEndpoint + "?type=diagnostics&file=" + encodeURIComponent(payload.file));
    } catch (error) { message(error.message || String(error), true); }
  }

  async function loadFreshConfig() {
    var response = await fetch(endpoint + "?action=config&_=" + Date.now(), { cache: "no-store", credentials: "same-origin" });
    var payload = await response.json();
    if (!response.ok) throw new Error(payload.error || "Configuration refresh failed");
    config = payload.config;
    loadGlobals();
  }

  async function pollCommission(id) {
    window.clearTimeout(commissionTimers[id]);
    try {
      var response = await fetch(endpoint + "?action=commission&id=" + encodeURIComponent(id) + "&_=" + Date.now(), { cache: "no-store", credentials: "same-origin" });
      var job = await response.json();
      if (!response.ok) throw new Error(job.error || "Unable to read commissioning progress");
      commissionJobs[id] = job;
      updateCommissionCard(id);
      if (job.running) {
        commissionTimers[id] = window.setTimeout(function () { pollCommission(id); }, 1000);
      } else if (job.phase === "passed") {
        await loadFreshConfig();
        await discover();
        message("Identify & test passed. Review the detected enclosure and associated disks before enabling control.", false);
      } else if (job.phase === "failed") {
        await loadFreshConfig();
        message("Identify & test failed. Review the result below; the shelf remains uncommissioned.", true);
      }
    } catch (error) {
      message(error.message || String(error), true);
      commissionTimers[id] = window.setTimeout(function () { pollCommission(id); }, 2000);
    }
  }

  async function startCommission(id) {
    if (config.enabled || byId("md12xx-enabled").checked) {
      message("Disable this controller before running Identify & test.", true);
      return;
    }
    if (!window.confirm("Identify this shelf now? Its fans will run at 20%, then 50%, then return to 20%. The test takes about one minute and must not be interrupted.")) return;
    if (!await save(false)) return;
    try {
      var token = String(window.csrf_token || "");
      if (!token) throw new Error("The current Unraid session token is unavailable; reload this page");
      var body = new URLSearchParams({ action: "commission", csrf_token: token, id: id });
      var response = await fetch(endpoint, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" }, body: body.toString() });
      var payload = await response.json();
      if (!response.ok) throw new Error(payload.error || "Unable to start Identify & test");
      commissionJobs[id] = payload.job;
      updateCommissionCard(id);
      message("Identify & test started. It continues safely even if this page is closed.", false);
      pollCommission(id);
    } catch (error) { message(error.message || String(error), true); }
  }

  function resumeCommissionJobs() {
    (config.shelves || []).forEach(function (shelf) { pollCommission(shelf.id); });
  }

  byId("md12xx-add").addEventListener("click", function () {
    config = collect();
    var number = config.shelves.length + 1;
    var id = "shelf-" + number;
    while (config.shelves.some(function (item) { return item.id === id; })) { number++; id = "shelf-" + number; }
    config.shelves.push({ id: id, name: "MD1200 Shelf " + number, model: "MD1200", enabled: true, commissioned: false, serialPort: "", sesDevice: "", sesAddress: "", diskAssignment: "automatic", disks: [] });
    renderShelves();
  });
  byId("md12xx-refresh").addEventListener("click", function () { config = collect(); discover().then(function () { message("Discovery refreshed.", false); }).catch(function (error) { message(error.message, true); }); });
  byId("md12xx-diagnostics").addEventListener("click", diagnostics);
  byId("md12xx-curve-points").addEventListener("change", function () { resizeCurve(this.value); });
  byId("md12xx-help-toggle").addEventListener("click", function () {
    var help = byId("md12xx-help");
    help.hidden = !help.hidden;
    this.setAttribute("aria-expanded", help.hidden ? "false" : "true");
    this.textContent = help.hidden ? "Setup directions" : "Hide directions";
  });
  byId("md12xx-save").addEventListener("click", function () { save(true); });

  loadGlobals();
  discover().catch(function (error) { message(error.message, true); });
  refreshStatus();
  resumeCommissionJobs();
  window.setInterval(refreshStatus, 5000);
}());
