<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

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
        
        $email = 'admin@gemverify.com';
        $password = 'Admin@2026';
        $hash = password_hash($password, PASSWORD_BCRYPT);
        
        $stmt = $db->prepare("INSERT INTO admins (name, email, password_hash, role, is_active) VALUES (?, ?, ?, 'super_admin', 1)");
        $stmt->execute(['GemVerify Super Admin', $email, $hash]);
        
        echo "<div style='padding: 30px; max-width: 500px; margin: 50px auto; background: #eaf8ef; color: #15803d; border-radius: 16px; border: 1px solid #16a34a/20; font-family: sans-serif; box-shadow: 0 4px 12px rgba(0,0,0,0.05);'>";
        echo "<h3 style='margin-top:0;'>🎉 Success! Admin Account Created</h3>";
        echo "<p style='margin: 10px 0;'><strong>Email:</strong> <code>$email</code></p>";
        echo "<p style='margin: 10px 0;'><strong>Password:</strong> <code>$password</code></p>";
        echo "<p style='color: #b45309; font-size: 13px; background: #fff7e6; padding: 10px; border-radius: 8px;'><strong>CRITICAL SECURITY ACTION:</strong> Delete the file <code>api/setup_admin.php</code> from your server immediately.</p>";
        echo "<a href='../admin/index.html' style='display: inline-block; background: #0050ff; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: bold; margin-top: 15px;'>Go to Admin Login Panel</a>";
        echo "</div>";
        exit;
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
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { background: white; border: 1px solid #e2e8f0; padding: 32px; border-radius: 20px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); max-width: 400px; text-align: center; }
        h1 { font-size: 22px; margin: 0 0 12px; color: #0f172a; font-weight: 700; }
        p { font-size: 14px; color: #64748b; line-height: 1.6; margin-bottom: 24px; }
        button { background: #0050ff; color: white; border: none; padding: 14px 28px; border-radius: 10px; font-weight: bold; cursor: pointer; width: 100%; font-size: 14px; transition: background 0.15s ease; }
        button:hover { background: #003ecb; }
        .warning { background: #fff7e6; color: #b45309; padding: 12px; border-radius: 8px; font-size: 12px; text-align: left; margin-bottom: 20px; border: 1px solid #d97706/10; }
        .danger-msg { background: #fef2f2; color: #991b1b; padding: 12px; border-radius: 8px; font-size: 12px; text-align: left; margin-bottom: 20px; border: 1px solid #dc2626/10; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Setup First Admin</h1>
        <?php if ($count > 0): ?>
            <div class="danger-msg">
                ⚠ Admin accounts already exist in the database. This setup tool is disabled to prevent security issues.
            </div>
            <a href="../admin/index.html" style="color: #0050ff; font-weight: 600; text-decoration: none; font-size: 14px;">Go to Admin Login</a>
        <?php else: ?>
            <p>Click below to create the initial Super Administrator account for your live GemVerify installation.</p>
            <div class="warning">
                ⚠ Note: For safety, please delete this file (<code>api/setup_admin.php</code>) immediately after creation.
            </div>
            <form method="POST">
                <button type="submit" name="create">Create First Admin Account</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
