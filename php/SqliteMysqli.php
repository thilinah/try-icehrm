<?php
/**
 * mysqli-compatible adapter over WordPress's MySQL-on-SQLite driver
 * (wordpress/mysql-on-sqlite, class WP_MySQL_On_SQLite).
 *
 * IceHrm talks to MySQL through mysqli: its ORM (MyORM\MySqlActiveRecord) uses
 * prepared statements, and DbDelta / DashboardService call query() directly. The
 * browser PHP build has no MySQL server (and no mysqli extension), so the playground
 * build points the ORM at this class instead.
 *
 * It implements the subset of mysqli / mysqli_stmt / mysqli_result that IceHrm uses.
 * MySQL-on-SQLite does not support prepared statements yet, so they are emulated:
 * each "?" placeholder (outside quotes, identifiers and comments) is replaced with
 * the bound value, quoted by the driver's own PDO::quote().
 */

namespace {
    if (!defined('MYSQLI_ASSOC')) {
        define('MYSQLI_ASSOC', 1);
        define('MYSQLI_NUM', 2);
        define('MYSQLI_BOTH', 3);
    }
    if (!defined('MYSQLI_REPORT_OFF')) {
        define('MYSQLI_REPORT_OFF', 0);
        define('MYSQLI_REPORT_ERROR', 1);
        define('MYSQLI_REPORT_STRICT', 2);
        define('MYSQLI_REPORT_INDEX', 4);
        define('MYSQLI_REPORT_ALL', 255);
    }
    if (!function_exists('mysqli_report')) {
        function mysqli_report($flags)
        {
            \IcePlayground\SqliteMysqli::$reportMode = (int) $flags;
            return true;
        }
    }
}

namespace IcePlayground {

    /** Thrown on SQL errors when error reporting is on (the PHP 8.1+ mysqli default). */
    class SqlException extends \RuntimeException
    {
        public $sqlState = 'HY000';
    }

    class SqliteMysqli
    {
        /** MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, the PHP 8.1+ default. */
        public static $reportMode = 3;

        public $connect_errno = 0;
        public $connect_error = null;
        public $errno = 0;
        public $error = '';
        public $error_list = [];
        public $sqlstate = '00000';
        public $insert_id = 0;
        public $affected_rows = 0;
        public $field_count = 0;
        public $server_info = '8.0.38-mysql-on-sqlite';
        public $server_version = 80038;
        public $client_info = 'mysql-on-sqlite';
        public $host_info = 'SQLite via mysql-on-sqlite';
        public $info = null;

        /** @var \WP_MySQL_On_SQLite */
        private $pdo;
        private $inTransaction = false;

        public function __construct($path, $dbname = 'icehrm')
        {
            $dsn = 'mysql-on-sqlite:path=' . str_replace(';', ';;', $path)
                . ';dbname=' . str_replace(';', ';;', $dbname);
            try {
                $this->pdo = new \WP_MySQL_On_SQLite($dsn, null, null, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    // WAL needs shared memory, which the WebAssembly filesystem lacks.
                    'sqlite_journal_mode' => 'DELETE',
                ]);
                // SQLite only allows foreign keys to reference a PRIMARY KEY or UNIQUE
                // column and otherwise fails every write with "foreign key mismatch".
                // IceHrm references non-unique columns (e.g. Country.code), which MySQL
                // accepts, so run without enforcement like a MySQL dump import does.
                $this->pdo->get_connection()->query('PRAGMA foreign_keys = OFF');
            } catch (\Throwable $e) {
                $this->connect_errno = 2002;
                $this->connect_error = $e->getMessage();
            }
        }

        /** The underlying MySQL-on-SQLite PDO object. */
        public function getPdo()
        {
            return $this->pdo;
        }

        public static function reportMode()
        {
            if (class_exists('\mysqli_driver', false)) {
                return (new \mysqli_driver())->report_mode;
            }
            return self::$reportMode;
        }

        public function prepare($sql)
        {
            return new SqliteMysqliStmt($this, (string) $sql);
        }

        public function query($sql, $resultMode = 0)
        {
            return $this->run((string) $sql, false);
        }

        public function real_query($sql)
        {
            return $this->run((string) $sql, false) !== false;
        }

        public function real_escape_string($value)
        {
            return substr($this->pdo->quote((string) $value), 1, -1);
        }

        public function escape_string($value)
        {
            return $this->real_escape_string($value);
        }

        public function quoteValue($value)
        {
            return $this->pdo->quote((string) $value);
        }

        public function set_charset($charset)
        {
            return true;
        }

        public function character_set_name()
        {
            return 'utf8mb4';
        }

