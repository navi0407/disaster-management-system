<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
authStartSession();
$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid > 0) logAudit($conn, $uid, 'Logged out', 'users', null, null, null);
authEndSession();
header('Location: login.php?msg=out'); exit;
