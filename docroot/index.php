<?php
session_start();

// Active Directory configuration
define('AD_SERVER', 'pa-infn-dc01.infinera.com');
define('AD_DOMAIN', 'infinera.com');
define('AD_NETBIOS', 'INFINERA');
define('PUBKEY_DIR', __DIR__ . '/pubkey');

$errors = [];
$success_message = '';

/**
 * Authenticate a user against Active Directory via LDAP bind.
 *
 * @return array{0: bool, 1: ?string} [success, error message]
 */
function authenticate_ad(string $username, string $password): array
{
    if (!function_exists('ldap_connect')) {
        return [false, 'PHP LDAP extension is not installed on this server.'];
    }

    if ($username === '' || $password === '') {
        return [false, 'Both username and password are required.'];
    }

    $ldap = @ldap_connect('ldap://' . AD_SERVER);
    if ($ldap === false) {
        return [false, 'Could not connect to Active Directory server.'];
    }

    ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 10);

    $bind_identities = [];
    if (strpos($username, '@') !== false || strpos($username, '\\') !== false) {
        $bind_identities[] = $username;
    } else {
        $bind_identities[] = $username . '@' . AD_DOMAIN;
        $bind_identities[] = AD_NETBIOS . '\\' . $username;
    }

    foreach ($bind_identities as $bind_dn) {
        if (@ldap_bind($ldap, $bind_dn, $password)) {
            ldap_unbind($ldap);
            return [true, null];
        }
    }

    ldap_unbind($ldap);
    return [false, 'Invalid username or password.'];
}

/**
 * Extract a safe username for use as a filename.
 */
function sanitize_username(string $username): string
{
    return preg_replace('/[^A-Za-z0-9_\-]/', '', basename($username));
}

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle Active Directory login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $login_user = trim($_POST['login_user'] ?? '');
    $login_pass = $_POST['login_pass'] ?? '';

    [$authenticated, $auth_error] = authenticate_ad($login_user, $login_pass);
    if ($authenticated) {
        $_SESSION['authenticated'] = true;
        $_SESSION['username'] = strpos($login_user, '\\') !== false
            ? substr($login_user, strrpos($login_user, '\\') + 1)
            : (strpos($login_user, '@') !== false ? strstr($login_user, '@', true) : $login_user);
    } else {
        $errors[] = $auth_error;
    }
}

// Handle public key submission (authenticated users only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['key_submit'])) {
    if (!isset($_SESSION['authenticated'])) {
        http_response_code(401);
        die('Unauthorized access.');
    }

    $target_username = trim($_POST['target_username'] ?? '');
    $public_key = trim($_POST['public_key'] ?? '');

    if ($target_username === '' || $public_key === '') {
        $errors[] = 'Both username and public key are required.';
    } elseif (!preg_match('/^(ssh-rsa|ssh-ed25519|ecdsa-sha2-nistp256)\s+[A-Za-z0-9\/+=]+/i', $public_key)) {
        $errors[] = 'Invalid public key format. Must start with a valid algorithm (e.g., ssh-rsa).';
    } else {
        $safe_username = sanitize_username($target_username);

        if ($safe_username === '') {
            $errors[] = 'Invalid username characters.';
        } else {
            if (!is_dir(PUBKEY_DIR) && !mkdir(PUBKEY_DIR, 0750, true)) {
                $errors[] = 'Error: Unable to create storage directory. Check server folder permissions.';
            } else {
                $csv_file = PUBKEY_DIR . '/' . $safe_username . '.csv';
                $clean_key = str_replace(["\r", "\n"], '', $public_key);
                $file_exists = file_exists($csv_file);
                $file_handle = fopen($csv_file, 'a');

                if ($file_handle !== false) {
                    if (!$file_exists) {
                        fputcsv($file_handle, ['Username', 'Public Key']);
                    }
                    fputcsv($file_handle, [$target_username, $clean_key]);
                    fclose($file_handle);

                    $_SESSION = [];
                    if (ini_get('session.use_cookies')) {
                        $params = session_get_cookie_params();
                        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
                    }
                    session_destroy();

                    setcookie('flash_success', 'Key saved to pubkey/' . $safe_username . '.csv. You have been logged out.', time() + 5, '/');
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                }

                $errors[] = 'Error: Unable to write to the storage file. Check server folder permissions.';
            }
        }
    }
}

if (isset($_COOKIE['flash_success'])) {
    $success_message = htmlspecialchars($_COOKIE['flash_success']);
    setcookie('flash_success', '', time() - 3600, '/');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SSH Key Registration</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f7f6; margin: 0; padding: 40px; }
        .container { max-width: 500px; background: #fff; padding: 30px; margin: 0 auto; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h2 { margin-top: 0; color: #333; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #555; }
        input[type="text"], input[type="password"], textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        textarea { height: 120px; font-family: monospace; resize: vertical; }
        button { background: #28a745; color: white; border: none; padding: 12px 20px; border-radius: 4px; cursor: pointer; width: 100%; font-size: 16px; }
        button:hover { background: #218838; }
        .error { color: #721c24; background: #f8d7da; padding: 10px; border-radius: 4px; margin-bottom: 20px; }
        .success { color: #155724; background: #d4edda; padding: 10px; border-radius: 4px; margin-bottom: 20px; }
        .status-bar { display: flex; justify-content: space-between; align-items: center; background: #e2e3e5; padding: 10px; border-radius: 4px; margin-bottom: 20px; font-size: 14px; }
        .logout-btn { color: #dc3545; text-decoration: none; font-weight: bold; }
        .hint { font-size: 13px; color: #666; margin-top: 4px; }
    </style>
</head>
<body>

<div class="container">
    <?php if (!empty($errors)): ?>
        <div class="error">
            <?php foreach ($errors as $error) { echo htmlspecialchars($error) . '<br>'; } ?>
        </div>
    <?php endif; ?>

    <?php if ($success_message !== ''): ?>
        <div class="success"><?php echo $success_message; ?></div>
    <?php endif; ?>

    <?php if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true): ?>

        <div class="status-bar">
            <span>Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></span>
            <a href="?action=logout" class="logout-btn">Log Out</a>
        </div>

        <h2>Submit Public Key</h2>
        <form method="POST" action="">
            <div class="form-group">
                <label for="target_username">Username:</label>
                <input type="text" id="target_username" name="target_username" required placeholder="e.g., jdoe" value="<?php echo htmlspecialchars($_SESSION['username']); ?>">
                <p class="hint">SSH key will be saved to pubkey/&lt;username&gt;.csv</p>
            </div>
            <div class="form-group">
                <label for="public_key">SSH Public Key:</label>
                <textarea id="public_key" name="public_key" required placeholder="ssh-rsa AAAAB3NzaC1yc2E..."></textarea>
            </div>
            <button type="submit" name="key_submit">Submit Key</button>
        </form>

    <?php else: ?>

        <h2>Active Directory Login</h2>
        <form method="POST" action="">
            <div class="form-group">
                <label for="login_user">Username:</label>
                <input type="text" id="login_user" name="login_user" required placeholder="jdoe or jdoe@infinera.com" autocomplete="username">
            </div>
            <div class="form-group">
                <label for="login_pass">Password:</label>
                <input type="password" id="login_pass" name="login_pass" required placeholder="Password" autocomplete="current-password">
            </div>
            <button type="submit" name="login_submit">Log In</button>
        </form>

    <?php endif; ?>
</div>

</body>
</html>
