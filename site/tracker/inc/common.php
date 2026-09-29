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

// The same frame as bigredh.com: blue-gray page, 760 wide (made for 800x600 screens), white
// panel, black footer bar. The big banner is only on the front page, so other pages load quickly.
define('FONT', 'face="Verdana, Geneva, Helvetica, Arial"');

function page_header($title, $query = '', $front = false)
{
    header('Content-Type: text/html; charset=utf-8');
    $full = $title === '' ? 'BigRedH Hotline Tracker' : $title . ' - BigRedH Hotline Tracker';
    $nav = array(
        './' => 'Server List',
        'population.php' => 'Population',
        'search.php' => 'File Search',
        'about.php' => 'About',
    );
    ?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="color-scheme" content="only light">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($full); ?></title>
<link rel="stylesheet" type="text/css" href="style.css?v=<?php echo (int) @filemtime(__DIR__ . '/../style.css'); ?>">
</head>
<body bgcolor="#7F99B3" text="#000000" link="#22228A" vlink="#22228A" alink="#6666FF" marginheight="0" marginwidth="0" leftmargin="0" topmargin="0">
<table width="100%" cellpadding="0" cellspacing="0" border="0">
<tr><td align="center" valign="top">
<table class="frame" width="760" cellpadding="0" cellspacing="0" border="0">
<tr><td><a href="http://bigredh.com/"><img class="titlebar" src="images/title_bar.gif" width="760" height="30" border="0" alt="Hotline"></a></td></tr>
<?php if ($front) { ?>
<tr><td><img class="banner" src="images/hotline_home.jpg" width="760" height="118" border="0" alt="Hotline ... Make it your Internet."></td></tr>
<?php } ?>
<tr><td bgcolor="#FFFFFF">
<table class="panel" width="760" cellpadding="0" cellspacing="0" border="0">
<tr><td width="20">&nbsp;</td><td width="720" valign="top">
<br>
<table width="100%" cellpadding="0" cellspacing="0" border="0">
<tr><td valign="bottom"><font <?php echo FONT; ?> size="4" color="#8C1021"><b>BigRedH Hotline Tracker</b></font></td>
<td align="right" valign="bottom"><font <?php echo FONT; ?> size="1"><a href="http://bigredh.com/">Back to BigRedH</a></font></td></tr>
</table>
<table class="nav" width="100%" cellpadding="4" cellspacing="0" border="0" bgcolor="#DDDDDD">
<tr><td><font <?php echo FONT; ?> size="2"><b><?php
    $links = array();
    foreach ($nav as $href => $label) {
        $links[] = '<a href="' . h($href) . '">' . h($label) . '</a>';
    }
    echo implode(' &nbsp;|&nbsp; ', $links);
?></b></font></td>
<td align="right"><form action="search.php" method="get" style="margin:0"><font <?php echo FONT; ?> size="2">
<input type="text" name="q" size="16" value="<?php echo h($query); ?>"> <input type="submit" value="Search Files">
</font></form></td></tr>
</table>
<font <?php echo FONT; ?> size="2">
<?php
}

function page_footer()
{
    ?>
</font>
<br><br>
</td><td width="20">&nbsp;</td></tr>
</table>
</td></tr>
<tr><td>
<table width="100%" cellspacing="0" cellpadding="0" border="0">
<tr>
<td width="16" align="left" bgcolor="#7F99B3"><img src="images/btm_left.gif" width="16" height="30" border="0" alt=""></td>
<td width="100%" align="center" bgcolor="#000000"><font <?php echo FONT; ?> size="1" color="#FFFFFF">Run by the Hotline Wiki team at <a href="http://hlwiki.com/"><font color="#FFFFFF">HLWiki.com</font></a>. The code and every listing are kept on <a href="<?php echo h(GITHUB_URL); ?>"><font color="#FFFFFF">GitHub</font></a>.</font></td>
<td width="15" align="right" bgcolor="#7F99B3"><img src="images/btm_right.gif" width="15" height="30" border="0" alt=""></td>
</tr>
</table>
</td></tr>
</table>
<br>
</td></tr>
</table>
</body>
</html>
<?php
}

/** A section heading in the main site's dark red. */
function heading($text)
{
    return '<p class="heading"><font ' . FONT . ' size="3" color="#8C1021"><b>' . $text . '</b></font></p>';
}

/** The small summary line under a page's heading. */
function stats_bar($html)
{
    return '<table class="stats" width="100%" cellpadding="4" cellspacing="0" border="0" bgcolor="#EEF1F5"><tr><td>'
        . '<font ' . FONT . ' size="1">' . $html . '</font></td></tr></table>';
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
