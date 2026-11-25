<?php
// Small box that shows who is logged in. Paste the call into includes/header.php.
function authRenderUserBox()
{
    $u = authUser();
    if (!$u) return;
    echo '<div class="user-box">Signed in as <strong>' . authEsc($u['username']) . '</strong> (' . authEsc($u['role']) . ') | ';
    if (authIsAdmin()) echo '<a href="users.php">Users</a> | <a href="audit_trail.php">Audit Trail</a> | ';
    echo '<a href="change_password.php">Password</a> | <a href="logout.php">Log out</a></div>';
}
