<?php
// CLI helper to test LDAP/AD connectivity and group membership.
// Usage (example):
// LDAP_HOST=ad.favala.es LDAP_PORT=389 LDAP_DOMAIN=favala.es \
// TEST_USER=nacho_ad TEST_PASS='...password...' ADMIN_GROUP_DN='CN=Admins,OU=Groups,DC=favala,DC=es' \
// php tools/test_ldap_bind.php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/ldap.php';

// Read env vars (do NOT hardcode credentials into files)
$env = function($k, $default = null) {
    $v = getenv($k);
    return $v === false ? $default : $v;
};

$host = $env('LDAP_HOST');
$port = $env('LDAP_PORT') ?: null;
$use_ssl = $env('LDAP_USE_SSL') ?: null;
$use_tls = $env('LDAP_USE_TLS') ?: null;
$bind_dn = $env('LDAP_BIND_DN') ?: null;
$bind_pw = $env('LDAP_BIND_PW') ?: null;
$base_dn = $env('LDAP_BASE_DN') ?: null;
$domain = $env('LDAP_DOMAIN') ?: null;

$testUser = $env('TEST_USER');
$testPass = $env('TEST_PASS');
$adminGroup = $env('ADMIN_GROUP_DN');

echo "LDAP test script\n";
if (empty($host)) {
    echo "ERROR: LDAP_HOST not set. Set environment variables before running.\n";
    exit(2);
}

// Build runtime config for LdapAuth (do NOT persist credentials to database)
$runtimeConfig = [];
if ($port) $runtimeConfig['port'] = $port;
$runtimeConfig['host'] = $host;
if ($use_ssl) $runtimeConfig['use_ssl'] = $use_ssl;
if ($use_tls) $runtimeConfig['use_tls'] = $use_tls;
if ($bind_dn) $runtimeConfig['bind_dn'] = $bind_dn;
if ($bind_pw) $runtimeConfig['bind_password'] = $bind_pw;
if ($base_dn) $runtimeConfig['base_dn'] = $base_dn;
if ($domain) $runtimeConfig['domain'] = $domain;

// Instantiate LdapAuth for AD and inject runtime config using reflection (avoid storing secrets)
$ldap = new LdapAuth('ad');
$ref = new ReflectionObject($ldap);
if ($ref->hasProperty('config')) {
    $prop = $ref->getProperty('config');
    $prop->setAccessible(true);
    $prop->setValue($ldap, $runtimeConfig);
}

echo "Testing connection to {$host}" . ($port ? ":{$port}" : "") . "...\n";
$res = $ldap->testConnection();
if ($res['success']) {
    echo "OK: " . $res['message'] . "\n";
} else {
    echo "FAIL: " . $res['message'] . "\n";
    if (!empty($res['debug'])) print_r($res['debug']);
}

if (!empty($testUser) && !empty($testPass)) {
    echo "\nAttempting to authenticate user {$testUser}...\n";
    $authOk = $ldap->authenticate($testUser, $testPass);
    echo $authOk ? "Authentication succeeded\n" : "Authentication FAILED\n";
    // show user info
    $info = $ldap->getUserInfo($testUser);
    echo "User info:\n";
    var_export($info);
    echo "\n";

    if (!empty($adminGroup)) {
        echo "\nChecking group membership for {$adminGroup}...\n";
        $isAdmin = $ldap->isMemberOf($testUser, $adminGroup);
        echo $isAdmin ? "User IS member of group\n" : "User is NOT member of group\n";
    }
    
    // Additionally, fetch user's memberOf attributes directly to inspect memberships
    echo "\nFetching raw memberOf attributes for user {$testUser}...\n";
    $port = $runtimeConfig['port'] ?? 389;
    $useSsl = !empty($runtimeConfig['use_ssl']);
    $useTls = !empty($runtimeConfig['use_tls']);
    $scheme = ($port == 636 || $useSsl) ? 'ldaps://' : 'ldap://';
    $ldapUri = $scheme . $runtimeConfig['host'] . ':' . $port;
    $conn = @ldap_connect($ldapUri);
    if ($conn) {
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        if ($useTls && !$useSsl) {@ldap_start_tls($conn);} 
        // bind for search
        $bound = false;
        if (!empty($runtimeConfig['bind_dn']) && !empty($runtimeConfig['bind_password'])) {
            $bound = @ldap_bind($conn, $runtimeConfig['bind_dn'], $runtimeConfig['bind_password']);
        }
        if (!$bound) {
            $upn = $testUser . (@$runtimeConfig['domain'] ? '@' . $runtimeConfig['domain'] : '@' . ($runtimeConfig['host'] ?? ''));
            $bound = @ldap_bind($conn, $upn, $testPass);
        }
        if ($bound) {
            $baseDn = $runtimeConfig['base_dn'] ?? ($domain ? 'DC=' . str_replace('.', ',DC=', $domain) : '');
            $safe = ldap_escape($testUser, '', LDAP_ESCAPE_FILTER);
            $filter = "(|(sAMAccountName={$safe})(uid={$safe})(cn={$safe}))";
            $s = @ldap_search($conn, $baseDn, $filter, ['memberOf','distinguishedName','dn']);
            if ($s) {
                $ents = ldap_get_entries($conn, $s);
                if ($ents && $ents['count'] > 0) {
                    $e = $ents[0];
                    if (!empty($e['memberof'])) {
                        echo "memberOf entries (count={$e['memberof']['count']}):\n";
                        for ($i=0;$i<$e['memberof']['count'];$i++) echo " - " . $e['memberof'][$i] . "\n";
                    } else {
                        echo "No memberOf attributes present on user entry.\n";
                    }
                } else {
                    echo "User entry not found when fetching memberOf.\n";
                }
            } else {
                echo "User search failed when fetching memberOf.\n";
            }
        } else {
            echo "Could not bind to LDAP to fetch memberOf.\n";
        }
        ldap_close($conn);
    } else {
        echo "Could not connect to LDAP to fetch memberOf.\n";
    }
}