        public function select_db($dbname)
        {
            return true;
        }

        public function ping()
        {
            return true;
        }

        public function get_server_info()
        {
            return $this->server_info;
        }

        public function begin_transaction($flags = 0, $name = null)
        {
            if (!$this->inTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
            return true;
        }

        public function autocommit($enable)
        {
            if ($enable) {
                return $this->commit();
            }
            return $this->begin_transaction();
        }

        public function commit($flags = 0, $name = null)
        {
            if ($this->inTransaction) {
                $this->pdo->commit();
                $this->inTransaction = false;
            }
            return true;
        }

        public function rollback($flags = 0, $name = null)
        {
            if ($this->inTransaction) {
                $this->pdo->rollBack();
                $this->inTransaction = false;
            }
            return true;
        }

        public function more_results()
        {
            return false;
        }

        public function next_result()
        {
            return false;
        }

        public function close()
        {
            return true;
        }

        /**
         * Run one SQL statement.
         *
         * @param string $sql
         * @param bool   $nativeTypes true for prepared-statement results, which mysqlnd
         *                            returns with native int/float values; plain
         *                            query() results are all strings.
         * @return SqliteMysqliResult|bool result for row-returning statements, true for
         *                                 others, false on error (when reporting is off)
         */
        public function run($sql, $nativeTypes)
        {
            $this->errno = 0;
            $this->error = '';
            $this->error_list = [];
            $this->sqlstate = '00000';
            $this->field_count = 0;

            if (stripos($sql, 'TIMESTAMPDIFF') !== false) {
                $sql = self::rewriteTimestampDiff($sql);
            }
            if (stripos($sql, 'AUTO_INCREMENT') !== false) {
                $sql = self::rewriteAutoIncrementLookup($sql);
            }
            if (stripos($sql, 'NOT NULL') !== false && preg_match('/^\s*ALTER\s+TABLE\b/i', $sql)) {
                $sql = self::addImplicitDefault($sql);
            }

            try {
                $stmt = $this->pdo->query($sql);
                $columns = $stmt->columnCount();
                if ($columns > 0) {
                    $names = [];
                    $types = [];
                    for ($i = 0; $i < $columns; $i++) {
                        $meta = $stmt->getColumnMeta($i);
                        $names[] = is_array($meta) && isset($meta['name']) ? $meta['name'] : (string) $i;
                        $types[] = is_array($meta) && isset($meta['native_type']) ? strtoupper($meta['native_type']) : '';
                    }
                    $rows = $stmt->fetchAll(\PDO::FETCH_NUM);
                    $this->field_count = $columns;
                    $this->affected_rows = count($rows);
                    return new SqliteMysqliResult($names, $types, $rows, $nativeTypes);
                }

                $this->affected_rows = $stmt->rowCount();
                $this->insert_id = preg_match('/^\s*(INSERT|REPLACE)\b/i', $sql)
                    ? (int) $this->pdo->lastInsertId()
                    : 0;
                return true;
            } catch (\Throwable $e) {
                $info = ($e instanceof \PDOException && is_array($e->errorInfo)) ? $e->errorInfo : [];
                $this->sqlstate = isset($info[0]) ? (string) $info[0] : 'HY000';
                $this->errno = isset($info[1]) && $info[1] ? (int) $info[1] : 1064;
                $this->error = $e->getMessage();
                $this->error_list = [['errno' => $this->errno, 'sqlstate' => $this->sqlstate, 'error' => $this->error]];
                $this->affected_rows = -1;

                if (self::reportMode() & MYSQLI_REPORT_ERROR) {
                    $message = $this->error . ' [query: ' . substr($sql, 0, 500) . ']';
                    if (class_exists('\mysqli_sql_exception', false)) {
                        throw new \mysqli_sql_exception($message, $this->errno);
                    }
                    $ex = new SqlException($message, $this->errno);
                    $ex->sqlState = $this->sqlstate;
                    throw $ex;
                }
                return false;
            }
        }

        /**
         * Replace "?" placeholders with quoted values, skipping quoted strings,
         * backtick identifiers and comments.
         */
        /**
         * The translator's information_schema.TABLES has no AUTO_INCREMENT column.
         * IceHrm reads it to get a table's next id (for generated employee numbers),
         * so answer "SELECT AUTO_INCREMENT ... WHERE TABLE_NAME = 'X'" with MAX(id) + 1.
         */
        public static function rewriteAutoIncrementLookup($sql)
        {
            $pattern = '/^\s*SELECT\s+AUTO_INCREMENT(?:\s+AS\s+`?(\w+)`?)?\s+FROM\s+information_schema\.TABLES\b'
                . '.*\bTABLE_NAME\s*=\s*\'(\w+)\'/is';
            if (!preg_match($pattern, $sql, $m)) {
                return $sql;
            }
            $alias = $m[1] !== '' ? $m[1] : 'AUTO_INCREMENT';
            return 'SELECT COALESCE(MAX(`id`), 0) + 1 AS `' . $alias . '` FROM `' . $m[2] . '`';
        }

        /**
         * "ALTER TABLE t ADD COLUMN c <type> NOT NULL" with no DEFAULT works in MySQL on
         * a table that has rows (they get the type's implicit default: '' or 0) but fails
         * in SQLite, which has no value to give them. Spell that default out. Only single
         * ADD COLUMN statements with a string or numeric type are rewritten.
         */
        public static function addImplicitDefault($sql)
        {
            $pattern = '/^(\s*ALTER\s+TABLE\s+`?\w+`?\s+ADD\s+(?:COLUMN\s+)?`?\w+`?\s+(\w+)(?:\s*\([^)]*\))?'
                . '[^,;]*?\bNOT\s+NULL\b)([^,;]*;?\s*)$/is';
            if (!preg_match($pattern, $sql, $m)
                || preg_match('/\b(DEFAULT|AUTO_INCREMENT|PRIMARY|UNIQUE)\b/i', $sql)) {
                return $sql;
            }
            $type = strtoupper($m[2]);
            if (preg_match('/^(VARCHAR|CHAR|TINYTEXT|TEXT|MEDIUMTEXT|LONGTEXT)$/', $type)) {
                $default = "''";
            } elseif (preg_match('/^(TINYINT|SMALLINT|MEDIUMINT|INT|INTEGER|BIGINT|DECIMAL|NUMERIC|FLOAT|DOUBLE)$/', $type)) {
                $default = '0';
            } else {
                return $sql;
            }
            return $m[1] . ' DEFAULT ' . $default . $m[3];
        }

        /**
         * The MySQL-on-SQLite translator does not support TIMESTAMPDIFF(unit, a, b)
         * (it reads the unit as a column). Rewrite it to UNIX_TIMESTAMP arithmetic,
         * which it does support. Arguments may contain nested calls and strings.
         */
        public static function rewriteTimestampDiff($sql)
        {
            static $seconds = [
                'SECOND' => 1, 'MINUTE' => 60, 'HOUR' => 3600, 'DAY' => 86400, 'WEEK' => 604800,
            ];
            $offset = 0;
            while (preg_match('/\bTIMESTAMPDIFF\s*\(/i', $sql, $m, PREG_OFFSET_CAPTURE, $offset)) {
                $start = $m[0][1];
                $open = $start + strlen($m[0][0]) - 1;
                // Split the arguments on top-level commas.
                $args = [];
                $depth = 0;
                $quote = null;
                $from = $open + 1;
                $end = null;
                for ($i = $from, $n = strlen($sql); $i < $n; $i++) {
                    $c = $sql[$i];
                    if ($quote !== null) {
                        if ($c === '\\') {
                            $i++;
                        } elseif ($c === $quote) {
                            $quote = null;
                        }
                        continue;
                    }
                    if ($c === "'" || $c === '"' || $c === '`') {
                        $quote = $c;
                    } elseif ($c === '(') {
                        $depth++;
                    } elseif ($c === ')') {
                        if ($depth === 0) {
                            $args[] = substr($sql, $from, $i - $from);
                            $end = $i;
                            break;
                        }
                        $depth--;
                    } elseif ($c === ',' && $depth === 0) {
                        $args[] = substr($sql, $from, $i - $from);
                        $from = $i + 1;
                    }
                }
                $unit = $end !== null && count($args) === 3 ? strtoupper(trim($args[0])) : null;
                if ($unit === null || !isset($seconds[$unit])) {
                    $offset = $open + 1; // leave MONTH/YEAR or malformed calls alone
                    continue;
                }
                $diff = '(UNIX_TIMESTAMP(' . trim($args[2]) . ') - UNIX_TIMESTAMP(' . trim($args[1]) . '))';
                $replacement = $seconds[$unit] === 1 ? $diff : '(CAST(' . $diff . ' / ' . $seconds[$unit] . ' AS SIGNED))';
                $sql = substr($sql, 0, $start) . $replacement . substr($sql, $end + 1);
                $offset = $start + strlen($replacement);
            }
            return $sql;
        }

        public function interpolate($sql, array $values, $types)
        {
            $out = '';
            $index = 0;
            $length = strlen($sql);
            $i = 0;

            while ($i < $length) {
                $c = $sql[$i];

                if ($c === "'" || $c === '"' || $c === '`') {
                    $j = $i + 1;
                    while ($j < $length) {
                        if ($sql[$j] === '\\' && $c !== '`') {
                            $j += 2;
                            continue;
                        }
                        if ($sql[$j] === $c) {
                            if ($j + 1 < $length && $sql[$j + 1] === $c) { // doubled quote
                                $j += 2;
                                continue;
                            }
                            break;
                        }
                        $j++;
                    }
                    $out .= substr($sql, $i, $j - $i + 1);
                    $i = $j + 1;
                    continue;
                }

                if (($c === '-' && substr($sql, $i, 3) === '-- ') || $c === '#') {
                    $end = strpos($sql, "\n", $i);
                    $end = $end === false ? $length : $end;
                    $out .= substr($sql, $i, $end - $i);
                    $i = $end;
                    continue;
                }

                if ($c === '/' && substr($sql, $i, 2) === '/*') {
                    $end = strpos($sql, '*/', $i + 2);
                    $end = $end === false ? $length : $end + 2;
                    $out .= substr($sql, $i, $end - $i);
                    $i = $end;
                    continue;
                }

                if ($c === '?') {
                    if (!array_key_exists($index, $values)) {
                        throw new SqlException('No data supplied for parameters in prepared statement', 2031);
                    }
                    $type = isset($types[$index]) ? $types[$index] : 's';
                    // MySQL accepts a string bound to LIMIT/OFFSET ("LIMIT ?" bound with 's',
                    // as the ORM does), but the SQL text "LIMIT '10'" does not parse.
                    if (preg_match('/^\d+$/', (string) $values[$index])
                        && preg_match('/\b(LIMIT|OFFSET)\s*$|\bLIMIT\s+\d+\s*,\s*$/i', $out)) {
                        $type = 'i';
                    }
                    $out .= $this->literal($values[$index], $type);
                    $index++;
                    $i++;
                    continue;
                }

                $out .= $c;
                $i++;
            }

            return $out;
        }

        private function literal($value, $type)
        {
            if ($value === null) {
                return 'NULL';
            }
            if ($type === 'i') {
                return (string) (int) $value;
            }
            if ($type === 'd') {
                return var_export((float) $value, true);
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '';
            }
            return $this->pdo->quote((string) $value);
        }
    }

