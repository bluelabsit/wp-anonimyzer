#!/usr/bin/env php
<?php
/**
 * wp-anonymizer.php
 *
 * Exports an anonymized copy of a WordPress/WooCommerce shop database
 * WITHOUT EVER writing to the production database.
 *
 * Flow: preflight -> wizard -> read-only dump -> temporary schema ->
 *       anonymization -> verification -> final dump -> cleanup.
 *
 * Requirements: PHP >= 7.4 CLI, WP-CLI, mysql/mysqldump client.
 * Usage: php wp-anonymizer.php [--path=/var/www/shop] [--dry-run] [--output-dir=./anon-export]
 */

declare(strict_types=1);

const APP_VERSION  = '1.0.0';
const TMP_PREFIX   = 'anon_tmp_';
const FAKE_DOMAIN  = 'example.invalid';
const FAKE_IP      = '203.0.113.7';   // TEST-NET-3, RFC 5737
const DICT_N       = 32;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Eseguire da riga di comando.\n");
    exit(1);
}
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    fwrite(STDERR, "Serve PHP >= 7.4 (rilevato " . PHP_VERSION . ").\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Global state used by cleanup routine
// ---------------------------------------------------------------------------
$GLOBALS['CTX'] = [
        'defaults_file' => null,
        'tmp_db' => null,
        'tmp_db_owned' => false,
        'prepared_cleanup' => 'empty',
        'tmp_files' => [],
        'tmp_dirs' => [],
        'mysql' => 'mysql',
        'cleaning' => false,
        'cleaned' => false,
];

$hasPcntl = function_exists('pcntl_signal') && function_exists('pcntl_async_signals');
register_shutdown_function('cleanup');
if ($hasPcntl) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () { out("\nInterrupted by the user."); exit(130); });
    pcntl_signal(SIGTERM, function () { exit(143); });
}

// ---------------------------------------------------------------------------
// Output helpers
// ---------------------------------------------------------------------------
function c(string $s, string $color): string
{
    $codes = ['red' => '0;31', 'green' => '0;32', 'yellow' => '0;33', 'blue' => '0;34', 'bold' => '1'];
    if (getenv('NO_COLOR') !== false || !stream_isatty(STDOUT)) return $s;
    return "\033[" . ($codes[$color] ?? '0') . "m" . $s . "\033[0m";
}
function out(string $s = ''): void   { fwrite(STDOUT, $s . "\n"); }
function step(string $s): void       { out("\n" . c('▸ ' . $s, 'bold')); }
function ok(string $s): void         { out('  ' . c('✔', 'green') . ' ' . $s); }
function warn(string $s): void       { out('  ' . c('!', 'yellow') . ' ' . $s); }
function fail(string $s): void       { fwrite(STDERR, "\n" . c('✘ ' . $s, 'red') . "\n"); exit(1); }
function hr(): void                  { out(str_repeat('─', 72)); }

function str_has(string $haystack, string $needle): bool
{
    return $needle === '' || strpos($haystack, $needle) !== false;
}

// ---------------------------------------------------------------------------
// Interactive input
// ---------------------------------------------------------------------------
function prompt(string $question, string $default = ''): string
{
    $suffix = $default !== '' ? ' [' . $default . ']' : '';
    fwrite(STDOUT, '  ' . $question . $suffix . ': ');
    $line = fgets(STDIN);
    if ($line === false) fail('No input available: this script requires an interactive terminal.');
    $line = trim($line);
    return $line === '' ? $default : $line;
}

function confirm(string $question, bool $default = true): bool
{
    $hint = $default ? 'Y/n' : 'y/N';
    while (true) {
        $a = strtolower(prompt($question . ' (' . $hint . ')'));
        if ($a === '') return $default;
        if (in_array($a, ['y', 'yes'], true)) return true;
        if (in_array($a, ['n', 'no'], true)) return false;
    }
}

/**
 * Same as choose(), but accepts a "!" suffix to apply the answer to every
 * remaining item. Returns ['index' => int, 'all' => bool].
 */
function chooseSticky(string $question, array $options, int $default = 0): array
{
    out('  ' . $question);
    foreach ($options as $i => $o) {
        out('    ' . ($i + 1) . ') ' . $o . ($i === $default ? c('  (default)', 'blue') : ''));
    }
    out('    ' . c('Append ! to apply the answer to every remaining item (e.g. 1!)', 'blue'));
    while (true) {
        $a   = trim(prompt('Choice', (string)($default + 1)));
        $all = false;
        if (substr($a, -1) === '!') { $all = true; $a = trim(substr($a, 0, -1)); }
        if ($a === '') $a = (string)($default + 1);
        $n = (int)$a;
        if ($n >= 1 && $n <= count($options)) return ['index' => $n - 1, 'all' => $all];
    }
}

function choose(string $question, array $options, int $default = 0): int
{
    out('  ' . $question);
    foreach ($options as $i => $o) {
        out('    ' . ($i + 1) . ') ' . $o . ($i === $default ? c('  (default)', 'blue') : ''));
    }
    while (true) {
        $a = prompt('Choice', (string)($default + 1));
        $n = (int)$a;
        if ($n >= 1 && $n <= count($options)) return $n - 1;
    }
}

// ---------------------------------------------------------------------------
// Command execution, SQL/file safety and cleanup
// ---------------------------------------------------------------------------
function sh(string $cmd, ?array &$output = null, ?array &$errors = null): int
{
    $output = [];
    $errors = [];
    $code = 0;
    $errFile = tempnam(sys_get_temp_dir(), 'anonerr_');
    if ($errFile === false) {
        exec($cmd, $output, $code);
        return $code;
    }
    exec($cmd . ' 2>' . escapeshellarg($errFile), $output, $code);
    $raw = (string)file_get_contents($errFile);
    @unlink($errFile);
    if ($raw !== '') $errors = explode("\n", rtrim($raw, "\n"));
    return $code;
}

function diag(array $out, array $err): string
{
    $all = array_filter(array_merge($out, $err), function ($line) {
        return trim((string)$line) !== '';
    });
    return $all ? "  " . implode("\n  ", $all) : '  (no output)';
}

function which(string $bin): ?string
{
    $output = [];
    if (sh('command -v ' . escapeshellarg($bin), $output) === 0 && !empty($output[0])) {
        return trim((string)$output[0]);
    }
    return null;
}

function sqlIdentifier(string $identifier): string
{
    if ($identifier === '' || strpos($identifier, "\0") !== false) {
        fail('Invalid empty or NUL-containing SQL identifier.');
    }
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function sqlString(string $value): string
{
    if ($value === '') return "''";
    // Hex encoding makes the literal independent of NO_BACKSLASH_ESCAPES and
    // prevents database-derived metadata keys from altering generated SQL.
    // An explicit binary collation also avoids coercion failures when a site
    // uses unicode_520_ci, 0900_ai_ci, or another utf8mb4 column collation.
    return 'CONVERT(0x' . bin2hex($value) . ' USING utf8mb4) COLLATE utf8mb4_bin';
}

function encodeJsonOrFail($value, int $flags = 0): string
{
    $json = json_encode($value, $flags);
    if ($json === false) fail('Could not encode JSON: ' . json_last_error_msg());
    return $json;
}

function assertTempSchemaName(string $schema): void
{
    if (strlen($schema) > 64 || !preg_match('/^' . TMP_PREFIX . '[A-Za-z0-9_]+$/', $schema)) {
        fail('Working schema must match ' . TMP_PREFIX . '[A-Za-z0-9_]+ and be at most 64 characters.');
    }
}

function ensurePrivateDirectory(string $dir): void
{
    if (is_link($dir)) fail('Output directory must not be a symlink: ' . $dir);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) fail('Could not create ' . $dir);
    if (!@chmod($dir, 0700)) fail('Could not restrict output directory permissions: ' . $dir);
    $mode = @fileperms($dir);
    if ($mode === false || (($mode & 0777) !== 0700)) fail('Output directory must have mode 0700: ' . $dir);
}

function createPrivateFile(string $file): void
{
    if (is_link($file) || file_exists($file)) fail('Refusing to overwrite existing path: ' . $file);
    $oldUmask = umask(0177);
    $handle = @fopen($file, 'x');
    umask($oldUmask);
    if ($handle === false) fail('Could not create private file: ' . $file);
    if (!@chmod($file, 0600)) {
        fclose($handle);
        @unlink($file);
        fail('Could not restrict file permissions: ' . $file);
    }
    if (!fclose($handle)) {
        @unlink($file);
        fail('Could not close private file: ' . $file);
    }
}

function writePrivateFile(string $file, string $contents): void
{
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(6));
    createPrivateFile($tmp);
    $GLOBALS['CTX']['tmp_files'][] = $tmp;
    $handle = @fopen($tmp, 'wb');
    if ($handle === false) fail('Could not open private file for writing: ' . $tmp);
    $length = strlen($contents);
    $offset = 0;
    while ($offset < $length) {
        $written = fwrite($handle, substr($contents, $offset));
        if ($written === false || $written === 0) {
            fclose($handle);
            fail('Could not completely write ' . $tmp);
        }
        $offset += $written;
    }
    $flushed = fflush($handle);
    $closed = fclose($handle);
    if (!$flushed || !$closed) fail('Could not finalize ' . $tmp);
    if (is_link($file) || !@rename($tmp, $file)) fail('Could not publish ' . $file);
    $GLOBALS['CTX']['tmp_files'] = array_values(array_diff($GLOBALS['CTX']['tmp_files'], [$tmp]));
    if (!@chmod($file, 0600)) {
        $GLOBALS['CTX']['tmp_files'][] = $file;
        fail('Could not restrict file permissions: ' . $file);
    }
}

function mysqlCmd(?string $db = null): string
{
    $ctx = $GLOBALS['CTX'];
    if (!$ctx['defaults_file'] || !is_file($ctx['defaults_file'])) fail('MySQL credentials file is unavailable.');
    $cmd = escapeshellarg($ctx['mysql'])
        . ' --defaults-extra-file=' . escapeshellarg($ctx['defaults_file'])
        . ' --default-character-set=utf8mb4';
    if ($db !== null) $cmd .= ' ' . escapeshellarg($db);
    return $cmd;
}

function queryRows(string $sql, ?string $db, ?array &$errors = null): ?array
{
    $statement = trim($sql);
    if (!preg_match('/^(SELECT|SHOW)\b/i', $statement) || strpos(rtrim($statement, ';'), ';') !== false) {
        $errors = ['Rejected non-read-only or multi-statement query.'];
        return null;
    }
    $output = [];
    $err = [];
    $code = sh(mysqlCmd($db) . ' -N -B -e ' . escapeshellarg($statement), $output, $err);
    if ($code !== 0) {
        $errors = array_merge($output, $err);
        return null;
    }
    $rows = [];
    foreach ($output as $line) {
        if ($line === '') continue;
        $rows[] = explode("\t", $line);
    }
    $errors = [];
    return $rows;
}

function q(string $sql, ?string $db = null): array
{
    $errors = [];
    $rows = queryRows($sql, $db, $errors);
    if ($rows === null) fail("Read-only query failed:\n  " . $sql . "\n" . diag([], $errors));
    return $rows;
}

function qScalar(string $sql, ?string $db = null, string $fallback = ''): string
{
    $rows = q($sql, $db);
    return isset($rows[0][0]) ? $rows[0][0] : $fallback;
}

function runMysqlSql(string $sql, ?string $db = null, ?array &$errors = null): bool
{
    $output = [];
    $err = [];
    $code = sh(mysqlCmd($db) . ' -e ' . escapeshellarg($sql), $output, $err);
    $errors = array_merge($output, $err);
    return $code === 0;
}

function execSqlFile(string $file, string $db): void
{
    $ctx = $GLOBALS['CTX'];
    assertTempSchemaName($db);
    if ($ctx['tmp_db'] !== $db) fail('Refusing SQL execution outside registered temporary schema.');
    $output = [];
    $errors = [];
    if (sh(mysqlCmd($db) . ' < ' . escapeshellarg($file), $output, $errors) !== 0) {
        fail("SQL execution failed (" . basename($file) . "):\n" . diag($output, $errors));
    }
}

