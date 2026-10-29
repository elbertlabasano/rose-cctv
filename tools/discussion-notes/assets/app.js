const byId = (id) => document.getElementById(id);
const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
const ui = Object.fromEntries(
  [
    "status",
    "file-path",
    "save-status",
    "start",
    "pause",
    "language",
    "speaker",
    "elapsed",
    "interim",
    "save-interim",
    "support",
    "error-box",
    "error-message",
    "retry",
    "transcript",
    "pending-box",
    "pending",
    "manual",
    "add-note",
    "download",
    "copy-prompt",
    "prompt-label",
    "codex-prompt",
    "copy-status",
  ].map((id) => [id, byId(id)]),
);
let token,
  outputFile,
  savedText = "",
  wantListening = false,
  recognizing = false,
  starting = false;
let recognition,
  restartTimer,
  startedAt = 0,
  elapsedMs = 0,
  emptyEnds = 0,
  finalized = new Set();
let queue = [],
  flushing = false,
  saveFailed = false,
  interimText = "";

function status(text, tone = "") {
  ui.status.textContent = text;
  ui.status.className = `status ${tone}`;
}
function error(message, retry = false) {
  ui["error-message"].textContent = message;
  ui["error-box"].hidden = !message;
  ui.retry.hidden = !retry;
}
function buttons() {
  ui.start.disabled =
    !token ||
    !Recognition ||
    wantListening ||
    recognizing ||
    starting ||
    queue.length > 0;
  ui.pause.disabled = !wantListening && !recognizing && !starting;
  ui.language.disabled = wantListening || recognizing || starting;
  ui["add-note"].disabled = !token || !ui.manual.value.trim();
  ui["save-interim"].hidden =
    !interimText.trim() || wantListening || recognizing || starting;
}
function pending() {
  ui["pending-box"].hidden = queue.length === 0;
  ui.pending.textContent = queue.map((item) => item.text).join("\n\n");
  ui["save-status"].textContent = queue.length
    ? `${queue.length} section${queue.length === 1 ? "" : "s"} waiting to save`
    : "Saved locally";
  buttons();
}
function showSaved(text) {
  const atBottom =
    ui.transcript.scrollHeight -
      ui.transcript.scrollTop -
      ui.transcript.clientHeight <
    50;
  savedText = text;
  ui.transcript.textContent = text.trim()
    ? text
    : "No notes yet. Start listening or add a note.";
  if (atBottom) ui.transcript.scrollTop = ui.transcript.scrollHeight;
}
async function request(path, options) {
  const response = await fetch(path, { cache: "no-store", ...options });
  const result = await response.json();
  if (!response.ok)
    throw new Error(result.error || "The note could not be saved.");
  return result;
}
async function refresh() {
  const result = await request("/api/note");
  showSaved(result.text);
}
async function flush() {
  if (flushing || !token || !queue.length) return;
  flushing = true;
  ui.retry.disabled = true;
  try {
    while (queue.length) {
      await request("/api/entries", {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-Notes-Token": token },
        body: JSON.stringify(queue[0]),
        signal: AbortSignal.timeout(10000),
      });
      queue.shift();
      pending();
    }
    saveFailed = false;
    error("");
    await refresh();
  } catch (cause) {
    saveFailed = queue.length > 0;
    pause();
    status("Save needs attention", "warning");
    error(
      `${cause.message} ${queue.length ? "Your unsaved words are shown below. Keep this page open and retry." : "The save was confirmed, but the preview could not refresh. You can download the file after reconnecting."}`,
      queue.length > 0,
    );
  } finally {
    flushing = false;
    ui.retry.disabled = false;
    pending();
  }
}
function enqueue(text, kind = "note") {
  if (!text.trim()) return;
  queue.push({
    id: crypto.randomUUID(),
    text: text.trim(),
    kind,
    speaker: ui.speaker.value.trim(),
  });
  pending();
  flush();
}
function clockStop() {
  if (startedAt) {
    elapsedMs += Date.now() - startedAt;
    startedAt = 0;
  }
}
function pause() {
  wantListening = false;
  clearTimeout(restartTimer);
  clockStop();
  if (recognizing || starting) {
    status("Finishing phrase…");
    try {
      recognition.stop();
    } catch {
      recognizing = false;
      starting = false;
    }
  } else status("Paused");
  buttons();
}
function begin() {
  if (!wantListening) return;
  finalized = new Set();
  starting = true;
  status("Requesting microphone…");
  buttons();
  try {
    recognition.start();
  } catch {
    wantListening = false;
    starting = false;
    clockStop();
    status("Could not start", "warning");
    error(
      "The browser could not start dictation. Check microphone access or use Add a note.",
    );
    buttons();
  }
}
if (Recognition) {
  recognition = new Recognition();
  recognition.continuous = true;
  recognition.interimResults = true;
  recognition.onstart = () => {
    starting = false;
    recognizing = true;
    if (!wantListening) {
      recognition.stop();
      return;
    }
    if (!startedAt) startedAt = Date.now();
    status("Listening", "listening");
    buttons();
  };
  recognition.onresult = (event) => {
    let words = [];
    for (let i = event.resultIndex; i < event.results.length; i++) {
      const result = event.results[i];
      const text = result[0].transcript.trim();
      if (result.isFinal) {
        if (!finalized.has(i) && text) {
          finalized.add(i);
          emptyEnds = 0;
          enqueue(text, "dictation");
        }
      } else words.push(text);
    }
    interimText = words.join(" ");
    ui.interim.textContent = interimText || "Listening for the next phrase…";
    buttons();
  };
  recognition.onerror = (event) => {
    if (event.error === "no-speech") return;
    if (event.error === "aborted" && !wantListening) return;
    wantListening = false;
    clearTimeout(restartTimer);
    clockStop();
    const messages = {
      "not-allowed":
        "Microphone access was denied. Allow it in your browser’s site settings, then press Start listening.",
      "service-not-allowed":
        "The browser’s recognition service is unavailable. Try a supported browser or paste text into Add a note.",
      "audio-capture":
        "No working microphone was found. Check your microphone connection and browser settings.",
      network:
        "The browser’s recognition service could not connect. Check internet access or use Add a note.",
      "language-not-supported":
        "This spoken language is not supported by the browser’s service. Try English (US) or manual notes.",
    };
    status("Microphone paused", "warning");
    error(
      messages[event.error] ||
        "Dictation stopped. Check your browser and microphone, then try again.",
    );
    buttons();
  };
  recognition.onend = () => {
    recognizing = false;
    starting = false;
    if (wantListening && !saveFailed) {
      emptyEnds = finalized.size ? 0 : emptyEnds + 1;
      if (emptyEnds >= 3) {
        wantListening = false;
        clockStop();
        status("Paused after silence");
        error(
          "No speech was confirmed after several attempts. Press Start listening when you are ready, or use Add a note.",
        );
      } else {
        status("Reconnecting microphone…");
        restartTimer = setTimeout(begin, 800);
      }
    } else if (!saveFailed && ui["error-box"].hidden) status("Paused");
    if (interimText)
      ui.interim.textContent = `${interimText} (unfinished phrase, not saved)`;
    buttons();
  };
} else {
  ui.support.hidden = false;
  ui.support.textContent =
    "Live dictation is unavailable in this browser. Try Google Chrome, or type/paste into Add a note below.";
}

