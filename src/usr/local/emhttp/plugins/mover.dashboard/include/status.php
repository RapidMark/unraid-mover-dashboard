<?php
/* Mover Dashboard: one JSON snapshot of what the mover is doing.
 *
 * Read-only. Sources: the mover's pid file and progress file
 * (/usr/local/emhttp/state/mover.ini, written when "Mover progress" is on),
 * the file lists the mover writes for each share (/tmp/mover-files.*),
 * the files shfs has open (the .partial being written, and what else is read),
 * /proc/diskstats and /proc/meminfo.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

const STATE_DIR = '/var/run/mover.dashboard';
const HISTORY_SECONDS = 900;
const QUEUE_SHOWN = 15;
const WAITING_TTL = 60;

@mkdir(STATE_DIR, 0700, true);
$now = microtime(true);

function read_json($file, $default) {
  $raw = @file_get_contents($file);
  $data = $raw === false ? null : json_decode($raw, true);
  return is_array($data) ? $data : $default;
}

function write_json($file, $data) {
  $tmp = $file.'.'.getmypid();
  if (@file_put_contents($tmp, json_encode($data)) !== false) @rename($tmp, $file);
}

function mib($bytes) {
  return round($bytes / 1048576, 1);
}

function share_cfgs() {
  $shares = [];
  foreach (glob('/boot/config/shares/*.cfg') ?: [] as $cfg) {
    $ini = @parse_ini_file($cfg) ?: [];
    $shares[basename($cfg, '.cfg')] = [
      'use' => $ini['shareUseCache'] ?? 'no',
      'pool' => $ini['shareCachePool'] ?? '',
    ];
  }
  return $shares;
}

// --- mover process and its own progress file -------------------------------

$pid = trim((string)@file_get_contents('/var/run/mover.pid'));
$running = $pid !== '' && ctype_digit($pid) && is_dir("/proc/$pid")
  && str_contains((string)@file_get_contents("/proc/$pid/cmdline"), 'mover');
$progress = $running ? (@parse_ini_file('/usr/local/emhttp/state/mover.ini') ?: null) : null;
$started = $running ? (int)@filemtime('/var/run/mover.pid') : 0;
$global = @parse_ini_file('/boot/config/share.cfg') ?: [];
$array = @parse_ini_file('/var/local/emhttp/var.ini') ?: [];
// md_write_method: 1 = reconstruct ("turbo") write, 0 = read/modify/write, "auto".
$writeMethod = (string)($array['md_write_method'] ?? '');

// --- devices: names from disks.ini, rates from diskstats --------------------

$disks = @parse_ini_file('/var/local/emhttp/disks.ini', true) ?: [];
$devices = [];
foreach ($disks as $d) {
  if (empty($d['device']) || ($d['type'] ?? '') === 'Flash') continue;
  $devices[$d['device']] = ['name' => $d['name'], 'type' => $d['type'] ?? '', 'temp' => $d['temp'] ?? '*'];
}

$counters = [];
foreach (file('/proc/diskstats', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
  $f = preg_split('/\s+/', trim($line));
  if (isset($devices[$f[2]])) $counters[$f[2]] = [(int)$f[5], (int)$f[9], (int)$f[12]];
}

// Rates are measured between two snapshots; a poll that comes too soon after
// the last one (a second browser tab) reuses the last result.
$prev = read_json(STATE_DIR.'/prev.json', []);
$rates = $prev['rates'] ?? [];
$sampled = false;
if (isset($prev['t']) && $now - $prev['t'] >= 1.0) {
  $dt = $now - $prev['t'];
  $rates = [];
  foreach ($counters as $dev => [$rd, $wr, $busy]) {
    $p = $prev['c'][$dev] ?? null;
    if (!$p) continue;
    $rates[$dev] = [
      'read' => max(0, ($rd - $p[0]) * 512 / $dt),
      'write' => max(0, ($wr - $p[1]) * 512 / $dt),
      'busy' => min(100, max(0, ($busy - $p[2]) / ($dt * 10))),
    ];
  }
  $sampled = true;
}

// --- what shfs has open: the file being written, and what else is read ------

$writing = [];
$reading = [];
$positions = [];
foreach (glob('/proc/[0-9]*/comm') ?: [] as $comm) {
  if (trim((string)@file_get_contents($comm)) !== 'shfs') continue;
  $proc = dirname($comm);
  foreach (glob("$proc/fd/*") ?: [] as $fd) {
    $path = @readlink($fd);
    if (!$path || !preg_match('#^/mnt/([^/]+)/#', $path, $m) || $m[1] === 'user' || $m[1] === 'user0') continue;
    $info = (string)@file_get_contents("$proc/fdinfo/".basename($fd));
    $flags = preg_match('/^flags:\s+(\d+)/m', $info, $fm) ? octdec($fm[1]) : 0;
    if ($flags & 3) {
      // The copy reserves the full size up front, so the write position, not
      // the file size, is how far it has got.
      $writing[$path] = $m[1];
      $positions[$path] = preg_match('/^pos:\s+(\d+)/m', $info, $pm) ? (int)$pm[1] : 0;
    } else {
      $reading[$path] = $m[1];
    }
  }
}

