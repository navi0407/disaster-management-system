<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
authStartSession();

if ((int) ($_SESSION['user_id'] ?? 0) > 0) { header('Location: index.php'); exit; }

$next = authSafeNext($_GET['next'] ?? $_POST['next'] ?? '');
$errors = [];
$username = trim($_POST['username'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    if (!authCsrfOk()) {
        $errors[] = 'The page expired. Please try again.';
    } elseif ($username === '' || $password === '') {
        $errors[] = 'Please enter your username and password.';
    } else {
        // too many wrong passwords for this username in the last few minutes? (counted from audit_logs)
        $fails = authQuery($conn,
            "SELECT COUNT(*) AS n FROM audit_logs
             WHERE activity = 'Failed login' AND new_value = ? AND log_datetime > DATE_SUB(NOW(), INTERVAL " . AUTH_LOCK_MINUTES . " MINUTE)",
            's', [$username])[0]['n'];
        if ((int) $fails >= AUTH_MAX_FAILS) {
            $errors[] = 'Too many wrong passwords. Please wait ' . AUTH_LOCK_MINUTES . ' minutes and try again.';
        } else {
            $rows = authQuery($conn,
                "SELECT u.user_id, u.password_hash, u.status, r.role_name
                 FROM users u LEFT JOIN roles r ON r.role_id = u.role_id WHERE u.username = ? LIMIT 1", 's', [$username]);
            $user = $rows[0] ?? null;
            // always run password_verify, even for an unknown username, so the timing does not reveal who exists
            $ok = password_verify($password, $user['password_hash'] ?? AUTH_DUMMY_HASH) && $user;
            if ($ok && strtolower((string) $user['status']) !== 'active') {
                $errors[] = 'This account is inactive. Please ask an administrator.';
            } elseif ($ok && authRoleKey($user['role_name']) === '') {
                $errors[] = 'This account has no role yet. Please ask an administrator.';
            } elseif ($ok) {
                session_regenerate_id(true);               // new session id after login stops session fixation
                $_SESSION['user_id'] = (int) $user['user_id'];
                $_SESSION['last_seen'] = time();
                unset($_SESSION['csrf']);
                logAudit($conn, (int) $user['user_id'], 'Logged in', 'users', null, null, null);
                header('Location: ' . $next); exit;
            } else {
                logAudit($conn, $user ? (int) $user['user_id'] : null, 'Failed login', 'users', null, $username, null);
                $errors[] = 'Wrong username or password.';
            }
        }
    }
}
$notes = ['out' => 'You have been logged out.', 'expired' => 'You were logged out because you were away for a while.',
          'inactive' => 'Your account is not active. Please ask an administrator.'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Log in</title>
    <link rel="stylesheet" href="<?= authEsc(authCssPath()) ?>">
</head>
<body>
<header class="site-header"><h1 style="margin:0">Disaster Management System</h1></header>
<main>
    <h1>Log in</h1>
    <?php if (isset($_GET['msg'], $notes[$_GET['msg']])): ?><div class="success-box"><?= authEsc($notes[$_GET['msg']]) ?></div><?php endif; ?>
    <?php foreach ($errors as $err): ?><div class="error-box"><?= authEsc($err) ?></div><?php endforeach; ?>
    <form method="post" autocomplete="off">
        <?= authCsrfField() ?>
        <input type="hidden" name="next" value="<?= authEsc($next) ?>">
        <label>Username <input type="text" name="username" maxlength="50" value="<?= authEsc($username) ?>" autofocus></label>
        <label>Password <input type="password" id="pw" name="password" maxlength="100"></label>
        <label><input type="checkbox" style="width:auto;max-width:none" onclick="document.getElementById('pw').type = this.checked ? 'text' : 'password'"> Show password</label>
        <p><button type="submit">Log in</button></p>
    </form>
</main>
</body>
</html>
