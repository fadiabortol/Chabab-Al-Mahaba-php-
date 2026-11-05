<?php
require __DIR__ . '/includes/auth.php';

mahaba_start_session();

$requestedLanguage = $_GET['lang'] ?? 'en';
$language = is_string($requestedLanguage) && in_array($requestedLanguage, ['ar', 'en', 'tr', 'fr'], true)
    ? $requestedLanguage
    : 'en';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !mahaba_valid_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Invalid request.');
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'] ?? 'Lax',
    ]);
}
session_destroy();

header('Location: login.php?lang=' . rawurlencode($language));
exit;
