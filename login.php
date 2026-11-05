<?php
require __DIR__ . '/includes/site.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/footer.php';

mahaba_start_session();

$requestedLanguage = $_GET['lang'] ?? 'en';
$language = is_string($requestedLanguage) && isset($siteTranslations[$requestedLanguage])
    ? $requestedLanguage
    : 'en';
$message = '';
$messageType = '';
$username = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!mahaba_valid_csrf($_POST['csrf_token'] ?? null)) {
        $message = site_text($language, 'csrf_error');
        $messageType = 'error';
    } else {
        $submittedUsername = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $username = is_string($submittedUsername) ? trim($submittedUsername) : '';

        $usernameLength = mahaba_character_count($username);
        if (
            $username !== ''
            && $usernameLength !== null
            && $usernameLength <= 190
            && strlen($username) <= 760
            && is_string($password)
            && strlen($password) <= 4096
        ) {
            try {
                $pdo = mahaba_database();
                mahaba_ensure_user_roles($pdo);
                $statement = $pdo->prepare('SELECT id, username, password_hash, role FROM users WHERE username = :username LIMIT 1');
                $statement->execute(['username' => $username]);
                $user = $statement->fetch();

                if ($user && password_verify($password, $user['password_hash'])) {
                    if (!session_regenerate_id(true)) {
                        throw new RuntimeException('Unable to renew the session ID.');
                    }
                    unset($_SESSION['csrf_token']);
                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    header('Location: index.php?lang=' . rawurlencode($language));
                    exit;
                }

                $message = site_text($language, 'invalid_login');
                $messageType = 'error';
            } catch (PDOException $exception) {
                error_log('Mahaba login database error: ' . $exception->getMessage());
                $message = $exception->getCode() === '42S02'
                    ? site_text($language, 'setup_required')
                    : site_text($language, 'database_error');
                $messageType = 'error';
            }
        } else {
            $message = site_text($language, 'invalid_login');
            $messageType = 'error';
        }
    }
}
?>
<!doctype html>
<html lang="<?= site_escape($language) ?>" dir="<?= $language === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#ffffff">
    <title><?= site_escape(site_text($language, 'login_title')) ?> - <?= site_escape(site_text($language, 'name')) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php site_header($language, 'login'); ?>
    <main class="page-content">
        <section class="content-card login-card" aria-labelledby="login-title">
            <h1 id="login-title"><?= site_escape(site_text($language, 'login_title')) ?></h1>
            <?php if (!empty($_SESSION['username'])): ?>
                <p class="form-message success"><?= site_escape(site_text($language, 'logged_in')) ?> <?= site_escape($_SESSION['username']) ?>.</p>
                <form method="post" action="logout.php?lang=<?= site_escape($language) ?>">
                    <input type="hidden" name="csrf_token" value="<?= site_escape(mahaba_csrf_token()) ?>">
                    <button class="button-primary" type="submit"><?= site_escape(site_text($language, 'logout')) ?></button>
                </form>
            <?php else: ?>
                <p><?= site_escape(site_text($language, 'login_intro')) ?></p>
                <?php if ($message !== ''): ?>
                    <p class="form-message" role="alert"><?= site_escape($message) ?>
                        <?php if ($messageType === 'error' && $message === site_text($language, 'setup_required')): ?>
                            <a href="setup.php?lang=<?= site_escape($language) ?>"><?= site_escape(site_text($language, 'setup_link')) ?></a>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <form class="login-form" method="post" action="login.php?lang=<?= site_escape($language) ?>">
                    <input type="hidden" name="csrf_token" value="<?= site_escape(mahaba_csrf_token()) ?>">
                    <label class="form-field">
                        <?= site_escape(site_text($language, 'username')) ?>
                        <input type="text" name="username" value="<?= site_escape($username) ?>" maxlength="190" autocomplete="username" required>
                    </label>
                    <label class="form-field">
                        <?= site_escape(site_text($language, 'password')) ?>
                        <input type="password" name="password" maxlength="4096" autocomplete="current-password" required>
                    </label>
                    <button class="button-primary" type="submit"><?= site_escape(site_text($language, 'sign_in')) ?></button>
                </form>
                <p class="setup-note"><a href="forgot-password.php?lang=<?= site_escape($language) ?>"><?= site_escape(site_text($language, 'forgot_password')) ?></a></p>
                <p class="setup-note"><a href="setup.php?lang=<?= site_escape($language) ?>"><?= site_escape(site_text($language, 'setup_link')) ?></a></p>
            <?php endif; ?>
        </section>
    </main>
    <?php site_footer($language); ?>
</body>
</html>
