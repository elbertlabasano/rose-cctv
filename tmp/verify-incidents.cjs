const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const html = fs.readFileSync('index.html', 'utf8');
const script = html.slice(html.indexOf('<script>') + 8, html.indexOf('</script>'));
const storage = new Map();

// Exercise the page's handlers in a minimal DOM when a browser isn't attached.
function boot({ failWrite = false, readGate = null } = {}) {
  const elements = new Map();
  class Element {
    constructor(id) {
      this.id = id;
      this.handlers = {};
      this._value = id.endsWith('Filter') ? 'all' : '';
      this._html = '';
      this.hidden = false;
      this.checked = false;
      this.isConnected = true;
      const classes = new Set();
      this.classList = { add: key => classes.add(key), remove: key => classes.delete(key), contains: key => classes.has(key), toggle: key => classes.has(key) ? classes.delete(key) : classes.add(key) };
    }
    set value(value) { this._value = String(value); }
    get value() { return this._value; }
    set innerHTML(value) { this._html = String(value); if (['deviceParent', 'modalCamera'].includes(this.id)) this.value = ''; }
    get innerHTML() { return this._html; }
    addEventListener(type, handler) { this.handlers[type] = handler; }
    querySelectorAll() { return []; }
    insertAdjacentHTML(position, content) { this._html += content; }
    appendChild() {}
    setAttribute() {}
    removeAttribute() {}
    focus() {}
    remove() {}
    reset() {
      if (this.id === 'incidentWorkflowForm') ['workflowNotes', 'workflowReference', 'resolutionPrevention', 'resolutionCause'].forEach(id => element(id).value = '');
      element('workflowConfirmed').checked = false;
    }
  }
  function element(id) {
    if (!elements.has(id)) elements.set(id, new Element(id));
    return elements.get(id);
  }
  class MockFileReader {
    readAsDataURL(file) {
      Promise.resolve(readGate).then(() => {
        if (file.fail) { this.onerror(); return; }
        this.result = `data:${file.type};base64,${file.content.toString('base64')}`;
        this.onload();
      });
    }
  }
  const context = vm.createContext({
    console,
    FileReader: MockFileReader,
    atob,
    Blob,
    URL,
    Uint8Array,
    document: { getElementById: element, querySelectorAll: () => [], querySelector: () => element('queriedContainer'), createElement: () => new Element('createdElement'), addEventListener() {}, activeElement: null },
    window: { scrollTo() {}, setTimeout() {} },
    localStorage: { getItem: key => storage.get(key) || null, setItem: (key, value) => { if (failWrite) throw Error('Storage unavailable'); storage.set(key, value); } }
  });
  vm.runInContext(script, context);
  return { element, context, run: code => vm.runInContext(code, context) };
}
const page = boot();
const submit = () => page.element('incidentWorkflowForm').handlers.submit({ preventDefault() {} });
assert.equal(page.run('incidents.length'), 5);
assert.match(page.element('incidentDetail').innerHTML, /PoE switch port remotely restarted/);

// Individual reports show their own histories, without leaking another report's logs.
page.run("selectedIncidentId = 'IR-2026-1051'; renderIncidents()");
assert.doesNotMatch(page.element('incidentDetail').innerHTML, /PoE switch port remotely restarted/);
page.run("openIncidentWorkflow('action', 'IR-2026-1051')");
page.element('workflowNotes').value = 'Replaced the damaged cable <check>';
page.element('actionLogType').value = 'Repair / replacement';
page.element('actionLogStatus').value = 'on_hold';
page.element('actionLogAssignee').value = 'Branch Facilities';
page.element('workflowReference').value = 'WO-101';
submit();
assert.equal(page.run("incidents.find(i=>i.id==='IR-2026-1051').status"), 'on_hold');
assert.equal(page.run("incidents.find(i=>i.id==='IR-2026-1051').assignee"), 'Branch Facilities');
assert.match(page.element('incidentDetail').innerHTML, /&lt;check&gt;/);
assert.equal(page.run("incidents.find(i=>i.id==='IR-2026-1048').logs.length"), 4);

