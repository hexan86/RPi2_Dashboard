<?php
// stats.php - restituisce contatori grezzi in JSON. I calcoli (percentuali, velocità) li fa la pagina.
ini_set('display_errors', '0');   // gli avvisi PHP non devono finire dentro il JSON
error_reporting(0);
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rd($f) { return @file_get_contents($f); }
function num($f) { $v = rd($f); return $v === false ? null : (float)trim($v); }

$vcPath = null;
foreach (['/usr/bin/vcgencmd', '/opt/vc/bin/vcgencmd'] as $p) {
    if (is_executable($p)) { $vcPath = $p; break; }
}
function vc($args) {
    global $vcPath;
    if (!$vcPath || !function_exists('shell_exec')) return null;
    $o = @shell_exec($vcPath . ' ' . $args . ' 2>/dev/null');
    return $o === null ? null : trim($o);
}
function vcval($args) {
    $o = vc($args);
    if ($o === null || strpos($o, '=') === false) return null;
    return trim(explode('=', $o, 2)[1]);
}

$out = ['now' => microtime(true)];

// Fuso orario del Raspberry (per mostrare ora e data corrette)
$tz = trim((string)rd('/etc/timezone'));
$out['tz'] = $tz !== '' ? $tz : date_default_timezone_get();

// Sistema
$os = 'Linux';
if (preg_match('/^PRETTY_NAME="?([^"\n]+)"?/m', (string)rd('/etc/os-release'), $m)) $os = $m[1];
$model = trim(str_replace("\0", '', (string)rd('/proc/device-tree/model')));
$out['sys'] = [
    'host'   => gethostname(),
    'os'     => $os,
    'kernel' => php_uname('r'),
    'model'  => $model !== '' ? $model : 'Raspberry Pi',
    'ip'     => $_SERVER['SERVER_ADDR'] ?? '',
    'uptime' => num('/proc/uptime') !== null ? (float)explode(' ', trim(rd('/proc/uptime')))[0] : null,
    'procs'  => count(glob('/proc/[0-9]*') ?: []),
];

// CPU: jiffies cumulativi totale/idle per "cpu" e ogni core
$cpu = [];
foreach (explode("\n", (string)rd('/proc/stat')) as $l) {
    if (preg_match('/^(cpu\d*)\s+(.*)$/', $l, $m)) {
        $v = array_map('intval', preg_split('/\s+/', trim($m[2])));
        $cpu[$m[1]] = [array_sum(array_slice($v, 0, 8)), $v[3] + ($v[4] ?? 0)];
    }
}
$out['cpu'] = $cpu;
$out['load'] = sys_getloadavg();
$out['freq'] = [
    'cur' => num('/sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq'),
    'max' => num('/sys/devices/system/cpu/cpu0/cpufreq/cpuinfo_max_freq'),
];
$t = num('/sys/class/thermal/thermal_zone0/temp');
$out['temp'] = $t !== null ? $t / 1000 : null;

// RAM e swap (kB)
$mem = [];
foreach (explode("\n", (string)rd('/proc/meminfo')) as $l) {
    if (preg_match('/^(\w+):\s+(\d+)/', $l, $m)) $mem[$m[1]] = (int)$m[2] * 1024;
}
$out['mem'] = [
    'total' => $mem['MemTotal'] ?? 0,
    'free'  => $mem['MemFree'] ?? 0,
    'avail' => $mem['MemAvailable'] ?? 0,
    'buf'   => $mem['Buffers'] ?? 0,
    'cache' => ($mem['Cached'] ?? 0) + ($mem['SReclaimable'] ?? 0),
    'swapTotal' => $mem['SwapTotal'] ?? 0,
    'swapFree'  => $mem['SwapFree'] ?? 0,
];

// GPU (VideoCore IV) via vcgencmd
$gpuMem = vcval('get_mem gpu');
$coreClk = vcval('measure_clock core');
$v3dClk = vcval('measure_clock v3d');
$volt = vcval('measure_volts core');
$thr = vcval('get_throttled');
$out['gpu'] = [
    'available' => $vcPath !== null && $coreClk !== null,
    'mem'    => $gpuMem !== null ? (int)$gpuMem : null,          // MB
    'core'   => $coreClk !== null ? (float)$coreClk : null,      // Hz
    'v3d'    => $v3dClk !== null ? (float)$v3dClk : null,        // Hz
    'volt'   => $volt !== null ? (float)$volt : null,            // V
    'throttled' => $thr !== null ? hexdec($thr) : null,
];

