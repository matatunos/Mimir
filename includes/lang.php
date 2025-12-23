<?php
// Do NOT start sessions here — session handling (cookie params, secure/samesite)
// must be configured by the application before starting a session.
// `t()` will only read `$_SESSION` when a session is already active.

function load_lang($code) {
    static $loaded = [];
    $code = preg_replace('/[^a-zA-Z0-9_\-]/', '', $code);
    if (isset($loaded[$code])) {
        return $loaded[$code];
    }

    $path = __DIR__ . '/../lang/' . $code . '.php';
    $data = [];
    if (file_exists($path)) {
        $ret = include $path;
        if (is_array($ret)) {
            $data = $ret;
        } elseif (isset($LANG) && is_array($LANG)) {
            $data = $LANG;
        }
    }

    $loaded[$code] = $data;
    return $data;
}

/**
 * t($key, $vars = [])
 * - Picks language from $_SESSION['lang'] (fallback 'es').
 * - Loads lang/{code}.php which should return an array $LANG or return the array.
 * - Supports placeholder replacement using %s and sprintf/vsprintf when $vars is provided.
 */
function t($key, $vars = []) {
    $code = 'es';
    // Only inspect $_SESSION if a session is already active. Do not call
    // session_start() here to avoid creating cookies or altering headers
    // before the application has had a chance to configure them.
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['lang'])) {
        $candidate = $_SESSION['lang'];
        if (is_string($candidate)) {
            $code = $candidate;
        }
    }

    // Load selected language and merge with fallbacks (en then es)
    $lang = load_lang($code);
    $fallback_en = load_lang('en');
    $fallback_es = load_lang('es');
    $merged = array_merge($fallback_es ?: [], $fallback_en ?: [], $lang ?: []);
    $value = isset($merged[$key]) ? $merged[$key] : $key;

    if ($vars === null || $vars === []) {
        return $value;
    }

    if (is_array($vars)) {
        if (strpos($value, '%') !== false) {
            $result = @vsprintf($value, $vars);
            return $result === false ? $value : $result;
        } else {
            return $value;
        }
    }

    // scalar $vars
    if (strpos($value, '%') !== false) {
        $result = @sprintf($value, $vars);
        return $result === false ? $value : $result;
    }

    return $value;
}

/**
 * Return available language codes and human names by scanning lang/ directory.
 * Simple mapping for common languages; unknown codes will be title-cased.
 */
function get_available_languages() {
    $dir = __DIR__ . '/../lang';
    $files = @scandir($dir) ?: [];
    $langs = [];
    $known = [
        'es' => 'Español',
        'en' => 'English',
        'fr' => 'Français',
        'de' => 'Deutsch'
    ];

    foreach ($files as $f) {
        if (substr($f, -4) === '.php') {
            $code = basename($f, '.php');
            if ($code === 'index') continue;
            $langs[$code] = $known[$code] ?? ucfirst($code);
        }
    }

    // Ensure spanish fallback exists
    if (!isset($langs['es'])) $langs['es'] = 'Español';

    return $langs;
}

/**
 * Return a simple emoji flag for a language code.
 * Uses common mappings; returns empty string if unknown.
 */
function get_language_flag($code) {
    // Prefer SVG flags in public assets for consistent rendering. Fall back to
    // emoji if SVG isn't available. Use root-relative asset path so BASE_URL
    // isn't required by the helper.
    $svgPath = '/assets/flags/' . $code . '.svg';
    $svgFull = __DIR__ . '/../public' . $svgPath;

    if (file_exists($svgFull)) {
        $img = '<img src="' . $svgPath . '" alt="' . htmlspecialchars($code, ENT_QUOTES) . '" class="lang-flag" style="width:1.25em; height:auto; vertical-align:middle; margin-right:6px;">';
        return $img;
    }

    // Emoji fallback map
    $map = [
        'es' => "🇪🇸",
        'en' => "🇬🇧",
        'fr' => "🇫🇷",
        'de' => "🇩🇪",
        'pt' => "🇵🇹",
        'it' => "🇮🇹",
        'nl' => "🇳🇱",
        'sv' => "🇸🇪",
        'da' => "🇩🇰",
        'fi' => "🇫🇮",
        'pl' => "🇵🇱",
        'cs' => "🇨🇿",
        'hu' => "🇭🇺",
        'ro' => "🇷🇴",
    ];

    // Prefer emoji fallback; if not available, return short uppercase code
    return $map[$code] ?? get_language_flag_emoji($code);
}

/**
 * Return only an emoji (no HTML) for use inside <option> elements.
 * Falls back to a short text if no emoji mapping exists.
 */
function get_language_flag_emoji($code) {
    $map = [
        'es' => "🇪🇸",
        'en' => "🇬🇧",
        'fr' => "🇫🇷",
        'de' => "🇩🇪",
        'pt' => "🇵🇹",
        'it' => "🇮🇹",
        'nl' => "🇳🇱",
        'sv' => "🇸🇪",
        'da' => "🇩🇰",
        'fi' => "🇫🇮",
        'pl' => "🇵🇱",
        'cs' => "🇨🇿",
        'hu' => "🇭🇺",
        'ro' => "🇷🇴",
    ];

    return $map[$code] ?? strtoupper(substr($code,0,2));
}
