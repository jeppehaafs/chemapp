<?php
declare(strict_types=1);
$appRoot = dirname(__DIR__);
require $appRoot . '/src/bootstrap.php';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: text/html; charset=utf-8');

// Query-string links work even when URL rewriting is unavailable on shared hosting.
$legacy = ['/' => 'home', '/about_bp' => 'about', '/info_bp' => 'info', '/map/map_bp' => 'map', '/music/music_bp' => 'music', '/mail/mail_bp' => 'mail', '/database/show' => 'api/shows'];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = base_path();
if ($base !== '' && str_starts_with($path, $base . '/')) {
    $path = substr($path, strlen($base));
}
$path = rtrim($path, '/') ?: '/';
$page = $_GET['page'] ?? $legacy[$path] ?? ($path === '/index.php' ? 'home' : '404');
$page = is_string($page) ? $page : '404';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($page === 'manage') {
    require $appRoot . '/src/admin-controller.php';
    exit;
}

if ($page === 'api/shows') {
    header('Cache-Control: no-store');
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        json_response(['error' => 'Method not allowed.'], 405);
    }
    if ($method === 'POST') {
        $token = app_config()['write_token'];
        if (!is_string($token) || $token === '') {
            json_response(['error' => 'Creating shows is disabled. Configure a write token first.'], 403);
        }
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!hash_equals('Bearer ' . $token, $authorization)) {
            json_response(['error' => 'Unauthorized.'], 401);
        }
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            json_response(['error' => 'Use Content-Type: application/json.'], 415);
        }
        // Read one byte beyond the limit so oversized chunked requests are rejected too.
        $body = file_get_contents('php://input', false, null, 0, 65537);
        if ($body === false || strlen($body) > 65536) {
            json_response(['error' => 'Request body must be at most 64 KiB.'], 413);
        }
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_is_list($data)) {
                throw new InvalidArgumentException('Expected a JSON object.');
            }
            $show = validate_show($data);
        } catch (JsonException | InvalidArgumentException $error) {
            json_response(['error' => $error instanceof JsonException ? 'Invalid JSON.' : $error->getMessage()], 400);
        }
    }
    try {
        if ($method === 'GET') {
            json_response(all_shows(database()));
        }
        $id = insert_show(database(), $show);
        json_response(['id' => $id, 'message' => 'Show created successfully.'], 201);
    } catch (Throwable $error) {
        error_log('Chemapp database error: ' . $error->getMessage());
        json_response(['error' => 'Shows are temporarily unavailable.'], 503);
    }
}

if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit('Method not allowed.');
}
$titles = ['home' => 'Home', 'about' => 'About', 'music' => 'Music', 'mail' => 'Contact', 'map' => 'Tour Dates', 'info' => 'Show Information'];
$view = array_key_exists($page, $titles) ? $page : 'message';
$title = $titles[$page] ?? 'Page not found';
$message = 'This page could not be found.';
$shows = [];
$show = null;
$databaseError = false;
$tourPeriod = ($_GET['period'] ?? null) === 'past' ? 'past' : 'upcoming';
$tourToday = (new DateTimeImmutable('today', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
if ($view === 'message') {
    http_response_code(404);
}
try {
    if ($page === 'map') {
        $shows = array_values(array_filter(all_shows(database()), fn (array $entry): bool =>
            $tourPeriod === 'past' ? $entry['date'] < $tourToday : $entry['date'] >= $tourToday
        ));
        if ($tourPeriod === 'upcoming') {
            $shows = array_reverse($shows);
        }
    } elseif ($page === 'info' && isset($_GET['id'])) {
        $id = filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
        $show = $id === false ? null : find_show(database(), $id);
        if ($show === null) {
            http_response_code(404);
            $view = 'message';
            $message = 'This show could not be found.';
        } else {
            $title = $show['name'];
        }
    }
} catch (Throwable $error) {
    error_log('Chemapp database error: ' . $error->getMessage());
    http_response_code(503);
    $databaseError = true;
    if ($page === 'info') {
        $view = 'message';
        $message = 'Show information is temporarily unavailable. Please try again later.';
    }
}
require $appRoot . '/views/layout.php';
