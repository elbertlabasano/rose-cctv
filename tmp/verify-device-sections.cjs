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
  ws.addEventListener('message',e=>{const m=JSON.parse(e.data);if(m.id){pending.get(m.id)?.(m);pending.delete(m.id)}if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.text)});
  const send=(method,params={})=>new Promise(r=>{pending.set(++id,r);ws.send(JSON.stringify({id,method,params}))});
  const evaluate=async expression=>{const r=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(r.result.exceptionDetails)throw Error(JSON.stringify(r.result.exceptionDetails));return r.result.result.value};
  await send('Runtime.enable');await send('Page.enable');
  await send('Page.navigate',{url:'file:///'+path.resolve('index.html').replaceAll('\\','/')});await delay(900);
  await evaluate("showPage('devices');openDeviceForm()");
  assert.equal(await evaluate("document.getElementById('deviceSection-identity').open"),true);
  assert.equal(await evaluate("document.getElementById('deviceSection-connections').open"),false);
  assert.equal(await evaluate("document.getElementById('deviceSection-maintenance').open"),false);
  await evaluate("document.getElementById('deviceForm').requestSubmit()");
  assert.equal(await evaluate("document.getElementById('deviceSection-maintenance').open"),true);
  assert.equal(await evaluate("document.getElementById('deviceModal').classList.contains('open')"),true);
  await evaluate("closeDeviceForm();openDeviceForm('NVR-MKT-02')");
  assert.equal(await evaluate("document.getElementById('deviceSection-maintenance').open"),true);
  assert.equal(await evaluate("document.activeElement.id"),'deviceStatus');
  assert.equal(await evaluate("document.getElementById('deviceSection-connections').open"),false);
  await evaluate("document.querySelector('#deviceSection-connections summary').click()");
  assert.equal(await evaluate("document.getElementById('deviceSection-connections').open"),true);
  assert.equal(await evaluate("document.getElementById('recorderFields').hidden"),false);
  assert.deepEqual(errors,[]);
  console.log('PASS: accordion defaults for add/edit, invalid field reveal, status focus, section toggling, recorder fields, no runtime errors.');
  await send('Browser.close');
 } finally {ws?.close();chrome.kill();}
})().catch(e=>{console.error(e);process.exitCode=1});
