<?php
/*
 * Shared setup for the download page and the admin area: config, the SQLite
 * database, sessions, CSRF tokens and small helpers.
 *
 * Settings come from lib/config.php and can be overridden in config.local.php
 * at the site root (not in git). Keep the data directory outside the web root
 * when the host allows it; data/.htaccess blocks it on Apache otherwise.
 */

declare(strict_types=1);

const PLANS = [
    'standard' => ['label' => 'Standard', 'price' => 15000],
    'complete' => ['label' => 'Complete', 'price' => 25000],
];
const MAINTENANCE_RATE = 0.33;
const DEPLOYMENT_STATUSES = [
    'lead'      => 'Lead',
    'quoted'    => 'Quoted',
    'paid'      => 'Paid',
    'scheduled' => 'Deployment scheduled',
    'deployed'  => 'Deployed',
    'inactive'  => 'Inactive',
];

function config(string $key)
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
        $local = dirname(__DIR__) . '/config.local.php';
        if (is_file($local)) {
            $config = array_replace($config, require $local);
        }
    }
    return $config[$key] ?? null;
}

function data_dir(): string
{
    $dir = rtrim((string) config('data_dir'), '/\\');
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create the data directory.');
    }
    return $dir;
}

function releases_dir(): string
{
    $dir = data_dir() . '/releases';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create the releases directory.');
    }
    return $dir;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . data_dir() . '/ntmart-go.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        migrate($pdo);
    }
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS clients (
            id INTEGER PRIMARY KEY,
            business TEXT NOT NULL,
            contact_name TEXT NOT NULL DEFAULT '',
            phone TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '' COLLATE NOCASE,
            location TEXT NOT NULL DEFAULT '',
            plan TEXT NOT NULL DEFAULT 'standard',
            status TEXT NOT NULL DEFAULT 'lead',
            licence_price INTEGER NOT NULL DEFAULT 0,
            deployment_quote INTEGER NOT NULL DEFAULT 0,
            purchase_date TEXT,
            maintenance_due TEXT,
            notes TEXT NOT NULL DEFAULT '',
            download_enabled INTEGER NOT NULL DEFAULT 0,
            download_code_hash TEXT,
            download_count INTEGER NOT NULL DEFAULT 0,
            last_download_at TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS clients_email ON clients (email);
        CREATE TABLE IF NOT EXISTS releases (
            id INTEGER PRIMARY KEY,
            version TEXT NOT NULL,
            stored_name TEXT NOT NULL,
            original_name TEXT NOT NULL,
            size INTEGER NOT NULL,
            sha256 TEXT NOT NULL,
            notes TEXT NOT NULL DEFAULT '',
            is_current INTEGER NOT NULL DEFAULT 0,
            uploaded_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS download_tokens (
            token_hash TEXT PRIMARY KEY,
            client_id INTEGER NOT NULL REFERENCES clients (id) ON DELETE CASCADE,
            release_id INTEGER NOT NULL REFERENCES releases (id) ON DELETE CASCADE,
            expires_at TEXT NOT NULL,
            used INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS downloads (
            id INTEGER PRIMARY KEY,
            client_id INTEGER NOT NULL REFERENCES clients (id) ON DELETE CASCADE,
            release_id INTEGER REFERENCES releases (id) ON DELETE SET NULL,
            ip TEXT NOT NULL DEFAULT '',
            downloaded_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS attempts (
            id INTEGER PRIMARY KEY,
            kind TEXT NOT NULL,
            ip TEXT NOT NULL,
            at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS attempts_lookup ON attempts (kind, ip, at);
    ");
}

/* ---------- Helpers ---------- */

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function kes(int $amount): string
{
    return 'KES ' . number_format($amount);
}

function maintenance_fee(int $licencePrice): int
{
    return (int) round($licencePrice * MAINTENANCE_RATE);
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

function redirect(string $url): void
{
    header('Location: ' . $url, true, 303);
    exit;
}

/* A download code is what an admin hands to a client: easy to read out over
 * the phone, no look-alike characters (0/O, 1/I/L). */
function new_download_code(): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 12; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return implode('-', str_split($code, 4));
}

function normalise_code(string $code): string
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    return implode('-', str_split($code, 4));
}

/* ---------- Rate limiting ---------- */

function too_many_attempts(string $kind, int $max, int $minutes): bool
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM attempts WHERE kind = ? AND ip = ? AND at > datetime('now', ?)");
    $stmt->execute([$kind, client_ip(), '-' . $minutes . ' minutes']);
    return (int) $stmt->fetchColumn() >= $max;
}

function record_attempt(string $kind): void
{
    db()->prepare('INSERT INTO attempts (kind, ip) VALUES (?, ?)')->execute([$kind, client_ip()]);
    db()->exec("DELETE FROM attempts WHERE at < datetime('now', '-1 day')");
}

function clear_attempts(string $kind): void
{
    db()->prepare('DELETE FROM attempts WHERE kind = ? AND ip = ?')->execute([$kind, client_ip()]);
}

/* ---------- Sessions and CSRF ---------- */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('ntgo_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function check_csrf(): void
{
    start_session();
    $sent = (string) ($_POST['csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(400);
        exit('Your session expired. Go back, reload the page and try again.');
    }
}

function flash(?string $message = null, string $type = 'ok'): ?array
{
    start_session();
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}
