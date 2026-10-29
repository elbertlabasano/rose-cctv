import { spawn } from "node:child_process";
import { mkdir, open } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
import { parseArgs } from "./server.mjs";

const options = parseArgs(process.argv.slice(2));
if (!options.port)
  throw new Error(
    "The launcher needs a fixed port. Use --port 8767 or another free port.",
  );
const url = `http://127.0.0.1:${options.port}`;
async function running() {
  try {
    const response = await fetch(`${url}/api/health`, {
      signal: AbortSignal.timeout(750),
    });
    if (!response.ok) return null;
    return await response.json();
  } catch {
    return null;
  }
}
function confirm(info) {
  if (info.app !== "discussion-notes" || info.file !== options.file) {
    throw new Error(
      "This port is serving another app or transcript. Choose another --port.",
    );
  }
  console.log(JSON.stringify({ app: info.app, url, file: info.file }));
}
try {
  const existing = await running();
  if (existing) confirm(existing);
  else {
    const logDir = join(tmpdir(), "codex-discussion-notes");
    await mkdir(logDir, { recursive: true });
    const out = await open(join(logDir, `server-${options.port}.log`), "a");
    const child = spawn(
      process.execPath,
      [
        fileURLToPath(new URL("./server.mjs", import.meta.url)),
        "--file",
        options.file,
        "--port",
        String(options.port),
      ],
      {
        detached: true,
        windowsHide: true,
        stdio: ["ignore", out.fd, out.fd],
      },
    );
    let launchError;
    child.on("error", (error) => {
      launchError = error;
    });
    child.unref();
    let ready;
    for (let attempt = 0; attempt < 30; attempt++) {
      if (launchError) throw launchError;
      ready = await running();
      if (ready) break;
      await new Promise((resolve) => setTimeout(resolve, 150));
    }
    await out.close();
    if (!ready)
      throw new Error(
        "The app could not start. Check that the port is free or choose --port 8768.",
      );
    confirm(ready);
  }
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