    class SqliteMysqliStmt
    {
        public $errno = 0;
        public $error = '';
        public $error_list = [];
        public $sqlstate = '00000';
        public $insert_id = 0;
        public $affected_rows = 0;
        public $num_rows = 0;
        public $field_count = 0;
        public $param_count = 0;

        private $conn;
        private $sql;
        private $types = '';
        private $params = [];
        private $result = null;

        public function __construct(SqliteMysqli $conn, $sql)
        {
            $this->conn = $conn;
            $this->sql = $sql;
            $this->param_count = substr_count($sql, '?');
        }

        public function bind_param($types, &...$vars)
        {
            $this->types = (string) $types;
            $this->params = [];
            foreach ($vars as $k => &$v) {
                $this->params[$k] = &$v;
            }
            return true;
        }

        public function execute($params = null)
        {
            $values = [];
            $types = $this->types;
            if (is_array($params)) {
                $values = array_values($params);
                $types = str_repeat('s', count($values));
            } else {
                foreach ($this->params as $v) {
                    $values[] = $v;
                }
            }

            $this->result = null;
            try {
                $sql = $this->conn->interpolate($this->sql, $values, $types);
            } catch (SqlException $e) {
                $this->errno = $e->getCode();
                $this->error = $e->getMessage();
                if (SqliteMysqli::reportMode() & MYSQLI_REPORT_ERROR) {
                    throw $e;
                }
                return false;
            }

            $outcome = $this->conn->run($sql, true);

            $this->errno = $this->conn->errno;
            $this->error = $this->conn->error;
            $this->error_list = $this->conn->error_list;
            $this->sqlstate = $this->conn->sqlstate;
            $this->insert_id = $this->conn->insert_id;
            $this->affected_rows = $this->conn->affected_rows;
            $this->field_count = $this->conn->field_count;

            if ($outcome === false) {
                return false;
            }
            if ($outcome instanceof SqliteMysqliResult) {
                $this->result = $outcome;
                $this->num_rows = $outcome->num_rows;
            }
            return true;
        }

