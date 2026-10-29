import test from "node:test";
import assert from "node:assert/strict";
import { mkdtemp, mkdir, writeFile, readFile, rm } from "node:fs/promises";
import { join, resolve, sep } from "node:path";
import { randomUUID } from "node:crypto";
import { get } from "node:http";
import { startServer, parseArgs } from "../scripts/server.mjs";

async function fixture(t) {
  const root = resolve("tmp/discussion-notes-tests");
  await mkdir(root, { recursive: true });
  const dir = await mkdtemp(join(root, "case-"));
  const file = join(dir, "note.text");
  await writeFile(file, "Previous discussion stays here.\n");
  const app = await startServer({ file, port: 0 });
  t.after(async () => {
    app.server.closeAllConnections();
    await new Promise((resolve) => app.server.close(resolve));
    assert.ok(resolve(dir).startsWith(root + sep));
    await rm(dir, { recursive: true, force: true });
  });
  const bootstrap = await (await fetch(app.origin + "/api/bootstrap")).json();
  const post = (body, headers = {}) =>
    fetch(app.origin + "/api/entries", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-Notes-Token": bootstrap.token,
        ...headers,
      },
      body: JSON.stringify(body),
    });
  return { ...app, post, bootstrap };
}
const entry = (extra = {}) => ({
  id: randomUUID(),
  text: "The team will review the daily report.",
  speaker: "Discussion fixture",
  kind: "dictation",
  ...extra,
});

test("appends text and a PHT timestamp while preserving existing discussion", async (t) => {
  const app = await fixture(t);
  const result = await app.post(entry());
  assert.equal(result.status, 200);
  assert.equal((await result.json()).saved, true);
  const text = await readFile(app.file, "utf8");
  assert.ok(text.startsWith("Previous discussion stays here.\n"));
  assert.match(text, /PHT \| Dictation \| Discussion fixture/);
  assert.ok(text.includes("The team will review the daily report."));
  assert.equal(
    (await (await fetch(app.origin + "/api/note")).json()).text,
    text,
  );
});

test("concurrent requests and retry of the same ID append one section only", async (t) => {
  const app = await fixture(t);
  const body = entry();
  const results = await Promise.all([
    app.post(body),
    app.post(body),
    app.post(body),
  ]);
  assert.ok(results.every((response) => response.status === 200));
  assert.equal(
    (await readFile(app.file, "utf8")).split(body.text).length - 1,
    1,
  );
  assert.equal(
    (await app.post({ ...body, text: "Different text" })).status,
    409,
  );
});

test("different IDs with the same spoken words are preserved as separate sections", async (t) => {
  const app = await fixture(t);
  await app.post(entry());
  await app.post(entry());
  assert.equal(
    (await readFile(app.file, "utf8")).split(
      "The team will review the daily report.",
    ).length - 1,
    2,
  );
});

test("rejects missing tokens, foreign origins, cross-site requests, and rebinding hosts", async (t) => {
  const app = await fixture(t);
  const before = await readFile(app.file, "utf8");
  for (const headers of [
    { "X-Notes-Token": "" },
    { "X-Notes-Token": "é".repeat(64) },
    { Origin: "https://foreign.example" },
    { "Sec-Fetch-Site": "cross-site" },
  ])
    assert.equal(
      (await app.post(entry(), headers)).status,
      403,
      Object.keys(headers)[0],
    );
  const hostStatus = await new Promise((resolve, reject) => {
    get(
      app.origin + "/api/bootstrap",
      { headers: { Host: "foreign.example" } },
      (response) => {
        response.resume();
        resolve(response.statusCode);
      },
    ).on("error", reject);
  });
  assert.equal(hostStatus, 403, "raw HTTP Host header");
  assert.equal(await readFile(app.file, "utf8"), before);
  assert.equal(
    (
      await fetch(app.origin + "/api/bootstrap", {
        headers: { Origin: "https://foreign.example" },
      })
    ).status,
    403,
  );
});

test("validates content and does not accept browser-supplied file paths", async (t) => {
  const app = await fixture(t);
  for (const body of [
    entry({ text: "" }),
    entry({ text: "x".repeat(12001) }),
    entry({ speaker: "One\nTwo" }),
    entry({ file: "../another-file.text" }),
    entry({ kind: "command" }),
    [],
  ]) {
    assert.equal((await app.post(body)).status, 422);
  }
  assert.equal(
    (await app.post(entry(), { "Content-Type": "text/plain" })).status,
    415,
  );
  assert.equal((await fetch(app.origin + "/.env")).status, 404);
  assert.equal((await fetch(app.origin + "/scripts/server.mjs")).status, 404);
  assert.equal(
    await readFile(app.file, "utf8"),
    "Previous discussion stays here.\n",
  );
});

test("serves the app without CORS, caching, framing, or arbitrary static files", async (t) => {
  const app = await fixture(t);
  const response = await fetch(app.origin);
  assert.equal(response.status, 200);
  assert.equal(response.headers.get("cache-control"), "no-store");
  assert.equal(response.headers.get("access-control-allow-origin"), null);
  assert.match(
    response.headers.get("content-security-policy"),
    /frame-ancestors 'none'/,
  );
  assert.match(await response.text(), /Start listening/);
  assert.equal((await fetch(app.origin + "/app.js")).status, 200);
});

test("refuses nontext outputs and invalid ports before starting", () => {
  assert.throws(() => parseArgs(["--file", ".env"]), /text/);
  assert.throws(() => parseArgs(["--file", "script.js"]), /text/);
  assert.throws(() => parseArgs(["--port", "99999"]), /port/);
  assert.throws(() => parseArgs(["--unknown", "value"]), /Usage/);
  assert.equal(parseArgs(["--file", "notes.md", "--port", "8768"]).port, 8768);
});