// --- who has those files open: containers, matched through their mounts -----

// Container names and mounts change rarely; docker is asked once a minute.
function containers($now) {
  $cache = read_json(STATE_DIR.'/containers.json', []);
  if (($cache['t'] ?? 0) >= $now - 60) return $cache['list'];
  $list = [];
  $ids = preg_replace('/\s+/', ' ', trim((string)@shell_exec('docker ps -q --no-trunc 2>/dev/null')));
  if ($ids !== '') {
    $fmt = '{{.Id}}|{{.Name}}|{{range .Mounts}}{{.Destination}}={{.Source}};{{end}}';
    foreach (explode("\n", trim((string)@shell_exec('docker inspect --format '.escapeshellarg($fmt).' '.$ids.' 2>/dev/null'))) as $line) {
      [$id, $name, $mounts] = array_pad(explode('|', $line, 3), 3, '');
      $map = [];
      foreach (array_filter(explode(';', $mounts)) as $m) {
        [$dst, $src] = array_pad(explode('=', $m, 2), 2, '');
        if (preg_match('#^/mnt/#', $src)) $map[rtrim($dst, '/')] = rtrim($src, '/');
      }
      uksort($map, fn($a, $b) => strlen($b) <=> strlen($a));
      $name = ltrim($name, '/');
      $icon = "/usr/local/emhttp/state/plugins/dynamix.docker.manager/images/$name-icon.png";
      $list[$id] = [
        'name' => $name,
        'icon' => is_file($icon) ? "/state/plugins/dynamix.docker.manager/images/$name-icon.png" : null,
        'mounts' => $map,
      ];
    }
  }
  write_json(STATE_DIR.'/containers.json', ['t' => $now, 'list' => $list]);
  return $list;
}

// Share-relative path ("Movies/H/Hugo.mkv") => the container using it. A
// suspended process (a paused job) is skipped: it holds the file but reads nothing.
$owners = [];
$stopped = [];
$containers = containers($now);
if ($containers) {
  foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $proc) {
    $cg = (string)@file_get_contents("$proc/cgroup");
    if (!preg_match('#docker[-/]([0-9a-f]{64})#', $cg, $m) || !isset($containers[$m[1]])) continue;
    $c = $containers[$m[1]];
    if (!$c['mounts']) continue;
    $stat = (string)@file_get_contents("$proc/stat");
    $suspended = preg_match('/\) ([A-Z]) /', $stat, $sm) && $sm[1] === 'T';
    foreach (glob("$proc/fd/*") ?: [] as $fd) {
      $path = @readlink($fd);
      if (!$path || $path[0] !== '/') continue;
      foreach ($c['mounts'] as $dst => $src) {
        if ($path !== $dst && !str_starts_with($path, $dst.'/')) continue;
        $host = $src.substr($path, strlen($dst));
        $rel = preg_replace('#^/mnt/[^/]+/#', '', $host);
        if ($suspended) $stopped[$rel] = true;
        else $owners[$rel] ??= ['name' => $c['name'], 'icon' => $c['icon']];
        break;
      }
    }
  }
}

function owner_of($path, $owners) {
  global $source;
  $rel = preg_replace('#^/mnt/[^/]+/#', '', $path);
  if (str_ends_with($rel, '.partial') || $path === $source) return ['name' => 'mover', 'icon' => null, 'mover' => true];
  return $owners[$rel] ?? null;
}

// --- the file being moved ---------------------------------------------------