// Required fields and invalid transitions do not mutate history.
assert.match(page.run("applyIncidentWorkflow('IR-2026-1051','resolve',{notes:' '}).error"), /details/);
assert.match(page.run("applyIncidentWorkflow('IR-2026-1051','resolve',{notes:'Fixed', cause:'Network', restoredAt:incidentLocalInput(new Date()), confirmed:false}).error"), /confirm/);
assert.match(page.run("applyIncidentWorkflow('IR-2026-1051','verify',{notes:'Checked', confirmed:true}).error"), /before verifying/);
assert.match(page.run("applyIncidentWorkflow('IR-2026-1051','resolve',{notes:'Fixed', cause:'Network', restoredAt:'2099-01-01T12:00:00', confirmed:true}).error"), /Restoration time/);
assert.match(page.run("applyIncidentWorkflow('IR-2026-1051','resolve',{notes:'Fixed', cause:'Network', restoredAt:'2020-01-01T12:00:00', confirmed:true}).error"), /Restoration time/);

page.run("openIncidentWorkflow('resolve', 'IR-2026-1051')");
page.element('workflowNotes').value = 'Cable replaced; connectivity checked.';
page.element('resolutionCause').value = 'Cabling / connector';
page.element('workflowConfirmed').checked = true;
page.element('resolutionPrevention').value = 'Secure cable run.';
submit();
assert.equal(page.run("incidents.find(i=>i.id==='IR-2026-1051').status"), 'resolved');
assert.equal(page.element('incidentStatusFilter').value, 'resolved');
assert.match(page.element('incidentDetail').innerHTML, /Resolution awaiting verification/);
assert.match(page.element('incidentDetail').innerHTML, /Secure cable run/);

const reloaded = boot();
assert.equal(reloaded.run("incidents.find(i=>i.id==='IR-2026-1051').resolution.cause"), 'Cabling / connector');
page.run("openIncidentWorkflow('verify','IR-2026-1051')");
page.element('workflowNotes').value = 'Branch confirmed stable camera connectivity.';
submit();
assert.match(page.element('workflowError').textContent, /Confirm/);
page.element('workflowConfirmed').checked = true;
submit();
assert.equal(page.run("incidents.find(i=>i.id==='IR-2026-1051').status"), 'closed');
assert.match(page.element('incidentDetail').innerHTML, /Verified resolution/);
page.run("openIncidentWorkflow('reopen','IR-2026-1051')");
page.element('workflowNotes').value = 'Camera disconnected again.';
submit();
assert.equal(page.run("incidents.find(i=>i.id==='IR-2026-1051').status"), 'in_progress');
assert.equal(page.run("incidents.find(i=>i.id==='IR-2026-1051').resolution"), null);
assert.match(page.element('incidentDetail').innerHTML, /Cabling \/ connector/);
assert.match(page.element('incidentDetail').innerHTML, /Branch confirmed stable/);
assert.equal(page.element('dashboardIncidentCount').textContent, 4);

// New incidents can reference managed devices such as NVRs, not just cameras.
page.element('modalBranch').value = 'Makati Central';
page.run('refreshIncidentDeviceOptions()');
assert.match(page.element('modalCamera').innerHTML, /NVR-MKT-02/);
page.element('modalCamera').value = 'NVR-MKT-02';
page.element('modalSummary').value = 'Disk replacement required';
page.element('modalPriority').value = 'High';
page.element('modalAssignee').value = 'Security Vendor';
page.element('modalCategory').value = 'Storage issue';
page.element('modalDetails').value = 'Recorder disk inspection.';
page.element('incidentForm').handlers.submit({ preventDefault() {} });
assert.equal(page.run('incidents.length'), 6);
assert.equal(page.run('incidents.at(-1).deviceId'), 'NVR-MKT-02');
assert.equal(page.element('dashboardIncidentCount').textContent, 5);

