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
$currentUser = null;
$activities = [];

try {
    $pdo = mahaba_database();

    if (!empty($_SESSION['user_id'])) {
        mahaba_ensure_user_roles($pdo);
        $statement = $pdo->prepare('SELECT id, username, role FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => (int) $_SESSION['user_id']]);
        $currentUser = $statement->fetch();

        if (!$currentUser) {
            unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role']);
        } else {
            $_SESSION['username'] = $currentUser['username'];
            $_SESSION['role'] = $currentUser['role'];
            mahaba_ensure_content_tables($pdo);
        }
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!$currentUser) {
            http_response_code(403);
            $message = site_text($language, 'dashboard_access_denied');
            $messageType = 'error';
        } elseif (!mahaba_valid_csrf($_POST['csrf_token'] ?? null)) {
            $message = site_text($language, 'csrf_error');
            $messageType = 'error';
        } else {
            $action = $_POST['action'] ?? '';

            if ($action === 'save_activity') {
                $activityIdInput = $_POST['activity_id'] ?? '';
                $activityId = is_string($activityIdInput) ? trim($activityIdInput) : null;
                $title = trim(is_string($_POST['title'] ?? null) ? $_POST['title'] : '');
                $description = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');
                $dateValue = is_string($_POST['activity_date'] ?? null) ? trim($_POST['activity_date']) : '';
                $titleLength = mahaba_character_count($title);
                $descriptionLength = mahaba_character_count($description);
                $date = $dateValue === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
                $dateIsValid = $dateValue === ''
                    || ($date !== false && $date->format('Y-m-d') === $dateValue);

                if (
                    $activityId === null
                    || $title === ''
                    || $titleLength === null
                    || $titleLength > 190
                    || strlen($title) > 760
                    || preg_match('/[\x00-\x1F\x7F]/', $title)
                    || $description === ''
                    || $descriptionLength === null
                    || $descriptionLength > 4000
                    || strlen($description) > 16000
                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $description)
                    || !$dateIsValid
                ) {
                    $message = site_text($language, 'activity_invalid');
                    $messageType = 'error';
                } elseif ($activityId === '') {
                    $statement = $pdo->prepare(
                        'INSERT INTO site_activities (language, title, description, activity_date, created_by)
                         VALUES (:language, :title, :description, :activity_date, :created_by)'
                    );
                    $statement->execute([
                        'language' => $language,
                        'title' => $title,
                        'description' => $description,
                        'activity_date' => $dateValue === '' ? null : $dateValue,
                        'created_by' => (int) $currentUser['id'],
                    ]);
                    $message = site_text($language, 'activity_saved');
                    $messageType = 'success';
                } else {
                    $validatedId = filter_var($activityId, FILTER_VALIDATE_INT, [
                        'options' => ['min_range' => 1],
                    ]);
                    if ($validatedId === false) {
                        $message = site_text($language, 'activity_invalid');
                        $messageType = 'error';
                    } else {
                        $lookup = $pdo->prepare('SELECT created_by FROM site_activities WHERE id = :id LIMIT 1');
                        $lookup->execute(['id' => $validatedId]);
                        $existingActivity = $lookup->fetch();

                        if (!$existingActivity) {
                            $message = site_text($language, 'activity_invalid');
                            $messageType = 'error';
                        } elseif (
                            $currentUser['role'] !== 'admin'
                            && (int) $existingActivity['created_by'] !== (int) $currentUser['id']
                        ) {
                            http_response_code(403);
                            $message = site_text($language, 'activity_forbidden');
                            $messageType = 'error';
                        } else {
                            $statement = $pdo->prepare(
                                'UPDATE site_activities
                                 SET title = :title, description = :description, activity_date = :activity_date
                                 WHERE id = :id'
                            );
                            $statement->execute([
                                'title' => $title,
                                'description' => $description,
                                'activity_date' => $dateValue === '' ? null : $dateValue,
                                'id' => $validatedId,
                            ]);
                            $message = site_text($language, 'activity_saved');
                            $messageType = 'success';
                        }
                    }
                }
            } elseif ($action === 'delete_activity') {
                $activityId = filter_var($_POST['activity_id'] ?? null, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);
                if ($activityId === false) {
                    $message = site_text($language, 'activity_invalid');
                    $messageType = 'error';
                } else {
                    $lookup = $pdo->prepare('SELECT created_by FROM site_activities WHERE id = :id LIMIT 1');
                    $lookup->execute(['id' => $activityId]);
                    $existingActivity = $lookup->fetch();

                    if (!$existingActivity) {
                        $message = site_text($language, 'activity_invalid');
                        $messageType = 'error';
                    } elseif (
                        $currentUser['role'] !== 'admin'
                        && (int) $existingActivity['created_by'] !== (int) $currentUser['id']
                    ) {
                        http_response_code(403);
                        $message = site_text($language, 'activity_forbidden');
                        $messageType = 'error';
                    } else {
                        $statement = $pdo->prepare('DELETE FROM site_activities WHERE id = :id');
                        $statement->execute(['id' => $activityId]);
                        $message = site_text($language, 'activity_deleted');
                        $messageType = 'success';
                    }
                }
            } else {
                $message = site_text($language, 'dashboard_invalid_action');
                $messageType = 'error';
            }
        }
    }

    $statement = $pdo->prepare(
        'SELECT id, title, description, activity_date, created_by
         FROM site_activities
         WHERE language = :language
         ORDER BY activity_date DESC, created_at DESC'
    );
    $statement->execute(['language' => $language]);
    $activities = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('Mahaba activities database error: ' . $exception->getMessage());
    $message = site_text($language, 'database_error');
    $messageType = 'error';
} catch (Throwable $exception) {
    error_log('Mahaba activities error: ' . $exception->getMessage());
    $message = site_text($language, 'setup_error');
    $messageType = 'error';
}
?>
<!doctype html>
<html lang="<?= site_escape($language) ?>" dir="<?= $language === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#ffffff">
    <title><?= site_escape(site_text($language, 'activities_title')) ?> - <?= site_escape(site_text($language, 'name')) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php site_header($language, 'activities'); ?>
    <main class="page-content">
        <section class="content-card" aria-labelledby="activities-title">
            <h1 id="activities-title"><?= site_escape(site_text($language, 'activities_title')) ?></h1>
            <p><?= site_escape(site_text($language, 'activities_intro')) ?></p>
            <?php if ($message !== ''): ?>
                <p class="form-message <?= $messageType === 'success' ? 'success' : '' ?>" role="status"><?= site_escape($message) ?></p>
            <?php endif; ?>

            <?php if ($currentUser): ?>
                <section class="activity-editor" aria-labelledby="add-activity-title">
                    <h2 id="add-activity-title"><?= site_escape(site_text($language, 'activity_add')) ?></h2>
                    <form class="login-form" method="post" action="activities.php?lang=<?= site_escape($language) ?>">
                        <input type="hidden" name="csrf_token" value="<?= site_escape(mahaba_csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_activity">
                        <label class="form-field">
                            <?= site_escape(site_text($language, 'activity_title')) ?>
                            <input type="text" name="title" maxlength="190" required>
                        </label>
                        <label class="form-field">
                            <?= site_escape(site_text($language, 'activity_description')) ?>
                            <textarea name="description" rows="4" maxlength="16000" required></textarea>
                        </label>
                        <label class="form-field">
                            <?= site_escape(site_text($language, 'activity_date')) ?>
                            <input type="date" name="activity_date">
                        </label>
                        <button class="button-primary" type="submit"><?= site_escape(site_text($language, 'activity_add')) ?></button>
                    </form>
                </section>
            <?php endif; ?>

            <section class="activities-list" aria-label="<?= site_escape(site_text($language, 'activities_title')) ?>">
                <?php if (!$activities): ?>
                    <p><?= site_escape(site_text($language, 'activity_none')) ?></p>
                <?php endif; ?>
                <?php foreach ($activities as $activity): ?>
                    <article class="activity-card">
                        <?php if ($currentUser && ($currentUser['role'] === 'admin' || (int) $activity['created_by'] === (int) $currentUser['id'])): ?>
                            <div class="activity-actions">
                                <form class="activity-edit-form" method="post" action="activities.php?lang=<?= site_escape($language) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= site_escape(mahaba_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="save_activity">
                                    <input type="hidden" name="activity_id" value="<?= (int) $activity['id'] ?>">
                                    <label class="form-field">
                                        <?= site_escape(site_text($language, 'activity_title')) ?>
                                        <input type="text" name="title" value="<?= site_escape($activity['title']) ?>" maxlength="190" required>
                                    </label>
                                    <label class="form-field">
                                        <?= site_escape(site_text($language, 'activity_description')) ?>
                                        <textarea name="description" rows="4" maxlength="16000" required><?= site_escape($activity['description']) ?></textarea>
                                    </label>
                                    <label class="form-field">
                                        <?= site_escape(site_text($language, 'activity_date')) ?>
                                        <input type="date" name="activity_date" value="<?= site_escape($activity['activity_date'] ?? '') ?>">
                                    </label>
                                    <button class="button-secondary" type="submit"><?= site_escape(site_text($language, 'activity_save')) ?></button>
                                </form>
                                    <form method="post" action="activities.php?lang=<?= site_escape($language) ?>"
                                        onsubmit="return confirm(<?= site_escape(json_encode(site_text($language, 'activity_confirm_delete'))) ?>);">
                                        <input type="hidden" name="csrf_token" value="<?= site_escape(mahaba_csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_activity">
                                        <input type="hidden" name="activity_id" value="<?= (int) $activity['id'] ?>">
                                        <button class="button-danger" type="submit"><?= site_escape(site_text($language, 'activity_delete')) ?></button>
                                    </form>
                            </div>
                        <?php else: ?>
                            <h2><?= site_escape($activity['title']) ?></h2>
                            <?php if ($activity['activity_date'] !== null): ?>
                                <p class="activity-date"><?= site_escape($activity['activity_date']) ?></p>
                            <?php endif; ?>
                            <p><?= nl2br(site_escape($activity['description'])) ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
        </section>
    </main>
    <?php site_footer($language); ?>
</body>
</html>
