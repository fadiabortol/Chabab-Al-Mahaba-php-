<?php

function site_footer(string $language): void
{
    $email = 'info@shababalMahaba.org';
    $registrationNumber = '2012/2186';
    $facebookUrl = 'https://www.facebook.com/chabab.almahabah/';
    $instagramUrl = 'https://www.instagram.com/chababalmahabah/';
    try {
        $statement = mahaba_database()->prepare(
            'SELECT content_key, content_value FROM site_content
             WHERE language = :language
             AND content_key IN (:email_key, :registration_key, :facebook_key, :instagram_key)'
        );
        $statement->execute([
            'language' => $language,
            'email_key' => 'contact_email_address',
            'registration_key' => 'contact_registration_number',
            'facebook_key' => 'contact_facebook_url',
            'instagram_key' => 'contact_instagram_url',
        ]);
        foreach ($statement->fetchAll() as $row) {
            if ($row['content_key'] === 'contact_email_address' && filter_var($row['content_value'], FILTER_VALIDATE_EMAIL) !== false) {
                $email = $row['content_value'];
            } elseif ($row['content_key'] === 'contact_registration_number') {
                $registrationNumber = $row['content_value'];
            } elseif ($row['content_key'] === 'contact_facebook_url' && $row['content_value'] === '') {
                $facebookUrl = '';
            } elseif ($row['content_key'] === 'contact_instagram_url' && $row['content_value'] === '') {
                $instagramUrl = '';
            } elseif ($row['content_key'] === 'contact_facebook_url' && function_exists('mahaba_safe_external_url')) {
                $facebookUrl = mahaba_safe_external_url($row['content_value']) ?? $facebookUrl;
            } elseif ($row['content_key'] === 'contact_instagram_url' && function_exists('mahaba_safe_external_url')) {
                $instagramUrl = mahaba_safe_external_url($row['content_value']) ?? $instagramUrl;
            }
        }
    } catch (PDOException $exception) {
        error_log('Mahaba footer contact email database error: ' . $exception->getMessage());
    }
    ?>
    <footer class="site-footer">
        <div class="footer-inner">
            <p class="footer-item"><?= site_escape(site_text($language, 'email_label')) ?>:
                <a href="mailto:<?= site_escape($email) ?>"><?= site_escape($email) ?></a>
            </p>
            <div class="footer-contact-group">
                <p class="footer-item"><?= site_escape(site_text($language, 'registration_label')) ?>: <span><?= site_escape($registrationNumber) ?></span></p>
                <?php if ($facebookUrl !== '' || $instagramUrl !== ''): ?>
                    <nav class="footer-socials" aria-label="Social media">
                        <?php if ($facebookUrl !== ''): ?>
                            <a href="<?= site_escape($facebookUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Facebook">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <path d="M13.5 21v-8.2h2.8l.4-3.2h-3.2v-2c0-.9.3-1.6 1.6-1.6h1.7V3.1c-.3 0-1.3-.1-2.5-.1-2.5 0-4.2 1.5-4.2 4.3v2.3H7.3v3.2h2.8V21h3.4Z"/>
                                </svg>
                            </a>
                        <?php endif; ?>
                        <?php if ($instagramUrl !== ''): ?>
                            <a href="<?= site_escape($instagramUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <rect x="3" y="3" width="18" height="18" rx="5"/>
                                    <circle cx="12" cy="12" r="4"/>
                                    <circle class="footer-social-dot" cx="17.5" cy="6.8" r="1"/>
                                </svg>
                            </a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </div>
            <p class="footer-item">© <?= date('Y') ?> <?= site_escape(site_text($language, 'name')) ?>. <?= site_escape(site_text($language, 'copyright')) ?></p>
        </div>
    </footer>
    <?php
}
