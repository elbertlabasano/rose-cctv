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
  assert.equal(await evaluate('typeof window.openCameraDay'),'function',JSON.stringify(errors));
  await evaluate("localStorage.removeItem('rosewatch-camera-workflow-v1'); location.reload()");await delay(700);
  assert.equal(await evaluate("document.querySelector('[data-page=incidents]').textContent.includes('Incidents')"),true);
  assert.equal(await evaluate("document.querySelector('[data-page=tickets]').textContent.includes('Tickets')"),true);
  const clickCell = async () => {
    await evaluate("showPage('cameras')"); await delay(300);
    const point = await evaluate("showPage('cameras'); (()=>{const cell=document.querySelector('[data-camera-day=\"CAM-MKT-001\"][data-day=\"22\"]');cell.scrollIntoView({block:'center',inline:'center',behavior:'instant'});const r=cell.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2}})()");
    await send('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...point});
    await send('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,...point});
    assert.equal(await evaluate("document.getElementById('cameraDayDialog').open"),true,'Clicking a device/date cell opens the daily editor');
    assert.equal(await evaluate("document.getElementById('cameraDayContext').textContent.includes('2026-10-22')"),true);
  };
  await clickCell();
  assert.equal(await evaluate("document.getElementById('cameraStatusSection').hidden"),true);
  assert.equal(await evaluate("document.getElementById('saveCameraDay').disabled"),true);
  await evaluate("['actionCameraStatus','actionCameraImages','actionCameraTicket'].forEach(id=>document.getElementById(id).click())");
  assert.equal(await evaluate("document.getElementById('cameraImagesSection').hidden"),false);
  await evaluate("document.getElementById('actionCameraImages').click();document.getElementById('cameraTicketTitle').value='Support request';document.getElementById('cameraDayForm').requestSubmit()");
  assert.equal(await evaluate("document.getElementById('cameraDayError').textContent.includes('not connected')"),true);
  assert.equal(await evaluate("localStorage.getItem('rosewatch-camera-workflow-v1')"),null);
  await evaluate("document.getElementById('actionCameraImages').click();window.ticketCalls=[];roseWatch.createTicket=async payload=>{window.ticketCalls.push(payload);return {id:'EXT-123',url:'https://tickets.example.com/123'}}");
  // Upload two images through the file input; save status, images and ticket together.
  await evaluate(`(async()=>{const c=document.createElement('canvas');c.width=40;c.height=40;c.getContext('2d').fillRect(0,0,40,40);const blob=await new Promise(r=>c.toBlob(r,'image/png'));const dt=new DataTransfer();dt.items.add(new File([blob],'one.png',{type:'image/png'}));dt.items.add(new File([blob],'two.png',{type:'image/png'}));const input=document.getElementById('cameraScreenshotFile');input.files=dt.files;input.dispatchEvent(new Event('change'))})()`);
  for(let i=0;i<30;i++){if(await evaluate("document.getElementById('cameraImageProgress').textContent==='2 of 6 images attached'"))break;await delay(50)}
  assert.equal(await evaluate("document.querySelectorAll('#cameraScreenshotPreview img').length"),2);
  assert.equal(await evaluate("localStorage.getItem('rosewatch-camera-workflow-v1')"),null,'No early saves while drafting');
  await evaluate("document.getElementById('cameraDayStatus').value='down';document.getElementById('cameraTicketTitle').value='Camera support request';document.getElementById('cameraDayForm').requestSubmit()");
  await delay(100);
  assert.equal(await evaluate("cameraRows[0].current"),'down');
  assert.equal(await evaluate("document.getElementById('cameraDayDialog').open"),false);
  assert.equal(await evaluate('ticketCalls.length'),1);
  assert.equal(await evaluate('ticketCalls[0].screenshots.length'),2);
  assert.equal(await evaluate('ticketCalls[0].status'),'offline');
  assert.equal(await evaluate("JSON.parse(localStorage.getItem('rosewatch-camera-workflow-v1')).tickets.length"),0);
  assert.equal(await evaluate("JSON.parse(localStorage.getItem('rosewatch-camera-workflow-v1')).records['CAM-MKT-001:2026-10-22'].screenshots.length"),2);
  assert.equal(await evaluate("cameraDayIndicators('CAM-MKT-001',22).includes('External ticket linked')"),true);
  assert.equal(await evaluate("cameraDayIndicators('CAM-MKT-001',22).includes('Screenshot attached')"),true);
  await clickCell();
  await evaluate("document.getElementById('actionCameraTicket').click();document.getElementById('cameraDayStatus').value='live';document.getElementById('cameraDayForm').requestSubmit()");
  assert.equal(await evaluate("cameraRows[0].current"),'down','Unselected status action preserves state');
  assert.equal(await evaluate("JSON.parse(localStorage.getItem('rosewatch-camera-workflow-v1')).tickets.length"),0);
  await evaluate(`(async()=>{const canvas=document.createElement('canvas');canvas.width=40;canvas.height=40;const blob=await new Promise(r=>canvas.toBlob(r,'image/png'));await roseWatch.attachCameraScreenshot('CAM-MKT-001','2026-10-22',blob)})()`);
  await evaluate('location.reload()');await delay(700);
  assert.equal(await evaluate("cameraRows[0].current"),'down');
  await clickCell();
  assert.equal(await evaluate("document.querySelectorAll('#cameraScreenshotPreview img').length"),3);
  await evaluate("document.getElementById('actionCameraImages').click();document.querySelector('[data-remove-daily-image]').click();document.getElementById('cameraDayForm').requestSubmit()");
  assert.equal(await evaluate("JSON.parse(localStorage.getItem('rosewatch-camera-workflow-v1')).records['CAM-MKT-001:2026-10-22'].screenshots.length"),2);
  assert.equal(await evaluate("incidents.some(i=>i.id.startsWith('TKT-'))"),false);
  await clickCell();
  await evaluate("document.getElementById('actionCameraStatus').click();Storage.prototype.setItem=function(){throw Error('full')};document.getElementById('cameraDayStatus').value='live';document.getElementById('cameraDayForm').requestSubmit()");
  assert.equal(await evaluate("cameraRows[0].current"),'down');
  assert.equal(await evaluate("document.getElementById('cameraDayDialog').open"),true);
  assert.equal(await evaluate("document.getElementById('cameraDayError').hidden"),false);
  // Signed document links on existing incident reports persist independently.
  await evaluate("document.getElementById('cameraDayDialog').close();location.reload()");await delay(700);
  await evaluate("showPage('incidents');document.getElementById('signedIncidentDocumentUrl').value='https://documents.example.com/signed.pdf';document.getElementById('signedIncidentDocumentForm').requestSubmit()");
  assert.equal(await evaluate("incidents.find(i=>i.id===selectedIncidentId).signedDocumentUrl"),'https://documents.example.com/signed.pdf');
  assert.equal(await evaluate("document.querySelector('#signedIncidentDocumentForm a').getAttribute('rel')"),'noopener noreferrer');
  await evaluate('location.reload()');await delay(700);
  assert.equal(await evaluate("incidents.some(i=>i.signedDocumentUrl==='https://documents.example.com/signed.pdf')"),true);
  assert.equal(await evaluate("safeHttpUrl('javascript:alert(1)')"),'');
  assert.deepEqual(errors,[]);
  console.log('PASS: action selection, multi-file uploads, combined status/images/external ticket integration, unselected action isolation, provider-confirmed external creation without local tickets, programmatic image append, persistence, image removal, and storage failure rollback, and signed incident report links.');
  await send('Browser.close');
 } finally {ws?.close();chrome.kill();}
})().catch(e=>{console.error(e);process.exitCode=1});
