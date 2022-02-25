<?php
/*
 * simple-file-manager
 *
 * Copyright:
 * - jcampbell1 -> initial project (github.com/jcampbell1/simple-file-manager)
 * - diego95root -> file edit feature (github.com/diego95root/File-manager-php)
 * - danielk117 -> recent version with file editing (github.com/danielk117/simple-file-manager)
 *
 * Liscense: MIT
 */

// disable error report for undefined superglobals
error_reporting( error_reporting() & ~E_NOTICE );

// default settings (edit only in config.php)
$base_dir = '';
$allow_delete = true;
$allow_upload = true;
$allow_edit = true;
$allow_create_folder = true;
$allow_direct_link = true;
$allow_show_folders = true;
$disallowed_patterns = ['*.php'];
$hidden_patterns = ['*.php','.*'];
$datetime_format = 'Y-m-d H:i:s';
$jquery_url = '//code.jquery.com/jquery-3.6.0.min.js';
$PASSWORD = '';

include_once __DIR__ . '/config.php';

if ($base_dir!=='') chdir(getcwd().$base_dir);

if ($PASSWORD)
{
    session_start();
    if (!$_SESSION['_sfm_allowed'])
    {
        session_regenerate_id();
        // sha1, and random bytes to thwart timing attacks.  Not meant as secure hashing.
        $t = bin2hex(random_bytes(10));
        if ($_POST['p'] && sha1($t . $_POST['p']) === sha1($t . $PASSWORD))
        {
            $_SESSION['_sfm_allowed'] = true;
            header('Location: ?');
        }
        echo '<html><body><form action=? method=post>PASSWORD:<input type=password name=p autofocus/></form></body></html>';
        exit;
    }
}

// must be in UTF-8 or `basename` doesn't work
setlocale(LC_ALL,'en_US.UTF-8');

$tmp_dir = dirname($_SERVER['SCRIPT_FILENAME']) . $base_dir;
if (DIRECTORY_SEPARATOR === '\\')
{
    $tmp_dir = str_replace('/', DIRECTORY_SEPARATOR, $tmp_dir);
}
$tmp = get_absolute_path($tmp_dir . '/' . $_REQUEST['file']);

if ($tmp === false)
{
    response(404, 'File or Directory Not Found');
}
if (substr($tmp, 0, strlen($tmp_dir)) !== $tmp_dir)
{
    response(403, "Forbidden");
}
if (strpos($_REQUEST['file'], DIRECTORY_SEPARATOR) === 0)
{
    response(403, "Forbidden");
}
if (preg_match('@^.+://@', $_REQUEST['file']))
{
    response(403, "Forbidden");
}

// CSRF

$xsrf = generate_xsrf();
if ($_POST)
{
    if ($_COOKIE['_sfm_xsrf'] !== $_POST['xsrf'] || !$_POST['xsrf'])
    {
        response(403, "XSRF Failure");
    }
}

// GET and POST handling

$file = $_REQUEST['file'] ?: '.';

if ($_GET['action'] == 'list')
{
    if (is_dir($file))
    {
        $directory = $file;
        $result = [];
        $files = array_diff(scandir($directory), ['.', '..']);
        foreach ($files as $entry)
        {
            if (!is_entry_ignored($entry, $allow_show_folders, $hidden_patterns))
            {
                $i = $directory . '/' . $entry;
                $stat = @stat($i);
                $result[] = [
                    'mtime'         => $stat['mtime'],
                    'ftime'         => date($datetime_format, $stat['mtime']),
                    'size'          => $stat['size'],
                    'name'          => basename($i),
                    'path'          => preg_replace('@^\./@', '', $i),
                    'is_dir'        => is_dir($i),
                    'is_deleteable' => $allow_delete && ((!is_dir($i) && is_writable($directory)) ||
                            (is_dir($i) && is_writable($directory) && is_recursively_deleteable($i))),
                    'is_editable' => $allow_edit && ((!is_dir($i) && is_writable($directory)) ||
                            (is_dir($i) && is_writable($directory) && is_recursively_deleteable($i))),
                    'is_readable'   => is_readable($i),
                    'is_writable'   => is_writable($i),
                    'is_executable' => is_executable($i),
                    'owner'         => @posix_getpwuid(fileowner($i))['name'] . ':' . @posix_getgrgid(filegroup($i))['name'],
                ];

            }
        }
        usort($result, function ($f1, $f2)
        {
            $f1_key = ($f1['is_dir'] ?: 2) . $f1['name'];
            $f2_key = ($f2['is_dir'] ?: 2) . $f2['name'];

            return $f1_key > $f2_key;
        });
    }
    else
    {
        response(412, "Not a Directory");
    }
    echo json_encode(['success' => true, 'is_writable' => is_writable($file), 'results' => $result]);
    exit;
}
elseif ($_POST['action'] == 'delete')
{
    if ($allow_delete)
    {
        if (rmrf($file))
        {
            response(200, 'deleted successful');
        }
    }
    response(400, 'deleting not successful');
}
elseif ($_POST['action'] == 'mkdir' && $allow_create_folder)
{
    // don't allow actions outside root. we also filter out slashes to catch args like './../outside'
    $dir = $_POST['name'];
    $dir = str_replace('/', '', $dir);
    if (substr($dir, 0, 2) === '..')
    {
        exit;
    }
    chdir($file);

    if (mkdir($_POST['name']))
    {
        response(200, 'mkdir successful');
    }
     response(400, 'mkdir not successful');
}
elseif ($_POST['action'] == 'upload' && $allow_upload)
{
    foreach ($disallowed_patterns as $pattern)
    {
        if (fnmatch($pattern, $_FILES['file_data']['name']))
        {
            response(403, "Files of this type are not allowed.");
        }
    }
    $res = move_uploaded_file($_FILES['file_data']['tmp_name'], $file . '/' . $_FILES['file_data']['name']);
    exit;
}
elseif ($_GET['action'] == 'view' || $_GET['action'] == 'download')
{
    foreach ($hidden_patterns as $pattern)
    {
        if (fnmatch($pattern, $file))
        {
            response(403, "Files of this type are not allowed.");
        }
    }

    $filename = basename($file);
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $type = finfo_file($finfo, $file);
    if ($_GET['action'] == 'download')
    {
        header('Content-Type: ' . $type);
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    }
    else
    {
        if (in_array($type, ['image/jpeg', 'image/png', 'application/pdf']))
        {
            header('Content-Type: ' . $type);
        }
        else
        {
            header('Content-Type: text/plain');
        }
    }
    readfile($file);
    exit;
}
elseif ($_POST['action'] == 'load')
{
    if (isset($_POST['file']))
    {
        readfile($_POST['file']);
    }
    exit;
}
elseif ($_POST['action'] == 'save' && $allow_edit)
{
    if (isset($_POST['textarea']))
    {
        $data_write = rawurldecode($_POST['textarea']);
        $location = $_POST['file'];

        $fp = fopen($location, 'w');
        $result = fwrite($fp, $data_write);
        fclose($fp);
        if ($result)
        {
            response(200, 'saved successful');
        }
    }
    response(400, 'saving not successful');
}

