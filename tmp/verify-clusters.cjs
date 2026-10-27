const {spawn}=require('node:child_process');
const assert=require('node:assert/strict');
const path=require('node:path');
const fs=require('node:fs');
const profile=path.resolve('tmp/clusters-browser-review');
const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--disable-gpu','--no-sandbox','--remote-debugging-port=9246',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
const delay=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
 let ws;
 try {
  let pages;
  for(let n=0;n<40;n++){try{pages=await (await fetch('http://127.0.0.1:9246/json')).json();break}catch{await delay(200)}}
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
  const manifest=JSON.parse(fs.readFileSync('web/public/build/manifest.json','utf8'))['resources/js/app.ts'];
  const sections={dashboard:'overview',cameras:'cameras',checklist:'checklist',devices:'devices',incidents:'incidents',evidence:'evidence',outages:'outages',branches:'branches',tickets:'tickets',reminders:'reminders',users:'users',reports:'reports'};
  const escape=value=>value.replaceAll('&','&amp;').replaceAll('"','&quot;').replaceAll('<','&lt;');
  const writes=[];
  await send('Fetch.enable',{patterns:[{urlPattern:'http://127.0.0.1:8002/*',requestStage:'Request'}]});
  ws.addEventListener('message',async e=>{const m=JSON.parse(e.data);if(m.method!=='Fetch.requestPaused')return;
    const {requestId,request}=m.params;const url=new URL(request.url),route=url.pathname.slice(1);
    if(request.method!=='GET'){writes.push(request.url);await send('Fetch.failRequest',{requestId,errorReason:'BlockedByClient'});return;}
    if(!sections[route]){await send('Fetch.continueRequest',{requestId});return;}
    const scoped=structuredClone(fixture);scoped.date=url.searchParams.get('date')||fixture.date;
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
  const rows={dashboard:'.rw-overview-panel:first-child .rw-list-row',cameras:'.rw-register-matrix tbody:not(.rw-daily-summary) tr',checklist:'.rw-checklist > .rw-list-row',devices:'.rw-table tbody tr',incidents:'.rw-incident-list button',evidence:'.rw-evidence-item',outages:'article.rw-card > .rw-list-row',branches:'.rw-branch-panel',tickets:'article.rw-card > .rw-list-row',reminders:'article.rw-card > .rw-list-row',users:'.rw-table tbody tr',reports:'article.rw-card > .rw-list-row'};
  for(const route of Object.keys(sections)){
    await navigate(route);await choose(cluster,'1');
    assert.equal(await count(cluster),1,route+' has one shared cluster selector');
    const options=await evaluate(`Array.from(document.querySelector(${JSON.stringify(branchSelect)})?.options||[]).map(e=>e.textContent.trim())`);
    assert.deepEqual(options,['All branches','North Main','North Second'],route+' branch choices are scoped');
    const actual=await count(rows[route]);
    assert.equal(actual,route==='users'?3:route==='reminders'?4:2,route+' filtered rows');
    assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,route+' desktop layout');
    if(route==='dashboard'||route==='checklist'){
      const metrics=await evaluate("Array.from(document.querySelectorAll('.rw-metrics article')).map(e=>e.textContent.trim())");
      assert.deepEqual(metrics,route==='dashboard'?['Cameras2','Online0','Offline2','Active incidents2']:['Checked2 / 2','Still to check0','Offline2','Evidence images2'],route+' scoped metrics');
    }
    if(route==='cameras'){
      assert.equal(await evaluate("document.querySelector('.summary-offline td:nth-child(27)').textContent.trim()"),'2','register summary follows cluster');
    }
    if(route==='devices'){
      await choose('.rw-card-filters label:nth-child(3) select','online');
      assert.equal(await count(rows.devices),0,'status intersects cluster');
      await choose('.rw-card-filters label:nth-child(3) select','all');
      await evaluate("(()=>{const e=document.querySelector('.rw-card-filters input[type=search]');e.value='CAM-2';e.dispatchEvent(new Event('input',{bubbles:true}))})()");await delay(100);
      assert.equal(await count(rows.devices),1,'search intersects cluster');
    }
    if(route==='reports'){
      assert.equal(await evaluate("Array.from(document.querySelectorAll('a[href*=\"/reports/export\"]')).every(e=>new URL(e.href).searchParams.get('cluster_id')==='1')"),true);
      await choose(branchSelect,'2');await choose('input[type="date"]','2026-10-24');
      await waitFor("location.search.includes('date=2026-10-24')");
      assert.equal(await evaluate("new URLSearchParams(location.search).get('cluster_id')"),'1');
      assert.equal(await evaluate("new URLSearchParams(location.search).get('branch_id')"),'2');
      assert.equal(await count(rows.reports),1);
    }
    if(route==='users'){
      assert.equal(await count('article.rw-card.space-top .rw-list-row'),3,'scoped audits and North account audit');
      await choose(branchSelect,'1');assert.equal(await count(rows.users),2,'all-branch admin and matching account');
      await choose(cluster,'2');
      assert.equal(await evaluate(`document.querySelector(${JSON.stringify(branchSelect)}).value`),'all','incompatible branch reset');
      assert.equal(await count(rows.users),2,'South account and all-branch admin');
    }
    await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});await delay(60);
    assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,route+' mobile layout');
    await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    console.log('PASS '+route);
  }
  await navigate('checklist');await choose(cluster,'1');
  await evaluate("Array.from(document.querySelectorAll('button')).find(e=>e.textContent.trim()==='Select visible cameras').click()");
  assert.equal(await count('.rw-checklist > .rw-list-row input:checked'),2,'bulk selection in cluster');
  await choose(cluster,'2');assert.equal(await count('.rw-checklist > .rw-list-row input:checked'),0,'cluster change clears bulk selection');
  await choose(cluster,'unassigned');assert.equal(await count(rows.checklist),1,'unassigned branch');
  await choose(cluster,'clustered');assert.equal(await count(rows.checklist),3,'assigned clusters');
  await choose(cluster,'all');assert.equal(await count(rows.checklist),4,'all clusters includes unassigned');
  assert.deepEqual(writes,[],'no backend mutations');assert.deepEqual(errors,[],'no browser exceptions');
  console.log('PASS: cluster scope across 12 pages, branch cascade, bulk selection reset, user access and audits, export links/date navigation, and desktop/mobile layouts. Browser fixtures only; all writes blocked.');
  await send('Browser.close');
 } finally {ws?.close();chrome.kill();}
})().catch(e=>{console.error(e);process.exitCode=1});
