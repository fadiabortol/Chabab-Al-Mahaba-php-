<?php
require __DIR__ . '/includes/site.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/footer.php';

mahaba_start_session();
header('Cache-Control: no-store');

$requestedLanguage = $_GET['lang'] ?? 'en';
$language = is_string($requestedLanguage) && isset($siteTranslations[$requestedLanguage])
    ? $requestedLanguage
    : 'en';
$message = '';
$messageType = '';
$email = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!mahaba_valid_csrf($_POST['csrf_token'] ?? null)) {
        $message = site_text($language, 'csrf_error');
        $messageType = 'error';
    } else {
        $submittedEmail = $_POST['email'] ?? '';
        $email = is_string($submittedEmail) ? trim($submittedEmail) : '';

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            $message = site_text($language, 'email_invalid');
            $messageType = 'error';
        } else {
            try {
                $pdo = mahaba_database();
                mahaba_ensure_user_roles($pdo);
                mahaba_ensure_account_recovery($pdo);
                $statement = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
                $statement->execute(['email' => $email]);
                $userId = $statement->fetchColumn();

                if ($userId !== false) {
                    $rateLimit = $pdo->prepare(
                        'SELECT COUNT(*) AS request_count, MAX(created_at) AS latest_request
                         FROM password_reset_tokens
                         WHERE user_id = :user_id AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)'
                    );
                    $rateLimit->execute(['user_id' => $userId]);
                    $requestStats = $rateLimit->fetch();
                    $recentRequest = $requestStats['latest_request'] !== null
                        && strtotime($requestStats['latest_request']) > time() - 60;

                    if ((int) $requestStats['request_count'] < 5 && !$recentRequest) {
                        $token = bin2hex(random_bytes(32));
                        $tokenHash = hash('sha256', $token);
                        $pdo->beginTransaction();
                        $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id OR expires_at <= NOW()')
                            ->execute(['user_id' => $userId]);
                        $insert = $pdo->prepare(
                            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
                             VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
                        );
                        $insert->execute(['user_id' => $userId, 'token_hash' => $tokenHash]);
                        $pdo->commit();

                        try {
                            $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
                            $baseUrl = getenv('MAHABA_BASE_URL');
                            if ($baseUrl === false || trim($baseUrl) === '') {
                                $host = $_SERVER['SERVER_NAME'] ?? '';
                                $port = (int) ($_SERVER['SERVER_PORT'] ?? ($scheme === 'https' ? 443 : 80));
                                $portSuffix = ($scheme === 'https' && $port === 443)
                                    || ($scheme === 'http' && $port === 80)
                                    ? ''
                                    : ':' . $port;
                                $basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
                                $basePath = $basePath === '/' || $basePath === '.' ? '' : rtrim($basePath, '/');
                                $baseUrl = $scheme . '://' . $host . $portSuffix . $basePath;
                            }

                            $baseUrl = rtrim(trim($baseUrl), '/');
                            $hostParts = parse_url($baseUrl);
                            if (
                                !is_array($hostParts)
                                || !in_array($hostParts['scheme'] ?? '', ['http', 'https'], true)
                                || empty($hostParts['host'])
                                || !preg_match('/\A[A-Za-z0-9.-]+\z/', $hostParts['host'])
                                || isset($hostParts['user'])
                                || isset($hostParts['pass'])
                                || isset($hostParts['query'])
                                || isset($hostParts['fragment'])
                            ) {
                                throw new RuntimeException('Invalid canonical URL for password reset.');
                            }
                            $resetUrl = $baseUrl . '/reset-password.php?lang='
                                . rawurlencode($language) . '&token=' . rawurlencode($token);
                            $subject = site_text($language, 'reset_email_subject');
                            $body = sprintf(site_text($language, 'reset_email_body'), $resetUrl);

                            if (!mail($email, $subject, $body, "Content-Type: text/plain; charset=UTF-8\r\n")) {
                                throw new RuntimeException('The password reset email could not be sent.');
                            }
                        } catch (Throwable $deliveryException) {
                            $pdo->prepare('DELETE FROM password_reset_tokens WHERE token_hash = :token_hash')
                                ->execute(['token_hash' => $tokenHash]);
                            error_log('Mahaba password reset email delivery failed: ' . $deliveryException->getMessage());
                        }
                    }
                }

                $message = site_text($language, 'reset_request_sent');
                $messageType = 'success';
            } catch (PDOException $exception) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Mahaba password reset request database error: ' . $exception->getMessage());
                $message = site_text($language, 'database_error');
                $messageType = 'error';
            } catch (Throwable $exception) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Mahaba password reset request error: ' . $exception->getMessage());
                $message = site_text($language, 'database_error');
                $messageType = 'error';
            }
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
    <title><?= site_escape(site_text($language, 'forgot_password_title')) ?> - <?= site_escape(site_text($language, 'name')) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php site_header($language, 'login'); ?>
    <main class="page-content">
        <section class="content-card login-card" aria-labelledby="forgot-password-title">
            <h1 id="forgot-password-title"><?= site_escape(site_text($language, 'forgot_password_title')) ?></h1>
            <p><?= site_escape(site_text($language, 'forgot_password_intro')) ?></p>
            <?php if ($message !== ''): ?>
                <p class="form-message <?= $messageType === 'success' ? 'success' : '' ?>" role="status"><?= site_escape($message) ?></p>
            <?php endif; ?>
            <form class="login-form" method="post" action="forgot-password.php?lang=<?= site_escape($language) ?>">
                <input type="hidden" name="csrf_token" value="<?= site_escape(mahaba_csrf_token()) ?>">
                <label class="form-field">
                    <?= site_escape(site_text($language, 'email_label')) ?>
                    <input type="email" name="email" value="<?= site_escape($email) ?>" maxlength="254" autocomplete="email" required>
                </label>
                <button class="button-primary" type="submit"><?= site_escape(site_text($language, 'request_reset')) ?></button>
            </form>
            <p class="setup-note"><a href="login.php?lang=<?= site_escape($language) ?>"><?= site_escape(site_text($language, 'login_title')) ?></a></p>
        </section>
    </main>
    <?php site_footer($language); ?>
</body>
</html>
