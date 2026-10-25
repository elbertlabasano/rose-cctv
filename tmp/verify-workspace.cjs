const {spawn}=require('node:child_process');
const assert=require('node:assert/strict');
const path=require('node:path');
const fs=require('node:fs');
const profile=path.resolve('tmp/workspace-browser-review');
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
  const fixture=require('./fixtures/workspace.cjs');
  const manifest=JSON.parse(fs.readFileSync('web/public/build/manifest.json','utf8'))['resources/js/app.ts'];
  const sections={dashboard:'overview',cameras:'cameras',checklist:'checklist',devices:'devices',incidents:'incidents',evidence:'evidence',outages:'outages',branches:'branches',tickets:'tickets',reminders:'reminders',users:'users',reports:'reports',process:'process'};
  const escape=value=>value.replaceAll('&','&amp;').replaceAll('"','&quot;').replaceAll('<','&lt;');
  await send('Fetch.enable',{patterns:[{urlPattern:'http://127.0.0.1:8127/*',requestStage:'Request'}]});
  ws.addEventListener('message',async e=>{const m=JSON.parse(e.data);if(m.method!=='Fetch.requestPaused')return;const {requestId,request}=m.params;const path=new URL(request.url).pathname.slice(1);if(!sections[path]||request.method!=='GET'){await send('Fetch.continueRequest',{requestId});return;}
    const page={component:'Workspace',props:{auth:{user:{id:1,name:'UI Test',email:'fixture@example.com',role:'admin'}},flash:{},section:sections[path],workspace:fixture,errors:{}},url:'/'+path,version:null,clearHistory:false,encryptHistory:false};
    const inertia=Object.keys(request.headers).some(h=>h.toLowerCase()==='x-inertia');
    const body=inertia?JSON.stringify(page):`<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/build/${manifest.css[0]}"></head><body><div id="app" data-page="${escape(JSON.stringify(page))}"></div><script type="module" src="/build/${manifest.file}"></script></body></html>`;
    await send('Fetch.fulfillRequest',{requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:inertia?'application/json':'text/html'},...(inertia?[{name:'X-Inertia',value:'true'}]:[])],body:Buffer.from(body).toString('base64')});
  });
  await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
  await send('Page.navigate',{url:'http://127.0.0.1:8127/cameras'});await delay(1200);
  assert.equal(await evaluate("document.querySelector('h1')?.textContent"),'Camera status by day');
  assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true);
  assert.equal(await evaluate("document.querySelectorAll('.rw-register-matrix thead tr').length"),2);
  assert.equal(await evaluate("document.querySelectorAll('.rw-register-legend span').length"),5);
  assert.equal(await evaluate("(()=>{const label=document.querySelector('.rw-register-month-label').getBoundingClientRect();const viewport=document.querySelector('.rw-matrix-scroll').getBoundingClientRect();return label.left>=viewport.left&&label.right<=viewport.right+1})()"),true,'Month heading remains visible in the viewport');
  assert.equal(await evaluate("!!document.querySelector('.rw-matrix-camera .rw-status')"),true);
  assert.equal(await evaluate("document.querySelector('.rw-register-matrix').getBoundingClientRect().width>document.querySelector('.rw-matrix-scroll').clientWidth"),true);
  assert.equal(await evaluate("document.querySelector('.rw-register-toolbar input').placeholder"),'Search camera, branch or location');
  const registerShot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync('tmp/register-review.png',Buffer.from(registerShot.result.data,'base64'));

  await evaluate("document.querySelector('.rw-matrix tbody td button').click()");
  assert.equal(await evaluate("document.querySelector('dialog[open]')?.getAttribute('aria-label').includes('CAM-MKT-001')"),true);
  assert.equal(await evaluate("document.querySelector('dialog[open]').textContent.includes('read-only')"),true);
  assert.equal(await evaluate("document.querySelectorAll('dialog[open] .rw-action-choices input').length"),0);
  assert.equal(await evaluate("document.querySelectorAll('dialog[open] button[type=submit]').length"),0);
  await evaluate("document.querySelector('dialog[open] button[aria-label=\"Close dialog\"]').click();document.querySelector('.rw-matrix tbody td:last-child button').click()");
  assert.equal(await evaluate("document.querySelectorAll('dialog[open] .rw-action-choices input').length"),3);
  await evaluate("[...document.querySelectorAll('dialog[open] .rw-action-choices input')].forEach(i=>i.click())");
  assert.equal(await evaluate("document.querySelector('dialog[open]').textContent.includes('Capture screenshot')"),true);
  assert.equal(await evaluate("document.querySelector('dialog[open]').textContent.includes('not connected yet')"),true);
  await evaluate("document.querySelector('dialog[open] button[aria-label=\"Close dialog\"]').click()");
  for(const path of ['checklist','devices','incidents','evidence','outages','branches','tickets','reminders','users','reports','process']){
    await send('Page.navigate',{url:'http://127.0.0.1:8127/'+path});await delay(350);
    assert.equal(await evaluate("!!document.querySelector('h1')"),true,path+' renders');
    assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,path+' has no page overflow');
  }
  await send('Page.navigate',{url:'http://127.0.0.1:8127/devices'});await delay(350);
  await evaluate("[...document.querySelectorAll('button')].find(b=>b.textContent.includes('Add device')).click()");
  assert.equal(await evaluate("document.querySelector('dialog[open]').textContent.includes('Connections & storage')"),true);
  await evaluate("document.querySelector('dialog[open] button[aria-label=\"Close dialog\"]').click()");
  await send('Page.navigate',{url:'http://127.0.0.1:8127/incidents'});await delay(350);
  await evaluate("[...document.querySelectorAll('button')].find(b=>b.textContent==='Resolve').click()");
  assert.equal(await evaluate("document.querySelector('dialog[open]').textContent.includes('Root cause')"),true);
  await evaluate("document.querySelector('dialog[open] button[aria-label=\"Close dialog\"]').click()");
  await send('Page.navigate',{url:'http://127.0.0.1:8127/checklist'});await delay(350);
  let shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync('tmp/workspace-desktop.png',Buffer.from(shot.result.data,'base64'));
  await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});await delay(250);
  assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true);
  await evaluate("document.querySelector('button[aria-label=\"Open menu\"]').click()");
  assert.equal(await evaluate("document.querySelector('.rw-sidebar').classList.contains('open')"),true);await delay(300);
  shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync('tmp/workspace-mobile.png',Buffer.from(shot.result.data,'base64'));
  assert.deepEqual(errors,[]);
  console.log('PASS: compiled workspace pages, device/date dialog and action selection, device accordion, incident resolution form, desktop/mobile layouts, and mobile navigation. Browser-only fixtures used; no backend account was created.');
  await send('Browser.close');
 } finally {ws?.close();chrome.kill();}
})().catch(e=>{console.error(e);process.exitCode=1});
