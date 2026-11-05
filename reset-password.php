<?php
require __DIR__ . '/includes/site.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/footer.php';

mahaba_start_session();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$requestedLanguage = $_GET['lang'] ?? 'en';
$language = is_string($requestedLanguage) && isset($siteTranslations[$requestedLanguage])
    ? $requestedLanguage
    : 'en';
$token = is_string($_POST['token'] ?? null)
    ? $_POST['token']
    : (is_string($_GET['token'] ?? null) ? $_GET['token'] : '');
$message = '';
$messageType = '';
$tokenValid = false;
$completed = false;

try {
    $pdo = mahaba_database();
    mahaba_ensure_user_roles($pdo);
    mahaba_ensure_account_recovery($pdo);

    if (preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        $statement = $pdo->prepare(
            'SELECT id FROM password_reset_tokens
             WHERE token_hash = :token_hash AND expires_at > NOW() LIMIT 1'
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $tokenValid = $statement->fetchColumn() !== false;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!mahaba_valid_csrf($_POST['csrf_token'] ?? null)) {
            $message = site_text($language, 'csrf_error');
            $messageType = 'error';
        } else {
            $password = $_POST['password'] ?? null;
            $confirmation = $_POST['confirm_password'] ?? null;
            $passwordLength = is_string($password) && strlen($password) <= 4096
                ? mahaba_character_count($password)
                : null;

            if (!$tokenValid) {
                $message = site_text($language, 'reset_password_invalid');
                $messageType = 'error';
            } elseif ($passwordLength === null || $passwordLength < 12) {
                $message = site_text($language, 'password_short');
                $messageType = 'error';
            } elseif (!is_string($confirmation) || !hash_equals($password, $confirmation)) {
                $message = site_text($language, 'password_mismatch');
                $messageType = 'error';
            } else {
                $pdo->beginTransaction();
                $statement = $pdo->prepare(
                    'SELECT user_id FROM password_reset_tokens
                     WHERE token_hash = :token_hash AND expires_at > NOW() LIMIT 1 FOR UPDATE'
                );
                $statement->execute(['token_hash' => hash('sha256', $token)]);
                $userId = $statement->fetchColumn();

                if ($userId === false) {
                    $pdo->rollBack();
                    $tokenValid = false;
                    $message = site_text($language, 'reset_password_invalid');
                    $messageType = 'error';
                } else {
                    $update = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
                    $update->execute([
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'id' => $userId,
                    ]);
                    if ($update->rowCount() !== 1) {
                        throw new RuntimeException('The account for the reset token no longer exists.');
                    }
                    $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id')
                        ->execute(['user_id' => $userId]);
                    $pdo->commit();
                    $tokenValid = false;
                    $completed = true;
                    $message = site_text($language, 'reset_password_success');
                    $messageType = 'success';
                }
            }
        }
    } elseif (!$tokenValid) {
        $message = site_text($language, 'reset_password_invalid');
        $messageType = 'error';
    }
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Mahaba password reset database error: ' . $exception->getMessage());
    $message = site_text($language, 'database_error');
    $messageType = 'error';
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Mahaba password reset error: ' . $exception->getMessage());
    $message = site_text($language, 'database_error');
    $messageType = 'error';
}
?>
<!doctype html>
<html lang="<?= site_escape($language) ?>" dir="<?= $language === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#ffffff">
    <title><?= site_escape(site_text($language, 'reset_password_title')) ?> - <?= site_escape(site_text($language, 'name')) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php site_header($language, 'login'); ?>
    <main class="page-content">
        <section class="content-card login-card" aria-labelledby="reset-password-title">
            <h1 id="reset-password-title"><?= site_escape(site_text($language, 'reset_password_title')) ?></h1>
            <?php if ($message !== ''): ?>
                <p class="form-message <?= $messageType === 'success' ? 'success' : '' ?>" role="status"><?= site_escape($message) ?></p>
            <?php endif; ?>
            <?php if ($tokenValid && !$completed): ?>
                <p><?= site_escape(site_text($language, 'reset_password_intro')) ?></p>
                <form class="login-form" method="post" action="reset-password.php?lang=<?= site_escape($language) ?>">
                    <input type="hidden" name="csrf_token" value="<?= site_escape(mahaba_csrf_token()) ?>">
                    <input type="hidden" name="token" value="<?= site_escape($token) ?>">
                    <label class="form-field">
                        <?= site_escape(site_text($language, 'password')) ?>
                        <input type="password" name="password" minlength="12" maxlength="4096" autocomplete="new-password" required>
                    </label>
                    <label class="form-field">
                        <?= site_escape(site_text($language, 'confirm_password')) ?>
                        <input type="password" name="confirm_password" minlength="12" maxlength="4096" autocomplete="new-password" required>
                    </label>
                    <button class="button-primary" type="submit"><?= site_escape(site_text($language, 'reset_password_title')) ?></button>
                </form>
            <?php endif; ?>
            <p class="setup-note"><a href="login.php?lang=<?= site_escape($language) ?>"><?= site_escape(site_text($language, 'login_title')) ?></a></p>
            <?php if (!$tokenValid && !$completed): ?>
                <p class="setup-note"><a href="forgot-password.php?lang=<?= site_escape($language) ?>"><?= site_escape(site_text($language, 'forgot_password')) ?></a></p>
            <?php endif; ?>
        </section>
    </main>
    <?php site_footer($language); ?>
</body>
</html>
