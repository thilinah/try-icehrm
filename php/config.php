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

// PHP in the browser cannot open network connections. An attempt does not fail, it
// waits for minutes, and with a single PHP instance the whole app stops responding
// meanwhile. IceHrm connects on its own in places - it reports installation data to
// icehrm.com once an hour, for example. The worker therefore switches off the functions
// that connect (allow_url_fopen and disable_functions, see web/src/php-worker.js), and
// these stand-ins make every such call fail at once, as it would with no connectivity.
// curl_multi_exec is left undefined on purpose: a stand-in that never completes a
// transfer would make Guzzle wait forever, without it Guzzle uses curl_exec.
if (!function_exists('curl_exec')) {
    function curl_exec($handle)
    {
        return false;
    }
}
if (!function_exists('fsockopen')) {
    function fsockopen($hostname, $port = -1, &$error_code = null, &$error_message = null, $timeout = null)
    {
        $error_code = 101;
        $error_message = 'No network in the browser playground';
        return false;
    }
}
if (!function_exists('pfsockopen')) {
    function pfsockopen($hostname, $port = -1, &$error_code = null, &$error_message = null, $timeout = null)
    {
        return fsockopen($hostname, $port, $error_code, $error_message, $timeout);
    }
}
if (!function_exists('stream_socket_client')) {
    function stream_socket_client($address, &$error_code = null, &$error_message = null, $timeout = null, $flags = 0, $context = null)
    {
        $error_code = 101;
        $error_message = 'No network in the browser playground';
        return false;
    }
}

// Keep IceHrm's calls home (news, marketplace, updates) away from icehrm.com even if
// the functions above are available, as they are while the database is built in Node.
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
