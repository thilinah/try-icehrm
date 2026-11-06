#!/usr/bin/env node
/**
 * Builds web/public/icehrm-bundle.zip: an IceHrm release prepared to run in the
 * browser on PHP WebAssembly with an SQLite database.
 *
 *   ICEHRM_SRC   unpacked IceHrm release (default ../icehrm/build/release/data/icehrm,
 *                produced by ./release.sh in the icehrm repo)
 *   PHP_VERSION  PHP version used to build the database (default 8.3)
 *
 * Steps:
 *   1. Copy the release into .build/srv/icehrm, leaving out what the browser does
 *      not need (dev tooling, docs, most fonts, unused vendor code, source maps).
 *   2. Add the MySQL-on-SQLite driver and the mysqli adapter, patch the ORM to use
 *      them, and write the playground app/config.php.
 *   3. Run php/build-db.php in PHP WebAssembly (Node) to create the database with
 *      schema, master data, demo data and all migrations.
 *   4. Zip srv/icehrm + srv/data into web/public/icehrm-bundle.zip.
 */
import { cp, mkdir, readFile, rm, writeFile, stat, readdir } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { zipSync } from 'fflate';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ICEHRM_SRC = path.resolve(process.env.ICEHRM_SRC || path.join(ROOT, '../icehrm/build/release/data/icehrm'));
const PHP_VERSION = process.env.PHP_VERSION || '8.3';
const SQLITE_DRIVER = path.join(ROOT, 'vendor-src/sqlite-database-integration/packages/mysql-on-sqlite/src');
const STAGE = path.join(ROOT, '.build/srv');
const SITE = path.join(STAGE, 'icehrm');
const DATA = path.join(STAGE, 'data');
const OUTPUT = path.join(ROOT, 'web/public/icehrm-bundle.zip');

const VENDOR = 'core/lib/composer/vendor';

/** Paths (relative to the release root) left out of the browser bundle. */
const EXCLUDE = [
  'docs', 'release-notes', 'updater', 'bin', 'app/install', 'app/data/upgrades',
  // Dev tooling that ships in vendor/ (require-dev in core/lib/composer/composer.json).
  ...['phpunit', 'sebastian', 'pdepend', 'phploc', 'phpmd', 'mayflower', 'squizlabs',
    'phpspec', 'phpdocumentor', 'phar-io', 'theseer', 'doctrine/instantiator']
    .map((p) => `${VENDOR}/${p}`),
];

/** Directories pruned down to an allowlist of entries. */
const KEEP_ONLY = [
  // Only the flag icons are still referenced (core/header.php).
  { dir: 'web/bower_components', keep: (name) => name === 'flag-icon-css' },
  // Google services are only used for Calendar sync, which is off in the playground.
  { dir: `${VENDOR}/google/apiclient-services/src`, keep: (name) => /^Calendar(\.php)?$/.test(name) },
  // mPDF: keep the DejaVu family (its default) only.
  { dir: `${VENDOR}/mpdf/mpdf/ttfonts`, keep: (name) => /^DejaVu/i.test(name) },
];

const DROP_FILE = (rel) => rel.endsWith('.map') || path.basename(rel) === '.DS_Store';

function log(msg) {
  console.log(`==> ${msg}`);
}

async function dirSize(dir) {
  let total = 0;
  let files = 0;
  for (const entry of await readdir(dir, { withFileTypes: true, recursive: true })) {
    if (entry.isFile()) {
      total += (await stat(path.join(entry.parentPath ?? entry.path, entry.name))).size;
      files++;
    }
  }
  return { total, files };
}

const mb = (bytes) => `${(bytes / 1024 / 1024).toFixed(1)} MB`;