function purgeSchema(string $schema): bool
{
    assertTempSchemaName($schema);
    $errors = [];
    $rows = queryRows(
        'SELECT table_name, table_type FROM information_schema.tables WHERE table_schema=' . sqlString($schema),
        null,
        $errors
    );
    if ($rows === null) {
        warn('Could not inventory working schema during cleanup:' . diag([], $errors));
        return false;
    }
    $routines = queryRows(
        'SELECT routine_name, routine_type FROM information_schema.routines WHERE routine_schema=' . sqlString($schema),
        null,
        $errors
    );
    $events = queryRows(
        'SELECT event_name FROM information_schema.events WHERE event_schema=' . sqlString($schema),
        null,
        $errors
    );
    if ($routines === null || $events === null) {
        warn('Could not inventory working schema routines/events during cleanup:' . diag([], $errors));
        return false;
    }
    $views = [];
    $tables = [];
    foreach ($rows as $row) {
        if (($row[1] ?? '') === 'VIEW') $views[] = sqlIdentifier($row[0]);
        else $tables[] = sqlIdentifier($row[0]);
    }
    foreach ([['DROP VIEW IF EXISTS ', $views], ['DROP TABLE IF EXISTS ', $tables]] as $drop) {
        foreach (array_chunk($drop[1], 50) as $chunk) {
            if (!$chunk) continue;
            $sql = 'SET FOREIGN_KEY_CHECKS=0; ' . $drop[0] . implode(',', $chunk) . '; SET FOREIGN_KEY_CHECKS=1';
            if (!runMysqlSql($sql, $schema, $errors)) {
                warn('Working schema cleanup command failed:' . diag([], $errors));
                return false;
            }
        }
    }
    foreach ($routines as $routine) {
        $type = strtoupper((string)($routine[1] ?? ''));
        if (!in_array($type, ['FUNCTION', 'PROCEDURE'], true)) {
            warn('Unexpected routine type during cleanup: ' . $type);
            return false;
        }
        $sql = 'DROP ' . $type . ' IF EXISTS '
            . sqlIdentifier($schema) . '.' . sqlIdentifier((string)$routine[0]);
        if (!runMysqlSql($sql, null, $errors)) {
            warn('Working schema routine cleanup failed:' . diag([], $errors));
            return false;
        }
    }
    foreach ($events as $event) {
        $sql = 'DROP EVENT IF EXISTS '
            . sqlIdentifier($schema) . '.' . sqlIdentifier((string)$event[0]);
        if (!runMysqlSql($sql, null, $errors)) {
            warn('Working schema event cleanup failed:' . diag([], $errors));
            return false;
        }
    }
    $remaining = queryRows(
        'SELECT '
        . '(SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=' . sqlString($schema) . ') + '
        . '(SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema=' . sqlString($schema) . ') + '
        . '(SELECT COUNT(*) FROM information_schema.events WHERE event_schema=' . sqlString($schema) . ')',
        null,
        $errors
    );
    return $remaining !== null && (int)($remaining[0][0] ?? -1) === 0;
}

function cleanupDatabase(): bool
{
    $ctx = &$GLOBALS['CTX'];
    if (!$ctx['tmp_db']) return true;
    $schema = $ctx['tmp_db'];
    assertTempSchemaName($schema);
    $dropRequested = $ctx['tmp_db_owned'] || $ctx['prepared_cleanup'] === 'drop';
    $dropped = false;
    $errors = [];
    if ($dropRequested) {
        for ($attempt = 0; $attempt < 3 && !$dropped; $attempt++) {
            $dropped = runMysqlSql('DROP DATABASE IF EXISTS ' . sqlIdentifier($schema), null, $errors);
        }
    }
    if (!$dropped && !purgeSchema($schema)) return false;
    if ($dropRequested && !$dropped) {
        warn('Schema was emptied but could not be dropped: ' . $schema);
        return false;
    }
    $ctx['tmp_db'] = null;
    return true;
}

function cleanup(): void
{
    $ctx = &$GLOBALS['CTX'];
    if ($ctx['cleaned'] || $ctx['cleaning']) return;
    $ctx['cleaning'] = true;
    $ok = cleanupDatabase();
    foreach (array_reverse(array_unique($ctx['tmp_files'])) as $file) {
        if (!file_exists($file) && !is_link($file)) continue;
        if (is_file($file)) {
            $handle = @fopen($file, 'r+');
            if ($handle) {
                @ftruncate($handle, 0);
                @fclose($handle);
            }
        }
        if (!@unlink($file)) $ok = false;
    }
    foreach (array_reverse(array_unique($ctx['tmp_dirs'])) as $dir) {
        if (is_dir($dir) && !@rmdir($dir)) $ok = false;
    }
    if ($ctx['defaults_file'] && is_file($ctx['defaults_file'])) {
        if (!@unlink($ctx['defaults_file'])) $ok = false;
    }
    $ctx['defaults_file'] = null;
    $ctx['cleaning'] = false;
    $ctx['cleaned'] = $ok;
    if (!$ok) fwrite(STDERR, "\nCleanup incomplete; inspect the working schema and staging paths above.\n");
}

// ---------------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------------
$opts = [
        'path' => getcwd(),
        'output-dir' => getcwd() . '/anon-export',
        'seed-file' => null,
        'unknown' => null,
        'config' => null,
        'dry-run' => false,
        'allow-unsafe-signals' => false,
];
$valueOptions = ['path', 'output-dir', 'seed-file', 'unknown', 'config'];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') { $opts['dry-run'] = true; continue; }
    if ($arg === '--allow-unsafe-signals') { $opts['allow-unsafe-signals'] = true; continue; }
    if ($arg === '-h' || $arg === '--help') { usage(); exit(0); }
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $match) && in_array($match[1], $valueOptions, true)) {
        $opts[$match[1]] = $match[2];
        continue;
    }
    fail('Unknown argument: ' . $arg);
}

if ($opts['unknown'] !== null && !in_array($opts['unknown'], ['schema', 'copy', 'exclude'], true)) {
    fail('--unknown accepts only: schema, copy, exclude');
}

$replay = [];
if ($opts['config'] !== null) {
    if (!is_file($opts['config']) || is_link($opts['config'])) fail('Configuration file not found or unsafe: ' . $opts['config']);
    $replay = json_decode((string)file_get_contents($opts['config']), true);
    if (!is_array($replay) || ($replay['config_version'] ?? null) !== 1) {
        fail('Unsupported or invalid configuration file: ' . $opts['config']);
    }
}

function usage(): void
{
    out("\n  wp-anonymizer v" . APP_VERSION . "\n" . <<<TXT
Usage:
  php wp-anonymizer.php [options]

  --path=DIR                 WordPress installation root (default: current directory)
  --output-dir=DIR           Private output directory (default: ./anon-export)
  --seed-file=FILE           64-hex-character pseudonymization seed
  --unknown=ACTION           schema, copy or exclude for every unrecognized table
  --config=FILE              Replay a version-1 run-config.json
  --dry-run                  Read-only plan; writes only local run-config.json
  --allow-unsafe-signals     Allow a real run without pcntl (unsafe, recorded)
  -h, --help                 Show this help
TXT
    );
}
// ===========================================================================
// 1. PREFLIGHT
// ===========================================================================
step('Preflight');
out('wp-anonymizer v' . APP_VERSION);
warn('Run this tool only against a disposable workflow with a dedicated private output directory.');

$wpBin = which('wp') ?: which('wp-cli') ?: which('wp-cli.phar');
if (!$wpBin) fail('WP-CLI not found in PATH.');
$mysqlBin = which('mysql') ?: which('mariadb');
$dumpBin = which('mysqldump') ?: which('mariadb-dump');
if (!$mysqlBin) fail('mysql/mariadb client not found in PATH.');
if (!$dumpBin) fail('mysqldump/mariadb-dump not found in PATH.');
if (!function_exists('gzopen')) fail('zlib extension not available in PHP CLI.');
$GLOBALS['CTX']['mysql'] = $mysqlBin;
ok('Binaries: wp, ' . basename($mysqlBin) . ', ' . basename($dumpBin));

if (!$hasPcntl && !$opts['dry-run'] && !$opts['allow-unsafe-signals']) {
    fail('pcntl is required for cleanup on SIGINT/SIGTERM. Use --allow-unsafe-signals only after accepting the risk.');
}
if (!$hasPcntl && $opts['allow-unsafe-signals']) {
    warn('Unsafe signal override active: interruption cleanup cannot be guaranteed.');
}

$wpPath = rtrim((string)$opts['path'], '/');
$GLOBALS['WP_PATH'] = $wpPath;
if (!is_dir($wpPath) || !is_file($wpPath . '/wp-includes/version.php')) {
    fail('WordPress installation files not found in ' . $wpPath);
}
// These WP-CLI config commands run before WordPress bootstrap: no plugins, themes,
// MU-plugins or drop-ins are loaded.
$resolvedWpPath = realpath($wpPath);
if ($resolvedWpPath === false) fail('Could not resolve WordPress path: ' . $wpPath);
$wpPath = $resolvedWpPath;
$GLOBALS['WP_PATH'] = $wpPath;

$wpConfigFile = is_file($wpPath . '/wp-config.php')
    ? $wpPath . '/wp-config.php'
    : dirname($wpPath) . '/wp-config.php';
if (!is_file($wpConfigFile)) {
    fail('wp-config.php not found in the WordPress directory or its parent.');
}
$resolvedWpConfigFile = realpath($wpConfigFile);
if ($resolvedWpConfigFile === false) fail('Could not resolve wp-config.php path.');
$wpConfigDir = dirname($resolvedWpConfigFile);

// Custom configurations may load project files through relative paths.
$wp = 'cd ' . escapeshellarg($wpConfigDir) . ' && '
    . "WP_CLI_PHP_ARGS='-d error_reporting=0 -d display_errors=0' "
    . escapeshellarg($wpBin) . ' --path=' . escapeshellarg($wpPath)
    . ' --skip-plugins --skip-themes';

function wpConfigGet(string $wp, string $key, string $type = 'constant'): string
{
    $capture = sys_get_temp_dir() . '/anonwp_' . bin2hex(random_bytes(8));
    createPrivateFile($capture);
    $GLOBALS['CTX']['tmp_files'][] = $capture;
    $output = [];
    $errors = [];
    $command = $wp . ' config get ' . escapeshellarg($key)
        . ' --type=' . escapeshellarg($type) . ' > ' . escapeshellarg($capture);
    if (sh($command, $output, $errors) !== 0) {
        fail('Could not read ' . $key . " from wp-config.php:\n" . diag($output, $errors));
    }
    $value = @file_get_contents($capture);
    if ($value === false) fail('Could not read private WP-CLI output for ' . $key . '.');
    $handle = @fopen($capture, 'r+');
    if ($handle) {
        @ftruncate($handle, 0);
        @fclose($handle);
    }
    if (!@unlink($capture)) fail('Could not remove private WP-CLI output for ' . $key . '.');
    $GLOBALS['CTX']['tmp_files'] = array_values(array_diff($GLOBALS['CTX']['tmp_files'], [$capture]));
    if (PHP_EOL !== '' && substr($value, -strlen(PHP_EOL)) === PHP_EOL) {
        $value = substr($value, 0, -strlen(PHP_EOL));
    }
    return $value;
}

$db = [
        'name' => wpConfigGet($wp, 'DB_NAME'),
        'user' => wpConfigGet($wp, 'DB_USER'),
        'pass' => wpConfigGet($wp, 'DB_PASSWORD'),
        'host' => wpConfigGet($wp, 'DB_HOST'),
        'prefix' => wpConfigGet($wp, 'table_prefix', 'variable'),
];
foreach (['name' => 'DB_NAME', 'user' => 'DB_USER', 'host' => 'DB_HOST'] as $key => $label) {
    if ($db[$key] === '' || strpos($db[$key], "\0") !== false) fail('Invalid ' . $label . ' read from wp-config.php.');
}
if (strpos($db['pass'], "\0") !== false) fail('Invalid DB_PASSWORD read from wp-config.php.');
if (!preg_match('/^[A-Za-z0-9_]+$/', $db['prefix'])) {
    fail('table_prefix may contain only ASCII letters, digits and underscores.');
}

$hostSpec = $db['host'];
$host = $hostSpec;
$port = '';
$socket = '';
if (preg_match('/^\[([^]]+)\](?::([0-9]+))?$/', $hostSpec, $match)) {
    $host = $match[1];
    $port = $match[2] ?? '';
} elseif (preg_match('/^([^:]+):(\/.*)$/', $hostSpec, $match)) {
    $host = $match[1];
    $socket = $match[2];
} elseif (substr_count($hostSpec, ':') === 1) {
    [$host, $port] = explode(':', $hostSpec, 2);
    if ($port !== '' && !ctype_digit($port)) fail('Invalid DB_HOST port: ' . $port);
}
if ($host === '') $host = 'localhost';

$oldUmask = umask(0177);
$defaultsFile = tempnam(sys_get_temp_dir(), 'anoncnf_');
umask($oldUmask);
if ($defaultsFile === false) fail('Could not create temporary credentials file.');
$GLOBALS['CTX']['defaults_file'] = $defaultsFile;
$escapeCnf = function (string $value): string {
    return '"' . str_replace(
        ['\\', "\n", "\r", "\t", '"'],
        ['\\\\', '\\n', '\\r', '\\t', '\\"'],
        $value
    ) . '"';
};
$cnf = "[client]\n"
    . 'user=' . $escapeCnf($db['user']) . "\n"
    . 'password=' . $escapeCnf($db['pass']) . "\n"
    . 'host=' . $escapeCnf($host) . "\n";
if ($port !== '') $cnf .= 'port=' . (int)$port . "\n";
if ($socket !== '') $cnf .= 'socket=' . $escapeCnf($socket) . "\n";
if (file_put_contents($defaultsFile, $cnf) !== strlen($cnf) || !@chmod($defaultsFile, 0600)) {
    fail('Could not safely write the temporary MySQL credentials file.');
}

