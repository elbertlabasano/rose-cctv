const { spawn } = require('node:child_process');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const chrome = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', [
    '--headless', '--disable-gpu', '--no-sandbox', '--remote-debugging-port=9251',
    `--user-data-dir=${path.resolve('tmp/home-browser-review')}`, 'about:blank',
], { windowsHide: true, stdio: 'ignore' });
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
(async () => {
    let ws;
    try {
        let pages;
        for (let attempt = 0; attempt < 40; attempt++) {
            try { pages = await (await fetch('http://127.0.0.1:9251/json')).json(); break; }
            catch { await delay(200); }
        }
        assert(pages, 'Chrome started');
        ws = new WebSocket(pages.find((page) => page.type === 'page').webSocketDebuggerUrl);
        await new Promise((resolve) => ws.addEventListener('open', resolve, { once: true }));
        let id = 0;
        const pending = new Map();
        const errors = [];
        const writes = [];
        const send = (method, params = {}) => new Promise((resolve) => {
            pending.set(++id, resolve);
            ws.send(JSON.stringify({ id, method, params }));
        });
        ws.addEventListener('message', (event) => {
            const message = JSON.parse(event.data);
            if (message.id) { pending.get(message.id)?.(message); pending.delete(message.id); }
            if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails);
        });
        const evaluate = async (expression) => {
            const message = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
            if (message.result.exceptionDetails) throw Error(JSON.stringify(message.result.exceptionDetails));
            return message.result.result.value;
        };
        await send('Runtime.enable');
        await send('Page.enable');

        const manifest=JSON.parse(fs.readFileSync('web/public/build/manifest.json','utf8'))['resources/js/app.ts'];
        const escape=value=>value.replaceAll('&','&amp;').replaceAll('"','&quot;').replaceAll('<','&lt;');
        let signedInFixture=false;
        const waitFor=async expression=>{for(let n=0;n<80;n++){if(await evaluate(expression))return;await delay(100)}throw Error('Timed out: '+expression)};
        await send('Fetch.enable',{patterns:[{urlPattern:'http://127.0.0.1:8002/*',requestStage:'Request'}]});
        ws.addEventListener('message',async event=>{
            const message=JSON.parse(event.data);if(message.method!=='Fetch.requestPaused')return;
            const {requestId,request}=message.params;
            if(request.method!=='GET'){writes.push(request.url);await send('Fetch.failRequest',{requestId,errorReason:'BlockedByClient'});return;}
            if(!signedInFixture||new URL(request.url).pathname!=='/'){await send('Fetch.continueRequest',{requestId});return;}
            const page={component:'Home',props:{auth:{user:{id:1,name:'Browser fixture',email:'fixture@example.com',role:'viewer'}},flash:{},errors:{},can_open_workspace:true,year:2026},url:'/',version:null,clearHistory:false,encryptHistory:false};
            const inertia=Object.keys(request.headers).some(h=>h.toLowerCase()==='x-inertia');
            const body=inertia?JSON.stringify(page):`<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/build/${manifest.css[0]}"></head><body><div id="app" data-page="${escape(JSON.stringify(page))}"></div><script type="module" src="/build/${manifest.file}"></script></body></html>`;
            await send('Fetch.fulfillRequest',{requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:inertia?'application/json':'text/html'},...(inertia?[{name:'X-Inertia',value:'true'}]:[])],body:Buffer.from(body).toString('base64')});
        });
        const navigate=async()=>{await send('Page.navigate',{url:'http://127.0.0.1:8002/'});await waitFor("!!document.querySelector('#home-title')");await evaluate('document.fonts.ready');};
        await send('Emulation.setDeviceMetricsOverride',{width:1440,height:900,deviceScaleFactor:1,mobile:false});
        await navigate();
        assert.equal(await evaluate("document.querySelector('#home-title').textContent"),'A little care.For everybranch.');
        assert.equal(await evaluate("document.querySelector('.home-hero .home-button').getAttribute('href')"),'/login');
        assert.equal(await evaluate("!!document.querySelector('.rw-sidebar')"),false,'homepage has its own public layout');
        await waitFor("Array.from(document.querySelectorAll('.home-page img')).every(e=>e.complete&&e.naturalWidth>0)");
        assert.equal(await evaluate("getComputedStyle(document.querySelector('.home-hero-copy > p')).fontSize"),'13px','paragraph font preference preserved');
        assert.equal(await evaluate("document.querySelector('.home-hero .home-button').getBoundingClientRect().bottom<innerHeight"),true,'entry visible above fold');
        assert.equal(await evaluate("document.querySelectorAll('.home-capability').length"),4);
        assert.equal(await evaluate("document.querySelectorAll('.home-routine-steps li').length"),3);
        for(const feature of ['monitoring','evidence','incidents','tickets']){
            await evaluate(`document.querySelector('#feature-tab-${feature}').click()`);
            await waitFor(`document.querySelector('#feature-tab-${feature}').getAttribute('aria-selected')==='true'`);
            assert.equal(await evaluate(`document.querySelector('#feature-panel-${feature}').hidden`),false);
            assert.equal(await evaluate("document.querySelectorAll('.home-feature-panel:not([hidden])').length"),1);
            assert.equal(await evaluate(`document.querySelector('#feature-panel-${feature}').querySelectorAll('li').length`),3);
        }
        await evaluate("document.querySelector('#feature-tab-tickets').focus()");
        await send('Input.dispatchKeyEvent',{type:'keyDown',key:'ArrowDown',code:'ArrowDown'});
        await waitFor("document.activeElement.id==='feature-tab-monitoring'");
        assert.equal(await evaluate("document.querySelector('#feature-tab-monitoring').getAttribute('aria-selected')"),'true','arrow key wraps tabs');
        await send('Input.dispatchKeyEvent',{type:'keyDown',key:'End',code:'End'});
        await waitFor("document.activeElement.id==='feature-tab-tickets'");
        await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Home',code:'Home'});
        await waitFor("document.activeElement.id==='feature-tab-monitoring'");
        await evaluate('scrollTo(0,0)');
        let shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync('tmp/home-browser-review/review-desktop.png',Buffer.from(shot.result.data,'base64'));
        shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});fs.writeFileSync('tmp/home-browser-review/review-full.png',Buffer.from(shot.result.data,'base64'));
        await evaluate("document.querySelector('.home-text-link').click()");await delay(100);
        assert.equal(await evaluate('location.hash'),'#routine','routine anchor works');
        for(const [width,height] of [[1440,900],[1024,768],[768,1024],[390,844],[320,740]]){
            await send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<768});await delay(100);
            assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'guest width '+width);
        }
        await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});await evaluate('scrollTo(0,0)');await delay(120);
        shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});fs.writeFileSync('tmp/home-browser-review/review-mobile.png',Buffer.from(shot.result.data,'base64'));
        await evaluate("document.querySelector('.home-header .home-button').click()");await waitFor("!!document.querySelector('#email')");
        assert.equal(await evaluate('location.pathname'),'/login','login entry works');
        assert.equal(await evaluate("document.querySelector('.auth-brand').getAttribute('href')"),'/', 'login logo returns home');
        await evaluate("document.querySelector('.auth-brand').click()");await waitFor("!!document.querySelector('#home-title')");
        signedInFixture=true;await navigate();
        assert.equal(await evaluate("document.querySelector('.home-hero .home-button').getAttribute('href')"),'/dashboard');
        assert.equal(await evaluate("document.querySelector('.home-hero .home-button').textContent.includes('Open workspace')"),true);
        assert.equal(await evaluate("document.querySelector('.home-routine-entry a').getAttribute('href')"),'/checklist');
        for(const width of [1440,768,390,320]){
            await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});await delay(100);
            assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'signed-in width '+width);
        }
        await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
        assert.equal(await evaluate("getComputedStyle(document.querySelector('.home-button')).transitionDuration"),'0s');
        assert.deepEqual(writes,[]);assert.deepEqual(errors,[]);
        console.log('PASS: live public homepage and login navigation, images, anchors, all four feature tabs, keyboard wrapping/Home/End, 13px paragraphs, hero entry, guest/authenticated layouts at 320-1440px, and reduced motion. Authenticated UI uses fixtures; all writes blocked.');
        await send('Browser.close');
    }finally{ws?.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1});
