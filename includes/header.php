<?php

function site_header(string $language, string $activePage): void
{
    $name = site_text($language, 'name');
    $slogan = site_text($language, 'slogan');
    $logoImage = 'assets/images/logo.jpeg';
    try {
        $statement = mahaba_database()->prepare(
            'SELECT content_value FROM site_content WHERE language = :language AND content_key = :content_key LIMIT 1'
        );
        $statement->execute(['language' => $language, 'content_key' => 'logo_image']);
        $savedLogo = $statement->fetchColumn();
        if (is_string($savedLogo)) {
            $logoImage = mahaba_safe_image_url($savedLogo) ?? $logoImage;
        }
    } catch (PDOException $exception) {
        error_log('Mahaba header logo database error: ' . $exception->getMessage());
    }
    $pages = [
        'home' => ['index.php', 'home'],
        'activities' => ['activities.php', 'activities'],
        'contact' => ['contact.php', 'contact'],
    ];
    $isLoggedIn = !empty($_SESSION['user_id']);
    $pages[$isLoggedIn ? 'dashboard' : 'login'] = [
        $isLoggedIn ? 'dashboard.php' : 'login.php',
        $isLoggedIn ? 'dashboard' : 'login',
    ];
    ?>
    <header class="site-header">
        <div class="header-inner">
            <a class="brand" href="index.php?lang=<?= site_escape($language) ?>" aria-label="<?= site_escape($name) ?>">
                <img src="<?= site_escape($logoImage) ?>" alt="">
                <span class="brand-copy">
                    <span class="brand-name"><?= site_escape($name) ?></span>
                    <span class="brand-slogan"><?= site_escape($slogan) ?></span>
                </span>
            </a>
            <nav class="primary-nav" aria-label="<?= site_escape($name) ?>">
                <?php foreach ($pages as $page => [$url, $label]): ?>
                    <a href="<?= site_escape($url) ?>?lang=<?= site_escape($language) ?>"
                        <?= $page === $activePage ? 'aria-current="page"' : '' ?>><?= site_escape(site_text($language, $label)) ?></a>
                <?php endforeach; ?>
                <?php if ($isLoggedIn): ?>
                    <form class="nav-logout" method="post" action="logout.php?lang=<?= site_escape($language) ?>">
                        <input type="hidden" name="csrf_token" value="<?= site_escape(mahaba_csrf_token()) ?>">
                        <button type="submit"><?= site_escape(site_text($language, 'logout')) ?></button>
                    </form>
                <?php endif; ?>
            </nav>
            <nav class="language-switch" aria-label="<?= site_escape(site_text($language, 'choose_language')) ?>">
                <?php foreach (['ar' => 'AR', 'en' => 'EN', 'tr' => 'TR', 'fr' => 'FR'] as $code => $label): ?>
                    <?php if ($code !== 'ar'): ?><span aria-hidden="true">|</span><?php endif; ?>
                    <a href="?lang=<?= site_escape($code) ?>" lang="<?= site_escape($code) ?>"
                        <?= $code === $language ? 'aria-current="page"' : '' ?>><?= site_escape($label) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
    </header>
    <?php
}
