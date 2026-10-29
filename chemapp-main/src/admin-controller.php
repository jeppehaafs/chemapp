<?php
declare(strict_types=1);
require_once __DIR__ . '/admin.php';
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    exit('Method not allowed.');
}
$view = 'manage';
$title = 'Manage shows';
$error = '';
$success = '';
$authenticated = false;
$setup = false;
$setupAllowed = false;
$deleteConfirmation = false;
$shows = [];
$form = ['id' => '', 'name' => '', 'date' => '', 'latitude' => '', 'longitude' => '', 'link_ticket' => '', 'link_img' => ''];
try {
    admin_session_start();
    $hash = admin_password_hash();
    $setup = $hash === '';
    $setupAllowed = admin_local_setup_allowed();
    $authenticated = admin_authenticated($hash);
    if ($method === 'POST') {
        $action = $_POST['action'] ?? '';
        if (!admin_csrf_valid($_POST['csrf'] ?? null)) {
            http_response_code(403);
            $error = 'This form expired. Reload the page and try again.';
        } elseif ($action === 'setup' && $setup && $setupAllowed) {
            $password = $_POST['password'] ?? null;
            if (!is_string($password) || $password !== ($_POST['password_confirm'] ?? null)) {
                throw new InvalidArgumentException('The two passwords must match.');
            }
            admin_sign_in(admin_set_password($password));
            admin_redirect();
        } elseif ($action === 'login' && !$setup) {
            if (!is_string($_POST['password'] ?? null) || !admin_login($_POST['password'])) {
                http_response_code(401);
                $error = 'The password is incorrect. Please try again.';
            } else {
                admin_redirect();
            }
        } elseif ($action === 'logout' && $authenticated) {
            $_SESSION = [];
            session_regenerate_id(true);
            admin_redirect();
        } elseif ($action === 'delete' && $authenticated) {
            $id = is_string($_POST['id'] ?? null)
                ? filter_var($_POST['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]])
                : false;
            if ($id === false) {
                throw new InvalidArgumentException('Invalid show ID.');
            }
            if (($_POST['confirm_delete'] ?? null) !== (string) $id) {
                throw new InvalidArgumentException('Select the show and confirm its deletion before removing it.');
            }
            if (!delete_show(database(), $id)) {
                http_response_code(404);
                $error = 'This show no longer exists.';
            } else {
                $_SESSION['notice'] = 'Show deleted.';
                admin_redirect();
            }
        } elseif ($action === 'save' && $authenticated) {
            foreach ($form as $key => $value) {
                $form[$key] = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
            }
            $id = $form['id'] === '' ? null : filter_var($form['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
            if ($id === false || !is_string($_POST['id'] ?? null)) {
                throw new InvalidArgumentException('Invalid show ID.');
            }
            // Validate the original values too, so malformed array fields are rejected.
            $show = validate_show($_POST);
            if ($id !== null) {
                if (find_show(database(), $id) === null) {
                    http_response_code(404);
                    $error = 'This show no longer exists. Choose another show or add a new one.';
                } else {
                    update_show(database(), $id, $show);
                    $_SESSION['notice'] = 'Show updated.';
                    admin_redirect(['id' => $id]);
                }
            } else {
                $id = insert_show(database(), $show);
                $_SESSION['notice'] = 'Show added.';
                admin_redirect(['id' => $id]);
            }
        } else {
            http_response_code(403);
            $error = 'Please log in before changing shows.';
        }
    }
    if ($authenticated) {
        $shows = all_shows(database());
        if ($method !== 'POST' && isset($_GET['id'])) {
            $id = filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
            $selected = $id === false ? null : find_show(database(), $id);
            if ($selected === null) {
                http_response_code(404);
                $error = 'This show could not be found.';
            } else {
                $form = array_intersect_key($selected, $form);
                $deleteConfirmation = ($_GET['delete'] ?? null) === '1';
            }
        }
        $success = $_SESSION['notice'] ?? '';
        unset($_SESSION['notice']);
    }
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    $error = $exception->getMessage();
    // Keep the submitted form and load the list after a validation error.
    if ($authenticated) {
        try {
            $shows = all_shows(database());
        } catch (Throwable $dbError) {
            error_log('Chemapp admin database error: ' . $dbError->getMessage());
        }
    }
} catch (OverflowException $exception) {
    http_response_code(429);
    header('Retry-After: 300');
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Chemapp admin error: ' . $exception->getMessage());
    http_response_code(503);
    $error = 'The show manager is unavailable. Check the MySQL connection, SELECT/INSERT/UPDATE/DELETE permissions, and that PHP can write the private storage folder. Details are in the PHP terminal or error log.';
}
require $appRoot . '/views/layout.php';
