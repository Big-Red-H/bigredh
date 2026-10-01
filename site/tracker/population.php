<?php
require __DIR__ . '/inc/common.php';

$pop = load_json('population.json');
$people = isset($pop['people']) ? $pop['people'] : array();
$online = isset($pop['online']) ? $pop['online'] : array();
$serverNames = isset($pop['server_names']) ? $pop['server_names'] : array();
// id => [width, height, "white" or "black"] from hlwiki's ik0ns.csv (older files: just ids).
$icons = array();
foreach (isset($pop['icons']) ? $pop['icons'] : array() as $k => $v) {
    if (is_array($v)) {
        $icons[(int) $k] = $v;
    } else {
        $icons[(int) $v] = array(232, 18, 'black');
    }
}
$keepDays = isset($pop['keep_days']) ? (int) $pop['keep_days'] : 30;
$filter = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$only = isset($_GET['s']) ? (string) $_GET['s'] : '';

// A name drawn over its icon the way Hotline clients (and Invigoration) show a user list: the icon
// at its own proportions, 18 pixels tall, and the name starting 33 pixels in, in whichever of
// white or black hlwiki's icon index says reads better there. A small icon sits at the left with
// the name beside it. Nested tables, so old browsers line it up too.
define('NAME_OFFSET', 33);

function user_tag($name, $iconId, $icons, $href)
{
    $info = isset($icons[(int) $iconId]) ? $icons[(int) $iconId] : null;
    $width = $info ? max(1, (int) round($info[0] * 18 / max(1, $info[1]))) : 0;
    $banner = $width >= 60;
    $color = ($info && $banner && $info[2] === 'white') ? 'white' : 'black';
    $src = 'http://hlwiki.com/ik0ns/' . (int) $iconId . '.png';
    $label = '<a href="' . h($href) . '" class="nick-' . $color . '"><font color="' . ($color === 'white' ? '#FFFFFF' : '#000000')
        . '" size="2"><b>' . h($name) . '</b></font></a>';
    $first = $info && !$banner
        ? '<img src="' . h($src) . '" width="' . $width . '" height="18" alt="" border="0">'
        : '<img src="images/pix.gif" width="' . NAME_OFFSET . '" height="1" alt="">';
    $box = max($banner ? $width : 0, 232);
    $inner = '<table cellpadding="0" cellspacing="0" border="0" width="' . $box . '"><tr>'
        . '<td width="' . NAME_OFFSET . '" height="18" valign="middle">' . $first . '</td>'
        . '<td height="18" valign="middle" nowrap><div class="nick-name" style="width:' . ($box - NAME_OFFSET - 4) . 'px">' . $label . '</div></td>'
        . '</tr></table>';
    if (!$banner) {
        return $inner;
    }
    return '<table cellpadding="0" cellspacing="0" border="0" class="nick"><tr>'
        . '<td width="' . $width . '" height="18" background="' . h($src) . '" style="background-size:' . $width . 'px 18px">'
        . $inner . '</td></tr></table>';
}

function server_name($key, $serverNames)
{
    return isset($serverNames[$key]) ? $serverNames[$key] : $key;
}

$onlineCount = 0;
foreach ($online as $list) {
    $onlineCount += count($list);
}

if ($filter !== '') {
    $people = array_filter($people, function ($p, $name) use ($filter) {
        return stripos($name, $filter) !== false;
    }, ARRAY_FILTER_USE_BOTH);
}
if ($only !== '') {
    $people = array_filter($people, function ($p) use ($only) {
        return isset($p['servers'][$only]);
    });
}
uasort($people, function ($a, $b) {
    return strcmp($b['last_seen'], $a['last_seen']);
});

page_header('Population');
?>
<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>Population</b></font></p>
<table class="stats" width="100%" cellpadding="4" cellspacing="0" border="0" bgcolor="#EEF1F5"><tr><td>
<font size="1">
<b><?php echo $onlineCount; ?></b> people online at the last check |
<b><?php echo count(isset($pop['people']) ? $pop['people'] : array()); ?></b> names seen in the last <?php echo $keepDays; ?> days |
Checked <?php echo h(format_ago(isset($pop['checked_at']) ? $pop['checked_at'] : '')); ?>
</font>
</td></tr></table>

<form action="population.php" method="get">
<font size="2">Find a name: <input type="text" name="q" size="20" value="<?php echo h($filter); ?>">
<?php if ($only !== '') { ?><input type="hidden" name="s" value="<?php echo h($only); ?>"><?php } ?>
<input type="submit" value="Find"><?php if ($filter !== '' || $only !== '') { ?> <a href="population.php">Show everyone</a><?php } ?></font>
</form>

<p><font size="1" class="dim">Every few hours the tracker looks at who's on each listed server. Names are
kept for <?php echo $keepDays; ?> days after they were last seen, and never stored anywhere else.
To be left off, send a <a href="<?php echo h(REMOVE_URL); ?>">removal request</a> or ask on the <a href="https://discord.gg/vdxJHwzfrN">Hotline Discord</a>.</font></p>

<?php ob_start(); ?>
<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>Online now</b></font></p>
<?php
$any = false;
foreach ($online as $key => $list) {
    if (!$list || ($only !== '' && $key !== $only)) {
        continue;
    }
    $any = true;
    ?>
<p><font size="2"><b><?php echo h(server_name($key, $serverNames)); ?></b>
<font size="1">(<a href="hotline://<?php echo h($key); ?>/"><?php echo h($key); ?></a>)</font></font></p>
<table cellpadding="0" cellspacing="1" border="0">
<?php foreach ($list as $u) { ?>
<tr><td><?php echo user_tag($u['name'], $u['icon'], $icons, page_url('population.php', array('q' => $u['name']))); ?></td></tr>
<?php } ?>
</table>
<?php } ?>
<?php if (!$any) { ?>
<p><font size="2">Nobody was on at the last check.</font></p>
<?php } ?>

<?php $onlineHtml = ob_get_clean(); ob_start(); ?>
<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>Seen in the last <?php echo $keepDays; ?> days</b></font></p>
<table class="list" width="100%" cellpadding="3" cellspacing="0" border="0">
<tr bgcolor="#DDDDDD">
<th width="236"><font size="2">Name</font></th>
<th><font size="2">Seen on</font></th>
<th class="num"><font size="2">Last seen</font></th>
</tr>
<?php foreach ($people as $name => $p) { ?>
<tr>
<td><?php echo user_tag($name, $p['icon'], $icons, page_url('population.php', array('q' => $name))); ?></td>
<td><font size="1"><?php
    $where = array();
    foreach ($p['servers'] as $key => $seen) {
        $where[] = '<a href="' . h(page_url('population.php', array('s' => $key))) . '">' . h(server_name($key, $serverNames)) . '</a>';
    }
    echo implode(', ', $where);
?></font></td>
<td class="num"><font size="1"><?php echo h(format_ago($p['last_seen'])); ?></font></td>
</tr>
<?php } ?>
<?php if (!$people) { ?>
<tr><td colspan="3"><font size="2"><?php echo ($filter !== '' || $only !== '') ? 'Nobody matches that.' : 'Nobody yet.'; ?></font></td></tr>
<?php } ?>
</table>
<?php $seenHtml = ob_get_clean();
// A name search shows its results first; otherwise who is on right now comes first.
echo $filter !== '' ? $seenHtml . $onlineHtml : $onlineHtml . $seenHtml;
?>
<?php
page_footer();
