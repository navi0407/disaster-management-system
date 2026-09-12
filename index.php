<?php require_once 'config/db.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Disaster Management System</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php include 'includes/header.php'; ?>

<main>
    <h1>Welcome to the Disaster Management System</h1>
    <p>This is the initial homepage created in Laboratory 1.</p>

    <?php if ($conn) : ?>
        <p class="status-ok">Database connection: successful</p>
    <?php else : ?>
        <p class="status-error">Database connection: failed</p>
    <?php endif; ?>
</main>

<?php include 'includes/footer.php'; ?>

</body>
</html>
