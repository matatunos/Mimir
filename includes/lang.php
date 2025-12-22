<?php
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

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
    if (!empty($_SESSION['lang'])) {
        $candidate = $_SESSION['lang'];
        if (is_string($candidate)) {
            $code = $candidate;
        }
    }

    $lang = load_lang($code);
    $value = isset($lang[$key]) ? $lang[$key] : $key;

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
