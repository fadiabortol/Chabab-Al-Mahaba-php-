<?php
require __DIR__ . '/includes/site.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/home-content.php';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/footer.php';

mahaba_start_session();

$translations = [
    'fr' => [
        'name' => "Jeunes d'El Mahaba",
        'slogan' => 'Amour - Solidarité - Développement',
        'video_title' => $homeContentDefaults['fr']['video_title'],
        'story_title' => $homeContentDefaults['fr']['story_title'],
        'story_paragraphs' => [
            $homeContentDefaults['fr']['story_paragraph_1'],
            $homeContentDefaults['fr']['story_paragraph_2'],
        ],
        'contact_label' => 'Contact',
        'nav_home' => 'Accueil',
        'nav_contact' => 'Contact',
        'nav_login' => 'Connexion',
        'nav_dashboard' => 'Tableau de bord',
        'registration_label' => 'N° d’enregistrement',
        'copyright' => 'Tous droits réservés.',
        'logo_alt' => "Logo de l'association Jeunes d'El Mahaba",
        'event_alt' => 'Événement communautaire réunissant des participants',
        'activities_alt' => 'Des jeunes participant à une activité de l’association',
        'link_image_alt' => 'Voir le site de Suntec Liban',
        'home' => "Accueil de l'association Jeunes d'El Mahaba",
        'choose_language' => 'Choisir la langue',
        'gallery' => "Activités de l'association",
    ],
    'tr' => [
        'name' => 'El Mahaba Gençlik Derneği',
        'slogan' => 'Sevgi - Dayanışma - Kalkınma',
        'video_title' => $homeContentDefaults['tr']['video_title'],
        'story_title' => $homeContentDefaults['tr']['story_title'],
        'story_paragraphs' => [
            $homeContentDefaults['tr']['story_paragraph_1'],
            $homeContentDefaults['tr']['story_paragraph_2'],
        ],
        'contact_label' => 'İletişim',
        'nav_home' => 'Ana Sayfa',
        'nav_contact' => 'İletişim',
        'nav_login' => 'Giriş',
        'nav_dashboard' => 'Kontrol Paneli',
        'registration_label' => 'Kayıt No.',
        'copyright' => 'Tüm hakları saklıdır.',
        'logo_alt' => 'El Mahaba Gençlik Derneği logosu',
        'event_alt' => 'İnsanları bir araya getiren topluluk etkinliği',
        'activities_alt' => 'Bir dernek etkinliğine katılan gençler',
        'link_image_alt' => 'Suntec Lübnan web sitesini ziyaret edin',
        'home' => 'El Mahaba Gençlik Derneği ana sayfası',
        'choose_language' => 'Dil seçin',
        'gallery' => 'Dernek etkinlikleri',
    ],
    'ar' => [
        'name' => 'شباب المحبة',
        'slogan' => 'محبة - تضامن - تنمية',
        'video_title' => $homeContentDefaults['ar']['video_title'],
        'story_title' => $homeContentDefaults['ar']['story_title'],
        'story_paragraphs' => [
            $homeContentDefaults['ar']['story_paragraph_1'],
            $homeContentDefaults['ar']['story_paragraph_2'],
        ],
        'contact_label' => 'للتواصل',
        'nav_home' => 'الرئيسية',
        'nav_contact' => 'تواصل معنا',
        'nav_login' => 'تسجيل الدخول',
        'nav_dashboard' => 'لوحة التحكم',
        'registration_label' => 'رقم التسجيل',
        'copyright' => 'جميع الحقوق محفوظة.',
        'logo_alt' => 'شعار جمعية شباب المحبة',
        'event_alt' => 'فعالية مجتمعية تجمع المشاركين',
        'activities_alt' => 'شباب يشاركون في نشاط للجمعية',
        'link_image_alt' => 'زيارة موقع Suntec لبنان',
        'home' => 'الصفحة الرئيسية لجمعية شباب المحبة',
        'choose_language' => 'اختر اللغة',
        'gallery' => 'أنشطة الجمعية',
    ],
    'en' => [
        'name' => 'Shabab Al-Mahaba',
        'slogan' => 'Love - Solidarity - Development',
        'video_title' => $homeContentDefaults['en']['video_title'],
        'story_title' => $homeContentDefaults['en']['story_title'],
        'story_paragraphs' => [
            $homeContentDefaults['en']['story_paragraph_1'],
            $homeContentDefaults['en']['story_paragraph_2'],
        ],
        'contact_label' => 'Contact',
        'nav_home' => 'Home',
        'nav_contact' => 'Contact',
        'nav_login' => 'Login',
        'nav_dashboard' => 'Dashboard',
        'registration_label' => 'Registration No.',
        'copyright' => 'All rights reserved.',
        'logo_alt' => 'Youth of Love Association logo',
        'event_alt' => 'Community event bringing people together',
        'activities_alt' => 'Young people taking part in an association activity',
        'link_image_alt' => 'Visit the Suntec Lebanon website',
        'home' => 'Shabab Al-Mahaba home',
        'choose_language' => 'Choose language',
        'gallery' => 'Association activities',
    ],
];

