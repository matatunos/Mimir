#!/usr/bin/env php
<?php
/**
 * LDAP Connection Test Script
 * Usage: php test_ldap.php
 */

echo "=== Mimir LDAP/AD Connection Test ===\n\n";

// Check if LDAP extension is available
if (!function_exists('ldap_connect')) {
    echo "❌ ERROR: PHP LDAP extension is NOT installed\n";
    echo "   Install with: apt-get install php-ldap (Debian/Ubuntu)\n";
    echo "                 or yum install php-ldap (RedHat/CentOS)\n";
    exit(1);
}

echo "✓ PHP LDAP extension is available\n\n";

// Test configuration for Active Directory
$config = [
    'server' => '192.168.1.254',
    'port' => 389,  // 389 = LDAP, 636 = LDAPS
    'base_dn' => 'DC=favala,DC=es',
    'use_ssl' => false,  // true for ldaps:// on port 636
    'use_starttls' => false,  // true to upgrade connection to TLS
    'username' => 'nacho',  // Test username
    'password' => '',  // Will prompt for password
];

echo "Configuration:\n";
echo "  Server: {$config['server']}:{$config['port']}\n";
echo "  Base DN: {$config['base_dn']}\n";
echo "  SSL: " . ($config['use_ssl'] ? 'Yes' : 'No') . "\n";
echo "  StartTLS: " . ($config['use_starttls'] ? 'Yes' : 'No') . "\n";
echo "  Test User: {$config['username']}\n\n";

// Prompt for password
echo "Enter password for {$config['username']}: ";
system('stty -echo');
$config['password'] = trim(fgets(STDIN));
system('stty echo');
echo "\n\n";

if (empty($config['password'])) {
    echo "❌ Password is required\n";
    exit(1);
}

echo "Step 1: Testing network connection...\n";
$protocol = $config['use_ssl'] ? 'ldaps' : 'ldap';
$uri = "{$protocol}://{$config['server']}:{$config['port']}";

// Test TCP connection
$socket = @fsockopen($config['server'], $config['port'], $errno, $errstr, 5);
if (!$socket) {
    echo "❌ Cannot connect to {$config['server']}:{$config['port']}\n";
    echo "   Error: $errstr ($errno)\n";
    echo "   Check firewall and network connectivity\n";
    exit(1);
}
fclose($socket);
echo "✓ Network connection successful\n\n";

echo "Step 2: Connecting to LDAP server ($uri)...\n";
$ldap = @ldap_connect($uri);

if (!$ldap) {
    echo "❌ ldap_connect() failed\n";
    exit(1);
}

echo "✓ LDAP connection established\n\n";

echo "Step 3: Setting LDAP options...\n";
ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 10);
echo "✓ LDAP options set\n\n";

// Step 4: StartTLS if configured
if ($config['use_starttls'] && !$config['use_ssl']) {
    echo "Step 4: Starting TLS encryption...\n";
    if (@ldap_start_tls($ldap)) {
        echo "✓ TLS started successfully\n\n";
    } else {
        echo "❌ StartTLS failed: " . ldap_error($ldap) . "\n";
        echo "   Try with use_starttls=false or use_ssl=true\n";
        ldap_close($ldap);
        exit(1);
    }
}

