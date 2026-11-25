<?php
// WP14: login check and role permissions.
// This file is loaded from config/db.php, and every page loads config/db.php,
// so every page is protected without editing each one.

const AUTH_IDLE_SECONDS = 1800;   // log out after 30 minutes of doing nothing
const AUTH_MAX_FAILS = 5;         // wrong passwords allowed ...
const AUTH_LOCK_MINUTES = 15;     // ... within this many minutes
const AUTH_DUMMY_HASH = '$2y$10$Njhv9k70DUQJf6WhuWTGMux5XSMD9myzrqU4dZyX1V2iy4Yepu8kO'; // used so a wrong username takes as long as a wrong password

function authEsc($v)
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function authCssPath()
{
    return file_exists(__DIR__ . '/../style.css') ? 'style.css' : 'assets/css/style.css';
}

function authStartSession()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

function authQuery($conn, $sql, $types = '', $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);
    if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

// ---------- Roles and permissions (Lab 38 and 39) ----------
// One table decides everything. 'r' = can look, 'rw' = can look and change.
// A page that changes data sends a POST, so "r" users are stopped at the first POST.
function authRoleKey($roleName)
{
    $k = strtolower(trim((string) $roleName));
    return $k === 'admin' ? 'administrator' : $k;   // an old role named Admin still works
}

function authPermissions()
{
    // Everyone can look at every module. Each officer can only CHANGE their own area.
    $read = [
        'home' => 'r',
        'disasters' => 'r',
        'residents' => 'r',
        'centers' => 'r',
        'evacuees' => 'r',
        'resources' => 'r',
        'relief_stock' => 'r',
        'distribution' => 'r',
        'release' => 'r',
        'reports' => 'r'
    ];
    return [
        'administrator'        => ['*' => 'rw'],                                          // manages users and system settings
        'disaster officer'     => array_merge($read, ['disasters' => 'rw']),               // disaster records
        'resident officer'     => array_merge($read, ['residents' => 'rw']),               // households and residents
        'evacuation officer'   => array_merge($read, ['centers' => 'rw', 'evacuees' => 'rw']),
        'resource officer'     => array_merge($read, ['resources' => 'rw']),               // resource inventory
        'relief officer'       => array_merge($read, ['relief_stock' => 'rw', 'distribution' => 'rw']),
        'distribution officer' => array_merge($read, ['distribution' => 'rw', 'release' => 'rw']),
        'management'           => $read,                                                  // reports and dashboard, changes nothing
    ];
}

// Special actions that need more than page access.
// Approving is kept away from the Distribution Officer on purpose: they only process what is already approved.
function authCapabilities()
{
    return [
        'approve' => ['administrator', 'relief officer'],
        'override_capacity' => ['administrator']
    ];   // WP17 rule 5: only an administrator may go over a center's capacity
}

// Which module a page belongs to, by the start of its file name. First match wins.
// A page that matches nothing counts as 'admin', so a forgotten page is locked, not open.
function authModuleFor($script)
{
    $map = [
        ['index', 'home'],
        ['dashboard', 'home'],
        ['change_password', 'account'],
        ['disaster_affected', 'residents'],
        ['disaster', 'disasters'],
        ['household', 'residents'],
        ['resident', 'residents'],
        ['evacuation_center', 'centers'],
        ['evacuee', 'evacuees'],
        ['resource', 'resources'],
        ['relief_item', 'relief_stock'],
        ['relief_receipt', 'relief_stock'],
        ['relief_monitor', 'relief_stock'],
        ['relief_release', 'release'],
        ['relief_', 'distribution'],
        ['report', 'reports'],
        ['users', 'admin'],
        ['audit', 'admin'],
        ['integration', 'admin'],
    ];
    foreach ($map as [$prefix, $module]) if (strpos($script, $prefix) === 0) return $module;
    return 'admin';
}

function authLevelFor($roleKey, $module)
{
    if ($module === 'account') return 'rw';           // everybody may change their own password
    $p = authPermissions()[$roleKey] ?? [];
    return $p[$module] ?? ($p['*'] ?? '');
}

function authUser()
{
    return $GLOBALS['AUTH_USER'] ?? null;
}
function authIsAdmin()
{
    return (authUser()['role_key'] ?? '') === 'administrator';
}
function authCan($capability)
{
    $u = authUser();
    return $u && in_array($u['role_key'], authCapabilities()[$capability] ?? [], true);
}

// ---------- Session helpers ----------
function authEndSession()
{
    authStartSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $c = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $c['path'], $c['domain'], $c['secure'], $c['httponly']);
    }
    session_destroy();
}

