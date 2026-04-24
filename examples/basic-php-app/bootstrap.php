<?php

declare(strict_types=1);

use PDO;

require_once __DIR__ . '/../../includes/AuthHandler.php';

$pdo = new PDO(
    'mysql:host=localhost;dbname=example_app;charset=utf8mb4',
    'db_user',
    'db_password',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$auth_config = require __DIR__ . '/auth_config.php';

$AuthHandler = new AuthHandler($auth_config);

// Optional: hand off mail formatting and delivery to the application.
$AuthHandler->SetEmailProcedure('AppAuthEmailProcedure', true);

// Handle auth requests before normal page rendering.
$AuthHandler->HandleRequest();

$currentUser = $AuthHandler->isLoggedIn() ? $AuthHandler->userData : null;

function AppAuthEmailProcedure(array $message, bool $send = true): bool
{
    if (!$send) {
        return true;
    }

    // Replace this with your real mail transport.
    return true;
}