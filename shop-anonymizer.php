#!/usr/bin/env php
<?php
/**
 * shop-anonymizer.php
 *
 * Exports an anonymized copy of a WordPress/WooCommerce shop database
 * WITHOUT EVER writing to the production database.
 *
 * Flow: preflight -> wizard -> read-only dump -> temporary schema ->
 *       anonymization -> verification -> final dump -> cleanup.
 *
 * Requirements: PHP >= 7.4 CLI, WP-CLI, mysql/mysqldump client.
 * Usage: php shop-anonymizer.php [--path=/var/www/shop] [--dry-run] [--output-dir=./anon-export]
 *
 * Xeader — Antonio Gatta <a.gatta@xeader.com>
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
// Global state used by the cleanup routine
// ---------------------------------------------------------------------------
$GLOBALS['CTX'] = [
        'defaults_file' => null,
        'tmp_db'        => null,
        'tmp_files'     => [],
        'keep_temp'     => false,
        'mysql'         => 'mysql',
        'cleaned'       => false,
];

register_shutdown_function('cleanup');
if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT,  function () { out("\nInterrupted by the user."); exit(130); });
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
// Command execution
// ---------------------------------------------------------------------------
function sh(string $cmd, ?array &$output = null, ?array &$errors = null): int
{
    $output = [];
    $errors  = [];
    $code    = 0;
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

/** Diagnostic message combining a command stdout and stderr. */
function diag(array $out, array $err): string
{
    $all = array_filter(array_merge($out, $err), function ($l) { return trim($l) !== ''; });
    return $all ? '  ' . implode("\n  ", $all) : '  (no output)';
}

/**
 * Strips the lines emitted by the PHP engine itself (extension warnings,
 * deprecations, stack traces) from WP-CLI output: they are not part of the value.
 */
function cleanOutput(array $lines): array
{
    $clean = [];
    foreach ($lines as $l) {
        $t = trim($l);
        if ($t === '') continue;
        if (preg_match('/^(PHP )?(Warning|Notice|Deprecated|Strict Standards|Fatal error|Parse error|Stack trace)\b/i', $t)) continue;
        if (preg_match('/^(PHP )?\s*#\d+\s/', $t)) continue;
        if (preg_match('/^\s*thrown in .* on line \d+/i', $t)) continue;
        $clean[] = $l;
    }
    return $clean;
}

function which(string $bin): ?string
{
    $o = [];
    if (sh('command -v ' . escapeshellarg($bin), $o) === 0 && !empty($o[0])) return trim($o[0]);
    return null;
}

/** Runs a SELECT and returns rows as an array of arrays (tab-separated). */
function q(string $sql, ?string $db = null): array
{
    $cmd = mysqlCmd($db) . ' -N -B -e ' . escapeshellarg($sql);
    $o = []; $e = [];
    if (sh($cmd, $o, $e) !== 0) fail("Query failed:\n  " . $sql . "\n" . diag($o, $e));
    $rows = [];
    foreach ($o as $line) {
        if ($line === '') continue;
        $rows[] = explode("\t", $line);
    }
    return $rows;
}

function qScalar(string $sql, ?string $db = null, string $fallback = ''): string
{
    $r = q($sql, $db);
    return isset($r[0][0]) ? $r[0][0] : $fallback;
}

/** Runs an SQL script from a file. Stops at the first error (no --force). */
function execSqlFile(string $file, ?string $db = null): void
{
    $cmd = mysqlCmd($db) . ' < ' . escapeshellarg($file);
    $o = []; $e = [];
    if (sh($cmd, $o, $e) !== 0) fail("SQL execution failed (" . basename($file) . "):\n" . diag($o, $e));
}

function mysqlCmd(?string $db = null): string
{
    $c = $GLOBALS['CTX'];
    $cmd = escapeshellarg($c['mysql'])
           . ' --defaults-extra-file=' . escapeshellarg($c['defaults_file'])
           . ' --default-character-set=utf8mb4';
    if ($db !== null) $cmd .= ' ' . escapeshellarg($db);
    return $cmd;
}

// ---------------------------------------------------------------------------
// Cleanup (always runs, including on error)
// ---------------------------------------------------------------------------
function cleanup(): void
{
    $c = &$GLOBALS['CTX'];
    if ($c['cleaned']) return;
    $c['cleaned'] = true;

    if ($c['tmp_db'] && !$c['keep_temp'] && $c['defaults_file'] && is_file($c['defaults_file'])) {
        // Guard: a DROP is never issued outside the temporary prefix.
        if (strpos($c['tmp_db'], TMP_PREFIX) === 0) {
            sh(mysqlCmd() . ' -e ' . escapeshellarg('DROP DATABASE IF EXISTS `' . $c['tmp_db'] . '`'));
        }
    }
    foreach ($c['tmp_files'] as $f) {
        if (is_file($f)) {
            // Best-effort overwrite of the raw dump before unlinking.
            $size = @filesize($f);
            if ($size !== false && $size < 512 * 1024 * 1024) {
                $fh = @fopen($f, 'r+');
                if ($fh) { @ftruncate($fh, 0); @fclose($fh); }
            }
            @unlink($f);
        }
    }
    if ($c['defaults_file'] && is_file($c['defaults_file'])) @unlink($c['defaults_file']);
}

// ---------------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------------
$opts = [
        'path'       => getcwd(),
        'output-dir' => getcwd() . '/anon-export',
        'seed-file'  => null,
        'unknown'    => null,
        'config'     => null,
        'dry-run'    => false,
        'keep-temp'  => false,
];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run')       { $opts['dry-run']   = true; continue; }
    if ($arg === '--keep-temp')     { $opts['keep-temp'] = true; continue; }
    if ($arg === '-h' || $arg === '--help') { usage(); exit(0); }
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) { $opts[$m[1]] = $m[2]; continue; }
    fail('Unknown argument: ' . $arg);
}
$GLOBALS['CTX']['keep_temp'] = (bool)$opts['keep-temp'];

if ($opts['unknown'] !== null && !in_array($opts['unknown'], ['schema', 'copy', 'exclude'], true)) {
    fail('--unknown accepts only: schema, copy, exclude');
}
$replay = [];
if ($opts['config'] !== null) {
    if (!is_file($opts['config'])) fail('Configuration file not found: ' . $opts['config']);
    $replay = json_decode((string)file_get_contents($opts['config']), true);
    if (!is_array($replay)) fail('Invalid configuration file: ' . $opts['config']);
}

function usage(): void
{
    out("\n  shop-anonymizer v" . APP_VERSION . "\n" . <<<TXT

  php shop-anonymizer.php [options]

  --path=DIR         WordPress installation root (default: current directory)
  --output-dir=DIR   Output directory (default: ./anon-export)
  --seed-file=FILE   File holding the pseudonymization seed
  --unknown=ACTION   Apply the same action to EVERY unrecognized table without
                     asking: schema | copy | exclude
  --config=FILE      Reuse the choices from a previous run-config.json
  --dry-run          Print the plan without creating anything or writing
  --keep-temp        Keep the temporary schema (debugging only)

TXT);
}

