<?php

function mahaba_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        throw new RuntimeException('Unable to start the session.');
    }
}

function mahaba_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function mahaba_valid_csrf(mixed $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function mahaba_character_count(string $value): ?int
{
    $count = preg_match_all('/./us', $value);
    return $count === false ? null : $count;
}

function mahaba_database(): PDO
{
    $host = getenv('MAHABA_DB_HOST') ?: '127.0.0.1';
    $port = getenv('MAHABA_DB_PORT') ?: '3306';
    $username = getenv('MAHABA_DB_USER') ?: 'root';
    $password = getenv('MAHABA_DB_PASSWORD');
    if ($password === false) {
        $password = '';
    }

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname=mahaba;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

function mahaba_ensure_user_roles(PDO $pdo): void
{
    $lock = $pdo->query("SELECT GET_LOCK('mahaba_users_role_migration', 10)")->fetchColumn();
    if ((int) $lock !== 1) {
        throw new RuntimeException('Unable to acquire the user-role migration lock.');
    }

    try {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name'
        );
        $statement->execute(['table_name' => 'users', 'column_name' => 'role']);

        if ((int) $statement->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('admin', 'user') NOT NULL DEFAULT 'user' AFTER password_hash");
            $pdo->exec("UPDATE users SET role = 'admin' ORDER BY id ASC LIMIT 1");
        }
    } finally {
        $released = $pdo->query("SELECT RELEASE_LOCK('mahaba_users_role_migration')")->fetchColumn();
        if ((int) $released !== 1) {
            throw new RuntimeException('Unable to release the user-role migration lock.');
        }
    }
}

function mahaba_ensure_account_recovery(PDO $pdo): void
{
    $lock = $pdo->query("SELECT GET_LOCK('mahaba_account_recovery_migration', 10)")->fetchColumn();
    if ((int) $lock !== 1) {
        throw new RuntimeException('Unable to acquire the account-recovery migration lock.');
    }

    try {
        $column = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name'
        );
        $column->execute(['table_name' => 'users', 'column_name' => 'email']);
        if ((int) $column->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE users ADD COLUMN email VARCHAR(254) NULL AFTER username');
        }

        $index = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table_name AND index_name = :index_name'
        );
        $index->execute(['table_name' => 'users', 'index_name' => 'users_email_unique']);
        if ((int) $index->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE users ADD UNIQUE INDEX users_email_unique (email)');
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX password_reset_user_created (user_id, created_at),
                INDEX password_reset_expiration (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } finally {
        $released = $pdo->query("SELECT RELEASE_LOCK('mahaba_account_recovery_migration')")->fetchColumn();
        if ((int) $released !== 1) {
            throw new RuntimeException('Unable to release the account-recovery migration lock.');
        }
    }
}

function mahaba_ensure_content_tables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS site_content (
            language CHAR(2) NOT NULL,
            content_key VARCHAR(64) NOT NULL,
            content_value TEXT NOT NULL,
            PRIMARY KEY (language, content_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS site_activities (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            language CHAR(2) NOT NULL,
            title VARCHAR(190) NOT NULL,
            description TEXT NOT NULL,
            activity_date DATE NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX activities_language_date (language, activity_date),
            INDEX activities_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS categories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL UNIQUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS posts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NULL,
            content LONGTEXT NULL,
            image VARCHAR(2048) NULL,
            video VARCHAR(2048) NULL,
            user_id BIGINT UNSIGNED NULL,
            category_id BIGINT UNSIGNED NULL,
            status ENUM('published', 'draft') NOT NULL DEFAULT 'draft',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX posts_status_active_created (status, is_active, created_at),
            INDEX posts_user (user_id),
            INDEX posts_category (category_id),
            CONSTRAINT posts_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT posts_category_fk FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS post_translations (
            post_id BIGINT UNSIGNED NOT NULL,
            language CHAR(2) NOT NULL,
            title VARCHAR(255) NULL,
            content LONGTEXT NULL,
            PRIMARY KEY (post_id, language),
            CONSTRAINT post_translations_post_fk FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS post_images (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            post_id BIGINT UNSIGNED NOT NULL,
            image VARCHAR(2048) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            INDEX post_images_post_order (post_id, sort_order, id),
            CONSTRAINT post_images_post_fk FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'INSERT INTO post_images (post_id, image, sort_order)
         SELECT posts.id, posts.image, 0
         FROM posts
         WHERE posts.image IS NOT NULL AND posts.image <> \'\'
           AND NOT EXISTS (
               SELECT 1 FROM post_images WHERE post_images.post_id = posts.id
           )'
    );
    $pdo->exec(
        "INSERT IGNORE INTO post_translations (post_id, language, title, content)
         SELECT id, 'en', title, content FROM posts"
    );

    $lock = $pdo->query("SELECT GET_LOCK('mahaba_posts_active_migration', 10)")->fetchColumn();
    if ((int) $lock !== 1) {
        throw new RuntimeException('Unable to acquire the post-availability migration lock.');
    }

    try {
        $column = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name'
        );
        $column->execute(['table_name' => 'posts', 'column_name' => 'is_active']);
        if ((int) $column->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE posts ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER status');
        }

        $index = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table_name AND index_name = :index_name'
        );
        $index->execute(['table_name' => 'posts', 'index_name' => 'posts_status_active_created']);
        if ((int) $index->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE posts ADD INDEX posts_status_active_created (status, is_active, created_at)');
        }
    } finally {
        $released = $pdo->query("SELECT RELEASE_LOCK('mahaba_posts_active_migration')")->fetchColumn();
        if ((int) $released !== 1) {
            throw new RuntimeException('Unable to release the post-availability migration lock.');
        }
    }
}

function mahaba_load_home_content(PDO $pdo, array $defaults): array
{
    $content = $defaults;
    $statement = $pdo->query('SELECT content_key, language, content_value FROM site_content');

    foreach ($statement->fetchAll() as $row) {
        if (isset($content[$row['language']][$row['content_key']])) {
            if (in_array($row['content_key'], [
                'video_url',
                'story_link_url',
                'story_link_image',
                'logo_image',
                'event_image',
                'activities_image',
            ], true)) {
                $safeValue = mahaba_safe_content_value($row['content_key'], $row['content_value']);
                if ($safeValue === null) {
                    error_log('Mahaba ignored invalid stored media URL: ' . $row['content_key']);
                    continue;
                }
                $row['content_value'] = $safeValue;
            }
            $content[$row['language']][$row['content_key']] = $row['content_value'];
        }
    }

    return $content;
}

function mahaba_safe_image_url(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7F]/', $value)) {
        return null;
    }

    if (preg_match('/\Ahttps:\/\/[^\s\\\\]+\z/i', $value)) {
        $parts = parse_url($value);
        if (
            is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
        ) {
            return $value;
        }
    }

    if (
        preg_match('/\A[A-Za-z0-9._\/-]+\z/', $value)
        && !str_starts_with($value, '/')
        && !str_contains($value, '..')
    ) {
        return str_contains($value, '/') ? $value : 'assets/images/' . $value;
    }

    return null;
}

