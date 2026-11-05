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
$email = '';
$setupOpen = false;

if (!mahaba_is_local_request()) {
    http_response_code(403);
    $message = site_text($language, 'local_only');
    $messageType = 'error';
} else {
    try {
        $pdo = mahaba_database();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(190) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        mahaba_ensure_user_roles($pdo);
        mahaba_ensure_account_recovery($pdo);
        mahaba_ensure_content_tables($pdo);
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS site_setup (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                completed TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB'
        );
        $pdo->exec('INSERT IGNORE INTO site_setup (id, completed) VALUES (1, 0)');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $submittedUsername = $_POST['username'] ?? '';
            $submittedEmail = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            $confirmation = $_POST['confirm_password'] ?? '';
            $username = is_string($submittedUsername) ? trim($submittedUsername) : '';
            $email = is_string($submittedEmail) ? trim($submittedEmail) : '';
            $usernameLength = mahaba_character_count($username);
            $passwordLength = is_string($password) && strlen($password) <= 4096
                ? mahaba_character_count($password)
                : null;

            if (!mahaba_valid_csrf($_POST['csrf_token'] ?? null)) {
                $message = site_text($language, 'csrf_error');
            } elseif (
                $username === ''
                || $usernameLength === null
                || $usernameLength > 190
                || strlen($username) > 760
                || preg_match('/[\x00-\x1F\x7F]/', $username)
            ) {
                $message = site_text($language, 'username_invalid');
            } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
                $message = site_text($language, 'email_invalid');
            } elseif ($passwordLength === null || $passwordLength < 12) {
                $message = site_text($language, 'password_short');
            } elseif (!is_string($confirmation) || !hash_equals($password, $confirmation)) {
                $message = site_text($language, 'password_mismatch');
            }

            if ($message !== '') {
                $messageType = 'error';
            } else {
                $pdo->beginTransaction();
                $setupState = $pdo->query('SELECT completed FROM site_setup WHERE id = 1 FOR UPDATE')->fetchColumn();
                $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

                if ((int) $setupState === 1 || $userCount > 0) {
                    $pdo->exec('UPDATE site_setup SET completed = 1 WHERE id = 1');
                    $pdo->commit();
                    $message = site_text($language, 'setup_done');
                    $messageType = 'error';
                } else {
                    $statement = $pdo->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (:username, :email, :password_hash, :role)');
                    $statement->execute([
                        'username' => $username,
                        'email' => $email,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'role' => 'admin',
                    ]);
                    $pdo->exec('UPDATE site_setup SET completed = 1 WHERE id = 1');
                    $pdo->commit();
                    $message = site_text($language, 'account_created');
                    $messageType = 'success';
                }
            }
        }

        if ($messageType !== 'success' && $message !== site_text($language, 'setup_done')) {
            $pdo->beginTransaction();
            $setupState = $pdo->query('SELECT completed FROM site_setup WHERE id = 1 FOR UPDATE')->fetchColumn();
            $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ((int) $setupState === 1 || $userCount > 0) {
                $pdo->exec('UPDATE site_setup SET completed = 1 WHERE id = 1');
                $pdo->commit();
                if ($message === '') {
                    $message = site_text($language, 'setup_done');
                }
            } else {
                $pdo->commit();
                $setupOpen = true;
            }
        }
    } catch (PDOException $exception) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Mahaba account setup database error: ' . $exception->getMessage());
        $message = site_text($language, 'database_error');
        $messageType = 'error';
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Mahaba account setup error: ' . $exception->getMessage());
        $message = site_text($language, 'setup_error');
        $messageType = 'error';
    }
}
?>
<!doctype html>
<html lang="<?= site_escape($language) ?>" dir="<?= $language === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#ffffff">
    <title><?= site_escape(site_text($language, 'setup_title')) ?> - <?= site_escape(site_text($language, 'name')) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php site_header($language, 'login'); ?>
    <main class="page-content">
        <section class="content-card" aria-labelledby="setup-title">
            <h1 id="setup-title"><?= site_escape(site_text($language, 'setup_title')) ?></h1>
            <?php if ($message !== ''): ?>
                <p class="form-message <?= $messageType === 'success' ? 'success' : '' ?>" role="status"><?= site_escape($message) ?></p>
            <?php endif; ?>
            <?php if ($setupOpen): ?>
                <p><?= site_escape(site_text($language, 'setup_intro')) ?></p>
                <form class="login-form" method="post" action="setup.php?lang=<?= site_escape($language) ?>">
                    <input type="hidden" name="csrf_token" value="<?= site_escape(mahaba_csrf_token()) ?>">
                    <label class="form-field">
                        <?= site_escape(site_text($language, 'username')) ?>
                        <input type="text" name="username" value="<?= site_escape($username) ?>" maxlength="190" autocomplete="username" required>
                    </label>
                    <label class="form-field">
                        <?= site_escape(site_text($language, 'email_label')) ?>
                        <input type="email" name="email" value="<?= site_escape($email) ?>" maxlength="254" autocomplete="email" required>
                    </label>
                    <label class="form-field">
                        <?= site_escape(site_text($language, 'password')) ?>
                        <input type="password" name="password" minlength="12" maxlength="4096" autocomplete="new-password" required>
                    </label>
                    <label class="form-field">
                        <?= site_escape(site_text($language, 'confirm_password')) ?>
                        <input type="password" name="confirm_password" minlength="12" maxlength="4096" autocomplete="new-password" required>
                    </label>
                    <button class="button-primary" type="submit"><?= site_escape(site_text($language, 'create_account')) ?></button>
                </form>
            <?php endif; ?>
            <p class="setup-note"><a href="login.php?lang=<?= site_escape($language) ?>"><?= site_escape(site_text($language, 'login_title')) ?></a></p>
        </section>
    </main>
    <?php site_footer($language); ?>
</body>
</html>
