const {spawn}=require('node:child_process');
const assert=require('node:assert/strict');
const path=require('node:path');
const fs=require('node:fs');
const profile=path.resolve('tmp/login-browser-review');
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
  await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
  await send('Page.navigate',{url:'http://127.0.0.1:8127/login'});await delay(900);
  for(let i=0;i<40;i++){if(await evaluate("!!document.getElementById('email')"))break;await delay(100)}
  assert.equal(await evaluate("document.querySelector('h2').textContent"),'Good to see you again.');
  assert.equal(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'),true);
  await evaluate('document.fonts.ready');
  let shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync('tmp/login-desktop.png',Buffer.from(shot.result.data,'base64'));
  await evaluate("document.querySelector('[aria-label=\"Show password\"]').click()");
  assert.equal(await evaluate("document.getElementById('password').type"),'text');
  await evaluate("document.querySelector('[aria-label=\"Hide password\"]').click();document.querySelector('.signin-help button').click()");
  assert.equal(await evaluate("document.querySelector('.help-dialog').open"),true);
  await evaluate("document.querySelector('.help-dialog button').click();document.querySelector('form').requestSubmit()");
  for(let i=0;i<30;i++){if(await evaluate("!!document.getElementById('email-error')"))break;await delay(100)}
  assert.equal(await evaluate("!!document.getElementById('email-error')"),true);
  assert.equal(await evaluate("!!document.getElementById('password-error')"),true);
  assert.equal(await evaluate("document.activeElement.id"),'email');
  await send('Page.navigate',{url:'http://127.0.0.1:8127/login'});await delay(800);
  await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
  await delay(300);
  assert.equal(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'),true);
  shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync('tmp/login-mobile.png',Buffer.from(shot.result.data,'base64'));
  await send('Page.navigate',{url:'http://127.0.0.1:8127/dashboard'});await delay(600);
  assert.equal(await evaluate('location.pathname'),'/login');
  assert.deepEqual(errors,[]);
  console.log('PASS: rendered Vue login, responsive desktop/mobile widths, password visibility, help dialog, server validation, error focus, protected redirect, and no runtime exceptions.');
  await send('Browser.close');
 } finally {ws?.close();chrome.kill();}
})().catch(e=>{console.error(e);process.exitCode=1});
