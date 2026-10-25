const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const html = fs.readFileSync('index.html', 'utf8');
const script = html.slice(html.indexOf('<script>') + 8, html.indexOf('</script>'));
new vm.Script(script);
const storage = new Map();

// Exercise actual page handlers with a minimal DOM; no real accounts are created.
function boot({ failWrite = false } = {}) {
  const elements = new Map();
  class Element {
    constructor(id) {
      this.id = id;
      this.handlers = {};
      this._value = id.endsWith('Filter') ? 'all' : '';
      this.innerHTML = '';
      this.hidden = false;
      this.checked = false;
      this.isConnected = true;
      const classes = new Set();
      this.classList = { add: key => classes.add(key), remove: key => classes.delete(key), contains: key => classes.has(key), toggle: key => classes.has(key) ? classes.delete(key) : classes.add(key) };
    }
    set value(value) { this._value = String(value); }
    get value() { return this._value; }
    addEventListener(type, handler) { this.handlers[type] = handler; }
    insertAdjacentHTML(position, html) { this.innerHTML += html; }
    querySelectorAll() { return []; }
    appendChild() {}
    setAttribute() {}
    removeAttribute() {}
    focus() {}
    remove() {}
    reset() {}
  }
  function element(id) {
    if (!elements.has(id)) elements.set(id, new Element(id));
    return elements.get(id);
  }
  const context = vm.createContext({
    console,
    document: { getElementById: element, querySelectorAll: () => [], querySelector: () => element('queriedContainer'), createElement: () => new Element('createdElement'), addEventListener() {}, activeElement: null },
    window: { scrollTo() {}, setTimeout() {} },
    localStorage: { getItem: key => storage.get(key) || null, setItem: (key, value) => { if (failWrite) throw Error('Storage unavailable'); storage.set(key, value); } }
  });
  vm.runInContext(script, context);
  return { element, run: code => vm.runInContext(code, context) };
}
const page = boot();
const submit = () => page.element('userForm').handlers.submit({ preventDefault() {} });
assert.equal(page.run('users.length'), 6);
assert.match(page.element('userDetail').innerHTML, /Current demo account/);
assert.match(page.element('userPermissionTable').innerHTML, /Technician/);

page.run('openUserForm()');
page.element('userName').value = 'New <Operator>';
page.element('userEmail').value = ' NEW.OPERATOR@EXAMPLE.COM ';
page.element('userRole').value = 'operator';
page.element('userTeam').value = 'CCTV Operations';
page.element('userChangeReason').value = 'New branch operator';
submit();
assert.match(page.element('userFormError').textContent, /Select at least one valid branch/);
assert.equal(page.run('users.length'), 6);
page.element('userBranchChoice-0').checked = true;
submit();
assert.equal(page.run('users.length'), 7);
assert.equal(page.run('users.at(-1).email'), 'new.operator@example.com');
assert.equal(page.run('users.at(-1).branches[0]'), 'Makati Central');
assert.match(page.element('userDetail').innerHTML, /New &lt;Operator&gt;/);
assert.doesNotMatch(page.element('userDetail').innerHTML, /New <Operator>/);
assert.equal(page.run('users.at(-1).logs.at(-1).actor'), 'Alex Mendoza');

const newId = page.run('users.at(-1).id');
page.run(`openUserForm('${newId}')`);
page.element('userEmail').value = 'ALEX.MENDOZA@EXAMPLE.COM';
page.element('userChangeReason').value = 'Change email';
submit();
assert.match(page.element('userFormError').textContent, /already assigned/);
page.element('userEmail').value = 'new.operator@example.com';
page.element('userEnvironmentProduction').checked = false;
submit();
assert.match(page.element('userFormError').textContent, /valid environment/);
page.element('userEnvironmentProduction').checked = true;
page.element('userRole').value = 'technician';
page.element('userChangeReason').value = 'Transferred to technical support';
submit();
assert.equal(page.run(`users.find(u=>u.id==='${newId}').role`), 'technician');
assert.match(page.element('userDetail').innerHTML, /Role changed/);
assert.match(page.element('userDetail').innerHTML, /Operator → Technician/);

