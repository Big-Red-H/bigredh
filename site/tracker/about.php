<?php
require __DIR__ . '/inc/common.php';

page_header('About');
?>
<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>About the tracker</b></font></p>
<p>BigRedH doesn't run a tracker of its own. Every hour it asks the Hotline trackers below for
their lists, merges them, and checks that each server answers. Servers a tracker lists for
decoration (welcome lines and dividers) are left out.</p>
<ul>
<li>hltracker.com</li>
<li>tracker.preterhuman.net</li>
<li>hotline.kicks-ass.net</li>
<li>saddle.dyndns.org</li>
</ul>

<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>The file index</b></font></p>
<p>BigRedH logs into each server as a guest and writes down the name, size and kind of each file
and folder a guest can see, so you can search across every server. Nothing is downloaded. To
download, connect to the server with a Hotline client.</p>
<ul>
<li><b>About every 60 days.</b> Each server's file list is looked at again roughly every two
months, never more often.</li>
<li><b>Slowly.</b> It logs in as <b>TrackerCheck</b> and opens one folder at a time, at most one a
second, and slower still if the server is slow to answer. If anything goes wrong it waits before
trying again. Servers on the same machine are done one after another, never at once. A very big
server is done over more than one visit.</li>
<li><b>Archives are left alone.</b> If a server's files are exactly the same as the last time, it's
treated as an archive and isn't indexed again. A server's owner can also say it's an archive.</li>
<li><b>Never, if you'd rather not.</b> A server can be left out of the index permanently, and
whatever was indexed is removed.</li>
</ul>

<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>What's changed from the old tracker</b></font></p>
<p>The old BigRedH tracker indexed every server's files about every five days. The new one:</p>
<ul>
<li>indexes each server about every <b>60 days</b> instead of every 5;</li>
<li>goes at <b>one folder a second</b> at most, and never opens more than one connection to a
server, or to the servers on one machine, at a time;</li>
<li><b>stops indexing archives</b>: a server that hasn't changed between two visits isn't visited
for its files again;</li>
<li>lets server owners <b>opt out permanently</b>, or mark their server as an archive, with one
<a href="<?php echo h(INDEXING_URL); ?>">short form</a>;</li>
<li>keeps the file lists in the open, on <a href="<?php echo h(GITHUB_URL); ?>">GitHub</a>, so anyone
can see exactly what was recorded and when.</li>
</ul>

<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>Population</b></font></p>
<p>Every six hours, BigRedH logs into each listed server that the trackers say has users on it (empty servers aren't
visited) as <b>TrackerCheck</b>, reads the
user list, and leaves. The <a href="population.php">Population</a> page shows who was on and
the names seen in the last 30 days, with their icons from the
<a href="http://hlwiki.com/ik0ns/">Hotline Wiki's icon archive</a>. Names are only kept on this
site, for 30 days, and aren't saved anywhere else. To be left off, ask on the
<a href="https://discord.gg/vdxJHwzfrN">Hotline Discord</a>. Servers that keep out of the file
index are skipped here too.</p>

<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>For server owners</b></font></p>
<p>Use the <a href="<?php echo h(INDEXING_URL); ?>"><b>indexing form</b></a> on GitHub to have your
server never indexed (and what's indexed removed), marked as an archive (indexed once, then left
alone), or indexed again after it changed. To take your server off the list or off the Population
page, or your name off Population, use the <a href="<?php echo h(REMOVE_URL); ?>"><b>removal
request</b></a>. Both need a free GitHub account; you can also just ask on the
<a href="https://discord.gg/vdxJHwzfrN">Hotline Discord</a>.</p>
<p>Without asking: make a folder named <b>noindex</b> anywhere on your server. When the indexer
sees it, it stops, records nothing, and removes what it had.</p>

<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>Getting listed</b></font></p>
<p>Register your server with any of the trackers above and it shows up here within the hour.</p>

<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>The data</b></font></p>
<p>Everything the tracker collects is saved to <a href="<?php echo h(GITHUB_URL); ?>">GitHub</a>
as JSON: the server list every day, and every server's files every month. This hour's list is
also at <a href="data/servers.json">servers.json</a> and <a href="data/live.json">live.json</a>.</p>
<?php
page_footer();