ui.start.addEventListener("click", () => {
  if (!Recognition || !token || recognizing || starting || queue.length) return;
  if (interimText) {
    ui.manual.value = [ui.manual.value, interimText].filter(Boolean).join("\n");
    interimText = "";
  }
  error("");
  emptyEnds = 0;
  saveFailed = false;
  recognition.lang = ui.language.value;
  wantListening = true;
  begin();
});
ui.pause.addEventListener("click", pause);
ui.manual.addEventListener("input", buttons);
ui["add-note"].addEventListener("click", () => {
  const text = ui.manual.value;
  if (!text.trim()) return;
  enqueue(text);
  ui.manual.value = "";
  buttons();
});
ui["save-interim"].addEventListener("click", () => {
  enqueue(interimText, "dictation");
  interimText = "";
  ui.interim.textContent = "Phrase queued for saving.";
  buttons();
});
ui.retry.addEventListener("click", flush);
ui.download.addEventListener("click", async () => {
  try {
    await refresh();
    const url = URL.createObjectURL(
      new Blob([savedText], { type: "text/plain;charset=utf-8" }),
    );
    const link = document.createElement("a");
    link.href = url;
    link.download = outputFile.split(/[\\/]/).at(-1);
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 30000);
  } catch {
    error(
      "The saved file could not be loaded. Keep this page open and reconnect to the local app.",
    );
  }
});
ui["copy-prompt"].addEventListener("click", async () => {
  const prompt = `$discussion-notes Summarize the discussion in ${JSON.stringify(outputFile)} into key points, decisions, action items, and open questions. Preserve the raw transcript.`;
  ui["codex-prompt"].value = prompt;
  ui["codex-prompt"].hidden = false;
  ui["prompt-label"].hidden = false;
  try {
    await navigator.clipboard.writeText(prompt);
    ui["copy-status"].textContent = "Copied. Paste this into your Codex chat.";
  } catch {
    ui["codex-prompt"].focus();
    ui["codex-prompt"].select();
    ui["copy-status"].textContent =
      "Copy the selected prompt, then paste it into Codex.";
  }
});
setInterval(() => {
  const seconds = Math.floor(
    (elapsedMs + (startedAt ? Date.now() - startedAt : 0)) / 1000,
  );
  ui.elapsed.textContent = `${String(Math.floor(seconds / 60)).padStart(2, "0")}:${String(seconds % 60).padStart(2, "0")}`;
}, 1000);
setInterval(() => {
  if (token && !queue.length && !flushing) refresh().catch(() => {});
}, 5000);
window.addEventListener("beforeunload", (event) => {
  if (
    wantListening ||
    queue.length ||
    ui.manual.value.trim() ||
    interimText.trim()
  ) {
    event.preventDefault();
    event.returnValue = "";
  }
});
window.addEventListener("pagehide", () => {
  wantListening = false;
  clearTimeout(restartTimer);
  try {
    recognition?.abort();
  } catch {}
});

request("/api/bootstrap")
  .then((data) => {
    token = data.token;
    outputFile = data.file;
    ui["file-path"].textContent = outputFile;
    showSaved(data.text);
    status("Ready");
    pending();
    ui.download.disabled = false;
    ui["copy-prompt"].disabled = false;
  })
  .catch(() => {
    status("App unavailable", "warning");
    error(
      "Could not connect to the local notes app. Restart it with the Discussion Notes skill, then reload.",
    );
    ui["save-status"].textContent = "Not connected";
  });
