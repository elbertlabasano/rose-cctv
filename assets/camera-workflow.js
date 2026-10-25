/* Browser-local daily observations and service tickets; incident records remain separate. */
(() => {
  const key = 'rosewatch-camera-workflow-v1';
  const $ = id => document.getElementById(id);
  const dialog = $('cameraDayDialog');
  const dateFor = day => `2026-10-${String(day).padStart(2, '0')}`;
  const recordKey = (camera, day) => `${camera}:${dateFor(day)}`;
  let data = { records: {}, tickets: [] }, selected = null, draft = null, busy = false, generation = 0;
  const validImage = value => typeof value === 'string' && /^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$/.test(value) && value.length < 2100000;
  const imagesOf = record => record?.screenshots || (record?.screenshot ? [record.screenshot] : []);
  try {
    const saved = JSON.parse(localStorage.getItem(key) || 'null');
    if (saved) {
      if (!saved.records || !Array.isArray(saved.tickets) || !Object.values(saved.records).every(r => r && ['live','down','degraded','maintenance','nodata'].includes(r.state) && typeof r.notes === 'string' && imagesOf(r).length <= 6 && imagesOf(r).every(validImage) && Array.isArray(r.history)) || !saved.tickets.every(t => t && typeof t.id === 'string' && typeof t.title === 'string' && ['open','in-progress','closed'].includes(t.status) && cameraRows.some(c => c.id === t.camera))) throw Error('Invalid saved records');
      data = saved;
    }
  } catch { showToast('Saved daily records could not be read. Sample statuses are displayed.'); }
  const defaults = (camera, day) => ({ state: camera.days[day-1], notes: '', screenshots: [], history: [] });
  function persist(next) {
    try { localStorage.setItem(key, JSON.stringify(next)); data = next; return true; }
    catch { error('Browser storage is unavailable or full. No changes were saved.'); return false; }
  }
  function error(message) { $('cameraDayError').textContent = message; $('cameraDayError').hidden = !message; }
  function refresh() {
    for (const camera of cameraRows) {
      for (let day = 1; day <= camera.days.length; day++) {
        const record = data.records[recordKey(camera.id, day)];
        if (record) camera.days[day-1] = record.state;
      }
      camera.current = [...camera.days].reverse().find(state => state !== 'nodata') || 'nodata';
      const observedDays = camera.days.filter(state => state !== 'nodata');
      camera.uptime = observedDays.length ? `${(observedDays.filter(state => state === 'live').length / observedDays.length * 100).toFixed(1)}%` : 'No data';
    }
    renderStatusMatrix(); renderTickets(); window.dispatchEvent(new Event('rosewatch:records-changed'));
  }
  window.cameraDayIndicators = (camera, day) => {
    const record = data.records[recordKey(camera, day)];
    const ticket = record?.externalTicketUrl;
    return `${imagesOf(record).length ? `<span title="${imagesOf(record).length} images attached" aria-label="Screenshot attached">&#9635; ${imagesOf(record).length}</span>` : ''}${ticket ? '<span title="External ticket linked" aria-label="External ticket linked">&#9636;</span>' : ''}`;
  };
  function preview() {
    $('cameraScreenshotPreview').innerHTML = draft.screenshots.map((image, index) => `<div><img class="daily-screenshot" src="${image}" alt="Camera evidence ${index + 1}"><button type="button" class="text-btn" data-remove-daily-image="${index}">Remove image ${index + 1}</button></div>`).join('') || '<p class="device-description">No images attached.</p>';
    $('cameraImageProgress').textContent = busy ? 'Processing images?' : `${draft.screenshots.length} of 6 images attached`;
  }
  $('cameraScreenshotPreview').addEventListener('click', event => {
    const button = event.target.closest('[data-remove-daily-image]');
    if (button && !busy) { draft.screenshots.splice(Number(button.dataset.removeDailyImage), 1); preview(); }
  });
  function actions() { return { status: $('actionCameraStatus').checked, images: $('actionCameraImages').checked, ticket: $('actionCameraTicket').checked }; }
  function updateActions() {
    const chosen = actions();
    $('cameraStatusSection').hidden = !chosen.status;
    $('cameraImagesSection').hidden = !chosen.images;
    $('cameraTicketSection').hidden = !chosen.ticket;
    $('cameraSharedNotes').hidden = !Object.values(chosen).some(Boolean);
    $('saveCameraDay').disabled = busy || !Object.values(chosen).some(Boolean);
    $('saveCameraDay').textContent = busy ? 'Processing images?' : 'Save selected actions';
    $('captureCameraScreenshot').disabled = busy;
    $('uploadCameraImages').disabled = busy;
  }
  ['actionCameraStatus','actionCameraImages','actionCameraTicket'].forEach(id => $(id).addEventListener('change', updateActions));
  window.openCameraDay = (id, day) => {
    const camera = cameraRows.find(c => c.id === id);
    if (!camera || !Number.isInteger(day) || day < 1 || day > camera.days.length) return;
    generation++; selected = { camera, day }; busy = false;
    draft = structuredClone(data.records[recordKey(id, day)] || defaults(camera, day));
    draft.screenshots = [...imagesOf(draft)]; delete draft.screenshot;
    ['actionCameraStatus','actionCameraImages','actionCameraTicket'].forEach(id => $(id).checked = false);
    updateActions();
    $('cameraDayTitle').textContent = `${id} · ${camera.name}`;
    $('cameraDayContext').textContent = `${camera.branch} · ${dateFor(day)}`;
    $('cameraDayStatus').value = ['live','down'].includes(draft.state) ? draft.state : '';
    $('cameraDayNotes').value = draft.notes;
    $('cameraTicketTitle').value = `${camera.name} - camera support`;
    $('cameraTicketIntegrationNote').textContent = typeof window.roseWatch.createTicket === 'function' ? 'The external system will receive the selected status, notes and images when you save.' : 'Ticket integration is not connected yet. Create ticket will send this record once a provider is configured.';
    error(''); preview(); linkedTicket();
    $('cameraDayHistory').textContent = draft.history.length ? `Last update: ${draft.history.at(-1).by} · ${new Date(draft.history.at(-1).at).toLocaleString('en-PH')}` : 'No manual observations yet.';
    if (!dialog.open) dialog.showModal();
  };
  function linkedTicket() {
    const url = safeHttpUrl(draft.externalTicketUrl);
    $('cameraTicketFields').hidden = Boolean(url);
    $('cameraLinkedTicket').innerHTML = url ? `<p><a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">Open external ticket</a></p>` : '<p class="device-description">No external ticket linked.</p>';
  }
  async function commit() {
    const chosen = actions();
    if (!Object.values(chosen).some(Boolean)) { error('Select at least one action.'); return false; }
    if (!selected || busy) { error('Wait for screenshot capture to finish.'); return false; }
    const title = $('cameraTicketTitle').value.trim();
    const needsTicket = chosen.ticket && !draft.externalTicketUrl;
    if (chosen.status && !['live','down'].includes($('cameraDayStatus').value)) { error('Select Online or Offline.'); return false; }
    if (chosen.images && !draft.screenshots.length && !imagesOf(data.records[recordKey(selected.camera.id, selected.day)]).length) { error('Capture, paste, or upload at least one image.'); return false; }
    if (needsTicket && !title) { error('Enter a ticket title.'); return false; }
    if (needsTicket && typeof window.roseWatch.createTicket !== 'function') { error('Ticket integration is not connected yet. Configure the external ticket provider to create tickets here, or deselect Create ticket to save the other actions.'); return false; }
    const next = structuredClone(data), at = new Date().toISOString();
    const state = chosen.status ? $('cameraDayStatus').value : draft.state;
    const screenshots = chosen.images ? [...draft.screenshots] : [...imagesOf(data.records[recordKey(selected.camera.id, selected.day)])];
    const entry = { ...draft, state, screenshots, externalTicketUrl: draft.externalTicketUrl || '', notes: $('cameraDayNotes').value.trim(), history: [...draft.history, { at, by: $('sessionUserName').textContent, state, actions: Object.keys(chosen).filter(key => chosen[key]) }] };
    const camera = selected.camera, date = dateFor(selected.day), id = recordKey(camera.id, selected.day);
    if (needsTicket) entry.ticketRequestKey ||= crypto.randomUUID();
    next.records[id] = entry;
    if (!persist(next)) return false;
    draft = structuredClone(entry); refresh(); error('');
    if (needsTicket) {
      busy = true; updateActions();
      ['closeCameraDay','cancelCameraDay'].forEach(id => $(id).disabled = true);
      try {
        const result = await window.roseWatch.createTicket({
          idempotencyKey: entry.ticketRequestKey, cameraId: camera.id, cameraName: camera.name,
          branch: camera.branch, date, title, status: state === 'live' ? 'online' : state === 'down' ? 'offline' : state,
          notes: entry.notes, screenshots: [...screenshots], requestedBy: $('sessionUserName').textContent
        });
        if (!result || !safeHttpUrl(result.url) || !String(result.id || '').trim()) throw Error('The ticket provider did not return a valid ticket ID and URL.');
        const confirmed = { ...data.records[id], externalTicketId: String(result.id), externalTicketUrl: safeHttpUrl(result.url) };
        const updated = { ...data, records: { ...data.records, [id]: confirmed } };
        draft = structuredClone(confirmed);
        if (!persist(updated)) {
          // Retain confirmed result in this session so Retry saves its link instead of creating again.
          data = updated; refresh(); linkedTicket();
          error('External ticket was created, but its link could not be saved in this browser. Keep this dialog open and retry saving.');
          return false;
        }
        draft = structuredClone(confirmed); refresh(); linkedTicket();
      } catch (e) {
        error('Daily record saved, but external ticket creation could not be confirmed. Retry with the same request reference. ' + (e.message || 'Provider unavailable.'));
        return false;
      } finally {
        busy = false; updateActions();
        ['closeCameraDay','cancelCameraDay'].forEach(id => $(id).disabled = false);
      }
    }
    linkedTicket();
    showToast(needsTicket ? 'Daily record saved and external ticket created.' : 'Daily record saved.'); return true;
  }
  $('cameraDayForm').addEventListener('submit', async event => { event.preventDefault(); if (await commit()) dialog.close(); });
  dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
  ['closeCameraDay','cancelCameraDay'].forEach(id => $(id).addEventListener('click', () => dialog.close()));
  dialog.addEventListener('close', () => { if (!dialog.open) { generation++; selected = null; } });
  async function imageData(blob) {
    if (!(blob instanceof Blob) || !['image/png','image/jpeg','image/webp'].includes(blob.type) || blob.size > 8*1024*1024) throw Error('Choose a PNG, JPEG or WebP image up to 8 MB.');
    const bitmap = await createImageBitmap(blob);
    try {
      const scale = Math.min(1, 1440 / Math.max(bitmap.width, bitmap.height));
      const canvas = document.createElement('canvas'); canvas.width = Math.max(1, Math.round(bitmap.width*scale)); canvas.height = Math.max(1, Math.round(bitmap.height*scale));
      canvas.getContext('2d').drawImage(bitmap,0,0,canvas.width,canvas.height);
      const result = canvas.toDataURL('image/jpeg', .8);
      if (!validImage(result)) throw Error('Screenshot is too large to save. Choose a smaller image.');
      return result;
    } finally { bitmap.close(); }
  }
  async function attachDraft(blobs) {
    if (busy || !selected) return;
    if (draft.screenshots.length + blobs.length > 6) { error('A daily record can contain up to 6 images.'); return; }
    const token = generation; busy = true; updateActions(); preview();
    try {
      const results = [];
      for (const blob of blobs) results.push(await imageData(blob));
      if (token === generation && selected) { draft.screenshots.push(...results); error(''); }
    } catch (e) { if (token === generation) error(e.message); }
    finally { if (token === generation) { busy = false; updateActions(); preview(); } }
  }
  $('uploadCameraImages').addEventListener('click', () => $('cameraScreenshotFile').click());
  $('cameraScreenshotFile').addEventListener('change', event => { const files = [...event.target.files]; if (files.length) attachDraft(files); event.target.value = ''; });
  dialog.addEventListener('paste', event => {
    const files = [...(event.clipboardData?.items || [])].filter(i => i.type.startsWith('image/')).map(i => i.getAsFile()).filter(Boolean);
    if (files.length && actions().images) { event.preventDefault(); attachDraft(files); }
  });
  $('captureCameraScreenshot').addEventListener('click', async () => {
    if (busy) return;
    if (draft.screenshots.length >= 6) { error('A daily record can contain up to 6 images.'); return; }
    if (!navigator.mediaDevices?.getDisplayMedia) { error('Screen capture is unavailable. Use localhost or HTTPS, or attach/paste an image.'); return; }
    const token = generation; let stream; busy = true; updateActions(); preview();
    try {
      stream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
      const video = document.createElement('video'); video.srcObject = stream; video.muted = true; await video.play();
      if (token !== generation) return;
      const canvas = document.createElement('canvas'); canvas.width = video.videoWidth; canvas.height = video.videoHeight;
      canvas.getContext('2d').drawImage(video,0,0);
      const blob = await new Promise(resolve => canvas.toBlob(resolve,'image/jpeg',.85));
      const result = await imageData(blob);
      if (token === generation && selected) { draft.screenshots.push(result); preview(); error(''); }
    } catch(e) { if (token === generation) error(e.name === 'NotAllowedError' ? 'Capture cancelled or denied. You can attach or paste an image.' : 'Could not capture the selected window. Try attaching an image.'); }
    finally { stream?.getTracks().forEach(track => track.stop()); if (token === generation) { busy = false; updateActions(); preview(); } }
  });
  window.roseWatch = {
    getDailyRecords: () => structuredClone(data.records),
    saveDailyStatuses(updates) {
      if (!Array.isArray(updates) || !updates.length) throw Error('Select at least one camera.');
      const next = structuredClone(data), at = new Date().toISOString();
      for (const update of updates) {
        const camera = cameraRows.find(c => c.id === update.cameraId), day = Number(String(update.date).slice(8));
        if (!camera || dateFor(day) !== update.date || day < 1 || day > camera.days.length || !['live','down'].includes(update.state) || !String(update.notes || '').trim()) throw Error('Choose cameras, a valid date, status, and observation.');
        const id = recordKey(camera.id, day), previous = next.records[id] || defaults(camera, day);
        next.records[id] = { ...previous, state: update.state, notes: update.notes.trim(), observedAt: at, history: [...previous.history, { at, by: $('sessionUserName').textContent, state: update.state, actions: ['status'] }] };
      }
      if (!persist(next)) throw Error('Could not save observations. Browser storage is unavailable or full.');
      refresh(); return true;
    },
    async attachCameraScreenshot(cameraId, date, blob) {
      const camera = cameraRows.find(c => c.id === cameraId), day = Number(String(date).slice(8));
      if (!camera || dateFor(day) !== date || day < 1 || day > camera.days.length) throw Error('Unknown camera or date outside the register.');
      const screenshot = await imageData(blob), next = structuredClone(data), id = recordKey(cameraId, day);
      const record = next.records[id] || defaults(camera, day);
      const screenshots = [...imagesOf(record), screenshot];
      if (screenshots.length > 6) throw Error('A daily record can contain up to 6 images.');
      next.records[id] = { ...record, screenshots }; delete next.records[id].screenshot;
      if (!persist(next)) throw Error('Screenshot could not be saved.');
      if (selected?.camera.id === cameraId && selected.day === day) { draft.screenshots.push(screenshot); preview(); }
      refresh();
      return { cameraId, date, attached: true };
    }
  };
  function renderTickets() {
    const term = $('ticketSearch').value.trim().toLowerCase();
    const linked = Object.entries(data.records).filter(([,record]) => safeHttpUrl(record.externalTicketUrl));
    $('ticketNavCount').textContent = linked.length;
    $('ticketFilter').hidden = true;
    $('ticketList').innerHTML = linked.filter(([id, record]) => [id, record.externalTicketUrl, record.notes].join(' ').toLowerCase().includes(term)).map(([id, record]) => `<article class="ticket-entry"><strong>${escapeHtml(id)}</strong><p>${escapeHtml(record.notes)}</p><a href="${escapeHtml(safeHttpUrl(record.externalTicketUrl))}" target="_blank" rel="noopener noreferrer">Open external ticket</a><p>Status and assignment are managed in the external system.</p></article>`).join('') || '<p class="matrix-empty">No external tickets yet. Select Create ticket in a camera daily record to submit one through the integration.</p>';
  }
  ['ticketSearch','ticketFilter'].forEach(id => $(id).addEventListener('input', renderTickets));
  refresh();
})();