// Disco: mount reali
$diskstats = [];
foreach (explode("\n", (string)rd('/proc/diskstats')) as $l) {
    $p = preg_split('/\s+/', trim($l));
    if (count($p) >= 10) $diskstats[$p[2]] = [(float)$p[5], (float)$p[9]]; // settori letti, scritti
}
$disks = [];
$seen = [];
foreach (explode("\n", (string)rd('/proc/mounts')) as $l) {
    $p = preg_split('/\s+/', trim($l));
    if (count($p) < 3 || strpos($p[0], '/dev/') !== 0) continue;
    [$dev, $mnt, $fs] = $p;
    if (in_array($fs, ['squashfs', 'iso9660'])) continue;
    if (isset($seen[$dev]) && $mnt !== '/') continue;
    $mnt = str_replace(['\040'], [' '], $mnt);
    $total = @disk_total_space($mnt);
    $free  = @disk_free_space($mnt);
    if (!$total) continue;
    $name = basename($dev);
    // Sistema = root e partizioni di boot; storage = tutto il resto
    $isSystem = ($mnt === '/' || strpos($mnt, '/boot') === 0);
    $seen[$dev] = true;
    $disks[] = [
        'dev' => $dev, 'mnt' => $mnt, 'fs' => $fs,
        'total' => $total, 'free' => $free,
        'system' => $isSystem,
        'io' => $diskstats[$name] ?? null,
    ];
}
$out['disks'] = $disks;

// Rete
$net = [];
foreach (array_slice(explode("\n", (string)rd('/proc/net/dev')), 2) as $l) {
    if (!preg_match('/^\s*([^:]+):\s*(.*)$/', $l, $m)) continue;
    $if = $m[1];
    if ($if === 'lo' || strpos($if, 'veth') === 0 || strpos($if, 'docker') === 0) continue;
    $v = preg_split('/\s+/', trim($m[2]));
    $net[$if] = [
        'rx' => (float)$v[0], 'rxp' => (float)$v[1], 'rxe' => (float)$v[2] + (float)$v[3],
        'tx' => (float)$v[8], 'txp' => (float)$v[9], 'txe' => (float)$v[10] + (float)$v[11],
        'up' => trim((string)rd("/sys/class/net/$if/operstate")) === 'up',
        'speed' => num("/sys/class/net/$if/speed"),
    ];
}
$out['net'] = $net;

// Connessioni TCP stabilite (stato 01)
$est = 0;
foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $f) {
    foreach (array_slice(explode("\n", (string)rd($f)), 1) as $l) {
        $p = preg_split('/\s+/', trim($l));
        if (isset($p[3]) && $p[3] === '01') $est++;
    }
}
$out['tcp'] = $est;

