/**
 * Runs IceHrm in PHP WebAssembly inside a dedicated worker.
 *
 * The page sends { type: 'boot', scope, origin, bundleUrl }. The worker downloads
 * the bundle, unpacks it into PHP's in-memory filesystem and then answers HTTP
 * requests that the service worker forwards for its scope over a BroadcastChannel.
 *
 * The database and uploaded files are kept in the browser's Origin Private File
 * System, so they survive a reload. Only one tab can own that saved copy; any other
 * tab open at the same time runs a temporary, in-memory IceHrm.
 */
import { PHP, PHPRequestHandler } from '@php-wasm/universal';
import { createDirectoryHandleMountHandler, loadWebRuntime } from '@php-wasm/web';
import { unzipSync } from 'fflate';

const PHP_VERSION = '8.3';
const channel = new BroadcastChannel('icehrm-php');

// Saved data: one folder in the Origin Private File System, with a subfolder for each
// PHP directory that holds data - [name in browser storage, path in PHP].
const STORAGE_DIR = 'icehrm-playground';
const STORAGE_LOCK = 'icehrm-playground-storage';
const SAVED_DIRS = [
  ['data', '/srv/data'],
  ['uploads', '/srv/icehrm/app/data'],
];
// Kept in the data folder: the id of the bundle the saved data was created from.
const STORAGE_MARKER = 'bundle-id.txt';

let handler = null;
let phpInstance = null;
let scope = null;

function progress(stage, detail = {}) {
  self.postMessage({ type: 'progress', stage, ...detail });
}

async function download(url) {
  const response = await fetch(url);
  if (!response.ok) throw new Error(`Download failed: ${url} (${response.status})`);
  const total = Number(response.headers.get('content-length')) || 0;
  const reader = response.body.getReader();
  const chunks = [];
  let received = 0;
  for (;;) {
    const { done, value } = await reader.read();
    if (done) break;
    chunks.push(value);
    received += value.length;
    progress('download', { received, total });
  }
  const bytes = new Uint8Array(received);
  let offset = 0;
  for (const chunk of chunks) {
    bytes.set(chunk, offset);
    offset += chunk.length;
  }
  return bytes;
}

function unpack(php, zipBytes) {
  const files = unzipSync(zipBytes);
  const made = new Set();
  let info = {};
  for (const [name, data] of Object.entries(files)) {
    if (name === 'bundle.json') {
      info = JSON.parse(new TextDecoder().decode(data));
      continue;
    }
    if (name.endsWith('/')) continue;
    const full = `/srv/${name}`;
    const dir = full.slice(0, full.lastIndexOf('/'));
    if (!made.has(dir)) {
      php.mkdirTree(dir);
      made.add(dir);
    }
    php.writeFile(full, data);
  }
  return info;
}

/**
 * Claims the saved data for this tab. The lock is held until the tab closes, so two
 * tabs never write the same database. Retries briefly: on a reload the previous
 * page's worker may not have released the lock yet.
 */
async function claimStorage() {
  if (!navigator.locks || !navigator.storage?.getDirectory) return false;
  for (let attempt = 0; attempt < 5; attempt++) {
    const held = await new Promise((resolve) => {
      navigator.locks
        .request(STORAGE_LOCK, { ifAvailable: true }, (lock) => {
          resolve(lock !== null);
          return lock ? new Promise(() => {}) : undefined;
        })
        .catch(() => resolve(false));
    });
    if (held) return true;
    await new Promise((resolve) => setTimeout(resolve, 300));
  }
  return false;
}

async function readMarker(dir) {
  try {
    const data = await dir.getDirectoryHandle('data');
    // The marker alone is not enough: make sure the database is really there.
    await data.getFileHandle('icehrm.sqlite');
    return await (await (await data.getFileHandle(STORAGE_MARKER)).getFile()).text();
  } catch {
    return null;
  }
}

/**
 * Connects the data directories to browser storage. Saved data from this bundle is
 * loaded over the bundle's fresh copy; otherwise (first visit, a new bundle or a
 * reset) storage is cleared and filled from the fresh copy.
 *
 * @returns 'restored' or 'new', or null when the browser has no usable storage
 *          (e.g. some private windows) and this session stays in memory.
 */