$current = null;
$source = $progress['File'] ?? null;
$partial = null;
foreach ($writing as $path => $disk) {
  if (!str_ends_with($path, '.partial')) continue;
  $rel = preg_replace('#^/mnt/[^/]+/#', '', substr($path, 0, -8));
  if ($source && !str_ends_with($source, '/'.$rel)) continue;
  $partial = ['path' => $path, 'disk' => $disk, 'rel' => $rel];
  break;
}
if (!$source && $partial) {
  foreach (share_cfgs() as $cfg) {
    if ($cfg['pool'] && is_file("/mnt/{$cfg['pool']}/{$partial['rel']}")) {
      $source = "/mnt/{$cfg['pool']}/{$partial['rel']}";
      break;
    }
  }
}
if ($running && ($source || $partial)) {
  clearstatcache();
  $size = $source ? (int)@filesize($source) : 0;
  // shfs copies with positional writes, so the descriptor's position only
  // moves now and then. Progress is the position when the file was first seen
  // plus what its disk has written since: right while the mover is the only
  // writer there, and it counts data on disk, not data still in memory.
  $dev = null;
  foreach ($devices as $d => $info) if ($partial && $info['name'] === $partial['disk']) $dev = $d;
  $track = read_json(STATE_DIR.'/track.json', []);
  $key = $partial['path'] ?? $source;
  $pos = $partial ? ($positions[$partial['path']] ?? 0) : 0;
  $written = $dev ? ($counters[$dev][1] ?? 0) * 512 : 0;
  if (!isset($track['key']) || $track['key'] !== $key) {
    $track = ['key' => $key, 'pos' => $pos, 'written' => $written];
    write_json(STATE_DIR.'/track.json', $track);
  }
  $copied = min($size ?: PHP_INT_MAX, max($pos, $track['pos'] + max(0, $written - $track['written'])));
  $fileRate = $dev ? ($rates[$dev]['write'] ?? null) : null;
  $current = [
    'source' => $source,
    'rel' => $partial['rel'] ?? preg_replace('#^/mnt/[^/]+/#', '', (string)$source),
    'disk' => $partial['disk'] ?? null,
    'size' => $size,
    'copied' => $copied,
    'percent' => $size > 0 ? round(min(100, $copied / $size * 100), 1) : null,
    'rate' => $fileRate,
    'eta' => $fileRate > 0 && $size > $copied ? (int)(($size - $copied) / $fileRate) : null,
  ];
}

// --- the queue: the mover's own file list for the share it is on ------------

$queue = ['share' => null, 'next' => [], 'remainingFiles' => 0, 'remainingBytes' => 0];
if ($running) {
  $lists = glob('/tmp/mover-files.*') ?: [];
  usort($lists, fn($a, $b) => filemtime($b) <=> filemtime($a));
  $list = $lists[0] ?? null;
  if ($list) {
    $paths = file($list, FILE_IGNORE_NEW_LINES) ?: [];
    $at = $source ? array_search($source, $paths, true) : false;
    $start = $at === false ? 0 : $at + 1;
    if (preg_match('#^/mnt/[^/]+/([^/]+)#', $paths[0] ?? '', $sm)) $queue['share'] = $sm[1];
    for ($i = $start, $n = count($paths); $i < $n; $i++) {
      $p = $paths[$i];
      if (!is_file($p)) continue;
      $size = (int)@filesize($p);
      $queue['remainingFiles']++;
      $queue['remainingBytes'] += $size;
      if (count($queue['next']) < QUEUE_SHOWN) {
        $queue['next'][] = ['rel' => preg_replace('#^/mnt/[^/]+/#', '', $p), 'size' => $size];
      }
    }
  }
}

// --- what is waiting on the pools for the mover (cached, it walks trees) ----

$waiting = read_json(STATE_DIR.'/waiting.json', []);
if (($waiting['t'] ?? 0) < $now - WAITING_TTL) {
  $waiting = ['t' => $now, 'shares' => []];
  foreach (share_cfgs() as $name => $cfg) {
    if ($cfg['use'] !== 'yes' || !$cfg['pool'] || !is_dir("/mnt/{$cfg['pool']}/$name")) continue;
    $files = 0;
    $bytes = 0;
    $it = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator("/mnt/{$cfg['pool']}/$name", FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::LEAVES_ONLY,
      RecursiveIteratorIterator::CATCH_GET_CHILD
    );
    foreach ($it as $file) {
      if (!$file->isFile()) continue;
      $files++;
      $bytes += $file->getSize();
    }
    $waiting['shares'][] = ['share' => $name, 'pool' => $cfg['pool'], 'files' => $files, 'bytes' => $bytes];
  }
  write_json(STATE_DIR.'/waiting.json', $waiting);
}

