<?php
require __DIR__ . '/inc/common.php';

$pop = load_json('population.json');
$people = isset($pop['people']) ? $pop['people'] : array();
$online = isset($pop['online']) ? $pop['online'] : array();
$serverNames = isset($pop['server_names']) ? $pop['server_names'] : array();
$icons = array_flip(isset($pop['icons']) ? $pop['icons'] : array());
$keepDays = isset($pop['keep_days']) ? (int) $pop['keep_days'] : 30;
$filter = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$only = isset($_GET['s']) ? (string) $_GET['s'] : '';

function icon_img($id, $icons)
{
    if (!isset($icons[(int) $id])) {
        return '';
    }
    return '<img src="http://hlwiki.com/ik0ns/' . (int) $id . '.png" width="232" height="18" alt="" border="0">';
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
<table cellpadding="1" cellspacing="0" border="0">
<?php foreach ($list as $u) { ?>
<tr><td width="236"><?php echo icon_img($u['icon'], $icons); ?></td>
<td><font size="2"><a href="<?php echo h(page_url('population.php', array('q' => $u['name']))); ?>"><?php echo h($u['name']); ?></a></font></td></tr>
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
<th width="236"><font size="2">Icon</font></th>
<th><font size="2">Name</font></th>
<th><font size="2">Seen on</font></th>
<th class="num"><font size="2">Last seen</font></th>
</tr>
<?php foreach ($people as $name => $p) { ?>
<tr>
<td><?php echo icon_img($p['icon'], $icons); ?></td>
<td><font size="2"><b><?php echo h($name); ?></b></font></td>
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
<tr><td colspan="4"><font size="2"><?php echo ($filter !== '' || $only !== '') ? 'Nobody matches that.' : 'Nobody yet.'; ?></font></td></tr>
<?php } ?>
</table>
<?php $seenHtml = ob_get_clean();
// A name search shows its results first; otherwise who is on right now comes first.
echo $filter !== '' ? $seenHtml . $onlineHtml : $onlineHtml . $seenHtml;
?>
<?php
page_footer();
