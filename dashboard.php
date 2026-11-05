<?php
require __DIR__ . '/includes/site.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/home-content.php';
require __DIR__ . '/includes/contact-content.php';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/footer.php';

mahaba_start_session();

$requestedLanguage = $_GET['lang'] ?? 'en';
$language = is_string($requestedLanguage) && isset($siteTranslations[$requestedLanguage])
    ? $requestedLanguage
    : 'en';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php?lang=' . rawurlencode($language));
    exit;
}

$message = '';
$messageType = '';
$currentUser = null;
$users = [];
$posts = [];
$postLanguages = [
    'ar' => 'العربية',
    'en' => 'English',
    'fr' => 'Français',
    'tr' => 'Türkçe',
];
$postLanguageOrder = array_merge(
    [$language],
    array_values(array_diff(array_keys($postLanguages), [$language]))
);
$postTranslations = [];
$userCount = 0;
$adminCount = 0;
$postImageLimit = rtrim(rtrim(number_format(mahaba_post_image_max_bytes() / (1024 * 1024), 1, '.', ''), '0'), '.');
$postImageHelp = sprintf(site_text($language, 'post_image_help'), $postImageLimit);
$homeContent = $homeContentDefaults;
$contactContent = $contactContentDefaults;

try {
    $pdo = mahaba_database();
    mahaba_ensure_user_roles($pdo);
    mahaba_ensure_account_recovery($pdo);
    mahaba_ensure_content_tables($pdo);
    $homeContent = mahaba_load_home_content($pdo, $homeContentDefaults);
    $contactContent = mahaba_load_contact_content($pdo, $contactContentDefaults);

    $statement = $pdo->prepare('SELECT id, username, email, role FROM users WHERE id = :id LIMIT 1');
    $statement->execute(['id' => (int) $_SESSION['user_id']]);
    $currentUser = $statement->fetch();

    if (!$currentUser) {
        unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role']);
        header('Location: login.php?lang=' . rawurlencode($language));
        exit;
    }

    $_SESSION['username'] = $currentUser['username'];
    $_SESSION['role'] = $currentUser['role'];

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (mahaba_post_request_exceeds_limit()) {
            $message = site_text($language, 'post_request_too_large');
            $messageType = 'error';
        } elseif (!mahaba_valid_csrf($_POST['csrf_token'] ?? null)) {
            $message = site_text($language, 'csrf_error');
            $messageType = 'error';
        } elseif (($_POST['action'] ?? '') === 'save_home_content') {
            $submittedContent = $_POST['content'] ?? null;
            $validatedContent = [];
            $invalidContent = !is_array($submittedContent);

            if (!$invalidContent) {
                foreach ($homeContentDefaults as $contentLanguage => $defaultFields) {
                    if (!isset($submittedContent[$contentLanguage]) || !is_array($submittedContent[$contentLanguage])) {
                        $invalidContent = true;
                        break;
                    }

                    foreach ($defaultFields as $field => $defaultValue) {
                        $value = $submittedContent[$contentLanguage][$field] ?? null;
                        $mediaFields = [
                            'video_url',
                            'story_link_url',
                            'story_link_image',
                            'logo_image',
                            'event_image',
                            'activities_image',
                        ];
                        if (in_array($field, $mediaFields, true)) {
                            $safeValue = is_string($value)
                                ? mahaba_safe_content_value($field, $value)
                                : null;
                            if ($safeValue === null) {
                                $invalidContent = true;
                                break 2;
                            }
                            $validatedContent[$contentLanguage][$field] = $safeValue;
                            continue;
                        }

                        $maxLength = in_array($field, ['video_title', 'story_title'], true) ? 190 : 4000;
                        $characterCount = is_string($value) ? mahaba_character_count($value) : null;

                        if (
                            $characterCount === null
                            || trim($value) === ''
                            || $characterCount > $maxLength
                            || strlen($value) > $maxLength * 4
                            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
                        ) {
                            $invalidContent = true;
                            break 2;
                        }

                        $validatedContent[$contentLanguage][$field] = trim($value);
                    }
                }
            }

            if ($invalidContent) {
                $message = site_text($language, 'content_invalid');
                $messageType = 'error';
            } else {
                $pdo->beginTransaction();
                try {
                    $statement = $pdo->prepare(
                        'INSERT INTO site_content (content_key, language, content_value)
                         VALUES (:content_key, :language, :content_value)
                         ON DUPLICATE KEY UPDATE content_value = VALUES(content_value)'
                    );
                    foreach ($validatedContent as $contentLanguage => $fields) {
                        foreach ($fields as $field => $value) {
                            $statement->execute([
                                'content_key' => $field,
                                'language' => $contentLanguage,
                                'content_value' => $value,
                            ]);
                        }
                    }
                    $englishStory = $validatedContent['en'];
                    $storyContent = implode("\n\n", [
                        $englishStory['story_paragraph_1'],
                        $englishStory['story_paragraph_2'],
                    ]);
                    $pdo->prepare(
                        'UPDATE posts SET title = :title, content = :content, image = :image WHERE id = 1'
                    )->execute([
                        'title' => $englishStory['story_title'],
                        'content' => $storyContent,
                        'image' => $englishStory['event_image'],
                    ]);
                    $storyTranslationStatement = $pdo->prepare(
                        'INSERT INTO post_translations (post_id, language, title, content)
                         SELECT 1, :language, :title, :content
                         WHERE EXISTS (SELECT 1 FROM posts WHERE id = 1)
                         ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content)'
                    );
                    foreach ($validatedContent as $contentLanguage => $fields) {
                        $storyTranslationStatement->execute([
                            'language' => $contentLanguage,
                            'title' => $fields['story_title'],
                            'content' => implode("\n\n", [
                                $fields['story_paragraph_1'],
                                $fields['story_paragraph_2'],
                            ]),
                        ]);
                    }
                    $pdo->commit();
                } catch (PDOException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $exception;
                }
                $homeContent = $validatedContent;
                $message = site_text($language, 'content_saved');
                $messageType = 'success';
            }
        } elseif (($_POST['action'] ?? '') === 'save_contact_content') {
            if ($currentUser['role'] !== 'admin') {
                http_response_code(403);
                $message = site_text($language, 'dashboard_access_denied');
                $messageType = 'error';
            } else {
                $submittedContent = $_POST['contact_content'] ?? null;
                $validatedContent = [];
                $invalidContent = !is_array($submittedContent);
                $optionalFields = ['facebook_url', 'instagram_url'];

                if (!$invalidContent) {
                    foreach ($contactContentDefaults as $contentLanguage => $defaultFields) {
                        if (!isset($submittedContent[$contentLanguage]) || !is_array($submittedContent[$contentLanguage])) {
                            $invalidContent = true;
                            break;
                        }

                        foreach ($defaultFields as $field => $defaultValue) {
                            $value = $submittedContent[$contentLanguage][$field] ?? null;
                            if (!is_string($value)) {
                                $invalidContent = true;
                                break 2;
                            }
                            $value = trim($value);

                            if ($field === 'email_address') {
                                if (filter_var($value, FILTER_VALIDATE_EMAIL) === false || strlen($value) > 254) {
                                    $invalidContent = true;
                                    break 2;
                                }
                            } elseif (in_array($field, ['facebook_url', 'instagram_url'], true)) {
                                if ($value !== '') {
                                    $safeUrl = mahaba_safe_external_url($value);
                                    if ($safeUrl === null) {
                                        $invalidContent = true;
                                        break 2;
                                    }
                                    $value = $safeUrl;
                                }
                            } else {
                                $maxLength = in_array($field, [
                                    'hero_title',
                                    'first_name_label',
                                    'first_name_placeholder',
                                    'last_name_label',
                                    'last_name_placeholder',
                                    'email_label',
                                    'email_placeholder',
                                    'phone_label',
                                    'phone_placeholder',
                                    'city_label',
                                    'city_placeholder',
                                    'message_label',
                                    'message_placeholder',
                                    'send_label',
                                    'registration_number',
                                ], true)
                                    ? 190
                                    : 4000;
                                $characterCount = mahaba_character_count($value);
                                if (
                                    $characterCount === null
                                    || $characterCount > $maxLength
                                    || strlen($value) > $maxLength * 4
                                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
                                    || ($value === '' && !in_array($field, $optionalFields, true))
                                ) {
                                    $invalidContent = true;
                                    break 2;
                                }
                            }
                            $validatedContent[$contentLanguage][$field] = $value;
                        }
                    }
                }

                if ($invalidContent) {
                    $message = site_text($language, 'contact_content_invalid');
                    $messageType = 'error';
                } else {
                    $pdo->beginTransaction();
                    try {
                        $statement = $pdo->prepare(
                            'INSERT INTO site_content (content_key, language, content_value)
                             VALUES (:content_key, :language, :content_value)
                             ON DUPLICATE KEY UPDATE content_value = VALUES(content_value)'
                        );
                        foreach ($validatedContent as $contentLanguage => $fields) {
                            foreach ($fields as $field => $value) {
                                $statement->execute([
                                    'content_key' => 'contact_' . $field,
                                    'language' => $contentLanguage,
                                    'content_value' => $value,
                                ]);
                            }
                        }
                        $pdo->commit();
                    } catch (PDOException $exception) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        throw $exception;
                    }
                    $contactContent = $validatedContent;
                    $message = site_text($language, 'contact_content_saved');
                    $messageType = 'success';
                }
            }
        } elseif ($currentUser['role'] !== 'admin') {
            http_response_code(403);
            $message = site_text($language, 'dashboard_access_denied');
            $messageType = 'error';
        } else {
            $action = $_POST['action'] ?? '';

            if ($action === 'save_post') {
                $rawPostId = $_POST['post_id'] ?? '';
                $postId = $rawPostId === ''
                    ? null
                    : filter_var($rawPostId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $submittedTranslations = $_POST['translations'] ?? null;
                $submittedImageUrls = $_POST['image_urls'] ?? null;
                $submittedVideo = $_POST['video'] ?? null;
                $submittedCategory = $_POST['category'] ?? null;
                $submittedAuthorId = $_POST['author_id'] ?? null;
                $validatedTranslations = [];
                $invalidTranslations = !is_array($submittedTranslations);
                if (!$invalidTranslations) {
                    foreach ($postLanguages as $translationLanguage => $languageName) {
                        if (
                            !isset($submittedTranslations[$translationLanguage])
                            || !is_array($submittedTranslations[$translationLanguage])
                        ) {
                            $invalidTranslations = true;
                            break;
                        }

                        foreach (['title' => 255, 'content' => 100000] as $field => $maxLength) {
                            $value = $submittedTranslations[$translationLanguage][$field] ?? null;
                            if (!is_string($value)) {
                                $invalidTranslations = true;
                                break 2;
                            }
                            $value = trim($value);
                            $characterCount = mahaba_character_count($value);
                            $maxBytes = $maxLength * 4;
                            if (
                                $characterCount === null
                                || $characterCount > $maxLength
                                || strlen($value) > $maxBytes
                                || preg_match($field === 'title'
                                    ? '/[\x00-\x1F\x7F]/'
                                    : '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
                            ) {
                                $invalidTranslations = true;
                                break 2;
                            }
                            $validatedTranslations[$translationLanguage][$field] = $value;
                        }
                    }
                }
                $title = $validatedTranslations['en']['title'] ?? null;
                $content = $validatedTranslations['en']['content'] ?? null;
                $imageUrls = is_string($submittedImageUrls)
                    ? array_values(array_filter(array_map('trim', preg_split('/\R/u', $submittedImageUrls) ?: []), static fn(string $url): bool => $url !== ''))
                    : null;
                $safeImageUrls = [];
                if ($imageUrls !== null) {
                    foreach ($imageUrls as $imageUrl) {
                        $safeImageUrl = mahaba_safe_image_url($imageUrl);
                        if ($safeImageUrl === null) {
                            $safeImageUrls = null;
                            break;
                        }
                        $safeImageUrls[] = $safeImageUrl;
                    }
                }
                $videoValue = is_string($submittedVideo) ? trim($submittedVideo) : null;
                $categoryName = is_string($submittedCategory) ? trim($submittedCategory) : null;
                $authorId = filter_var($submittedAuthorId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $status = $_POST['status'] ?? '';
                $video = $videoValue === null || $videoValue === '' ? null : mahaba_youtube_embed_url($videoValue);
                $uploads = $_FILES['image_uploads'] ?? null;
                $uploadFieldProvided = array_key_exists('image_uploads', $_FILES);
                $removeImageIds = $_POST['remove_image_ids'] ?? [];
                $invalidUploadShape = $uploadFieldProvided && (
                    !is_array($uploads)
                    || !isset($uploads['name'], $uploads['type'], $uploads['tmp_name'], $uploads['error'], $uploads['size'])
                    || !is_array($uploads['name'])
                    || !is_array($uploads['type'])
                    || !is_array($uploads['tmp_name'])
                    || !is_array($uploads['error'])
                    || !is_array($uploads['size'])
                );
                $imageUploadCount = is_array($uploads) && is_array($uploads['error'] ?? null)
                    ? count(array_filter($uploads['error'], static fn(mixed $error): bool => $error !== UPLOAD_ERR_NO_FILE))
                    : 0;
                $invalidRemovals = !is_array($removeImageIds);
                $validatedRemoveImageIds = [];
                if (!$invalidRemovals) {
                    foreach ($removeImageIds as $removeImageId) {
                        $validatedId = filter_var($removeImageId, FILTER_VALIDATE_INT, [
                            'options' => ['min_range' => 1],
                        ]);
                        if ($validatedId === false || $validatedId === null) {
                            $invalidRemovals = true;
                            break;
                        }
                        $validatedRemoveImageIds[] = (int) $validatedId;
                    }
                }
                $categoryLength = $categoryName === null ? null : mahaba_character_count($categoryName);
                $invalidPost = ($rawPostId !== '' && ($postId === false || $postId === null))
                    || $invalidTranslations
                    || $imageUrls === null
                    || $safeImageUrls === null
                    || count($imageUrls) + $imageUploadCount > 20
                    || $videoValue === null
                    || ($videoValue !== '' && $video === null)
                    || $categoryLength === null
                    || $categoryLength > 255
                    || strlen($categoryName) > 1020
                    || preg_match('/[\x00-\x1F\x7F]/', $categoryName)
                    || $authorId === false
                    || !in_array($status, ['published', 'draft'], true)
                    || $invalidUploadShape
                    || $imageUploadCount > 20
                    || $invalidRemovals;
                $existingPost = null;
                $existingImages = [];

                if (!$invalidPost && $postId !== null) {
                    $existingStatement = $pdo->prepare('SELECT id, image FROM posts WHERE id = :id LIMIT 1');
                    $existingStatement->execute(['id' => $postId]);
                    $existingPost = $existingStatement->fetch() ?: null;
                    $invalidPost = $existingPost === null;
                    if ($existingPost !== null) {
                        $imageStatement = $pdo->prepare(
                            'SELECT id, image, sort_order FROM post_images WHERE post_id = :post_id ORDER BY sort_order, id'
                        );
                        $imageStatement->execute(['post_id' => $postId]);
                        $existingImages = $imageStatement->fetchAll();
                        $existingImageIds = array_map(static fn(array $image): int => (int) $image['id'], $existingImages);
                        $invalidPost = array_diff($validatedRemoveImageIds, $existingImageIds) !== [];
                    }
                }

                if (!$invalidPost) {
                    $authorCheck = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
                    $authorCheck->execute(['id' => $authorId]);
                    $invalidPost = $authorCheck->fetchColumn() === false;
                }

                if ($invalidPost) {
                    $message = site_text($language, 'post_invalid');
                    $messageType = 'error';
                } else {
                    $savedImages = [];
                    try {
                        if (!$invalidUploadShape) {
                            foreach ($uploads['error'] ?? [] as $uploadIndex => $uploadError) {
                                if ($uploadError === UPLOAD_ERR_NO_FILE) {
                                    continue;
                                }
                                $upload = [
                                    'name' => $uploads['name'][$uploadIndex] ?? '',
                                    'type' => $uploads['type'][$uploadIndex] ?? '',
                                    'tmp_name' => $uploads['tmp_name'][$uploadIndex] ?? '',
                                    'error' => $uploadError,
                                    'size' => $uploads['size'][$uploadIndex] ?? null,
                                ];
                                $savedImages[] = mahaba_save_post_image($upload);
                            }
                        }
                        $removedImageIds = array_fill_keys($validatedRemoveImageIds, true);
                        $remainingImages = array_values(array_filter(
                            $existingImages,
                            static fn(array $image): bool => !isset($removedImageIds[(int) $image['id']])
                        ));
                        $newImages = array_merge($safeImageUrls, $savedImages);
                        $firstImage = $remainingImages[0]['image'] ?? $newImages[0] ?? null;

                        $pdo->beginTransaction();
                        $categoryId = null;
                        if ($categoryName !== '') {
                            $categoryStatement = $pdo->prepare(
                                'INSERT INTO categories (name) VALUES (:name)
                                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
                            );
                            $categoryStatement->execute(['name' => $categoryName]);
                            $categoryId = (int) $pdo->lastInsertId();
                        }

                        $values = [
                            'title' => $title === '' ? null : $title,
                            'content' => $content === '' ? null : $content,
                            'image' => $firstImage,
                            'video' => $video,
                            'user_id' => $authorId,
                            'category_id' => $categoryId,
                            'status' => $status,
                        ];
                        if ($postId === null) {
                            $statement = $pdo->prepare(
                                'INSERT INTO posts (title, content, image, video, user_id, category_id, status)
                                 VALUES (:title, :content, :image, :video, :user_id, :category_id, :status)'
                            );
                            $statement->execute($values);
                            $savedPostId = (int) $pdo->lastInsertId();
                            $message = site_text($language, 'post_saved');
                        } else {
                            $values['id'] = $postId;
                            $statement = $pdo->prepare(
                                'UPDATE posts
                                 SET title = :title, content = :content, image = :image, video = :video,
                                     user_id = :user_id, category_id = :category_id, status = :status
                                 WHERE id = :id'
                            );
                            $statement->execute($values);
                            $savedPostId = $postId;
                            $message = site_text($language, 'post_updated');
                        }
                        $translationStatement = $pdo->prepare(
                            'INSERT INTO post_translations (post_id, language, title, content)
                             VALUES (:post_id, :language, :title, :content)
                             ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content)'
                        );
                        foreach ($validatedTranslations as $translationLanguage => $translation) {
                            $translationStatement->execute([
                                'post_id' => $savedPostId,
                                'language' => $translationLanguage,
                                'title' => $translation['title'] === '' ? null : $translation['title'],
                                'content' => $translation['content'] === '' ? null : $translation['content'],
                            ]);
                        }
                        if ($validatedRemoveImageIds !== []) {
                            $deleteImages = $pdo->prepare(
                                'DELETE FROM post_images WHERE post_id = :post_id AND id = :image_id'
                            );
                            foreach ($validatedRemoveImageIds as $imageId) {
                                $deleteImages->execute(['post_id' => $savedPostId, 'image_id' => $imageId]);
                            }
                        }
                        $imageInsert = $pdo->prepare(
                            'INSERT INTO post_images (post_id, image, sort_order)
                             VALUES (:post_id, :image, :sort_order)'
                        );
                        $nextSortOrder = $remainingImages === []
                            ? 0
                            : max(array_column($remainingImages, 'sort_order')) + 1;
                        foreach ($newImages as $imageIndex => $newImage) {
                            $imageInsert->execute([
                                'post_id' => $savedPostId,
                                'image' => $newImage,
                                'sort_order' => $nextSortOrder + $imageIndex,
                            ]);
                        }
                        $imageValues = array_merge(
                            array_column($remainingImages, 'image'),
                            $newImages
                        );
                        $pdo->prepare('UPDATE posts SET image = :image WHERE id = :id')
                            ->execute(['image' => $imageValues[0] ?? null, 'id' => $savedPostId]);
                        $pdo->commit();

                        foreach ($existingImages as $existingImage) {
                            if (!isset($removedImageIds[(int) $existingImage['id']])) {
                                continue;
                            }
                            try {
                                mahaba_delete_post_image($existingImage['image']);
                            } catch (RuntimeException $exception) {
                                error_log('Mahaba removed post image cleanup error: ' . $exception->getMessage());
                                $message = site_text($language, 'post_image_cleanup_failed');
                                $messageType = 'error';
                            }
                        }
                        if ($messageType !== 'error') {
                            $messageType = 'success';
                        }
                    } catch (RuntimeException $exception) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        foreach ($savedImages as $savedImage) {
                            mahaba_delete_post_image($savedImage);
                        }
                        error_log('Mahaba post image error: ' . $exception->getMessage());
                        $message = sprintf(site_text($language, 'post_image_invalid'), $postImageLimit);
                        $messageType = 'error';
                    } catch (PDOException $exception) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        foreach ($savedImages as $savedImage) {
                            mahaba_delete_post_image($savedImage);
                        }
                        throw $exception;
                    }
                }
            } elseif ($action === 'toggle_post_status') {
                $postId = filter_var($_POST['post_id'] ?? null, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);
                if ($postId === false || $postId === null) {
                    $message = site_text($language, 'dashboard_invalid_action');
                    $messageType = 'error';
                } else {
                    $statement = $pdo->prepare(
                        "UPDATE posts
                         SET status = CASE WHEN status = 'published' THEN 'draft' ELSE 'published' END
                         WHERE id = :id"
                    );
                    $statement->execute(['id' => $postId]);
                    $message = $statement->rowCount() > 0
                        ? site_text($language, 'post_status_updated')
                        : site_text($language, 'dashboard_invalid_action');
                    $messageType = $statement->rowCount() > 0 ? 'success' : 'error';
                }
            } elseif ($action === 'delete_post') {
                $postId = filter_var($_POST['post_id'] ?? null, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);
                if ($postId === false || $postId === null) {
                    $message = site_text($language, 'dashboard_invalid_action');
                    $messageType = 'error';
                } else {
                    $statement = $pdo->prepare('SELECT image FROM posts WHERE id = :id LIMIT 1');
                    $statement->execute(['id' => $postId]);
                    $image = $statement->fetchColumn();
                    if ($image === false) {
                        $message = site_text($language, 'dashboard_invalid_action');
                        $messageType = 'error';
                    } else {
                        $imageStatement = $pdo->prepare(
                            'SELECT image FROM post_images WHERE post_id = :post_id ORDER BY sort_order, id'
                        );
                        $imageStatement->execute(['post_id' => $postId]);
                        $images = array_column($imageStatement->fetchAll(), 'image');
                        if ($images === [] && is_string($image) && $image !== '') {
                            $images[] = $image;
                        }
                        $pdo->prepare('DELETE FROM posts WHERE id = :id')->execute(['id' => $postId]);
                        $message = site_text($language, 'post_deleted');
                        $messageType = 'success';
                        foreach ($images as $image) {
                            try {
                                mahaba_delete_post_image($image);
                            } catch (RuntimeException $exception) {
                                error_log('Mahaba deleted post image cleanup error: ' . $exception->getMessage());
                                $message = site_text($language, 'post_delete_image_cleanup_failed');
                                $messageType = 'error';
                            }
                        }
                    }
                }
            } elseif ($action === 'create_user') {
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

                if (
                    $username === ''
                    || $usernameLength === null
                    || $usernameLength > 190
                    || strlen($username) > 760
                    || preg_match('/[\x00-\x1F\x7F]/', $username)
                ) {
                    $message = site_text($language, 'username_invalid');
                    $messageType = 'error';
                } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
                    $message = site_text($language, 'email_invalid');
                    $messageType = 'error';
                } elseif ($passwordLength === null || $passwordLength < 12) {
                    $message = site_text($language, 'dashboard_password_short');
                    $messageType = 'error';
                } elseif (!is_string($confirmation) || !hash_equals($password, $confirmation)) {
                    $message = site_text($language, 'password_mismatch');
                    $messageType = 'error';
                } else {
                    try {
                        $statement = $pdo->prepare(
                            "INSERT INTO users (username, email, password_hash, role)
                             VALUES (:username, :email, :password_hash, 'user')"
                        );
                        $statement->execute([
                            'username' => $username,
                            'email' => $email,
                            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        ]);
                        $message = site_text($language, 'dashboard_user_created');
                        $messageType = 'success';
                    } catch (PDOException $exception) {
                        if ($exception->getCode() === '23000') {
                            $emailCheck = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
                            $emailCheck->execute(['email' => $email]);
                            $message = site_text(
                                $language,
                                $emailCheck->fetchColumn() !== false
                                    ? 'dashboard_email_duplicate'
                                    : 'dashboard_duplicate_user'
                            );
                            $messageType = 'error';
                        } else {
                            throw $exception;
                        }
                    }
                }
            } elseif ($action === 'save_user_email') {
                $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);
                $submittedEmail = $_POST['email'] ?? null;
                $email = is_string($submittedEmail) ? trim($submittedEmail) : null;

                if ($targetId === false || $targetId === null || $email === null) {
                    $message = site_text($language, 'dashboard_invalid_action');
                    $messageType = 'error';
                } elseif ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254)) {
                    $message = site_text($language, 'dashboard_email_invalid');
                    $messageType = 'error';
                } else {
                    try {
                        $statement = $pdo->prepare('UPDATE users SET email = :email WHERE id = :id');
                        $statement->execute(['email' => $email === '' ? null : $email, 'id' => $targetId]);
                        $accountExists = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
                        $accountExists->execute(['id' => $targetId]);
                        if ($accountExists->fetchColumn() === false) {
                            $message = site_text($language, 'dashboard_invalid_action');
                            $messageType = 'error';
                        } else {
                            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id')
                                ->execute(['user_id' => $targetId]);
                            $message = site_text($language, 'dashboard_email_updated');
                            $messageType = 'success';
                        }
                    } catch (PDOException $exception) {
                        if ($exception->getCode() === '23000') {
                            $message = site_text($language, 'dashboard_email_duplicate');
                            $messageType = 'error';
                        } else {
                            throw $exception;
                        }
                    }
                }
            } elseif ($action === 'change_role' || $action === 'delete_user') {
                $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);

                if ($targetId === false || $targetId === null) {
                    $message = site_text($language, 'dashboard_invalid_action');
                    $messageType = 'error';
                } elseif ($action === 'delete_user' && (int) $targetId === (int) $currentUser['id']) {
                    $message = site_text($language, 'dashboard_cannot_delete_self');
                    $messageType = 'error';
                } else {
                    $lock = $pdo->query("SELECT GET_LOCK('mahaba_admin_manage_users', 10)")->fetchColumn();
                    if ((int) $lock !== 1) {
                        throw new RuntimeException('Unable to acquire the account-management lock.');
                    }

                    try {
                        $targetQuery = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
                        $targetQuery->execute(['id' => $targetId]);
                        $target = $targetQuery->fetch();

                        if (!$target) {
                            $message = site_text($language, 'dashboard_invalid_action');
                            $messageType = 'error';
                        } else {
                            $newRole = $_POST['role'] ?? '';
                            $removingAdmin = $target['role'] === 'admin'
                                && ($action === 'delete_user' || $newRole === 'user');
                            $lastAdmin = $removingAdmin
                                && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() <= 1;

                            if ($lastAdmin) {
                                $message = site_text($language, 'dashboard_last_admin');
                                $messageType = 'error';
                            } elseif ($action === 'change_role' && !in_array($newRole, ['admin', 'user'], true)) {
                                $message = site_text($language, 'dashboard_invalid_action');
                                $messageType = 'error';
                            } elseif ($action === 'change_role') {
                                $statement = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
                                $statement->execute(['role' => $newRole, 'id' => $targetId]);
                                $message = site_text($language, 'dashboard_role_updated');
                                $messageType = 'success';
                            } else {
                                $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id')
                                    ->execute(['user_id' => $targetId]);
                                $statement = $pdo->prepare('DELETE FROM users WHERE id = :id');
                                $statement->execute(['id' => $targetId]);
                                $message = site_text($language, 'dashboard_user_deleted');
                                $messageType = 'success';
                            }
                        }
                    } finally {
                        $released = $pdo->query("SELECT RELEASE_LOCK('mahaba_admin_manage_users')")->fetchColumn();
                        if ((int) $released !== 1) {
                            throw new RuntimeException('Unable to release the account-management lock.');
                        }
                    }
                }
            } else {
                $message = site_text($language, 'dashboard_invalid_action');
                $messageType = 'error';
            }
        }
    }

    if ($currentUser['role'] === 'admin') {
        $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        $users = $pdo->query('SELECT id, username, email, role, created_at FROM users ORDER BY id ASC')->fetchAll();
        $posts = $pdo->query(
            'SELECT posts.id, posts.title, posts.content, posts.image, posts.video, posts.user_id,
                    posts.category_id, posts.status, posts.created_at,
                    users.username AS author, categories.name AS category
             FROM posts
             LEFT JOIN users ON users.id = posts.user_id
             LEFT JOIN categories ON categories.id = posts.category_id
             ORDER BY posts.id ASC'
        )->fetchAll();
        $postImages = [];
        $imageRows = $pdo->query(
            'SELECT post_id, id, image FROM post_images ORDER BY post_id, sort_order, id'
        )->fetchAll();
        foreach ($imageRows as $imageRow) {
            $postImages[(int) $imageRow['post_id']][] = $imageRow;
        }
        $postTranslations = [];
        foreach ($posts as $post) {
            $postId = (int) $post['id'];
            foreach ($postLanguages as $translationLanguage => $languageName) {
                $postTranslations[$postId][$translationLanguage] = [
                    'title' => '',
                    'content' => '',
                ];
                if ($postId === 1) {
                    $postTranslations[$postId][$translationLanguage] = [
                        'title' => $homeContent[$translationLanguage]['story_title'],
                        'content' => implode("\n\n", [
                            $homeContent[$translationLanguage]['story_paragraph_1'],
                            $homeContent[$translationLanguage]['story_paragraph_2'],
                        ]),
                    ];
                }
            }
        }
        $translationRows = $pdo->query(
            'SELECT post_id, language, title, content FROM post_translations'
        )->fetchAll();
        foreach ($translationRows as $translationRow) {
            $postId = (int) $translationRow['post_id'];
            if (isset($postTranslations[$postId][$translationRow['language']])) {
                $postTranslations[$postId][$translationRow['language']] = [
                    'title' => $translationRow['title'] ?? '',
                    'content' => $translationRow['content'] ?? '',
                ];
            }
        }
    }
} catch (PDOException $exception) {
    error_log('Mahaba dashboard database error: ' . $exception->getMessage());
    $message = site_text($language, 'database_error');
    $messageType = 'error';
} catch (RuntimeException $exception) {
    error_log('Mahaba dashboard error: ' . $exception->getMessage());
    $message = site_text($language, 'setup_error');
    $messageType = 'error';
}
$submittedAction = $_POST['action'] ?? '';
$openDashboardPanel = match ($submittedAction) {
    'save_home_content' => 'home',
    'save_contact_content' => 'contact',
    'save_post', 'toggle_post_status', 'delete_post' => 'posts',
    'create_user' => 'create-user',
    'save_user_email', 'change_role', 'delete_user' => 'users',
    default => '',
};
?>
<!doctype html>
<html lang="<?= site_escape($language) ?>" dir="<?= $language === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#ffffff">
    <title><?= site_escape(site_text($language, 'dashboard_title')) ?> - <?= site_escape(site_text($language, 'name')) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php site_header($language, 'dashboard'); ?>
    <main class="page-content dashboard-content">
        <section class="content-card" aria-labelledby="dashboard-title">
            <h1 id="dashboard-title"><?= site_escape(site_text($language, 'dashboard_title')) ?></h1>
            <p><?= site_escape(site_text($language, 'dashboard_intro')) ?></p>
            <?php if ($message !== ''): ?>
                <p class="form-message <?= $messageType === 'success' ? 'success' : '' ?>" role="status"><?= site_escape($message) ?></p>
            <?php endif; ?>

            <?php if ($currentUser): ?>
                <p class="permission-note"><?= site_escape(site_text($language, $currentUser['role'] === 'admin' ? 'permissions_admin' : 'permissions_user')) ?></p>

                <details class="dashboard-create content-editor dashboard-panel" <?= $openDashboardPanel === 'home' ? 'open' : '' ?>>
                    <summary class="dashboard-panel-summary">
                        <span id="content-title" role="heading" aria-level="2"><?= site_escape(site_text($language, 'content_section')) ?></span>
                    </summary>
                    <p><?= site_escape(site_text($language, 'content_intro')) ?></p>
                    <form method="post" action="dashboard.php?lang=<?= site_escape($language) ?>">
                        <input type="hidden" name="csrf_token" value="<?= site_escape(mahaba_csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_home_content">
                        <div class="table-scroll">
                            <table class="users-table content-language-list">
                                <tbody>
                                    <?php foreach ($homeContentDefaults as $contentLanguage => $defaultFields): ?>
                                        <tr>
                                            <td colspan="2">
                                                <details class="content-language-dropdown">
                                                    <summary><?= site_escape(site_text($language, 'content_language')) ?>: <?= site_escape(['ar' => 'العربية', 'en' => 'English', 'fr' => 'Français', 'tr' => 'Türkçe'][$contentLanguage]) ?></summary>
                                                    <section class="content-language-table">
                                                        <p><?= site_escape(site_text($language, 'content_media_note')) ?></p>
                                                        <div class="table-scroll">
                                                            <table class="users-table content-table">
                                                                <thead>
                                                                    <tr>
                                                                        <th scope="col"><?= site_escape(site_text($language, 'content_field')) ?></th>
                                                                        <th scope="col"><?= site_escape(site_text($language, 'content_value')) ?></th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ($defaultFields as $field => $defaultValue): ?>
                                                                        <?php
                                                                        $fieldId = 'home-' . $contentLanguage . '-' . $field;
                                                                        $fieldLabel = site_text($language, [
                                                                            'video_title' => 'content_video_title',
                                                                            'video_url' => 'content_video_url',
                                                                            'story_title' => 'content_story_title',
                                                                            'story_paragraph_1' => 'content_paragraph_1',
                                                                            'story_paragraph_2' => 'content_paragraph_2',
                                                                            'story_link_url' => 'content_story_link_url',
                                                                            'story_link_image' => 'content_story_link_image',
                                                                            'logo_image' => 'content_logo_image',
                                                                            'event_image' => 'content_event_image',
                                                                            'activities_image' => 'content_activities_image',
                                                                        ][$field]);
                                                                        ?>
                                                                        <tr>
                                                                            <th scope="row"><label for="<?= site_escape($fieldId) ?>"><?= site_escape($fieldLabel) ?></label></th>
                                                                            <td>
                                                                                <?php if (str_starts_with($field, 'story_paragraph_')): ?>
                                                                                    <textarea id="<?= site_escape($fieldId) ?>" name="content[<?= site_escape($contentLanguage) ?>][<?= site_escape($field) ?>]" rows="5" maxlength="16000" required><?= site_escape($homeContent[$contentLanguage][$field]) ?></textarea>
                                                                                <?php elseif (in_array($field, ['video_url', 'story_link_url', 'story_link_image', 'logo_image', 'event_image', 'activities_image'], true)): ?>
                                                                                    <input id="<?= site_escape($fieldId) ?>" type="text" name="content[<?= site_escape($contentLanguage) ?>][<?= site_escape($field) ?>]" value="<?= site_escape($homeContent[$contentLanguage][$field]) ?>" maxlength="2048" required>
                                                                                <?php else: ?>
                                                                                    <input id="<?= site_escape($fieldId) ?>" type="text" name="content[<?= site_escape($contentLanguage) ?>][<?= site_escape($field) ?>]" value="<?= site_escape($homeContent[$contentLanguage][$field]) ?>" maxlength="190" required>
                                                                                <?php endif; ?>
                                                                            </td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </section>
                                                </details>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <button class="button-primary" type="submit"><?= site_escape(site_text($language, 'content_save')) ?></button>
                    </form>
                </details>

                <?php if ($currentUser['role'] === 'admin'): ?>
                    <details class="dashboard-create content-editor dashboard-panel" <?= $openDashboardPanel === 'contact' ? 'open' : '' ?>>
                        <summary class="dashboard-panel-summary">
                            <span id="contact-content-title" role="heading" aria-level="2"><?= site_escape(site_text($language, 'contact_content_section')) ?></span>
                        </summary>
                        <p><?= site_escape(site_text($language, 'contact_content_intro')) ?></p>
                        <form method="post" action="dashboard.php?lang=<?= site_escape($language) ?>">
                            <input type="hidden" name="csrf_token" value="<?= site_escape(mahaba_csrf_token()) ?>">
                            <input type="hidden" name="action" value="save_contact_content">
                            <div class="table-scroll">
                                <table class="users-table content-language-list">
                                    <tbody>
                                        <?php foreach ($contactContentDefaults as $contentLanguage => $defaultFields): ?>
                                            <tr>
                                                <td colspan="2">
                                                    <details class="content-language-dropdown">
                                                        <summary><?= site_escape(site_text($language, 'content_language')) ?>: <?= site_escape(['ar' => 'العربية', 'en' => 'English', 'fr' => 'Français', 'tr' => 'Türkçe'][$contentLanguage]) ?></summary>
                                                        <section class="content-language-table">
                                                            <div class="table-scroll">
                                                                <table class="users-table content-table">
                                                                    <thead>
                                                                        <tr>
                                                                            <th scope="col"><?= site_escape(site_text($language, 'content_field')) ?></th>
                                                                            <th scope="col"><?= site_escape(site_text($language, 'content_value')) ?></th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <?php foreach ($defaultFields as $field => $defaultValue): ?>
                                                                            <?php
                                                                            $fieldId = 'contact-' . $contentLanguage . '-' . $field;
                                                                            $fieldMaxLength = match ($field) {
                                                                                'facebook_url', 'instagram_url' => 2048,
                                                                                'email_address' => 254,
                                                                                'hero_title', 'first_name_label', 'last_name_label', 'email_label',
                                                                                'phone_label', 'message_label', 'send_label', 'registration_number',
                                                                                'first_name_placeholder', 'last_name_placeholder',
                                                                                'email_placeholder', 'phone_placeholder',
                                                                                'city_label', 'city_placeholder',
                                                                                'message_placeholder' => 190,
                                                                                default => 4000,
                                                                            };
                                                                            ?>
                                                                            <tr>
                                                                                <th scope="row"><label for="<?= site_escape($fieldId) ?>"><?= site_escape($contactContentFieldLabels[$language][$field]) ?></label></th>
                                                                                <td>
                                                                                    <?php if ($field === 'hero_intro'): ?>
                                                                                        <textarea id="<?= site_escape($fieldId) ?>" name="contact_content[<?= site_escape($contentLanguage) ?>][<?= site_escape($field) ?>]" rows="3" maxlength="4000" required><?= site_escape($contactContent[$contentLanguage][$field]) ?></textarea>
                                                                                    <?php else: ?>
                                                                                        <input id="<?= site_escape($fieldId) ?>" type="text" name="contact_content[<?= site_escape($contentLanguage) ?>][<?= site_escape($field) ?>]" value="<?= site_escape($contactContent[$contentLanguage][$field]) ?>" maxlength="<?= $fieldMaxLength ?>" <?= in_array($field, ['facebook_url', 'instagram_url'], true) ? '' : 'required' ?>>
                                                                                    <?php endif; ?>
                                                                                </td>
                                                                            </tr>
                                                                        <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </section>
                                                    </details>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button class="button-primary" type="submit"><?= site_escape(site_text($language, 'contact_content_save')) ?></button>
                        </form>
                    </details>

                    <details class="dashboard-posts dashboard-panel" <?= $openDashboardPanel === 'posts' ? 'open' : '' ?>>
                        <summary class="dashboard-panel-summary">
                            <span id="posts-title" role="heading" aria-level="2"><?= site_escape(site_text($language, 'posts_title')) ?></span>
                        </summary>
                        <p><?= site_escape(site_text($language, 'posts_intro')) ?></p>
                        <p><?= site_escape(site_text($language, 'post_translation_intro')) ?></p>
                        <form class="post-editor" method="post" enctype="multipart/form-data" action="dashboard.php?lang=<?= site_escape($language) ?>">
                            <input type="hidden" name="csrf_token" value="<?= site_escape(mahaba_csrf_token()) ?>">
                            <input type="hidden" name="action" value="save_post">
                            <?php foreach ($postLanguageOrder as $translationLanguage): ?>
                                <fieldset class="content-language-panel post-editor-wide">
                                    <legend>
                                        <?= site_escape(site_text($language, 'content_language')) ?>:
                                        <?= site_escape($postLanguages[$translationLanguage]) ?>
                                        <?php if ($translationLanguage === $language): ?>
                                            (<?= site_escape(site_text($language, 'post_selected_language')) ?>)
                                        <?php endif; ?>
                                    </legend>
                                    <label class="form-field">
                                        <?= site_escape(site_text($language, 'post_title')) ?>
                                        <input type="text" name="translations[<?= site_escape($translationLanguage) ?>][title]" maxlength="255">
                                    </label>
                                    <label class="form-field">
                                        <?= site_escape(site_text($language, 'post_content')) ?>
                                        <textarea name="translations[<?= site_escape($translationLanguage) ?>][content]" rows="6" maxlength="400000"></textarea>
                                    </label>
                                </fieldset>
                            <?php endforeach; ?>
                            <label class="form-field">
                                <?= site_escape(site_text($language, 'post_images_upload')) ?>
                                <input type="file" name="image_uploads[]" accept="image/jpeg,image/png,image/webp" multiple>
                            </label>
                            <label class="form-field">
                                <?= site_escape(site_text($language, 'post_images_urls')) ?>
                                <textarea name="image_urls" rows="3" maxlength="40960"></textarea>
                            </label>
                            <p class="post-editor-help post-editor-wide"><?= site_escape($postImageHelp) ?></p>
                            <label class="form-field post-editor-wide">
                                <?= site_escape(site_text($language, 'post_video')) ?>
                                <input type="url" name="video" maxlength="2048">
                            </label>
                            <label class="form-field">
                                <?= site_escape(site_text($language, 'post_category')) ?>
                                <input type="text" name="category" maxlength="255">
                            </label>
                            <label class="form-field">
                                <?= site_escape(site_text($language, 'post_author')) ?>
                                <select name="author_id" required>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?= (int) $user['id'] ?>" <?= (int) $user['id'] === (int) $currentUser['id'] ? 'selected' : '' ?>><?= site_escape($user['username']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="form-field">
                                <?= site_escape(site_text($language, 'post_status')) ?>
                                <select name="status">
                                    <option value="draft" selected><?= site_escape(site_text($language, 'post_draft')) ?></option>
                                    <option value="published"><?= site_escape(site_text($language, 'post_published')) ?></option>
                                </select>
                            </label>
                            <button class="button-primary post-editor-wide" type="submit"><?= site_escape(site_text($language, 'post_add')) ?></button>
                        </form>

                        <?php if (!$posts): ?>
                            <p><?= site_escape(site_text($language, 'posts_none')) ?></p>
                        <?php else: ?>
                            <div class="table-scroll">
                                <table class="users-table post-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">ID</th>
                                            <th scope="col"><?= site_escape(site_text($language, 'post_title')) ?></th>
                                            <th scope="col"><?= site_escape(site_text($language, 'post_author')) ?></th>
                                            <th scope="col"><?= site_escape(site_text($language, 'post_category')) ?></th>
                                            <th scope="col"><?= site_escape(site_text($language, 'post_date')) ?></th>
                                            <th scope="col"><?= site_escape(site_text($language, 'post_status')) ?></th>
                                            <th scope="col"><?= site_escape(site_text($language, 'post_edit')) ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($posts as $post): ?>
                                            <?php
                                            $postId = (int) $post['id'];
                                            $postTitle = $postTranslations[$postId][$language]['title'] ?? '';
                                            if ($postTitle === '') {
                                                $postTitle = $postTranslations[$postId]['en']['title'] ?? '';
                                            }
                                            if ($postTitle === '') {
                                                $postTitle = $post['title'] ?? '';
                                            }
                                            if ($postTitle === '') {
                                                $postTitle = site_text($language, 'post_default_title');
                                            }
                                            ?>
                                            <tr>
                                                <td><?= (int) $post['id'] ?></td>
                                                <td>
                                                    <details class="post-editor-panel">
                                                        <summary><?= site_escape($postTitle) ?></summary>
                                                        <form class="post-editor" method="post" enctype="multipart/form-data" action="dashboard.php?lang=<?= site_escape($language) ?>">
                                                            <input type="hidden" name="csrf_token" value="<?= site_escape(mahaba_csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="save_post">
                                                            <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                                                            <?php foreach ($postLanguageOrder as $translationLanguage): ?>
                                                                <fieldset class="content-language-panel post-editor-wide">
                                                                    <legend>
                                                                        <?= site_escape(site_text($language, 'content_language')) ?>:
                                                                        <?= site_escape($postLanguages[$translationLanguage]) ?>
                                                                        <?php if ($translationLanguage === $language): ?>
                                                                            (<?= site_escape(site_text($language, 'post_selected_language')) ?>)
                                                                        <?php endif; ?>
                                                                    </legend>
                                                                    <label class="form-field">
                                                                        <?= site_escape(site_text($language, 'post_title')) ?>
                                                                        <input type="text" name="translations[<?= site_escape($translationLanguage) ?>][title]" value="<?= site_escape($postTranslations[$postId][$translationLanguage]['title']) ?>" maxlength="255">
                                                                    </label>
                                                                    <label class="form-field">
                                                                        <?= site_escape(site_text($language, 'post_content')) ?>
                                                                        <textarea name="translations[<?= site_escape($translationLanguage) ?>][content]" rows="6" maxlength="400000"><?= site_escape($postTranslations[$postId][$translationLanguage]['content']) ?></textarea>
                                                                    </label>
                                                                </fieldset>
                                                            <?php endforeach; ?>
                                                            <?php
                                                            $currentImages = $postImages[$postId] ?? [];
                                                            if ($currentImages === [] && !empty($post['image'])) {
                                                                $currentImages[] = ['id' => 0, 'image' => $post['image']];
                                                            }
                                                            ?>
                                                            <?php if ($currentImages !== []): ?>
                                                                <div class="post-editor-wide post-image-list">
                                                                    <?php foreach ($currentImages as $currentImage): ?>
                                                                        <?php $safeCurrentImage = mahaba_safe_image_url($currentImage['image']); ?>
                                                                        <?php if ($safeCurrentImage !== null): ?>
                                                                            <div class="post-image-item">
                                                                                <img class="post-current-image" src="<?= site_escape($safeCurrentImage) ?>" alt="<?= site_escape($postTitle) ?>">
                                                                                <?php if ((int) $currentImage['id'] > 0): ?>
                                                                                    <label class="post-remove-image">
                                                                                        <input type="checkbox" name="remove_image_ids[]" value="<?= (int) $currentImage['id'] ?>">
                                                                                        <?= site_escape(site_text($language, 'post_remove_image')) ?>
                                                                                    </label>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                            <label class="form-field">
                                                                <?= site_escape(site_text($language, 'post_images_upload')) ?>
                                                                <input type="file" name="image_uploads[]" accept="image/jpeg,image/png,image/webp" multiple>
                                                            </label>
                                                            <label class="form-field">
                                                                <?= site_escape(site_text($language, 'post_images_urls')) ?>
                                                                <textarea name="image_urls" rows="3" maxlength="40960"></textarea>
                                                            </label>
                                                            <p class="post-editor-help post-editor-wide"><?= site_escape($postImageHelp) ?></p>
                                                            <label class="form-field">
                                                                <?= site_escape(site_text($language, 'post_video')) ?>
                                                                <input type="url" name="video" value="<?= site_escape($post['video'] ?? '') ?>" maxlength="2048">
                                                            </label>
                                                            <label class="form-field">
                                                                <?= site_escape(site_text($language, 'post_category')) ?>
                                                                <input type="text" name="category" value="<?= site_escape($post['category'] ?? '') ?>" maxlength="255">
                                                            </label>
                                                            <label class="form-field">
                                                                <?= site_escape(site_text($language, 'post_author')) ?>
                                                                <select name="author_id" required>
                                                                    <?php if ($post['user_id'] === null): ?>
                                                                        <option value=""><?= site_escape(site_text($language, 'post_choose_author')) ?></option>
                                                                    <?php endif; ?>
                                                                    <?php foreach ($users as $user): ?>
                                                                        <option value="<?= (int) $user['id'] ?>" <?= (int) $user['id'] === (int) $post['user_id'] ? 'selected' : '' ?>><?= site_escape($user['username']) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </label>
                                                            <label class="form-field">
                                                                <?= site_escape(site_text($language, 'post_status')) ?>
                                                                <select name="status">
                                                                    <option value="draft" <?= $post['status'] === 'draft' ? 'selected' : '' ?>><?= site_escape(site_text($language, 'post_draft')) ?></option>
                                                                    <option value="published" <?= $post['status'] === 'published' ? 'selected' : '' ?>><?= site_escape(site_text($language, 'post_published')) ?></option>
                                                                </select>
                                                            </label>
                                                            <button class="button-primary" type="submit"><?= site_escape(site_text($language, 'post_save')) ?></button>
                                                        </form>
                                                    </details>
                                                </td>
                                                <td><?= site_escape($post['author'] ?? '') ?></td>
                                                <td><?= site_escape($post['category'] ?? '') ?></td>
                                                <td><?= site_escape($post['created_at']) ?></td>
                                                <td class="<?= $post['status'] === 'published' ? 'post-status-published' : 'post-status-draft' ?>">
                                                    <?= site_escape(site_text($language, $post['status'] === 'published' ? 'post_published' : 'post_draft')) ?>
                                                </td>
                                                <td>
                                                    <div class="post-action-forms">
                                                        <form method="post" action="dashboard.php?lang=<?= site_escape($language) ?>">
                                                            <input type="hidden" name="csrf_token" value="<?= site_escape(mahaba_csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="toggle_post_status">
                                                            <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                                                            <button class="button-secondary" type="submit"><?= site_escape(site_text($language, $post['status'] === 'published' ? 'post_unpublish' : 'post_publish')) ?></button>
                                                        </form>
                                                        <form method="post" action="dashboard.php?lang=<?= site_escape($language) ?>"
                                                            onsubmit="return confirm(<?= site_escape(json_encode(site_text($language, 'post_confirm_delete'))) ?>);">
                                                            <input type="hidden" name="csrf_token" value="<?= site_escape(mahaba_csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="delete_post">
                                                            <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                                                            <button class="button-danger" type="submit"><?= site_escape(site_text($language, 'post_delete')) ?></button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </details>

                    <details class="dashboard-create dashboard-panel" <?= $openDashboardPanel === 'create-user' ? 'open' : '' ?>>
                        <summary class="dashboard-panel-summary">
                            <span id="create-user-title" role="heading" aria-level="2"><?= site_escape(site_text($language, 'dashboard_create_user')) ?></span>
                        </summary>
                        <form class="login-form" method="post" action="dashboard.php?lang=<?= site_escape($language) ?>">
                            <input type="hidden" name="csrf_token" value="<?= site_escape(mahaba_csrf_token()) ?>">
                            <input type="hidden" name="action" value="create_user">
                            <label class="form-field">
                                <?= site_escape(site_text($language, 'dashboard_username')) ?>
                                <input type="text" name="username" maxlength="190" autocomplete="off" required>
                            </label>
                            <label class="form-field">
                                <?= site_escape(site_text($language, 'dashboard_email')) ?>
                                <input type="email" name="email" maxlength="254" autocomplete="email" required>
                            </label>
                            <label class="form-field">
                                <?= site_escape(site_text($language, 'dashboard_password')) ?>
                                <input type="password" name="password" minlength="12" maxlength="4096" autocomplete="new-password" required>
                            </label>
                            <label class="form-field">
                                <?= site_escape(site_text($language, 'confirm_password')) ?>
                                <input type="password" name="confirm_password" minlength="12" maxlength="4096" autocomplete="new-password" required>
                            </label>
                            <button class="button-primary" type="submit"><?= site_escape(site_text($language, 'dashboard_create_user')) ?></button>
                        </form>
                    </details>
                <?php endif; ?>

                <?php if ($currentUser['role'] === 'admin'): ?>
                    <details class="dashboard-users dashboard-panel" <?= $openDashboardPanel === 'users' ? 'open' : '' ?>>
                    <summary class="dashboard-panel-summary">
                        <span id="users-title" role="heading" aria-level="2"><?= site_escape(site_text($language, 'dashboard_users')) ?></span>
                    </summary>
                    <div class="dashboard-stats">
                        <div class="dashboard-stat">
                            <span><?= site_escape(site_text($language, 'dashboard_total_users')) ?></span>
                            <strong><?= $userCount ?></strong>
                        </div>
                        <div class="dashboard-stat">
                            <span><?= site_escape(site_text($language, 'dashboard_admin_count')) ?></span>
                            <strong><?= $adminCount ?></strong>
                        </div>
                    </div>
                    <div class="table-scroll">
                        <table class="users-table">
                            <thead>
                                <tr>
                                    <th scope="col"><?= site_escape(site_text($language, 'dashboard_username')) ?></th>
                                    <th scope="col"><?= site_escape(site_text($language, 'dashboard_email')) ?></th>
                                    <th scope="col"><?= site_escape(site_text($language, 'dashboard_role')) ?></th>
                                    <th scope="col"><?= site_escape(site_text($language, 'dashboard_created_at')) ?></th>
                                    <?php if ($currentUser['role'] === 'admin'): ?><th scope="col"><?= site_escape(site_text($language, 'dashboard_change_role')) ?></th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?= site_escape($user['username']) ?></td>
                                        <td>
                                            <form class="role-form" method="post" action="dashboard.php?lang=<?= site_escape($language) ?>">
                                                <input type="hidden" name="csrf_token" value="<?= site_escape(mahaba_csrf_token()) ?>">
                                                <input type="hidden" name="action" value="save_user_email">
                                                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                                <label class="visually-hidden" for="email-<?= (int) $user['id'] ?>"><?= site_escape(site_text($language, 'dashboard_email')) ?>: <?= site_escape($user['username']) ?></label>
                                                <input id="email-<?= (int) $user['id'] ?>" type="email" name="email" value="<?= site_escape($user['email'] ?? '') ?>" maxlength="254" autocomplete="email">
                                                <button class="button-secondary" type="submit"><?= site_escape(site_text($language, 'dashboard_email')) ?></button>
                                            </form>
                                        </td>
                                        <td><?= site_escape(site_text($language, $user['role'] === 'admin' ? 'dashboard_admin' : 'dashboard_member')) ?></td>
                                        <td><?= site_escape($user['created_at']) ?></td>
                                        <?php if ($currentUser['role'] === 'admin'): ?>
                                            <td>
                                                <div class="user-actions">
                                                    <form class="role-form" method="post" action="dashboard.php?lang=<?= site_escape($language) ?>">
                                                        <input type="hidden" name="csrf_token" value="<?= site_escape(mahaba_csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="change_role">
                                                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                                        <label class="visually-hidden" for="role-<?= (int) $user['id'] ?>"><?= site_escape(site_text($language, 'dashboard_role')) ?>: <?= site_escape($user['username']) ?></label>
                                                        <select id="role-<?= (int) $user['id'] ?>" name="role">
                                                            <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>><?= site_escape(site_text($language, 'dashboard_member')) ?></option>
                                                            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>><?= site_escape(site_text($language, 'dashboard_admin')) ?></option>
                                                        </select>
                                                        <button class="button-secondary" type="submit"><?= site_escape(site_text($language, 'dashboard_change_role')) ?></button>
                                                    </form>
                                                    <?php if ((int) $user['id'] !== (int) $currentUser['id']): ?>
                                                        <form method="post" action="dashboard.php?lang=<?= site_escape($language) ?>"
                                                            onsubmit="return confirm(<?= site_escape(json_encode(site_text($language, 'dashboard_confirm_delete'))) ?>);">
                                                            <input type="hidden" name="csrf_token" value="<?= site_escape(mahaba_csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="delete_user">
                                                            <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                                            <button class="button-danger" type="submit"><?= site_escape(site_text($language, 'dashboard_delete')) ?></button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
    <?php site_footer($language); ?>
</body>
</html>