// Optional: search for an entry name (group or OU) to discover its DN
$searchName = $env('SEARCH_NAME');
if (!empty($searchName)) {
    echo "\nSearching for entries named '{$searchName}' under base '" . ($runtimeConfig['base_dn'] ?? ($domain ? 'DC=' . str_replace('.', ',DC=', $domain) : '')) . "'...\n";

    $port = $runtimeConfig['port'] ?? 389;
    $useSsl = !empty($runtimeConfig['use_ssl']);
    $useTls = !empty($runtimeConfig['use_tls']);
    $scheme = ($port == 636 || $useSsl) ? 'ldaps://' : 'ldap://';
    $ldapUri = $scheme . $runtimeConfig['host'] . ':' . $port;
    $conn = @ldap_connect($ldapUri);
    if (!$conn) {
        echo "Could not connect to LDAP at {$ldapUri}\n";
    } else {
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        if ($useTls && !$useSsl) {@ldap_start_tls($conn);} 

        // Bind for search: prefer bind_dn, else try authenticating as test user (UPN) if domain known
        $bound = false;
        if (!empty($runtimeConfig['bind_dn']) && !empty($runtimeConfig['bind_password'])) {
            $bound = @ldap_bind($conn, $runtimeConfig['bind_dn'], $runtimeConfig['bind_password']);
        }
        if (!$bound) {
            // try bind as UPN
            $upn = $testUser . (@$runtimeConfig['domain'] ? '@' . $runtimeConfig['domain'] : '@' . ($runtimeConfig['host'] ?? ''));
            $bound = @ldap_bind($conn, $upn, $testPass);
        }

        if (!$bound) {
            echo "LDAP bind for search failed.\n";
        } else {
            $baseDn = $runtimeConfig['base_dn'] ?? ($domain ? 'DC=' . str_replace('.', ',DC=', $domain) : '');
            $safe = ldap_escape($searchName, '', LDAP_ESCAPE_FILTER);
            $filter = "(|(cn={$safe})(ou={$safe})(name={$safe})(displayName={$safe}))";
            $attrs = ['dn','objectClass','cn','ou','distinguishedName'];
            $s = @ldap_search($conn, $baseDn, $filter, $attrs);
            if ($s) {
                $ents = ldap_get_entries($conn, $s);
                if ($ents && $ents['count'] > 0) {
                    echo "Found {$ents['count']} entries:\n";
                    for ($i=0;$i<$ents['count'];$i++) {
                        $dn = $ents[$i]['dn'];
                        echo "- DN: {$dn}\n";
                        if (!empty($ents[$i]['objectclass'])) {
                            echo "  objectClass: " . implode(',', $ents[$i]['objectclass']) . "\n";
                        }
                    }
                } else {
                    echo "No entries found for '{$searchName}'.\n";
                }
            } else {
                echo "Search failed or returned no results.\n";
            }
        }
        ldap_close($conn);
    }
}

echo "\nDone. Note: credentials were not written to files except into runtime DB config for this test (REPLACE INTO config used temporarily). Remove test config entries if needed.\n";

exit(0);
