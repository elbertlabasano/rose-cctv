const {spawn}=require('node:child_process');
const assert=require('node:assert/strict');
const path=require('node:path');
const fs=require('node:fs');
const profile=path.resolve('tmp/quick-status-browser-review');
const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--disable-gpu','--no-sandbox','--remote-debugging-port=9249',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
const delay=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
 let ws;
 try {
  let pages;
  for(let n=0;n<40;n++){try{pages=await (await fetch('http://127.0.0.1:9249/json')).json();break}catch{await delay(200)}}
  assert(pages,'Chrome started');
  ws=new WebSocket(pages.find(p=>p.type==='page').webSocketDebuggerUrl);
  await new Promise(r=>ws.addEventListener('open',r,{once:true}));
  let id=0;const pending=new Map(),errors=[];
  ws.addEventListener('message',e=>{const m=JSON.parse(e.data);if(m.id){pending.get(m.id)?.(m);pending.delete(m.id)}if(m.method==='Runtime.exceptionThrown')errors.push(JSON.stringify(m.params.exceptionDetails))});
  const send=(method,params={})=>new Promise(r=>{pending.set(++id,r);ws.send(JSON.stringify({id,method,params}))});
  const evaluate=async expression=>{const r=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(r.result.exceptionDetails)throw Error(JSON.stringify(r.result.exceptionDetails));return r.result.result.value};
  await send('Runtime.enable');await send('Page.enable');

  const template=require('./fixtures/workspace.cjs');
  const fixture=structuredClone(template);
  const names=['North Main','North Second','South Branch','Legacy Branch'];
  fixture.today=fixture.date='2026-10-26';
  fixture.clusters=[{id:1,name:'North Cluster',code:'N'},{id:2,name:'South Cluster',code:'S'}];
  fixture.branches=names.map((name,i)=>({...template.branches[0],id:i+1,name,code:'B'+(i+1),cluster_id:i<2?1:i===2?2:null,cluster:i<2?fixture.clusters[0]:i===2?fixture.clusters[1]:null}));
  fixture.devices=fixture.branches.map(b=>({...template.devices[0],id:b.id,asset_id:'CAM-'+b.id,name:'Camera '+b.id,branch_id:b.id,branch:b}));
  fixture.observations=fixture.devices.map(d=>({...template.observations[0],id:d.id,device_id:d.id,date:fixture.date,attachments:[{id:d.id,name:'Evidence '+d.id,url:'/brand/rose-logo.png',mime:'image/png'}],ticket:{id:d.id,status:'created',external_url:'https://example.com/ticket/'+d.id,external_id:'T'+d.id,requested_at:'2026-10-26T01:00:00Z'}}));
  fixture.incidents=fixture.devices.map(d=>({...template.incidents[0],id:d.id,device_id:d.id,device:d,branch_id:d.branch_id,branch:d.branch,title:'Incident '+d.branch.name,reference:'IR-'+d.id,logs:[]}));
  fixture.outages=fixture.devices.map(d=>({...template.outages[0],id:d.id,device_id:d.id}));
  fixture.accounts=[{...template.accounts[0],name:'All branch admin'},...fixture.branches.map(b=>({...template.accounts[0],id:b.id+1,name:b.name+' user',email:'user'+b.id+'@example.com',role:'operator',all_branches:false,branches:[b]}))];
  fixture.audits=fixture.branches.map(b=>({id:b.id,branch_id:b.id,action:'updated',subject_type:'Device',subject_id:b.id,reason:b.name+' audit',created_at:'2026-10-26T01:00:00Z',user:{name:'Reviewer'}}));
  fixture.audits.push({id:8,branch_id:null,action:'updated',subject_type:'User',subject_id:2,reason:'North account audit',created_at:'2026-10-26T01:00:00Z',user:{name:'Reviewer'}});
  for(let id=5;id<=25;id++){
    const branch=fixture.branches[(id-1)%3];
    fixture.devices.push({...fixture.devices[0],id,asset_id:'CAM-'+id,name:'Camera '+id,branch_id:branch.id,branch});
  }
  const quickCalls=[];let failSave=false, pageRequests=0;
  const manifest=JSON.parse(fs.readFileSync('web/public/build/manifest.json','utf8'))['resources/js/app.ts'];
  const sections={dashboard:'overview',cameras:'cameras',checklist:'checklist',devices:'devices',incidents:'incidents',evidence:'evidence',outages:'outages',branches:'branches',tickets:'tickets',reminders:'reminders',users:'users',reports:'reports'};
  const escape=value=>value.replaceAll('&','&amp;').replaceAll('"','&quot;').replaceAll('<','&lt;');
  const writes=[];
  await send('Fetch.enable',{patterns:[{urlPattern:'http://127.0.0.1:8002/*',requestStage:'Request'}]});
  ws.addEventListener('message',async e=>{const m=JSON.parse(e.data);if(m.method!=='Fetch.requestPaused')return;
    const {requestId,request}=m.params;const url=new URL(request.url),route=url.pathname.slice(1);
    if(request.method==='POST' && url.pathname==='/observations/quick-status'){
      const input=JSON.parse(request.postData);quickCalls.push(input);
      await delay(250);
      if(failSave){await send('Fetch.fulfillRequest',{requestId,responseCode:422,responseHeaders:[{name:'Content-Type',value:'application/json'}],body:Buffer.from(JSON.stringify({message:'Validation failed',errors:{date:['Quick status checks are allowed only for today in Philippine time.']}})).toString('base64')});return;}
      const device=fixture.devices.find(d=>d.id===input.device_id);device.status=input.status;device.status_reported_on=null;device.status_observed_at='2026-10-26T07:00:00Z';
      const original=fixture.observations.find(o=>o.device_id===device.id && o.date===input.date);
      const observation={...(original||{id:100+device.id,device_id:device.id,attachments:[],ticket:null,notes:null}),date:input.date,status:input.status,is_checked:true,check_result:null,import_source:null,import_reference:null,checked_at:'2026-10-26T07:00:00Z',checker:'Reviewer'};
      fixture.observations=fixture.observations.filter(o=>o.device_id!==device.id||o.date!==input.date);fixture.observations.push(observation);
      const body={device:{id:device.id,status:device.status,status_reported_on:null,status_observed_at:device.status_observed_at},observation,outages:[]};
      await send('Fetch.fulfillRequest',{requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'application/json'}],body:Buffer.from(JSON.stringify(body)).toString('base64')});return;
    }
    if(request.method!=='GET'){writes.push(request.url);await send('Fetch.failRequest',{requestId,errorReason:'BlockedByClient'});return;}
    if(!sections[route]){await send('Fetch.continueRequest',{requestId});return;}
    pageRequests++;const scoped=structuredClone(fixture);scoped.date=url.searchParams.get('date')||fixture.date;
    const page={component:'Workspace',props:{auth:{user:{id:1,name:'Reviewer',email:'fixture@example.com',role:'admin'}},flash:{},section:sections[route],workspace:scoped,errors:{}},url:url.pathname+url.search,version:null,clearHistory:false,encryptHistory:false};
    const inertia=Object.keys(request.headers).some(h=>h.toLowerCase()==='x-inertia');
    const body=inertia?JSON.stringify(page):`<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/build/${manifest.css[0]}"></head><body><div id="app" data-page="${escape(JSON.stringify(page))}"></div><script type="module" src="/build/${manifest.file}"></script></body></html>`;
    await send('Fetch.fulfillRequest',{requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:inertia?'application/json':'text/html'},...(inertia?[{name:'X-Inertia',value:'true'}]:[])],body:Buffer.from(body).toString('base64')});
  });
  const waitFor=async(expression)=>{for(let n=0;n<50;n++){if(await evaluate(expression))return;await delay(100)}throw Error('Timed out: '+expression)};
  const choose=async(selector,value)=>{await evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});if(!e)throw Error('Missing '+${JSON.stringify(selector)});e.value=${JSON.stringify(value)};e.dispatchEvent(new Event('change',{bubbles:true}))})()`);await delay(100)};
  const cluster='select[aria-label="Filter by cluster"]';
  const branchSelect='.rw-card-filters label:nth-child(2) select, .rw-checklist-toolbar label:nth-child(2) select, .rw-register-toolbar label:nth-child(3) select';
  const navigate=async route=>{await send('Page.navigate',{url:'http://127.0.0.1:8002/'+route});await waitFor(`!!document.querySelector(${JSON.stringify(cluster)})`)};
  const count=selector=>evaluate(`document.querySelectorAll(${JSON.stringify(selector)}).length`);
  await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});

  const online=id=>`input[aria-label="CAM-${id} online for 2026-10-26"]`;
  const offline=id=>`input[aria-label="CAM-${id} online for 2026-10-26"]`;
  await navigate('cameras');
  assert.equal(await count('.quick-status input[type=checkbox]'),24,'one checkbox per camera');
  assert.equal(await evaluate("document.querySelector('.rw-register-matrix').lastElementChild.tagName"),'TFOOT','daily totals follow camera rows');
  assert.equal(await count('.rw-register-matrix tfoot.rw-daily-summary tr'),6,'all summary rows retained at bottom');
  assert.equal(await count('.quick-status'),24,'quick marking only for today on every visible camera');
  assert.equal(await count('.rw-register-matrix td.past .quick-status'),0);
  assert.equal(await count('.rw-register-matrix td.future .quick-status'),0);
  assert.equal(await evaluate("document.querySelector('.rw-quick-cell').getBoundingClientRect().width>=105"),true,'compact status column');
  await evaluate("(()=>{const e=document.querySelector('.rw-matrix-scroll');e.scrollLeft=1800;e.scrollTop=140})()");
  const scroll=await evaluate("(()=>{const e=document.querySelector('.rw-matrix-scroll');return [e.scrollLeft,e.scrollTop]})()");
  await evaluate(`document.querySelector(${JSON.stringify(online(1))}).click()`);
  assert.equal(await evaluate(`document.querySelector(${JSON.stringify(online(1))}).disabled`),true);
  assert.equal(await evaluate(`document.querySelector(${JSON.stringify(offline(1))}).disabled`),true);
  await evaluate(`document.querySelector(${JSON.stringify(online(1))}).click()`);
  await waitFor(`document.querySelector(${JSON.stringify(online(1))}).checked===true`);
  assert.equal(quickCalls.length,1,'double click sends one request');
  assert.deepEqual(quickCalls[0],{device_id:1,date:'2026-10-26',status:'online'});
  assert.deepEqual(await evaluate("(()=>{const e=document.querySelector('.rw-matrix-scroll');return [e.scrollLeft,e.scrollTop]})()"),scroll,'scroll preserved');
  assert.equal(pageRequests,1,'no full workspace reload');
  assert.equal(await count('dialog[open]'),0,'no modal for marking status');
  assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'desktop overflow contained');
  assert.equal(await evaluate("document.querySelector('.summary-online td:nth-child(27)').textContent.trim()"),'1');
  assert.equal(await evaluate("document.querySelector('.summary-offline td:nth-child(27)').textContent.trim()"),'2');
  await evaluate(`document.querySelector(${JSON.stringify(online(2))}).click();document.querySelector(${JSON.stringify(online(3))}).click()`);
  await waitFor(`document.querySelector(${JSON.stringify(online(2))}).checked===true && document.querySelector(${JSON.stringify(online(3))}).checked===true`);
  assert.equal(quickCalls.length,3,'different camera saves can proceed together');
  assert.equal(await evaluate("document.querySelector('.summary-online td:nth-child(27)').textContent.trim()"),'3','concurrent updates retained');
  failSave=true;
  await evaluate(`document.querySelector(${JSON.stringify(offline(1))}).click()`);await waitFor("!!document.querySelector('.quick-error')");
  assert.equal(await evaluate(`document.querySelector(${JSON.stringify(online(1))}).checked`),true,'failed save does not change displayed state');
  assert.equal(await evaluate(`document.querySelector(${JSON.stringify(offline(1))}).disabled`),false,'failed action can be retried');
  failSave=false;
  await evaluate(`document.querySelector(${JSON.stringify(offline(1))}).focus()`);
  await send('Page.bringToFront');
  assert.equal(await evaluate(`document.activeElement===document.querySelector(${JSON.stringify(offline(1))})`),true,'button focused for keyboard marking');
  await send('Input.dispatchKeyEvent',{type:'rawKeyDown',key:' ',code:'Space',windowsVirtualKeyCode:32});await send('Input.dispatchKeyEvent',{type:'char',key:' ',text:' ',windowsVirtualKeyCode:32});await send('Input.dispatchKeyEvent',{type:'keyUp',key:' ',code:'Space',windowsVirtualKeyCode:32});
  await waitFor(`document.querySelector(${JSON.stringify(offline(1))}).checked===false`);
  await evaluate("(()=>{const e=document.querySelector('.rw-matrix-scroll');e.scrollTop=0;const cell=document.querySelector('.rw-quick-cell');e.scrollLeft=cell.offsetLeft-e.clientWidth+190})()");
  let shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync('tmp/quick-status-desktop.png',Buffer.from(shot.result.data,'base64'));
  await evaluate("document.querySelector('button[aria-label^=\"CAM-1, 2026-10-26:\"]').click()");await waitFor("!!document.querySelector('dialog[open]')");
  await evaluate("Array.from(document.querySelectorAll('dialog[open] label')).find(e=>e.textContent.trim()==='Attach images').querySelector('input').click()");await waitFor("!!document.querySelector('dialog[open] textarea')");
  assert.equal(await evaluate("document.querySelector('dialog[open] textarea').value"),'Offline on check','notes preserved after quick save');
  assert.equal(await count('dialog[open] img'),1,'evidence retained');
  await evaluate("document.querySelector('dialog[open] [aria-label=\"Close dialog\"]').click()");
  await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});await delay(100);
  assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true);
  assert.equal(await evaluate(`document.querySelector(${JSON.stringify(online(1))}).closest('label').getBoundingClientRect().height>=44`),true,'mobile label touch size');
  await evaluate("Array.from(document.querySelectorAll('button')).find(e=>e.textContent.trim()==='Jump to today').click()");await delay(600);
  assert.equal(await evaluate(`(()=>{const cell=document.querySelector('.rw-quick-cell').getBoundingClientRect();const viewport=document.querySelector('.rw-matrix-scroll').getBoundingClientRect();const sticky=document.querySelector('th.sticky-camera').getBoundingClientRect();return cell.left>=sticky.right-1&&cell.right<=viewport.right+1})()`),true,'checkbox fits beside the sticky camera on mobile');
  shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});fs.writeFileSync('tmp/quick-status-mobile.png',Buffer.from(shot.result.data,'base64'));
  fixture.permissions.write=false;await navigate('cameras');assert.equal(await count('.quick-status'),0,'viewer has no quick actions');
  fixture.permissions.write=true;await navigate('cameras?date=2026-09-26');assert.equal(await count('.quick-status'),0,'historical month has no quick actions');
  assert.deepEqual(writes,[],'all other writes blocked');assert.deepEqual(errors,[]);
  console.log('PASS: single checkbox: check Online, uncheck Offline, one request per click, safe errors/retry, concurrent saves, summary updates, preserved matrix scroll, Details/evidence, keyboard controls, viewer/history restrictions and responsive layout. Synthetic POSTs only; no live database writes.');
  await send('Browser.close');
 } finally {ws?.close();chrome.kill();}
})().catch(e=>{console.error(e);process.exitCode=1});