// ===========================================================================
// 1. PREFLIGHT
// ===========================================================================
hr();
out(c('  Shop Anonymizer ' . APP_VERSION . ' — anonymized export for test environments', 'bold'));
out('  This script performs NO writes on the production database.');
hr();

step('Preflight');

$wpBin = which('wp') ?? which('wp-cli') ?? which('wp-cli.phar');
if (!$wpBin) fail('WP-CLI not found in PATH.');
$mysqlBin = which('mysql') ?? which('mariadb');
$dumpBin  = which('mysqldump') ?? which('mariadb-dump');
if (!$mysqlBin) fail('mysql client not found in PATH.');
if (!$dumpBin)  fail('mysqldump not found in PATH.');
$GLOBALS['CTX']['mysql'] = $mysqlBin;
if (!function_exists('gzopen')) fail('zlib extension not available in this PHP CLI.');
ok('Binaries: wp, ' . basename($mysqlBin) . ', ' . basename($dumpBin));

$wpPath = rtrim($opts['path'], '/');
$GLOBALS['WP_PATH'] = $wpPath;
if (!is_dir($wpPath)) fail('WordPress path does not exist: ' . $wpPath);
// WP_CLI_PHP_ARGS silences PHP start-up warnings (extensions, deprecations):
// without it they end up mixed into the values read from wp-config.php.
$wp = "WP_CLI_PHP_ARGS='-d error_reporting=0 -d display_errors=0' "
      . escapeshellarg($wpBin) . ' --path=' . escapeshellarg($wpPath) . ' --skip-plugins --skip-themes';

$o = []; $e = [];
if (sh($wp . ' core is-installed', $o, $e) !== 0) {
    fail("No WordPress installation detected at {$wpPath}:\n" . diag(cleanOutput($o), $e));
}
ok('WordPress installation detected at ' . $wpPath);

function wpConfigGet(string $wp, string $key, string $type = 'constant'): string
{
    $o = [];
    if (sh($wp . ' config get ' . escapeshellarg($key) . ' --type=' . $type, $o) !== 0) {
        return '';
    }
    $lines = cleanOutput($o);
    if (!$lines) return '';
    // Warnings always precede the value: the last usable line is the right one.
    return trim((string)end($lines));
}

$db = [
        'name'   => wpConfigGet($wp, 'DB_NAME'),
        'user'   => wpConfigGet($wp, 'DB_USER'),
        'pass'   => wpConfigGet($wp, 'DB_PASSWORD'),
        'host'   => wpConfigGet($wp, 'DB_HOST') ?: 'localhost',
        'prefix' => wpConfigGet($wp, 'table_prefix', 'variable') ?: 'wp_',
];
if ($db['name'] === '') fail('Could not read DB_NAME from wp-config.php.');
foreach (['name' => 'DB_NAME', 'user' => 'DB_USER', 'host' => 'DB_HOST'] as $k => $label) {
    if (preg_match('/^(PHP )?(Warning|Notice|Deprecated|Fatal)/i', $db[$k]) || preg_match('/\s/', $db[$k])) {
        fail($label . " was read from wp-config.php in an unexpected form: \"" . $db[$k] . "\"\n"
             . "  Most likely spurious PHP or WP-CLI output. Check it with:\n"
             . "    wp config get " . $label . " --path=" . $GLOBALS['WP_PATH']);
    }
}

// DB_HOST may contain host:port or host:/path/to/socket
$host = $db['host']; $port = ''; $socket = '';
if (strpos($host, ':') !== false) {
    [$host, $tail] = explode(':', $host, 2);
    if (strpos($tail, '/') === 0) $socket = $tail; else $port = $tail;
}
if ($host === '') $host = 'localhost';

// Temporary credentials file: keeps the password off the command line (ps leak).
$oldUmask = umask(0177);
$defaultsFile = tempnam(sys_get_temp_dir(), 'anoncnf_');
if ($defaultsFile === false) fail('Could not create the temporary credentials file.');
$escape = function (string $v): string {
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
};
$cnf  = "[client]\n";
$cnf .= 'user=' . $escape($db['user']) . "\n";
$cnf .= 'password=' . $escape($db['pass']) . "\n";
$cnf .= 'host=' . $escape($host) . "\n";
if ($port !== '')   $cnf .= 'port=' . (int)$port . "\n";
if ($socket !== '') $cnf .= 'socket=' . $escape($socket) . "\n";
file_put_contents($defaultsFile, $cnf);
chmod($defaultsFile, 0600);
umask($oldUmask);
$GLOBALS['CTX']['defaults_file'] = $defaultsFile;

ok('DB connection: ' . $db['user'] . '@' . $host . ($port !== '' ? ':' . $port : '') . ($socket !== '' ? ' (socket ' . $socket . ')' : ''));
$version        = qScalar('SELECT VERSION()');
$versionComment = qScalar('SELECT @@version_comment');
$isMariaDB      = stripos($version, 'mariadb') !== false || stripos($versionComment, 'mariadb') !== false;
$isRDS          = str_has($host, '.rds.amazonaws.com');
ok('Server: ' . $version . ($isMariaDB ? ' (MariaDB)' : '') . ($isRDS ? ' — Amazon RDS' : ''));
ok('Source database: ' . $db['name'] . '  |  table prefix: ' . $db['prefix']);

// Data size and disk space
$sizeRow  = q("SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema = '" . addslashes($db['name']) . "'");
$dataSize = (int)($sizeRow[0][0] ?? 0);
ok('Estimated size: ' . fmtBytes($dataSize));

function fmtBytes(int $b): string
{
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = (float)$b;
    while ($v >= 1024 && $i < count($u) - 1) { $v /= 1024; $i++; }
    return sprintf('%.1f %s', $v, $u[$i]);
}

// CREATE DATABASE privilege: probed for real, not inferred from GRANTs
$probeDb    = TMP_PREFIX . 'probe_' . bin2hex(random_bytes(3));
$canCreate  = sh(mysqlCmd() . ' -e ' . escapeshellarg('CREATE DATABASE `' . $probeDb . '`')) === 0;
if ($canCreate) {
    sh(mysqlCmd() . ' -e ' . escapeshellarg('DROP DATABASE `' . $probeDb . '`'));
    ok('The DB user can create temporary schemas');
} else {
    warn('The DB user cannot create schemas (typical on RDS with an application user).');
    warn('An empty schema pre-created by your DBA will be required.');
}

// ===========================================================================
// 2. INVENTORY AND CLASSIFICATION
// ===========================================================================
step('Table inventory');