function mahaba_safe_external_url(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || strlen($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false) {
        return null;
    }

    $parts = parse_url($value);
    if (
        !is_array($parts)
        || strtolower($parts['scheme'] ?? '') !== 'https'
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
    ) {
        return null;
    }

    return $value;
}

function mahaba_safe_content_value(string $key, string $value): ?string
{
    if ($key === 'video_url') {
        return mahaba_youtube_embed_url($value);
    }

    if ($key === 'story_link_url') {
        return mahaba_safe_external_url($value);
    }

    if (in_array($key, ['story_link_image', 'logo_image', 'event_image', 'activities_image'], true)) {
        return mahaba_safe_image_url($value);
    }

    return null;
}

function mahaba_youtube_embed_url(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7F]/', $value)) {
        return null;
    }

    $parts = parse_url($value);
    if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
        return null;
    }

    $host = strtolower($parts['host'] ?? '');
    $path = $parts['path'] ?? '';
    $videoId = null;

    if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'], true)) {
        if (preg_match('#\A/embed/([A-Za-z0-9_-]{11})\z#', $path, $matches)) {
            $videoId = $matches[1];
        } elseif (in_array($path, ['/watch', '/'], true)) {
            parse_str($parts['query'] ?? '', $query);
            $candidate = $query['v'] ?? null;
            if (is_string($candidate) && preg_match('/\A[A-Za-z0-9_-]{11}\z/', $candidate)) {
                $videoId = $candidate;
            }
        }
    } elseif (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
        $candidate = trim($path, '/');
        if (preg_match('/\A[A-Za-z0-9_-]{11}\z/', $candidate)) {
            $videoId = $candidate;
        }
    }

    return $videoId === null
        ? null
        : 'https://www.youtube-nocookie.com/embed/' . $videoId;
}

