<?php
require __DIR__ . '/includes/site.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/contact-content.php';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/footer.php';

mahaba_start_session();

$requestedLanguage = $_GET['lang'] ?? 'en';
$language = is_string($requestedLanguage) && isset($siteTranslations[$requestedLanguage])
    ? $requestedLanguage
    : 'en';
$content = $contactContentDefaults[$language];
$message = '';
$messageType = '';
$firstName = '';
$lastName = '';
$email = '';
$phone = '';
$city = '';
$phoneCountry = 'LB';
$phoneCountries = [
    'LB' => ['🇱🇧 Lebanon', '+961'],
    'US' => ['🇺🇸 United States', '+1'],
    'CA' => ['🇨🇦 Canada', '+1'],
    'GB' => ['🇬🇧 United Kingdom', '+44'],
    'FR' => ['🇫🇷 France', '+33'],
    'TR' => ['🇹🇷 Türkiye', '+90'],
    'SY' => ['🇸🇾 Syria', '+963'],
    'JO' => ['🇯🇴 Jordan', '+962'],
    'PS' => ['🇵🇸 Palestine', '+970'],
    'AE' => ['🇦🇪 United Arab Emirates', '+971'],
    'SA' => ['🇸🇦 Saudi Arabia', '+966'],
    'QA' => ['🇶🇦 Qatar', '+974'],
    'KW' => ['🇰🇼 Kuwait', '+965'],
    'BH' => ['🇧🇭 Bahrain', '+973'],
    'OM' => ['🇴🇲 Oman', '+968'],
    'EG' => ['🇪🇬 Egypt', '+20'],
    'IQ' => ['🇮🇶 Iraq', '+964'],
    'MA' => ['🇲🇦 Morocco', '+212'],
    'TN' => ['🇹🇳 Tunisia', '+216'],
    'DZ' => ['🇩🇿 Algeria', '+213'],
    'DE' => ['🇩🇪 Germany', '+49'],
    'IT' => ['🇮🇹 Italy', '+39'],
    'ES' => ['🇪🇸 Spain', '+34'],
    'NL' => ['🇳🇱 Netherlands', '+31'],
    'BE' => ['🇧🇪 Belgium', '+32'],
    'CH' => ['🇨🇭 Switzerland', '+41'],
    'SE' => ['🇸🇪 Sweden', '+46'],
    'NO' => ['🇳🇴 Norway', '+47'],
    'DK' => ['🇩🇰 Denmark', '+45'],
    'GR' => ['🇬🇷 Greece', '+30'],
    'CY' => ['🇨🇾 Cyprus', '+357'],
    'IE' => ['🇮🇪 Ireland', '+353'],
    'AU' => ['🇦🇺 Australia', '+61'],
    'NZ' => ['🇳🇿 New Zealand', '+64'],
    'BR' => ['🇧🇷 Brazil', '+55'],
    'MX' => ['🇲🇽 Mexico', '+52'],
    'IN' => ['🇮🇳 India', '+91'],
    'PK' => ['🇵🇰 Pakistan', '+92'],
    'BD' => ['🇧🇩 Bangladesh', '+880'],
    'JP' => ['🇯🇵 Japan', '+81'],
    'CN' => ['🇨🇳 China', '+86'],
    'KR' => ['🇰🇷 South Korea', '+82'],
    'NG' => ['🇳🇬 Nigeria', '+234'],
    'ZA' => ['🇿🇦 South Africa', '+27'],
    'RU' => ['🇷🇺 Russia', '+7'],
];
$contactMessage = '';