function generate_xsrf(): string
{
    $xsrf = bin2hex(random_bytes(16));
    setcookie('_sfm_xsrf', $xsrf);
    return $xsrf;
}

function is_entry_ignored($entry, $allow_show_folders, $hidden_patterns)
{
    if ($entry === basename(__FILE__))
    {
        return true;
    }

    if (is_dir($entry) && !$allow_show_folders)
    {
        return true;
    }

    foreach ($hidden_patterns as $pattern)
    {
        if (fnmatch($pattern, $entry))
        {
            return true;
        }
    }

    return false;
}

function rmrf($dir): bool
{
    if (is_dir($dir))
    {
        $result = true;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file)
        {
            $result &= rmrf("$dir/$file");
        }
        $result &= rmdir($dir);
        return $result;
    }
    else
    {
        return unlink($dir);
    }
}

function is_recursively_deleteable($d)
{
    $stack = [$d];
    while ($dir = array_pop($stack))
    {
        if (!is_readable($dir) || !is_writable($dir))
        {
            return false;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file)
        {
            if (is_dir($file))
            {
                $stack[] = "$dir/$file";
            }
        }
    }

    return true;
}

// from: http://php.net/manual/en/function.realpath.php#84012
function get_absolute_path($path)
{
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $parts = explode(DIRECTORY_SEPARATOR, $path);
    $absolutes = [];
    foreach ($parts as $part)
    {
        if ('.' == $part)
        {
            continue;
        }
        if ('..' == $part)
        {
            array_pop($absolutes);
        }
        else
        {
            $absolutes[] = $part;
        }
    }

    return implode(DIRECTORY_SEPARATOR, $absolutes);
}

function response($code, $msg)
{
    http_response_code($code);
    header("Content-Type: application/json");
    $type = 'success';
    if ($code >= 400)
    {
        $type = 'error';
    }
    echo json_encode([$type => ['code' => intval($code), 'msg' => $msg]]);
    exit;
}

function asBytes($ini_v)
{
    $ini_v = trim($ini_v);
    $s = ['g' => 1 << 30, 'm' => 1 << 20, 'k' => 1 << 10];

    return intval($ini_v) * ($s[strtolower(substr($ini_v, -1))] ?: 1);
}

$MAX_UPLOAD_SIZE = min(asBytes(ini_get('post_max_size')), asBytes(ini_get('upload_max_filesize')));
?>

