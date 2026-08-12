<?php

if (!class_exists('SQLite3')) {
	echo "SQLite3 extension unavailable; schema test skipped\n";
	exit(0);
}

$db = new SQLite3(':memory:');
$files = array(
	dirname(__DIR__).'/lib/data/sqlite.sql',
	dirname(__DIR__, 2).'/recognize/lib/data/sqlite.sql',
);
foreach ($files as $file) {
	if (!$db->exec(file_get_contents($file))) {
		throw new RuntimeException($file.': '.$db->lastErrorMsg());
	}
}
$tables = array();
$result = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) $tables[] = $row['name'];
foreach (array('plugin_memories_media', 'plugin_memories_album', 'plugin_memories_album_item', 'plugin_memories_metadata_state', 'plugin_recognize_queue', 'plugin_recognize_tag') as $table) {
	assert(in_array($table, $tables, true));
}
echo "SQLite schemas passed\n";
