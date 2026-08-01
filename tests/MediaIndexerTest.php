<?php

define('BASIC_PATH', dirname(__DIR__, 4).DIRECTORY_SEPARATOR);
function _get($array, $key, $default = null) {
	$cursor = $array;
	foreach (explode('.', $key) as $part) {
		if (!is_array($cursor) || !array_key_exists($part, $cursor)) return $default;
		$cursor = $cursor[$part];
	}
	return $cursor;
}
require_once dirname(__DIR__).'/lib/MediaIndexer.class.php';

$indexer = new MemoriesMediaIndexer(null);
assert($indexer->parseExifDate('2024:06:15 11:22:33') === mktime(11, 22, 33, 6, 15, 2024));
assert($indexer->parseExifDate('0000:00:00 00:00:00') === false);
assert(abs($indexer->gps(array('31/1', '12/1', '3000/100'), 'N') - 31.20833333) < 0.0000001);
assert(abs($indexer->gps(array('121/1', '30/1', '0/1'), 'W') + 121.5) < 0.0000001);
echo "MediaIndexer tests passed\n";
