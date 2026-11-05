<?php
require __DIR__ . '/includes/site.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/home-content.php';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/footer.php';

mahaba_start_session();

$requestedLanguage = $_GET['lang'] ?? 'en';
$language = is_string($requestedLanguage) && isset($siteTranslations[$requestedLanguage])
    ? $requestedLanguage
    : 'en';
$rawPostId = $_GET['id'] ?? null;
$postId = is_string($rawPostId)
    ? filter_var($rawPostId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$post = null;
$postImages = [];
$postVideo = null;
$postFound = false;
$databaseError = false;

if ($postId !== false) {
    try {
        $pdo = mahaba_database();
        $statement = $pdo->prepare(
            "SELECT posts.id,
                    COALESCE(NULLIF(selected_translation.title, ''), NULLIF(english_translation.title, ''), posts.title) AS title,
                    COALESCE(NULLIF(selected_translation.content, ''), NULLIF(english_translation.content, ''), posts.content) AS content,
                    posts.image, posts.video, posts.created_at,
                    categories.name AS category
             FROM posts
             LEFT JOIN post_translations AS selected_translation
                ON selected_translation.post_id = posts.id AND selected_translation.language = :selected_language
             LEFT JOIN post_translations AS english_translation
                ON english_translation.post_id = posts.id AND english_translation.language = :english_language
             LEFT JOIN categories ON categories.id = posts.category_id
             WHERE posts.id = :post_id AND posts.status = 'published'
             LIMIT 1"
        );
        $statement->execute([
            'selected_language' => $language,
            'english_language' => 'en',
            'post_id' => $postId,
        ]);
        $post = $statement->fetch() ?: null;

        if ($post !== null) {
            $postFound = true;
            if ($postId === 1) {
                $homeContent = mahaba_load_home_content($pdo, $homeContentDefaults);
                $post['title'] = $homeContent[$language]['story_title'];
                $post['content'] = implode("\n\n", [
                    $homeContent[$language]['story_paragraph_1'],
                    $homeContent[$language]['story_paragraph_2'],
                ]);
                $postImages = [
                    $homeContent[$language]['event_image'],
                    $homeContent[$language]['activities_image'],
                    $homeContent[$language]['story_link_image'],
                ];
            } else {
                $imageStatement = $pdo->prepare(
                    'SELECT image FROM post_images WHERE post_id = :post_id ORDER BY sort_order, id'
                );
                $imageStatement->execute(['post_id' => $postId]);
                $postImages = array_column($imageStatement->fetchAll(), 'image');
                if ($postImages === [] && !empty($post['image'])) {
                    $postImages[] = $post['image'];
                }
            }
            $postImages = array_values(array_filter(
                array_map('mahaba_safe_image_url', $postImages),
                static fn(?string $image): bool => $image !== null
            ));
            $postVideo = !empty($post['video'])
                ? mahaba_youtube_embed_url($post['video'])
                : null;
            if ($post['title'] === null || $post['title'] === '') {
                $post['title'] = site_text($language, 'post_default_title');
            }
        }
    } catch (PDOException $exception) {
        error_log('Mahaba post page database error: ' . $exception->getMessage());
        http_response_code(503);
        $databaseError = true;
    }
} else {
    http_response_code(404);
}

if (!$postFound && !$databaseError && $postId !== false) {
    http_response_code(404);
}

$escape = static fn(string $value): string => site_escape($value);
?>
<!doctype html>
<html lang="<?= site_escape($language) ?>" dir="<?= $language === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#ffffff">
    <title><?= site_escape($post['title'] ?? site_text($language, $databaseError ? 'database_error' : 'post_not_found')) ?> - <?= site_escape(site_text($language, 'name')) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php site_header($language, 'home'); ?>
    <main class="page-content post-page">
        <a class="content-back-link" href="index.php?lang=<?= site_escape($language) ?>"><?= site_escape(site_text($language, 'post_back_home')) ?></a>
        <?php if ($postFound && $post !== null): ?>
            <article class="post-detail">
                <header class="post-detail-header">
                    <h1><?= $escape($post['title']) ?></h1>
                    <?php if (!empty($post['category'])): ?>
                        <p class="post-detail-category"><?= $escape($post['category']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($post['created_at'])): ?>
                        <time datetime="<?= $escape($post['created_at']) ?>"><?= $escape($post['created_at']) ?></time>
                    <?php endif; ?>
                </header>
                <?php if ($postVideo !== null): ?>
                    <div class="post-detail-video">
                        <iframe src="<?= $escape($postVideo) ?>" title="<?= $escape($post['title']) ?>" referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>
                    </div>
                <?php endif; ?>
                <?php if ($postImages !== []): ?>
                    <div class="post-detail-gallery">
                        <?php foreach ($postImages as $image): ?>
                            <img src="<?= $escape($image) ?>" alt="<?= $escape($post['title']) ?>">
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($post['content'])): ?>
                    <div class="post-detail-content"><?= nl2br($escape($post['content'])) ?></div>
                <?php endif; ?>
            </article>
        <?php else: ?>
            <section class="content-card" role="status">
                <h1><?= site_escape(site_text($language, $databaseError ? 'database_error' : 'post_not_found')) ?></h1>
            </section>
        <?php endif; ?>
    </main>
    <?php site_footer($language); ?>
</body>
</html>
