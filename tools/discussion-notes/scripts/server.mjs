import { createServer } from "node:http";
import { readFile, appendFile, lstat, mkdir, stat } from "node:fs/promises";
import { randomBytes, createHash, timingSafeEqual } from "node:crypto";
import { resolve, dirname, extname } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const assets = fileURLToPath(new URL("../assets/", import.meta.url));
const MAX_FILE = 10 * 1024 * 1024;
const MAX_BODY = 64 * 1024;
const allowedFields = new Set(["id", "text", "speaker", "kind"]);
const staticFiles = new Map([
  ["/", ["index.html", "text/html; charset=utf-8"]],
  ["/app.js", ["app.js", "text/javascript; charset=utf-8"]],
  ["/app.css", ["app.css", "text/css; charset=utf-8"]],
]);

export function parseArgs(args) {
  const options = { file: resolve("note.text"), port: 8767 };
  for (let i = 0; i < args.length; i++) {
    const arg = args[i];
    if (!["--file", "--port"].includes(arg) || !args[i + 1]) {
      throw new Error(
        "Usage: node server.mjs [--file note.text] [--port 8767]",
      );
    }
    if (arg === "--file") options.file = resolve(args[++i]);
    else options.port = Number(args[++i]);
  }
  if (
    !Number.isInteger(options.port) ||
    options.port < 0 ||
    options.port > 65535
  ) {
    throw new Error("Choose a port from 0 to 65535.");
  }
  if (![".text", ".txt", ".md"].includes(extname(options.file).toLowerCase())) {
    throw new Error("Choose a .text, .txt, or .md transcript file.");
  }
  return options;
}

function failure(status, message) {
  return Object.assign(new Error(message), { status });
}

async function checkFile(file) {
  try {
    const info = await lstat(file);
    if (info.isSymbolicLink() || !info.isFile())
      throw failure(
        400,
        "The transcript must be a regular file, not a link or directory.",
      );
    if (info.size > MAX_FILE)
      throw failure(413, "This transcript exceeds 10 MB. Start a new file.");
  } catch (error) {
    if (error.code !== "ENOENT") throw error;
  }
}

async function readText(file) {
  await checkFile(file);
  return readFile(file, "utf8");
}

function timestamp() {
  return (
    new Intl.DateTimeFormat("en-CA", {
      timeZone: "Asia/Manila",
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hourCycle: "h23",
    }).format(new Date()) + " PHT"
  );
}

async function jsonBody(req) {
  if (!/^application\/json(?:;|$)/i.test(req.headers["content-type"] || "")) {
    throw failure(415, "Send JSON text.");
  }
  let bytes = 0;
  const chunks = [];
  for await (const chunk of req) {
    bytes += chunk.length;
    if (bytes > MAX_BODY)
      throw failure(413, "This note is too large. Send a shorter section.");
    chunks.push(chunk);
  }
  try {
    return JSON.parse(Buffer.concat(chunks).toString("utf8"));
  } catch {
    throw failure(400, "The note is not valid JSON.");
  }
}

