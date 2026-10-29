import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdir, writeFile, readFile, rm } from 'node:fs/promises';
import { resolve } from 'node:path';
import { startServer } from '../tools/discussion-notes/scripts/server.mjs';

const review = resolve('tmp/discussion-notes-browser-review');
await mkdir(review, { recursive: true });
const file = resolve(review, 'fixture.text');
await writeFile(file, 'Existing browser fixture.\n');
const app = await startServer({ file, port: 0 });
const chrome = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', [
    '--headless', '--disable-gpu', '--no-sandbox', '--remote-debugging-port=9257',
    `--user-data-dir=${review}/profile`, 'about:blank',
], { windowsHide: true, stdio: 'ignore' });
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
let ws;
try {
    let pages;
    for (let n = 0; n < 40; n++) {
        try { pages = await (await fetch('http://127.0.0.1:9257/json')).json(); break; }
        catch { await delay(200); }
    }
    assert.ok(pages, 'test Chrome starts');
    ws = new WebSocket(pages.find(page => page.type === 'page').webSocketDebuggerUrl);
    await new Promise(resolve => ws.addEventListener('open', resolve, { once: true }));
    let id = 0;
    const pending = new Map();
    const exceptions = [];
    function send(method, params = {}) {
        return new Promise(resolve => { pending.set(++id, resolve); ws.send(JSON.stringify({ id, method, params })); });
    }
    ws.addEventListener('message', event => {
        const message = JSON.parse(event.data);
        if (message.id) { pending.get(message.id)?.(message); pending.delete(message.id); }
        if (message.method === 'Runtime.exceptionThrown') exceptions.push(message.params.exceptionDetails.text);
    });
    async function evaluate(expression) {
        const message = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
        if (message.result.exceptionDetails) throw Error(JSON.stringify(message.result.exceptionDetails));
        return message.result.result.value;
    }
    async function waitFor(expression) {
        for (let n = 0; n < 100; n++) { if (await evaluate(expression)) return; await delay(70); }
        throw Error('Timed out: ' + expression);
    }
    await send('Page.enable');
    await send('Runtime.enable');
    const injected = await send('Page.addScriptToEvaluateOnNewDocument', { source: `
        window.fakeStarts = 0;
        class FakeRecognition {
            constructor() { window.fakeRecognition = this; }
            start() { window.fakeStarts++; this.onstart?.(); }
            stop() { this.onend?.(); }
            abort() { this.onend?.(); }
        }
        Object.defineProperty(window, 'SpeechRecognition', { value: FakeRecognition, configurable: true });
        Object.defineProperty(window, 'webkitSpeechRecognition', { value: undefined, configurable: true });
        const realFetch = window.fetch.bind(window);
        window.fetch = async (...args) => {
            const result = await realFetch(...args);
            if (window.loseNextSaveResponse && args[0] === '/api/entries') {
                window.loseNextSaveResponse = false;
                throw new TypeError('Simulated lost save response');
            }
            return result;
        };
    ` });
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    await send('Page.navigate', { url: app.origin });
    await waitFor("document.querySelector('#status')?.textContent === 'Ready'");
    assert.equal(await evaluate("document.querySelector('#transcript').textContent"), 'Existing browser fixture.\n');
    await evaluate("document.querySelector('#speaker').value='Fixture speaker'; document.querySelector('#start').click()");
    assert.equal(await evaluate("document.querySelector('#status').textContent"), 'Listening');
    await evaluate("window.fakeRecognition.onresult({ resultIndex: 0, results: [Object.assign([{transcript: 'Interim words'}], {isFinal: false})] })");
    assert.equal(await readFile(file, 'utf8'), 'Existing browser fixture.\n', 'interim text is not saved');
    const finalEvent = "window.fakeRecognition.onresult({ resultIndex: 0, results: [Object.assign([{transcript: 'The daily report is due tomorrow.'}], {isFinal: true})] })";
    await evaluate(finalEvent);
    await waitFor("document.querySelector('#transcript').textContent.includes('The daily report is due tomorrow.')");
    await evaluate(finalEvent);
    await delay(120);
    assert.equal((await readFile(file, 'utf8')).split('The daily report is due tomorrow.').length - 1, 1, 'repeated recognition event is not duplicated');
    await evaluate("document.querySelector('#pause').click()");
    assert.equal(await evaluate("document.querySelector('#status').textContent"), 'Paused');
    const maliciousText = '<img src=x onerror="window.fixtureXss=true"> Review the camera concern.';
    await evaluate(`document.querySelector('#manual').value=${JSON.stringify(maliciousText)}; document.querySelector('#manual').dispatchEvent(new Event('input')); document.querySelector('#add-note').click()`);
    await waitFor("document.querySelector('#transcript').textContent.includes('Review the camera concern.')");
    assert.equal(await evaluate('window.fixtureXss === true'), false, 'discussion rendered as plain text');
    await evaluate("window.loseNextSaveResponse=true; document.querySelector('#manual').value='Recoverable saved note.'; document.querySelector('#manual').dispatchEvent(new Event('input')); document.querySelector('#add-note').click()");
    await waitFor("!document.querySelector('#retry').hidden");
    assert.equal(await evaluate("document.querySelector('#pending').textContent"), 'Recoverable saved note.');
    await evaluate("document.querySelector('#retry').click()");
    await waitFor("document.querySelector('#pending-box').hidden && document.querySelector('#transcript').textContent.includes('Recoverable saved note.')");
    assert.equal((await readFile(file, 'utf8')).split('Recoverable saved note.').length - 1, 1, 'lost response retry does not duplicate disk entry');
    await evaluate("document.querySelector('#start').click(); window.fakeRecognition.onerror({error:'not-allowed'}); window.fakeRecognition.onend()");
    const starts = await evaluate('window.fakeStarts');
    await delay(1100);
    assert.equal(await evaluate('window.fakeStarts'), starts, 'permission rejection does not auto-restart microphone');
    await evaluate("document.querySelector('#copy-prompt').click()");
    await waitFor("!document.querySelector('#codex-prompt').hidden");
    const prompt = await evaluate("document.querySelector('#codex-prompt').value");
    assert.ok(prompt.includes('$discussion-notes') && prompt.includes(JSON.stringify(file)));
    const downloads = resolve(review, 'downloads');
    await mkdir(downloads, { recursive: true });
    const downloadedFile = resolve(downloads, 'fixture.text');
    await rm(downloadedFile, { force: true });
    await send('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: downloads });
    await evaluate("document.querySelector('#download').click()");
    let downloaded = false;
    for (let n = 0; n < 50; n++) {
        try { downloaded = (await readFile(downloadedFile, 'utf8')) === await readFile(file, 'utf8'); }
        catch {}
        if (downloaded) break;
        await delay(100);
    }
    assert.ok(downloaded, 'download contains saved transcript');
    for (const width of [1440, 768, 390, 320]) {
        await send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
        assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, 'responsive at ' + width);
    }
    const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
    await writeFile(resolve(review, 'mobile.png'), Buffer.from(shot.result.data, 'base64'));
    await send('Page.removeScriptToEvaluateOnNewDocument', { identifier: injected.result.identifier });
    await send('Page.addScriptToEvaluateOnNewDocument', { source: "Object.defineProperty(window,'SpeechRecognition',{value:undefined}); Object.defineProperty(window,'webkitSpeechRecognition',{value:undefined});" });
    await send('Page.navigate', { url: app.origin });
    await waitFor("document.querySelector('#status')?.textContent === 'Ready'");
    assert.equal(await evaluate("document.querySelector('#start').disabled"), true);
    assert.equal(await evaluate("document.querySelector('#support').hidden"), false);
    await evaluate("document.querySelector('#manual').value='Manual fallback works.'; document.querySelector('#manual').dispatchEvent(new Event('input')); document.querySelector('#add-note').click()");
    await waitFor("document.querySelector('#transcript').textContent.includes('Manual fallback works.')");
    assert.deepEqual(exceptions, []);
    await send('Browser.close');
    console.log('PASS: real local file saves, simulated interim/final speech, recognition-event deduplication, lost-response retry, permission denial, manual fallback, prompt export, responsive layouts and no script execution from note text. No real microphone was used.');
} finally {
    ws?.close(); chrome.kill();
    app.server.closeAllConnections();
    await new Promise(resolve => app.server.close(resolve));
}
