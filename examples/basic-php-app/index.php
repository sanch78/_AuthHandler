<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AuthHandler Example</title>
<?= $AuthHandler->AssetsInjector() ?>
<script>
function applyAuthUi() {
    document.body.classList.toggle('is-logged-in', !!(window.authHandler && window.authHandler.userToken));
}
</script>
</head>
<body>
    <header>
        <h1>Example Application</h1>
        <div class="auth-buttons"></div>
    </header>

    <main>
        <?php if ($currentUser): ?>
            <p>Signed in as <?= htmlspecialchars((string)($currentUser['display_name'] ?? $currentUser['user_email'] ?? 'user'), ENT_QUOTES, 'UTF-8') ?></p>
            <button type="button" onclick="authHandler.changePassword()">Change password</button>
            <button type="button" onclick="authHandler.logout()">Logout</button>
        <?php else: ?>
            <p>You are currently signed out.</p>
            <button type="button" onclick="authHandler.login()">Login</button>
            <button type="button" onclick="authHandler.registration()">Register</button>
        <?php endif; ?>
    </main>

<?= $AuthHandler->Injector() ?>
</body>
</html>