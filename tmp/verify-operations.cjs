const {spawn}=require('node:child_process');
const assert=require('node:assert/strict');
const path=require('node:path');
const fs=require('node:fs');
const profile=path.resolve('tmp/camera-browser-review');
const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--disable-gpu','--no-sandbox','--remote-debugging-port=9238',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
const delay=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
 let ws;
 try {
  let pages;
  for(let n=0;n<40;n++){try{pages=await (await fetch('http://127.0.0.1:9238/json')).json();break}catch{await delay(200)}}
  assert(pages,'Chrome started');
  ws=new WebSocket(pages.find(p=>p.type==='page').webSocketDebuggerUrl);
  await new Promise(r=>ws.addEventListener('open',r,{once:true}));
  let id=0;const pending=new Map(),errors=[];
  ws.addEventListener('message',e=>{const m=JSON.parse(e.data);if(m.id){pending.get(m.id)?.(m);pending.delete(m.id)}if(m.method==='Runtime.exceptionThrown')errors.push(JSON.stringify(m.params.exceptionDetails))});
  const send=(method,params={})=>new Promise(r=>{pending.set(++id,r);ws.send(JSON.stringify({id,method,params}))});
  const evaluate=async expression=>{const r=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(r.result.exceptionDetails)throw Error(JSON.stringify(r.result.exceptionDetails));return r.result.result.value};
  await send('Runtime.enable');await send('Page.enable');
  await send('Page.navigate',{url:'file:///'+path.resolve('index.html').replaceAll('\\','/')});await delay(900);
  for(let n=0;n<30;n++){if(await evaluate('typeof window.openCameraDay === \'function\''))break;await delay(100)}
  for(let i=0;i<30;i++){if(await evaluate('typeof window.roseWatchOperations === \'object\''))break;await delay(100)}
  await evaluate("localStorage.removeItem('rosewatch-camera-workflow-v1');localStorage.removeItem('rosewatch-followups-v1');location.reload()");await delay(900);
  assert.equal(await evaluate("document.querySelectorAll('#page-checklist [data-ops-review]').length"),8);
  await evaluate("showPage('checklist');document.querySelector('[data-ops-select=\"CAM-MKT-001\"]').click();document.querySelector('[data-ops-select=\"CAM-MKT-012\"]').click();document.getElementById('opsBulkOpen').click()");
  assert.equal(await evaluate("document.getElementById('opsBulkDialog').open"),true);
  await evaluate("document.getElementById('opsBulkReason').value='Confirmed shared power outage';document.getElementById('opsBulkState').value='down';document.getElementById('opsBulkForm').requestSubmit()");
  assert.equal(await evaluate("cameraRows[0].current"),'down');
  assert.equal(await evaluate("roseWatch.getDailyRecords()['CAM-MKT-001:2026-10-25'].notes"),'Confirmed shared power outage');
  assert.equal(await evaluate("document.getElementById('opsBulkDialog').open"),false);
  assert.equal(await evaluate("document.getElementById('ops-checklist').textContent.includes('Checked today')"),true);
  assert.equal(await evaluate("roseWatchOperations.episodes().some(e=>e.camera.id==='CAM-MKT-001'&&e.active)"),true);
  await evaluate("roseWatch.saveDailyStatuses([{cameraId:'CAM-MKT-012',date:'2026-10-25',state:'live',notes:'Power restored and camera checked'}])");
  assert.equal(await evaluate("roseWatchOperations.episodes().some(e=>e.camera.id==='CAM-MKT-012'&&!e.active&&e.end===25)"),true);
  await evaluate(`(async()=>{const c=document.createElement('canvas');c.width=40;c.height=40;const blob=await new Promise(r=>c.toBlob(r,'image/png'));await roseWatch.attachCameraScreenshot('CAM-MKT-001','2026-10-25',blob)})()`);
  assert.equal(await evaluate("document.querySelectorAll('[data-ops-image]').length"),1);
  await evaluate("showPage('evidence');document.querySelector('[data-ops-image]').click()");
  assert.equal(await evaluate("document.querySelector('.ops-image-viewer').open"),true);
  await evaluate("document.getElementById('opsImageClose').click();showPage('branches')");
  assert.equal(await evaluate("document.querySelectorAll('[data-ops-branch]').length"),4);
  await evaluate("document.querySelector('[data-ops-branch=\"Makati Central\"]').click()");
  assert.equal(await evaluate("document.getElementById('opsBranch').value"),'Makati Central');
  assert.equal(await evaluate("document.querySelectorAll('#ops-checklist [data-ops-review]').length"),2);
  await evaluate("showPage('reminders');document.querySelector('[data-ops-snooze]').click()");
  assert.equal(await evaluate("Object.values(JSON.parse(localStorage.getItem('rosewatch-followups-v1'))).includes('2026-10-26')"),true);
  assert.equal(await evaluate("roseWatchOperations.reportHtml().includes('Confirmed shared power outage')"),true);
  assert.equal(await evaluate("roseWatchOperations.reportHtml().includes('data:image/jpeg')"),true);
  await evaluate('location.reload()');await delay(900);
  assert.equal(await evaluate("roseWatch.getDailyRecords()['CAM-MKT-001:2026-10-25'].state"),'down');
  assert.deepEqual(errors,[]);
  console.log('PASS: checklist, bulk status persistence, outage/restoration dates, screenshot history and preview, branch filters, follow-up snooze, full report evidence, reload, and no runtime errors.');
  await evaluate("showPage('checklist')"); await delay(300);
  await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1100,deviceScaleFactor:1,mobile:false});
  const shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync('tmp/operations-review.png',Buffer.from(shot.result.data,'base64'));
  await send('Browser.close');
 } finally {ws?.close();chrome.kill();}
})().catch(e=>{console.error(e);process.exitCode=1});
