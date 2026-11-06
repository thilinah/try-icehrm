# Try IceHrm

IceHrm running entirely in the browser: PHP, the database and the application all run on
the visitor's device, with no server behind them. Live at **https://icehrm.github.io/**.

> **Status: proof of concept.** Not for real HR data. Changes are saved in the visitor's
> browser until they choose **Reset demo**.

## How it works

```
 page (index.html)                 service worker (sw.js)            PHP worker (php-worker.js)
 ─────────────────                 ──────────────────────            ──────────────────────────
 starts a worker for a  ────────►  intercepts every request  ──────► PHP 8.3 in WebAssembly
 random scope, then                under /scope-<id>/ and     ◄──────  (@php-wasm/web) serving
 loads /scope-<id>/app/            forwards it over a                   IceHrm from an in-memory
 login.php in an iframe            BroadcastChannel                     filesystem
                                                                       └─ SQLite database
```

- **PHP** comes from [`@php-wasm`](https://www.npmjs.com/package/@php-wasm/web), PHP compiled
  to WebAssembly.
- **MySQL** is replaced with SQLite through a MySQL-on-SQLite driver (fetched by
  [`scripts/fetch-sqlite-driver.sh`](scripts/fetch-sqlite-driver.sh)), which translates MySQL
  queries to SQLite. IceHrm talks to MySQL through mysqli, so
  [`php/SqliteMysqli.php`](php/SqliteMysqli.php) implements the parts of the mysqli API
  IceHrm uses on top of that driver, emulating prepared statements.
- **The build** ([`scripts/build-bundle.mjs`](scripts/build-bundle.mjs)) takes an IceHrm release,
  trims it for the browser (no dev tooling, docs, most fonts or unused vendor code), patches
  the ORM to use the adapter, then runs [`php/build-db.php`](php/build-db.php) in PHP
  WebAssembly for Node to create the database: schema, master data, demo data and every
  migration. The result is one zip, `web/public/icehrm-bundle.zip`, that the browser downloads
  and unpacks.

The IceHrm code itself is unchanged apart from a three-line patch to
`MySqlActiveRecord::Connect()` and a playground `app/config.php`.

## Saved data

The database and uploaded files (`/srv/data` and `/srv/icehrm/app/data` inside PHP) are
mirrored to the browser's Origin Private File System after every request, using
`@php-wasm/web`'s directory mount ([`web/src/php-worker.js`](web/src/php-worker.js)).

- A reload loads the saved copy instead of the bundle's fresh database. The login session
  is not saved, so the visitor signs in again.
- The saved copy belongs to one bundle build. A new `icehrm-bundle.zip` (a new release, or
  a rebuild) starts from that bundle's fresh database and discards the old copy.
- **Reset demo** discards the saved copy.
- Only one tab can own the saved copy (a Web Lock). Another tab opened at the same time
  runs in memory and is marked "Not saved"; so is a browser window without storage.

## Build

Prerequisites: Node 20+, and an unpacked IceHrm release. The default is
`../icehrm/build/release/data/icehrm`, which `./release.sh` in the
[icehrm](https://github.com/gamonoid/icehrm) repo produces.

```bash
npm install
npm run fetch:sqlite     # MySQL-on-SQLite driver, pinned release
npm run build:bundle     # -> web/public/icehrm-bundle.zip
npm run dev              # http://localhost:5173
```

Use another release with `ICEHRM_SRC=/path/to/icehrm npm run build:bundle`.
The bundle build prints SQL errors and failed migrations, and writes the full report to
`.build/db-report.json`.

## Demo logins

`admin`, `manager`, `user1` … `user9`, all with the password `demo1234`
(set `PLAYGROUND_PASSWORD` when building to change it).

## Deploying

The playground is published with GitHub Pages at https://icehrm.github.io/, from the
`try_icehrm` branch of [icehrm/icehrm.github.io](https://github.com/icehrm/icehrm.github.io):

```bash
npm run build:bundle && npm run build && npm run deploy
```

[`scripts/deploy.sh`](scripts/deploy.sh) pushes `dist/` to that branch as a single commit,
replacing the previous one, and does nothing if the branch already has the same build.
`npm run deploy -- --dry-run` goes through the steps without updating the branch. Pages
publishes a new build within a few minutes.

### Other hosts

`npm run build` writes a static site to `dist/`. Any static host works
(S3 + CloudFront, GitHub Pages, Netlify, Cloudflare Pages):

- **HTTPS is required.** Service workers only run on HTTPS (or localhost).
- Serve `sw.js` from the site root with `Cache-Control: no-cache`, so updates reach visitors.
- Serve `.wasm` as `application/wasm`.
- Cache `assets/*` (hashed file names) and the PHP runtime files for a long time.
  Version `icehrm-bundle.zip` per release or give it a short cache lifetime.
- The first visit downloads the PHP runtime and the IceHrm bundle (tens of MB); later
  visits come from the browser cache.
- `dist/` is about 410 MB because Vite emits every PHP version `@php-wasm/web` ships.
  Only PHP 8.3 is ever requested (about 18 MB plus the 37 MB bundle), so the rest is
  dead weight on the host; prune it before upload if storage matters.

## Known limits (proof of concept)

- **No network features.** Email, the Microsoft/Google integrations, S3 storage, the
  marketplace and the updater don't work in the browser.
- **No background jobs.** There is no cron or worker process.
- **SQL coverage.** IceHrm uses MySQL-specific SQL in many places; anything the
  translator can't handle shows up as an error in that screen. The build report lists
  problems found while creating the database.
- **One saved copy.** Each tab runs its own IceHrm, and only the first tab's data is saved
  (see Saved data).

## License

GPL-3.0-or-later, like IceHrm; see [LICENSE](LICENSE). The MySQL-on-SQLite driver is GPL-2.0-or-later and
`@php-wasm` is GPL-2.0-or-later.