const noStorage = boot({ failWrite: true });
assert.equal(noStorage.run("applyIncidentWorkflow('IR-2026-1051','action',{notes:'Follow-up',type:'Note',status:'in_progress',assignee:'Branch Facilities'}).saved"), false);
assert.match(noStorage.element('incidentStorageNote').textContent, /lost on reload/);

console.log('PASS: isolated incident logs, assignment/status updates, required resolution fields, restoration time validation, resolve/verify/reopen, preserved history, local persistence, new device incidents, safe rendering, dashboard counts, storage failure.');

async function verifyProofs() {
  storage.clear();
  const proofPage = boot();
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jWZkAAAAASUVORK5CYII=', 'base64');
  const pdf = Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF');
  const imageFile = { name: 'Repair <photo>.png', type: 'image/png', size: png.length, content: png };
  const pdfFile = { name: 'Work order.pdf', type: 'application/pdf', size: pdf.length, content: pdf };
  async function selectFiles(targetPage, files) {
    const input = targetPage.element('workflowProofFiles');
    input.files = files;
    await input.handlers.change({ target: input });
  }
  const saveProofForm = () => proofPage.element('incidentWorkflowForm').handlers.submit({ preventDefault() {} });
  proofPage.run("openIncidentWorkflow('action','IR-2026-1051')");
  await selectFiles(proofPage, [imageFile, pdfFile]);
  assert.equal(proofPage.run('workflowProofs.length'), 2);
  assert.match(proofPage.element('workflowProofList').innerHTML, /Repair &lt;photo&gt;\.png/);
  assert.match(proofPage.element('workflowProofList').innerHTML, /Download/);
  proofPage.run('openProofViewer(workflowProofs[0].id)');
  assert.match(proofPage.element('proofViewerBody').innerHTML, /<img src="blob:/);
  assert.equal(proofPage.element('proofViewerModal').classList.contains('open'), true);
  proofPage.run('closeProofViewer()');
  assert.equal(proofPage.run('proofViewUrl'), null);
  proofPage.run('openProofViewer(workflowProofs[1].id)');
  assert.match(proofPage.element('proofViewerBody').innerHTML, /<iframe src="blob:/);
  proofPage.run('closeProofViewer()');
  proofPage.element('workflowNotes').value = 'Cable replaced; photos and work order attached.';
  proofPage.element('actionLogType').value = 'Repair / replacement';
  saveProofForm();
  assert.equal(proofPage.run("incidents.find(i=>i.id==='IR-2026-1051').logs.at(-1).attachments.length"), 2);
  assert.equal(proofPage.run("incidents.find(i=>i.id==='IR-2026-1051').logs.at(-1).attachments[0].uploadedBy"), 'Alex Mendoza');
  assert.match(proofPage.element('incidentDetail').innerHTML, /Proof attachments/);
  const reloadProofs = boot();
  assert.equal(reloadProofs.run("incidents.find(i=>i.id==='IR-2026-1051').logs.at(-1).attachments[0].dataUrl"), `data:image/png;base64,${png.toString('base64')}`);

  proofPage.run("openIncidentWorkflow('resolve','IR-2026-1051')");
  await selectFiles(proofPage, [imageFile]);
  proofPage.element('workflowNotes').value = 'Resolution proof attached.';
  proofPage.element('resolutionCause').value = 'Cabling / connector';
  proofPage.element('workflowConfirmed').checked = true;
  saveProofForm();
  assert.equal(proofPage.run("incidents.find(i=>i.id==='IR-2026-1051').resolution.proofIds.length"), 1);
  assert.equal(proofPage.run("JSON.stringify(incidents.find(i=>i.id==='IR-2026-1051').resolution).includes('base64')"), false);
  assert.match(proofPage.element('incidentDetail').innerHTML, /Resolution proof \(1\)/);
  proofPage.run("applyIncidentWorkflow('IR-2026-1051','reopen',{notes:'Repeat fault'})");
  assert.equal(proofPage.run("incidentProofs(incidents.find(i=>i.id==='IR-2026-1051')).length"), 3);

  proofPage.run("openIncidentWorkflow('action','IR-2026-1051')");
  await selectFiles(proofPage, [imageFile]);
  const removeId = proofPage.run('workflowProofs[0].id');
  proofPage.element('workflowProofList').handlers.click({ target: { closest: () => ({ dataset: { proofRemove: removeId } }) } });
  assert.equal(proofPage.run('workflowProofs.length'), 0);
  await selectFiles(proofPage, [{ name: 'wrong.png', type: 'image/png', size: 20, content: Buffer.alloc(20) }]);
  assert.match(proofPage.element('workflowError').textContent, /does not match/);
  await selectFiles(proofPage, [{ ...imageFile, name: 'script.html', type: 'text/html' }]);
  assert.match(proofPage.element('workflowError').textContent, /choose a JPG/);
  await selectFiles(proofPage, [{ ...imageFile, size: 1024 * 1024 + 1 }]);
  assert.match(proofPage.element('workflowError').textContent, /1 MB/);
  await selectFiles(proofPage, [imageFile, imageFile, imageFile, imageFile]);
  assert.match(proofPage.element('workflowError').textContent, /up to 3/);
  await selectFiles(proofPage, Array(3).fill({ ...imageFile, size: 800000 }));
  assert.match(proofPage.element('workflowError').textContent, /within 2 MB/);
  await selectFiles(proofPage, [{ ...imageFile, fail: true }]);
  assert.match(proofPage.element('workflowError').textContent, /could not be read/);
  assert.equal(proofPage.run('workflowProofs.length'), 0);

  const failedSave = boot({ failWrite: true });
  failedSave.run("openIncidentWorkflow('resolve','IR-2026-1051')");
  await selectFiles(failedSave, [pdfFile]);
  failedSave.element('workflowNotes').value = 'Attempt to save proof with storage unavailable.';
  failedSave.element('resolutionCause').value = 'Configuration';
  failedSave.element('workflowConfirmed').checked = true;
  const before = failedSave.run("incidents.find(i=>i.id==='IR-2026-1051').logs.length");
  failedSave.element('incidentWorkflowForm').handlers.submit({ preventDefault() {} });
  assert.match(failedSave.element('workflowError').textContent, /incident was not changed/);
  assert.equal(failedSave.run("incidents.find(i=>i.id==='IR-2026-1051').logs.length"), before);
  assert.equal(failedSave.run("incidents.find(i=>i.id==='IR-2026-1051').status"), 'in_progress');
  assert.equal(failedSave.element('incidentWorkflowModal').classList.contains('open'), true);
  assert.equal(failedSave.run('workflowProofs.length'), 1);

  let release;
  const gate = new Promise(resolve => { release = resolve; });
  const racing = boot({ readGate: gate });
  racing.run("openIncidentWorkflow('action','IR-2026-1051')");
  const pending = selectFiles(racing, [imageFile]);
  assert.equal(racing.element('workflowSave').disabled, true);
  racing.element('incidentWorkflowForm').handlers.submit({ preventDefault() {} });
  assert.match(racing.element('workflowError').textContent, /finished loading/);
  racing.run("closeIncidentDialog(workflowModal);openIncidentWorkflow('action','IR-2026-1048')");
  release();
  await pending;
  assert.equal(racing.run('workflowProofs.length'), 0);
  assert.equal(racing.element('workflowSave').disabled, false);
  console.log('PASS: proof selection, image/PDF preview, removal, action and resolution evidence, reload persistence, preserved proof on reopening, file format/size/count checks, read failure, storage failure rollback, cancelled-read isolation.');
}
verifyProofs().catch(error => { console.error(error); process.exitCode = 1; });
