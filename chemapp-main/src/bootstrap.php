<?php
declare(strict_types=1);

function app_config(): array
{
    static $config;
    if ($config !== null) {
        return $config;
    }
    $config = [
        'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => '',
        'db_user' => '', 'db_password' => '', 'db_socket' => '', 'db_ssl_ca' => '',
        'write_token' => '', 'base_path' => null,
    ];
    $path = getenv('CHEMAPP_CONFIG') ?: dirname(__DIR__) . '/config.php';
    if (is_file($path)) {
        $local = require $path;
        if (!is_array($local)) {
            throw new RuntimeException('Configuration must return an array.');
        }
        $config = array_replace($config, $local);
    }
    foreach (['db_host', 'db_port', 'db_name', 'db_user', 'db_password', 'db_socket', 'db_ssl_ca', 'write_token', 'base_path'] as $key) {
        $env = getenv('CHEMAPP_' . strtoupper($key));
        if ($env !== false) {
            $config[$key] = $env;
        }
    }
    return $config;
}

function database(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = app_config();
    if ($c['db_name'] === '' || $c['db_user'] === '') {
        throw new RuntimeException('MySQL has not been configured.');
    }
    if (!preg_match('/^[0-9]+$/D', (string) $c['db_port']) || (int) $c['db_port'] < 1 || (int) $c['db_port'] > 65535) {
        throw new RuntimeException('Invalid MySQL port.');
    }
    $location = $c['db_socket'] !== '' ? 'unix_socket=' . $c['db_socket'] : 'host=' . $c['db_host'] . ';port=' . $c['db_port'];
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    if ($c['db_ssl_ca'] !== '') {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $c['db_ssl_ca'];
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
    $pdo = new PDO('mysql:' . $location . ';dbname=' . $c['db_name'] . ';charset=utf8mb4', $c['db_user'], $c['db_password'], $options);
    return $pdo;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(): string
{
    $configured = app_config()['base_path'];
    $path = $configured ?? str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $path = trim($path, '/');
    return $path === '' || $path === '.' ? '' : '/' . $path;
}

function asset(string $filename): string
{
    return base_path() . '/static/' . rawurlencode($filename);
}

function page_url(string $page, array $params = []): string
{
    return base_path() . '/index.php?' . http_build_query(['page' => $page] + $params);
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/shows.php';