// --- memory still to be written ---------------------------------------------

$mem = [];
foreach (file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
  if (preg_match('/^(Dirty|Writeback):\s+(\d+) kB/', $line, $m)) $mem[strtolower($m[1])] = (int)$m[2] * 1024;
}
$vm = [];
foreach (['dirty_background_bytes', 'dirty_bytes', 'dirty_background_ratio', 'dirty_ratio'] as $k) {
  $vm[$k] = (int)trim((string)@file_get_contents("/proc/sys/vm/$k"));
}

// --- per-device rows and the history chart ----------------------------------

$rows = [];
$parityWrite = 0;
$arrayWrite = 0;
foreach ($devices as $dev => $info) {
  $r = $rates[$dev] ?? ['read' => 0, 'write' => 0, 'busy' => 0];
  if ($info['type'] === 'Parity') $parityWrite = max($parityWrite, $r['write']);
  if ($info['type'] === 'Data') $arrayWrite += $r['write'];
  $readers = [];
  foreach ($reading as $path => $disk) {
    if ($disk !== $info['name']) continue;
    $by = owner_of($path, $owners);
    // A file only a suspended process holds is not being read.
    if (!$by && isset($stopped[preg_replace('#^/mnt/[^/]+/#', '', $path)])) continue;
    $readers[] = ['rel' => preg_replace('#^/mnt/[^/]+/#', '', $path), 'by' => $by];
  }
  $rows[] = [
    'name' => $info['name'],
    'device' => $dev,
    'type' => $info['type'],
    'temp' => $info['temp'],
    'read' => $r['read'],
    'write' => $r['write'],
    'busy' => round($r['busy']),
    'rotational' => trim((string)@file_get_contents("/sys/block/$dev/queue/rotational")) === '1',
    'writing' => array_values(array_map(
      fn($p) => ['rel' => preg_replace('#^/mnt/[^/]+/#', '', $p), 'by' => owner_of($p, $owners)],
      array_keys(array_filter($writing, fn($d) => $d === $info['name']))
    )),
    'readers' => array_slice($readers, 0, 5),
  ];
}

$history = read_json(STATE_DIR.'/history.json', []);
if ($sampled) {
  $history[] = [
    't' => round($now, 1),
    'parity' => mib($parityWrite),
    'data' => mib($arrayWrite),
    'file' => $current['rel'] ?? '',
  ];
  $history = array_values(array_filter($history, fn($h) => $h['t'] >= $now - HISTORY_SECONDS));
  write_json(STATE_DIR.'/history.json', $history);
}

if ($sampled || !isset($prev['t'])) write_json(STATE_DIR.'/prev.json', ['t' => $now, 'c' => $counters, 'rates' => $rates]);

echo json_encode([
  'now' => $now,
  'running' => $running,
  'pid' => $running ? (int)$pid : null,
  'started' => $started ?: null,
  'progressEnabled' => ($global['shareMoverProgress'] ?? 'no') === 'yes',
  'turbo' => $writeMethod === '1' ? true : ($writeMethod === '0' ? false : null),
  'progress' => $progress ? [
    'total' => (int)($progress['TotalSize'] ?? 0),
    'remain' => (int)($progress['RemainSize'] ?? 0),
    'rate' => (int)($progress['Throughput'] ?? 0),
    'eta' => (int)($progress['ETA'] ?? 0),
    'shareTotal' => (int)($progress['ShareTotal'] ?? 0),
    'shareRemain' => (int)($progress['ShareRemain'] ?? 0),
    'shareRate' => (int)($progress['ShareThroughput'] ?? 0),
    'shareEta' => (int)($progress['ShareETA'] ?? 0),
    'action' => $progress['Action'] ?? '',
  ] : null,
  'current' => $current,
  'queue' => $queue,
  'waiting' => $waiting['shares'],
  'waitingAt' => $waiting['t'],
  'memory' => $mem + ['vm' => $vm],
  'devices' => $rows,
  'history' => $history,
], JSON_UNESCAPED_SLASHES);