<!DOCTYPE html>
<html>
<head>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<style>
    body {
        font-family: sans-serif;
        font-size: 14px;
        padding: 1em;
        margin: 0;
    }

    th {
        font-weight: normal;
        background-color: #f2f2f2;
        padding: .5em;
        text-align: left;
        cursor: pointer;
        user-select: none;
        color: #00509e;
    }

    th .indicator {
        margin-left: 6px
    }

    thead {
        border-top: 1px solid #c6c6c6;
        border-bottom: 1px solid #c6c6c6;
    }

    #top {
        height: 52px;
    }

    #mkdir {
        display: inline-block;
        float: right;
        padding-top: 16px;
    }

    label {
        display: block;
        font-size: 11px;
        color: #555;
    }

    #file_drop_target {
        width: 500px;
        padding: 12px 0;
        border: 4px dashed #ccc;
        font-size: 12px;
        color: #ccc;
        text-align: center;
        float: right;
        margin-right: 20px;
    }

    #file_drop_target.drag_over {
        border: 4px dashed #96C4EA;
        color: #96C4EA;
    }

    #upload_progress {
        padding: 4px 0;
    }

    #upload_progress .error {
        color: #a00;
    }

    #upload_progress > div {
        padding: 3px 0;
    }

    .no_write #mkdir, .no_write #file_drop_target {
        display: none
    }

    .progress_track {
        display: inline-block;
        width: 200px;
        height: 10px;
        border: 1px solid #333;
        margin: 0 4px 0 10px;
    }

    .progress {
        background-color: #82CFFA;
        height: 10px;
    }

    footer {
        font-size: 11px;
        color: #bbbbc5;
        padding: 4em 0 0;
        text-align: left;
    }

    footer a, footer a:visited {
        color: #bbbbc5;
    }

    #breadcrumb {
        padding-top: 34px;
        font-size: 15px;
        color: #aaa;
        display: inline-block;
        float: left;
    }

    #folder_actions {
        width: 50%;
        float: right;
    }

    a, a:visited {
        color: #00509e;
        text-decoration: none
    }

    a:hover {
        text-decoration: underline
    }

    .sort_hide {
        display: none;
    }

    table {
        border-collapse: collapse;
        width: 100%;
    }

    thead {
        max-width: 1024px
    }

    td {
        padding: .2em .5em;
        border-bottom: 1px solid #e6e6e6;
        height: 30px;
        font-size: 12px;
        white-space: nowrap;
    }

    td.first {
        font-size: 14px;
        white-space: normal;
        width: 100%;
    }

    td.empty {
        color: #777;
        font-style: italic;
        text-align: center;
        padding: 3em 0;
    }

    .is_dir .size {
        color: transparent;
        font-size: 0;
    }

    .is_dir .size:before {
        content: "--";
        font-size: 14px;
        color: #333;
    }

    .is_dir .download {
        visibility: hidden
    }

    .is_dir .edit {
        visibility: hidden
    }

    .name {
        background: url(data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iaXNvLTg4NTktMSI/PjxzdmcgdmVyc2lvbj0iMS4xIiBpZD0iTGF5ZXJfMSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB4bWxuczp4bGluaz0iaHR0cDovL3d3dy53My5vcmcvMTk5OS94bGluayIgeD0iMHB4IiB5PSIwcHgiIHZpZXdCb3g9IjAgMCAzMDkuMjY3IDMwOS4yNjciIHN0eWxlPSJlbmFibGUtYmFja2dyb3VuZDpuZXcgMCAwIDMwOS4yNjcgMzA5LjI2NzsiIHhtbDpzcGFjZT0icHJlc2VydmUiPjxnPjxwYXRoIHN0eWxlPSJmaWxsOiNEOUQ5RDk7IiBkPSJNMzguNjU4LDBoMTY0LjIzbDg3LjA0OSw4Ni43MTF2MjAzLjIyN2MwLDEwLjY3OS04LjY1OSwxOS4zMjktMTkuMzI5LDE5LjMyOUgzOC42NTggYy0xMC42NywwLTE5LjMyOS04LjY1LTE5LjMyOS0xOS4zMjlWMTkuMzI5QzE5LjMyOSw4LjY1LDI3Ljk4OSwwLDM4LjY1OCwweiIvPjxwYXRoIHN0eWxlPSJmaWxsOiNBOUE5QTk7IiBkPSJNMjg5LjY1OCw4Ni45ODFoLTY3LjM3MmMtMTAuNjcsMC0xOS4zMjktOC42NTktMTkuMzI5LTE5LjMyOVYwLjE5M0wyODkuNjU4LDg2Ljk4MXoiLz48cGF0aCBzdHlsZT0iZmlsbDojQTlBOUE5OyIgZD0iTTU3Ljk4OCwxMjUuNjR2MTkuMzI5SDI1MS4yOFYxMjUuNjRINTcuOTg4eiBNNTcuOTg4LDE4My42MzdIMjUxLjI4di0xOS4zMjlINTcuOTg4VjE4My42Mzd6IE01Ny45ODgsMjIyLjI4NkgyNTEuMjh2LTE5LjMyOUg1Ny45ODhWMjIyLjI4NnogTTU3Ljk4OCwyNjAuOTQ0SDI1MS4yOHYtMTkuMzJINTcuOTg4VjI2MC45NDR6IE0xNjQuMjk4LDg2Ljk4MUg1Ny45ODh2MTkuMzI5IGgxMDYuMzExTDE2NC4yOTgsODYuOTgxTDE2NC4yOTgsODYuOTgxeiBNMTY0LjI5OCw0OC4zMjNINTcuOTg4djE5LjMyOWgxMDYuMzExTDE2NC4yOTgsNDguMzIzTDE2NC4yOTgsNDguMzIzeiIvPjwvZz48L3N2Zz4=) no-repeat scroll 0px 8px;
        background-size: 28px;
        padding: 15px 0 10px 40px;
    }

    .is_dir .name {
        background: url(data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iaXNvLTg4NTktMSI/PjxzdmcgdmVyc2lvbj0iMS4xIiBpZD0iTGF5ZXJfMSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB4bWxuczp4bGluaz0iaHR0cDovL3d3dy53My5vcmcvMTk5OS94bGluayIgeD0iMHB4IiB5PSIwcHgiIHZpZXdCb3g9IjAgMCAzMDkuMjY3IDMwOS4yNjciIHN0eWxlPSJlbmFibGUtYmFja2dyb3VuZDpuZXcgMCAwIDMwOS4yNjcgMzA5LjI2NzsiIHhtbDpzcGFjZT0icHJlc2VydmUiPjxnPjxwYXRoIHN0eWxlPSJmaWxsOiNEMDk5NEI7IiBkPSJNMjYwLjk0NCw0My40OTFIMTI1LjY0YzAsMC0xOC4zMjQtMjguOTk0LTI4Ljk5NC0yOC45OTRINDguMzIzYy0xMC42NywwLTE5LjMyOSw4LjY1LTE5LjMyOSwxOS4zMjkgdjIyMi4yODZjMCwxMC42Nyw4LjY1OSwxOS4zMjksMTkuMzI5LDE5LjMyOWgyMTIuNjIxYzEwLjY3LDAsMTkuMzI5LTguNjU5LDE5LjMyOS0xOS4zMjlWNjIuODIgQzI4MC4yNzMsNTIuMTUsMjcxLjYxNCw0My40OTEsMjYwLjk0NCw0My40OTF6Ii8+PHBhdGggc3R5bGU9ImZpbGw6I0U0RTdFNzsiIGQ9Ik0yOC45OTQsNzIuNDg0aDI1MS4yNzl2NzcuMzE3SDI4Ljk5NFY3Mi40ODR6Ii8+PHBhdGggc3R5bGU9ImZpbGw6I0Y0QjQ1OTsiIGQ9Ik0xOS4zMjksOTEuODE0aDI3MC42MDljMTAuNjcsMCwxOS4zMjksOC42NSwxOS4zMjksMTkuMzI5bC0xOS4zMjksMTY0LjI5OCBjMCwxMC42Ny04LjY1OSwxOS4zMjktMTkuMzI5LDE5LjMyOUgzOC42NThjLTEwLjY3LDAtMTkuMzI5LTguNjU5LTE5LjMyOS0xOS4zMjlMMCwxMTEuMTQzQzAsMTAwLjQ2Myw4LjY1OSw5MS44MTQsMTkuMzI5LDkxLjgxNHoiLz48L2c+PC9zdmc+) no-repeat scroll 0px 8px;
        background-size: 28px;
        padding: 15px 0 10px 40px;
    }

    .edit {
        background: url(data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iaXNvLTg4NTktMSI/PjxzdmcgdmVyc2lvbj0iMS4xIiBpZD0iQ2FwYV8xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiB4PSIwcHgiIHk9IjBweCIgdmlld0JveD0iMCAwIDQ5MC4yNzMgNDkwLjI3MyIgc3R5bGU9ImVuYWJsZS1iYWNrZ3JvdW5kOm5ldyAwIDAgNDkwLjI3MyA0OTAuMjczOyIgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+PGcgc3R5bGU9ImZpbGw6I2ZlYWQxYSI+PHBhdGggZD0iTTMxMy41NDgsMTUyLjM4N2wtMjMwLjgsMjMwLjljLTYuNyw2LjctNi43LDE3LjYsMCwyNC4zYzMuMywzLjMsNy43LDUsMTIuMSw1czguOC0xLjcsMTIuMS01bDIzMC44LTIzMC44IGM2LjctNi43LDYuNy0xNy42LDAtMjQuM0MzMzEuMTQ4LDE0NS42ODcsMzIwLjI0OCwxNDUuNjg3LDMxMy41NDgsMTUyLjM4N3oiLz48cGF0aCBkPSJNNDMxLjE0OCwxOTEuODg3YzQuNCwwLDguOC0xLjcsMTIuMS01bDI1LjItMjUuMmMyOS4xLTI5LjEsMjkuMS03Ni40LDAtMTA1LjRsLTM0LjQtMzQuNCBjLTE0LjEtMTQuMS0zMi44LTIxLjgtNTIuNy0yMS44Yy0xOS45LDAtMzguNiw3LjgtNTIuNywyMS44bC0yNS4yLDI1LjJjLTYuNyw2LjctNi43LDE3LjYsMCwyNC4zbDExNS42LDExNS42IEM0MjIuMzQ4LDE5MC4xODcsNDI2Ljc0OCwxOTEuODg3LDQzMS4xNDgsMTkxLjg4N3ogTTM1Mi45NDgsNDUuOTg3YzcuNi03LjYsMTcuNy0xMS44LDI4LjUtMTEuOGMxMC43LDAsMjAuOSw0LjIsMjguNSwxMS44IGwzNC40LDM0LjRjMTUuNywxNS43LDE1LjcsNDEuMiwwLDU2LjlsLTEzLjIsMTMuMmwtOTEuNC05MS40TDM1Mi45NDgsNDUuOTg3eiIvPjxwYXRoIGQ9Ik0xNjIuODQ4LDQ2Ny4xODdsMjQzLjUtMjQzLjVjNi43LTYuNyw2LjctMTcuNiwwLTI0LjNzLTE3LjYtNi43LTI0LjMsMGwtMjM5LjMsMjM5LjVsLTEwNS42LDE0LjJsMTQuMi0xMDUuNiBsMjI4LjYtMjI4LjZjNi43LTYuNyw2LjctMTcuNiwwLTI0LjNjLTYuNy02LjctMTcuNi02LjctMjQuMywwbC0yMzIuNiwyMzIuOGMtMi43LDIuNy00LjQsNi4xLTQuOSw5LjhsLTE4LDEzMy42IGMtMC43LDUuMywxLjEsMTAuNiw0LjksMTQuNGMzLjIsMy4yLDcuNiw1LDEyLjEsNWMwLjgsMCwxLjUtMC4xLDIuMy0wLjJsMTMzLjYtMTggQzE1Ni43NDgsNDcxLjU4NywxNjAuMjQ4LDQ2OS44ODcsMTYyLjg0OCw0NjcuMTg3eiIvPjwvZz48L3N2Zz4=) no-repeat scroll 0px 2px;
        background-size: 16px;
        padding: 4px 0 4px 20px;
        color: orange !important;
        margin-right: 15px;
    }

    .download {
        background: url(data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iaXNvLTg4NTktMSI/PjxzdmcgdmVyc2lvbj0iMS4xIiBpZD0iQ2FwYV8xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiB4PSIwcHgiIHk9IjBweCIgdmlld0JveD0iMCAwIDE5Mi43MDEgMTkyLjcwMSIgc3R5bGU9ImVuYWJsZS1iYWNrZ3JvdW5kOm5ldyAwIDAgMTkyLjcwMSAxOTIuNzAxOyIgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+PGcgc3R5bGU9ImZpbGw6IzAwNTA5ZSI+PHBhdGggZD0iTTE3MS45NTUsODguNTI2bC03NS42MSw3NC41MjhsLTc1LjYxLTc0LjU0Yy00Ljc0LTQuNzA0LTEyLjQzOS00LjcwNC0xNy4xNzksMGMtNC43NCw0LjcwNC00Ljc0LDEyLjMxOSwwLDE3LjAxMSBsODQuMiw4Mi45OTdjNC41NTksNC41MTEsMTIuNjA4LDQuNTM1LDE3LjE5MSwwbDg0LjItODMuMDA5YzQuNzQtNC42OTIsNC43NC0xMi4zMTksMC0xNy4wMTEgQzE4NC4zOTQsODMuODIzLDE3Ni42OTUsODMuODIzLDE3MS45NTUsODguNTI2eiIvPjxwYXRoIGQ9Ik04Ny43NTUsMTA0LjMyMmM0LjU1OSw0LjUxMSwxMi42MDgsNC41MzUsMTcuMTkxLDBsODQuMi04Mi45OTdjNC43NC00LjcwNCw0Ljc0LTEyLjMxOSwwLTE3LjAxMSBjLTQuNzQtNC43MDQtMTIuNDM5LTQuNzA0LTE3LjE3OSwwTDk2LjM0NSw3OC44NDJMMjAuNzM0LDQuMzE0Yy00Ljc0LTQuNzA0LTEyLjQzOS00LjcwNC0xNy4xNzksMCBjLTQuNzQsNC43MDQtNC43NCwxMi4zMTksMCwxNy4wMTFMODcuNzU1LDEwNC4zMjJ6Ii8+PC9nPjwvc3ZnPg==) no-repeat scroll 0px 2px;
        background-size: 16px;
        padding: 4px 0 4px 20px;
        margin-right: 15px;
    }

    .delete {
        background: url(data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iaXNvLTg4NTktMSI/PjxzdmcgdmVyc2lvbj0iMS4xIiBpZD0iTGF5ZXJfMSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB4bWxuczp4bGluaz0iaHR0cDovL3d3dy53My5vcmcvMTk5OS94bGluayIgeD0iMHB4IiB5PSIwcHgiIHZpZXdCb3g9IjAgMCAyODQuMDExIDI4NC4wMTEiIHN0eWxlPSJlbmFibGUtYmFja2dyb3VuZDpuZXcgMCAwIDI4NC4wMTEgMjg0LjAxMTsiIHhtbDpzcGFjZT0icHJlc2VydmUiPjxnIHN0eWxlPSJmaWxsOiNGRjAwMDAiPjxwYXRoIGQ9Ik0yMzUuNzMyLDY2LjIxNGwtMjguMDA2LTEzLjMwMWwxLjQ1Mi0zLjA1N2M2LjM1NC0xMy4zNzksMC42MzktMjkuNDM0LTEyLjc0LTM1Ljc4OUwxNzIuMzE2LDIuNjExIGMtNi40OC0zLjA3OS0xMy43NzEtMy40NDctMjAuNTMyLTEuMDQyYy02Ljc2LDIuNDA2LTEyLjE3OCw3LjMwMS0xNS4yNTYsMTMuNzgybC0xLjQ1MiwzLjA1N0wxMDcuMDcsNS4xMDYgYy0xNC42NTMtNi45NTgtMzIuMjM5LTAuNjk4LTM5LjIsMTMuOTU1TDYwLjcsMzQuMTU1Yy0xLjEzOCwyLjM5Ni0xLjI3Nyw1LjE0Ni0wLjM4OCw3LjY0NGMwLjg5LDIuNDk5LDIuNzM1LDQuNTQyLDUuMTMxLDUuNjggbDc0LjIxOCwzNS4yNWgtOTguMThjLTIuNzk3LDAtNS40NjUsMS4xNzEtNy4zNTgsMy4yMjljLTEuODk0LDIuMDU5LTIuODM5LDQuODE1LTIuNjA3LDcuNjAybDEzLjE0MywxNTcuNzA2IGMxLjUzLDE4LjM2MiwxNy4xNjIsMzIuNzQ1LDM1LjU4OCwzMi43NDVoNzMuNTRjMTguNDI1LDAsMzQuMDU3LTE0LjM4MywzNS41ODctMzIuNzQ1bDExLjYxOC0xMzkuNDA4bDI4LjIwNSwxMy4zOTYgYzEuMzg1LDAuNjU4LDIuODQ1LDAuOTY5LDQuMjgzLDAuOTY5YzMuNzQsMCw3LjMyOC0yLjEwOCw5LjA0LTUuNzEybDcuMTY5LTE1LjA5M0MyNTYuNjQ2LDkwLjc2MSwyNTAuMzg2LDczLjE3NSwyMzUuNzMyLDY2LjIxNHoKIE0xNTQuNTk0LDIzLjkzMWMwLjc4Ni0xLjY1NSwyLjE3LTIuOTA1LDMuODk2LTMuNTIxYzEuNzI5LTAuNjE0LDMuNTktMC41MjEsNS4yNDUsMC4yNjdsMjQuMTIxLDExLjQ1NSBjMy40MTgsMS42MjQsNC44NzgsNS43MjYsMy4yNTUsOS4xNDRsLTEuNDUyLDMuMDU3bC0zNi41MTgtMTcuMzQ0TDE1NC41OTQsMjMuOTMxeiBNMTY5LjQ0MSwyNDkuNjA0IGMtMC42NzMsOC4wNzctNy41NSwxNC40MDUtMTUuNjU1LDE0LjQwNWgtNzMuNTRjLTguMTA2LDAtMTQuOTgzLTYuMzI4LTE1LjY1Ni0xNC40MDVMNTIuMzUsMTAyLjcyOGgxMjkuMzMyTDE2OS40NDEsMjQ5LjYwNHoKIE0yMzEuNjIsOTYuODM1bC0yLjg3OCw2LjA2TDgzLjA1NywzMy43MDFsMi44NzktNi4wNjFjMi4yMjktNC42OTUsNy44NjMtNi42OTgsMTIuNTU0LTQuNDY5bDEyOC42NjEsNjEuMTA4IEMyMzEuODQ1LDg2LjUwOSwyMzMuODUsOTIuMTQyLDIzMS42Miw5Ni44MzV6Ii8+PC9nPjwvc3ZnPg==) no-repeat scroll 0px 2px;
        background-size: 16px;
        padding: 4px 0 4px 20px;
        color: red !important;
    }

    #textarea {
        width: 1200px;
        height: 500px;
        resize: none;
        border-radius: 8px;
        border: 2px solid rgb(125, 125, 125);
        outline: none;
        font-family: monospace;
        padding: 10px;
    }

    .control {
        border: 2px solid rgb(125, 125, 125);
        border-radius: 8px;
        background-color: rgb(240, 240, 240);
        transition: .25s;
        padding: 2px 5px;
    }

    .control:hover {
        cursor: pointer;
        background-color: rgb(200, 200, 200);
        transition: .25s;
    }

    #overlay-back {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: #000;
        opacity: 0;
        filter: alpha(opacity=60);
        z-index: 5;
        transition: .3s;
        visibility: hidden;
    }

    #edit-container {
        position: fixed;
        z-index: 10;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        opacity: 0;
        transition: .3s;
        visibility: hidden;
    }

    #saved {
        position: fixed;
        background: #aaa;
        opacity: 0;
        filter: alpha(opacity=60);
        transition: .3s;
        transition: .3s;
        visibility: hidden;
        z-index: 14;
        color: white;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        padding: 40px;
        border-radius: 8px;
        border: 2px solid #777;
        font-size: 28px;
    }
