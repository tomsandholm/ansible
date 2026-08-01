<?php
session_start();

define('AD_SERVER', 'pa-infn-dc01.infinera.com');
define('AD_DOMAIN', 'infinera.com');
define('AD_NETBIOS', 'INFINERA');
define('PUBKEY_DIR', __DIR__ . '/pubkey');
define('USERS_CSV', PUBKEY_DIR . '/users.csv');

$errors = [];
$success_message = '';

/**
 * Check whether a username exists in users.csv using a shared file lock.
 *
 * @return array{0: bool, 1: ?string}
 */
function user_exists_in_csv(string $csv_path, string $username): array
{
    if (!is_file($csv_path)) {
        return [false, 'users.csv not found.'];
    }

    $handle = fopen($csv_path, 'r');
    if ($handle === false) {
        return [false, 'Unable to open users.csv.'];
    }

    if (!flock($handle, LOCK_SH)) {
        fclose($handle);
        return [false, 'Unable to lock users.csv.'];
    }

    try {
        $header = fgetcsv($handle);
        if ($header === false) {
            return [false, 'users.csv is empty.'];
        }

        $user_idx = array_search('username', $header, true);
        if ($user_idx === false) {
            return [false, 'users.csv must contain a username column.'];
        }

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) === count($header) && $row[$user_idx] === $username) {
                return [true, null];
            }
        }

        return [false, "Username {$username} is not authorized. Contact an administrator to be added to users.csv."];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Update public_key for a username in users.csv using an exclusive file lock.
 *
 * @return array{0: bool, 1: ?string}
 */
function update_users_csv_locked(string $csv_path, string $username, string $public_key): array
{
    if (!is_file($csv_path)) {
        return [false, 'users.csv not found.'];
    }

    $handle = fopen($csv_path, 'r+');
    if ($handle === false) {
        return [false, 'Unable to open users.csv.'];
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return [false, 'Unable to lock users.csv.'];
    }

    try {
        $header = fgetcsv($handle);
        if ($header === false) {
            return [false, 'users.csv is empty.'];
        }

        $user_idx = array_search('username', $header, true);
        $key_idx = array_search('public_key', $header, true);
        if ($user_idx === false || $key_idx === false) {
            return [false, 'users.csv must contain username and public_key columns.'];
        }

        $rows = [];
        $updated = false;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($header)) {
                continue;
            }

            if ($row[$user_idx] === $username) {
                $row[$key_idx] = $public_key;
                $updated = true;
            }

            $rows[] = $row;
        }

        if (!$updated) {
            return [false, "Username {$username} not found in users.csv."];
        }

        ftruncate($handle, 0);
        rewind($handle);
        fputcsv($handle, $header);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fflush($handle);

        return [true, null];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Read a pubkey file, update users.csv, and remove the drop file.
 *
 * @return array{0: bool, 1: ?string}
 */
function process_pubkey_file(string $pub_file): array
{
    if (!is_file($pub_file)) {
        return [true, null];
    }

    $filename = basename($pub_file);
    if (!preg_match('/^([A-Za-z0-9_-]+)\.pub$/', $filename, $matches)) {
        return [false, "Invalid pubkey filename: {$filename}"];
    }

    $username = $matches[1];
    $public_key = trim(str_replace(["\r", "\n"], '', (string) file_get_contents($pub_file)));

    if ($public_key === '') {
        return [false, "Pubkey file is empty: {$filename}"];
    }

    if (!preg_match('/^(ssh-rsa|ssh-ed25519|ecdsa-sha2-nistp256)\s+[A-Za-z0-9\/+=]+/i', $public_key)) {
        return [false, "Invalid public key in {$filename}."];
    }

    [$updated, $error] = update_users_csv_locked(USERS_CSV, $username, $public_key);
    if (!$updated) {
        return [false, $error];
    }

    if (!unlink($pub_file)) {
        return [false, "Updated users.csv but failed to remove {$filename} from pubkey."];
    }

    return [true, null];
}

/**
 * Process any pubkey drop files waiting in the pubkey directory.
 */
function process_pending_pubkeys(): void
{
    foreach (glob(PUBKEY_DIR . '/*.pub') ?: [] as $pub_file) {
        [$ok, $error] = process_pubkey_file($pub_file);
        if (!$ok) {
            error_log('pubkey processing failed for ' . basename($pub_file) . ': ' . $error);
        }
    }
}

process_pending_pubkeys();

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

function sanitize_login_name(string $login_name): string
{
    return preg_replace('/[^A-Za-z0-9_\-]/', '', basename($login_name));
}

function destroy_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    destroy_session();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['key_submit'])) {
    if (!isset($_SESSION['authenticated'])) {
        http_response_code(401);
        die('Unauthorized access.');
    }

    $login_name = trim($_POST['login_name'] ?? '');
    $public_key = trim($_POST['public_key'] ?? '');

    if ($login_name === '' || $public_key === '') {
        $errors[] = 'Both login name and public key are required.';
    } elseif (!preg_match('/^(ssh-rsa|ssh-ed25519|ecdsa-sha2-nistp256)\s+[A-Za-z0-9\/+=]+/i', $public_key)) {
        $errors[] = 'Invalid public key format. Must start with a valid algorithm (e.g., ssh-rsa).';
    } else {
        $safe_login_name = sanitize_login_name($login_name);

        if ($safe_login_name === '') {
            $errors[] = 'Invalid login name characters.';
        } elseif (!is_dir(PUBKEY_DIR) && !mkdir(PUBKEY_DIR, 0750, true)) {
            $errors[] = 'Error: Unable to create pubkey directory. Check server folder permissions.';
        } else {
            [$authorized, $auth_error] = user_exists_in_csv(USERS_CSV, $safe_login_name);
            if (!$authorized) {
                $errors[] = $auth_error ?? 'Username is not authorized.';
            } else {
                $pub_file = PUBKEY_DIR . '/' . $safe_login_name . '.pub';
                $clean_key = str_replace(["\r", "\n"], '', $public_key) . "\n";

                if (file_put_contents($pub_file, $clean_key, LOCK_EX) === false) {
                    $errors[] = 'Error: Unable to write public key file. Check server folder permissions.';
                } else {
                    [$updated, $csv_error] = process_pubkey_file($pub_file);
                    if (!$updated) {
                        $errors[] = $csv_error ?? 'Error: Unable to update users.csv.';
                    } else {
                        destroy_session();
                        setcookie(
                            'flash_success',
                            'Public key saved and users.csv updated for ' . $safe_login_name . '. You have been logged out.',
                            time() + 5,
                            '/'
                        );
                        header('Location: ' . $_SERVER['PHP_SELF']);
                        exit;
                    }
                }
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
                <label for="login_name">Login Name:</label>
                <input type="text" id="login_name" name="login_name" required placeholder="e.g., jdoe" value="<?php echo htmlspecialchars($_SESSION['username']); ?>">
                <p class="hint">Key updates users.csv and is removed from pubkey/ after processing</p>
            </div>
            <div class="form-group">
                <label for="public_key">SSH Public Key:</label>
                <textarea id="public_key" name="public_key" required placeholder="ssh-rsa AAAAB3NzaC1yc2E..."></textarea>
            </div>
            <button type="submit" name="key_submit">Submit</button>
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
