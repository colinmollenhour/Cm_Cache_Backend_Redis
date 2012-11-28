<?php

$server = 'tcp://127.0.0.1:6379';
$db = 0;
$limit = 20;
array_shift($argv);
while($arg = array_shift($argv)) {
  switch($arg) {
    case '--db': $db = intval(array_shift($argv)); break;
    case '--server': $server = array_shift($argv); break;
    case '--limit': $limit = array_shift($argv); break;
    default: die("Unrecognized argument '$arg'.\nUsage: path/to/stats.php [--server tcp://127.0.0.1:6378] [--db 0] [--limit 20]\n");
  }
}

require __DIR__.'/lib/Credis/Client.php';
require './lib/Zend/Cache/Backend/Interface.php';
require './lib/Zend/Cache/Backend/ExtendedInterface.php';
require './lib/Zend/Cache/Backend.php';
require __DIR__.'/Cm/Cache/Backend/Redis.php';

$client = new Credis_Client($server);
$client->select($db);

$tagStats = array();
foreach($client->sMembers(Cm_Cache_Backend_Redis::SET_TAGS) as $tag) {
  if (preg_match('/^\w{3}_MAGE$/', $tag)) continue;
  $ids = $client->sMembers(Cm_Cache_Backend_Redis::PREFIX_TAG_IDS . $tag);
  $tagSizes = array();
  $missing = 0;
  foreach ($ids as $id) {
    $data = $client->hGet(Cm_Cache_Backend_Redis::PREFIX_KEY.$id, Cm_Cache_Backend_Redis::FIELD_DATA);
    $size = strlen($data);
    if ($size) $tagSizes[] = $size;
    else $missing++;
  }
  if ($tagSizes) {
    $tagStats[$tag] = array(
      'count' => count($tagSizes),
      'min' => min($tagSizes),
      'max' => max($tagSizes),
      'avg size' => array_sum($tagSizes) / count($tagSizes),
      'total size' => array_sum($tagSizes),
      'missing' => $missing,
    );
  }
}

function _format_bytes($a_bytes)
{
  if ($a_bytes < 1024) {
    return $a_bytes .' B';
  } elseif ($a_bytes < 1048576) {
    return round($a_bytes / 1024, 4) .' KB';
  } else {
    return round($a_bytes / 1048576, 4) . ' MB';
  }
}

function printStats($data, $key, $limit) {
  echo "Top $limit tags by ".ucwords($key)."\n";
  echo "------------------------------------------------------------------------------------\n";
  $sort = array();
  foreach ($data as $tag => $stats) {
    $sort[$tag] = $stats[$key];
  }
  array_multisort($sort, SORT_DESC, $data);
  $i = 0;
  $fmt = "%-40s| %-8s| %-15s| %-15s\n";
  printf($fmt, 'Tag', 'Count', 'Avg Size', 'Total Size');
  foreach ($data as $tag => $stats) {
    $tag = substr($tag, 4);
    if (++$i > $limit) break;
    $avg = _format_bytes($stats['avg size']);
    $total = _format_bytes($stats['total size']);
    printf($fmt, $tag, $stats['count'], $avg, $total);
  }
  echo "\n";
}

// Top 20 by total size
printStats($tagStats, 'total size', $limit, true);

// Top 20 by average size
printStats($tagStats, 'avg size', $limit, true);

// Top 20 by count
printStats($tagStats, 'count', $limit, true);