        /** Like mysqlnd: a result for row-returning statements, false otherwise. */
        public function get_result()
        {
            return $this->result === null ? false : $this->result;
        }

        public function store_result()
        {
            return true;
        }

        public function free_result()
        {
            $this->result = null;
        }

        public function result_metadata()
        {
            return $this->result === null ? false : $this->result;
        }

        public function reset()
        {
            return true;
        }

        public function close()
        {
            $this->result = null;
            return true;
        }
    }

    class SqliteMysqliResult implements \IteratorAggregate, \Countable
    {
        public $num_rows = 0;
        public $field_count = 0;
        public $current_field = 0;
        public $lengths = null;
        public $type = 0;

        private $names;
        private $rows;
        private $position = 0;
        private $fieldPosition = 0;

        private static $intTypes = ['TINY', 'SHORT', 'LONG', 'LONGLONG', 'INT24', 'INTEGER', 'INT', 'BIGINT', 'SMALLINT', 'TINYINT', 'MEDIUMINT', 'YEAR'];
        private static $floatTypes = ['FLOAT', 'DOUBLE', 'REAL'];

        public function __construct(array $names, array $types, array $rows, $nativeTypes)
        {
            $this->names = $names;
            $this->field_count = count($names);
            foreach ($rows as &$row) {
                foreach ($row as $i => &$value) {
                    if ($value === null) {
                        continue;
                    }
                    if (!$nativeTypes) {
                        // mysqli query() results are always strings.
                        $value = (string) $value;
                        continue;
                    }
                    // Prepared-statement results: mysqlnd returns ints and floats natively.
                    // Columns of unknown type are left as the driver returned them.
                    $type = isset($types[$i]) ? $types[$i] : '';
                    if (in_array($type, self::$intTypes, true) && is_numeric($value)) {
                        $value = (int) $value;
                    } elseif (in_array($type, self::$floatTypes, true) && is_numeric($value)) {
                        $value = (float) $value;
                    }
                }
                unset($value);
            }
            unset($row);
            $this->rows = $rows;
            $this->num_rows = count($rows);
        }

