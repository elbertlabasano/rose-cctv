(() => {
  document.getElementById('incidentDetail').addEventListener('submit', event => {
    if (event.target.id !== 'signedIncidentDocumentForm') return;
    event.preventDefault();
    const field = document.getElementById('signedIncidentDocumentUrl');
    const error = document.getElementById('signedIncidentDocumentError');
    const value = field.value.trim(), url = safeHttpUrl(value);
    if (value && !url) { error.hidden = false; error.textContent = 'Use an HTTP or HTTPS link to the signed document.'; return; }
    const incident = incidents.find(item => item.id === selectedIncidentId);
    if (!incident) return;
    const next = incidents.map(item => item === incident ? { ...item, signedDocumentUrl: url, logs: [...item.logs, { at: new Date().toISOString(), by: incidentActor, type: 'Signed document link updated', notes: url ? `Signed incident report: ${url}` : 'Signed document link removed.', from: item.status, to: item.status }] } : item);
    if (!persistIncidents(next)) { error.hidden = false; error.textContent = 'Document link could not be saved. Retry when browser storage is available.'; return; }
    incidents = next;
    renderIncidents();
    showToast('Signed incident report link saved.');
  });
})();
