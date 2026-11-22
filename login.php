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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in | Disaster Management System</title>
    <link rel="icon" type="image/png" href="logo.png">
    <style>
        /* This page carries its own styles so it looks the same on the laptop and the hosted site. */
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", Arial, sans-serif; font-size: 15px; line-height: 1.5; color: #222; background: #fff; }
        .split { display: grid; grid-template-columns: 1fr 1fr; min-height: 100vh; }

        /* Left: the brand side */
        .brand-panel {
            position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: center;
            padding: 48px 56px; color: #22384d; border-right: 5px solid #d9622b;
            background: linear-gradient(160deg, #fcf4f5 0%, #f3dde0 100%);
        }
        .brand-panel::before, .brand-panel::after { content: ""; position: absolute; border-radius: 50%; background: rgba(168, 50, 58, .08); }
        .brand-panel::before { width: 420px; height: 420px; right: -140px; top: -140px; }
        .brand-panel::after { width: 300px; height: 300px; left: -100px; bottom: -110px; }
        .brand-inner { position: relative; z-index: 1; max-width: 460px; }
        .brand-logo { display: block; width: 110px; height: auto; margin-bottom: 22px; }
        .brand-title { margin: 0 0 10px; font-size: 34px; line-height: 1.15; }
        .brand-tag { margin: 0 0 28px; font-size: 17px; color: #4a5a6a; }
        .brand-list { list-style: none; margin: 0; padding: 0; }
        .brand-list li { position: relative; padding: 6px 0 6px 28px; font-size: 15px; }
        .brand-list li::before { content: ""; position: absolute; left: 0; top: 13px; width: 12px; height: 12px; border-radius: 3px; background: #a8323a; }
        .brand-foot { position: relative; z-index: 1; margin-top: 40px; font-size: 13px; color: #6b7785; }

        /* Right: the form side */
        .form-panel { display: flex; align-items: center; justify-content: center; padding: 40px 24px; background: #fff; }
        .form-card { width: 100%; max-width: 380px; }
        .form-card h2 { margin: 0 0 4px; font-size: 28px; }
        .form-sub { margin: 0 0 22px; color: #666; }
        .notice, .error { padding: 10px 14px; border-radius: 6px; margin-bottom: 14px; font-size: 14px; }
        .notice { background: #e6f6ee; border: 1px solid #b9e3cf; border-left: 4px solid #1d9e75; color: #146b50; }
        .error { background: #fdecea; border: 1px solid #f3c2bb; border-left: 4px solid #d85a30; color: #9c3419; }
        .field { display: block; margin-bottom: 16px; font-weight: 600; font-size: 14px; }
        .field input[type=text], .field input[type=password] {
            display: block; width: 100%; margin-top: 6px; padding: 11px 12px; font: inherit; font-weight: 400;
            border: 1px solid #c9d1d9; border-radius: 6px; background: #fff;
        }
        .field input:focus { outline: none; border-color: #22384d; box-shadow: 0 0 0 3px rgba(34, 56, 77, .15); }
        .show-pw { display: flex; align-items: center; gap: 8px; margin: -4px 0 20px; font-size: 14px; color: #444; cursor: pointer; }
        .btn { width: 100%; padding: 12px; border: 0; border-radius: 6px; background: #22384d; color: #fff; font: inherit; font-weight: 600; font-size: 16px; cursor: pointer; }
        .btn:hover { background: #162736; }
        .form-note { margin: 18px 0 0; font-size: 13px; color: #777; text-align: center; }

        @media (max-width: 800px) {
            .split { grid-template-columns: 1fr; min-height: 0; }
            .brand-panel { padding: 26px 24px; border-right: 0; border-bottom: 5px solid #d9622b; }
            .brand-inner { display: flex; align-items: center; gap: 16px; max-width: none; }
            .brand-logo { width: 56px; margin: 0; }
            .brand-title { font-size: 21px; margin: 0; }
            .brand-tag, .brand-list, .brand-foot { display: none; }
            .form-panel { padding: 28px 20px 40px; }
        }
    </style>
</head>
<body>
<div class="split">
    <section class="brand-panel">
        <div class="brand-inner">
            <img class="brand-logo" src="logo.png" alt="DMS logo">
            <div>
                <h1 class="brand-title">Disaster Management System</h1>
                <p class="brand-tag">One place to coordinate disaster response, evacuation and relief.</p>
                <ul class="brand-list">
                    <li>Disasters and affected residents</li>
                    <li>Evacuation centers and evacuees</li>
                    <li>Resources, relief stock and distribution</li>
                    <li>Reports and a live dashboard</li>
                </ul>
            </div>
        </div>
        <div class="brand-foot">Final Project, Software Engineering</div>
    </section>

    <main class="form-panel">
        <div class="form-card">
            <h2>Welcome back</h2>
            <p class="form-sub">Log in to continue.</p>

            <?php if (isset($_GET['msg'], $notes[$_GET['msg']])): ?><div class="notice"><?= authEsc($notes[$_GET['msg']]) ?></div><?php endif; ?>
            <?php foreach ($errors as $err): ?><div class="error"><?= authEsc($err) ?></div><?php endforeach; ?>

            <form method="post" autocomplete="off">
                <?= authCsrfField() ?>
                <input type="hidden" name="next" value="<?= authEsc($next) ?>">
                <label class="field">Username
                    <input type="text" name="username" maxlength="50" value="<?= authEsc($username) ?>" autofocus>
                </label>
                <label class="field">Password
                    <input type="password" id="pw" name="password" maxlength="100">
                </label>
                <label class="show-pw"><input type="checkbox" onclick="document.getElementById('pw').type = this.checked ? 'text' : 'password'"> Show password</label>
                <button type="submit" class="btn">Log in</button>
            </form>
            <p class="form-note">Authorized users only. Logins are recorded in the audit trail.</p>
        </div>
    </main>
</div>
</body>
</html>