// Step 5: Try anonymous bind first to test base configuration
echo "Step 5: Testing anonymous bind...\n";
if (@ldap_bind($ldap)) {
    echo "✓ Anonymous bind successful (server allows it)\n\n";
    
    // Try to search for the user
    echo "Step 6: Searching for user in directory...\n";
    $searchFilter = "(sAMAccountName={$config['username']})";
    echo "  Filter: $searchFilter\n";
    echo "  Base DN: {$config['base_dn']}\n";
    
    $search = @ldap_search($ldap, $config['base_dn'], $searchFilter, ['cn', 'mail', 'sAMAccountName', 'distinguishedName']);
    
    if ($search) {
        $entries = ldap_get_entries($ldap, $search);
        echo "  Found: {$entries['count']} user(s)\n";
        
        if ($entries['count'] > 0) {
            echo "✓ User found!\n";
            $userDn = $entries[0]['dn'];
            echo "  User DN: $userDn\n";
            
            if (isset($entries[0]['mail'][0])) {
                echo "  Email: {$entries[0]['mail'][0]}\n";
            }
            if (isset($entries[0]['cn'][0])) {
                echo "  Common Name: {$entries[0]['cn'][0]}\n";
            }
            echo "\n";
            
            // Step 7: Try to bind as the user
            echo "Step 7: Authenticating as user...\n";
            if (@ldap_bind($ldap, $userDn, $config['password'])) {
                echo "✓✓✓ AUTHENTICATION SUCCESSFUL! ✓✓✓\n\n";
                
                echo "=== Configuration for Mimir ===\n";
                echo "Use these settings in Admin → Configuración → LDAP:\n\n";
                echo "Servidor LDAP: {$config['server']}\n";
                echo "Puerto: {$config['port']}\n";
                echo "Base DN: {$config['base_dn']}\n";
                echo "Usar SSL: " . ($config['use_ssl'] ? 'Sí' : 'No') . "\n";
                echo "Usar StartTLS: " . ($config['use_starttls'] ? 'Sí' : 'No') . "\n";
                echo "Filtro de Usuario: (sAMAccountName={username})\n";
                echo "Atributo Username: sAMAccountName\n";
                echo "Atributo Email: mail\n";
                echo "Atributo Nombre: displayName\n\n";
                
                echo "You can leave 'DN Admin' and 'Password Admin' empty if anonymous search works.\n";
                
            } else {
                echo "❌ Authentication failed: " . ldap_error($ldap) . "\n";
                echo "   Check the password or try with full DN:\n";
                echo "   User DN: $userDn\n";
            }
        } else {
            echo "❌ User not found with filter: $searchFilter\n";
            echo "   Try searching with different filters:\n";
            echo "   - (cn={$config['username']})\n";
            echo "   - (userPrincipalName={$config['username']}@favala.es)\n";
        }
    } else {
        echo "❌ Search failed: " . ldap_error($ldap) . "\n";
    }
    
} else {
    echo "⚠ Anonymous bind not allowed (this is normal for AD)\n";
    echo "  Error: " . ldap_error($ldap) . "\n\n";
    
    // Try binding directly with common DN patterns for Active Directory
    echo "Step 6: Trying to authenticate with common DN patterns...\n";
    
    $dnPatterns = [
        "CN={$config['username']},CN=Users,{$config['base_dn']}",
        "{$config['username']}@favala.es",
        "FAVALA\\{$config['username']}",
    ];
    
    $authenticated = false;
    foreach ($dnPatterns as $pattern) {
        echo "  Trying: $pattern\n";
        if (@ldap_bind($ldap, $pattern, $config['password'])) {
            echo "✓✓✓ AUTHENTICATION SUCCESSFUL! ✓✓✓\n\n";
            $authenticated = true;
            
            echo "=== Configuration for Mimir ===\n";
            echo "Use these settings in Admin → Configuración → LDAP:\n\n";
            echo "Servidor LDAP: {$config['server']}\n";
            echo "Puerto: {$config['port']}\n";
            echo "Base DN: {$config['base_dn']}\n";
            echo "Patrón User DN: $pattern\n";
            echo "   (replace username with {username} placeholder)\n";
            echo "Usar SSL: " . ($config['use_ssl'] ? 'Sí' : 'No') . "\n";
            echo "Usar StartTLS: " . ($config['use_starttls'] ? 'Sí' : 'No') . "\n";
            echo "Filtro de Usuario: (sAMAccountName={username})\n";
            echo "Atributo Username: sAMAccountName\n";
            echo "Atributo Email: mail\n";
            echo "Atributo Nombre: displayName\n\n";
            
            if (strpos($pattern, 'CN=') === 0) {
                echo "NOTE: Since we're using direct DN bind pattern:\n";
                echo "      User DN Pattern: " . str_replace($config['username'], '{username}', $pattern) . "\n";
                echo "      Leave 'DN Admin' and 'Password Admin' empty\n";
            }
            
            break;
        }
    }
    
    if (!$authenticated) {
        echo "❌ Authentication failed with all DN patterns\n";
        echo "   Last error: " . ldap_error($ldap) . "\n\n";
        
        echo "Troubleshooting:\n";
        echo "1. Verify username and password are correct\n";
        echo "2. Check if user is in CN=Users or another OU\n";
        echo "3. Try enabling StartTLS: use_starttls=true\n";
        echo "4. Try LDAPS on port 636: use_ssl=true, port=636\n";
        echo "5. Check Windows Event Viewer on DC for authentication errors\n";
    }
}

ldap_close($ldap);

echo "\n=== Test Complete ===\n";