</style>
<script src="<?= $jquery_url ?>"></script>
<script>
    let XSRF = '<?= $xsrf ?>';
    let FILE = '';

    (function ($) {
        $.fn.tablesorter = function () {
            var $table = this;
            this.find('th').click(function () {
                var idx = $(this).index();
                var direction = $(this).hasClass('sort_asc');
                $table.tablesortby(idx, direction);
            });
            return this;
        };
        $.fn.tablesortby = function (idx, direction) {
            var $rows = this.find('tbody tr');

            function elementToVal(a) {
                var $a_elem = $(a).find('td:nth-child(' + (idx + 1) + ')');
                var a_val = $a_elem.attr('data-sort') || $a_elem.text();
                return (a_val == parseInt(a_val) ? parseInt(a_val) : a_val);
            }

            $rows.sort(function (a, b) {
                var a_val = elementToVal(a), b_val = elementToVal(b);
                return (a_val > b_val ? 1 : (a_val == b_val ? 0 : -1)) * (direction ? 1 : -1);
            })
            this.find('th').removeClass('sort_asc sort_desc');
            $(this).find('thead th:nth-child(' + (idx + 1) + ')').addClass(direction ? 'sort_desc' : 'sort_asc');
            for (var i = 0; i < $rows.length; i++)
                this.append($rows[i]);
            this.settablesortmarkers();
            return this;
        }
        $.fn.retablesort = function () {
            var $e = this.find('thead th.sort_asc, thead th.sort_desc');
            if ($e.length)
                this.tablesortby($e.index(), $e.hasClass('sort_desc'));

            return this;
        }
        $.fn.settablesortmarkers = function () {
            this.find('thead th span.indicator').remove();
            this.find('thead th.sort_asc').append('<span class="indicator">&darr;<span>');
            this.find('thead th.sort_desc').append('<span class="indicator">&uarr;<span>');
            return this;
        }
    })(jQuery);

    $(function () {
        var MAX_UPLOAD_SIZE = <?= $MAX_UPLOAD_SIZE ?>;
        var $tbody = $('#list');
        $(window).on('hashchange', list).trigger('hashchange');
        $('#table').tablesorter();

        $('#table').on('click', '.delete', function (data) {
            XSRF = (document.cookie.match('(^|; )_sfm_xsrf=([^;]*)')||0)[2];
            let file = $(this).attr('data-file');
            if (confirm('Delete ' + file + '?')) {
                // $.post("", {'action': 'delete', file: file, xsrf: XSRF}, function (response) {
                //     list();
                // }, 'json');
                $.ajax({
                    type: "POST",
                    url: '',
                    data: {
                        action: 'delete',
                        file: file,
                        xsrf: XSRF
                    }
                }).done((data) => {
                    list();
                });
            }
            return false;
        });

        $('#mkdir').submit(function (e) {
            XSRF = (document.cookie.match('(^|; )_sfm_xsrf=([^;]*)')||0)[2];
            var hashval = decodeURIComponent(window.location.hash.substr(1)),
                $dir = $(this).find('[name=name]');
            e.preventDefault();
            $dir.val().length && $.post('?', {'action': 'mkdir', name: $dir.val(), xsrf: XSRF, file: hashval}, function (data) {
                list();
            }, 'json');
            $dir.val('');
            return false;
        });

        <?php if($allow_upload): ?>
        // file upload stuff
        $('#file_drop_target').on('dragover', function () {
            $(this).addClass('drag_over');
            return false;
        }).on('dragend', function () {
            $(this).removeClass('drag_over');
            return false;
        }).on('drop', function (e) {
            e.preventDefault();
            var files = e.originalEvent.dataTransfer.files;
            $.each(files, function (k, file) {
                uploadFile(file);
            });
            $(this).removeClass('drag_over');
        });

        $('input[type=file]').change(function (e) {
            e.preventDefault();
            $.each(this.files, function (k, file) {
                uploadFile(file);
            });
        });

        function uploadFile(file) {
            XSRF = (document.cookie.match('(^|; )_sfm_xsrf=([^;]*)')||0)[2];
            var folder = decodeURIComponent(window.location.hash.substr(1));

            if (file.size > MAX_UPLOAD_SIZE) {
                var $error_row = renderFileSizeErrorRow(file, folder);
                $('#upload_progress').append($error_row);
                window.setTimeout(function () {
                    $error_row.fadeOut();
                }, 5000);
                return false;
            }

            var $row = renderFileUploadRow(file, folder);
            $('#upload_progress').append($row);
            var fd = new FormData();
            fd.append('file_data', file);
            fd.append('file', folder);
            fd.append('xsrf', XSRF);
            fd.append('action', 'upload');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '?');
            xhr.onload = function () {
                $row.remove();
                list();
            };
            xhr.upload.onprogress = function (e) {
                if (e.lengthComputable) {
                    $row.find('.progress').css('width', (e.loaded / e.total * 100 | 0) + '%');
                }
            };
            xhr.send(fd);
        }

        function renderFileUploadRow(file, folder) {
            return $row = $('<div/>')
                .append($('<span class="fileuploadname" />').text((folder ? folder + '/' : '') + file.name))
                .append($('<div class="progress_track"><div class="progress"></div></div>'))
                .append($('<span class="size" />').text(formatFileSize(file.size)))
        }

        function renderFileSizeErrorRow(file, folder) {
            return $row = $('<div class="error" />')
                .append($('<span class="fileuploadname" />').text('Error: ' + (folder ? folder + '/' : '') + file.name))
                .append($('<span/>').html(' file size - <b>' + formatFileSize(file.size) + '</b>'
                    + ' exceeds max upload size of <b>' + formatFileSize(MAX_UPLOAD_SIZE) + '</b>'));
        }
        <?php endif; ?>

        function list() {
            var hashval = window.location.hash.substr(1);
            $.get('?action=list&file=' + hashval, function (data) {
                $tbody.empty();
                $('#breadcrumb').empty().html(renderBreadcrumbs(hashval));
                if (data.success) {
                    $.each(data.results, function (k, v) {
                        $tbody.append(renderFileRow(v));
                    });
                    !data.results.length && $tbody.append('<tr><td class="empty" colspan=6>This folder is empty</td></tr>')
                    data.is_writable ? $('body').removeClass('no_write') : $('body').addClass('no_write');
                } else {
                    console.warn(data.error.msg);
                }
                $('#table').retablesort();
            }, 'json');
        }

        function renderFileRow(data) {
            var $link = $('<a class="name" />')
                .attr('href', data.is_dir ? '#' + encodeURIComponent(data.path) : '?action=view&file=' + encodeURIComponent(data.path))
                .attr('target', data.is_dir ? '' : '_blank').text(data.name);
            var allow_direct_link = <?= $allow_direct_link ? 'true' : 'false'; ?>;
            if (!data.is_dir && !allow_direct_link) $link.css('pointer-events', 'none');
            var $dl_link = $('<a/>').attr('href', '?action=download&file=' + encodeURIComponent(data.path))
                .addClass('download').text('download');
            var $edit_link = $('<a/>').attr('href', 'javascript:loadEditor(\'' + encodeURIComponent(data.path) + '\'); try {document.getElementById("mceu_28-body").remove()} catch(err){}')
                .addClass('edit').text('edit');
            var $delete_link = $('<a href="#" />').attr('data-file', data.path).addClass('delete').text('delete');
            var perms = [];
            if (data.is_readable) perms.push('read');
            if (data.is_writable) perms.push('write');
            if (data.is_executable) perms.push('exec');
            var $html = $('<tr />')
                .addClass(data.is_dir ? 'is_dir' : '')
                .append($('<td class="first" />').append($link))
                .append($('<td/>').attr('data-sort', data.is_dir ? -1 : data.size)
                    .html($('<span class="size" />').text(formatFileSize(data.size))))
                .append($('<td/>').attr('data-sort', data.mtime).text(data.ftime))
                .append($('<td/>').text(data.owner))
                .append($('<td/>').text(perms.join('+')))
                .append($('<td/>').append(data.is_editable ? $edit_link : '').append($dl_link).append(data.is_deleteable ? $delete_link : ''))
            return $html;
        }

        function renderBreadcrumbs(path) {
            var base = "",
                $html = $('<div/>').append($('<a href=#><?= basename(getcwd()) ?></a></div>'));
            $.each(path.split('%2F'), function (k, v) {
                if (v) {
                    var v_as_text = decodeURIComponent(v);
                    $html.append($('<span/>').text(' / '))
                        .append($('<a/>').attr('href', '#' + base + v).text(v_as_text));
                    base += v + '%2F';
                }
            });
            return $html;
        }

        function formatFileSize(bytes) {
            var s = ['bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'];
            for (var pos = 0; bytes >= 1000; pos++, bytes /= 1024) ;
            var d = Math.round(bytes * 10);
            return pos ? [parseInt(d / 10), ".", d % 10, " ", s[pos]].join('') : bytes + ' bytes';
        }
    })

    function loadEditor(path) {
        XSRF = (document.cookie.match('(^|; )_sfm_xsrf=([^;]*)')||0)[2];
        FILE = path;
        $.ajax({
            type: "POST",
            url: '',
            data: {
                action: 'load',
                file: FILE,
                xsrf: XSRF
            }
        }).done((data) => {
            document.getElementById('textarea').value = data;
        });
        appear();
        document.getElementById("edit-container").style.opacity = "1";
        document.getElementById("overlay-back").style.opacity = "0.6";
    }

    function saveFile() {
        XSRF = (document.cookie.match('(^|; )_sfm_xsrf=([^;]*)')||0)[2];
        let data = document.getElementById('textarea').value;
        $.ajax({
            type: "POST",
            url: '',
            data: {
                action: 'save',
                textarea: data,
                file: FILE,
                xsrf: XSRF
            }
        }).done(() => {
            alert('File was saved');
        }).fail(() => {
            alert('Error while saving');
        });
    }

    function closeEditor() {
        document.getElementById("overlay-back").style.opacity = "0";
        document.getElementById("edit-container").style.opacity = "0";
        disappear();
    }

    function appear() {
        document.getElementById("overlay-back").style.visibility = "visible";
        document.getElementById("edit-container").style.visibility = "visible";
    }

    function disappear() {
        document.getElementById("overlay-back").style.visibility = "hidden";
        document.getElementById("edit-container").style.visibility = "hidden";
    }