ok('DB connection: ' . $db['user'] . '@' . $host . ($port !== '' ? ':' . $port : ''));
$version = qScalar('SELECT VERSION()');
$versionComment = qScalar('SELECT @@version_comment');
$isMariaDB = stripos($version, 'mariadb') !== false || stripos($versionComment, 'mariadb') !== false;
ok('Server: ' . $version . ($isMariaDB ? ' (MariaDB)' : ''));

$dataSize = (int)qScalar(
    'SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema='
    . sqlString($db['name'])
);

function fmtBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float)$bytes;
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }
    return sprintf($index === 0 ? '%.0f %s' : '%.1f %s', $value, $units[$index]);
}
ok('Source data size: ' . fmtBytes($dataSize));
// ===========================================================================
// 2. INVENTORY AND CLASSIFICATION
// ===========================================================================
step('Table inventory');

$p = $db['prefix'];
$tables = [];
foreach (q(
    'SELECT table_name, COALESCE(table_rows,0), COALESCE(data_length+index_length,0) '
    . 'FROM information_schema.tables WHERE table_schema=' . sqlString($db['name'])
    . " AND table_type='BASE TABLE' ORDER BY table_name"
) as $row) {
    $tables[$row[0]] = ['rows' => (int)$row[1], 'size' => (int)$row[2]];
}
if (!$tables) fail('No base tables found in source database.');

$columns = [];
foreach (q(
    'SELECT table_name, column_name FROM information_schema.columns WHERE table_schema=' . sqlString($db['name'])
) as $row) {
    $columns[$row[0]][] = $row[1];
}
$hasTable = function (string $table) use ($tables): bool { return isset($tables[$table]); };
$hasCol = function (string $table, string $column) use ($columns): bool {
    return isset($columns[$table]) && in_array($column, $columns[$table], true);
};

$hpos = $hasTable($p . 'wc_orders');
$legacy = $hasTable($p . 'posts')
    && (int)qScalar(
        "SELECT EXISTS(SELECT 1 FROM " . sqlIdentifier($p . 'posts') . " WHERE post_type LIKE 'shop_order%')",
        $db['name'],
        '0'
    ) > 0;
ok('Order storage: ' . ($hpos ? 'HPOS' : '') . ($hpos && $legacy ? ' + ' : '')
    . ($legacy ? 'legacy posts' : '') . (!$hpos && !$legacy ? 'no orders detected' : ''));

/** Actions: anonymize | copy | schema (structure only) | exclude. */
function classify(string $table, string $prefix): array
{
    $suffix = strpos($table, $prefix) === 0 ? substr($table, strlen($prefix)) : $table;
    $anonymize = [
        'users', 'usermeta', 'posts', 'postmeta', 'comments', 'commentmeta', 'options',
        'wc_orders', 'wc_order_addresses', 'wc_orders_meta', 'wc_order_operational_data',
        'wc_customer_lookup', 'wc_download_log', 'woocommerce_downloadable_product_permissions',
        'woocommerce_order_itemmeta',
    ];
    $schemaOnly = [
        'woocommerce_sessions', 'woocommerce_payment_tokens', 'woocommerce_payment_tokenmeta',
        'woocommerce_api_keys', 'woocommerce_log', 'wc_admin_notes', 'wc_admin_note_actions',
        'wc_webhooks', 'wc_rate_limits', 'wc_reserved_stock',
        'actionscheduler_actions', 'actionscheduler_claims', 'actionscheduler_groups',
        'actionscheduler_logs', 'yoast_indexable', 'yoast_indexable_hierarchy',
        'yoast_seo_links', 'redirection_logs', 'redirection_404', 'wfhits', 'wflogins',
        'statistics_visitor', 'statistics_useronline',
    ];
    $copy = [
        'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'links',
        'woocommerce_order_items', 'woocommerce_attribute_taxonomies',
        'woocommerce_tax_rates', 'woocommerce_tax_rate_locations', 'woocommerce_shipping_zones',
        'woocommerce_shipping_zone_locations', 'woocommerce_shipping_zone_methods',
        'wc_product_meta_lookup', 'wc_tax_rate_classes', 'wc_category_lookup',
        'wc_order_stats', 'wc_order_product_lookup', 'wc_order_tax_lookup', 'wc_order_coupon_lookup',
    ];
    if (in_array($suffix, $anonymize, true)) return ['anonymize', 'catalogued table requiring privacy rules'];
    if (in_array($suffix, $schemaOnly, true)) return ['schema', 'logs, sessions or secrets: structure only'];
    if (in_array($suffix, $copy, true)) return ['copy', 'catalogued structural or catalogue data'];
    return ['schema', 'unrecognized table: cautious default'];
}

$plan = [];
$unknown = [];
foreach ($tables as $table => $stats) {
    [$action, $reason] = classify($table, $p);
    $plan[$table] = [
        'action' => $action,
        'reason' => $reason,
        'rows' => $stats['rows'],
        'size' => $stats['size'],
    ];
    if ($reason === 'unrecognized table: cautious default') $unknown[] = $table;
}

out('');
out(str_pad('TABLE', 42) . str_pad('ROWS', 10) . str_pad('SIZE', 10) . 'ACTION');
foreach ($plan as $table => $item) {
    $label = [
        'anonymize' => c('anonymize', 'green'),
        'copy' => 'copy',
        'schema' => c('structure only', 'yellow'),
        'exclude' => c('excluded', 'red'),
    ][$item['action']];
    out('  ' . str_pad($table, 42) . str_pad((string)$item['rows'], 10)
        . str_pad(fmtBytes($item['size']), 10) . $label);
}
ok(count($plan) . ' tables classified, ' . count($unknown) . ' unrecognized');
// ===========================================================================
// 3. WIZARD
// ===========================================================================
step('Export configuration');

$outputDefault = (string)$opts['output-dir'];
if ($outputDefault === getcwd() . '/anon-export' && isset($replay['output_dir'])) {
    $outputDefault = (string)$replay['output_dir'];
}
$outputDir = prompt('Output directory', $outputDefault);
ensurePrivateDirectory($outputDir);
$free = disk_free_space($outputDir);
$needed = (int)($dataSize * 2.5);
if ($free !== false && $free < $needed) {
    warn('Free space ' . fmtBytes((int)$free) . ', estimated requirement ' . fmtBytes($needed) . '.');
    if (!confirm('Continue anyway?', false)) exit(0);
} else {
    ok('Available space: ' . ($free === false ? 'unknown' : fmtBytes((int)$free)));
}

$ACTIONS = ['schema', 'copy', 'exclude'];
$LABELS = ['Structure only (recommended)', 'Copy all data', 'Exclude entirely'];

function family(string $table, string $prefix): string
{
    $suffix = strpos($table, $prefix) === 0 ? substr($table, strlen($prefix)) : $table;
    $parts = explode('_', $suffix);
    return count($parts) > 1 ? $parts[0] : $suffix;
}

function applyAction(array &$plan, string $table, string $action, string $reason): void
{
    $plan[$table]['action'] = $action;
    $plan[$table]['reason'] = $reason;
}

if ($unknown) {
    $remaining = [];
    foreach ($unknown as $table) {
        $configured = $replay['table_actions'][$table] ?? null;
        if (in_array($configured, $ACTIONS, true)) {
            applyAction($plan, $table, $configured, 'run-config');
        } else {
            $remaining[] = $table;
        }
    }
    $unknown = $remaining;
}
if ($unknown && $opts['unknown'] !== null) {
    foreach ($unknown as $table) applyAction($plan, $table, $opts['unknown'], '--unknown');
    $unknown = [];
}
if ($unknown) {
    $groups = [];
    foreach ($unknown as $table) $groups[family($table, $p)][] = $table;
    ksort($groups);
    warn(count($unknown) . ' unrecognized tables across ' . count($groups) . ' groups.');
    foreach ($groups as $group => $groupTables) {
        $rows = array_sum(array_map(function ($table) use ($plan) { return $plan[$table]['rows']; }, $groupTables));
        out('  ' . str_pad($group . '_*', 28) . count($groupTables) . ' tables, ' . $rows . ' rows');
    }
    $mode = choose('How do you want to decide?', [
        'Structure only for all (recommended)',
        'Exclude all',
        'Decide by plugin group',
        'Decide table by table',
    ], 0);
    if ($mode === 0 || $mode === 1) {
        $action = $mode === 0 ? 'schema' : 'exclude';
        foreach ($unknown as $table) applyAction($plan, $table, $action, 'bulk choice');
    } elseif ($mode === 2) {
        $sticky = null;
        foreach ($groups as $group => $groupTables) {
            if ($sticky === null) {
                $answer = chooseSticky($group . '_*', $LABELS, 0);
                $action = $ACTIONS[$answer['index']];
                if ($answer['all']) $sticky = $action;
            } else {
                $action = $sticky;
            }
            foreach ($groupTables as $table) applyAction($plan, $table, $action, 'operator group choice');
        }
    } else {
        $sticky = null;
        foreach ($unknown as $table) {
            if ($sticky === null) {
                $answer = chooseSticky($table . ' (' . $plan[$table]['rows'] . ' rows)', $LABELS, 0);
                $action = $ACTIONS[$answer['index']];
                if ($answer['all']) $sticky = $action;
            } else {
                $action = $sticky;
            }
            applyAction($plan, $table, $action, 'operator table choice');
        }
    }
}
$copiedUnknown = array_keys(array_filter($plan, function ($item) {
    return $item['action'] === 'copy' && strpos($item['reason'], 'choice') !== false;
}));
if ($copiedUnknown) warn('Unrecognized tables copied with data: ' . implode(', ', $copiedUnknown));

// Discover suspicious EAV keys without printing their values.
$residualScopes = [];
foreach ([
    'usermeta' => [$p . 'usermeta', 'meta_key', 'meta_value'],
    'postmeta' => [$p . 'postmeta', 'meta_key', 'meta_value'],
    'hpos_order_meta' => [$p . 'wc_orders_meta', 'meta_key', 'meta_value'],
    'order_itemmeta' => [$p . 'woocommerce_order_itemmeta', 'meta_key', 'meta_value'],
    'commentmeta' => [$p . 'commentmeta', 'meta_key', 'meta_value'],
    'options' => [$p . 'options', 'option_name', 'option_value'],
] as $scope => $definition) {
    if ($hasTable($definition[0]) && ($plan[$definition[0]]['action'] ?? '') === 'anonymize') {
        $residualScopes[$scope] = $definition;
    }
}
function knownUsermetaPiiKeys(): array
{
    return [
        'session_tokens', '_new_email', '_password_reset_key', '_application_passwords',
        'first_name', 'last_name', 'nickname', 'billing_email', 'shipping_email', 'billing_phone',
        'shipping_phone', 'billing_address_1', 'shipping_address_1', 'billing_address_2',
        'shipping_address_2', 'billing_company', 'shipping_company', 'description',
        'billing_city', 'shipping_city', 'billing_postcode', 'shipping_postcode',
    ];
}

function knownLegacyOrderPiiKeys(): array
{
    return [
        '_billing_first_name', '_shipping_first_name', '_billing_last_name', '_shipping_last_name',
        '_billing_email', '_shipping_email', '_billing_phone', '_shipping_phone',
        '_billing_address_1', '_shipping_address_1', '_billing_address_2', '_shipping_address_2',
        '_billing_company', '_shipping_company', '_billing_city', '_shipping_city',
        '_billing_postcode', '_shipping_postcode', '_customer_user_agent', '_customer_note',
        '_customer_ip_address', '_transaction_id', '_order_key', '_payment_tokens',
        '_stripe_customer_id', '_stripe_source_id', '_paypal_transaction_id',
    ];
}

function handledResidualKey(string $scope, string $key): bool
{
    $lower = strtolower($key);
    if ($scope === 'usermeta') {
        if (in_array($lower, knownUsermetaPiiKeys(), true)) return true;
        if (preg_match('/(vat|codice_fiscale|piva|(^|_)cf$)/', $lower)) return true;
    }
    if ($scope === 'postmeta') {
        if (in_array($lower, knownLegacyOrderPiiKeys(), true)) return true;
        if (preg_match('/(token|secret|api_key|vat|codice_fiscale|piva|(^|_)cf$)/', $lower)) return true;
    }
    if ($scope === 'hpos_order_meta' && preg_match('/(token|secret|api_key|customer_id|vat|codice_fiscale|piva)/', $lower)) return true;
    if ($scope === 'commentmeta' && strpos($lower, 'akismet_') === 0) return true;
    if ($scope === 'options' && (strpos($lower, '_transient_') === 0
        || preg_match('/(api.?key|secret|password|passwd|private.?key|token|smtp|mailgun|sendgrid|license|stripe|paypal|braintree|nexi|satispay|recaptcha|aws_)/', $lower))) return true;
    return false;
}

