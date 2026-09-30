<?php
declare(strict_types=1);

// Before anything else, because the rest of the code needs PHP 8 to even be read.
require dirname(__DIR__) . '/lib/require-php8.php';

require_once __DIR__ . '/../lib/auth.php';

auth_logout();
header('Location: ' . rtrim((string) cfg('base_url'), '/') . '/login.php');
