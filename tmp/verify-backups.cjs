const { spawn } = require('node:child_process');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const chrome = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', [
    '--headless', '--disable-gpu', '--no-sandbox', '--remote-debugging-port=9244',
    `--user-data-dir=${path.resolve('tmp/backups-browser-review')}`, 'about:blank',
], { windowsHide: true, stdio: 'ignore' });
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
(async () => {
    let ws;
    try {
        let pages;
        for (let attempt = 0; attempt < 40; attempt++) {
            try { pages = await (await fetch('http://127.0.0.1:9244/json')).json(); break; }
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
        const manifest = JSON.parse(fs.readFileSync('web/public/build/manifest.json', 'utf8'))['resources/js/app.ts'];
        const escape = (value) => value.replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;');
        let available = true;
        let attachmentsAvailable = true;
        let zipMode='success',zipCalls=0;
        const archive=Buffer.from('UEsDBBQAAAAIAFebWl0t5bQHNQAAADMAAAAKAAAAUkVBRE1FLnR4dAuuzCvJSC3JTFZIKsovL04tUkhKTM4uLVAoSS0u0VPwy1coKMosSyxJVUgty0xJzUtO1QMAUEsBAhQAFAAAAAgAV5taXS3ltAc1AAAAMwAAAAoAAAAAAAAAAAAAAIABAAAAAFJFQURNRS50eHRQSwUGAAAAAAEAAQA4AAAAXQAAAAAA','base64');
        const downloadDirectory=path.resolve('tmp/backups-browser-review/downloads');
        fs.mkdirSync(downloadDirectory,{recursive:true});
        const downloadedFile=path.join(downloadDirectory,'RoseWatch-attachments-fixture.zip');
        if(fs.existsSync(downloadedFile))fs.unlinkSync(downloadedFile);
        await send('Browser.setDownloadBehavior',{behavior:'allow',downloadPath:downloadDirectory});
        const waitFor=async(expression)=>{for(let n=0;n<80;n++){if(await evaluate(expression))return;await delay(100)}throw Error('Timed out: '+expression)};

        await send('Fetch.enable', { patterns: [{ urlPattern: 'http://127.0.0.1:8002/*', requestStage: 'Request' }] });
        ws.addEventListener('message', async (event) => {
            const message = JSON.parse(event.data);
            if (message.method !== 'Fetch.requestPaused') return;
            const { requestId, request } = message.params;
            if(request.method==='POST' && new URL(request.url).pathname==='/backups/attachments/download'){
                zipCalls++;
                assert.equal(JSON.parse(request.postData)._token,'browser-fixture-token');
                await delay(350);
                const status=zipMode==='success'?200:zipMode==='throttle'?429:zipMode==='html'?200:503;
                const body=zipMode==='success'?archive:Buffer.from(zipMode==='html'?'<html>Sign in</html>':JSON.stringify({message:'Evidence storage unavailable. No ZIP was downloaded.'}));
                const headers=[{name:'Content-Type',value:zipMode==='success'?'application/zip':zipMode==='html'?'text/html':'application/json'}];
                if(zipMode==='success')headers.push({name:'Content-Disposition',value:'attachment; filename="RoseWatch-attachments-fixture.zip"'});
                await send('Fetch.fulfillRequest',{requestId,responseCode:status,responseHeaders:headers,body:body.toString('base64')});return;
            }
            if (request.method !== 'GET') {
                writes.push(request.url);
                await send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' });
                return;
            }
            if (new URL(request.url).pathname !== '/backups') {
                await send('Fetch.continueRequest', { requestId });
                return;
            }
            const page = { component: 'Backups/Index', props: {
                auth: { user: { id: 1, name: 'UI Test', email: 'fixture@example.com', role: 'admin' } },
                flash: {}, errors: {}, csrf_token: 'browser-fixture-token',
                backups: { available, attachments_available: attachmentsAvailable, attachment_count: 12, attachment_bytes: 3145728, drive_folder_url: 'https://drive.google.com/drive/folders/test_RoseWatch_backup_folder_12345', recent_exports: [
                    {id:2,type:'attachments',filename:'RoseWatch-attachments-fixture.zip',bytes:2400000,actor:'UI Test',created_at:'2026-10-26T06:00:00Z'},
                    {id:1,type:'database',filename:'RoseWatch-database-fixture.sql',bytes:120000,actor:'UI Test',created_at:'2026-10-26T05:59:00Z'},
                ] },
            }, url: '/backups', version: null, clearHistory: false, encryptHistory: false };
            const inertia = Object.keys(request.headers).some((header) => header.toLowerCase() === 'x-inertia');
            const body = inertia ? JSON.stringify(page) : `<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/build/${manifest.css[0]}"></head><body><div id="app" data-page="${escape(JSON.stringify(page))}"></div><script type="module" src="/build/${manifest.file}"></script></body></html>`;
            await send('Fetch.fulfillRequest', { requestId, responseCode: 200,
                responseHeaders: [{ name: 'Content-Type', value: inertia ? 'application/json' : 'text/html' }, ...(inertia ? [{ name: 'X-Inertia', value: 'true' }] : [])],
                body: Buffer.from(body).toString('base64'),
            });
        });
        await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
        await send('Page.navigate', { url: 'http://127.0.0.1:8002/backups' });
        for (let attempt = 0; attempt < 40; attempt++) {
            if (await evaluate("!!document.querySelector('.backup-steps')")) break;
            await delay(150);
        }
        assert.equal(await evaluate("document.querySelectorAll('.backup-steps > li').length"), 3);
        assert.equal(await evaluate("document.querySelector('form').getAttribute('method')"), 'post');
        assert.equal(await evaluate("document.querySelector('form').getAttribute('action')"), '/backups/download');
        assert.equal(await evaluate("document.querySelector('[name=_token]').value"), 'browser-fixture-token');
        assert.equal(await evaluate("document.querySelector('form button').disabled"), false);
        assert.equal(await evaluate("document.querySelectorAll('.backup-downloads form').length"), 2);
        assert.equal(await evaluate("document.querySelector('form[action=\"/backups/attachments/download\"]').method"), 'post');
        assert.equal(await evaluate("document.querySelector('form[action=\"/backups/attachments/download\"] [name=_token]').value"), 'browser-fixture-token');
        assert.equal(await evaluate("document.querySelector('form[action=\"/backups/attachments/download\"] button').disabled"), false);
        assert.equal(await evaluate("document.querySelector('.backup-downloads').textContent.includes('12 registered attachments')"), true);
        assert.equal(await evaluate("document.querySelector('.backup-downloads').textContent.includes('3.0 MB')"), true);
        assert.equal(await evaluate("document.querySelectorAll('.backup-table tbody tr').length"), 2);
        assert.equal(await evaluate("document.querySelector('.backup-table').textContent.includes('Attachments ZIP')"), true);
        assert.equal(await evaluate("document.querySelector('.backup-table').textContent.includes('Database SQL')"), true);
        assert.equal(await evaluate("document.querySelector('.backup-scope').textContent.includes('uploads are manual')"), true);
        assert.equal(await evaluate("document.querySelector('.backup-steps a').getAttribute('target')"), '_blank');
        assert.equal(await evaluate("document.querySelector('.backup-steps a').href"), 'https://drive.google.com/drive/folders/test_RoseWatch_backup_folder_12345');
        assert.equal(await evaluate("!!document.querySelector('.rw-sidebar a[href=\"/backups\"].active')"), true);
        assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true);
        const zipButton='form[action="/backups/attachments/download"] button';
        await evaluate(`document.querySelector(${JSON.stringify(zipButton)}).click()`);
        assert.equal(await evaluate(`document.querySelector(${JSON.stringify(zipButton)}).disabled`),true,'ZIP preparation disables repeated clicks');
        assert.equal(await evaluate(`document.querySelector(${JSON.stringify(zipButton)}).textContent.includes('Preparing ZIP')`),true);
        await evaluate(`document.querySelector(${JSON.stringify(zipButton)}).click()`);
        await waitFor("!!document.querySelector('.backup-ready a[download]')");
        for(let n=0;n<60&&!fs.existsSync(downloadedFile);n++)await delay(100);
        assert.equal(fs.existsSync(downloadedFile),true,'Chrome actually saved the ZIP');
        assert.deepEqual(fs.readFileSync(downloadedFile),archive,'downloaded bytes match the ZIP');
        assert.equal(zipCalls,1,'no duplicate export while busy');
        assert.equal(await evaluate("document.querySelector('.backup-ready a').download"),'RoseWatch-attachments-fixture.zip');
        for(const mode of ['failure','throttle','html']){
            zipMode=mode;
            await waitFor(`!document.querySelector(${JSON.stringify(zipButton)}).disabled`);
            await evaluate(`document.querySelector(${JSON.stringify(zipButton)}).click()`);
            await waitFor("!!document.querySelector('.backup-download-error')");
            const message=await evaluate("document.querySelector('.backup-download-error').textContent");
            assert.equal(message.includes(mode==='failure'?'Evidence storage unavailable':mode==='throttle'?'Wait a minute':'No ZIP was received'),true,mode+' failure is visible');
            assert.equal(await evaluate("!!document.querySelector('.backup-ready')"),false,'failed export has no download link');
        }
        fs.unlinkSync(downloadedFile);
        await evaluate('document.fonts.ready');
        let shot = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync('tmp/backups-desktop.png', Buffer.from(shot.result.data, 'base64'));
        await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
        await delay(200);
        assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true);
        shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
        fs.writeFileSync('tmp/backups-mobile.png', Buffer.from(shot.result.data, 'base64'));
        available = false;
        await send('Page.navigate', { url: 'http://127.0.0.1:8002/backups' });
        await delay(600);
        assert.equal(await evaluate("document.querySelector('form button').disabled"), true);
        assert.equal(await evaluate("document.querySelector('form[action=\"/backups/attachments/download\"] button').disabled"), false, 'ZIP is available independently of SQL');
        attachmentsAvailable = false;
        await send('Page.navigate', { url: 'http://127.0.0.1:8002/backups' });await delay(600);
        assert.equal(await evaluate("document.querySelector('form[action=\"/backups/attachments/download\"] button').disabled"), true);
        assert.equal(await evaluate("document.querySelector('.backup-downloads').textContent.includes('Attachment backup is unavailable')"), true);
        assert.deepEqual(errors, []);
        assert.deepEqual(writes, []);
        console.log('PASS: compiled manual backup page, SQL and attachment ZIP workflow, SQL POST/CSRF form and verified ZIP browser download with CSRF, progress, fallback link, safe errors and rate-limit handling, manual Drive upload instructions, export history, independent unavailable states and responsive layout. Browser-only fixtures; no real export or Drive upload.');
        await send('Browser.close');
    } finally { ws?.close(); chrome.kill(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