page.run(`openUserForm('${newId}', 'inactive')`);
page.element('userChangeReason').value = 'Access no longer needed';
submit();
assert.equal(page.run(`users.find(u=>u.id==='${newId}').status`), 'inactive');
assert.match(page.element('userDetail').innerHTML, /User deactivated/);
page.run(`openUserForm('${newId}', 'active')`);
page.element('userChangeReason').value = 'Returned to branch support';
submit();
assert.equal(page.run(`users.find(u=>u.id==='${newId}').status`), 'active');
assert.match(page.element('userDetail').innerHTML, /User activated/);
const reloaded = boot();
assert.equal(reloaded.run(`users.find(u=>u.id==='${newId}').logs.length`), 4);

page.element('userSearch').value = 'nobody matches';
page.run('renderUsers()');
assert.match(page.element('userTableBody').innerHTML, /No users match/);
page.element('userSearch').value = '';
page.element('userRoleFilter').value = 'viewer';
page.run('renderUsers()');
assert.equal(page.element('userResultCount').textContent, '1 of 7 users · select a user to manage');
page.element('userRoleFilter').value = 'all';
page.element('userStatusFilter').value = 'inactive';
page.run('renderUsers()');
assert.equal(page.element('userResultCount').textContent, '1 of 7 users · select a user to manage');
page.element('userStatusFilter').value = 'all';
page.element('userBranchFilter').value = 'Cebu IT Park';
page.run('renderUsers()');
assert.equal(page.element('userResultCount').textContent, '3 of 7 users · select a user to manage');

// The only administrator cannot be removed or deactivated.
assert.match(page.run("saveUserRecord('USR-0001', {...users[0], status:'inactive', reason:'Test'}).error"), /at least one active administrator/);
page.run('openUserForm()');
page.element('userName').value = 'Second Administrator';
page.element('userEmail').value = 'second.admin@example.com';
page.element('userRole').value = 'admin';
page.element('userScope').value = 'selected';
page.element('userEnvironmentProduction').checked = false;
page.element('userEnvironmentTest').checked = false;
page.element('userChangeReason').value = 'Backup administrator';
page.run('refreshUserAccessFields()');
assert.equal(page.element('userScope').value, 'all');
assert.equal(page.element('userScope').disabled, true);
assert.equal(page.element('userEnvironmentTest').checked, true);
submit();
assert.equal(page.run('users.at(-1).branchScope'), 'all');
assert.equal(page.run('users.at(-1).environments.length'), 2);
assert.match(page.run("saveUserRecord('USR-0001', {...users[0], role:'viewer', reason:'Test'}).error"), /current demo account/);

// Renaming the demo administrator updates new device/incident audit attribution.
page.run("openUserForm('USR-0001')");
page.element('userName').value = 'Alex Cruz';
page.element('userChangeReason').value = 'Profile correction';
submit();
assert.equal(page.element('sessionUserName').textContent, 'Alex Cruz');
assert.equal(page.run('users[0].logs.at(-1).actor'), 'Alex Mendoza');
page.run("applyIncidentWorkflow('IR-2026-1048','action',{notes:'Follow-up', type:'Note', status:'in_progress', assignee:'Network Support'})");
assert.equal(page.run("incidents.find(i=>i.id==='IR-2026-1048').logs.at(-1).by"), 'Alex Cruz');
page.run("openDeviceForm('UPS-MKT-01')");
page.element('deviceReason').value = 'Routine inspection';
page.element('deviceForm').handlers.submit({ preventDefault() {} });
assert.equal(page.run("devices.find(d=>d.id==='UPS-MKT-01').logs.at(-1).actor"), 'Alex Cruz');

const noStorage = boot({ failWrite: true });
noStorage.run("openUserForm('USR-0003', 'inactive')");
noStorage.element('userChangeReason').value = 'Access review';
const oldLogs = noStorage.run('users[2].logs.length');
noStorage.element('userForm').handlers.submit({ preventDefault() {} });
assert.equal(noStorage.run('users[2].status'), 'active');
assert.equal(noStorage.run('users[2].logs.length'), oldLogs);
assert.equal(noStorage.element('userModal').classList.contains('open'), true);
assert.match(noStorage.element('userFormError').textContent, /was not changed/);

console.log('PASS: user creation/editing, duplicate-email validation, roles, branch/environment assignments, activation/deactivation, account history, persistence, filters, safe rendering, administrator protection, audit attribution, and storage failure rollback.');