// Only plain page names are allowed after login, so nobody can bounce a user to another website.
function authSafeNext($next)
{
    $next = (string) $next;
    if (!preg_match('/^[A-Za-z0-9_]+\.php(\?[A-Za-z0-9_=&%.\-\[\]]*)?$/', $next)) return 'index.php';
    if (preg_match('/^(login|logout)\.php/', $next)) return 'index.php';
    return $next;
}

function authRedirectToLogin($msg = '')
{
    $path = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
    $query = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
    $next = $_SERVER['REQUEST_METHOD'] === 'GET' ? authSafeNext($path . ($query ? '?' . $query : '')) : 'index.php';
    header('Location: login.php?' . ($msg ? 'msg=' . urlencode($msg) . '&' : '') . 'next=' . urlencode($next));
    exit;
}

function authDeny($message, $title = 'Not allowed')
{
    // WP17: every blocked attempt is written to the audit trail, so the administrator can see it.
    try {
        $conn = $GLOBALS['conn'] ?? null;
        $u = authUser();
        if ($conn && $u) {
            require_once __DIR__ . '/crud.php';
            require_once __DIR__ . '/audit.php';
            logAudit(
                $conn,
                $u['id'],
                'Access denied',
                'security',
                null,
                $title . ': ' . basename($_SERVER['SCRIPT_NAME'] ?? '') . ' (' . ($_SERVER['REQUEST_METHOD'] ?? '') . ')',
                null
            );
        }
    } catch (Throwable $e) { /* never let logging stop the message */
    }
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . authEsc($title) . '</title>'
        . '<link rel="stylesheet" href="' . authEsc(authCssPath()) . '"></head><body><main>'
        . '<h1>' . authEsc($title) . '</h1><div class="error-box">' . authEsc($message) . '</div>'
        . '<p><a href="index.php">Back to home</a> | <a href="javascript:history.back()">Go back</a></p></main></body></html>';
    exit;
}

// ---------- CSRF (stops other websites from sending forms for the user) ----------
function authCsrfToken()
{
    authStartSession();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function authCsrfField()
{
    return '<input type="hidden" name="csrf" value="' . authCsrfToken() . '">';
}
function authCsrfOk()
{
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string) $_POST['csrf']);
}

// WP17: put the CSRF token into EVERY form that posts, so no page can forget it.
// The page is built as normal, then this runs once on the finished HTML and adds the hidden field
// right after each <form method="post">. The guard below then refuses any POST without the token.
function authCsrfInject($html)
{
    if (stripos($html, '<form') === false) return $html;
    foreach (headers_list() as $h) {                       // leave downloads and other non-HTML alone
        if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) return $html;
    }
    $field = authCsrfField();
    $out = preg_replace('~<form\b[^>]*\bmethod\s*=\s*["\']?post["\']?[^>]*>~i', '$0' . $field, $html);
    return $out ?? $html;
}

// ---------- The guard: runs at the top of every page ----------
function authGuard($conn)
{
    if (PHP_SAPI === 'cli') return;
    authStartSession();
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (in_array($script, ['login.php', 'logout.php'], true)) return;

    $uid = (int) ($_SESSION['user_id'] ?? 0);
    $expired = false;
    if ($uid > 0 && time() - (int) ($_SESSION['last_seen'] ?? 0) > AUTH_IDLE_SECONDS) {
        authEndSession();
        $uid = 0;
        $expired = true;
    }
    if ($uid <= 0) authRedirectToLogin($expired ? 'expired' : '');

    // Read the user fresh every time, so a deactivated account or a changed role works immediately.
    $rows = authQuery(
        $conn,
        "SELECT u.user_id, u.username, u.status, r.role_name
         FROM users u LEFT JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?",
        'i',
        [$uid]
    );
    $u = $rows[0] ?? null;
    if (!$u || strtolower((string) $u['status']) !== 'active') {
        authEndSession();
        authRedirectToLogin('inactive');
    }
    $_SESSION['last_seen'] = time();
    $GLOBALS['AUTH_USER'] = [
        'id' => (int) $u['user_id'],
        'username' => $u['username'],
        'role' => $u['role_name'] ?: 'No role',
        'role_key' => authRoleKey($u['role_name'])
    ];

    $module = authModuleFor($script);
    $level = authLevelFor($GLOBALS['AUTH_USER']['role_key'], $module);
    if ($level === '') authDeny('Your role (' . $GLOBALS['AUTH_USER']['role'] . ') cannot open this page.');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $level !== 'rw')
        authDeny('Your role (' . $GLOBALS['AUTH_USER']['role'] . ') can look at this page but cannot change anything on it.');

    // WP17: every form that changes data must carry the session's token.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !authCsrfOk())
        authDeny('This form has expired or did not come from this site. Go back, reload the page and send the form again.', 'Form expired');
    ob_start('authCsrfInject');
}
