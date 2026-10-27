const {spawn}=require('node:child_process');
const assert=require('node:assert/strict');
const path=require('node:path');
const fs=require('node:fs');
const profile=path.resolve('tmp/tickets-browser-review');
const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--disable-gpu','--no-sandbox','--remote-debugging-port=9248',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
const delay=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
 let ws;
 try {
  let pages;
  for(let n=0;n<40;n++){try{pages=await (await fetch('http://127.0.0.1:9248/json')).json();break}catch{await delay(200)}}
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
  fixture.ticket_configured=fixture.ticket_view_configured=true;
  fixture.ticket_boards=[{id:10,name:'CCTV Reports/Concerns'},{id:9,name:'CCTV Footage/Defiance Daily Reporting'}];
  fixture.ticket_default_board=10;
  fixture.observations[1].ticket=null;
  fixture.observations[2].ticket.status='unconfirmed';fixture.observations[2].ticket.external_url=null;
  let liveCalls=0, failLive=false;
  const manifest=JSON.parse(fs.readFileSync('web/public/build/manifest.json','utf8'))['resources/js/app.ts'];
  const sections={dashboard:'overview',cameras:'cameras',checklist:'checklist',devices:'devices',incidents:'incidents',evidence:'evidence',outages:'outages',branches:'branches',tickets:'tickets',reminders:'reminders',users:'users',reports:'reports'};
  const escape=value=>value.replaceAll('&','&amp;').replaceAll('"','&quot;').replaceAll('<','&lt;');
  const writes=[];
  await send('Fetch.enable',{patterns:[{urlPattern:'http://127.0.0.1:8002/*',requestStage:'Request'}]});
  ws.addEventListener('message',async e=>{const m=JSON.parse(e.data);if(m.method!=='Fetch.requestPaused')return;
    const {requestId,request}=m.params;const url=new URL(request.url),route=url.pathname.slice(1);
    if(request.method!=='GET'){writes.push(request.url);await send('Fetch.failRequest',{requestId,errorReason:'BlockedByClient'});return;}
    if(url.pathname==='/tickets/external'){
      liveCalls++;
      const page=Number(url.searchParams.get('page')||1);
      const entry=id=>({request_id:id,id:'SH-'+id,url:'https://support.example/app/ticket/'+id,subject:'Camera ticket '+id,description:'<b>Plain text evidence notes</b>',status:'open',priority:'medium',board:'CCTV Reports/Concerns',assignees:['Technician'],due_date:'2026-10-27',updated_at:'2026-10-26T06:00:00Z'});
      const body=failLive?{message:'Live tickets could not be loaded. Please try again later.'}:{data:page===1?[entry(1),entry(4)]:[],page,has_more:page===1};
      await send('Fetch.fulfillRequest',{requestId,responseCode:failLive?503:200,responseHeaders:[{name:'Content-Type',value:'application/json'}],body:Buffer.from(JSON.stringify(body)).toString('base64')});return;
    }
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

  await navigate('tickets');await choose(cluster,'all');
  assert.equal(liveCalls,0,'no unsolicited remote load');
  assert.equal(await evaluate("Array.from(document.querySelectorAll('button')).some(e=>e.textContent.trim()==='Retry creation')"),false,'uncertain creation has no retry button');
  await evaluate("document.querySelector('.live-tickets button').click()");await waitFor("document.querySelectorAll('.live-ticket').length===2");
  assert.equal(liveCalls,1);
  assert.equal(await evaluate("document.querySelector('.live-ticket-description').textContent.trim()"),'<b>Plain text evidence notes</b>','description remains escaped');
  assert.equal(await count('.live-ticket-description b'),0);
  await choose(cluster,'1');assert.equal(await count('.live-ticket'),1,'live list follows cluster');
  assert.equal(await evaluate("document.querySelector('.live-ticket .rw-status').textContent.trim()"),'open');
  await evaluate("document.querySelector('.live-ticket-pages button:last-child').click()");await waitFor("document.querySelector('.live-ticket-pages span')?.textContent==='Page 2'");
  assert.equal(await count('.live-ticket'),0);assert.equal(await evaluate("document.querySelector('.live-ticket-pages button:last-child').disabled"),true);
  await evaluate("document.querySelector('.live-ticket-pages button:first-child').click()");await waitFor("document.querySelectorAll('.live-ticket').length===1");
  let shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync('tmp/tickets-desktop.png',Buffer.from(shot.result.data,'base64'));
  await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});await delay(100);
  assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true);
  shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});fs.writeFileSync('tmp/tickets-mobile.png',Buffer.from(shot.result.data,'base64'));
  failLive=true;await evaluate("document.querySelector('.live-ticket-heading button').click()");await waitFor("!!document.querySelector('.live-tickets [role=alert]')");
  await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
  await navigate('cameras');
  await evaluate("document.querySelector('button[aria-label^=\"CAM-2, 2026-10-26:\"]').click()");await waitFor("!!document.querySelector('dialog[open]')");
  await evaluate("Array.from(document.querySelectorAll('dialog[open] label')).find(e=>e.textContent.trim()==='Create ticket').querySelector('input').click()");
  await waitFor("Array.from(document.querySelectorAll('dialog[open] label')).some(e=>e.textContent.includes('Ticket board'))");
  assert.deepEqual(await evaluate("Array.from(document.querySelector('dialog[open] select').options).map(e=>e.value)"),['10','9']);
  assert.equal(await evaluate("document.querySelector('dialog[open] select').value"),'10');
  await choose('dialog[open] select','9');assert.equal(await evaluate("document.querySelector('dialog[open] select').value"),'9');
  assert.deepEqual(writes,[]);assert.deepEqual(errors,[]);
  console.log('PASS: ticket board selection, live read-only feed, cluster filtering, safe text rendering, pagination, API failure state, uncertain retry protection and responsive layout. Browser-only fixtures with all writes blocked.');
  await send('Browser.close');
 } finally {ws?.close();chrome.kill();}
})().catch(e=>{console.error(e);process.exitCode=1});