$p = $db['prefix'];
$tables = [];
foreach (q("SELECT table_name, COALESCE(table_rows,0), COALESCE(data_length+index_length,0)
            FROM information_schema.tables
            WHERE table_schema = '" . addslashes($db['name']) . "' AND table_type='BASE TABLE'
            ORDER BY table_name") as $r) {
    $tables[$r[0]] = ['rows' => (int)$r[1], 'size' => (int)$r[2]];
}
if (!$tables) fail('No tables found in the source database.');

$columns = [];
foreach (q("SELECT table_name, column_name FROM information_schema.columns
            WHERE table_schema = '" . addslashes($db['name']) . "'") as $r) {
    $columns[$r[0]][] = $r[1];
}
$hasTable = function (string $t) use ($tables): bool { return isset($tables[$t]); };
$hasCol   = function (string $t, string $col) use ($columns): bool {
    return isset($columns[$t]) && in_array($col, $columns[$t], true);
};

$hpos   = $hasTable($p . 'wc_orders');
$legacy = $hasTable($p . 'posts')
          && (int)qScalar("SELECT EXISTS(SELECT 1 FROM `" . $p . "posts` WHERE post_type LIKE 'shop_order%')", $db['name'], '0') > 0;
ok('Order storage: ' . ($hpos ? 'HPOS' : '') . ($hpos && $legacy ? ' + ' : '') . ($legacy ? 'legacy posts' : '') . (!$hpos && !$legacy ? 'no orders detected' : ''));

/**
 * Actions: anonymize | copy | schema (structure only) | exclude
 */
function classify(string $table, string $p): array
{
    $s = strpos($table, $p) === 0 ? substr($table, strlen($p)) : $table;

    $anonymize = ['users', 'usermeta', 'posts', 'postmeta', 'comments', 'options',
                  'wc_orders', 'wc_order_addresses', 'wc_orders_meta', 'wc_order_operational_data',
                  'wc_customer_lookup', 'wc_download_log',
                  'woocommerce_downloadable_product_permissions'];
    $truncate  = ['woocommerce_sessions', 'woocommerce_payment_tokens', 'woocommerce_payment_tokenmeta',
                  'woocommerce_api_keys', 'woocommerce_log', 'wc_admin_notes', 'wc_admin_note_actions',
                  'wc_webhooks', 'wc_rate_limits', 'wc_reserved_stock',
                  'actionscheduler_actions', 'actionscheduler_claims', 'actionscheduler_groups',
                  'actionscheduler_logs', 'yoast_indexable', 'yoast_indexable_hierarchy',
                  'yoast_seo_links', 'redirection_logs', 'redirection_404', 'wfhits', 'wflogins',
                  'statistics_visitor', 'statistics_useronline'];
    $copy      = ['commentmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'links',
                  'woocommerce_order_items', 'woocommerce_order_itemmeta', 'woocommerce_attribute_taxonomies',
                  'woocommerce_tax_rates', 'woocommerce_tax_rate_locations', 'woocommerce_shipping_zones',
                  'woocommerce_shipping_zone_locations', 'woocommerce_shipping_zone_methods',
                  'wc_product_meta_lookup', 'wc_tax_rate_classes', 'wc_category_lookup',
                  'wc_order_stats', 'wc_order_product_lookup', 'wc_order_tax_lookup', 'wc_order_coupon_lookup'];

    if (in_array($s, $anonymize, true)) return ['anonymize', 'core table holding personal data'];
    if (in_array($s, $truncate, true))  return ['schema', 'logs/sessions/secrets: structure only'];
    if (in_array($s, $copy, true))      return ['copy', 'no personal data expected'];

    return ['schema', c('UNRECOGNIZED TABLE', 'yellow') . ' — cautious default: structure only'];
}

$plan = [];
foreach ($tables as $t => $meta) {
    [$action, $reason] = classify($t, $p);
    $plan[$t] = ['action' => $action, 'reason' => $reason] + $meta;
}
$unknown = array_keys(array_filter($plan, function ($v) { return str_has($v['reason'], 'UNRECOGNIZED'); }));

out('');
out('  ' . str_pad('TABLE', 42) . str_pad('ROWS', 10) . str_pad('SIZE', 10) . 'ACTION');
foreach ($plan as $t => $v) {
    $label = ['anonymize' => c('anonymize', 'green'), 'copy' => 'copy', 'schema' => c('structure only', 'yellow'), 'exclude' => c('excluded', 'red')][$v['action']];
    out('  ' . str_pad($t, 42) . str_pad((string)$v['rows'], 10) . str_pad(fmtBytes($v['size']), 10) . $label);
}
out('');
ok(count($plan) . ' tables classified, ' . count($unknown) . ' unrecognized');

// ===========================================================================
// 3. WIZARD
// ===========================================================================
step('Export configuration');

$outputDir = prompt('Output directory', $opts['output-dir']);
if (!is_dir($outputDir) && !@mkdir($outputDir, 0700, true)) fail('Could not create ' . $outputDir);
$free = disk_free_space($outputDir);
$needed = (int)($dataSize * 2.5);
if ($free !== false && $free < $needed) {
    warn('Free space ' . fmtBytes((int)$free) . ', estimated requirement ' . fmtBytes($needed) . '.');
    if (!confirm('Continue anyway?', false)) exit(0);
} else {
    ok('Available space: ' . fmtBytes((int)$free));
}

// --- Unrecognized tables
$ACTIONS = ['schema', 'copy', 'exclude'];
$LABELS  = ['Structure only (recommended)', 'Copy all data', 'Exclude entirely'];

/** Table family: the first segment after the WordPress prefix. */
function family(string $table, string $p): string
{
    $s = strpos($table, $p) === 0 ? substr($table, strlen($p)) : $table;
    $parts = explode('_', $s);
    return count($parts) > 1 ? $parts[0] : $s;
}

function applyAction(array &$plan, string $t, string $action, string $reason): void
{
    $plan[$t]['action'] = $action;
    $plan[$t]['reason'] = $reason;
}

if ($unknown) {
    // 1) Replay from a previous run-config: no questions for already-decided tables
    $fromConfig = 0;
    if (!empty($replay['table_actions'])) {
        foreach ($unknown as $i => $t) {
            $a = $replay['table_actions'][$t] ?? null;
            if ($a !== null && in_array($a, $ACTIONS, true)) {
                applyAction($plan, $t, $a, 'from run-config');
                unset($unknown[$i]);
                $fromConfig++;
            }
        }
        $unknown = array_values($unknown);
        if ($fromConfig) ok($fromConfig . ' tables resolved from the supplied run-config');
    }

    // 2) Action forced from the command line
    if ($unknown && $opts['unknown'] !== null) {
        foreach ($unknown as $t) applyAction($plan, $t, $opts['unknown'], '--unknown');
        ok(count($unknown) . ' unrecognized tables set to "' . $opts['unknown'] . '" from the command line');
        $unknown = [];
    }
}

if ($unknown) {
    $groups = [];
    foreach ($unknown as $t) $groups[family($t, $p)][] = $t;
    ksort($groups);

    out('');
    warn(count($unknown) . ' tables are not in the rule catalogue, across ' . count($groups) . ' groups.');
    warn('The cautious default is to export their structure only: they may hold');
    warn('personal data belonging to custom plugins.');
    out('');
    foreach ($groups as $g => $ts) {
        $rows = array_sum(array_map(function ($t) use ($plan) { return $plan[$t]['rows']; }, $ts));
        $size = array_sum(array_map(function ($t) use ($plan) { return $plan[$t]['size']; }, $ts));
        out('    ' . c(str_pad($g . '_*', 24), 'bold') . str_pad(count($ts) . ' tables', 14)
            . str_pad($rows . ' rows', 16) . fmtBytes($size));
    }
    out('');

    $mode = choose('How do you want to proceed?', [
            'Structure only for all of them (recommended, no further questions)',
            'Exclude all of them',
            'Decide per plugin group (' . count($groups) . ' questions)',
            'Decide table by table (' . count($unknown) . ' questions)',
    ], 0);

    if ($mode === 0 || $mode === 1) {
        $a = $mode === 0 ? 'schema' : 'exclude';
        foreach ($unknown as $t) applyAction($plan, $t, $a, 'bulk choice');
        ok(count($unknown) . ' tables set to "' . $a . '"');

    } elseif ($mode === 2) {
        $sticky = null;
        foreach ($groups as $g => $ts) {
            if ($sticky !== null) { foreach ($ts as $t) applyAction($plan, $t, $sticky, 'extended choice'); continue; }
            $rows = array_sum(array_map(function ($t) use ($plan) { return $plan[$t]['rows']; }, $ts));
            $r = chooseSticky(
                    "\n  Group " . c($g . '_*', 'bold') . ' — ' . count($ts) . ' tables, ' . $rows . ' rows'
                    . "\n    " . implode(', ', array_slice($ts, 0, 6)) . (count($ts) > 6 ? ', …' : ''),
                    $LABELS, 0
            );
            $a = $ACTIONS[$r['index']];
            foreach ($ts as $t) applyAction($plan, $t, $a, 'group choice');
            if ($r['all']) { $sticky = $a; ok('Choice "' . $a . '" applied to every remaining group as well'); }
        }

    } else {
        $sticky = null;
        foreach ($unknown as $t) {
            if ($sticky !== null) { applyAction($plan, $t, $sticky, 'extended choice'); continue; }
            $r = chooseSticky(
                    "\n  Table " . c($t, 'bold') . ' (' . $plan[$t]['rows'] . ' rows, ' . fmtBytes($plan[$t]['size']) . ')',
                    $LABELS, 0
            );
            $a = $ACTIONS[$r['index']];
            applyAction($plan, $t, $a, 'operator choice');
            if ($r['all']) { $sticky = $a; ok('Choice "' . $a . '" applied to every remaining table as well'); }
        }
    }

    // Recap of the tables that keep their data, so risky choices get a second look
    $copied = array_keys(array_filter($plan, function ($v) { return $v['action'] === 'copy' && str_has($v['reason'], 'choice'); }));
    if ($copied) {
        out('');
        warn('Copied in full by explicit choice: ' . implode(', ', $copied));
    }
}

// --- Time-based subsetting
$monthsBack = 0;
$cfgMonths  = isset($replay['months_back']) ? (int)$replay['months_back'] : 0;
if ($hpos || $legacy) {
    if (confirm("\n  Limit the export to the most recent orders?", $cfgMonths > 0)) {
        $monthsBack = (int)prompt('Months to keep', (string)($cfgMonths > 0 ? $cfgMonths : 12));
        if ($monthsBack < 1) $monthsBack = 0;
    }
}

// --- Service administrator account
$serviceAdmin = null;
if (confirm("\n  Create a service administrator account in the dump?",
        array_key_exists('service_admin_login', $replay) ? $replay['service_admin_login'] !== null : true)) {
    // Randomized on every run: a predictable service login on a shared test
    // environment is a standing invitation.
    $login = prompt('Login', 'admin_test_' . bin2hex(random_bytes(2)));
    $pass  = prompt('Password', 'anon-' . bin2hex(random_bytes(4)));
    $o = [];
    // wp eval is read-only here: it only computes the hash with the algorithm of this install.
    $hash = '';
    if (sh($wp . ' eval ' . escapeshellarg('echo wp_hash_password("' . addslashes($pass) . '");'), $o) === 0) {
        $lines = cleanOutput($o);
        $hash  = $lines ? trim((string)end($lines)) : '';
    }
    if ($hash === '' || strlen($hash) < 20) {
        warn('Could not compute the password hash: the service account will not be created.');
    } else {
        $serviceAdmin = ['login' => $login, 'pass' => $pass, 'hash' => $hash];
        ok('Hash computed with the algorithm of this installation');
    }
}

// --- Media library
$keepAttachments = confirm("\n  Keep the media library records (attachments)?",
        isset($replay['keep_attachments']) ? (bool)$replay['keep_attachments'] : true);

// --- Persistent seed
$seedFile = $opts['seed-file'] ?: $outputDir . '/.anon-seed';
if (is_file($seedFile)) {
    $seed = trim((string)file_get_contents($seedFile));
    ok('Existing seed reused: fake values will match the previous exports');
} else {
    $seed = bin2hex(random_bytes(32));
    $old = umask(0177);
    file_put_contents($seedFile, $seed);
    chmod($seedFile, 0600);
    umask($old);
    ok('New seed generated in ' . $seedFile);
}
warn('The seed makes the transformation deterministic: keep it as a secret');
warn('and do NOT hand it over together with the dump.');

// --- Temporary schema
$stamp = date('Ymd-His');
if ($canCreate) {
    $tmpDb = TMP_PREFIX . preg_replace('/[^a-z0-9_]/i', '_', substr($db['name'], 0, 24)) . '_' . date('YmdHis');
} else {
    out('');
    $tmpDb = prompt('Name of the empty schema already prepared (must start with ' . TMP_PREFIX . ')');
}
if (strpos($tmpDb, TMP_PREFIX) !== 0) fail('The working schema must start with "' . TMP_PREFIX . '".');
if (strtolower($tmpDb) === strtolower($db['name'])) fail('The working schema matches the production database. Aborted.');

// ===========================================================================
// 4. SUMMARY AND CONFIRMATION
// ===========================================================================
$counts = array_count_values(array_column($plan, 'action'));
step('Summary');
hr();
out('  Source (read-only)  : ' . $db['name'] . ' @ ' . $host);
out('  Working schema      : ' . $tmpDb . ($canCreate ? ' (created, then dropped)' : ' (pre-existing, will be emptied)'));
out('  Output              : ' . $outputDir);
out('  Tables              : ' . ($counts['anonymize'] ?? 0) . ' anonymized, '
    . ($counts['copy'] ?? 0) . ' copied, '
    . ($counts['schema'] ?? 0) . ' structure only, '
    . ($counts['exclude'] ?? 0) . ' excluded');
out('  Orders              : ' . ($monthsBack ? 'last ' . $monthsBack . ' months' : 'all'));
out('  Media library       : ' . ($keepAttachments ? 'kept' : 'removed'));
out('  Service account     : ' . ($serviceAdmin ? $serviceAdmin['login'] . ' / ' . $serviceAdmin['pass'] : 'none'));
out('  Mode                : ' . ($opts['dry-run'] ? c('DRY-RUN (no writes)', 'yellow') : 'full run'));
hr();
out('  ' . c('No write statement will be sent to ' . $db['name'] . '.', 'green'));
hr();

if ($opts['dry-run']) {
    $dryCfg = [
            'output_dir' => $outputDir, 'months_back' => $monthsBack, 'keep_attachments' => $keepAttachments,
            'service_admin_login' => $serviceAdmin['login'] ?? null,
            'table_actions' => array_map(function ($v) { return $v['action']; }, $plan),
    ];
    $dryFile = $outputDir . '/run-config.json';
    file_put_contents($dryFile, json_encode($dryCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    out("\n  Dry-run complete. No schema touched, no dump produced.");
    out('  Choices saved to ' . $dryFile . ' — reuse them with:');
    out('    php shop-anonymizer.php --path=... --config=' . $dryFile . "\n");
    exit(0);
}

if (strtoupper(prompt("\n  Type " . c('ANONYMIZE', 'bold') . ' to proceed')) !== 'ANONYMIZE') {
    out('  Cancelled.');
    exit(0);
}

// ===========================================================================
// 5. READ-ONLY EXTRACTION
// ===========================================================================
step('Extraction from the production database (read-only)');

$dumpFlags = ['--single-transaction', '--quick', '--skip-lock-tables', '--default-character-set=utf8mb4', '--hex-blob'];
$helpOut = [];
sh(escapeshellarg($dumpBin) . ' --help', $helpOut);
$help = implode("\n", $helpOut);
if (str_has($help, 'no-tablespaces'))    $dumpFlags[] = '--no-tablespaces';
if (str_has($help, 'set-gtid-purged'))   $dumpFlags[] = '--set-gtid-purged=OFF';
if (str_has($help, 'column-statistics')) $dumpFlags[] = '--column-statistics=0';

$withData   = [];
$schemaOnly = [];
foreach ($plan as $t => $v) {
    if ($v['action'] === 'exclude') continue;
    if ($v['action'] === 'schema') $schemaOnly[] = $t; else $withData[] = $t;
}

$e = [];
$rawDump = $outputDir . '/.raw-' . $stamp . '.sql';
$GLOBALS['CTX']['tmp_files'][] = $rawDump;
$old = umask(0177);
touch($rawDump);
chmod($rawDump, 0600);
umask($old);

$base = escapeshellarg($dumpBin) . ' --defaults-extra-file=' . escapeshellarg($defaultsFile) . ' ' . implode(' ', $dumpFlags);

$cmd1 = $base . ' ' . escapeshellarg($db['name']) . ' ' . implode(' ', array_map('escapeshellarg', $withData))
        . ' > ' . escapeshellarg($rawDump);
$o = [];
if (sh($cmd1, $o, $e) !== 0) fail("mysqldump failed:\n" . diag($o, $e));
ok(count($withData) . ' tables extracted with data');

if ($schemaOnly) {
    $cmd2 = $base . ' --no-data ' . escapeshellarg($db['name']) . ' ' . implode(' ', array_map('escapeshellarg', $schemaOnly))
            . ' >> ' . escapeshellarg($rawDump);
    if (sh($cmd2, $o, $e) !== 0) fail("mysqldump (structure only) failed:\n" . diag($o, $e));
    ok(count($schemaOnly) . ' tables extracted without data');
}
ok('Raw dump: ' . fmtBytes((int)filesize($rawDump)) . ' (temporary, will be removed)');

// ===========================================================================
// 6. TEMPORARY SCHEMA
// ===========================================================================
step('Loading into the temporary schema');

if ($canCreate) {
    $o = [];
    if (sh(mysqlCmd() . ' -e ' . escapeshellarg('CREATE DATABASE `' . $tmpDb . '` CHARACTER SET utf8mb4'), $o, $e) !== 0) {
        fail("Temporary schema creation failed:\n" . diag($o, $e));
    }
} else {
    $exists = (int)qScalar("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='" . addslashes($tmpDb) . "'", null, '0');
    if ($exists === 0) fail('Schema ' . $tmpDb . ' does not exist or is not visible to this user.');
    $n = (int)qScalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='" . addslashes($tmpDb) . "'", null, '0');
    if ($n > 0)  fail('Schema ' . $tmpDb . ' already holds ' . $n . ' tables: please supply an empty one.');
}
$GLOBALS['CTX']['tmp_db'] = $tmpDb;

$o = [];
if (sh(mysqlCmd($tmpDb) . ' < ' . escapeshellarg($rawDump), $o, $e) !== 0) {
    fail("Import into the temporary schema failed:\n" . diag($o, $e));
}
ok('Import completed into ' . $tmpDb);

// The raw dump holding real data is no longer needed: remove it right away.
$fh = @fopen($rawDump, 'r+'); if ($fh) { ftruncate($fh, 0); fclose($fh); }
@unlink($rawDump);
ok('Raw dump removed');

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

$sqlFile = $outputDir . '/.anon-' . $stamp . '.sql';
$GLOBALS['CTX']['tmp_files'][] = $sqlFile;
$sql = [];

// --- Guard: fails hard if the active schema is not the temporary one
$sql[] = "SET NAMES utf8mb4;";
$sql[] = "SET SESSION sql_mode='STRICT_ALL_TABLES';";
$sql[] = "SET @seed := '" . $seed . "';";
$sql[] = "SET @guard := (SELECT IF(DATABASE() LIKE '" . TMP_PREFIX . "%', 1, NULL));";
$sql[] = "CREATE TEMPORARY TABLE _anon_guard (ok INT NOT NULL);";
$sql[] = "-- If the active schema is not a temporary one this INSERT fails and the script stops here.";
$sql[] = "INSERT INTO _anon_guard (ok) VALUES (@guard);";
$sql[] = "DROP TEMPORARY TABLE _anon_guard;";
$sql[] = "SET SESSION sql_mode='';";
$sql[] = "SET FOREIGN_KEY_CHECKS=0;";

// --- Dictionaries
$dicts = ['_anon_first' => $firstNames, '_anon_last' => $lastNames, '_anon_city' => $cities, '_anon_street' => $streets];
foreach ($dicts as $name => $values) {
    $sql[] = "DROP TABLE IF EXISTS `$name`;";
    $sql[] = "CREATE TABLE `$name` (n INT PRIMARY KEY, v VARCHAR(64)) ENGINE=InnoDB;";
    $rows = [];
    foreach (array_slice($values, 0, DICT_N) as $i => $v) {
        $rows[] = '(' . $i . ",'" . str_replace("'", "''", $v) . "')";
    }
    $sql[] = "INSERT INTO `$name` (n, v) VALUES " . implode(',', $rows) . ';';
}

/** Deterministic hash expression derived from the seed. */
function h(string $key, string $salt): string
{
    return "SHA2(CONCAT(@seed,'{$salt}',COALESCE(" . $key . ",'')),256)";
}
function pick(string $dict, string $key, string $salt): string
{
    return "(SELECT v FROM `{$dict}` WHERE n = CONV(SUBSTR(" . h($key, $salt) . ",1,6),16,10) % " . DICT_N . ")";
}
function fakeEmail(string $key): string
{
    return "CONCAT('u', SUBSTR(" . h($key, 'email') . ",1,12), '@" . FAKE_DOMAIN . "')";
}
function fakePhone(string $key): string
{
    return "CONCAT('+39 3', LPAD(CONV(SUBSTR(" . h($key, 'phone') . ",1,8),16,10) % 100000000, 8, '0'))";
}
function fakeZip(string $key): string
{
    return "LPAD(CONV(SUBSTR(" . h($key, 'zip') . ",1,6),16,10) % 100000, 5, '0')";
}
function fakeAddr(string $key): string
{
    return "CONCAT('Via ', " . pick('_anon_street', $key, 'street') . ", ' ', 1 + CONV(SUBSTR(" . h($key, 'civ') . ",1,4),16,10) % 150)";
}
function fakeVat(string $key): string
{
    return "LPAD(CONV(SUBSTR(" . h($key, 'vat') . ",1,10),16,10) % 100000000000, 11, '0')";
}

$act = function (string $t) use ($plan): bool {
    return isset($plan[$t]) && $plan[$t]['action'] === 'anonymize';
};

// --- users
$tUsers = $p . 'users';
if ($act($tUsers)) {
    $sql[] = "UPDATE `$tUsers` SET
        user_login    = CONCAT('user', ID),
        user_pass     = '!ANONYMIZED!',
        user_nicename = CONCAT('user-', ID),
        user_email    = " . fakeEmail('ID') . ",
        user_url      = '',
        display_name  = CONCAT(" . pick('_anon_first', 'ID', 'fn') . ", ' ', " . pick('_anon_last', 'ID', 'ln') . "),
        user_activation_key = '';";
}

// --- usermeta
$tUmeta = $p . 'usermeta';
if ($act($tUmeta)) {
    $sql[] = "DELETE FROM `$tUmeta` WHERE meta_key IN ('session_tokens','_new_email','_password_reset_key','wp_user-settings');";
    $sql[] = "UPDATE `$tUmeta` SET meta_value = " . pick('_anon_first', 'user_id', 'fn') . "
              WHERE meta_key IN ('first_name','billing_first_name','shipping_first_name','nickname');";
    $sql[] = "UPDATE `$tUmeta` SET meta_value = " . pick('_anon_last', 'user_id', 'ln') . "
              WHERE meta_key IN ('last_name','billing_last_name','shipping_last_name');";
    $sql[] = "UPDATE `$tUmeta` SET meta_value = " . fakeEmail('user_id') . " WHERE meta_key IN ('billing_email','shipping_email');";
    $sql[] = "UPDATE `$tUmeta` SET meta_value = " . fakePhone('user_id') . " WHERE meta_key IN ('billing_phone','shipping_phone');";
    $sql[] = "UPDATE `$tUmeta` SET meta_value = " . fakeAddr('user_id') . " WHERE meta_key IN ('billing_address_1','shipping_address_1');";
    $sql[] = "UPDATE `$tUmeta` SET meta_value = '' WHERE meta_key IN ('billing_address_2','shipping_address_2','billing_company','shipping_company','description');";
    $sql[] = "UPDATE `$tUmeta` SET meta_value = " . pick('_anon_city', 'user_id', 'city') . " WHERE meta_key IN ('billing_city','shipping_city');";
    $sql[] = "UPDATE `$tUmeta` SET meta_value = " . fakeZip('user_id') . " WHERE meta_key IN ('billing_postcode','shipping_postcode');";
    $sql[] = "UPDATE `$tUmeta` SET meta_value = " . fakeVat('user_id') . " WHERE meta_key LIKE '%vat%' OR meta_key LIKE '%codice_fiscale%' OR meta_key LIKE '%_cf' OR meta_key LIKE '%piva%';";
}

// --- posts (legacy orders) and attachments
$tPosts = $p . 'posts';
if ($act($tPosts)) {
    $sql[] = "UPDATE `$tPosts` SET post_excerpt = '', post_password = ''
              WHERE post_type LIKE 'shop_order%' OR post_type = 'shop_subscription';";
    if (!$keepAttachments) {
        $sql[] = "DELETE FROM `$tPosts` WHERE post_type = 'attachment';";
    }
}

// --- postmeta (legacy orders)
$tPmeta = $p . 'postmeta';
if ($act($tPmeta)) {
    $sql[] = "UPDATE `$tPmeta` SET meta_value = " . pick('_anon_first', 'post_id', 'fn') . " WHERE meta_key IN ('_billing_first_name','_shipping_first_name');";
    $sql[] = "UPDATE `$tPmeta` SET meta_value = " . pick('_anon_last', 'post_id', 'ln') . "  WHERE meta_key IN ('_billing_last_name','_shipping_last_name');";
    $sql[] = "UPDATE `$tPmeta` SET meta_value = " . fakeEmail('post_id') . " WHERE meta_key = '_billing_email';";
    $sql[] = "UPDATE `$tPmeta` SET meta_value = " . fakePhone('post_id') . " WHERE meta_key IN ('_billing_phone','_shipping_phone');";
    $sql[] = "UPDATE `$tPmeta` SET meta_value = " . fakeAddr('post_id') . "  WHERE meta_key IN ('_billing_address_1','_shipping_address_1');";
    $sql[] = "UPDATE `$tPmeta` SET meta_value = " . pick('_anon_city', 'post_id', 'city') . " WHERE meta_key IN ('_billing_city','_shipping_city');";
    $sql[] = "UPDATE `$tPmeta` SET meta_value = " . fakeZip('post_id') . "   WHERE meta_key IN ('_billing_postcode','_shipping_postcode');";
    $sql[] = "UPDATE `$tPmeta` SET meta_value = '' WHERE meta_key IN ('_billing_address_2','_shipping_address_2','_billing_company','_shipping_company','_customer_user_agent','_billing_vat','_customer_note');";
    $sql[] = "UPDATE `$tPmeta` SET meta_value = '" . FAKE_IP . "' WHERE meta_key = '_customer_ip_address';";
    $sql[] = "UPDATE `$tPmeta` SET meta_value = CONCAT('anon-', post_id) WHERE meta_key IN ('_transaction_id','_order_key','_payment_tokens','_stripe_customer_id','_stripe_source_id','_paypal_transaction_id');";
    $sql[] = "DELETE FROM `$tPmeta` WHERE meta_key LIKE '%_token%' OR meta_key LIKE '%_secret%' OR meta_key LIKE '%api_key%';";
}

// --- comments (order notes and reviews)
$tComm = $p . 'comments';
if ($act($tComm)) {
    $sql[] = "UPDATE `$tComm` SET
        comment_author       = CONCAT(" . pick('_anon_first', 'comment_ID', 'fn') . ", ' ', SUBSTR(" . pick('_anon_last', 'comment_ID', 'ln') . ",1,1), '.'),
        comment_author_email = " . fakeEmail('comment_ID') . ",
        comment_author_url   = '',
        comment_author_IP    = '" . FAKE_IP . "',
        comment_agent        = 'anonymized';";
    $sql[] = "UPDATE `$tComm` SET comment_content = CONCAT('[nota ordine anonimizzata #', comment_ID, ']') WHERE comment_type = 'order_note';";
}

// --- HPOS
if ($hpos) {
    $tO = $p . 'wc_orders';
    if ($act($tO)) {
        $sql[] = "UPDATE `$tO` SET
            billing_email = " . fakeEmail('id') . ",
            ip_address    = '" . FAKE_IP . "',
            user_agent    = 'anonymized',
            customer_note = CASE WHEN customer_note IS NULL OR customer_note = '' THEN customer_note ELSE CONCAT('[nota cliente anonimizzata #', id, ']') END,
            transaction_id = CASE WHEN transaction_id IS NULL OR transaction_id = '' THEN transaction_id ELSE CONCAT('anon-', id) END;";
    }
    $tA = $p . 'wc_order_addresses';
    if ($act($tA)) {
        $sql[] = "UPDATE `$tA` SET
            first_name = " . pick('_anon_first', 'id', 'fn') . ",
            last_name  = " . pick('_anon_last', 'id', 'ln') . ",
            company    = '',
            address_1  = " . fakeAddr('id') . ",
            address_2  = '',
            city       = " . pick('_anon_city', 'id', 'city') . ",
            postcode   = " . fakeZip('id') . ",
            email      = CASE WHEN email IS NULL OR email = '' THEN email ELSE " . fakeEmail('id') . " END,
            phone      = CASE WHEN phone IS NULL OR phone = '' THEN phone ELSE " . fakePhone('id') . " END;";
    }
    $tM = $p . 'wc_orders_meta';
    if ($act($tM)) {
        $sql[] = "DELETE FROM `$tM` WHERE meta_key LIKE '%_token%' OR meta_key LIKE '%_secret%' OR meta_key LIKE '%api_key%' OR meta_key LIKE '%customer_id%';";
        $sql[] = "UPDATE `$tM` SET meta_value = " . fakeVat('order_id') . " WHERE meta_key LIKE '%vat%' OR meta_key LIKE '%codice_fiscale%' OR meta_key LIKE '%piva%';";
    }
    $tOp = $p . 'wc_order_operational_data';
    if ($act($tOp)) {
        $sql[] = "UPDATE `$tOp` SET order_key = CONCAT('wc_order_anon', order_id);";
    }
    $tCl = $p . 'wc_customer_lookup';
    if ($act($tCl)) {
        $sql[] = "UPDATE `$tCl` SET
            username   = CONCAT('user', customer_id),
            first_name = " . pick('_anon_first', 'customer_id', 'fn') . ",
            last_name  = " . pick('_anon_last', 'customer_id', 'ln') . ",
            email      = " . fakeEmail('customer_id') . ",
            city       = " . pick('_anon_city', 'customer_id', 'city') . ",
            postcode   = " . fakeZip('customer_id') . ";";
    }
}

// --- Download log and permissions
$tDl = $p . 'wc_download_log';
if ($act($tDl) && $hasCol($tDl, 'user_ip_address')) {
    $sql[] = "UPDATE `$tDl` SET user_ip_address = '" . FAKE_IP . "';";
}
$tDp = $p . 'woocommerce_downloadable_product_permissions';
if ($act($tDp)) {
    $sql[] = "UPDATE `$tDp` SET user_email = " . fakeEmail('user_id') . ";";
}

// --- options: secrets and transients
$tOpt = $p . 'options';
if ($act($tOpt)) {
    $sql[] = "DELETE FROM `$tOpt` WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%';";
    $secretPatterns = ['%api_key%','%apikey%','%_secret%','%secret_key%','%password%','%passwd%','%private_key%',
                       '%access_token%','%refresh_token%','%_token%','%smtp%','%mailgun%','%sendgrid%','%license%',
                       '%stripe%','%paypal%','%braintree%','%nexi%','%satispay%','%recaptcha%','%_salt%','%aws_%'];
    $where = implode(' OR ', array_map(function ($x) { return "option_name LIKE '" . $x . "'"; }, $secretPatterns));
    $sql[] = "UPDATE `$tOpt` SET option_value = '' WHERE (" . $where . ") AND option_name NOT IN ('siteurl','home','blogname','admin_email');";
    $sql[] = "UPDATE `$tOpt` SET option_value = 'test@" . FAKE_DOMAIN . "' WHERE option_name IN ('admin_email','new_admin_email','woocommerce_stock_email_recipient');";
}

// --- Time-based subsetting
if ($monthsBack > 0) {
    if ($hpos) {
        $sql[] = "CREATE TEMPORARY TABLE _anon_drop (id BIGINT PRIMARY KEY);";
        $sql[] = "INSERT INTO _anon_drop SELECT id FROM `" . $p . "wc_orders` WHERE date_created_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . $monthsBack . " MONTH);";
        foreach ([[$p . 'wc_order_addresses', 'order_id'], [$p . 'wc_orders_meta', 'order_id'],
                  [$p . 'wc_order_operational_data', 'order_id'], [$p . 'wc_order_stats', 'order_id'],
                  [$p . 'wc_order_product_lookup', 'order_id'], [$p . 'wc_order_tax_lookup', 'order_id'],
                  [$p . 'wc_order_coupon_lookup', 'order_id'], [$p . 'woocommerce_order_items', 'order_id']] as [$t, $col]) {
            if ($hasTable($t) && $hasCol($t, $col)) {
                $sql[] = "DELETE FROM `$t` WHERE `$col` IN (SELECT id FROM _anon_drop);";
            }
        }
        $sql[] = "DELETE FROM `" . $p . "wc_orders` WHERE id IN (SELECT id FROM _anon_drop);";
        $sql[] = "DROP TEMPORARY TABLE _anon_drop;";
    }
    if ($legacy) {
        $sql[] = "CREATE TEMPORARY TABLE _anon_drop_legacy (id BIGINT PRIMARY KEY);";
        $sql[] = "INSERT INTO _anon_drop_legacy SELECT ID FROM `$tPosts` WHERE post_type LIKE 'shop_order%' AND post_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . $monthsBack . " MONTH);";
        $sql[] = "DELETE FROM `$tPmeta` WHERE post_id IN (SELECT id FROM _anon_drop_legacy);";
        $sql[] = "DELETE FROM `$tComm` WHERE comment_post_ID IN (SELECT id FROM _anon_drop_legacy);";
        $sql[] = "DELETE FROM `$tPosts` WHERE ID IN (SELECT id FROM _anon_drop_legacy);";
        $sql[] = "DROP TEMPORARY TABLE _anon_drop_legacy;";
    }
}

// --- Service account
if ($serviceAdmin && $hasTable($tUsers)) {
    $login = addslashes($serviceAdmin['login']);
    $hash  = addslashes($serviceAdmin['hash']);
    $sql[] = "INSERT INTO `$tUsers` (user_login, user_pass, user_nicename, user_email, user_registered, display_name)
              VALUES ('$login', '$hash', '$login', '$login@" . FAKE_DOMAIN . "', UTC_TIMESTAMP(), 'Xeader Test');";
    $sql[] = "SET @svc := LAST_INSERT_ID();";
    $sql[] = "INSERT INTO `$tUmeta` (user_id, meta_key, meta_value) VALUES (@svc, '" . $p . "capabilities', 'a:1:{s:13:\"administrator\";b:1;}');";
    $sql[] = "INSERT INTO `$tUmeta` (user_id, meta_key, meta_value) VALUES (@svc, '" . $p . "user_level', '10');";
}

// --- Drop dictionaries
foreach (array_keys($dicts) as $name) {
    $sql[] = "DROP TABLE IF EXISTS `$name`;";
}
$sql[] = "SET FOREIGN_KEY_CHECKS=1;";

$old = umask(0177);
file_put_contents($sqlFile, implode("\n", $sql) . "\n");
chmod($sqlFile, 0600);
umask($old);

execSqlFile($sqlFile, $tmpDb);
ok(count($sql) . ' statements applied to the temporary schema');

// ===========================================================================
// 8. VERIFICATION
// ===========================================================================
step('Residual data checks');

$checks = [];
if ($hasTable($tUsers)) {
    $checks['user emails'] = "SELECT COUNT(*) FROM `$tUsers` WHERE user_email NOT LIKE '%@" . FAKE_DOMAIN . "'";
    $checks['password hashes'] = "SELECT COUNT(*) FROM `$tUsers` WHERE user_pass <> '!ANONYMIZED!'" . ($serviceAdmin ? " AND user_login <> '" . addslashes($serviceAdmin['login']) . "'" : '');
}
if ($hpos && $hasTable($p . 'wc_orders')) {
    $checks['HPOS order emails'] = "SELECT COUNT(*) FROM `" . $p . "wc_orders` WHERE billing_email <> '' AND billing_email NOT LIKE '%@" . FAKE_DOMAIN . "'";
    $checks['HPOS order IP addresses']    = "SELECT COUNT(*) FROM `" . $p . "wc_orders` WHERE ip_address NOT IN ('', '" . FAKE_IP . "')";
}
if ($legacy && $hasTable($tPmeta)) {
    $checks['legacy order emails'] = "SELECT COUNT(*) FROM `$tPmeta` WHERE meta_key='_billing_email' AND meta_value <> '' AND meta_value NOT LIKE '%@" . FAKE_DOMAIN . "'";
}
if ($hasTable($tComm)) {
    $checks['comment emails'] = "SELECT COUNT(*) FROM `$tComm` WHERE comment_author_email <> '' AND comment_author_email NOT LIKE '%@" . FAKE_DOMAIN . "'";
}
foreach ($plan as $t => $v) {
    if ($v['action'] === 'schema') {
        $checks['emptied ' . $t] = "SELECT COUNT(*) FROM `$t`";
    }
}

$failed = [];
foreach ($checks as $label => $sqlCheck) {
    $n = (int)qScalar($sqlCheck, $tmpDb, '0');
    if ($n > 0) { $failed[$label] = $n; out('  ' . c('✘', 'red') . ' ' . $label . ': ' . $n . ' rows left'); }
    else        { ok($label); }
}
if ($failed) {
    fail("Verification failed: no artifact has been produced.\n  The temporary schema has been dropped. Please report this to Xeader so the rules can be updated.");
}

// ===========================================================================
// 9. FINAL DUMP, CHECKSUM, MANIFEST
// ===========================================================================
step('Artifact generation');

$finalSql = $outputDir . '/.final-' . $stamp . '.sql';
$GLOBALS['CTX']['tmp_files'][] = $finalSql;
$cmd = $base . ' ' . escapeshellarg($tmpDb) . ' > ' . escapeshellarg($finalSql);
$o = [];
if (sh($cmd, $o, $e) !== 0) fail("Final dump failed:\n" . diag($o, $e));

// Strip DEFINER clauses while compressing
$outFile = $outputDir . '/shop-anon-' . $stamp . '.sql.gz';
$in  = fopen($finalSql, 'r');
$gz  = gzopen($outFile, 'wb9');
if (!$in || !$gz) fail('Could not write the final artifact.');
while (($line = fgets($in)) !== false) {
    $line = preg_replace('/\/\*!\d+ DEFINER=[^*]+\*\//', '', $line);
    gzwrite($gz, $line);
}
fclose($in);
gzclose($gz);
$sha = hash_file('sha256', $outFile);
@unlink($finalSql);
ok('Artifact: ' . basename($outFile) . ' (' . fmtBytes((int)filesize($outFile)) . ')');

$rowCounts = [];
foreach (array_keys($plan) as $t) {
    if ($plan[$t]['action'] === 'exclude') continue;
    $rowCounts[$t] = (int)qScalar("SELECT COUNT(*) FROM `$t`", $tmpDb, '0');
}

$manifestTables = [];
foreach ($rowCounts as $t => $n) {
    $manifestTables[$t] = ['action' => $plan[$t]['action'], 'rows' => $n];
}

$manifest = [
        'generated_at'      => gmdate('c'),
        'tool'              => 'shop-anonymizer',
        'tool_version'      => APP_VERSION,
        'tool_vendor'       => 'Xeader',
        'tool_author'       => 'Antonio Gatta <a.gatta@xeader.com>',
        'source_database'   => $db['name'],
        'table_prefix'      => $p,
        'server_version'    => $version,
        'order_storage'     => array_merge($hpos ? ['hpos'] : [], $legacy ? ['legacy'] : []),
        'orders_window'     => $monthsBack ? $monthsBack . ' months' : 'all',
        'attachments_kept'  => $keepAttachments,
        'service_admin'     => $serviceAdmin ? $serviceAdmin['login'] : null,
        'pseudonymization'  => [
                'method' => 'Deterministic SHA-256 with a persistent seed',
                'note'   => 'Deterministic transformation: whoever holds the seed can relink the values. Treat this dataset as pseudonymized under GDPR art. 4(5), not anonymized.',
                'seed_fingerprint' => substr(hash('sha256', $seed), 0, 16),
        ],
        'artifact'          => ['file' => basename($outFile), 'sha256' => $sha, 'bytes' => (int)filesize($outFile)],
        'tables'            => $manifestTables,
        'checks_passed'     => array_keys($checks),
];
file_put_contents($outputDir . '/shop-anon-' . $stamp . '.manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
file_put_contents($outputDir . '/shop-anon-' . $stamp . '.sha256', $sha . '  ' . basename($outFile) . "\n");

$runConfig = [
        'output_dir' => $outputDir, 'months_back' => $monthsBack, 'keep_attachments' => $keepAttachments,
        'service_admin_login' => $serviceAdmin['login'] ?? null,
        'table_actions' => array_map(function ($v) { return $v['action']; }, $plan),
];
file_put_contents($outputDir . '/run-config.json', json_encode($runConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
ok('Manifest, checksum and run-config written');

cleanup();

hr();
out(c('  Export complete', 'green'));
out('  File      : ' . $outFile);
out('  SHA-256   : ' . $sha);
if ($serviceAdmin) out('  Account   : ' . $serviceAdmin['login'] . ' / ' . $serviceAdmin['pass']);
out('  Seed      : ' . $seedFile . '  ' . c('(do not send it along with the dump)', 'yellow'));
out('');
out('  Transfer: encrypt the archive before sending it, for example');
out('    gpg -c --cipher-algo AES256 ' . escapeshellarg($outFile));
out('  and share the passphrase over a channel other than the one used for the file.');
out('  The recipient decrypts it with:');
out('    gpg --output ' . escapeshellarg(basename($outFile)) . ' --decrypt ' . escapeshellarg(basename($outFile) . '.gpg'));
hr();