$residualCandidates = [];
$keyPattern = '(email|e_mail|phone|mobile|address|company|vat|piva|codice_fiscale|(^|_)cf($|_)|token|secret|password|passwd|api_key|(^|_)ip($|_)|user_agent|customer_note)';
$emailPattern = '[[:alnum:]._%+-]+@[[:alnum:].-]+[.][[:alpha:]]{2,}';
$ipPattern = '([0-9]{1,3}[.]){3}[0-9]{1,3}';
foreach ($residualScopes as $scope => $definition) {
    [$table, $keyColumn, $valueColumn] = $definition;
    $sql = 'SELECT HEX(' . sqlIdentifier($keyColumn) . '), COUNT(*) FROM ' . sqlIdentifier($table)
        . ' WHERE LOWER(' . sqlIdentifier($keyColumn) . ') REGEXP ' . sqlString($keyPattern)
        . ' OR ' . sqlIdentifier($valueColumn) . ' REGEXP ' . sqlString($emailPattern)
        . ' OR ' . sqlIdentifier($valueColumn) . ' REGEXP ' . sqlString($ipPattern)
        . ' GROUP BY ' . sqlIdentifier($keyColumn) . ' ORDER BY ' . sqlIdentifier($keyColumn);
    foreach (q($sql, $db['name']) as $row) {
        $keyHex = (string)$row[0];
        $key = hex2bin($keyHex);
        if ($key === false) fail('Could not decode a metadata key returned by MySQL.');
        if (handledResidualKey($scope, $key)) continue;
        $residualCandidates[] = [
            'scope' => $scope, 'table' => $table, 'key_column' => $keyColumn,
            'value_column' => $valueColumn, 'key' => $key, 'rows' => (int)$row[1],
        ];
    }
}

$residualActions = [];
$residualExceptions = [];
$stickyResidual = null;
foreach ($residualCandidates as $candidate) {
    $scope = $candidate['scope'];
    $key = $candidate['key'];
    $configured = $replay['residual_actions'][$scope][$key] ?? null;
    if (in_array($configured, ['redact', 'preserve'], true)) {
        $action = $configured;
    } elseif ($stickyResidual !== null) {
        $action = $stickyResidual;
    } else {
        $answer = chooseSticky(
            'Suspicious metadata ' . $scope . ':' . $key . ' (' . $candidate['rows'] . ' rows; values hidden)',
            ['Redact values (recommended)', 'Preserve values and record exception'],
            0
        );
        $action = $answer['index'] === 0 ? 'redact' : 'preserve';
        if ($answer['all']) $stickyResidual = $action;
    }
    $residualActions[$scope][$key] = $action;
    if ($action === 'preserve') $residualExceptions[] = $candidate;
}
if ($residualCandidates) ok(count($residualCandidates) . ' suspicious metadata keys reviewed.');

$monthsBack = 0;
$configuredMonths = isset($replay['months_back']) ? (int)$replay['months_back'] : 0;
if (($hpos || $legacy) && confirm("\nLimit export to recent orders?", $configuredMonths > 0)) {
    $monthsBack = max(0, (int)prompt('Months to keep', (string)($configuredMonths > 0 ? $configuredMonths : 12)));
}

$createServiceAdmin = confirm(
    "\nCreate a service administrator account?",
    isset($replay['create_service_admin']) ? (bool)$replay['create_service_admin'] : false
);
$keepAttachments = confirm(
    "\nKeep media-library attachment records? They may contain PII/EXIF.",
    isset($replay['keep_attachments']) ? (bool)$replay['keep_attachments'] : true
);
if ($keepAttachments) warn('Attachment records are retained and will be recorded as a privacy exception.');

$configuredFreeText = $replay['free_text_action'] ?? 'redact';
$freeTextAction = ['redact', 'preserve'][choose(
    "\nProduct reviews and other free-text comments:",
    ['Redact bodies (recommended)', 'Preserve bodies and record exception'],
    $configuredFreeText === 'preserve' ? 1 : 0
)];
if ($freeTextAction === 'preserve') {
    $residualExceptions[] = ['scope' => 'comment_text', 'key' => 'comment_content', 'rows' => -1];
}

$preparedCleanup = in_array($replay['prepared_schema_cleanup'] ?? '', ['empty', 'drop'], true)
    ? $replay['prepared_schema_cleanup']
    : 'empty';

$buildRunConfig = function () use (
    &$outputDir, &$monthsBack, &$keepAttachments, &$createServiceAdmin, &$preparedCleanup,
    &$freeTextAction, &$plan, &$residualActions
): array {
    return [
        'config_version' => 1,
        'output_dir' => $outputDir,
        'months_back' => $monthsBack,
        'keep_attachments' => $keepAttachments,
        'create_service_admin' => $createServiceAdmin,
        'prepared_schema_cleanup' => $preparedCleanup,
        'free_text_action' => $freeTextAction,
        'table_actions' => array_map(function ($item) { return $item['action']; }, $plan),
        'residual_actions' => $residualActions,
    ];
};

// ===========================================================================
// 4. SUMMARY AND CONFIRMATION
// ===========================================================================
$counts = array_count_values(array_column($plan, 'action'));
step('Summary');
hr();
out('  Source (read-only) : ' . $db['name'] . ' @ ' . $host);
out('  Working schema     : automatic private schema, or operator-supplied empty schema');
out('  Output             : ' . $outputDir);
out('  Tables             : ' . ($counts['anonymize'] ?? 0) . ' anonymized, '
    . ($counts['copy'] ?? 0) . ' copied, ' . ($counts['schema'] ?? 0) . ' structure only, '
    . ($counts['exclude'] ?? 0) . ' excluded');
out('  Orders             : ' . ($monthsBack ? 'last ' . $monthsBack . ' months' : 'all'));
out('  Attachments        : ' . ($keepAttachments ? 'kept (exception)' : 'removed with related rows'));
out('  Free text          : ' . $freeTextAction);
out('  Service account    : ' . ($createServiceAdmin ? 'created after confirmation' : 'none'));
out('  Suspicious keys    : ' . count($residualCandidates) . ' reviewed, '
    . count($residualExceptions) . ' preserved exceptions');
out('  Mode               : ' . ($opts['dry-run'] ? c('DRY-RUN (database read-only)', 'yellow') : 'full run'));
hr();

