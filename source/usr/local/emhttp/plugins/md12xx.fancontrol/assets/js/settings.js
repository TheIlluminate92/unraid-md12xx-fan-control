(function () {
  "use strict";

  var boot = window.MD12xxBootstrap || {};
  var endpoint = boot.endpoint || "/plugins/md12xx.fancontrol/include/api.php";
  var downloadEndpoint = endpoint.replace(/api\.php(?:\?.*)?$/, "download.php");
  var config = JSON.parse(JSON.stringify(boot.config || {}));
  var persistedConfig = JSON.parse(JSON.stringify(boot.config || {}));
  var discovery = { serialPorts: [], sesDevices: [], disks: [] };
  var stateById = {};
  var controllerState = {};
  var commissionJobs = {};
  var commissionTimers = {};
  var curveResizeTimer = null;

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
  function option(value, label, selected, disabled) {
    return '<option value="' + esc(value) + '"' + (selected ? " selected" : "") + (disabled ? " disabled" : "") + ">" + esc(label) + "</option>";
  }
  function downloadLocalArchive(type, file) {
    var link = document.createElement("a");
    link.href = downloadEndpoint + "?type=" + encodeURIComponent(type) + "&file=" + encodeURIComponent(file);
    link.download = file;
    link.hidden = true;
    document.body.appendChild(link);
    link.click();
    window.setTimeout(function () { link.remove(); }, 0);
  }
  function slug(value) {
    var result = String(value || "shelf").toLowerCase().replace(/[^a-z0-9_-]+/g, "-").replace(/^-+|-+$/g, "");
    return (result || "shelf").slice(0, 48);
  }
  function liveControlSignature(value) {
    var shelves = Array.isArray(value.shelves) ? value.shelves : [];
    return JSON.stringify({
      mode: value.mode,
      manualSpeed: value.manualSpeed,
      reassertSeconds: value.reassertSeconds,
      sensorFailureSpeed: value.sensorFailureSpeed,
      hysteresisC: value.hysteresisC,
      curve: value.curve,
      shelves: shelves.map(function (shelf) {
        return {
          id: shelf.id,
          enabled: shelf.enabled,
          commissioned: shelf.commissioned,
          model: shelf.model,
          serialPort: shelf.serialPort,
          sesAddress: shelf.sesAddress,
          sesDevice: shelf.sesDevice,
          diskAssignment: shelf.diskAssignment,
          disks: shelf.disks
        };
      })
    });
  }

  function valuesUsedByOtherShelves(key, shelfId) {
    var used = {};
    (Array.isArray(config.shelves) ? config.shelves : []).forEach(function (shelf) {
      if (shelf.id === shelfId) return;
      var values = key === "disks" ? (Array.isArray(shelf.disks) ? shelf.disks : []) : [shelf[key]];
      values.forEach(function (value) { if (value) used[String(value)] = true; });
    });
    return used;
  }

  function serialOptions(selected, shelfId) {
    var values = discovery.serialPorts.map(function (item) {
      return typeof item === "string" ? { path: item, probeState: "passive-only" } : item;
    });
    var used = valuesUsedByOtherShelves("serialPort", shelfId);
    if (selected && !values.some(function (item) { return item.path === selected; })) values.unshift({ path: selected, probeState: "saved" });
    return option("", "Select persistent serial adapter…", !selected) + values.map(function (item) {
      var suffix = item.consoleVerified ? " · MD12xx verified" : item.knownFtdiCandidate ? " · FTDI candidate" : " · " + (item.probeState || "detected");
      if (item.message && ["fault", "busy", "missing", "no-response", "unrecognized"].indexOf(item.probeState) >= 0) suffix += " · " + item.message;
      if (used[item.path]) suffix += " · already assigned";
      return option(item.path, item.path + suffix, item.path === selected, !!used[item.path]);
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
    if (discovery.error) text += " Discovery error: " + discovery.error + ".";
    if (discovery.stale) text += " Discovery data is stale; the background worker may not be running.";
    if (blocked.length) text += " Active probing blocked by: " + blocked.join(", ") + ".";
    else if (discovery.autoProbeKnownFtdi && !discovery.activeProbeAllowed) text += " Active probing is paused while fan control is enabled.";
    if (setupComplete && discovery.autoProbeKnownFtdi) text += " Setup is complete; turn off Test likely FTDI adapters unless you are troubleshooting.";
    byId("md12xx-discovery-summary").textContent = text;
  }
  function sesOptions(shelf) {
    var selected = (shelf.sesAddress || "") + "|" + (shelf.sesDevice || "");
    var values = discovery.sesDevices.slice();
    var usedAddresses = valuesUsedByOtherShelves("sesAddress", shelf.id);
    var usedDevices = valuesUsedByOtherShelves("sesDevice", shelf.id);
    if (shelf.sesAddress && !values.some(function (item) { return item.address === shelf.sesAddress; })) {
      values.unshift({ address: shelf.sesAddress, device: shelf.sesDevice, vendor: "Saved", model: "mapping" });
    }
    return option("|", "Select SES enclosure…", !shelf.sesAddress && !shelf.sesDevice) + values.map(function (item) {
      var value = String(item.address || "") + "|" + String(item.device || "");
      var label = [item.device, item.address, item.vendor, item.model].filter(Boolean).join(" · ");
      var used = !!usedAddresses[item.address] || !!usedDevices[item.device];
      return option(value, label + (used ? " · already assigned" : ""), value === selected, used);
    }).join("");
  }
  function diskOptions(selected, shelfId) {
    selected = Array.isArray(selected) ? selected : [];
    var values = discovery.disks.map(function (disk) { return disk.name; });
    var used = valuesUsedByOtherShelves("disks", shelfId);
    selected.forEach(function (name) { if (values.indexOf(name) < 0) values.push(name); });
    return values.map(function (name) {
      var isSelected = selected.indexOf(name) >= 0;
      return option(name, name + (used[name] ? " · already assigned" : ""), isSelected, !!used[name] && !isSelected);
    }).join("");
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
    var saved = Array.isArray(shelf.disks) ? shelf.disks : [];
    var current = ses && Array.isArray(ses.disks) ? ses.disks : [];
    var disks = saved.length ? saved : current;
    if (saved.length && current.length) {
      var savedKey = saved.slice().sort().join("\n");
      var currentKey = current.slice().sort().join("\n");
      if (savedKey !== currentKey) return "Commissioned mapping: " + saved.join(", ") + ". Current discovery differs; fan control will use fail-safe speed until the shelf is reviewed.";
    }
    if (disks.length) return "Automatically detected: " + disks.join(", ");
    if (!shelf.sesAddress && !shelf.sesDevice) return "Select a verified serial adapter, save, then run Identify & test to find its enclosure and disks.";
    return (ses && ses.diskMappingMessage) || "No automatic disk mapping was found. Open Manual mapping only if the identification test cannot resolve it.";
  }

  function calibrationSummary(shelf) {
    var calibration = shelf && shelf.calibration && typeof shelf.calibration === "object" ? shelf.calibration : {};
    var low = Number(calibration.rpmAt20 || 0);
    var high = Number(calibration.rpmAt50 || 0);
    if (!shelf.commissioned) return "Commissioned: no";
    if (low > 0 && high > low) return "Commissioned: yes · 20% " + low + " RPM · 50% " + high + " RPM";
    return "Commissioned: yes · telemetry calibration missing; run Identify & test again";
  }
  function mappedDisks(shelf) {
    var ses = selectedSes(shelf);
    var saved = Array.isArray(shelf.disks) ? shelf.disks : [];
    var disks = assignmentMode(shelf) === "automatic" && !saved.length && ses && Array.isArray(ses.disks) ? ses.disks : saved;
    return Array.isArray(disks) ? disks : [];
  }

  function shelfDraft(card) {
    var ses = card.querySelector(".md12xx-ses").value.split("|");
    return {
      id: card.getAttribute("data-id"),
      name: card.querySelector(".md12xx-name").value.trim(),
      model: card.querySelector(".md12xx-model").value,
      serialPort: card.querySelector(".md12xx-port").value,
      sesAddress: ses[0] || "",
      sesDevice: ses[1] || "",
      diskAssignment: card.querySelector(".md12xx-assignment").value,
      disks: Array.prototype.map.call(card.querySelector(".md12xx-disks").selectedOptions, function (item) { return item.value; })
    };
  }

  function updateMappingPreview(card) {
    var shelf = shelfDraft(card);
    card.querySelector(".md12xx-assignment-summary").textContent = assignmentSummary(shelf);
    var disks = mappedDisks(shelf);
    card.querySelector(".md12xx-mapped-disk-list").textContent = disks.length ? disks.join(", ") : "None detected yet";
  }

  function commissioningRunning() {
    return Object.keys(commissionJobs).some(function (id) { return !!(commissionJobs[id] && commissionJobs[id].running); });
  }

  function updateActionAvailability() {
    var running = commissioningRunning();
    byId("md12xx-save").disabled = running;
    byId("md12xx-add").disabled = running;
    byId("md12xx-refresh").disabled = running;
    Array.prototype.forEach.call(document.querySelectorAll(".md12xx-remove"), function (button) { button.disabled = running; });
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
    button.disabled = !!job.running || !!config.enabled || byId("md12xx-enabled").checked || !card.querySelector(".md12xx-port").value;
    button.textContent = job.running ? "Identify & test running…" : "Identify & test";
    phase.textContent = commissionPhaseLabel(job.phase);
    phase.className = "md12xx-commission-phase is-" + (job.phase === "passed" ? "passed" : job.phase === "failed" ? "failed" : job.running ? "running" : "ready");
    output.textContent = job.output || "";
    output.hidden = !job.output;
    if (!output.hidden) output.scrollTop = output.scrollHeight;
    result.hidden = !job.resultFile;
    if (job.resultFile) {
      result.href = downloadEndpoint + "?type=commissioning&file=" + encodeURIComponent(job.resultFile);
      result.setAttribute("download", job.resultFile);
    }
    updateActionAvailability();
  }

  function updateShelfStatus() {
    Array.prototype.forEach.call(document.querySelectorAll(".md12xx-shelf"), function (card) {
      var id = card.getAttribute("data-id");
      var status = stateById[id] || {};
      var values = {
        rpm: status.averageRpm == null ? "—" : status.averageRpm,
        temperature: status.temperatureC == null ? "—" : status.temperatureC + "°C",
        target: status.targetPercent == null ? "—" : status.targetPercent + "%",
        reason: status.targetReason || "—",
        telemetry: status.telemetryState || "—",
        command: status.writeState || "—",
        mapping: status.diskMappingState || "—"
      };
      Object.keys(values).forEach(function (key) {
        var node = card.querySelector('[data-status="' + key + '"]');
        if (node) node.textContent = values[key];
      });
      var telemetryNode = card.querySelector('[data-status="telemetry"]');
      if (telemetryNode) telemetryNode.title = status.telemetryMessage || "";
      var commandNode = card.querySelector('[data-status="command"]');
      if (commandNode) commandNode.title = status.writeMessage || "";
      var mappingNode = card.querySelector('[data-status="mapping"]');
      if (mappingNode) mappingNode.title = status.diskMappingMessage || "";
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
          '<label><span>Serial adapter</span><select class="md12xx-port">' + serialOptions(shelf.serialPort || "", shelf.id) + '</select><small>This is the only hardware choice needed for automatic setup</small></label>' +
          '<label><span>Disk assignment</span><select class="md12xx-assignment">' + option("automatic", "Automatic from detected SES enclosure", assignment === "automatic") + option("manual", "Manual override", assignment === "manual") + '</select><small class="md12xx-assignment-summary">' + esc(assignmentSummary(shelf)) + '</small></label>' +
          '<label><span>Shelf enabled</span><input class="md12xx-shelf-enabled" type="checkbox"' + (shelf.enabled !== false ? " checked" : "") + '><small>' + esc(calibrationSummary(shelf)) + '</small></label>' +
        '</div>' +
        '<div class="md12xx-mapped-disks"><strong>Associated Unraid disks</strong><span class="md12xx-mapped-disk-list">' + esc(mappedDisks(shelf).length ? mappedDisks(shelf).join(", ") : "None detected yet") + '</span></div>' +
        '<details class="md12xx-manual"' + (assignment === "manual" ? " open" : "") + '><summary>Manual mapping fallback</summary><div class="md12xx-shelf-grid">' +
          '<label><span>SES enclosure</span><select class="md12xx-ses">' + sesOptions(shelf) + '</select><small>Normally filled by Identify & test</small></label>' +
          '<label><span>Assigned Unraid disks</span><select class="md12xx-disks" multiple>' + diskOptions(shelf.disks, shelf.id) + '</select><small>Used only in Manual override mode; Ctrl/Cmd-click for multiple disks</small></label>' +
        '</div></details>' +
        '<div class="md12xx-status">' +
          '<span>RPM<b data-status="rpm">' + esc(status.averageRpm == null ? "—" : status.averageRpm) + '</b></span>' +
          '<span>Temperature<b data-status="temperature">' + esc(status.temperatureC == null ? "—" : status.temperatureC + "°C") + '</b></span>' +
          '<span>Target<b data-status="target">' + esc(status.targetPercent == null ? "—" : status.targetPercent + "%") + '</b></span>' +
          '<span>Reason<b data-status="reason">' + esc(status.targetReason || "—") + '</b></span>' +
          '<span>Telemetry<b data-status="telemetry" title="' + esc(status.telemetryMessage || "") + '">' + esc(status.telemetryState || "—") + '</b></span>' +
          '<span>Command<b data-status="command" title="' + esc(status.writeMessage || "") + '">' + esc(status.writeState || "—") + '</b></span>' +
          '<span>Mapping<b data-status="mapping" title="' + esc(status.diskMappingMessage || "") + '">' + esc(status.diskMappingState || "—") + '</b></span>' +
        '</div>' +
        '<div class="md12xx-commission"><div class="md12xx-commission-head"><button type="button" class="md12xx-commission-start">Identify &amp; test</button><strong class="md12xx-commission-phase">Ready</strong></div>' +
          '<p>Verifies the console, runs 20% → 50% → 20%, identifies the responding SES enclosure, saves its disks, and proves the final 20% state.</p>' +
          '<pre class="md12xx-commission-output" hidden></pre><a class="md12xx-commission-result" href="#" hidden>Download test results (review identifiers before sharing)</a></div>' +
      '</article>';
    }).join("");
    Array.prototype.forEach.call(root.querySelectorAll(".md12xx-remove"), function (button) {
      button.addEventListener("click", function () {
        var card = button.closest(".md12xx-shelf");
        var id = card.getAttribute("data-id");
        if (commissionJobs[id] && commissionJobs[id].running) { message("Wait for Identify & test to finish before removing this shelf.", true); return; }
        if (!window.confirm("Remove this shelf from the draft configuration? Nothing changes until Save configuration is selected.")) return;
        config.shelves.splice(Number(card.getAttribute("data-index")), 1);
        renderShelves();
      });
    });
    Array.prototype.forEach.call(root.querySelectorAll(".md12xx-assignment"), function (select) {
      select.addEventListener("change", function () {
        var card = select.closest(".md12xx-shelf");
        var details = card.querySelector(".md12xx-manual");
        if (select.value === "manual") details.open = true;
        updateMappingPreview(card);
      });
    });
    Array.prototype.forEach.call(root.querySelectorAll(".md12xx-ses, .md12xx-disks"), function (input) {
      input.addEventListener("change", function () { config = collect(); syncHardwareOptions(); updateMappingPreview(input.closest(".md12xx-shelf")); });
    });
    Array.prototype.forEach.call(root.querySelectorAll(".md12xx-port"), function (input) {
      input.addEventListener("change", function () {
        config = collect();
        syncHardwareOptions({ refreshSerialPorts: true });
        updateCommissionCard(input.closest(".md12xx-shelf").getAttribute("data-id"));
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
    updateActionAvailability();
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
  function scheduleCurveResize(value, immediate) {
    if (curveResizeTimer) window.clearTimeout(curveResizeTimer);
    var apply = function () {
      curveResizeTimer = null;
      resizeCurve(value);
    };
    if (immediate) apply();
    else curveResizeTimer = window.setTimeout(apply, 300);
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
      var shelfId = card.getAttribute("data-id") || slug(name || "shelf-" + (index + 1));
      var existing = (Array.isArray(config.shelves) ? config.shelves : []).find(function (shelf) { return shelf.id === shelfId; }) || {};
      var persisted = (Array.isArray(persistedConfig.shelves) ? persistedConfig.shelves : []).find(function (shelf) { return shelf.id === shelfId; }) || {};
      var calibration = existing.calibration && typeof existing.calibration === "object" && Object.keys(existing.calibration).length
        ? existing.calibration
        : (persisted.calibration && typeof persisted.calibration === "object" ? persisted.calibration : {});
      next.shelves.push({
        id: shelfId,
        name: name,
        model: card.querySelector(".md12xx-model").value,
        enabled: card.querySelector(".md12xx-shelf-enabled").checked,
        commissioned: card.getAttribute("data-commissioned") === "1",
        serialPort: card.querySelector(".md12xx-port").value,
        sesAddress: ses[0] || "",
        sesDevice: ses[1] || "",
        diskAssignment: card.querySelector(".md12xx-assignment").value,
        disks: disks,
        calibration: calibration
      });
    });
    return next;
  }

  async function readJson(response, fallback) {
    var text = await response.text();
    var payload = {};
    if (text) {
      try { payload = JSON.parse(text); }
      catch (error) {
        if (response.status === 401 || response.status === 403) throw new Error("The Unraid session expired; reload this page and try again");
        throw new Error(fallback + " returned an invalid response");
      }
    }
    if (!response.ok) throw new Error(payload.error || (response.status === 401 || response.status === 403 ? "The Unraid session expired; reload this page and try again" : fallback));
    return payload;
  }

  function syncHardwareOptions(options) {
    options = options || {};
    config = collect();
    Array.prototype.forEach.call(document.querySelectorAll(".md12xx-shelf"), function (card, index) {
      var shelf = config.shelves[index];
      if (!shelf) return;
      var port = card.querySelector(".md12xx-port");
      var ses = card.querySelector(".md12xx-ses");
      var disks = card.querySelector(".md12xx-disks");
      if (options.refreshSerialPorts || document.activeElement !== port) { port.innerHTML = serialOptions(shelf.serialPort, shelf.id); port.value = shelf.serialPort; }
      if (document.activeElement !== ses) { ses.innerHTML = sesOptions(shelf); ses.value = (shelf.sesAddress || "") + "|" + (shelf.sesDevice || ""); }
      if (document.activeElement !== disks) disks.innerHTML = diskOptions(shelf.disks, shelf.id);
      updateMappingPreview(card);
    });
  }

  async function discover() {
    var response = await fetch(endpoint + "?action=discover&_=" + Date.now(), { cache: "no-store", credentials: "same-origin" });
    discovery = await readJson(response, "Discovery failed");
    renderDiscoverySummary();
    syncHardwareOptions();
  }
  async function waitForDiscovery(previousGenerationId, timeoutSeconds) {
    var deadline = Date.now() + timeoutSeconds * 1000;
    while (Date.now() < deadline) {
      var response = await fetch(endpoint + "?action=discover&_=" + Date.now(), { cache: "no-store", credentials: "same-origin" });
      var payload = await readJson(response, "Discovery refresh failed");
      if (payload.generationId && String(payload.generationId) !== String(previousGenerationId || "")) return payload;
      await new Promise(function (resolve) { window.setTimeout(resolve, 1000); });
    }
    throw new Error("Discovery refresh did not finish in time; retry once or review the displayed discovery error");
  }
  async function refreshDiscovery() {
    if (commissioningRunning()) { message("Wait for Identify & test to finish before refreshing discovery.", true); return; }
    var button = byId("md12xx-refresh");
    var draft = collect();
    var token = String(window.csrf_token || "");
    if (!token) { message("The current Unraid session token is unavailable; reload this page", true); return; }
    button.disabled = true;
    button.textContent = "Refreshing discovery…";
    try {
      var body = new URLSearchParams({ action: "refresh-discovery", csrf_token: token, discovery: JSON.stringify(draft.discovery) });
      var response = await fetch(endpoint, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" }, body: body.toString() });
      var payload = await readJson(response, "Discovery refresh failed");
      config = draft;
      config.discovery = payload.config.discovery;
      var candidateCount = (discovery.serialPorts || []).filter(function (item) { return item && item.knownFtdiCandidate; }).length;
      var timeoutSeconds = Math.min(120, Math.max(20, candidateCount * Number(config.discovery.responseSeconds || 3) + 20));
      discovery = await waitForDiscovery(payload.previousGenerationId, timeoutSeconds);
      byId("md12xx-probe-ftdi").checked = !!config.discovery.autoProbeKnownFtdi;
      byId("md12xx-discovery-interval").value = String(config.discovery.intervalSeconds);
      byId("md12xx-response-seconds").value = String(config.discovery.responseSeconds);
      renderDiscoverySummary();
      syncHardwareOptions();
      message("Discovery refreshed. Only the discovery options were saved.", false);
    } catch (error) { message(error.message || String(error), true); }
    finally { button.textContent = "Refresh discovery"; updateActionAvailability(); }
  }
  async function refreshStatus() {
    try {
      var response = await fetch(endpoint + "?_=" + Date.now(), { cache: "no-store", credentials: "same-origin" });
      var payload = await readJson(response, "Status refresh failed");
      stateById = {};
      (payload.shelves || []).forEach(function (shelf) { stateById[shelf.id] = shelf; });
      var controller = payload.controller || {};
      controllerState = controller;
      var health = byId("md12xx-health");
      var state = payload.stale ? "fault" : (payload.enabled ? (controller.state || "normal") : "disabled");
      health.className = "md12xx-pill is-" + (state === "normal" ? "normal" : state === "fault" ? "fault" : "attention");
      health.textContent = String(state).toUpperCase();
      health.title = controller.message || "";
      var detail = byId("md12xx-controller-detail");
      var detailText = payload.stale
        ? "Controller status is stale; the background service may not be running."
        : (controller.message && controller.message !== "Controller disabled" ? controller.message : "");
      detail.hidden = !detailText;
      detail.textContent = detailText;
      detail.className = "md12xx-controller-detail is-" + (state === "attention" ? "attention" : "fault");
      updateShelfStatus();
    } catch (error) {
      byId("md12xx-health").className = "md12xx-pill is-fault";
      byId("md12xx-health").textContent = "UNAVAILABLE";
      var detail = byId("md12xx-controller-detail");
      detail.hidden = false;
      detail.className = "md12xx-controller-detail is-fault";
      detail.textContent = error.message || "Controller status could not be read; reload the page and try again.";
    }
  }
  async function save(showConfirmation) {
    if (commissioningRunning()) { message("Wait for Identify & test to finish before saving configuration.", true); return false; }
    try {
      var next = collect();
      if (next.enabled && !persistedConfig.enabled && !window.confirm("Enable MD12xx fan control now? Every enabled, commissioned shelf may receive its current target command immediately.")) return false;
      if (next.enabled && persistedConfig.enabled && liveControlSignature(next) !== liveControlSignature(persistedConfig)
          && !window.confirm("Apply these live fan-control changes now? An enabled, commissioned shelf may receive a new target command immediately.")) return false;
      var token = String(window.csrf_token || "");
      if (!token) throw new Error("The current Unraid session token is unavailable; reload this page");
      var body = new URLSearchParams({ action: "save", csrf_token: token, config: JSON.stringify(next) });
      var response = await fetch(endpoint, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" }, body: body.toString() });
      var payload = await readJson(response, "Save failed");
      config = payload.config;
      persistedConfig = JSON.parse(JSON.stringify(payload.config));
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
      var payload = await readJson(response, "Diagnostics failed");
      message("Local redacted diagnostics created and downloaded. Nothing was uploaded.", false);
      downloadLocalArchive("diagnostics", payload.file);
    } catch (error) { message(error.message || String(error), true); }
  }

  async function loadFreshConfig() {
    var response = await fetch(endpoint + "?action=config&_=" + Date.now(), { cache: "no-store", credentials: "same-origin" });
    var payload = await readJson(response, "Configuration refresh failed");
    config = payload.config;
    persistedConfig = JSON.parse(JSON.stringify(payload.config));
    loadGlobals();
  }

  async function pollCommission(id, resumeOnly) {
    window.clearTimeout(commissionTimers[id]);
    try {
      var response = await fetch(endpoint + "?action=commission&id=" + encodeURIComponent(id) + "&_=" + Date.now(), { cache: "no-store", credentials: "same-origin" });
      var job = await readJson(response, "Unable to read commissioning progress");
      commissionJobs[id] = job;
      updateCommissionCard(id);
      if (job.running) {
        commissionTimers[id] = window.setTimeout(function () { pollCommission(id, false); }, 1000);
      } else if (resumeOnly) {
        return;
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
      commissionTimers[id] = window.setTimeout(function () { pollCommission(id, resumeOnly); }, 2000);
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
      var payload = await readJson(response, "Unable to start Identify & test");
      commissionJobs[id] = payload.job;
      updateCommissionCard(id);
      message("Identify & test started. It continues safely even if this page is closed.", false);
      pollCommission(id, false);
    } catch (error) { message(error.message || String(error), true); }
  }

  function resumeCommissionJobs() {
    (config.shelves || []).forEach(function (shelf) { pollCommission(shelf.id, true); });
  }

  byId("md12xx-add").addEventListener("click", function () {
    config = collect();
    var number = config.shelves.length + 1;
    var id = "shelf-" + number;
    while (config.shelves.some(function (item) { return item.id === id; })) { number++; id = "shelf-" + number; }
    config.shelves.push({ id: id, name: "MD1200 Shelf " + number, model: "MD1200", enabled: true, commissioned: false, serialPort: "", sesDevice: "", sesAddress: "", diskAssignment: "automatic", disks: [] });
    renderShelves();
  });
  byId("md12xx-refresh").addEventListener("click", refreshDiscovery);
  byId("md12xx-diagnostics").addEventListener("click", diagnostics);
  byId("md12xx-curve-points").addEventListener("input", function () { scheduleCurveResize(this.value, false); });
  byId("md12xx-curve-points").addEventListener("change", function () { scheduleCurveResize(this.value, true); });
  byId("md12xx-help-toggle").addEventListener("click", function () {
    var help = byId("md12xx-help");
    help.hidden = !help.hidden;
    this.setAttribute("aria-expanded", help.hidden ? "false" : "true");
    this.textContent = help.hidden ? "Setup directions" : "Hide directions";
  });
  byId("md12xx-enabled").addEventListener("change", function () {
    Array.prototype.forEach.call(document.querySelectorAll(".md12xx-shelf"), function (card) { updateCommissionCard(card.getAttribute("data-id")); });
  });
  byId("md12xx-save").addEventListener("click", function () { save(true); });

  loadGlobals();
  discover().catch(function (error) { message(error.message, true); });
  refreshStatus();
  resumeCommissionJobs();
  window.setInterval(refreshStatus, 5000);
}());

