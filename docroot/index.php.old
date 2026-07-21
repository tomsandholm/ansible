<?php
// 1. START SESSION AND CONFIGURATION
session_start();

// Define allowed users and their hashed passwords
// Generate new hashes using: password_hash('your_password', PASSWORD_DEFAULT)
$allowed_users = [
    'admin' => '$2y$10$I1LP5Ly6BUc.q/bC47FcAu5LOh0uGg2GJz6ECRmPqaS6DzCFvhDuy',
    'jdoe'  => '$2y$10$e0myVwYnDms5S4ZtK9OqEe7R8G8Vf.3J1D2o4M6m5N8y8w8x8z8z.'  // default pass: secure456
];

$errors = [];
$success_message = "";

// 2. HANDLE LOGOUT
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// 3. HANDLE LOCAL AUTHENTICATION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $login_user = trim($_POST['login_user'] ?? '');
    $login_pass = $_POST['login_pass'] ?? '';

    if (empty($login_user) || empty($login_pass)) {
        $errors[] = "Both username and password fields are required.";
    } else {
        // Check if user exists and password matches the hash
        if (array_key_exists($login_user, $allowed_users) && password_verify($login_pass, $allowed_users[$login_user])) {
            $_SESSION['authenticated'] = true;
            $_SESSION['username'] = $login_user;
        } else {
            $errors[] = "Invalid username or password.";
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
        // Validation: ensure it looks like an SSH key (e.g., ssh-rsa, ssh-ed25519)
        if (!preg_match('/^(ssh-rsa|ssh-ed25519|ecdsa-sha2-nistp256)\s+[A-Za-z0-9\/+=]+/i', $public_key)) {
            $errors[] = "Invalid public key format. Must start with a valid algorithm (e.g., ssh-rsa).";
        } else {
            // SECURITY: Strip path characters to force the file into the local directory
            $safe_username = preg_replace('/[^A-Za-z0-9_\-]/', '', basename($target_username));
            
            if (empty($safe_username)) {
                $errors[] = "Invalid target username characters.";
            } else {
                // Dynamically name the file based on the entered username
                $csv_file = './pubkey/' . $safe_username . '.csv';
                
                // Clean up the key string to prevent breaking CSV row formatting
                $clean_key = str_replace(array("\r", "\n"), '', $public_key);
                
                // Open the file in append mode ('a')
                $file_handle = fopen($csv_file, 'a');
                
                if ($file_handle !== false) {
                    // Write the username and key as a single CSV row
                    fputcsv($file_handle, [$target_username, $clean_key]);
                    fclose($file_handle);
                    
                    // Clear the session array and destroy it to completely log the user out
                    $_SESSION = [];
                    session_destroy();

                    // Set a temporary cookie to show the message on the next page load
                    setcookie('flash_success', "Key saved to {$csv_file}. You have been logged out.", time() + 5, "/");
                    
                    // Redirect to the same page to show the logged-out state cleanly
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $errors[] = "Error: Unable to write to the storage file. Check server folder permissions.";
                }
            }
        }
    }
}

// Read the flash message from cookie if it exists, then delete it
if (isset($_COOKIE['flash_success'])) {
    $success_message = htmlspecialchars($_COOKIE['flash_success']);
    setcookie('flash_success', '', time() - 3600, "/");
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simple Auth & Key Submission</title>
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
            <span>Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></span>
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
        
        <h2>Login</h2>
        <form method="POST" action="">
            <div class="form-group">
                <label for="login_user">Username:</label>
                <input type="text" id="login_user" name="login_user" required placeholder="Username">
            </div>
            <div class="form-group">
                <label for="login_pass">Password:</label>
                <input type="password" id="login_pass" name="login_pass" required placeholder="Password">
            </div>
            <button type="submit" name="login_submit">Log In</button>
        </form>

    <?php endif; ?>
</div>

</body>
</html>

