/**
 * Page bootstrap: register the service worker, start the PHP worker for this tab's
 * scope, show progress, then load IceHrm into the iframe.
 */
const loading = document.getElementById('loading');
const frame = document.getElementById('app');
const errorBox = document.getElementById('error');
const downloadProgress = document.getElementById('download-progress');
const STAGES = ['php', 'download', 'unpack', 'storage', 'ready'];
const RESET_FLAG = 'icehrm-reset';
const STORAGE_NOTES = {
  restored: ['Saved in this browser', 'Your changes are kept in this browser until you choose Reset demo.'],
  new: ['Saved in this browser', 'Your changes are kept in this browser until you choose Reset demo.'],
  temporary: ['Not saved', 'IceHrm is open in another tab, which keeps the saved copy. Changes made in this tab are lost when you reload.'],
  unavailable: ['Not saved', 'This browser window has no storage for the demo. Changes are lost when you reload.'],
};

function setStage(stage) {
  const current = STAGES.indexOf(stage);
  for (const li of document.querySelectorAll('.steps li')) {
    const index = STAGES.indexOf(li.dataset.step);
    li.classList.toggle('done', index < current);
    li.classList.toggle('active', index === current);
  }
}

function fail(message) {
  errorBox.textContent = message;
  errorBox.hidden = false;
}

async function ensureServiceWorker() {
  if (!('serviceWorker' in navigator)) {
    throw new Error('This browser does not support service workers, which the playground needs.');
  }
  await navigator.serviceWorker.register(new URL('/sw.js', location.href), { scope: '/' });
  await navigator.serviceWorker.ready;
  if (!navigator.serviceWorker.controller) {
    // First visit: the worker activates and claims this page.
    await new Promise((resolve) => navigator.serviceWorker.addEventListener('controllerchange', resolve, { once: true }));
  }
}

async function start() {
  setStage('php');
  await ensureServiceWorker();

  const scope = `scope-${crypto.getRandomValues(new Uint32Array(1))[0].toString(36)}`;
  const worker = new Worker(new URL('./php-worker.js', import.meta.url), { type: 'module' });

  worker.onmessage = (event) => {
    const message = event.data;
    if (message.type === 'progress') {
      setStage(message.stage);
      if (message.stage === 'download' && message.total) {
        downloadProgress.textContent = `${Math.round((message.received / message.total) * 100)}%`;
      }
      if (message.stage === 'ready') {
        const [label, title] = STORAGE_NOTES[message.storage];
        const note = document.getElementById('storage');
        note.textContent = label;
        note.title = title;
        note.hidden = false;
        frame.src = message.entry;
        frame.addEventListener('load', () => {
          loading.hidden = true;
          frame.hidden = false;
          document.getElementById('logins').hidden = false;
          document.getElementById('reset').hidden = false;
        }, { once: true });
      }
    } else if (message.type === 'error') {
      fail(`IceHrm could not start: ${message.message}`);
    }
  };
  worker.onerror = (event) => fail(`IceHrm could not start: ${event.message || 'worker error'}`);

  // Set by the Reset demo button just before it reloads the page.
  const reset = sessionStorage.getItem(RESET_FLAG) === '1';
  sessionStorage.removeItem(RESET_FLAG);

  worker.postMessage({
    type: 'boot',
    reset,
    scope,
    origin: location.origin,
    bundleUrl: new URL('icehrm-bundle.zip', location.href).href,
  });
}

document.getElementById('reset').addEventListener('click', () => {
  // The saved data is cleared by the worker on the next start.
  sessionStorage.setItem(RESET_FLAG, '1');
  location.reload();
});

start().catch((error) => fail(error.message));
