<?php
// Shared by every tracker page. Plain HTML 4 tables and colors as attributes, no JavaScript:
// the pages have to work in classic Mac OS browsers over plain HTTP.

define('DATA_DIR', __DIR__ . '/../data');
define('DB_FILE', __DIR__ . '/../db/files.sqlite');
define('GITHUB_URL', 'https://github.com/Big-Red-H/bigredh');

function h($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function load_json($name)
{
    $text = @file_get_contents(DATA_DIR . '/' . $name);
    $value = $text === false ? null : json_decode($text, true);
    return is_array($value) ? $value : array();
}

function open_db()
{
    static $db = false;
    if ($db === false) {
        $db = null;
        if (is_file(DB_FILE)) {
            try {
                $db = new PDO('sqlite:' . DB_FILE, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
            } catch (Exception $e) {
                $db = null;
            }
        }
    }
    return $db;
}

function format_bytes($bytes)
{
    $units = array('bytes', 'KB', 'MB', 'GB', 'TB');
    $i = 0;
    $n = (float) $bytes;
    while ($n >= 1024 && $i < count($units) - 1) {
        $n /= 1024;
        $i++;
    }
    return $i === 0 ? number_format($n) . ' bytes' : number_format($n, $n < 10 ? 1 : 0) . ' ' . $units[$i];
}

function format_ago($iso)
{
    $t = strtotime($iso);
    if (!$t) {
        return 'never';
    }
    $s = time() - $t;
    if ($s < 90) {
        return 'just now';
    }
    $steps = array(array(3600, 60, 'minute'), array(86400, 3600, 'hour'), array(86400 * 45, 86400, 'day'),
        array(86400 * 548, 86400 * 30, 'month'), array(PHP_INT_MAX, 86400 * 365, 'year'));
    foreach ($steps as $step) {
        if ($s < $step[0]) {
            $n = (int) round($s / $step[1]);
            return $n . ' ' . $step[2] . ($n === 1 ? '' : 's') . ' ago';
        }
    }
    return '';
}

function page_url($page, $params = array())
{
    $params = array_filter($params, function ($v) { return $v !== null && $v !== ''; });
    return $page . ($params ? '?' . http_build_query($params) : '');
}

function page_header($title, $query = '')
{
    header('Content-Type: text/html; charset=utf-8');
    $full = $title === '' ? 'BigRedH Hotline Tracker' : $title . ' - BigRedH Hotline Tracker';
    ?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($full); ?></title>
<link rel="stylesheet" type="text/css" href="style.css">
</head>
<body bgcolor="#222222" text="#00FF00" link="#00FFFF" vlink="#00CCCC" alink="#FFFFFF">
<table id="container" width="800" align="center" cellpadding="0" cellspacing="0" border="0" bgcolor="#000000">
<tr><td class="frame">
<table width="100%" cellpadding="8" cellspacing="0" border="0">
<tr><td id="header">
<font face="Lucida Console, Monaco, Courier New, monospace" size="5"><b><a href="./" class="title">BigRedH Hotline Tracker</a></b></font><br>
<font face="Lucida Console, Monaco, Courier New, monospace" size="1">Every Hotline server the trackers know about, checked every hour. <a href="http://bigredh.com/">Back to BigRedH</a></font>
</td></tr>
<tr><td id="nav" bgcolor="#111111">
<form action="search.php" method="get" style="margin:0">
<font face="Lucida Console, Monaco, Courier New, monospace" size="2">
<b><a href="./">Server List</a></b> &nbsp; <b><a href="search.php">File Search</a></b> &nbsp; <b><a href="about.php">About</a></b>
&nbsp; &nbsp; <input type="text" name="q" size="22" value="<?php echo h($query); ?>"> <input type="submit" value="Search Files">
</font>
</form>
</td></tr>
<tr><td id="content">
<font face="Lucida Console, Monaco, Courier New, monospace" size="2">
<?php
}

function page_footer()
{
    ?>
</font>
</td></tr>
<tr><td id="footer" bgcolor="#111111" align="center">
<font face="Lucida Console, Monaco, Courier New, monospace" size="1">
Run by the Hotline Wiki team at <a href="http://hlwiki.com/">HLWiki.com</a>.
The code and every listing are kept on <a href="<?php echo h(GITHUB_URL); ?>">GitHub</a>.
</font>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
<?php
}

// Servers known to the tracker, merged with this hour's check and the file index summary.
function load_servers()
{
    $servers = load_json('servers.json');
    $live = load_json('live.json');
    $liveServers = isset($live['servers']) ? $live['servers'] : array();
    $out = array();
    foreach ($servers as $key => $s) {
        $l = isset($liveServers[$key]) ? $liveServers[$key] : array();
        $s['key'] = $key;
        $s['listed'] = !empty($l['listed']);
        $s['online'] = !empty($l['online']);
        $s['users'] = isset($l['users']) ? (int) $l['users'] : 0;
        $out[$key] = $s;
    }
    return $out;
}

function file_summary()
{
    $db = open_db();
    if (!$db) {
        return array();
    }
    $rows = array();
    try {
        foreach ($db->query('SELECT * FROM servers') as $row) {
            $rows[$row['server']] = $row;
        }
    } catch (Exception $e) {
    }
    return $rows;
}
