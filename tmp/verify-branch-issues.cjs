const {spawn}=require('node:child_process');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const profile=path.resolve('tmp/branch-issues-browser-review');
const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--disable-gpu','--no-sandbox','--remote-debugging-port=9258',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
(async()=>{
let ws;
try {
 let pages;
 for(let n=0;n<40;n++){try{pages=await(await fetch('http://127.0.0.1:9258/json')).json();break}catch{await delay(200)}}
 assert(pages);ws=new WebSocket(pages.find(p=>p.type==='page').webSocketDebuggerUrl);
 await new Promise(resolve=>ws.addEventListener('open',resolve,{once:true}));
 let id=0;const pending=new Map(),errors=[],writes=[],generated=[];
 ws.addEventListener('message',event=>{const m=JSON.parse(event.data);if(m.id){pending.get(m.id)?.(m);pending.delete(m.id)}if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.text)});
 const send=(method,params={})=>new Promise(resolve=>{pending.set(++id,resolve);ws.send(JSON.stringify({id,method,params}))});
 const evaluate=async expression=>{const m=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(m.result.exceptionDetails)throw Error(JSON.stringify(m.result.exceptionDetails));return m.result.result.value};
 const waitFor=async expression=>{for(let n=0;n<100;n++){if(await evaluate(expression))return;await delay(80)}throw Error('Timed out: '+expression+' '+JSON.stringify({errors,body:await evaluate('document.body.innerText.slice(0,500)')}))};
 await send('Runtime.enable');await send('Page.enable');
 const manifest=JSON.parse(fs.readFileSync('web/public/build/manifest.json','utf8'))['resources/js/app.ts'];
 const escape=s=>s.replaceAll('&','&amp;').replaceAll('"','&quot;').replaceAll('<','&lt;');
 const clusters=[{id:1,name:'North Cluster',code:'N'},{id:2,name:'South Cluster',code:'S'}];
 const branches=[{id:1,name:'North Branch',cluster_id:1},{id:2,name:'South Branch',cluster_id:2}];
 const rows=branches.map((b,index)=>({branch_id:b.id,branch:b.name,cluster:clusters[index].name,device_id:b.id,asset_id:'CAM-'+b.id,device:'Branch IP Camera',status:index?'online':'offline',status_date:'2026-10-28',days_offline:index?null:4,remarks:index?'Image unclear; technician reviewing':'No visual. Replacement requested.',reference:index?'IR-FIXTURE':null,incident_id:index?1:null,issue_state:index?'in_progress':'camera_status',concern_on:'2026-10-26',resolved_on:null}));
 await send('Fetch.enable',{patterns:[{urlPattern:'http://127.0.0.1:8002/*',requestStage:'Request'}]});
 ws.addEventListener('message',async event=>{
  const m=JSON.parse(event.data);if(m.method!=='Fetch.requestPaused')return;
  const {requestId,request}=m.params;const url=new URL(request.url);
  if(request.method!=='GET'){writes.push(request.url);await send('Fetch.failRequest',{requestId,errorReason:'BlockedByClient'});return;}
  if(!['/reports','/reports/issues'].includes(url.pathname)){await send('Fetch.continueRequest',{requestId});return;}
  let props,component;
  if(url.pathname==='/reports'){
    const workspace=structuredClone(require('./fixtures/workspace.cjs'));workspace.today=workspace.date='2026-10-29';workspace.clusters=clusters;workspace.branches=branches;
    props={section:'reports',workspace};component='Workspace';
  }else{
    generated.push(url.search);
    const cluster=url.searchParams.get('cluster_id')||'all',branch=url.searchParams.get('branch_id')||'all';
    const filtered=rows.filter(row=>(cluster==='all'||row.branch_id===Number(cluster))&&(branch==='all'||row.branch_id===Number(branch)));
    props={report:{date:url.searchParams.get('date')||'2026-10-29',today:'2026-10-29',generated_at:'2026-10-29 12:00 PHT',filters:{cluster_id:cluster,branch_id:branch,include_resolved:url.searchParams.get('include_resolved')==='1'},branches,clusters,rows:filtered,summary:{branches:filtered.length,devices:filtered.length,issues:filtered.length}}};component='Reports/Issues';
  }
  const page={component,props:{...props,auth:{user:{id:1,name:'Fixture reviewer',role:'admin'}},flash:{},errors:{}},url:url.pathname+url.search,version:null,clearHistory:false,encryptHistory:false};
  const inertia=Object.keys(request.headers).some(key=>key.toLowerCase()==='x-inertia');
  const body=inertia?JSON.stringify(page):`<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/build/${manifest.css[0]}"></head><body><div id="app" data-page="${escape(JSON.stringify(page))}"></div><script type="module" src="/build/${manifest.file}"></script></body></html>`;
  await send('Fetch.fulfillRequest',{requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:inertia?'application/json':'text/html'},...(inertia?[{name:'X-Inertia',value:'true'}]:[])],body:Buffer.from(body).toString('base64')});
 });
 await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
 await send('Page.navigate',{url:'http://127.0.0.1:8002/reports'});
 await waitFor("Array.from(document.querySelectorAll('a')).some(a=>a.textContent.trim()==='Branch Issues Report')");
 await evaluate("Array.from(document.querySelectorAll('a')).find(a=>a.textContent.trim()==='Branch Issues Report').click()");
 await waitFor("!!document.querySelector('.issue-report')");
 assert.equal(await evaluate("document.querySelectorAll('.issue-table tbody tr').length"),2);
 assert.equal(await evaluate("document.querySelector('.rw-nav-link.active').textContent.includes('Reports')"),true);
 assert.equal(await evaluate("document.querySelector('.rw-nav-group-button.active')?.textContent.includes('Management') || document.querySelector('#rw-menu-management').style.display!=='none'"),true);
 assert.equal(await evaluate("getComputedStyle(document.querySelector('.issue-remarks p')).fontSize"),'13px');
 await evaluate("const selects=document.querySelectorAll('.issue-filters select'); selects[1].value='2'; selects[1].dispatchEvent(new Event('change',{bubbles:true})); selects[0].value='1'; selects[0].dispatchEvent(new Event('change',{bubbles:true}));");
 await waitFor("document.querySelectorAll('.issue-filters select')[1].value==='all'");
 assert.equal(await evaluate("new URL(document.querySelector('a[href*=\"format=csv\"]').href).searchParams.get('cluster_id')"),'all','export uses applied filters');
 await evaluate("document.querySelector('.issue-checkbox input').click(); document.querySelector('.issue-filters button').click()");
 await waitFor("document.querySelectorAll('.issue-table tbody tr').length===1");
 assert(generated.some(query=>query.includes('cluster_id=1')&&query.includes('include_resolved=1')));
 assert.equal(await evaluate("new URL(document.querySelector('a[href*=\"format=csv\"]').href).searchParams.get('cluster_id')"),'1');
 assert.equal(await evaluate("document.querySelector('a[href*=\"format=html\"]').target"),'_blank');
 for(const width of [1440,768,390,320]){
  await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});await delay(100);
  assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'layout width '+width);
 }
 const shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});fs.writeFileSync(path.join(profile,'mobile.png'),Buffer.from(shot.result.data,'base64'));
 assert.deepEqual(writes,[]);assert.deepEqual(errors,[]);
 console.log('PASS: report entry/navigation, issue rows, cluster/branch cascade, Generate request, applied-scope CSV/print links, 13px paragraphs and layouts 320-1440px. Browser data is synthetic and all writes are blocked.');
 await send('Browser.close');
}finally{ws?.close();chrome.kill()}
})().catch(error=>{console.error(error);process.exitCode=1});
