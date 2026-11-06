<?php
/**
 * Builds the playground database at build time (run by scripts/build-bundle.mjs in
 * PHP WebAssembly for Node, with the staged bundle mounted at /srv).
 *
 * 1. Load the schema, master data and demo fixtures - the same files and the same
 *    naive ";" splitting the web installer (app/install/submit.php) uses.
 * 2. Give every demo user the playground password.
 * 3. Run all core and extension migrations, as the first login would.
 *
 * Prints a JSON report on the last line of stdout.
 */
$report = ['files' => [], 'migrations' => [], 'errors' => []];

require '/srv/icehrm/app/config.php';
require_once '/srv/icehrm/core/lib/composer/vendor/icehrm/php-active-record/src/MyORM/MySqlActiveRecord.php';

// Keep going on errors (like the installer) but record them.
mysqli_report(MYSQLI_REPORT_OFF);

$demoPassword = getenv('PLAYGROUND_PASSWORD') ?: 'demo1234';

$db = new \MyORM\MySqlActiveRecord();
if (!$db->Connect(APP_HOST, APP_USERNAME, APP_PASSWORD, APP_DB)) {
    fwrite(STDERR, "Could not open the SQLite database: " . $db->ErrorMsg() . "\n");
    exit(1);
}

function runSqlFile($db, $file, array &$report)
{
    $sql = file_get_contents($file);
    $ok = 0;
    $failed = 0;
    foreach (preg_split('/;/', $sql) as $statement) {
        if (preg_match('/^\s*$/', $statement)) {
            continue;
        }
        if ($db->Execute($statement) === false) {
            $failed++;
            if (count($report['errors']) < 50) {
                $report['errors'][] = [
                    'file' => basename($file),
                    'error' => substr($db->ErrorMsg(), 0, 400),
                    'sql' => substr(trim($statement), 0, 200),
                ];
            }
        } else {
            $ok++;
        }
    }
    $report['files'][basename($file)] = ['ok' => $ok, 'failed' => $failed];
}

$scripts = '/srv/icehrm/core/scripts/';
runSqlFile($db, $scripts . 'icehrmdb.sql', $report);
runSqlFile($db, $scripts . 'icehrm_master_data.sql', $report);
if (getenv('PLAYGROUND_FIXTURES') !== '0') {
    runSqlFile($db, $scripts . 'icehrm_fixtures.sql', $report);
}

// Cost 10 rather than the app's 13: bcrypt in WebAssembly is slow, and this is a demo.
$hash = password_hash($demoPassword, PASSWORD_BCRYPT, ['cost' => 10]);
$db->Execute('UPDATE Users SET password = ?', [$hash]);
$db->Execute("UPDATE Users SET email = 'admin@example.com' WHERE username = 'admin'");
$users = $db->Execute('SELECT username, user_level FROM Users ORDER BY id');
$report['users'] = is_array($users) ? $users : [];

// Migrations, exactly as core/login.php triggers them after a successful login.
define('CLIENT_PATH', '/srv/icehrm/core');
chdir('/srv/icehrm/core');
include '/srv/icehrm/core/config.base.php';
include '/srv/icehrm/core/include.common.php';
include '/srv/icehrm/core/server.includes.inc.php';
\Classes\Migration\MigrationRunner::runAll(false);

// Pre-seed "no news" so the dashboard never calls icehrm.com: in WebAssembly the
// request can't connect and would hold the only PHP instance for the connect timeout.
// Key format from AppShellRestEndPoint::fetchRemoteNews().
$versionParts = explode('.', VERSION);
$newsType = strtolower((string) end($versionParts));
foreach (['Admin', 'Manager', 'Employee', 'Restricted Admin', 'Restricted Manager', 'Restricted Employee'] as $level) {
    \Classes\DatabaseCache::getInstance()->set('appshell:news:' . $newsType . ':' . VERSION . ':' . $level, [], 10 * 365 * 86400);
}

$rows = $db->Execute('SELECT status, COUNT(*) AS n FROM Migrations GROUP BY status');
foreach (is_array($rows) ? $rows : [] as $row) {
    $report['migrations'][$row['status']] = (int) $row['n'];
}
$failedMigrations = $db->Execute("SELECT file, status, last_error FROM Migrations WHERE status <> 'Up' ORDER BY id");
$report['failedMigrations'] = is_array($failedMigrations) ? array_slice($failedMigrations, 0, 50) : [];
$tables = $db->Execute('SHOW TABLES');
$report['tables'] = is_array($tables) ? count($tables) : 0;

echo "\n" . json_encode($report) . "\n";