        private function assoc(array $row)
        {
            $out = [];
            foreach ($this->names as $i => $name) {
                $out[$name] = $row[$i];
            }
            return $out;
        }

        private function next()
        {
            if ($this->position >= $this->num_rows) {
                return null;
            }
            return $this->rows[$this->position++];
        }

        public function fetch_assoc()
        {
            $row = $this->next();
            return $row === null ? null : $this->assoc($row);
        }

        public function fetch_row()
        {
            return $this->next();
        }

        public function fetch_array($mode = MYSQLI_BOTH)
        {
            $row = $this->next();
            if ($row === null) {
                return null;
            }
            if ($mode === MYSQLI_NUM) {
                return $row;
            }
            if ($mode === MYSQLI_ASSOC) {
                return $this->assoc($row);
            }
            return $row + $this->assoc($row);
        }

        public function fetch_all($mode = MYSQLI_NUM)
        {
            $out = [];
            while (($row = $this->fetch_array($mode)) !== null) {
                $out[] = $row;
            }
            return $out;
        }

        public function fetch_object($class = 'stdClass', array $args = [])
        {
            $row = $this->fetch_assoc();
            if ($row === null) {
                return null;
            }
            $obj = $class === 'stdClass' ? new \stdClass() : new $class(...$args);
            foreach ($row as $k => $v) {
                $obj->$k = $v;
            }
            return $obj;
        }

        public function fetch_column($column = 0)
        {
            $row = $this->next();
            return $row === null ? false : $row[$column];
        }

        public function fetch_fields()
        {
            $fields = [];
            foreach ($this->names as $name) {
                $fields[] = (object) ['name' => $name, 'orgname' => $name, 'table' => '', 'orgtable' => '', 'def' => '', 'db' => '', 'catalog' => 'def', 'max_length' => 0, 'length' => 0, 'charsetnr' => 0, 'flags' => 0, 'type' => 253, 'decimals' => 0];
            }
            return $fields;
        }

        public function fetch_field()
        {
            $fields = $this->fetch_fields();
            if ($this->fieldPosition >= count($fields)) {
                return false;
            }
            return $fields[$this->fieldPosition++];
        }

        public function data_seek($offset)
        {
            if ($offset < 0 || $offset >= $this->num_rows) {
                return false;
            }
            $this->position = (int) $offset;
            return true;
        }

        public function free()
        {
        }

        public function close()
        {
        }

        public function free_result()
        {
        }

        public function count(): int
        {
            return $this->num_rows;
        }

        public function getIterator(): \Iterator
        {
            foreach ($this->rows as $row) {
                yield $this->assoc($row);
            }
        }
    }
}
