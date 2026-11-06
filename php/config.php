<?php
/**
 * IceHrm playground configuration.
 * Copied to app/config.php by scripts/build-bundle.mjs - replaces the installer's
 * MySQL config with an SQLite database through the mysqli adapter.
 *
 * Filesystem layout inside PHP (WebAssembly):
 *   /srv/icehrm   IceHrm program files (document root)
 *   /srv/data     database, logs and the per-tab runtime settings
 */
ini_set('error_log', '/srv/data/icehrm.log');
ini_set('display_errors', '0');

// php-wasm only parses response headers written as "Name: value" and silently drops
// "Name:value", which IceHrm uses for its redirects (header("Location:" . $url)).
// Rewrite such headers with the space just before they are sent.
header_register_callback(function () {
    foreach (headers_list() as $line) {
        if (preg_match('/^([A-Za-z0-9-]+):(?! )(.*)$/s', $line, $m)
            && strcasecmp($m[1], 'Set-Cookie') !== 0) {
            header($m[1] . ': ' . $m[2], true);
        }
    }
});

define('PLAYGROUND', true);
define('PLAYGROUND_SQLITE_PATH', '/srv/data/icehrm.sqlite');

require_once '/srv/icehrm/playground/mysql-on-sqlite/load.php';
require_once '/srv/icehrm/playground/SqliteMysqli.php';

// Written by the browser worker before the first request: the public URL of this
// tab's IceHrm instance, e.g. https://try.icehrm.org/scope-1a2b3c4d/
if (is_file('/srv/data/runtime.php')) {
    require_once '/srv/data/runtime.php';
}
if (!defined('PLAYGROUND_BASE_URL')) {
    define('PLAYGROUND_BASE_URL', 'http://localhost/');
}

// The browser has no outbound network for PHP, and a call to icehrm.com (news,
// marketplace) would otherwise block the only PHP instance until curl times out.
// Point it at a closed local port so those calls fail immediately.
define('APP_WEB_URL', 'http://127.0.0.1:9');

define('APP_NAME', 'Ice Hrm');
define('FB_URL', 'Ice Hrm');
define('TWITTER_URL', 'Ice Hrm');

define('CLIENT_NAME', 'app');
define('APP_BASE_PATH', '/srv/icehrm/core/');
define('CLIENT_BASE_PATH', '/srv/icehrm/app/');
define('BASE_URL', PLAYGROUND_BASE_URL . 'web/');
define('CLIENT_BASE_URL', PLAYGROUND_BASE_URL . 'app/');

// Ignored by the SQLite adapter; kept because IceHrm reads them.
define('APP_DB', 'icehrm');
define('APP_USERNAME', 'playground');
define('APP_PASSWORD', 'playground');
define('APP_HOST', 'localhost');
define('APP_CON_STR', 'mysqli://' . APP_USERNAME . ':' . APP_PASSWORD . '@' . APP_HOST . '/' . APP_DB);

//file upload
define('FILE_TYPES', 'jpg,png,jpeg');
define('MAX_FILE_SIZE_KB', 10 * 1024);
