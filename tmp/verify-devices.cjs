const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const html = fs.readFileSync('index.html', 'utf8');
const script = html.slice(html.indexOf('<script>') + 8, html.indexOf('</script>'));
new vm.Script(script);
const storage = new Map();

// Run the page's actual handlers with a minimal DOM; no browser is available.
function boot(failStorage = false) {
  const elements = new Map();
  class Element {
    set value(value) { this._value = String(value); }
    get value() { return this._value; }
    set innerHTML(value) { this._html = value; if (this.id === 'deviceParent') this.value = ''; }
    get innerHTML() { return this._html; }
    constructor(id) {
      this.id = id;
      this.value = id.endsWith('Filter') ? 'all' : '';
      this.handlers = {};
      this.innerHTML = '';
      this.hidden = false;
      this.isConnected = true;
      this.classList = { add() {}, remove() {}, toggle() {}, contains() { return false; } };
    }
    addEventListener(type, handler) { this.handlers[type] = handler; }
    insertAdjacentHTML(position, content) { this.innerHTML += content; }
    reset() { if (elements.has('deviceReason')) elements.get('deviceReason').value = ''; }
    focus() {}
    querySelectorAll() { return []; }
    appendChild() {}
    setAttribute() {}
    remove() {}
  }
  function element(id) {
    if (!elements.has(id)) elements.set(id, new Element(id));
    return elements.get(id);
  }
  const context = vm.createContext({
    console,
    document: { getElementById: element, querySelectorAll: () => [], querySelector: () => element('queriedContainer'), createElement: () => new Element('newElement'), addEventListener() {}, activeElement: null },
    window: { scrollTo() {}, setTimeout() {} },
    localStorage: {
      getItem(key) { return storage.get(key) || null; },
      setItem(key, value) { if (failStorage) throw new Error('Storage disabled'); storage.set(key, value); }
    }
  });
  vm.runInContext(script, context);
  return { context, element, run(code) { return vm.runInContext(code, context); } };
}

const page = boot();
assert.equal(page.run('devices.length'), 18);
assert.match(page.element('deviceDetail').innerHTML, /32 capacity/);
assert.match(page.element('deviceDetail').innerHTML, /CAM-MKT-012/);
assert.match(page.element('deviceTableBody').innerHTML, /DVR-MKT-01/);

page.element('deviceTypeFilter').value = 'DVR';
page.run('renderDevices()');
assert.equal(page.element('deviceResultCount').textContent, '1 of 18 devices · select a device to view details');
page.element('deviceSearch').value = 'no such device';
page.run('renderDevices()');
assert.match(page.element('deviceTableBody').innerHTML, /No devices match/);

const validationCases = [
  ["validateDevice({...devices[0], reason:'test'}, null)", /already registered/],
  ["validateDevice({...devices[0], ip:'300.2.3.4', reason:'test'}, devices[0])", /valid IPv4/],
  ["validateDevice({...devices[0], parent:'NVR-MKT-02', reason:'test'}, devices[0])", /circular connection/],
  ["validateDevice({...devices[2], channels:1, reason:'test'}, devices[2])", /capacity/],
  ["validateDevice({...devices[2], branch:'Cebu IT Park', reason:'test'}, devices[2])", /Move the connected/],
  ["validateDevice({...devices.find(d=>d.id==='CAM-MKT-012'), channel:1, reason:'test'}, devices.find(d=>d.id==='CAM-MKT-012'))", /channel is unavailable/],
  ["validateDevice({...devices[0], reason:'  ' .trim()}, devices[0])", /reason/]
];
for (const [code, expected] of validationCases) assert.match(page.run(code), expected);

page.run("openDeviceForm('NVR-MKT-02')");
page.element('deviceStatus').value = 'maintenance';
page.element('deviceReason').value = 'Planned disk replacement';
page.element('deviceForm').handlers.submit({ preventDefault() {} });
assert.equal(page.run("devices.find(d=>d.id==='NVR-MKT-02').status"), 'maintenance');
assert.equal(page.run("devices.find(d=>d.id==='NVR-MKT-02').logs.at(-1).reason"), 'Planned disk replacement');
assert.match(page.element('deviceDetail').innerHTML, /Planned disk replacement/);
assert.ok(storage.has('rosewatch-device-register-v1'));
const reloaded = boot();
assert.equal(reloaded.run("devices.find(d=>d.id==='NVR-MKT-02').status"), 'maintenance');

page.run('openDeviceForm()');
page.element('deviceAssetId').value = 'UPS-QC-02';
page.element('deviceName').value = '<script>Unsafe name</script>';
page.element('deviceType').value = 'UPS';
page.element('deviceBranch').value = 'Quezon City';
page.element('deviceStatus').value = 'live';
page.element('deviceReason').value = 'Backup power installed';
page.element('deviceForm').handlers.submit({ preventDefault() {} });
assert.equal(page.run('devices.length'), 19);
assert.match(page.element('deviceDetail').innerHTML, /&lt;script&gt;Unsafe name/);
assert.doesNotMatch(page.element('deviceDetail').innerHTML, /<script>Unsafe name/);

const unavailable = boot(true);
unavailable.run("openDeviceForm('UPS-QC-02')");
unavailable.element('deviceStatus').value = 'down';
unavailable.element('deviceReason').value = 'Battery failure';
unavailable.element('deviceForm').handlers.submit({ preventDefault() {} });
assert.match(unavailable.element('deviceStorageNote').textContent, /lost on reload/);
assert.equal(unavailable.run("devices.find(d=>d.id==='UPS-QC-02').status"), 'down');
console.log('PASS: inventory rendering, filters, relationships, channel limits, duplicate IDs, IP validation, add/edit, status action log, persistence reload, escaped input, storage failure.');