function mahaba_ini_size_bytes(string $value): ?int
{
    $value = trim($value);
    if (!preg_match('/\A(\d+(?:\.\d+)?)\s*([KMG]?)\z/i', $value, $matches)) {
        return null;
    }

    $multipliers = [
        '' => 1,
        'K' => 1024,
        'M' => 1024 * 1024,
        'G' => 1024 * 1024 * 1024,
    ];
    $bytes = (float) $matches[1] * $multipliers[strtoupper($matches[2])];
    return $bytes > PHP_INT_MAX ? PHP_INT_MAX : (int) $bytes;
}

function mahaba_post_image_max_bytes(): int
{
    $maximum = 8 * 1024 * 1024;
    $uploadLimit = ini_get('upload_max_filesize');
    $uploadBytes = is_string($uploadLimit) ? mahaba_ini_size_bytes($uploadLimit) : null;
    if ($uploadBytes !== null && $uploadBytes > 0) {
        $maximum = min($maximum, $uploadBytes);
    }

    $postLimit = ini_get('post_max_size');
    $postBytes = is_string($postLimit) ? mahaba_ini_size_bytes($postLimit) : null;
    if ($postBytes !== null && $postBytes > 0) {
        $maximum = min($maximum, max(1, $postBytes - 64 * 1024));
    }

    return $maximum;
}

function mahaba_post_request_exceeds_limit(): bool
{
    $postLimit = ini_get('post_max_size');
    $postBytes = is_string($postLimit) ? mahaba_ini_size_bytes($postLimit) : null;
    $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;

    return $postBytes !== null
        && $postBytes > 0
        && is_string($contentLength)
        && ctype_digit($contentLength)
        && (int) $contentLength > $postBytes;
}

function mahaba_save_post_image(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The post image upload did not complete successfully.');
    }
    if (!isset($file['tmp_name'], $file['size']) || !is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('The uploaded post image is invalid.');
    }
    if (!is_int($file['size']) || $file['size'] < 1 || $file['size'] > mahaba_post_image_max_bytes()) {
        throw new RuntimeException('The post image exceeds the configured upload limit.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!is_string($mime) || !isset($extensions[$mime])) {
        throw new RuntimeException('Use a JPG, PNG, or WebP image for the post.');
    }

    $imageInfo = getimagesize($file['tmp_name']);
    if ($imageInfo === false || ($imageInfo['mime'] ?? null) !== $mime) {
        throw new RuntimeException('The uploaded file is not a valid image.');
    }

    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'posts';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('The post image upload directory could not be created.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    $destination = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('The post image could not be saved.');
    }

    return 'uploads/posts/' . $filename;
}

function mahaba_delete_post_image(?string $image): void
{
    if ($image === null || !preg_match('#\Auploads/posts/[a-f0-9]{32}\.(?:jpg|png|webp)\z#', $image)) {
        return;
    }

    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $image);
    if (is_file($path) && !unlink($path)) {
        throw new RuntimeException('The post image could not be removed.');
    }
}

function mahaba_is_local_request(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}