if ($opts['dry-run']) {
    $dryFile = $outputDir . '/run-config.json';
    writePrivateFile($dryFile, encodeJsonOrFail($buildRunConfig(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    out("\nDry-run complete: no database schema was created or modified.");
    out('Local configuration written with mode 0600: ' . $dryFile);
    exit(0);
}

if ($residualExceptions) {
    warn('Preserved residual-risk choices will make the export verified_with_exceptions.');
    if (prompt('Type ACCEPT RESIDUAL RISK to confirm these exceptions') !== 'ACCEPT RESIDUAL RISK') {
        out('Cancelled.');
        exit(0);
    }
}
if (strtoupper(prompt("\nType " . c('ANONYMIZE', 'bold') . ' to proceed')) !== 'ANONYMIZE') {
    out('Cancelled.');
    exit(0);
}

$runId = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
$automaticSchema = TMP_PREFIX
    . substr(preg_replace('/[^A-Za-z0-9_]/', '_', $db['name']), 0, 24)
    . '_' . substr(str_replace('-', '', $runId), -16);
$automaticSchema = substr($automaticSchema, 0, 64);
assertTempSchemaName($automaticSchema);
$GLOBALS['CTX']['tmp_db'] = $automaticSchema;
$GLOBALS['CTX']['tmp_db_owned'] = true;
$schemaErrors = [];
if (runMysqlSql(
    'CREATE DATABASE ' . sqlIdentifier($automaticSchema) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    null,
    $schemaErrors
)) {
    $tmpDb = $automaticSchema;
    ok('Created private working schema ' . $tmpDb);
} else {
    $GLOBALS['CTX']['tmp_db'] = null;
    $GLOBALS['CTX']['tmp_db_owned'] = false;
    warn('Automatic schema creation unavailable; an empty DBA-prepared schema is required.');
    $tmpDb = prompt('Prepared schema name (must start ' . TMP_PREFIX . ')');
    assertTempSchemaName($tmpDb);
    if (strtolower($tmpDb) === strtolower($db['name'])) fail('Working schema matches source database.');
    $exists = (int)qScalar(
        'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=' . sqlString($tmpDb),
        null,
        '0'
    );
    $objects = (int)qScalar(
        'SELECT '
        . '(SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=' . sqlString($tmpDb) . ') + '
        . '(SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema=' . sqlString($tmpDb) . ') + '
        . '(SELECT COUNT(*) FROM information_schema.events WHERE event_schema=' . sqlString($tmpDb) . ')',
        null,
        '0'
    );
    if ($exists !== 1 || $objects !== 0) {
        fail('Prepared schema must exist and contain no tables, views, routines or events.');
    }
    $preparedCleanup = ['empty', 'drop'][choose(
        'After the run, keep the prepared schema empty or drop it?',
        ['Keep it empty (recommended)', 'Drop the schema'],
        $preparedCleanup === 'drop' ? 1 : 0
    )];
    $GLOBALS['CTX']['tmp_db'] = $tmpDb;
    $GLOBALS['CTX']['prepared_cleanup'] = $preparedCleanup;
}
if (strtolower($tmpDb) === strtolower($db['name'])) fail('Working schema matches source database.');

$stageDir = $outputDir . '/.staging-' . $runId;
ensurePrivateDirectory($stageDir);
$GLOBALS['CTX']['tmp_dirs'][] = $stageDir;

$seedFile = $opts['seed-file'] ?: $outputDir . '/.anon-seed';
$newSeed = false;
if (is_file($seedFile) && !is_link($seedFile)) {
    $seed = trim((string)file_get_contents($seedFile));
    if (!preg_match('/^[a-fA-F0-9]{64}$/', $seed)) fail('Seed file must contain exactly 64 hexadecimal characters.');
    if (!@chmod($seedFile, 0600)) fail('Could not restrict seed permissions.');
    ok('Existing seed reused.');
} elseif (file_exists($seedFile) || is_link($seedFile)) {
    fail('Seed path is not a safe regular file: ' . $seedFile);
} else {
    $seed = bin2hex(random_bytes(32));
    writePrivateFile($seedFile, $seed . "\n");
    $GLOBALS['CTX']['tmp_files'][] = $seedFile;
    $newSeed = true;
    ok('New private seed generated.');
}

$serviceAdmin = null;
if ($createServiceAdmin) {
    $passwordClass = $wpPath . '/wp-includes/class-phpass.php';
    if (!is_file($passwordClass)) fail('WordPress PasswordHash implementation not found.');
    require_once $passwordClass;
    if (!class_exists('PasswordHash')) fail('WordPress PasswordHash class could not be loaded.');
    $password = 'anon-' . rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    $login = 'admin_test_' . bin2hex(random_bytes(4));
    $hasher = new PasswordHash(8, true);
    $hash = $hasher->HashPassword($password);
    if (!is_string($hash) || strlen($hash) < 20) fail('Could not generate WordPress-compatible password hash.');
    $serviceAdmin = ['login' => $login, 'pass' => $password, 'hash' => $hash];
}
// ===========================================================================
// 5. READ-ONLY EXTRACTION
// ===========================================================================
step('Extraction from source database (read-only)');

$dumpFlags = [
    '--single-transaction', '--quick', '--skip-lock-tables',
    '--default-character-set=utf8mb4', '--hex-blob', '--skip-triggers',
];
$helpOutput = [];
sh(escapeshellarg($dumpBin) . ' --help', $helpOutput);
$dumpHelp = implode("\n", $helpOutput);
if (str_has($dumpHelp, 'no-tablespaces')) $dumpFlags[] = '--no-tablespaces';
if (str_has($dumpHelp, 'set-gtid-purged')) $dumpFlags[] = '--set-gtid-purged=OFF';
if (str_has($dumpHelp, 'column-statistics')) $dumpFlags[] = '--column-statistics=0';

$withData = [];
$schemaOnly = [];
foreach ($plan as $table => $item) {
    if ($item['action'] === 'exclude') continue;
    if ($item['action'] === 'schema') $schemaOnly[] = $table;
    else $withData[] = $table;
}
if (!$withData) fail('Refusing an extraction with no data-bearing tables.');

$baseDump = escapeshellarg($dumpBin)
    . ' --defaults-extra-file=' . escapeshellarg($defaultsFile)
    . ' ' . implode(' ', $dumpFlags);
$rawDump = $stageDir . '/raw.sql';
createPrivateFile($rawDump);
$GLOBALS['CTX']['tmp_files'][] = $rawDump;

$command = $baseDump . ' ' . escapeshellarg($db['name']) . ' '
    . implode(' ', array_map('escapeshellarg', $withData))
    . ' > ' . escapeshellarg($rawDump);
$output = [];
$errors = [];
if (sh($command, $output, $errors) !== 0) fail("mysqldump failed:\n" . diag($output, $errors));
ok(count($withData) . ' tables extracted with data.');

if ($schemaOnly) {
    $command = $baseDump . ' --no-data ' . escapeshellarg($db['name']) . ' '
        . implode(' ', array_map('escapeshellarg', $schemaOnly))
        . ' >> ' . escapeshellarg($rawDump);
    if (sh($command, $output, $errors) !== 0) fail("mysqldump structure-only pass failed:\n" . diag($output, $errors));
    ok(count($schemaOnly) . ' tables extracted without data.');
}
if (!is_file($rawDump) || filesize($rawDump) === 0) fail('Source dump is empty.');

// ===========================================================================
// 6. TEMPORARY SCHEMA
// ===========================================================================
step('Loading into temporary schema');
$output = [];
$errors = [];
if (sh(mysqlCmd($tmpDb) . ' < ' . escapeshellarg($rawDump), $output, $errors) !== 0) {
    fail("Import into temporary schema failed:\n" . diag($output, $errors));
}
ok('Import completed into ' . $tmpDb);

$rawHandle = @fopen($rawDump, 'r+');
if ($rawHandle) {
    @ftruncate($rawHandle, 0);
    @fclose($rawHandle);
}
if (!@unlink($rawDump)) fail('Could not remove raw source dump.');
$GLOBALS['CTX']['tmp_files'] = array_values(array_diff($GLOBALS['CTX']['tmp_files'], [$rawDump]));
ok('Raw source dump removed.');
// ===========================================================================
// 7. ANONYMIZATION SQL GENERATION
// ===========================================================================
step('Anonymization');

// Italian-flavoured synthetic values: they keep the dataset plausible for an IT shop.
$firstNames = ['Alessandro','Giulia','Marco','Francesca','Luca','Chiara','Matteo','Sara','Andrea','Elena',
               'Davide','Martina','Simone','Alice','Federico','Valentina','Riccardo','Beatrice','Stefano','Ilaria',
               'Giorgio','Silvia','Paolo','Anna','Nicola','Laura','Antonio','Roberta','Michele','Serena',
               'Fabio','Camilla'];
$lastNames  = ['Rossi','Bianchi','Ferrari','Russo','Esposito','Colombo','Ricci','Marino','Greco','Bruno',
               'Gallo','Conti','De Luca','Costa','Giordano','Mancini','Rizzo','Lombardi','Moretti','Barbieri',
               'Fontana','Santoro','Mariani','Rinaldi','Caruso','Ferrara','Galli','Martini','Leone','Longo',
               'Gentile','Martinelli'];
$cities     = ['Milano','Roma','Napoli','Torino','Bologna','Firenze','Genova','Bari','Palermo','Verona',
               'Padova','Venezia','Catania','Trieste','Brescia','Parma','Modena','Perugia','Cagliari','Salerno',
               'Ancona','Pescara','Rimini','Lecce','Trento','Udine','Latina','Ferrara','Como','Pisa',
               'Bergamo','Livorno'];
$streets    = ['Garibaldi','Roma','Verdi','Dante','Manzoni','Mazzini','Cavour','Marconi','Leopardi','Vittorio Veneto',
               'della Repubblica','Trieste','Milano','San Marco','dei Mille','Matteotti','Puccini','Rossini','Alfieri','Foscolo',
               'Diaz','Petrarca','Boccaccio','Galilei','Volta','Fermi','Torino','Firenze','Napoli','Bologna',
               'Carducci','Pascoli'];

$sqlFile = $stageDir . '/anonymize.sql';
$sql = [
    'SET NAMES utf8mb4;',
    "SET SESSION sql_mode='STRICT_ALL_TABLES';",
    "SET @seed := UNHEX('" . strtolower($seed) . "');",
    'SET @guard := IF(DATABASE()=' . sqlString($tmpDb) . ', 1, NULL);',
    'CREATE TEMPORARY TABLE _anon_guard (ok INT NOT NULL);',
    'INSERT INTO _anon_guard (ok) VALUES (@guard);',
    'DROP TEMPORARY TABLE _anon_guard;',
    'SET FOREIGN_KEY_CHECKS=0;',
];

$dicts = [
    '_anon_first' => $firstNames,
    '_anon_last' => $lastNames,
    '_anon_city' => $cities,
    '_anon_street' => $streets,
];
foreach ($dicts as $name => $values) {
    $sql[] = 'DROP TEMPORARY TABLE IF EXISTS ' . sqlIdentifier($name) . ';';
    $sql[] = 'CREATE TEMPORARY TABLE ' . sqlIdentifier($name)
        . ' (n INT PRIMARY KEY, v VARCHAR(64) NOT NULL) ENGINE=InnoDB;';
    $rows = [];
    foreach (array_slice($values, 0, DICT_N) as $index => $value) {
        $rows[] = '(' . $index . ',' . sqlString($value) . ')';
    }
    $sql[] = 'INSERT INTO ' . sqlIdentifier($name) . ' (n,v) VALUES ' . implode(',', $rows) . ';';
}

function normalizedSql(string $value, string $kind = 'text'): string
{
    $base = "COALESCE(CAST(" . $value . " AS CHAR),'')";
    if ($kind === 'email') return 'LOWER(TRIM(' . $base . '))';
    if ($kind === 'phone') {
        foreach ([' ', '-', '(', ')', '.', '/'] as $char) {
            $base = 'REPLACE(' . $base . ',' . sqlString($char) . ",'')";
        }
        return $base;
    }
    if ($kind === 'tax') {
        return "UPPER(REPLACE(REPLACE(TRIM(" . $base . "), ' ', ''), '-', ''))";
    }
    return 'LOWER(TRIM(' . $base . '))';
}

function h(string $value, string $salt, string $kind = 'text'): string
{
    return 'SHA2(CONCAT(@seed,' . sqlString($salt) . ',' . normalizedSql($value, $kind) . '),256)';
}

function nonEmptyCase(string $value, string $replacement): string
{
    return 'CASE WHEN ' . $value . " IS NULL THEN NULL WHEN TRIM(CAST(" . $value
        . " AS CHAR))='' THEN " . $value . ' ELSE ' . $replacement . ' END';
}

function pick(string $dict, string $value, string $salt): string
{
    $index = 'MOD(CONV(SUBSTR(' . h($value, $salt) . ',1,6),16,10),' . DICT_N . ')';
    $nextIndex = 'MOD((' . $index . ')+1,' . DICT_N . ')';
    $picked = '(SELECT v FROM ' . sqlIdentifier($dict)
        . ' WHERE n IN (' . $index . ',' . $nextIndex . ')'
        . ' ORDER BY (BINARY LOWER(TRIM(v))=BINARY ' . normalizedSql($value) . ') ASC,'
        . ' (n=' . $index . ') DESC LIMIT 1)';
    return nonEmptyCase($value, $picked);
}

function fakeEmail(string $value): string
{
    return nonEmptyCase(
        $value,
        "CONCAT('u',SUBSTR(" . h($value, 'email', 'email') . ",1,20),'@" . FAKE_DOMAIN . "')"
    );
}

function fakePhone(string $value): string
{
    return nonEmptyCase(
        $value,
        "CONCAT('+39 3',LPAD(MOD(CONV(SUBSTR(" . h($value, 'phone', 'phone')
        . ",1,13),16,10),1000000000),9,'0'))"
    );
}

function fakeZip(string $value): string
{
    return nonEmptyCase(
        $value,
        "LPAD(MOD(CONV(SUBSTR(" . h($value, 'zip') . ",1,10),16,10),100000),5,'0')"
    );
}

function fakeAddr(string $value): string
{
    return nonEmptyCase(
        $value,
        "CONCAT('Via '," . pick('_anon_street', $value, 'street') . ",' ',1+MOD(CONV(SUBSTR("
        . h($value, 'house') . ",1,8),16,10),150))"
    );
}

function fakeCompany(string $value): string
{
    return nonEmptyCase($value, "CONCAT('Azienda ',SUBSTR(" . h($value, 'company') . ",1,10))");
}

function fakeVat(string $value): string
{
    $base = "LPAD(MOD(CONV(SUBSTR(" . h($value, 'vat', 'tax')
        . ",1,13),16,10),10000000000),10,'0')";
    $parts = [];
    for ($position = 1; $position <= 10; $position++) {
        $digit = 'CAST(SUBSTR(' . $base . ',' . $position . ',1) AS UNSIGNED)';
        $parts[] = $position % 2 === 0
            ? 'IF((' . $digit . '*2)>9,(' . $digit . '*2)-9,(' . $digit . '*2))'
            : $digit;
    }
    $check = 'MOD(10-MOD((' . implode('+', $parts) . '),10),10)';
    return nonEmptyCase($value, 'CONCAT(' . $base . ',' . $check . ')');
}

function fakeTaxCode(string $value): string
{
    $digest = h($value, 'tax-code', 'tax');
    $letter = function (int $offset) use ($digest): string {
        return 'CHAR(65+MOD(CONV(SUBSTR(' . $digest . ',' . $offset . ',2),16,10),26))';
    };
    $number = function (int $offset, int $modulo, int $width) use ($digest): string {
        return "LPAD(MOD(CONV(SUBSTR(" . $digest . ',' . $offset
            . ',2),16,10),' . $modulo . '),' . $width . ",'0')";
    };
    $base = 'CONCAT('
        . implode(',', [$letter(1), $letter(3), $letter(5), $letter(7), $letter(9), $letter(11)])
        . ',' . $number(13, 100, 2)
        . ",SUBSTR('ABCDEHLMPRST',1+MOD(CONV(SUBSTR(" . $digest . ",15,2),16,10),12),1)"
        . ",LPAD(1+MOD(CONV(SUBSTR(" . $digest . ",17,2),16,10),28),2,'0')"
        . ',' . $letter(19)
        . ',' . $number(21, 1000, 3)
        . ')';
    $oddMap = [
        '0'=>1,'1'=>0,'2'=>5,'3'=>7,'4'=>9,'5'=>13,'6'=>15,'7'=>17,'8'=>19,'9'=>21,
        'A'=>1,'B'=>0,'C'=>5,'D'=>7,'E'=>9,'F'=>13,'G'=>15,'H'=>17,'I'=>19,'J'=>21,
        'K'=>2,'L'=>4,'M'=>18,'N'=>20,'O'=>11,'P'=>3,'Q'=>6,'R'=>8,'S'=>12,'T'=>14,
        'U'=>16,'V'=>10,'W'=>22,'X'=>25,'Y'=>24,'Z'=>23,
    ];
    $sum = [];
    for ($position = 1; $position <= 15; $position++) {
        $char = 'SUBSTR(' . $base . ',' . $position . ',1)';
        if ($position % 2 === 1) {
            $case = 'CASE ' . $char;
            // PHP casts numeric-string array keys ("0"…"9") to int.
            foreach ($oddMap as $candidate => $score) $case .= ' WHEN ' . sqlString((string)$candidate) . ' THEN ' . $score;
            $sum[] = $case . ' ELSE 0 END';
        } else {
            $sum[] = "IF(" . $char . " BETWEEN '0' AND '9',CAST(" . $char
                . " AS UNSIGNED),ASCII(" . $char . ')-65)';
        }
    }
    $control = 'CHAR(65+MOD((' . implode('+', $sum) . '),26))';
    return nonEmptyCase($value, 'CONCAT(' . $base . ',' . $control . ')');
}

function fakeOpaque(string $value, string $salt, string $prefix = 'anon-'): string
{
    return nonEmptyCase($value, 'CONCAT(' . sqlString($prefix) . ',SUBSTR(' . h($value, $salt) . ',1,24))');
}

$act = function (string $table) use ($plan): bool {
    return isset($plan[$table]) && $plan[$table]['action'] === 'anonymize';
};

$tUsers = $p . 'users';
$tUsermeta = $p . 'usermeta';
$tPosts = $p . 'posts';
$tPostmeta = $p . 'postmeta';
$tComments = $p . 'comments';
$tCommentmeta = $p . 'commentmeta';
$tOptions = $p . 'options';

if ($act($tUsers)) {
    $table = sqlIdentifier($tUsers);
    $sql[] = "UPDATE $table SET "
        . "user_login=" . fakeOpaque('user_login', 'login', 'user_') . ','
        . "user_pass='!ANONYMIZED!',"
        . "user_nicename=" . fakeOpaque('user_nicename', 'nicename', 'user-') . ','
        . 'user_email=' . fakeEmail('user_email') . ','
        . "user_url='',"
        . "display_name=CONCAT(" . pick('_anon_first', 'display_name', 'display-first')
        . ",' '," . pick('_anon_last', 'display_name', 'display-last') . ');';
}

if ($act($tUsermeta)) {
    $table = sqlIdentifier($tUsermeta);
    $sql[] = "DELETE FROM $table WHERE meta_key IN ('session_tokens','_new_email','_password_reset_key',"
        . "'_application_passwords'," . sqlString($p . 'user-settings') . ');';
    $sql[] = "UPDATE $table SET meta_value=" . pick('_anon_first', 'meta_value', 'first-name')
        . " WHERE meta_key IN ('first_name','billing_first_name','shipping_first_name','nickname');";
    $sql[] = "UPDATE $table SET meta_value=" . pick('_anon_last', 'meta_value', 'last-name')
        . " WHERE meta_key IN ('last_name','billing_last_name','shipping_last_name');";
    $sql[] = "UPDATE $table SET meta_value=" . fakeEmail('meta_value')
        . " WHERE meta_key IN ('billing_email','shipping_email');";
    $sql[] = "UPDATE $table SET meta_value=" . fakePhone('meta_value')
        . " WHERE meta_key IN ('billing_phone','shipping_phone');";
    $sql[] = "UPDATE $table SET meta_value=" . fakeAddr('meta_value')
        . " WHERE meta_key IN ('billing_address_1','shipping_address_1');";
    $sql[] = "UPDATE $table SET meta_value='' WHERE meta_key IN "
        . "('billing_address_2','shipping_address_2','description');";
    $sql[] = "UPDATE $table SET meta_value=" . fakeCompany('meta_value')
        . " WHERE meta_key IN ('billing_company','shipping_company');";
    $sql[] = "UPDATE $table SET meta_value=" . pick('_anon_city', 'meta_value', 'city')
        . " WHERE meta_key IN ('billing_city','shipping_city');";
    $sql[] = "UPDATE $table SET meta_value=" . fakeZip('meta_value')
        . " WHERE meta_key IN ('billing_postcode','shipping_postcode');";
    $sql[] = "UPDATE $table SET meta_value=" . fakeVat('meta_value')
        . " WHERE LOWER(meta_key) LIKE '%vat%' OR LOWER(meta_key) LIKE '%piva%';";
    $sql[] = "UPDATE $table SET meta_value=" . fakeTaxCode('meta_value')
        . " WHERE LOWER(meta_key) LIKE '%codice_fiscale%' OR LOWER(meta_key) REGEXP '(^|_)cf$';";
}

if ($act($tPosts)) {
    $table = sqlIdentifier($tPosts);
    $sql[] = "UPDATE $table SET post_excerpt='',post_password='' "
        . "WHERE post_type LIKE 'shop_order%' OR post_type='shop_subscription';";
}

if ($act($tPostmeta)) {
    $table = sqlIdentifier($tPostmeta);
    $sql[] = "UPDATE $table SET meta_value=" . pick('_anon_first', 'meta_value', 'first-name')
        . " WHERE meta_key IN ('_billing_first_name','_shipping_first_name');";
    $sql[] = "UPDATE $table SET meta_value=" . pick('_anon_last', 'meta_value', 'last-name')
        . " WHERE meta_key IN ('_billing_last_name','_shipping_last_name');";
    $sql[] = "UPDATE $table SET meta_value=" . fakeEmail('meta_value')
        . " WHERE meta_key IN ('_billing_email','_shipping_email');";
    $sql[] = "UPDATE $table SET meta_value=" . fakePhone('meta_value')
        . " WHERE meta_key IN ('_billing_phone','_shipping_phone');";
    $sql[] = "UPDATE $table SET meta_value=" . fakeAddr('meta_value')
        . " WHERE meta_key IN ('_billing_address_1','_shipping_address_1');";
    $sql[] = "UPDATE $table SET meta_value='' WHERE meta_key IN ('_billing_address_2','_shipping_address_2','_customer_user_agent','_customer_note');";
    $sql[] = "UPDATE $table SET meta_value=" . fakeCompany('meta_value')
        . " WHERE meta_key IN ('_billing_company','_shipping_company');";
    $sql[] = "UPDATE $table SET meta_value=" . pick('_anon_city', 'meta_value', 'city')
        . " WHERE meta_key IN ('_billing_city','_shipping_city');";
    $sql[] = "UPDATE $table SET meta_value=" . fakeZip('meta_value')
        . " WHERE meta_key IN ('_billing_postcode','_shipping_postcode');";
    $sql[] = "UPDATE $table SET meta_value=" . fakeVat('meta_value')
        . " WHERE LOWER(meta_key) LIKE '%vat%' OR LOWER(meta_key) LIKE '%piva%';";
    $sql[] = "UPDATE $table SET meta_value=" . fakeTaxCode('meta_value')
        . " WHERE LOWER(meta_key) LIKE '%codice_fiscale%' OR LOWER(meta_key) REGEXP '(^|_)cf$';";
    $sql[] = "UPDATE $table SET meta_value=" . fakeOpaque('meta_value', 'payment-reference')
        . " WHERE meta_key IN ('_transaction_id','_order_key','_payment_tokens','_stripe_customer_id',"
        . "'_stripe_source_id','_paypal_transaction_id');";
    $sql[] = "DELETE FROM $table WHERE LOWER(meta_key) REGEXP '(token|secret|api_key)';";
    $sql[] = "UPDATE $table SET meta_value='" . FAKE_IP . "' WHERE meta_key='_customer_ip_address' AND meta_value<>'';";
}

if ($act($tComments)) {
    $table = sqlIdentifier($tComments);
    $sql[] = "UPDATE $table SET "
        . "comment_author=CONCAT(" . pick('_anon_first', 'comment_author', 'comment-first')
        . ", ' ', LEFT(" . pick('_anon_last', 'comment_author', 'comment-last') . ",1),'.'),"
        . 'comment_author_email=' . fakeEmail('comment_author_email') . ','
        . "comment_author_url='',"
        . "comment_author_IP=CASE WHEN comment_author_IP='' THEN '' ELSE '" . FAKE_IP . "' END,"
        . "comment_agent=CASE WHEN comment_agent='' THEN '' ELSE 'anonymized' END;";
    $sql[] = "UPDATE $table SET comment_content=CONCAT('[order note redacted #',comment_ID,']') "
        . "WHERE comment_type='order_note' AND comment_content<>'';";
    if ($freeTextAction === 'redact') {
        $sql[] = "UPDATE $table SET comment_content=CONCAT('[comment redacted #',comment_ID,']') "
            . "WHERE comment_type<>'order_note' AND comment_content<>'';";
    }
}

if ($act($tCommentmeta)) {
    $sql[] = 'DELETE FROM ' . sqlIdentifier($tCommentmeta)
        . " WHERE LOWER(meta_key) LIKE 'akismet_%' OR LOWER(meta_key) LIKE '%token%' OR LOWER(meta_key) LIKE '%secret%';";
}

if (!$keepAttachments && $hasTable($tPosts)) {
    $sql[] = 'CREATE TEMPORARY TABLE _anon_attachments (id BIGINT PRIMARY KEY);';
    $sql[] = 'INSERT INTO _anon_attachments SELECT ID FROM ' . sqlIdentifier($tPosts) . " WHERE post_type='attachment';";
    if ($hasTable($tCommentmeta) && $hasTable($tComments)) {
        $sql[] = 'DELETE cm FROM ' . sqlIdentifier($tCommentmeta) . ' cm JOIN ' . sqlIdentifier($tComments)
            . ' c ON c.comment_ID=cm.comment_id WHERE c.comment_post_ID IN (SELECT id FROM _anon_attachments);';
    }
    if ($hasTable($tComments)) {
        $sql[] = 'DELETE FROM ' . sqlIdentifier($tComments) . ' WHERE comment_post_ID IN (SELECT id FROM _anon_attachments);';
    }
    if ($hasTable($tPostmeta)) {
        $sql[] = 'DELETE FROM ' . sqlIdentifier($tPostmeta) . ' WHERE post_id IN (SELECT id FROM _anon_attachments);';
    }
    if ($hasTable($p . 'term_relationships')) {
        $sql[] = 'DELETE FROM ' . sqlIdentifier($p . 'term_relationships')
            . ' WHERE object_id IN (SELECT id FROM _anon_attachments);';
    }
    $sql[] = 'DELETE FROM ' . sqlIdentifier($tPosts) . ' WHERE ID IN (SELECT id FROM _anon_attachments);';
    $sql[] = 'DROP TEMPORARY TABLE _anon_attachments;';
}

if ($hpos) {
    $orders = $p . 'wc_orders';
    if ($act($orders)) {
        $sql[] = 'UPDATE ' . sqlIdentifier($orders) . ' SET '
            . 'billing_email=' . fakeEmail('billing_email') . ','
            . "ip_address=CASE WHEN ip_address='' THEN '' ELSE '" . FAKE_IP . "' END,"
            . "user_agent=CASE WHEN user_agent='' THEN '' ELSE 'anonymized' END,"
            . "customer_note=CASE WHEN customer_note IS NULL OR customer_note='' THEN customer_note ELSE CONCAT('[customer note redacted #',id,']') END,"
            . 'transaction_id=' . fakeOpaque('transaction_id', 'transaction') . ';';
    }
    $addresses = $p . 'wc_order_addresses';
    if ($act($addresses)) {
        $sql[] = 'UPDATE ' . sqlIdentifier($addresses) . ' SET '
            . 'first_name=' . pick('_anon_first', 'first_name', 'first-name') . ','
            . 'last_name=' . pick('_anon_last', 'last_name', 'last-name') . ','
            . 'company=' . fakeCompany('company') . ','
            . 'address_1=' . fakeAddr('address_1') . ",address_2='',"
            . 'city=' . pick('_anon_city', 'city', 'city') . ','
            . 'postcode=' . fakeZip('postcode') . ','
            . 'email=' . fakeEmail('email') . ',phone=' . fakePhone('phone') . ';';
    }
    $orderMeta = $p . 'wc_orders_meta';
    if ($act($orderMeta)) {
        $table = sqlIdentifier($orderMeta);
        $sql[] = "DELETE FROM $table WHERE LOWER(meta_key) REGEXP '(token|secret|api_key|customer_id)';";
        $sql[] = "UPDATE $table SET meta_value=" . fakeVat('meta_value')
            . " WHERE LOWER(meta_key) LIKE '%vat%' OR LOWER(meta_key) LIKE '%piva%';";
        $sql[] = "UPDATE $table SET meta_value=" . fakeTaxCode('meta_value')
            . " WHERE LOWER(meta_key) LIKE '%codice_fiscale%' OR LOWER(meta_key) REGEXP '(^|_)cf$';";
    }
    $operational = $p . 'wc_order_operational_data';
    if ($act($operational)) {
        $sql[] = 'UPDATE ' . sqlIdentifier($operational) . ' SET order_key='
            . fakeOpaque('order_key', 'order-key', 'wc_order_') . ';';
    }
}

$customers = $p . 'wc_customer_lookup';
if ($act($customers)) {
    $sql[] = 'UPDATE ' . sqlIdentifier($customers) . ' SET '
        . 'username=' . fakeOpaque('username', 'login', 'user_') . ','
        . 'first_name=' . pick('_anon_first', 'first_name', 'first-name') . ','
        . 'last_name=' . pick('_anon_last', 'last_name', 'last-name') . ','
        . 'email=' . fakeEmail('email') . ','
        . 'city=' . pick('_anon_city', 'city', 'city') . ','
        . 'postcode=' . fakeZip('postcode') . ';';
}

$downloadLog = $p . 'wc_download_log';
if ($act($downloadLog) && $hasCol($downloadLog, 'user_ip_address')) {
    $sql[] = 'UPDATE ' . sqlIdentifier($downloadLog)
        . " SET user_ip_address=CASE WHEN user_ip_address='' THEN '' ELSE '" . FAKE_IP . "' END;";
}
$permissions = $p . 'woocommerce_downloadable_product_permissions';
if ($act($permissions)) {
    $sql[] = 'UPDATE ' . sqlIdentifier($permissions) . ' SET user_email=' . fakeEmail('user_email') . ';';
}

if ($act($tOptions)) {
    $table = sqlIdentifier($tOptions);
    $sql[] = "DELETE FROM $table WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%';";
    $sql[] = "UPDATE $table SET option_value='' WHERE LOWER(option_name) REGEXP "
        . "'(api.?key|secret|password|passwd|private.?key|access.?token|refresh.?token|smtp|mailgun|sendgrid|license|stripe|paypal|braintree|nexi|satispay|recaptcha|aws_)' "
        . "AND option_name NOT IN ('siteurl','home','blogname','admin_email');";
    $sql[] = "UPDATE $table SET option_value='test@" . FAKE_DOMAIN
        . "' WHERE option_name IN ('admin_email','new_admin_email','woocommerce_stock_email_recipient');";
}

foreach ($residualCandidates as $candidate) {
    if (($residualActions[$candidate['scope']][$candidate['key']] ?? 'redact') !== 'redact') continue;
    $sql[] = 'UPDATE ' . sqlIdentifier($candidate['table'])
        . ' SET ' . sqlIdentifier($candidate['value_column']) . '=' . sqlString('[redacted]')
        . ' WHERE ' . sqlIdentifier($candidate['key_column']) . '=' . sqlString($candidate['key']) . ';';
}

if ($monthsBack > 0) {
    if ($hpos) {
        $sql[] = 'CREATE TEMPORARY TABLE _anon_drop (id BIGINT PRIMARY KEY);';
        $sql[] = 'INSERT INTO _anon_drop SELECT id FROM ' . sqlIdentifier($p . 'wc_orders')
            . ' WHERE date_created_gmt<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $monthsBack . ' MONTH);';
        $items = $p . 'woocommerce_order_items';
        $itemmeta = $p . 'woocommerce_order_itemmeta';
        if ($hasTable($items)) {
            $sql[] = 'CREATE TEMPORARY TABLE _anon_drop_items (id BIGINT PRIMARY KEY);';
            $sql[] = 'INSERT IGNORE INTO _anon_drop_items SELECT order_item_id FROM ' . sqlIdentifier($items)
                . ' WHERE order_id IN (SELECT id FROM _anon_drop);';
            if ($hasTable($itemmeta)) {
                $sql[] = 'DELETE FROM ' . sqlIdentifier($itemmeta)
                    . ' WHERE order_item_id IN (SELECT id FROM _anon_drop_items);';
            }
            $sql[] = 'DELETE FROM ' . sqlIdentifier($items)
                . ' WHERE order_item_id IN (SELECT id FROM _anon_drop_items);';
            $sql[] = 'DROP TEMPORARY TABLE _anon_drop_items;';
        }
        foreach ([
            [$p . 'wc_order_addresses', 'order_id'], [$p . 'wc_orders_meta', 'order_id'],
            [$p . 'wc_order_operational_data', 'order_id'], [$p . 'wc_order_stats', 'order_id'],
            [$p . 'wc_order_product_lookup', 'order_id'], [$p . 'wc_order_tax_lookup', 'order_id'],
            [$p . 'wc_order_coupon_lookup', 'order_id'],
        ] as $dependency) {
            if ($hasTable($dependency[0]) && $hasCol($dependency[0], $dependency[1])) {
                $sql[] = 'DELETE FROM ' . sqlIdentifier($dependency[0]) . ' WHERE '
                    . sqlIdentifier($dependency[1]) . ' IN (SELECT id FROM _anon_drop);';
            }
        }
        $sql[] = 'DELETE FROM ' . sqlIdentifier($p . 'wc_orders') . ' WHERE id IN (SELECT id FROM _anon_drop);';
        $sql[] = 'DROP TEMPORARY TABLE _anon_drop;';
    }
    if ($legacy) {
        $sql[] = 'CREATE TEMPORARY TABLE _anon_drop_legacy (id BIGINT PRIMARY KEY);';
        $sql[] = 'INSERT INTO _anon_drop_legacy SELECT ID FROM ' . sqlIdentifier($tPosts)
            . " WHERE post_type LIKE 'shop_order%' AND post_date_gmt<DATE_SUB(UTC_TIMESTAMP(),INTERVAL "
            . $monthsBack . ' MONTH);';
        $items = $p . 'woocommerce_order_items';
        $itemmeta = $p . 'woocommerce_order_itemmeta';
        if ($hasTable($items)) {
            $sql[] = 'CREATE TEMPORARY TABLE _anon_drop_legacy_items (id BIGINT PRIMARY KEY);';
            $sql[] = 'INSERT IGNORE INTO _anon_drop_legacy_items SELECT order_item_id FROM ' . sqlIdentifier($items)
                . ' WHERE order_id IN (SELECT id FROM _anon_drop_legacy);';
            if ($hasTable($itemmeta)) {
                $sql[] = 'DELETE FROM ' . sqlIdentifier($itemmeta)
                    . ' WHERE order_item_id IN (SELECT id FROM _anon_drop_legacy_items);';
            }
            $sql[] = 'DELETE FROM ' . sqlIdentifier($items)
                . ' WHERE order_item_id IN (SELECT id FROM _anon_drop_legacy_items);';
            $sql[] = 'DROP TEMPORARY TABLE _anon_drop_legacy_items;';
        }
        if ($hasTable($tCommentmeta) && $hasTable($tComments)) {
            $sql[] = 'DELETE cm FROM ' . sqlIdentifier($tCommentmeta) . ' cm JOIN ' . sqlIdentifier($tComments)
                . ' c ON c.comment_ID=cm.comment_id WHERE c.comment_post_ID IN (SELECT id FROM _anon_drop_legacy);';
        }
        if ($hasTable($tPostmeta)) {
            $sql[] = 'DELETE FROM ' . sqlIdentifier($tPostmeta)
                . ' WHERE post_id IN (SELECT id FROM _anon_drop_legacy);';
        }
        if ($hasTable($tComments)) {
            $sql[] = 'DELETE FROM ' . sqlIdentifier($tComments)
                . ' WHERE comment_post_ID IN (SELECT id FROM _anon_drop_legacy);';
        }
        $sql[] = 'DELETE FROM ' . sqlIdentifier($tPosts) . ' WHERE ID IN (SELECT id FROM _anon_drop_legacy);';
        $sql[] = 'DROP TEMPORARY TABLE _anon_drop_legacy;';
    }
}

if ($serviceAdmin && $act($tUsers) && $act($tUsermeta)) {
    $login = sqlString($serviceAdmin['login']);
    $hash = sqlString($serviceAdmin['hash']);
    $sql[] = 'INSERT INTO ' . sqlIdentifier($tUsers)
        . " (user_login,user_pass,user_nicename,user_email,user_registered,display_name) VALUES "
        . "($login,$hash,$login," . sqlString($serviceAdmin['login'] . '@' . FAKE_DOMAIN)
        . ",UTC_TIMESTAMP(),'Service Administrator');";
    $sql[] = 'SET @service_user_id:=LAST_INSERT_ID();';
    $sql[] = 'INSERT INTO ' . sqlIdentifier($tUsermeta)
        . ' (user_id,meta_key,meta_value) VALUES (@service_user_id,'
        . sqlString($p . 'capabilities') . ',' . sqlString('a:1:{s:13:"administrator";b:1;}') . '),'
        . '(@service_user_id,' . sqlString($p . 'user_level') . ",'10');";
}

foreach (array_keys($dicts) as $name) {
    $sql[] = 'DROP TEMPORARY TABLE IF EXISTS ' . sqlIdentifier($name) . ';';
}
$sql[] = 'SET FOREIGN_KEY_CHECKS=1;';

writePrivateFile($sqlFile, implode("\n", $sql) . "\n");
$GLOBALS['CTX']['tmp_files'][] = $sqlFile;
execSqlFile($sqlFile, $tmpDb);
ok(count($sql) . ' guarded statements applied to temporary schema.');
// ===========================================================================
// 8. VERIFICATION
// ===========================================================================
step('Residual-data and integrity checks');

$checks = [];
$addCheck = function (string $id, string $sql) use (&$checks): void {
    $checks[$id] = $sql;
};
if ($hasTable($tUsers)) {
    $users = sqlIdentifier($tUsers);
    $sourceUsers = sqlIdentifier($db['name']) . '.' . $users;
    $addCheck('user_emails_outside_fake_domain',
        "SELECT COUNT(*) FROM $users WHERE user_email<>'' AND user_email NOT LIKE '%@" . FAKE_DOMAIN . "'");
    $passwordWhere = "user_pass<>'!ANONYMIZED!'";
    if ($serviceAdmin) $passwordWhere .= ' AND user_login<>' . sqlString($serviceAdmin['login']);
    $addCheck('reusable_user_password_hashes', "SELECT COUNT(*) FROM $users WHERE $passwordWhere");
    $addCheck('unchanged_user_emails',
        "SELECT COUNT(*) FROM $users t JOIN $sourceUsers s ON s.ID=t.ID "
        . "WHERE t.user_email<>'' AND t.user_email=s.user_email");
}
if ($hasTable($tUsermeta)) {
    $table = sqlIdentifier($tUsermeta);
    $source = sqlIdentifier($db['name']) . '.' . $table;
    $addCheck('wordpress_application_passwords',
        "SELECT COUNT(*) FROM $table WHERE meta_key='_application_passwords'");
    $addCheck('usermeta_emails_outside_fake_domain',
        "SELECT COUNT(*) FROM $table WHERE meta_key IN ('billing_email','shipping_email') "
        . "AND meta_value<>'' AND meta_value NOT LIKE '%@" . FAKE_DOMAIN . "'");
    $knownKeys = implode(',', array_map('sqlString', knownUsermetaPiiKeys()));
    $addCheck('unchanged_known_usermeta_pii',
        "SELECT COUNT(*) FROM $table t JOIN $source s ON s.umeta_id=t.umeta_id "
        . "WHERE TRIM(CAST(t.meta_value AS CHAR))<>'' AND t.meta_value=s.meta_value AND ("
        . "LOWER(t.meta_key) IN ($knownKeys) OR "
        . "LOWER(t.meta_key) REGEXP '(vat|piva|codice_fiscale|(^|_)cf$)')");
}
if ($hasTable($tPostmeta)) {
    $table = sqlIdentifier($tPostmeta);
    $source = sqlIdentifier($db['name']) . '.' . $table;
    $addCheck('legacy_order_emails_outside_fake_domain',
        "SELECT COUNT(*) FROM $table WHERE meta_key IN ('_billing_email','_shipping_email') "
        . "AND meta_value<>'' AND meta_value NOT LIKE '%@" . FAKE_DOMAIN . "'");
    $knownKeys = implode(',', array_map('sqlString', knownLegacyOrderPiiKeys()));
    $addCheck('unchanged_known_legacy_order_pii',
        "SELECT COUNT(*) FROM $table t JOIN $source s ON s.meta_id=t.meta_id "
        . "WHERE TRIM(CAST(t.meta_value AS CHAR))<>'' AND t.meta_value=s.meta_value AND ("
        . "LOWER(t.meta_key) IN ($knownKeys) OR "
        . "LOWER(t.meta_key) REGEXP '(vat|piva|codice_fiscale|(^|_)cf$)')");
}
if ($hasTable($tComments)) {
    $comments = sqlIdentifier($tComments);
    $addCheck('comment_emails_outside_fake_domain',
        "SELECT COUNT(*) FROM $comments WHERE comment_author_email<>'' "
        . "AND comment_author_email NOT LIKE '%@" . FAKE_DOMAIN . "'");
    $addCheck('comment_ip_addresses',
        "SELECT COUNT(*) FROM $comments WHERE comment_author_IP NOT IN (''," . sqlString(FAKE_IP) . ')');
    if ($freeTextAction === 'redact') {
        $addCheck('unredacted_comment_bodies',
            "SELECT COUNT(*) FROM $comments WHERE comment_content<>'' "
            . "AND comment_content NOT LIKE '[comment redacted #%]' "
            . "AND comment_content NOT LIKE '[order note redacted #%]'");
    }
}
if ($hasTable($tCommentmeta)) {
    $addCheck('akismet_original_payloads',
        'SELECT COUNT(*) FROM ' . sqlIdentifier($tCommentmeta) . " WHERE LOWER(meta_key) LIKE 'akismet_%'");
}
if ($hpos && $hasTable($p . 'wc_orders')) {
    $orders = sqlIdentifier($p . 'wc_orders');
    $sourceOrders = sqlIdentifier($db['name']) . '.' . $orders;
    $addCheck('hpos_emails_outside_fake_domain',
        "SELECT COUNT(*) FROM $orders WHERE billing_email<>'' AND billing_email NOT LIKE '%@" . FAKE_DOMAIN . "'");
    $addCheck('hpos_ip_addresses',
        "SELECT COUNT(*) FROM $orders WHERE ip_address NOT IN (''," . sqlString(FAKE_IP) . ')');
    $addCheck('unchanged_hpos_emails',
        "SELECT COUNT(*) FROM $orders t JOIN $sourceOrders s ON s.id=t.id "
        . "WHERE t.billing_email<>'' AND t.billing_email=s.billing_email");
}
if ($hasTable($p . 'wc_order_addresses')) {
    $addresses = sqlIdentifier($p . 'wc_order_addresses');
    $sourceAddresses = sqlIdentifier($db['name']) . '.' . $addresses;
    $addCheck('hpos_address_emails_outside_fake_domain',
        "SELECT COUNT(*) FROM $addresses WHERE email<>'' AND email NOT LIKE '%@" . FAKE_DOMAIN . "'");
    $addCheck('unchanged_hpos_address_pii',
        "SELECT COUNT(*) FROM $addresses t JOIN $sourceAddresses s ON s.id=t.id WHERE "
        . "(t.email<>'' AND t.email=s.email) OR (t.phone<>'' AND t.phone=s.phone) "
        . "OR (t.address_1<>'' AND t.address_1=s.address_1)");
}
if ($hasTable($p . 'wc_customer_lookup')) {
    $lookup = sqlIdentifier($p . 'wc_customer_lookup');
    $addCheck('customer_lookup_emails_outside_fake_domain',
        "SELECT COUNT(*) FROM $lookup WHERE email<>'' AND email NOT LIKE '%@" . FAKE_DOMAIN . "'");
}
if ($hasTable($permissions)) {
    $addCheck('download_permission_emails_outside_fake_domain',
        'SELECT COUNT(*) FROM ' . sqlIdentifier($permissions)
        . " WHERE user_email<>'' AND user_email NOT LIKE '%@" . FAKE_DOMAIN . "'");
}
if ($hasTable($tOptions)) {
    $addCheck('named_secrets_in_options',
        'SELECT COUNT(*) FROM ' . sqlIdentifier($tOptions)
        . " WHERE option_value<>'' AND LOWER(option_name) REGEXP "
        . "'(api.?key|secret|password|passwd|private.?key|access.?token|refresh.?token|smtp|mailgun|sendgrid|stripe|paypal|braintree|nexi|satispay|recaptcha|aws_)'");
}
foreach ($plan as $table => $item) {
    if ($item['action'] === 'schema') {
        $addCheck('structure_only:' . $table, 'SELECT COUNT(*) FROM ' . sqlIdentifier($table));
    }
}
$addCheck('triggers_in_working_schema',
    'SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=' . sqlString($tmpDb));
foreach ($residualCandidates as $candidate) {
    if (($residualActions[$candidate['scope']][$candidate['key']] ?? '') !== 'redact') continue;
    $addCheck(
        'residual_redaction:' . $candidate['scope'] . ':' . $candidate['key'],
        'SELECT COUNT(*) FROM ' . sqlIdentifier($candidate['table'])
        . ' WHERE ' . sqlIdentifier($candidate['key_column']) . '=' . sqlString($candidate['key'])
        . ' AND ' . sqlIdentifier($candidate['value_column']) . '<>' . sqlString('[redacted]')
    );
}
if ($monthsBack > 0 && $hasTable($p . 'woocommerce_order_itemmeta') && $hasTable($p . 'woocommerce_order_items')) {
    $addCheck('orphan_order_itemmeta',
        'SELECT COUNT(*) FROM ' . sqlIdentifier($p . 'woocommerce_order_itemmeta') . ' m LEFT JOIN '
        . sqlIdentifier($p . 'woocommerce_order_items')
        . ' i ON i.order_item_id=m.order_item_id WHERE i.order_item_id IS NULL');
}
if (!$keepAttachments && $hasTable($tPosts)) {
    $addCheck('attachment_records',
        'SELECT COUNT(*) FROM ' . sqlIdentifier($tPosts) . " WHERE post_type='attachment'");
}

$failed = [];
$checkResults = [];
foreach ($checks as $id => $sqlCheck) {
    $count = (int)qScalar($sqlCheck, $tmpDb, '0');
    $status = $count === 0 ? 'passed' : 'failed';
    $checkResults[] = ['id' => $id, 'status' => $status, 'rows' => $count];
    if ($count === 0) ok($id);
    else {
        $failed[$id] = $count;
        out('  ' . c('✘', 'red') . ' ' . $id . ': ' . $count . ' rows');
    }
}
if ($failed) {
    fail("Verification failed: no artifact was published.\nThe working schema will be cleaned.");
}

// Collect evidence before removing the working schema.
$rowCounts = [];
foreach ($plan as $table => $item) {
    if ($item['action'] === 'exclude') continue;
    $rowCounts[$table] = (int)qScalar('SELECT COUNT(*) FROM ' . sqlIdentifier($table), $tmpDb, '0');
}
$attachmentRows = null;
if ($keepAttachments && isset($rowCounts[$tPosts])) {
    $attachmentRows = (int)qScalar(
        'SELECT COUNT(*) FROM ' . sqlIdentifier($tPosts) . " WHERE post_type='attachment'",
        $tmpDb,
        '0'
    );
}
if ($freeTextAction === 'preserve' && isset($rowCounts[$tComments])) {
    $preservedTextRows = (int)qScalar(
        'SELECT COUNT(*) FROM ' . sqlIdentifier($tComments)
        . " WHERE comment_content IS NOT NULL AND TRIM(CAST(comment_content AS CHAR))<>''",
        $tmpDb,
        '0'
    );
    foreach ($residualExceptions as &$exception) {
        if (($exception['scope'] ?? '') === 'comment_text') $exception['rows'] = $preservedTextRows;
    }
    unset($exception);
}

// ===========================================================================
// 9. FINAL DUMP, CHECKSUM, MANIFEST AND PUBLICATION
// ===========================================================================
step('Artifact generation in private staging');

$finalSql = $stageDir . '/final.sql';
createPrivateFile($finalSql);
$GLOBALS['CTX']['tmp_files'][] = $finalSql;
$output = [];
$errors = [];
$command = $baseDump . ' ' . escapeshellarg($tmpDb) . ' > ' . escapeshellarg($finalSql);
if (sh($command, $output, $errors) !== 0) fail("Final dump failed:\n" . diag($output, $errors));
if (!is_file($finalSql) || filesize($finalSql) === 0) fail('Final SQL dump is empty.');

$artifactStem = 'wp-anon-' . $runId;
$artifactName = $artifactStem . '.sql.gz';
$stageArtifact = $stageDir . '/' . $artifactName;
createPrivateFile($stageArtifact);
$GLOBALS['CTX']['tmp_files'][] = $stageArtifact;
$input = @fopen($finalSql, 'rb');
$gzip = @gzopen($stageArtifact, 'wb9');
if ($input === false || $gzip === false) fail('Could not open final dump compression streams.');
$compressionOk = true;
while (($line = fgets($input)) !== false) {
    $line = preg_replace('/\/\*!\d+ DEFINER=[^*]+\*\//', '', $line);
    if ($line === null || gzwrite($gzip, $line) === false) {
        $compressionOk = false;
        break;
    }
}
if (!feof($input)) $compressionOk = false;
if (!fclose($input)) $compressionOk = false;
if (!gzclose($gzip)) $compressionOk = false;
if (!$compressionOk || !is_file($stageArtifact) || filesize($stageArtifact) === 0) {
    fail('Compressed artifact could not be finalized.');
}
$testGzip = @gzopen($stageArtifact, 'rb');
if ($testGzip === false) fail('Compressed artifact validation failed.');
while (!gzeof($testGzip)) {
    if (gzread($testGzip, 1024 * 1024) === false) {
        gzclose($testGzip);
        fail('Compressed artifact is corrupt.');
    }
}
if (!gzclose($testGzip)) fail('Compressed artifact validation could not close the stream.');

$sha = hash_file('sha256', $stageArtifact);
if (!is_string($sha) || strlen($sha) !== 64) fail('Could not calculate artifact checksum.');
if (!@unlink($finalSql)) fail('Could not remove uncompressed final dump.');
$GLOBALS['CTX']['tmp_files'] = array_values(array_diff($GLOBALS['CTX']['tmp_files'], [$finalSql]));

$exceptions = [];
foreach ($residualExceptions as $exception) {
    $exceptions[] = [
        'kind' => 'operator_preserved_data',
        'scope' => $exception['scope'],
        'key' => $exception['key'] ?? null,
        'rows' => $exception['rows'] ?? null,
    ];
}
if ($keepAttachments) {
    $exceptions[] = [
        'kind' => 'attachments_retained',
        'scope' => 'posts',
        'rows' => $attachmentRows,
    ];
}
foreach ($copiedUnknown as $table) {
    $exceptions[] = [
        'kind' => 'unrecognized_table_copied',
        'scope' => $table,
        'rows' => $rowCounts[$table] ?? null,
    ];
}
if (!$hasPcntl && $opts['allow-unsafe-signals']) {
    $exceptions[] = ['kind' => 'unsafe_signal_cleanup_override', 'scope' => 'runtime', 'rows' => null];
}

$manifestTables = [];
foreach ($rowCounts as $table => $rows) {
    $manifestTables[$table] = ['action' => $plan[$table]['action'], 'rows' => $rows];
}
$manifest = [
    'generated_at' => gmdate('c'),
    'tool' => 'wp-anonymizer',
    'tool_version' => APP_VERSION,
    'source_database' => $db['name'],
    'table_prefix' => $p,
    'server_version' => $version,
    'privacy_status' => $exceptions ? 'verified_with_exceptions' : 'verified',
    'order_storage' => array_merge($hpos ? ['hpos'] : [], $legacy ? ['legacy'] : []),
    'orders_window_months' => $monthsBack ?: null,
    'attachments_kept' => $keepAttachments,
    'free_text_action' => $freeTextAction,
    'service_admin' => $serviceAdmin ? [
        'login' => $serviceAdmin['login'],
        'password_stored' => false,
    ] : null,
    'pseudonymization' => [
        'method' => 'value-based deterministic SHA-256 with a private 256-bit seed',
        'seed_fingerprint' => substr(hash('sha256', $seed), 0, 16),
        'legal_note' => 'Pseudonymized data remains subject to applicable data-protection obligations.',
    ],
    'artifact' => [
        'file' => $artifactName,
        'sha256' => $sha,
        'bytes' => (int)filesize($stageArtifact),
    ],
    'checks' => $checkResults,
    'exceptions' => $exceptions,
    'cleanup' => [
        'mode' => $GLOBALS['CTX']['tmp_db_owned'] ? 'drop_owned_schema' : $preparedCleanup,
        'status' => 'pending',
    ],
    'safety' => [
        'triggers_included' => false,
        'pcntl_available' => $hasPcntl,
        'unsafe_signals_override' => !$hasPcntl && $opts['allow-unsafe-signals'],
    ],
    'tables' => $manifestTables,
];

if (!cleanupDatabase()) fail('Working database cleanup failed; no artifact was published.');
$manifest['cleanup']['status'] = 'complete';

// Remove the generated SQL, seed-bearing script and credentials before publication.
if (is_file($sqlFile)) {
    $handle = @fopen($sqlFile, 'r+');
    if ($handle) {
        @ftruncate($handle, 0);
        @fclose($handle);
    }
    if (!@unlink($sqlFile)) fail('Could not remove anonymization SQL staging file.');
}
$GLOBALS['CTX']['tmp_files'] = array_values(array_diff($GLOBALS['CTX']['tmp_files'], [$sqlFile]));
if ($GLOBALS['CTX']['defaults_file'] && is_file($GLOBALS['CTX']['defaults_file'])) {
    if (!@unlink($GLOBALS['CTX']['defaults_file'])) fail('Could not remove MySQL credentials file before publication.');
    $GLOBALS['CTX']['defaults_file'] = null;
}

$checksumName = $artifactStem . '.sha256';
$stageChecksum = $stageDir . '/' . $checksumName;
$manifestName = $artifactStem . '.manifest.json';
$stageManifest = $stageDir . '/' . $manifestName;
$stageConfig = $stageDir . '/run-config.json';
writePrivateFile($stageChecksum, $sha . '  ' . $artifactName . "\n");
$GLOBALS['CTX']['tmp_files'][] = $stageChecksum;
writePrivateFile(
    $stageConfig,
    encodeJsonOrFail($buildRunConfig(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);
$GLOBALS['CTX']['tmp_files'][] = $stageConfig;
writePrivateFile(
    $stageManifest,
    encodeJsonOrFail($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);
$GLOBALS['CTX']['tmp_files'][] = $stageManifest;

$destinations = [
    [$stageArtifact, $outputDir . '/' . $artifactName, false],
    [$stageChecksum, $outputDir . '/' . $checksumName, false],
    [$stageConfig, $outputDir . '/run-config.json', true],
    // The manifest is intentionally last: its presence marks a complete export.
    [$stageManifest, $outputDir . '/' . $manifestName, false],
];
$published = [];
foreach ($destinations as $publication) {
    [, $destination, $replace] = $publication;
    if (is_link($destination) || (!$replace && file_exists($destination))) {
        fail('Refusing unsafe or existing publication path: ' . $destination);
    }
}
foreach ($destinations as $publication) {
    [$source, $destination, $replace] = $publication;
    if (!$replace) $GLOBALS['CTX']['tmp_files'][] = $destination;
    if (!@rename($source, $destination) || !@chmod($destination, 0600)) {
        fail('Could not publish private artifact: ' . $destination);
    }
    // run-config.json is useful independently (dry runs publish it too), so
    // retain the updated config if a later artifact publication fails.
    if (!$replace) $published[] = $destination;
}
if (!@rmdir($stageDir)) fail('Could not remove empty staging directory.');
$GLOBALS['CTX']['tmp_dirs'] = array_values(array_diff($GLOBALS['CTX']['tmp_dirs'], [$stageDir]));
$GLOBALS['CTX']['tmp_files'] = array_values(array_diff($GLOBALS['CTX']['tmp_files'], $published));
if ($newSeed) {
    $GLOBALS['CTX']['tmp_files'] = array_values(array_diff($GLOBALS['CTX']['tmp_files'], [$seedFile]));
}
cleanup();
if (!$GLOBALS['CTX']['cleaned']) fail('Final local cleanup failed.');

hr();
out(c('Export complete', 'green'));
out('  File       : ' . $outputDir . '/' . $artifactName);
out('  SHA-256    : ' . $sha);
out('  Privacy    : ' . $manifest['privacy_status']);
if ($serviceAdmin) {
    out('  Account    : ' . $serviceAdmin['login']);
    out('  Password   : ' . $serviceAdmin['pass'] . '  ' . c('(shown once; not stored)', 'yellow'));
}
out('  Seed       : ' . $seedFile . '  ' . c('(never send with the dump)', 'yellow'));
out('');
out('Encrypt the archive before transfer, for example:');
out('  gpg -c --cipher-algo AES256 ' . escapeshellarg($outputDir . '/' . $artifactName));
hr();
