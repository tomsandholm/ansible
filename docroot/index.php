<?php
// 1. START SESSION AND CONFIGURATION
session_start();

define('LDAP_SERVER', 'ldap://your-domain-controller.local'); 
define('LDAP_DOMAIN', '@your-domain.local'); // Domain suffix for UPN auth

$errors = [];
$success_message = "";

// 2. HANDLE LOGOUT
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// 3. HANDLE ACTIVE DIRECTORY AUTHENTICATION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $ad_user = trim($_POST['ad_user'] ?? '');
    $ad_pass = $_POST['ad_pass'] ?? '';

    if (empty($ad_user) || empty($ad_pass)) {
        $errors[] = "Both Active Directory fields are required.";
    } else {
        // Connect to LDAP server
        $ldap_conn = ldap_connect(LDAP_SERVER);
        if ($ldap_conn) {
            ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldap_conn, LDAP_OPT_REFERRALS, 0);

            // Authenticate using User Principal Name (UPN)
            $user_principal = $ad_user . LDAP_DOMAIN;
            $bind = @ldap_bind($ldap_conn, $user_principal, $ad_pass);

            if ($bind) {
                // Auth successful: store status in session
                $_SESSION['authenticated'] = true;
                $_SESSION['ad_username'] = $ad_user;
                @ldap_close($ldap_conn);
            } else {
                $errors[] = "Invalid Active Directory credentials.";
            }
        } else {
            $errors[] = "Could not connect to Active Directory server.";
        }
    }
}

// 4. HANDLE PUBLIC KEY SUBMISSION (AUTHENTICATED USERS ONLY)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['key_submit'])) {
    if (!isset($_SESSION['authenticated'])) {
        die("Unauthorized access.");
    }

    $target_username = trim($_POST['target_username'] ?? '');
    $public_key = trim($_POST['public_key'] ?? '');

    if (empty($target_username) || empty($public_key)) {
        $errors[] = "Both target username and public key are required.";
    } else {
        // Basic validation: ensure it looks like an SSH key (e.g., ssh-rsa, ssh-ed25519)
        if (!preg_match('/^(ssh-rsa|ssh-ed25519|ecdsa-sha2-nistp256)\s+[A-Za-z0-9\/+=]+/i', $public_key)) {
            $errors[] = "Invalid public key format. Must start with a valid algorithm (e.g., ssh-rsa).";
        } else {
            // SUCCESS: Process the data (e.g., save to DB, write to file, or update AD attribute)
            // Example: file_put_contents("keys/" . basename($target_username) . ".pub", $public_key);
            
            $success_message = "Successfully submitted public key for user: " . htmlspecialchars($target_username);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AD Auth & Key Submission</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f7f6; margin: 0; padding: 40px; }
        .container { max-width: 500px; background: #fff; padding: 30px; margin: 0 auto; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h2 { margin-top: 0; color: #333; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #555; }
        input[type="text"], input[type="password"], textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        textarea { height: 120px; font-family: monospace; resize: vertical; }
        button { background: #007bff; color: white; border: none; padding: 12px 20px; border-radius: 4px; cursor: pointer; width: 100%; font-size: 16px; }
        button:hover { background: #0056b3; }
        .error { color: #721c24; background: #f8d7da; padding: 10px; border-radius: 4px; margin-bottom: 20px; }
        .success { color: #155724; background: #d4edda; padding: 10px; border-radius: 4px; margin-bottom: 20px; }
        .status-bar { display: flex; justify-content: space-between; align-items: center; background: #e2e3e5; padding: 10px; border-radius: 4px; margin-bottom: 20px; font-size: 14px; }
        .logout-btn { color: #dc3545; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <!-- DISPLAY ERRORS OR SUCCESS -->
    <?php if (!empty($errors)): ?>
        <div class="error">
            <?php foreach ($errors as $error) { echo htmlspecialchars($error) . "<br>"; } ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($success_message)): ?>
        <div class="success"><?php echo $success_message; ?></div>
    <?php endif; ?>

    <!-- PHASE 2: DISPLAY PUBLIC KEY FORM IF AUTHENTICATED -->
    <?php if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true): ?>
        
        <div class="status-bar">
            <span>Logged in as: <strong><?php echo htmlspecialchars($_SESSION['ad_username']); ?></strong></span>
            <a href="?action=logout" class="logout-btn">Log Out</a>
        </div>

        <h2>Submit Public Key</h2>
        <form method="POST" action="">
            <div class="form-group">
                <label for="target_username">Target Username:</label>
                <input type="text" id="target_username" name="target_username" required placeholder="e.g., jdoe">
            </div>
            <div class="form-group">
                <label for="public_key">SSH Public Key:</label>
                <textarea id="public_key" name="public_key" required placeholder="ssh-rsa AAAAB3NzaC1yc2E..."></textarea>
            </div>
            <button type="submit" name="key_submit">Submit Key</button>
        </form>

    <!-- PHASE 1: DISPLAY LOGIN FORM IF NOT AUTHENTICATED -->
    <?php else: ?>
        
        <h2>Active Directory Login</h2>
        <form method="POST" action="">
            <div class="form-group">
                <label for="ad_user">AD Username:</label>
                <input type="text" id="ad_user" name="ad_user" required placeholder="Domain Username">
            </div>
            <div class="form-group">
                <label for="ad_pass">AD Password:</label>
                <input type="password" id="ad_pass" name="ad_pass" required placeholder="Password">
            </div>
            <button type="submit" name="login_submit">Log In</button>
        </form>

    <?php endif; ?>
</div>

</body>
</html>

