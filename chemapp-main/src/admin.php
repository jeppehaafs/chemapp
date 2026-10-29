<?php
declare(strict_types=1);

// This directory belongs beside src/, outside the web document root.
function admin_state_path(): string
{
    return dirname(__DIR__) . '/storage/admin.json';
}

/** Lock reads and writes together, including first-time setup and login throttling. */
function admin_state(callable $callback): mixed
{
    $path = admin_state_path();
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create private admin storage.');
    }
    $file = @fopen($path, 'c+');
    if ($file === false) {
        throw new RuntimeException('Cannot open private admin storage.');
    }
    @chmod($path, 0600);
    try {
        if (!flock($file, LOCK_EX)) {
            throw new RuntimeException('Cannot lock private admin storage.');
        }
        $contents = stream_get_contents($file);
        $state = $contents === '' ? [] : json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($state)) {
            throw new RuntimeException('Invalid private admin storage.');
        }
        $original = $state;
        $result = $callback($state);
        if ($state !== $original) {
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            rewind($file);
            if (!ftruncate($file, 0) || fwrite($file, $json) !== strlen($json) || !fflush($file)) {
                throw new RuntimeException('Cannot save private admin storage.');
            }
        }
        return $result;
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }
}

function admin_password_hash(): string
{
    return admin_state(fn (array $state): string => $state['password_hash'] ?? '');
}

function admin_set_password(string $password, bool $replace = false): string
{
    // Bcrypt (PASSWORD_DEFAULT) accepts at most 72 bytes; reject silent truncation.
    if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
        throw new InvalidArgumentException('Choose a password between 12 and 72 bytes long.');
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    return admin_state(function (array &$state) use ($hash, $replace): string {
        if (!$replace && !empty($state['password_hash'])) {
            throw new InvalidArgumentException('An admin password has already been set. Please log in.');
        }
        $state = ['password_hash' => $hash, 'failures' => 0, 'window_start' => time()];
        return $hash;
    });
}

/** Trust the direct connection only; forwarded headers must not enable setup. */
function admin_local_setup_allowed(): bool
{
    $host = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        && in_array(strtolower((string) $host), ['127.0.0.1', 'localhost', '[::1]'], true);
}

function admin_session_start(): void
{
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_cache_limiter('');
    session_name('chemapp_admin_' . substr(hash('sha256', base_path()), 0, 12));
    session_set_cookie_params([
        'lifetime' => 0, 'path' => (base_path() ?: '') . '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    if (!@session_start()) {
        throw new RuntimeException('Cannot start an admin session.');
    }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function admin_csrf_valid(mixed $token): bool
{
    return is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

function admin_authenticated(string $hash): bool
{
    $now = time();
    $valid = $hash !== '' && isset($_SESSION['admin_fingerprint'])
        && hash_equals(hash('sha256', $hash), $_SESSION['admin_fingerprint'])
        && $now - ($_SESSION['last_active'] ?? 0) < 1800
        && $now - ($_SESSION['logged_in'] ?? 0) < 28800;
    if ($valid) {
        $_SESSION['last_active'] = $now;
    } else {
        unset($_SESSION['admin_fingerprint']);
    }
    return $valid;
}

function admin_login(string $password): bool
{
    return admin_state(function (array &$state) use ($password): bool {
        $now = time();
        if ($now - ($state['window_start'] ?? 0) >= 300) {
            $state['failures'] = 0;
            $state['window_start'] = $now;
        }
        if (($state['failures'] ?? 0) >= 10) {
            throw new OverflowException('Too many login attempts. Wait five minutes and try again.');
        }
        $hash = $state['password_hash'] ?? '';
        $valid = $hash !== '' && strlen($password) <= 72 && password_verify($password, $hash);
        $state['failures'] = $valid ? 0 : ($state['failures'] ?? 0) + 1;
        if ($valid) {
            admin_sign_in($hash);
        }
        return $valid;
    });
}

function admin_sign_in(string $hash): void
{
    session_regenerate_id(true);
    $_SESSION = [
        'csrf' => bin2hex(random_bytes(32)), 'admin_fingerprint' => hash('sha256', $hash),
        'logged_in' => time(), 'last_active' => time(),
    ];
}

function admin_redirect(array $params = []): never
{
    header('Location: ' . page_url('manage', $params), true, 303);
    exit;
}
