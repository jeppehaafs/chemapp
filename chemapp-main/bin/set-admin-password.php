<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/admin.php';
fwrite(STDOUT, "Set or reset the show manager password. Existing admin sessions will be signed out.\nInput is visible in this terminal. Use a private terminal and clear it afterward.\nPassword (12-72 bytes): ");
$password = rtrim(fgets(STDIN) ?: '', "\r\n");
fwrite(STDOUT, 'Confirm password: ');
$confirm = rtrim(fgets(STDIN) ?: '', "\r\n");
try {
    if ($password !== $confirm) {
        throw new InvalidArgumentException('Passwords do not match.');
    }
    admin_set_password($password, true);
    fwrite(STDOUT, "\nAdmin password saved. Open Tour Dates → Manage shows.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "\nCould not save password: " . $error->getMessage() . "\n");
    exit(1);
}
