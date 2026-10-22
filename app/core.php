<?php
declare(strict_types=1);

const GAMEROOT_DIR = __DIR__ . '/..';

function portal_config(): ?array {
    static $config = false;
    if ($config !== false) return $config;
    $file = GAMEROOT_DIR . '/var/config.php';
    if (!is_file($file)) return null;
    $data = require $file;
    if (!is_array($data) || !isset($data['db'], $data['secret_key'])) {
        throw new RuntimeException('Invalid server configuration.');
    }
    $config = $data;
    return $config;
}
function installed(): bool { return is_file(GAMEROOT_DIR . '/var/config.php'); }
function portal_pdo(?array $config = null): PDO {
    static $pdo = null;
    if ($config === null && $pdo instanceof PDO) return $pdo;
    $settings = $config ?? portal_config();
    if (!$settings) throw new RuntimeException('Installation required.');
    $db = $settings['db'];
    $dsn = 'mysql:host=' . $db['host'] . ';port=' . (int)($db['port'] ?? 3306)
        . ';dbname=' . $db['name'] . ';charset=utf8mb4';
    $connection = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    if ($config === null) $pdo = $connection;
    return $connection;
}
function portal_boot(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $secure = portal_https();
        session_name('gameroot_session');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'secure' => $secure,
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    if (portal_https()) header('Strict-Transport-Security: max-age=31536000');
    header('Cache-Control: no-store, private');
}
function portal_https(): bool {
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}
function portal_require_https(): void {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $isLocal = (bool)preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/', $host)
        && in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
    if (!portal_https() && !$isLocal && PHP_SAPI !== 'cli') {
        http_response_code(403);
        exit('A secure HTTPS connection is required.');
    }
}
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(): string {
    if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_input(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function verify_csrf(): void {
    if (!isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals(csrf_token(), $_POST['csrf'])) {
        http_response_code(419); exit('Invalid or expired form. Reload the page and retry.');
    }
}
function redirect(string $route): never {
    if (!preg_match('/^\/[a-z0-9_.?=&%-]+$/iD', $route)) throw new LogicException('Invalid internal redirect.');
    header('Location: ' . $route, true, 303); exit;
}
function flash(string $type, string $message): void { $_SESSION['flash'] = [$type, $message]; }
function flash_take(): ?array { $result = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return is_array($result) ? $result : null; }
function current_user(): ?array {
    $id = $_SESSION['user_id'] ?? null;
    if (!is_int($id) || $id <= 0 || !installed()) return null;
    $q = portal_pdo()->prepare('SELECT id,email,display_name,role,created_at FROM users WHERE id=? AND status=?');
    $q->execute([$id, 'active']);
    return $q->fetch() ?: null;
}
function require_user(): array {
    $user = current_user(); if (!$user) redirect('/login.php'); return $user;
}
function require_admin(): array {
    $user = require_user();
    if ($user['role'] !== 'owner') { http_response_code(403); exit('Owner access required.'); }
    return $user;
}
function authenticate_user(int $userId): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}
function require_installed(): void { if (!installed()) redirect('/install.php'); }
function portal_encrypt(string $plaintext, ?array $config = null): array {
    if (!function_exists('sodium_crypto_secretbox')) throw new RuntimeException('PHP sodium extension required for secure credentials.');
    $rawKey = base64_decode((string)(($config ?? portal_config())['secret_key'] ?? ''), true);
    if (!is_string($rawKey) || strlen($rawKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('Invalid encryption key.');
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return ['nonce' => base64_encode($nonce), 'ciphertext' => base64_encode(sodium_crypto_secretbox($plaintext, $nonce, $rawKey))];
}
function portal_decrypt(array $sealed, ?array $config = null): string {
    if (!function_exists('sodium_crypto_secretbox_open')) throw new RuntimeException('PHP sodium extension required.');
    $key = base64_decode((string)(($config ?? portal_config())['secret_key'] ?? ''), true);
    $nonce = base64_decode((string)($sealed['nonce'] ?? ''), true);
    $ciphertext = base64_decode((string)($sealed['ciphertext'] ?? ''), true);
    if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || !is_string($nonce)
        || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || !is_string($ciphertext)) throw new RuntimeException('Invalid ciphertext.');
    $result = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
    if ($result === false) throw new RuntimeException('Secret cannot be decrypted.');
    return $result;
}
function valid_slug(string $slug): bool {
    $reserved = ['www','app','api','admin','root','portal','account','billing','login','register','mail','email','ftp','cpanel','whm','ns1','ns2','localhost','support','cdn','static','help'];
    return (bool)preg_match('/^[a-z](?:[a-z0-9-]{1,38}[a-z0-9])$/D', $slug) && !in_array($slug, $reserved, true);
}
function valid_domain(string $domain): bool {
    return strlen($domain) <= 253 && (bool)preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/iD', $domain);
}
function install_schema(PDO $pdo): void {
    $sql = file_get_contents(GAMEROOT_DIR . '/app/schema.sql');
    if (!is_string($sql)) throw new RuntimeException('Schema not found.');
    // Schema is controlled source code; no external SQL is accepted.
    foreach (explode(';', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') $pdo->exec($statement);
    }
}
