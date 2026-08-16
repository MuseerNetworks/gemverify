<?php
require_once __DIR__ . '/api/config/app.php';
require_once __DIR__ . '/api/config/database.php';

$errors = [];
$success = false;

try {
    $db = db();
    
    // Check if table exists first
    $tableExists = $db->query("SHOW TABLES LIKE 'admins'")->rowCount() > 0;
    if (!$tableExists) {
        die("Error: The 'admins' table does not exist. Please import the database schema first.");
    }

    // Check if any admin account already exists
    $count = (int) $db->query("SELECT COUNT(*) FROM admins")->fetchColumn();
    
    if (isset($_POST['create'])) {
        if ($count > 0) {
            die("Access Denied: Admin accounts already exist. For security, this script cannot be run again.");
        }
        
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        
        // Validation
        if (empty($name)) {
            $errors['name'] = "Name is required.";
        }
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "A valid email address is required.";
        }
        if (strlen($password) < 8) {
            $errors['password'] = "Password must be at least 8 characters.";
        }
        if ($password !== $confirm) {
            $errors['confirm_password'] = "Passwords do not match.";
        }
        
        if (empty($errors)) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            
            $stmt = $db->prepare("INSERT INTO admins (name, email, password_hash, role, is_active) VALUES (?, ?, ?, 'super_admin', 1)");
            $stmt->execute([$name, $email, $hash]);
            $success = true;
        }
    }

} catch (Exception $e) {
    die("Database Connection Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GemVerify — Setup First Admin</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
        .card { background: white; border: 1px solid #e2e8f0; padding: 32px; border-radius: 20px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); width: 100%; max-width: 440px; box-sizing: border-box; }
        h1 { font-size: 22px; margin: 0 0 8px; color: #0f172a; font-weight: 700; text-align: center; }
        .subtitle { font-size: 13px; color: #64748b; line-height: 1.5; margin-bottom: 24px; text-align: center; }
        .form-group { margin-bottom: 16px; text-align: left; }
        label { display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 6px; }
        input { width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box; }
        input:focus { border-color: #0050ff; box-shadow: 0 0 0 2px rgba(0,80,255,0.1); }
        button { background: #0050ff; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: bold; cursor: pointer; width: 100%; font-size: 14px; margin-top: 10px; transition: background 0.15s ease; }
        button:hover { background: #003ecb; }
        .warning { background: #fff7e6; color: #b45309; padding: 12px; border-radius: 8px; font-size: 11px; text-align: left; margin-bottom: 20px; border: 1px solid rgba(217,119,6,0.1); line-height: 1.4; }
        .error-msg { color: #dc2626; font-size: 11px; margin-top: 4px; display: block; }
        .danger-msg { background: #fef2f2; color: #991b1b; padding: 14px; border-radius: 8px; font-size: 12px; text-align: left; margin-bottom: 20px; border: 1px solid rgba(220,38,38,0.1); line-height: 1.5; }
        .success-box { padding: 24px; background: #eaf8ef; color: #15803d; border-radius: 12px; text-align: center; }
        .success-box h3 { margin: 0 0 10px; font-size: 18px; }
        .success-box p { font-size: 13px; color: #166534; margin: 5px 0; }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($success): ?>
            <div class="success-box">
                <h3>🎉 Account Created!</h3>
                <p>Your custom Super Admin account is ready.</p>
                <p><strong>Email:</strong> <code><?php echo htmlspecialchars($email); ?></code></p>
                <p style="background: #fff7e6; color: #b45309; padding: 10px; border-radius: 8px; font-size: 12px; margin-top: 15px; font-weight: bold;">
                    CRITICAL: Delete the file <code>setup_admin.php</code> from your server right away!
                </p>
                <a href="admin/index.html" style="display: inline-block; background: #0050ff; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: bold; margin-top: 15px; font-size: 13px;">Go to Admin Login</a>
            </div>
        <?php elseif ($count > 0): ?>
            <h1>Setup Disabled</h1>
            <div class="subtitle">Security Protection Enabled</div>
            <div class="danger-msg">
                ⚠ Admin accounts already exist in the database. This setup tool is disabled to prevent security hijacking.
            </div>
            <div style="text-align:center;">
                <a href="admin/index.html" style="color: #0050ff; font-weight: 600; text-decoration: none; font-size: 14px;">Go to Admin Login</a>
            </div>
        <?php else: ?>
            <h1>Setup First Admin</h1>
            <div class="subtitle">Choose your own administrator credentials</div>
            
            <div class="warning">
                ⚠ Note: After creating the account, you must delete this file (<code>setup_admin.php</code>) from your hosting server immediately.
            </div>
            
            <form method="POST">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required placeholder="e.g. John Doe">
                    <?php if (isset($errors['name'])): ?><span class="error-msg"><?php echo $errors['name']; ?></span><?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required placeholder="e.g. admin@yourdomain.com">
                    <?php if (isset($errors['email'])): ?><span class="error-msg"><?php echo $errors['email']; ?></span><?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label>Password (Min. 8 characters)</label>
                    <input type="password" name="password" required placeholder="Choose a secure password">
                    <?php if (isset($errors['password'])): ?><span class="error-msg"><?php echo $errors['password']; ?></span><?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" required placeholder="Confirm your password">
                    <?php if (isset($errors['confirm_password'])): ?><span class="error-msg"><?php echo $errors['confirm_password']; ?></span><?php endif; ?>
                </div>
                
                <button type="submit" name="create">Register First Admin Account</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