try {
    $pdo = mahaba_database();
    mahaba_ensure_content_tables($pdo);
    $contactContent = mahaba_load_contact_content($pdo, $contactContentDefaults);
    $content = $contactContent[$language];
} catch (PDOException $exception) {
    error_log('Mahaba contact content database error: ' . $exception->getMessage());
    $message = site_text($language, 'database_error');
    $messageType = 'error';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!mahaba_valid_csrf($_POST['csrf_token'] ?? null)) {
        $message = site_text($language, 'csrf_error');
        $messageType = 'error';
    } elseif (trim(is_string($_POST['website'] ?? null) ? $_POST['website'] : '') !== '') {
        $message = site_text($language, 'contact_send_success');
        $messageType = 'success';
    } else {
        $firstName = trim(is_string($_POST['first_name'] ?? null) ? $_POST['first_name'] : '');
        $lastName = trim(is_string($_POST['last_name'] ?? null) ? $_POST['last_name'] : '');
        $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
        $phone = trim(is_string($_POST['phone'] ?? null) ? $_POST['phone'] : '');
        $city = trim(is_string($_POST['city'] ?? null) ? $_POST['city'] : '');
        $submittedPhoneCountry = $_POST['phone_country'] ?? null;
        $phoneCountry = is_string($submittedPhoneCountry) ? $submittedPhoneCountry : '';
        $contactMessage = trim(is_string($_POST['message'] ?? null) ? $_POST['message'] : '');
        $firstNameLength = mahaba_character_count($firstName);
        $lastNameLength = mahaba_character_count($lastName);
        $messageLength = mahaba_character_count($contactMessage);
        $phoneLength = mahaba_character_count($phone);
        $phoneDigits = preg_replace('/\D/', '', $phone);
        $cityLength = mahaba_character_count($city);
        $phoneCountryValid = isset($phoneCountries[$phoneCountry]);
        $validPhone = $phoneCountryValid && (
            $phone === ''
            || (
                $phoneLength !== null
                && $phoneLength <= 25
                && preg_match('/\A[0-9\s().-]+\z/', $phone)
                && strlen($phoneDigits) >= 4
                && strlen($phoneDigits) + strlen(ltrim($phoneCountries[$phoneCountry][1], '+')) <= 15
            )
        );
        $validMessage = $messageLength !== null
            && $messageLength <= 5000
            && strlen($contactMessage) <= 20000
            && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $contactMessage);

        if (
            $firstNameLength === null
            || $firstNameLength < 1
            || $firstNameLength > 100
            || $lastNameLength === null
            || $lastNameLength < 1
            || $lastNameLength > 100
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || strlen($email) > 254
            || !$validPhone
            || ($city !== '' && (
                $cityLength === null
                || $cityLength > 190
                || strlen($city) > 760
                || preg_match('/[\x00-\x1F\x7F]/', $city)
            ))
            || !$validMessage
        ) {
            $message = site_text($language, 'contact_form_invalid');
            $messageType = 'error';
        } else {
            $recipient = $content['email_address'];
            $subject = 'New contact message - ' . site_text($language, 'name');
            $body = "Name: {$firstName} {$lastName}\nEmail: {$email}\n";
            $emailPhone = $phone;
            if ($phone !== '') {
                $emailPhone = $phoneCountries[$phoneCountry][1] . ' ' . $phone;
                $body .= "Phone: {$emailPhone}\n";
            }
            if ($city !== '') {
                $body .= "City: {$city}\n";
            }
            $body .= "\nMessage:\n{$contactMessage}\n";
            $headers = [
                'Reply-To: ' . $email,
                'Content-Type: text/plain; charset=UTF-8',
            ];

            if (mail($recipient, $subject, $body, implode("\r\n", $headers))) {
                $message = site_text($language, 'contact_send_success');
                $messageType = 'success';
                $firstName = '';
                $lastName = '';
                $email = '';
                $phone = '';
                $city = '';
                $phoneCountry = 'LB';
                $contactMessage = '';
            } else {
                error_log('Mahaba contact email could not be sent.');
                $message = site_text($language, 'contact_send_error');
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
    <title><?= site_escape($content['hero_title']) ?> - <?= site_escape(site_text($language, 'name')) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php site_header($language, 'contact'); ?>
    <main class="page-content contact-page">
        <section class="contact-heading" aria-labelledby="contact-title">
            <h1 id="contact-title"><?= site_escape($content['hero_title']) ?></h1>
            <p><?= site_escape($content['hero_intro']) ?></p>
        </section>
        <?php if ($message !== ''): ?>
            <p class="form-message <?= $messageType === 'success' ? 'success' : '' ?>" role="status"><?= site_escape($message) ?></p>
        <?php endif; ?>
        <div class="contact-layout">
            <form class="contact-form" method="post" action="contact.php?lang=<?= site_escape($language) ?>">
                <input type="hidden" name="csrf_token" value="<?= site_escape(mahaba_csrf_token()) ?>">
                <label class="form-field">
                    <?= site_escape($content['first_name_label']) ?>
                    <input type="text" name="first_name" maxlength="100" autocomplete="given-name" placeholder="<?= site_escape($content['first_name_placeholder']) ?>" value="<?= site_escape($firstName) ?>" required>
                </label>
                <label class="form-field">
                    <?= site_escape($content['last_name_label']) ?>
                    <input type="text" name="last_name" maxlength="100" autocomplete="family-name" placeholder="<?= site_escape($content['last_name_placeholder']) ?>" value="<?= site_escape($lastName) ?>" required>
                </label>
                <label class="form-field">
                    <?= site_escape($content['email_label']) ?>
                    <input type="email" name="email" maxlength="254" autocomplete="email" placeholder="<?= site_escape($content['email_placeholder']) ?>" value="<?= site_escape($email) ?>" required>
                </label>
                <div class="form-field">
                    <label for="contact-phone"><?= site_escape($content['phone_label']) ?></label>
                    <span class="phone-input-row" dir="ltr">
                        <label class="visually-hidden" for="phone-country"><?= site_escape(site_text($language, 'phone_country_code')) ?></label>
                        <select id="phone-country" name="phone_country" autocomplete="country" required>
                            <?php foreach ($phoneCountries as $countryCode => [$countryName, $dialCode]): ?>
                                <option value="<?= site_escape($countryCode) ?>" <?= $phoneCountry === $countryCode ? 'selected' : '' ?>>
                                    <?= site_escape($countryName . ' (' . $dialCode . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input id="contact-phone" type="tel" name="phone" maxlength="25" autocomplete="tel-national" inputmode="tel" placeholder="<?= site_escape($content['phone_placeholder']) ?>" value="<?= site_escape($phone) ?>">
                    </span>
                </div>
                <label class="form-field">
                    <?= site_escape($content['city_label']) ?>
                    <input type="text" name="city" maxlength="190" autocomplete="address-level2" placeholder="<?= site_escape($content['city_placeholder']) ?>" value="<?= site_escape($city) ?>">
                </label>
                <label class="form-field">
                    <?= site_escape($content['message_label']) ?>
                    <textarea name="message" rows="6" maxlength="5000" placeholder="<?= site_escape($content['message_placeholder']) ?>" required><?= site_escape($contactMessage) ?></textarea>
                </label>
                <div class="contact-honeypot" aria-hidden="true">
                    <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                </div>
                <button class="button-primary" type="submit"><?= site_escape($content['send_label']) ?></button>
            </form>
        </div>
    </main>
    <?php site_footer($language); ?>
</body>
</html>