$requestedLanguage = $_GET['lang'] ?? 'en';
$language = is_string($requestedLanguage) && isset($translations[$requestedLanguage])
    ? $requestedLanguage
    : 'en';
$text = $translations[$language];
$direction = $language === 'ar' ? 'rtl' : 'ltr';
$contentError = false;
$homePosts = [];
$storyPost = null;
try {
    $pdo = mahaba_database();
    mahaba_ensure_content_tables($pdo);
    $homeContent = mahaba_load_home_content($pdo, $homeContentDefaults);
    $text = array_merge($text, $homeContent[$language]);
    $text['story_paragraphs'] = [
        $homeContent[$language]['story_paragraph_1'],
        $homeContent[$language]['story_paragraph_2'],
    ];
    $storyStatement = $pdo->prepare(
        'SELECT posts.id, posts.title, posts.content, posts.image, posts.video, posts.status,
                post_translations.title AS translated_title,
                post_translations.content AS translated_content
         FROM posts
         LEFT JOIN post_translations
            ON post_translations.post_id = posts.id AND post_translations.language = :language
         WHERE posts.id = 1
         LIMIT 1'
    );
    $storyStatement->execute(['language' => $language]);
    $storyPost = $storyStatement->fetch() ?: null;
    $homePostsStatement = $pdo->prepare(
        "SELECT posts.id,
                COALESCE(NULLIF(selected_translation.title, ''), NULLIF(english_translation.title, ''), posts.title) AS title,
                COALESCE(NULLIF(selected_translation.content, ''), NULLIF(english_translation.content, ''), posts.content) AS content,
                posts.image, posts.video, posts.status
         FROM posts
         LEFT JOIN post_translations AS selected_translation
            ON selected_translation.post_id = posts.id AND selected_translation.language = :selected_language
         LEFT JOIN post_translations AS english_translation
            ON english_translation.post_id = posts.id AND english_translation.language = :english_language
         WHERE posts.id <> 1 AND posts.status = 'published'
         ORDER BY posts.created_at DESC, posts.id DESC"
    );
    $homePostsStatement->execute([
        'selected_language' => $language,
        'english_language' => 'en',
    ]);
    $homePosts = $homePostsStatement->fetchAll();
    $postImages = [];
    $imageRows = $pdo->query(
        'SELECT post_id, image FROM post_images ORDER BY post_id, sort_order, id'
    )->fetchAll();
    foreach ($imageRows as $imageRow) {
        $postImages[(int) $imageRow['post_id']][] = $imageRow['image'];
    }

    if ($storyPost !== null) {
        $englishStoryContent = implode("\n\n", [
            $homeContent['en']['story_paragraph_1'],
            $homeContent['en']['story_paragraph_2'],
        ]);
        $localizedStoryTitle = $storyPost['translated_title'];
        if (
            $localizedStoryTitle !== null
            && $localizedStoryTitle !== $homeContent[$language]['story_title']
        ) {
            $text['story_title'] = $localizedStoryTitle !== ''
                ? $localizedStoryTitle
                : site_text($language, 'post_default_title');
        }
        $localizedHomeStoryContent = implode("\n\n", [
            $homeContent[$language]['story_paragraph_1'],
            $homeContent[$language]['story_paragraph_2'],
        ]);
        if (
            $storyPost['translated_content'] !== null
            && trim($storyPost['translated_content']) !== trim($localizedHomeStoryContent)
        ) {
            $text['story_paragraphs'] = $storyPost['translated_content'] === ''
                ? []
                : [$storyPost['translated_content']];
        } elseif (
            $storyPost['translated_content'] === null
            && trim((string) $storyPost['content']) !== trim($englishStoryContent)
        ) {
            $text['story_paragraphs'] = $storyPost['content'] === null || $storyPost['content'] === ''
                ? []
                : [$storyPost['content']];
        }

        $englishStoryImage = $homeContent['en']['event_image'];
        if (
            $storyPost['image'] !== null
            && $storyPost['image'] !== ''
            && $storyPost['image'] !== $englishStoryImage
        ) {
            $storyImage = mahaba_safe_image_url($storyPost['image']);
            if ($storyImage !== null) {
                $text['event_image'] = $storyImage;
            }
        } elseif ($storyPost['image'] === null || $storyPost['image'] === '') {
            $text['event_image'] = '';
        }
    }
} catch (PDOException $exception) {
    error_log('Mahaba homepage content database error: ' . $exception->getMessage());
    $contentError = true;
}
$escape = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
?>
<!doctype html>
<html lang="<?= $escape($language) ?>" dir="<?= $escape($direction) ?>">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#ffffff">
    <title><?= $escape($text['name']) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <?php site_header($language, 'home'); ?>

    <main class="page-content">
        <?php if ($contentError): ?>
            <p class="form-message" role="status"><?= $escape(site_text($language, 'content_unavailable')) ?></p>
        <?php endif; ?>
        <section class="video-section" aria-labelledby="video-title">
            <div class="video-frame">
                <iframe
                    src="<?= $escape($text['video_url']) ?>"
                    title="<?= $escape($text['name'] . ' — ' . $text['video_title']) ?>"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                    referrerpolicy="strict-origin-when-cross-origin"
                    allowfullscreen>
                </iframe>
            </div>
            <h1 id="video-title"><?= $escape($text['video_title']) ?></h1>
        </section>

        <?php if ($storyPost === null || $storyPost['status'] === 'published'): ?>
        <section class="story-section" aria-labelledby="story-title" <?= $storyPost !== null && $storyPost['status'] === 'published' ? 'data-post-url="post.php?id=1&amp;lang=' . site_escape($language) . '" role="link" tabindex="0"' : '' ?>>
            <div class="story-copy">
                <h2 id="story-title"><?= $escape($text['story_title']) ?></h2>
                <?php foreach ($text['story_paragraphs'] as $paragraph): ?>
                    <p><?= nl2br($escape($paragraph)) ?></p>
                <?php endforeach; ?>
                <div class="story-link-wrapper">
                    <a class="story-link-image"
                        href="<?= $escape($text['story_link_url']) ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label="<?= $escape($text['link_image_alt']) ?>">
                        <img src="<?= $escape($text['story_link_image']) ?>" alt="<?= $escape($text['link_image_alt']) ?>" loading="lazy">
                    </a>
                </div>
                <?php if ($storyPost !== null && !empty($storyPost['video'])): ?>
                    <?php $storyVideo = mahaba_youtube_embed_url($storyPost['video']); ?>
                    <?php if ($storyVideo !== null): ?>
                        <div class="story-video">
                            <iframe src="<?= $escape($storyVideo) ?>" title="<?= $escape($text['story_title']) ?>" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="image-gallery" aria-label="<?= $escape($text['gallery']) ?>">
                <?php if ($text['event_image'] !== ''): ?>
                    <img src="<?= $escape($text['event_image']) ?>" alt="<?= $escape($text['event_alt']) ?>" loading="lazy">
                <?php endif; ?>
                <img src="<?= $escape($text['activities_image']) ?>" alt="<?= $escape($text['activities_alt']) ?>" loading="lazy">
            </div>
        </section>
        <?php endif; ?>

        <?php foreach ($homePosts as $post): ?>
            <?php
            $postTitle = ($post['title'] ?? '') !== ''
                ? $post['title']
                : site_text($language, 'post_default_title');
            $postImageValues = $postImages[(int) $post['id']] ?? [];
            if ($postImageValues === [] && !empty($post['image'])) {
                $postImageValues[] = $post['image'];
            }
            $postImageValues = array_values(array_filter(
                array_map('mahaba_safe_image_url', $postImageValues),
                static fn(?string $image): bool => $image !== null
            ));
            $postVideo = !empty($post['video']) ? mahaba_youtube_embed_url($post['video']) : null;
            ?>
            <section class="story-section home-post-section" data-post-url="post.php?id=<?= (int) $post['id'] ?>&amp;lang=<?= site_escape($language) ?>" role="link" tabindex="0" aria-label="<?= $escape($postTitle) ?>">
                <div class="story-copy">
                    <h2><?= $escape($postTitle) ?></h2>
                    <?php if (!empty($post['content'])): ?>
                        <p><?= nl2br($escape($post['content'])) ?></p>
                    <?php endif; ?>
                    <?php if ($postVideo !== null): ?>
                        <div class="story-video">
                            <iframe src="<?= $escape($postVideo) ?>" title="<?= $escape($postTitle) ?>" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($postImageValues !== []): ?>
                    <div class="image-gallery">
                        <?php foreach ($postImageValues as $postImage): ?>
                            <img src="<?= $escape($postImage) ?>" alt="<?= $escape($postTitle) ?>" loading="lazy">
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </main>

    <?php site_footer($language); ?>

    <script src="assets/js/script.js" defer></script>
</body>

</html>