</script>
</head>
<body>
<div id="overlay-back"></div>
<div id="saved">The file was saved</div>
<div id="top">
   <?php if($allow_create_folder): ?>
    <form action="?" method="post" id="mkdir" />
        <label for=dirname>Create New Folder</label><input id=dirname type=text name=name value="" />
        <input type="submit" value="create" />
    </form>

   <?php endif; ?>

   <?php if($allow_upload): ?>

    <div id="file_drop_target">
        Drag Files Here To Upload
        <b>or</b>
        <input type="file" multiple />
    </div>
   <?php endif; ?>
    <div id="breadcrumb">&nbsp;</div>
</div>

<div id="upload_progress"></div>
<table id="table"><thead><tr>
    <th>Name</th>
    <th>Size</th>
    <th>Modified</th>
    <th>Owner</th>
    <th>Permissions</th>
    <th>Actions</th>
</tr></thead><tbody id="list">

</tbody></table>
<div id="edit-container">
    <div style="display:inline-block;">
        <div style="">
            <textarea style="display:block; margin-bottom:5px" id="textarea" wrap="hard">Type some text here.</textarea>
            <button class="control" style="display:inline-block; float:right" onclick="closeEditor()">Close</button>
            <button class="control" style="display:inline-block; float:right" onclick="saveFile()">Save</button>
        </div>
    </div>
</div>
</body>
</html>
