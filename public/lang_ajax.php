<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Config.php';

header('Content-Type: application/json; charset=utf-8');

$code = $_GET['lang'] ?? '';
$code = preg_replace('/[^a-zA-Z0-9_\-]/', '', $code);
if ($code === '') $code = (new Config())->get('default_language','es');

// Load lang with fallbacks: english then spanish
$lang = load_lang($code);
$fallback_en = load_lang('en');
$fallback_es = load_lang('es');
$merged = array_merge($fallback_es ?: [], $fallback_en ?: [], $lang ?: []);

// Keys we want to return for the login page
$keys = [
    'login_title', 'login_prompt', 'label_username', 'label_password',
    'remember_me', 'login_button', 'forgot_password', 'label_language'
];

$out = [];
foreach ($keys as $k) {
    if (isset($merged[$k])) $out[$k] = $merged[$k];
    else $out[$k] = $k;
}

// Format login_title using site name from config if it contains %s
$config = new Config();
$siteName = $config->get('site_name', 'Mimir');
if (isset($out['login_title']) && strpos($out['login_title'], '%') !== false) {
    $out['login_title'] = @sprintf($out['login_title'], $siteName);
}

// Provide flag HTML (SVG) if available, otherwise emoji
$out['flag_html'] = get_language_flag($code);

echo json_encode($out, JSON_UNESCAPED_UNICODE);
exit;