async function mountStorage(php, bundleId, reset) {
  let root;
  let dir;
  let restore;
  try {
    root = await navigator.storage.getDirectory();
    dir = await root.getDirectoryHandle(STORAGE_DIR, { create: true });
    restore = !reset && (await readMarker(dir)) === bundleId;
    if (!restore) {
      await root.removeEntry(STORAGE_DIR, { recursive: true });
      dir = await root.getDirectoryHandle(STORAGE_DIR, { create: true });
    }
  } catch (error) {
    console.warn('[storage] not available, this session will not be saved', error);
    return null;
  }

  for (const [name, path] of SAVED_DIRS) {
    php.mkdirTree(path);
    const handle = await dir.getDirectoryHandle(name, { create: true });
    await php.mount(
      path,
      createDirectoryHandleMountHandler(handle, {
        initialSync: { direction: restore ? 'opfs-to-memfs' : 'memfs-to-opfs' },
      }),
    );
  }

  // Written after the copy (and saved like any other file in the data folder), so a
  // copy that was interrupted is never taken for saved data.
  if (!restore) php.writeFile(`/srv/data/${STORAGE_MARKER}`, bundleId);
  return restore ? 'restored' : 'new';
}

async function boot({ scope: tabScope, origin, bundleUrl, reset }) {
  scope = tabScope;
  const baseUrl = `${origin}/${scope}/`;
  const storageClaim = claimStorage();

  progress('php');
  const php = new PHP(await loadWebRuntime(PHP_VERSION));
  phpInstance = php;

  const zip = await download(bundleUrl);
  progress('unpack');
  const info = unpack(php, zip);

  // 'restored' | 'new' when saved in the browser, 'temporary' when another tab owns
  // the saved copy, 'unavailable' when the browser offers no storage.
  let storage = 'temporary';
  if (await storageClaim) {
    progress('storage');
    storage = (await mountStorage(php, `${info.version} ${info.built}`, reset)) ?? 'unavailable';
  }
  // The log is only useful for the current session; don't let it grow in storage.
  if (php.fileExists('/srv/data/icehrm.log')) php.unlink('/srv/data/icehrm.log');

  php.writeFile(
    '/srv/data/runtime.php',
    `<?php\ndefine('PLAYGROUND_BASE_URL', ${JSON.stringify(baseUrl)});\n`,
  );
  php.writeFile('/srv/icehrm/index.php', '<?php header("Location: app/"); ');

  handler = new PHPRequestHandler({
    php,
    documentRoot: '/srv/icehrm',
    absoluteUrl: `${origin}/${scope}`,
    rewriteRules: [
      // app/api/.htaccess: every non-file request under app/api/ goes to index.php.
      { match: /^\/app\/api\/(?!index\.php).+$/, replacement: '/app/api/index.php' },
    ],
  });

  progress('ready', { version: info.version, phpVersion: PHP_VERSION, storage, entry: `${baseUrl}app/login.php` });
}

channel.onmessage = async (event) => {
  const message = event.data;
  // Dev only: read a file from PHP's filesystem, e.g. the error log
  // (channel.postMessage({ type: 'read-file', scope, id, path: '/srv/data/icehrm.log' })).
  if (import.meta.env.DEV && message?.type === 'read-file' && message.scope === scope && phpInstance) {
    const text = phpInstance.fileExists(message.path) ? phpInstance.readFileAsText(message.path) : null;
    channel.postMessage({ type: 'file', id: message.id, path: message.path, text });
    return;
  }
  if (message?.type !== 'request' || message.scope !== scope || !handler) return;

  // One PHP instance and one SQLite connection: run requests strictly one after
  // another. Overlapping requests on the same instance fail with a bare 500.
  queue = queue.then(() => handle(message));
};

let queue = Promise.resolve();

async function handle(message) {
  try {
    const response = await handler.request({
      method: message.method,
      url: message.url,
      headers: message.headers,
      body: message.body ? new Uint8Array(message.body) : undefined,
    });
    const body = response.bytes.slice().buffer;
    channel.postMessage({
      type: 'response',
      id: message.id,
      status: response.httpStatusCode,
      headers: response.headers,
      body,
    });
    if (response.errors) console.warn('[php]', response.errors);
  } catch (error) {
    console.error('[php] request failed', message.url, error);
    channel.postMessage({
      type: 'response',
      id: message.id,
      status: 500,
      headers: { 'content-type': ['text/plain; charset=utf-8'] },
      body: new TextEncoder().encode(`PHP request failed: ${error?.message ?? error}`).buffer,
    });
  }
}

self.onmessage = (event) => {
  if (event.data?.type === 'boot') {
    boot(event.data).catch((error) => {
      console.error(error);
      self.postMessage({ type: 'error', message: error?.message ?? String(error) });
    });
  }
};
