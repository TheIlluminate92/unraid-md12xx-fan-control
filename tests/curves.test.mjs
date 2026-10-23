import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
class Element {
  constructor(tag) { this.tagName = tag; this.children = []; this.events = {}; this.className = ''; }
  appendChild(child) { this.children.push(child); }
  replaceChildren() { this.children = []; }
  setAttribute() {}
  addEventListener(type, callback) { this.events[type] = callback; }
  querySelectorAll(selector) { return this.children.flatMap(c => [(selector.startsWith('.') ? c.className === selector.slice(1) : c.tagName === selector) ? c : null, ...c.querySelectorAll(selector)]).filter(Boolean); }
  querySelector(selector) { return this.querySelectorAll(selector)[0]; }
}
const window = {};
vm.runInNewContext(readFileSync(new URL('../source/usr/local/emhttp/plugins/md12xx.fancontrol/assets/js/curves.js', import.meta.url), 'utf8'), { window, document: { createElement: tag => new Element(tag) } });
const curves = window.WAZCoolingCurves;
const plain = value => JSON.parse(JSON.stringify(value));

test('IPMI 15% minimum survives point-count edits without relaxing shelf limits', () => {
  const chassis = new Element('div'), shelf = new Element('div');
  curves.mount(chassis, [{ temperatureC: 0, speed: 15 }, { temperatureC: 40, speed: 40 }], 15);
  curves.mount(shelf, [{ temperatureC: 0, speed: 20 }, { temperatureC: 50, speed: 50 }]);
  assert.equal(chassis.querySelector('.cooling-curve-speed').min, '15');
  const count = chassis.querySelector('input'); count.value = '3'; count.events.change();
  assert.equal(chassis.querySelector('.cooling-curve-speed').min, '15');
  assert.equal(curves.read(chassis)[0].speed, 15);
  assert.equal(shelf.querySelector('.cooling-curve-speed').min, '20');
});
test('independent device editors do not share or overwrite draft curve values', () => {
  const shelf = new Element('div'), chassis = new Element('div');
  const global = [{ temperatureC: 0, speed: 20 }, { temperatureC: 50, speed: 50 }];
  curves.mount(shelf, global); curves.mount(chassis, global);
  chassis.querySelector('.cooling-curve-speed').value = '60';
  assert.equal(curves.read(chassis)[0].speed, 60);
  assert.equal(curves.read(shelf)[0].speed, 20);
  assert.equal(global[0].speed, 20);
});
test('curve point count is bounded and inserts a valid point below a 100°C endpoint', () => {
  const points = [{ temperatureC: 0, speed: 20 }, { temperatureC: 100, speed: 80 }];
  const result = plain(curves.resize(points, 3));
  assert.deepEqual(result, [{ temperatureC: 0, speed: 20 }, { temperatureC: 50, speed: 80 }, { temperatureC: 100, speed: 80 }]);
  assert.equal(curves.resize(points, 999).length, 10);
  assert.equal(curves.resize(points, -1).length, 2);
});
test('changing point count preserves edited values', () => {
  const editor = new Element('div'); curves.mount(editor, [{ temperatureC: 0, speed: 20 }, { temperatureC: 50, speed: 60 }]);
  editor.querySelector('.cooling-curve-speed').value = '30';
  const count = editor.querySelector('input'); count.value = '3'; count.events.change();
  assert.equal(curves.read(editor).length, 3);
  assert.equal(curves.read(editor)[0].speed, 30);
});
