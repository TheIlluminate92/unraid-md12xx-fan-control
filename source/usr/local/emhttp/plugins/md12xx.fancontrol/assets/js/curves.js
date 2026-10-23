(function () {
  'use strict';
  function resize(points, count) {
    count = Math.max(2, Math.min(10, Math.round(Number(count) || 2)));
    var next = points.slice(0, Math.max(2, Math.min(10, count))).map(function (p) { return { temperatureC: Number(p.temperatureC), speed: Number(p.speed) }; });
    while (next.length < count) {
      var previous = next[next.length - 1] || { temperatureC: 0, speed: 20 };
      if (previous.temperatureC >= 100 && next.length > 1) {
        var before = next[next.length - 2];
        next.splice(next.length - 1, 0, { temperatureC: (before.temperatureC + previous.temperatureC) / 2, speed: previous.speed });
      } else next.push({ temperatureC: Math.min(100, previous.temperatureC + 5), speed: previous.speed });
    }
    return next;
  }
  function read(root) {
    return Array.prototype.map.call(root.querySelectorAll('.cooling-curve-point'), function (row) {
      return { temperatureC: Number(row.querySelector('.cooling-curve-temp').value), speed: Number(row.querySelector('.cooling-curve-speed').value) };
    });
  }
  function readHysteresis(root) { return Number(root.querySelector('.cooling-curve-hysteresis').value); }
  function mount(root, points, minimumSpeed, hysteresis) {
    minimumSpeed = minimumSpeed === 15 ? 15 : 20;
    root.replaceChildren();
    var label = document.createElement('label'), count = document.createElement('input');
    label.textContent = 'Curve points (2–10) ';
    count.type = 'number'; count.min = '2'; count.max = '10'; count.value = String(points.length);
    count.setAttribute('aria-label', 'Number of individual curve points');
    label.appendChild(count); root.appendChild(label);
    var downshiftLabel = document.createElement('label'), downshift = document.createElement('input');
    downshiftLabel.textContent = 'Downshift hysteresis °C ';
    downshift.type = 'number'; downshift.className = 'cooling-curve-hysteresis'; downshift.min = '0'; downshift.max = '10'; downshift.step = '0.5';
    downshift.value = String(hysteresis == null ? 1 : hysteresis);
    downshift.setAttribute('aria-label', 'Individual downshift hysteresis °C');
    downshiftLabel.appendChild(downshift); root.appendChild(downshiftLabel);
    points.forEach(function (point) {
      var row = document.createElement('div'); row.className = 'cooling-curve-point';
      [['Temperature °C', 'cooling-curve-temp', point.temperatureC, 0, 100], ['Fan %', 'cooling-curve-speed', point.speed, minimumSpeed, 100]].forEach(function (field) {
        var item = document.createElement('label'), input = document.createElement('input');
        item.textContent = field[0] + ' '; input.className = field[1]; input.type = 'number'; input.step = field[1] === 'cooling-curve-temp' ? '0.5' : '1';
        input.value = String(field[2]); input.min = String(field[3]); input.max = String(field[4]);
        input.setAttribute('aria-label', field[0]); item.appendChild(input); row.appendChild(item);
      });
      root.appendChild(row);
    });
    count.addEventListener('change', function () {
      var desired = Math.max(2, Math.min(10, Math.round(Number(count.value) || 2)));
      mount(root, resize(read(root), desired), minimumSpeed, readHysteresis(root));
    });
  }
  window.WAZCoolingCurves = { read: read, readHysteresis: readHysteresis, mount: mount, resize: resize };
})();