async function stageRelease() {
  if (!existsSync(path.join(ICEHRM_SRC, 'core/config.base.php'))) {
    throw new Error(`No IceHrm release at ${ICEHRM_SRC} (set ICEHRM_SRC, or run ./release.sh in the icehrm repo)`);
  }
  const base = await readFile(path.join(ICEHRM_SRC, 'core/config.base.php'), 'utf8');
  const version = (base.match(/define\('VERSION', '([^']+)'\)/) || [])[1] || 'unknown';
  log(`Staging IceHrm ${version} from ${ICEHRM_SRC}`);

  await rm(path.join(ROOT, '.build'), { recursive: true, force: true });
  await mkdir(DATA, { recursive: true });

  const excluded = new Set(EXCLUDE.map((p) => path.join(ICEHRM_SRC, p)));
  await cp(ICEHRM_SRC, SITE, {
    recursive: true,
    filter: (src) => {
      if (excluded.has(src)) return false;
      const rel = path.relative(ICEHRM_SRC, src);
      if (DROP_FILE(rel)) return false;
      for (const rule of KEEP_ONLY) {
        if (path.dirname(rel) === rule.dir && !rule.keep(path.basename(rel))) return false;
      }
      return true;
    },
  });

  const before = await dirSize(ICEHRM_SRC);
  const after = await dirSize(SITE);
  log(`Bundle files: ${after.files} (${mb(after.total)}), release was ${before.files} (${mb(before.total)})`);
  return version;
}

async function addSqliteSupport() {
  if (!existsSync(path.join(SQLITE_DRIVER, 'load.php'))) {
    throw new Error(`MySQL-on-SQLite driver not found at ${SQLITE_DRIVER} (see README: npm run fetch:sqlite)`);
  }
  log('Adding MySQL-on-SQLite driver and mysqli adapter');
  await cp(SQLITE_DRIVER, path.join(SITE, 'playground/mysql-on-sqlite'), { recursive: true });
  await cp(path.join(ROOT, 'php/SqliteMysqli.php'), path.join(SITE, 'playground/SqliteMysqli.php'));
  await cp(path.join(ROOT, 'php/config.php'), path.join(SITE, 'app/config.php'));

  // Point the ORM's connection at the adapter when the playground config is loaded.
  const ormFile = path.join(SITE, `${VENDOR}/icehrm/php-active-record/src/MyORM/MySqlActiveRecord.php`);
  const orm = await readFile(ormFile, 'utf8');
  const target = '            if (1 === count($hostData)) {';
  if (orm.split(target).length !== 2) {
    throw new Error('MySqlActiveRecord::Connect() no longer matches the expected code - update the patch');
  }
  await writeFile(ormFile, orm.replace(target, [
    "            if (defined('PLAYGROUND_SQLITE_PATH')) {",
    '                self::$connection = new \\IcePlayground\\SqliteMysqli(PLAYGROUND_SQLITE_PATH, $schema);',
    '            } elseif (1 === count($hostData)) {',
  ].join('\n')));
}

async function buildDatabase() {
  log(`Building the database in PHP ${PHP_VERSION} (WebAssembly)`);
  const { PHP } = await import('@php-wasm/universal');
  const { loadNodeRuntime, createNodeFsMountHandler } = await import('@php-wasm/node');

  await cp(path.join(ROOT, 'php/build-db.php'), path.join(STAGE, 'build-db.php'));
  const php = new PHP(await loadNodeRuntime(PHP_VERSION, { emscriptenOptions: { processId: 1 } }));
  await php.mount('/srv', createNodeFsMountHandler(STAGE));

  const started = Date.now();
  const response = await php.run({ scriptPath: '/srv/build-db.php' });
  const stdout = response.text.trim();
  const reportLine = stdout.split('\n').pop();
  let report;
  try {
    report = JSON.parse(reportLine);
  } catch {
    console.error(stdout.slice(-4000));
    console.error(response.errors);
    throw new Error(`build-db.php did not finish (exit code ${response.exitCode})`);
  }
  php.exit();
  await rm(path.join(STAGE, 'build-db.php'));

  log(`Database built in ${((Date.now() - started) / 1000).toFixed(1)}s: ${report.tables} tables`);
  for (const [file, r] of Object.entries(report.files)) {
    console.log(`    ${file}: ${r.ok} statements ok, ${r.failed} failed`);
  }
  console.log(`    migrations: ${JSON.stringify(report.migrations)}`);
  console.log(`    users: ${report.users.map((u) => `${u.username} (${u.user_level})`).join(', ')}`);
  for (const e of report.errors) {
    console.log(`    SQL error in ${e.file}: ${e.error}\n        ${e.sql.replace(/\s+/g, ' ')}`);
  }
  for (const m of report.failedMigrations) {
    console.log(`    migration ${m.file}: ${m.status} ${m.last_error ?? ''}`);
  }
  await writeFile(path.join(ROOT, '.build/db-report.json'), JSON.stringify(report, null, 2));
  return report;
}

async function zipBundle(version) {
  log('Packing the bundle');
  const files = {};
  for (const top of ['icehrm', 'data']) {
    const dir = path.join(STAGE, top);
    for (const entry of await readdir(dir, { withFileTypes: true, recursive: true })) {
      if (!entry.isFile()) continue;
      const abs = path.join(entry.parentPath ?? entry.path, entry.name);
      const rel = path.relative(STAGE, abs).split(path.sep).join('/');
      // Already-compressed assets are stored, everything else deflated.
      const level = /\.(png|jpe?g|gif|woff2?|zip|gz)$/i.test(rel) ? 0 : 6;
      files[rel] = [new Uint8Array(await readFile(abs)), { level }];
    }
  }
  files['bundle.json'] = new TextEncoder().encode(JSON.stringify({ version, built: new Date().toISOString() }));
  await mkdir(path.dirname(OUTPUT), { recursive: true });
  const zipped = zipSync(files);
  await writeFile(OUTPUT, zipped);
  log(`Wrote ${path.relative(ROOT, OUTPUT)} (${mb(zipped.length)}, ${Object.keys(files).length} files)`);
}

const version = await stageRelease();
await addSqliteSupport();
await buildDatabase();
await zipBundle(version);
