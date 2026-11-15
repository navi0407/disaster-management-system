<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
$me = authUser();
$errors = []; $done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string) ($_POST['current'] ?? '');
    $new = (string) ($_POST['new'] ?? '');
    $again = (string) ($_POST['again'] ?? '');
    if (!authCsrfOk()) $errors[] = 'The page expired. Please try again.';
    if (strlen($new) < 8) $errors[] = 'The new password must be at least 8 characters.';
    if ($new !== $again) $errors[] = 'The two new passwords are not the same.';
    if ($new !== '' && $new === $current) $errors[] = 'The new password must be different from the current one.';
    if (!$errors) {
        $row = dbGetById($conn, 'users', 'user_id', $me['id']);
        if (!$row || !password_verify($current, $row['password_hash'])) {
            $errors[] = 'The current password is not correct.';
        } else {
            dbUpdate($conn, 'users', 'user_id', $me['id'], ['password_hash' => password_hash($new, PASSWORD_BCRYPT)]);
            logAudit($conn, $me['id'], 'Changed own password', 'users', null, null, null);
            $done = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Change Password</title>
    <link rel="stylesheet" href="<?= authEsc(authCssPath()) ?>">
</head>
<body>
<?php include 'includes/header.php'; ?>
<main>
    <h1>Change Password</h1>
    <?php if ($done): ?><div class="success-box">Your password was changed.</div><?php endif; ?>
    <?php foreach ($errors as $err): ?><div class="error-box"><?= authEsc($err) ?></div><?php endforeach; ?>
    <form method="post" autocomplete="off">
        <?= authCsrfField() ?>
        <label>Current password <input type="password" name="current"></label>
        <label>New password (8 characters or more) <input type="password" name="new"></label>
        <label>New password again <input type="password" name="again"></label>
        <p><button type="submit">Change password</button></p>
    </form>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