// Utenti connessi: SSH/console (comando who) e, se configurato, Samba
$users = [];
@date_default_timezone_set($out['tz']);
function sessionSrc($line) {
    if (strpos($line, 'pts/') === 0) return 'SSH';
    if (strpos($line, 'tty') === 0 || strpos($line, 'seat') === 0) return 'Console';
    if ($line === 'cockpit') return 'Cockpit';
    return $line;
}
// 0) sessioni di systemd-logind (SSH, Cockpit, console): file di testo, nessuna dipendenza da shell_exec
foreach (glob('/run/systemd/sessions/*') ?: [] as $f) {
    if (strpos(basename($f), '.') !== false) continue;   // salta i file .ref
    $kv = [];
    foreach (explode("\n", (string)@file_get_contents($f)) as $l) {
        if (strpos($l, '=') !== false) { [$k, $v] = explode('=', $l, 2); $kv[$k] = $v; }
    }
    if (empty($kv['USER']) || (isset($kv['CLASS']) && $kv['CLASS'] !== 'user')) continue;
    $tty = $kv['TTY'] ?? '';
    $host = preg_replace('/^::ffff:/', '', $kv['REMOTE_HOST'] ?? '');
    $users[] = [
        'user'  => $kv['USER'],
        'src'   => sessionSrc($tty !== '' ? $tty : ($kv['SERVICE'] ?? '')),
        'where' => $host !== '' ? $host : ($tty !== '' ? $tty : ($kv['SEAT'] ?? '')),
        'since' => !empty($kv['REALTIME']) ? date('Y-m-d H:i', (int)($kv['REALTIME'] / 1000000)) : '',
    ];
}
// 1) ripiego: utmp (record da 384 byte, tipo 7 = sessione utente), se esiste
$utmp = false;
foreach (['/run/utmp', '/var/run/utmp'] as $f) {
    $utmp = @file_get_contents($f);
    if ($utmp !== false) break;
}
if (!$users && $utmp !== false) {
    for ($o = 0; $o + 384 <= strlen($utmp); $o += 384) {
        if (unpack('v', substr($utmp, $o, 2))[1] !== 7) continue;
        $line = rtrim(substr($utmp, $o + 8, 32), "\0");
        $user = rtrim(substr($utmp, $o + 44, 32), "\0");
        $host = preg_replace('/^::ffff:/', '', rtrim(substr($utmp, $o + 76, 256), "\0"));
        $ts = unpack('V', substr($utmp, $o + 340, 4))[1];
        $users[] = ['user' => $user, 'src' => sessionSrc($line), 'where' => $host !== '' ? $host : $line, 'since' => date('Y-m-d H:i', $ts)];
    }
}
if (function_exists('shell_exec')) {
    // 2) ripiego: comando who, se utmp non è leggibile
    if (!$users) {
        foreach (explode("\n", (string)@shell_exec('/usr/bin/who 2>/dev/null')) as $l) {
            if (preg_match('/^(\S+)\s+(.+?)\s+(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2})(?:\s+\((.*)\))?\s*$/', $l, $m)) {
                $ln = preg_split('/\s+/', trim($m[2])); $ln = end($ln);
                $host = preg_replace('/^::ffff:/', '', $m[4] ?? '');
                $users[] = ['user' => $m[1], 'src' => sessionSrc($ln), 'where' => $host !== '' ? $host : $ln, 'since' => $m[3]];
            }
        }
    }
    // smbstatus richiede root: funziona solo con la regola sudoers descritta nelle istruzioni
    if (is_executable('/usr/bin/smbstatus')) {
        $go = false;
        foreach (explode("\n", (string)@shell_exec('sudo -n /usr/bin/smbstatus -b 2>/dev/null')) as $l) {
            if (strpos($l, '-----') === 0) { $go = true; continue; }
            if (!$go || trim($l) === '') continue;
            $p = preg_split('/\s+/', trim($l));
            if (count($p) >= 4) $users[] = ['user' => $p[1], 'src' => 'Samba', 'where' => $p[3], 'since' => ''];
        }
    }
}
$out['users'] = $users;

// Porte TCP in ascolto (local = raggiungibile solo da questo dispositivo)
$ports = [];
foreach (['/proc/net/tcp' => 4, '/proc/net/tcp6' => 6] as $f => $ver) {
    foreach (array_slice(explode("\n", (string)rd($f)), 1) as $l) {
        $p = preg_split('/\s+/', trim($l));
        if (count($p) < 4 || $p[3] !== '0A') continue;
        [$addr, $hp] = explode(':', $p[1]);
        $port = hexdec($hp);
        $loc = ($ver === 4 && substr($addr, -2) === '7F') || ($ver === 6 && $addr === '00000000000000000000000001000000');
        $ports[$port] = ['port' => $port, 'local' => isset($ports[$port]) ? ($ports[$port]['local'] && $loc) : $loc];
    }
}
ksort($ports);
$out['ports'] = array_values($ports);

// Processi: contatori cumulativi (la pagina calcola le percentuali tra due letture)
$procs = [];
foreach (glob('/proc/[0-9]*/stat') ?: [] as $f) {
    $s = @file_get_contents($f);
    if ($s === false) continue;
    $a = strpos($s, '('); $b = strrpos($s, ')');
    if ($a === false || $b === false) continue;
    $r = explode(' ', substr($s, $b + 2));
    $ticks = (int)($r[11] ?? 0) + (int)($r[12] ?? 0);
    $rss = (int)($r[21] ?? 0) * 4096;
    if ($ticks > 0 || $rss >= 2097152) {
        $procs[] = [(int)substr($s, 0, $a), substr($s, $a + 1, $b - $a - 1), $ticks, $rss];
    }
}
$out['procs'] = $procs;

$json = json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
ob_end_clean();
echo $json !== false ? $json : json_encode(['error' => json_last_error_msg()]);