export async function startServer({ file, port }) {
  file = resolve(file);
  if (![".text", ".txt", ".md"].includes(extname(file).toLowerCase()))
    throw new Error("Choose a text transcript file.");
  await mkdir(dirname(file), { recursive: true });
  await checkFile(file);
  await appendFile(file, "", { encoding: "utf8", mode: 0o600 });
  const token = randomBytes(32).toString("hex");
  const saved = new Map();
  let writes = Promise.resolve();
  let origin;

  const server = createServer(async (req, res) => {
    const headers = {
      "Cache-Control": "no-store",
      "X-Content-Type-Options": "nosniff",
      "Referrer-Policy": "no-referrer",
      "Content-Security-Policy":
        "default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
      "Permissions-Policy": "microphone=(self), camera=(), geolocation=()",
    };
    const json = (status, data) => {
      res.writeHead(status, {
        ...headers,
        "Content-Type": "application/json; charset=utf-8",
      });
      res.end(JSON.stringify(data));
    };
    try {
      if (
        req.headers.host !== new URL(origin).host ||
        (req.headers.origin && req.headers.origin !== origin) ||
        ["cross-site", "same-site"].includes(req.headers["sec-fetch-site"])
      ) {
        throw failure(403, "Open this app using its own 127.0.0.1 address.");
      }
      const url = new URL(req.url, origin);
      if (url.pathname === "/api/health" && req.method === "GET") {
        return json(200, { app: "discussion-notes", file });
      }
      if (url.pathname === "/api/bootstrap" && req.method === "GET") {
        return json(200, {
          app: "discussion-notes",
          file,
          token,
          text: await readText(file),
        });
      }
      if (url.pathname === "/api/note" && req.method === "GET") {
        return json(200, { text: await readText(file) });
      }
      if (url.pathname === "/api/entries" && req.method === "POST") {
        const supplied = req.headers["x-notes-token"] || "";
        if (
          typeof supplied !== "string" ||
          !/^[a-f0-9]{64}$/.test(supplied) ||
          !timingSafeEqual(Buffer.from(supplied), Buffer.from(token))
        ) {
          throw failure(
            403,
            "The app session changed. Copy unsaved words before reloading.",
          );
        }
        const body = await jsonBody(req);
        if (
          !body ||
          typeof body !== "object" ||
          Array.isArray(body) ||
          Object.keys(body).some((key) => !allowedFields.has(key)) ||
          typeof body.id !== "string" ||
          !/^[a-zA-Z0-9-]{16,80}$/.test(body.id) ||
          typeof body.text !== "string" ||
          !body.text.trim() ||
          body.text.length > 12000 ||
          (body.speaker != null &&
            (typeof body.speaker !== "string" ||
              body.speaker.length > 80 ||
              /[\r\n\x00-\x1f]/.test(body.speaker))) ||
          !["dictation", "note"].includes(body.kind)
        ) {
          throw failure(
            422,
            "Provide a short nonempty note and an optional speaker label.",
          );
        }
        const text = body.text
          .replace(/\r\n?/g, "\n")
          .replace(/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/g, "")
          .trim();
        if (!text) throw failure(422, "The note is empty.");
        const speaker = body.speaker?.trim() || "";
        const digest = createHash("sha256")
          .update(JSON.stringify([text, speaker, body.kind]))
          .digest("hex");
        const write = writes.then(async () => {
          const prior = saved.get(body.id);
          if (prior) {
            if (prior.digest !== digest)
              throw failure(
                409,
                "This note ID has already been used for different text.",
              );
            return { ...prior.result, duplicate: true };
          }
          await checkFile(file);
          const label = [
            timestamp(),
            body.kind === "note" ? "Note" : "Dictation",
            speaker,
          ]
            .filter(Boolean)
            .join(" | ");
          const block = `\n[${label}]\n${text}\n`;
          if ((await stat(file)).size + Buffer.byteLength(block) > MAX_FILE)
            throw failure(413, "This transcript is full. Start a new file.");
          await appendFile(file, block, "utf8");
          const result = { saved: true, id: body.id, block };
          saved.set(body.id, { digest, result });
          return result;
        });
        writes = write.catch(() => {});
        return json(200, await write);
      }
      if (req.method === "GET" && staticFiles.has(url.pathname)) {
        const [name, mime] = staticFiles.get(url.pathname);
        res.writeHead(200, { ...headers, "Content-Type": mime });
        return res.end(await readFile(resolve(assets, name)));
      }
      throw failure(404, "Not found.");
    } catch (error) {
      if (!res.headersSent)
        json(error.status || 500, {
          error: error.status
            ? error.message
            : "The note could not be saved. Keep this page open and retry.",
        });
      else res.end();
    }
  });
  server.requestTimeout = 10000;
  server.headersTimeout = 10000;
  await new Promise((resolve, reject) => {
    server.once("error", reject);
    server.listen(port, "127.0.0.1", resolve);
  });
  origin = `http://127.0.0.1:${server.address().port}`;
  return { server, origin, file };
}

if (
  process.argv[1] &&
  import.meta.url === pathToFileURL(resolve(process.argv[1])).href
) {
  startServer(parseArgs(process.argv.slice(2)))
    .then(({ origin, file }) =>
      console.log(
        JSON.stringify({ app: "discussion-notes", url: origin, file }),
      ),
    )
    .catch((error) => {
      console.error(
        error.code === "EADDRINUSE"
          ? "Port in use. Choose another --port."
          : error.message,
      );
      process.exitCode = 1;
    });
}
