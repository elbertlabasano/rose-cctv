const {spawn}=require('node:child_process');
const assert=require('node:assert/strict');
const path=require('node:path');
const fs=require('node:fs');
const profile=path.resolve('tmp/october-browser-review');
const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--disable-gpu','--no-sandbox','--remote-debugging-port=9245',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
const delay=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
 let ws;
 try {
  let pages;
  for(let n=0;n<40;n++){try{pages=await (await fetch('http://127.0.0.1:9245/json')).json();break}catch{await delay(200)}}
  assert(pages,'Chrome started');
  ws=new WebSocket(pages.find(p=>p.type==='page').webSocketDebuggerUrl);
  await new Promise(r=>ws.addEventListener('open',r,{once:true}));
  let id=0;const pending=new Map(),errors=[];
  ws.addEventListener('message',e=>{const m=JSON.parse(e.data);if(m.id){pending.get(m.id)?.(m);pending.delete(m.id)}if(m.method==='Runtime.exceptionThrown')errors.push(JSON.stringify(m.params.exceptionDetails))});
  const send=(method,params={})=>new Promise(r=>{pending.set(++id,r);ws.send(JSON.stringify({id,method,params}))});
  const evaluate=async expression=>{const r=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(r.result.exceptionDetails)throw Error(JSON.stringify(r.result.exceptionDetails));return r.result.result.value};
  await send('Runtime.enable');await send('Page.enable');

  const fixture=structuredClone(require('./fixtures/workspace.cjs'));
  const roster=JSON.parse(fs.readFileSync('web/database/seeders/data/october-2026-monitoring.json','utf8'));
  const source=JSON.parse(fs.readFileSync('web/database/seeders/data/october-2026-history.json','utf8'));
  const original=fixture.devices[0];
  fixture.branches=[];fixture.devices=[];fixture.observations=[];fixture.incidents=[];fixture.outages=[];fixture.audits=[];fixture.followups=[];
  fixture.date='2026-10-26';fixture.today='2026-10-26';fixture.clusters=[];
  let nextId=1;
  for(const [index,group] of roster.clusters.entries()) {
    const cluster={id:index+1,name:group.name,code:group.code};fixture.clusters.push(cluster);
    for(const entry of group.branches) {
      const branch={id:nextId++,name:entry.name,code:entry.code,address:null,active:true,cluster_id:cluster.id,cluster};
      const imported=source.branches.find(item=>item.branch_code===entry.code);
      const last=Object.entries(imported.days).filter(([,cell])=>['ON','OFF'].includes(cell.value)).at(-1);
      fixture.branches.push(branch);
      fixture.devices.push({...original,id:branch.id,asset_id:`CAM-${entry.code}-001`,name:'Branch IP Camera',branch_id:branch.id,branch,type:'IP Camera',status:last[1].value==='ON'?'online':'offline',status_observed_at:null,status_reported_on:last[0],parent_id:null,channel:null,ip:null});
      for(const [date,cell] of Object.entries(imported.days)) {
        const status={ON:'online',OFF:'offline'}[cell.value]||null;
        fixture.observations.push({id:fixture.observations.length+1,device_id:branch.id,date,status,check_result:status?null:{DAYOFF:'day_off',HOLIDAY:'holiday','NO DEVICE':'no_device',ALERT:'alert'}[cell.value],import_source:source.source,import_reference:`${source.sheet}!${cell.cell}`,is_checked:!!status,checked_at:null,checker:null,notes:'Imported worksheet entry; check time not supplied.',attachments:[],ticket:null});
      }
    }
  }
  const legacy={id:999,name:'Legacy demo',code:'DEMO',address:null,active:true,cluster_id:null,cluster:null};
  fixture.branches.push(legacy);fixture.devices.push({...original,id:999,asset_id:'DEMO',branch_id:999,branch:legacy,status:'unknown'});
  const manifest=JSON.parse(fs.readFileSync('web/public/build/manifest.json','utf8'))['resources/js/app.ts'];
  const escape=value=>value.replaceAll('&','&amp;').replaceAll('"','&quot;').replaceAll('<','&lt;');
  const writes=[];
  await send('Fetch.enable',{patterns:[{urlPattern:'http://127.0.0.1:8002/*',requestStage:'Request'}]});
  ws.addEventListener('message',async e=>{const m=JSON.parse(e.data);if(m.method!=='Fetch.requestPaused')return;const {requestId,request}=m.params;
    if(request.method!=='GET'){writes.push(request.url);await send('Fetch.failRequest',{requestId,errorReason:'BlockedByClient'});return;}
    if(new URL(request.url).pathname!=='/cameras'){await send('Fetch.continueRequest',{requestId});return;}
    const page={component:'Workspace',props:{auth:{user:{id:1,name:'UI Test',email:'fixture@example.com',role:'admin'}},flash:{},section:'cameras',workspace:fixture,errors:{}},url:'/cameras',version:null,clearHistory:false,encryptHistory:false};
    const inertia=Object.keys(request.headers).some(h=>h.toLowerCase()==='x-inertia');
    const body=inertia?JSON.stringify(page):`<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/build/${manifest.css[0]}"></head><body><div id="app" data-page="${escape(JSON.stringify(page))}"></div><script type="module" src="/build/${manifest.file}"></script></body></html>`;
    await send('Fetch.fulfillRequest',{requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:inertia?'application/json':'text/html'},...(inertia?[{name:'X-Inertia',value:'true'}]:[])],body:Buffer.from(body).toString('base64')});
  });
  const rowCount=()=>evaluate("document.querySelectorAll('.rw-register-matrix > tbody:not(.rw-daily-summary) > tr').length");
  const total=(key,date)=>evaluate(`document.querySelector('.summary-${key} td[aria-label^="${date}:"]').textContent.trim()`);
  await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1100,deviceScaleFactor:1,mobile:false});
  await send('Page.navigate',{url:'http://127.0.0.1:8002/cameras'});
  for(let i=0;i<60;i++){if(await evaluate("!!document.querySelector('.rw-daily-summary')"))break;await delay(100);}
  assert.equal(await rowCount(),112);
  assert.equal(await evaluate("document.querySelectorAll('.rw-register-day').length"),31);
  assert.equal(await total('online','2026-10-26'),'92');
  assert.equal(await total('offline','2026-10-26'),'19');
  assert.equal(await total('recorded','2026-10-26'),'111');
  assert.equal(await total('unchecked','2026-10-26'),'1');
  assert.equal(await total('day_off','2026-10-04'),'106');
  assert.equal(await total('recorded','2026-10-04'),'112');
  assert.equal(await total('recorded','2026-10-27'),'—');
  assert.equal(await evaluate("document.querySelector('.rw-register-matrix tbody:last-of-type tr:first-child td:nth-of-type(27) button').disabled"),true);
  await evaluate('document.fonts.ready');
  let shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync('tmp/october-desktop.png',Buffer.from(shot.result.data,'base64'));
  await evaluate("document.querySelector('.rw-register-matrix tbody:last-of-type tr:first-child td:nth-of-type(4) button').click()");
  assert.equal(await evaluate("document.querySelector('dialog[open]').textContent.includes('OCTOBER 2026.xlsx')"),true);
  assert.equal(await evaluate("!!document.querySelector('dialog[open] .rw-action-choices')"),false);
  await evaluate("document.querySelector('dialog[open] button[aria-label=\"Close dialog\"]').click()");
  const bankid=fixture.clusters.find(c=>c.name==='BANKID').id;
  await evaluate(`{const select=document.querySelector('[aria-label="Filter by cluster"]');select.value='${bankid}';select.dispatchEvent(new Event('change',{bubbles:true}));}`);await delay(100);
  assert.equal(await rowCount(),7);assert.equal(await total('offline','2026-10-26'),'1');assert.equal(await total('online','2026-10-26'),'6');
  await evaluate("{const select=document.querySelector('[aria-label=\"Filter by cluster\"]');select.value='all';select.dispatchEvent(new Event('change',{bubbles:true}));}");await delay(100);
  assert.equal(await rowCount(),113);
  assert.equal(await total('unchecked','2026-10-26'),'2');
  await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});await delay(200);
  assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true);
  shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync('tmp/october-mobile.png',Buffer.from(shot.result.data,'base64'));
  assert.deepEqual(errors,[]);assert.deepEqual(writes,[]);
  console.log('PASS: 112-camera October fixture, exact daily totals, corrected Sunday totals, 31 columns, blank/disabled future dates, source provenance, read-only historical dialog, cluster filtering, legacy camera access, and responsive layout. No backend writes.');
  await send('Browser.close');
 } finally {ws?.close();chrome.kill();}
})().catch(e=>{console.error(e);process.exitCode=1});
