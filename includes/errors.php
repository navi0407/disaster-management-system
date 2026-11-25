<?php
// WP17 (Lab 50): one place that turns errors into clear, friendly messages.
//
// Load it as the FIRST thing in config/db.php, before the database connection, so that
// even a failed connection shows a friendly page instead of raw PHP text.
//
// What it does:
//  1. Hides raw PHP errors from the screen and writes them to logs/app_error.log instead.
//  2. Catches anything nobody else caught and shows a friendly page with a short reference code.
//  3. Gives pages errMessage($e), which turns a database error into plain words
//     (duplicate record, record in use, too long, and so on).
//
// Set ERR_DEBUG to true in config/db.php (before this file is loaded) only while you are fixing a bug.

if (!defined('ERR_DEBUG')) define('ERR_DEBUG', false);

function errEsc($v)
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function errCssPath()
{
    return file_exists(__DIR__ . '/../style.css') ? 'style.css' : 'assets/css/style.css';
}

// A short code the user can read out, so you can find the same error in the log.
function errReference()
{
    return 'ERR-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
}

// Writes one line to logs/app_error.log (falls back to PHP's own log if the folder is not writable).
function errLog($ref, $e)
{
    $who = $GLOBALS['AUTH_USER']['username'] ?? '-';
    $page = basename($_SERVER['SCRIPT_NAME'] ?? 'cli');
    $line = '[' . date('Y-m-d H:i:s') . "] $ref user=$who page=$page "
        . get_class($e) . ' (' . $e->getCode() . '): ' . $e->getMessage()
        . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (is_dir($dir) && is_writable($dir)) {
        // a .htaccess keeps the log from being opened in a browser
        if (!file_exists($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($dir . '/app_error.log', $line, FILE_APPEND | LOCK_EX);
    } else {
        error_log(trim($line));
    }
}

// Turns a database error into words a normal person understands.
// $e->getCode() holds the MySQL/MariaDB error number.
function errFriendlyDb($e)
{
    $code = (int) $e->getCode();
    $msg = strtolower($e->getMessage());
    if ($code === 1062) return 'That record already exists. Please check for a duplicate.';
    if ($code === 1451) return 'This record is used by other records, so it cannot be removed.';
    if ($code === 1452) return 'A linked record (such as a disaster, center, household or resident) does not exist.';
    if ($code === 1048) return 'A required field was left empty.';
    if ($code === 1406) return 'One of the entries is too long.';
    if (in_array($code, [1264, 1292, 1366, 1265, 1411], true)) return 'A number or date is out of range or in the wrong format.';
    if ($code === 4025 || $code === 3819 || strpos($msg, 'constraint') !== false && strpos($msg, 'check') !== false)
        return 'That entry breaks a rule of the system (for example a negative or zero quantity).';
    if (in_array($code, [1205, 1213], true)) return 'The system is busy with another request. Please try again in a moment.';
    if (in_array($code, [2002, 2003, 2006, 2013, 1040, 1045, 1049, 1044], true))
        return 'The system cannot reach its database right now. Please try again in a few minutes.';
    if ($code === 1146 || $code === 1054) return 'The database is missing something this page needs. Ask the administrator to run the setup scripts.';
    return '';
}

// For pages that catch an exception themselves:
//   catch (Throwable $e) { mysqli_rollback($conn); $errors[] = errMessage($e); }
// It logs the real error and returns a message that is safe to show.
function errMessage($e, $fallback = 'Something went wrong.')
{
    $ref = errReference();
    errLog($ref, $e);
    $friendly = ($e instanceof mysqli_sql_exception) ? errFriendlyDb($e) : '';
    $text = $friendly !== '' ? $friendly : $fallback;
    return $text . ' Nothing was changed. (Reference ' . $ref . ')';
}

// A full friendly page. Used for errors that stop the page.
function errPage($title, $message, $status = 500, $ref = '', $details = '')
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
    }
    while (ob_get_level() > 0) @ob_end_clean();   // throw away half-drawn page
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . errEsc($title) . '</title>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<link rel="stylesheet" href="' . errEsc(errCssPath()) . '">'
        . '<style>.err-box{max-width:640px;margin:48px auto;padding:0 16px}.err-box .error-box{margin:12px 0}'
        . '.err-box pre{background:#f1f3f5;padding:10px;overflow:auto;font-size:12px}</style></head><body>'
        . '<main class="err-box"><h1>' . errEsc($title) . '</h1>'
        . '<div class="error-box">' . errEsc($message) . '</div>';
    if ($ref !== '') echo '<p>Reference: <strong>' . errEsc($ref) . '</strong> (give this to the administrator)</p>';
    if (ERR_DEBUG && $details !== '') echo '<pre>' . errEsc($details) . '</pre>';
    echo '<p><a href="index.php">Back to home</a> | <a href="javascript:history.back()">Go back</a></p></main></body></html>';
    exit;
}

// For a record that was asked for but is not there (a bad or old link).
//   if (!$row) errNotFound('relief request');
function errNotFound($what = 'record')
{
    errPage('Not found', 'That ' . $what . ' does not exist. It may have been removed, or the link is wrong.', 404);
}

function errHandleException($e)
{
    $ref = errReference();
    errLog($ref, $e);
    $friendly = ($e instanceof mysqli_sql_exception) ? errFriendlyDb($e) : '';
    $isDown = $e instanceof mysqli_sql_exception && in_array((int) $e->getCode(), [2002, 2003, 2006, 2013, 1040, 1045, 1049, 1044], true);
    errPage(
        $isDown ? 'System unavailable' : 'Something went wrong',
        $friendly !== '' ? $friendly : 'The page could not be completed. Nothing was saved by this step.',
        $isDown ? 503 : 500,
        $ref,
        $e->getMessage() . "\n" . basename($e->getFile()) . ':' . $e->getLine()
    );
}

function errInit()
{
    if (PHP_SAPI === 'cli') return;
    ini_set('display_errors', ERR_DEBUG ? '1' : '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
    set_exception_handler('errHandleException');
    // A fatal error cannot be caught like an exception, so look for it when the script ends.
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $ref = errReference();
            errLog($ref, new ErrorException($e['message'], 0, $e['type'], $e['file'], $e['line']));
            errPage('Something went wrong', 'The page could not be completed.', 500, $ref, $e['message']);
        }
    });
}
errInit();
