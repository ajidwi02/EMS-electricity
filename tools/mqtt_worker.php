<?php
/**
 * EMS MQTT Worker (M2) — recorder EMQX → ems.readings.
 * Spec: plans/ems-m2-mqtt-worker.md (landasan plans/ems-database-schema.md §5, Pendekatan A).
 * PHP 7.4+, tanpa dependensi eksternal. Mode: (default) listen, --probe N, --flush N, --help.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '0');

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Script ini hanya boleh dijalankan via CLI (php tools/mqtt_worker.php).\n");
    exit(1);
}

define('ENVIRONMENT', 'development');

$EMS_OPTS = array();
$argvs = array_slice($argv, 1);
for ($i = 0; $i < count($argvs); $i++) {
    $a = $argvs[$i];
    if ($a === '--help' || $a === '-h') {
        $EMS_OPTS['help'] = TRUE;
        continue;
    }
    if (preg_match('#^--(probe|flush)(?:=(\d+))?$#', $a, $m)) {
        if (isset($m[2])) {
            $EMS_OPTS[$m[1]] = (int) $m[2];
            continue;
        }
        if (isset($argvs[$i + 1]) && ctype_digit($argvs[$i + 1])) {
            $EMS_OPTS[$m[1]] = (int) $argvs[$i + 1];
            $i++;
            continue;
        }
        $EMS_OPTS[$m[1]] = FALSE;
    }
}
if (isset($EMS_OPTS['help'])) {
    echo "EMS MQTT Worker (M2)\n"
    . "Usage:\n"
    . "  php tools/mqtt_worker.php               Listen normal (recorder produksi)\n"
    . "  php tools/mqtt_worker.php --probe 12    Diagnostik baca-saja 12 dtk (tanpa DB)\n"
    . "  php tools/mqtt_worker.php --flush 75    Window + flush + laporan (uji tulis DB)\n"
    . "  (bentuk --probe=12 / --flush=75 juga didukung)\n"
    . "  php tools/mqtt_worker.php --help        Bantuan\n";
    exit(0);
}

define('EMS_TZ', 'Asia/Jakarta');
date_default_timezone_set(EMS_TZ);
$EMS_TZ = new DateTimeZone(EMS_TZ);

define('BASEPATH', realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);

final class MqttClient
{
    private $host;
    private $port;
    private $user;
    private $pass;
    private $sock = NULL;

    public function __construct($host, $port, $user, $pass)
    {
        $this->host = (string) $host;
        $this->port = (int) $port;
        $this->user = (string) $user;
        $this->pass = (string) $pass;
    }

    public function connect()
    {
        $sock = @stream_socket_client('tcp://' . $this->host . ':' . $this->port, $errno, $errstr, 5);
        if ($sock === FALSE) {
            throw new RuntimeException('MQTT TCP connect gagal (' . (int) $errno . '): ' . $errstr);
        }
        $this->sock = $sock;
        stream_set_timeout($sock, 5);

        $flags = 0x02;
        $pl = '';
        $cid = 'ems-w-' . substr(md5(getmypid() . microtime(true) . mt_rand()), 0, 10);
        $pl .= pack('n', strlen($cid)) . $cid;
        if ($this->user !== '') {
            $flags |= 0x80;
            $pl .= pack('n', strlen($this->user)) . $this->user;
        }
        if ($this->user !== '' && $this->pass !== '') {
            $flags |= 0x40;
            $pl .= pack('n', strlen($this->pass)) . $this->pass;
        }
        $vh = "\x00\x04MQTT" . "\x04" . chr($flags) . pack('n', 60);
        fwrite($sock, $this->frame(0x10, $vh . $pl));

        $ack = $this->readn(4);
        if ($ack === NULL || ord($ack[0]) !== 0x20 || ord($ack[3]) !== 0x00) {
            $this->close();
            throw new RuntimeException('CONNACK gagal/invalid (rc=' . ($ack === NULL ? 'timeout' : ord($ack[3])) . ')');
        }
        stream_set_timeout($sock, 1);
        return TRUE;
    }

    public function subscribe($topic, $qos = 0)
    {
        $body = pack('n', 1) . pack('n', strlen($topic)) . (string) $topic . chr($qos);
        fwrite($this->sock, $this->frame(0x82, $body));
        $ack = $this->readn(5);
        if ($ack === NULL || ord($ack[0]) !== 0x90) {
            $this->close();
            throw new RuntimeException('SUBACK gagal/invalid');
        }
        return TRUE;
    }

    public function ping()
    {
        fwrite($this->sock, "\xC0\x00");
        return TRUE;
    }

    public function readPacket()
    {
        if (!is_resource($this->sock)) {
            throw new RuntimeException('socket MQTT tidak tersedia');
        }
        $r = array($this->sock);
        $w = NULL;
        $e = NULL;
        $n = @stream_select($r, $w, $e, 0, 250000);
        if ($n === FALSE) {
            throw new RuntimeException('stream_select gagal');
        }
        if ($n === 0) {
            return NULL;
        }
        $hdr = $this->readn(1);
        if ($hdr === NULL) {
            return NULL;
        }
        $type = ord($hdr) >> 4;
        $len = $this->varint();
        if ($len === NULL) {
            return NULL;
        }
        $body = '';
        if ($len > 0) {
            $body = $this->readn($len);
            if ($body === NULL) {
                return NULL;
            }
        }
        return array($type, $body);
    }

    public function close()
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
        $this->sock = NULL;
    }

    private function readn($len)
    {
        $buf = '';
        while (strlen($buf) < $len) {
            $chunk = fread($this->sock, $len - strlen($buf));
            if ($chunk === FALSE || $chunk === '') {
                if (feof($this->sock)) {
                    $this->close();
                    throw new RuntimeException('koneksi MQTT putus saat membaca');
                }
                return NULL;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function varint()
    {
        $mul = 0;
        $val = 0;
        do {
            $b = $this->readn(1);
            if ($b === NULL) {
                return NULL;
            }
            $byte = ord($b);
            $val |= ($byte & 0x7F) << $mul;
            $mul += 7;
        } while ($byte & 0x80);
        return $val;
    }

    private function frame($byte1, $body)
    {
        $len = strlen($body);
        $enc = '';
        do {
            $d = $len % 128;
            $len = (int) ($len / 128);
            if ($len > 0) {
                $d |= 0x80;
            }
            $enc .= chr($d);
        } while ($len > 0);
        return chr($byte1) . $enc . $body;
    }
}

final class MqttRecorder
{
    private $stmt;
    public $db;
    public $panels = array();
    private $buffer = array();
    private $metricsKeys = array('v_r', 'v_s', 'v_t', 'v_rs', 'v_st', 'v_rt', 'i_r', 'i_s', 'i_t', 'pf', 'kw', 'kwh');
    private $upsertSql = 'INSERT INTO `readings`'
        . ' (`panel_id`, `recorded_at`, `terminal_time`,'
        . '  `v_r`, `v_s`, `v_t`, `v_rs`, `v_st`, `v_rt`,'
        . '  `i_r`, `i_s`, `i_t`, `pf`, `kw`, `kwh`)'
        . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        . ' ON DUPLICATE KEY UPDATE'
        . '  `terminal_time` = VALUES(`terminal_time`),'
        . '  `v_r`  = VALUES(`v_r`),'
        . '  `v_s`  = VALUES(`v_s`),'
        . '  `v_t`  = VALUES(`v_t`),'
        . '  `v_rs` = VALUES(`v_rs`),'
        . '  `v_st` = VALUES(`v_st`),'
        . '  `v_rt` = VALUES(`v_rt`),'
        . '  `i_r`  = VALUES(`i_r`),'
        . '  `i_s`  = VALUES(`i_s`),'
        . '  `i_t`  = VALUES(`i_t`),'
        . '  `pf`   = VALUES(`pf`),'
        . '  `kw`   = VALUES(`kw`),'
        . '  `kwh`  = VALUES(`kwh`)';
    public $counters = array('msg' => 0, 'kept' => 0, 'oversize' => 0, 'bad_json' => 0);

    public function connectDb()
    {
        $dbFile = __DIR__ . '/../application/config/database.php';
        if (!is_file($dbFile)) {
            throw new RuntimeException('application/config/database.php tidak ditemukan (' . $dbFile . ')');
        }
        require $dbFile;
        $cfg = isset($db['default']) && is_array($db['default']) ? $db['default'] : NULL;
        if ($cfg === NULL || (string) $cfg['username'] === '' || (string) $cfg['database'] === '') {
            throw new RuntimeException('database.php belum dikonfigurasi (username/database kosong)');
        }
        $this->db = @new mysqli(
            (string) $cfg['hostname'],
            (string) $cfg['username'],
            (string) $cfg['password'],
            (string) $cfg['database']
        );
        if ($this->db->connect_error) {
            throw new RuntimeException('DB connect gagal: ' . $this->db->connect_error);
        }
        if (!$this->db->set_charset(isset($cfg['char_set']) ? (string) $cfg['char_set'] : 'utf8mb4')) {
            throw new RuntimeException('set_charset gagal: ' . $this->db->error);
        }
        $stmt = $this->db->prepare($this->upsertSql);
        if ($stmt === FALSE) {
            throw new RuntimeException('prepare upsert gagal: ' . $this->db->error);
        }
        $this->stmt = $stmt;
    }

    public function loadPanels()
    {
        $res = $this->db->query('SELECT id, code FROM panels WHERE is_active = 1');
        if ($res === FALSE) {
            throw new RuntimeException('SELECT panels gagal: ' . $this->db->error);
        }
        $this->panels = array();
        while ($row = $res->fetch_assoc()) {
            $this->panels[strtolower((string) $row['code'])] = (int) $row['id'];
        }
        $res->free();
        return count($this->panels);
    }

    public function bufferCount()
    {
        return count($this->buffer);
    }

    public function onPublish($topic, $payload, DateTimeZone $tz = NULL)
    {
        $this->counters['msg']++;
        if (strlen($payload) > 8192) {
            $this->counters['oversize']++;
            return;
        }
        $data = json_decode($payload, TRUE);
        if (!is_array($data)) {
            $this->counters['bad_json']++;
            return;
        }
        $group = '';
        if (isset($data['_groupName'])) {
            $group = strtolower(trim((string) $data['_groupName']));
        }
        if ($group === '') {
            $segs = explode('/', (string) $topic);
            if (isset($segs[2])) {
                $group = strtolower(trim((string) $segs[2]));
            }
        }
        if ($group === '') {
            $this->counters['bad_json']++;
            return;
        }
        $row = $this->metrics($data);
        if ($tz instanceof DateTimeZone) {
            $now = new DateTime('now', $tz);
            $row['minute'] = $now->format('Y-m-d H:i:00');
        } else {
            $row['minute'] = date('Y-m-d H:i:00');
        }
        $this->buffer[$group] = $row;
        $this->counters['kept']++;
    }

    public function flush(DateTimeZone $tz, $now)
    {
        $buf = $this->buffer;
        $this->buffer = array();
        if (empty($buf)) {
            return array(0, 0, NULL);
        }
        $rows = 0;
        $unknown = 0;
        $drift = NULL;
        foreach ($buf as $group => $b) {
            if (!isset($this->panels[$group])) {
                $unknown++;
                continue;
            }
            $args = array(
                (int) $this->panels[$group],
                isset($b['minute']) ? (string) $b['minute'] : $now->format('Y-m-d H:i:00'),
                $b['terminal'] !== NULL ? (string) $b['terminal'] : NULL,
                $b['v_r'], $b['v_s'], $b['v_t'], $b['v_rs'], $b['v_st'], $b['v_rt'],
                $b['i_r'], $b['i_s'], $b['i_t'], $b['pf'], $b['kw'], $b['kwh'],
            );
            $bindings = $args;
            $refs = array();
            foreach ($bindings as $k => $v) {
                $refs[$k] = &$bindings[$k];
            }
            array_unshift($refs, 'iss' . str_repeat('d', 12));
            call_user_func_array(array($this->stmt, 'bind_param'), $refs);
            if ($this->stmt->execute() === FALSE) {
                fwrite(STDERR, '[' . date('H:i:s') . '] flush gagal panel=' . $group . ' : ' . $this->stmt->error . "\n");
                $this->buffer[$group] = $b;
                continue;
            }
            $rows++;
            if ($drift === NULL && $b['terminal'] !== NULL) {
                $tt = DateTime::createFromFormat('Y-m-d H:i:s.u', (string) $b['terminal'], $tz);
                if ($tt instanceof DateTime) {
                    $drift = (int) round($tt->format('U') - $now->format('U'));
                }
            }
        }
        $this->stmt->reset();
        return array($rows, $unknown, $drift);
    }

    private function metrics($data)
    {
        $row = array(
            'terminal' => isset($data['_terminalTime']) ? (string) $data['_terminalTime'] : NULL,
        );
        foreach ($this->metricsKeys as $key) {
            $val = NULL;
            if ($key === 'kwh') {
                if (isset($data['Kwh'])) {
                    $val = $data['Kwh'];
                } elseif (isset($data['kwh'])) {
                    $val = $data['kwh'];
                }
            } elseif (isset($data[$key])) {
                $val = $data[$key];
            }
            $row[$key] = is_numeric($val) ? (float) $val : NULL;
        }
        if ($row['pf'] !== NULL) {
            $apf = abs($row['pf']);
            if ($apf > 1 && $apf <= 1000) {
                $row['pf'] = round($row['pf'] / 1000, 4);
            } elseif ($apf > 1000) {
                $row['pf'] = NULL;
            }
        }
        return $row;
    }

    public function countersLine()
    {
        return sprintf('msg=%d kept=%d bad_json=%d oversize=%d', $this->counters['msg'], $this->counters['kept'], $this->counters['bad_json'], $this->counters['oversize']);
    }
}

function ems_say($line)
{
    if (defined('EMS_SAY_TZ') === FALSE) {
        define('EMS_SAY_TZ', TRUE);
    }
    echo '[' . date('H:i:s') . '] ' . $line . "\n";
}

function ems_parse_publish($body)
{
    if (strlen($body) < 4) {
        return NULL;
    }
    $tlen = unpack('n', substr($body, 0, 2))[1];
    if (strlen($body) < 2 + $tlen) {
        return NULL;
    }
    $topic = substr($body, 2, $tlen);
    $payload = substr($body, 2 + $tlen);
    return array($topic, $payload);
}

$ems_db = NULL;
try {
    $emsFile = __DIR__ . '/../application/config/ems.php';
    if (!is_file($emsFile)) {
        throw new RuntimeException('application/config/ems.php tidak ditemukan');
    }
    require $emsFile;
    $host = isset($config['ems_mqtt_host']) ? $config['ems_mqtt_host'] : 'localhost';
    $port = isset($config['ems_mqtt_tcp_port']) ? (int) $config['ems_mqtt_tcp_port'] : 1883;
    $pattern = isset($config['ems_mqtt_topic_pattern']) ? $config['ems_mqtt_topic_pattern'] : 'data/ems/+/+';
    $user = isset($config['ems_mqtt_user']) ? (string) $config['ems_mqtt_user'] : '';
    $pass = isset($config['ems_mqtt_pass']) ? (string) $config['ems_mqtt_pass'] : '';

    $rec = new MqttRecorder();
    $rec->connectDb();
    $nPanels = $rec->loadPanels();
    if ($nPanels === 0) {
        throw new RuntimeException('tidak ada panel aktif di tabel panels');
    }
    ems_say('panel aktif: ' . implode(', ', array_keys($rec->panels)));

    $client = new MqttClient($host, $port, $user, $pass);

    if (array_key_exists('probe', $EMS_OPTS) || array_key_exists('flush', $EMS_OPTS)) {
        $secs = 12;
        $mode = 'probe';
        if (array_key_exists('flush', $EMS_OPTS)) {
            $mode = 'flush';
            $secs = 75;
        }
        if (is_numeric($EMS_OPTS[$mode]) && (int) $EMS_OPTS[$mode] > 0) {
            $secs = (int) $EMS_OPTS[$mode];
        }
        $client->connect();
        $client->subscribe($pattern);
        ems_say($mode . ': jendela ' . $secs . ' s (' . $pattern . ' qos0), host=' . $host . ':' . $port);
        $end = microtime(TRUE) + $secs;
        $shown = FALSE;
        $lastMinute = (new DateTime('now', $EMS_TZ))->format('YmdHi');
        while (microtime(TRUE) < $end) {
            $pk = $client->readPacket();
            if (is_array($pk)) {
                if ($pk[0] === 3) {
                    $msg = ems_parse_publish($pk[1]);
                    if ($msg !== NULL) {
                        $rec->onPublish($msg[0], $msg[1], $EMS_TZ);
                        if (!$shown) {
                            $shown = TRUE;
                            $data = json_decode($msg[1], TRUE);
                            if (is_array($data)) {
                                ems_say('payload-1 topic=' . $msg[0]);
                                ems_say(json_encode($data, JSON_UNESCAPED_SLASHES));
                            }
                        }
                    }
                }
            }
            $cur = (new DateTime('now', $EMS_TZ))->format('YmdHi');
            if ($cur !== $lastMinute) {
                $lastMinute = $cur;
                if ($mode === 'flush') {
                    list($r, $u, $d) = $rec->flush($EMS_TZ, new DateTime('now', $EMS_TZ));
                    ems_say('autoflush menit: rows=' . $r . ' unknown=' . $u . ($d !== NULL ? ' drift=' . $d . 's' : ''));
                }
            }
        }
        ems_say($rec->countersLine());
        if ($mode === 'flush') {
            list($r, $u, $d) = $rec->flush($EMS_TZ, new DateTime('now', $EMS_TZ));
            ems_say('flush-final: rows=' . $r . ' unknown=' . $u . ($d !== NULL ? ' drift=' . $d . 's' : ''));
            $lastRow = $rec->db->query('SELECT COUNT(*) AS total FROM readings');
            $tot = $lastRow->fetch_assoc();
            ems_say('readings.total = ' . (int) $tot['total']);
            $lastRow->free();
            $show = $rec->db->query('SELECT id, panel_id, recorded_at, terminal_time, v_r, v_s, v_t, pf, kw, kwh FROM readings ORDER BY id DESC LIMIT 1');
            if ($show instanceof mysqli_result) {
                $row = $show->fetch_assoc();
                if ($row) {
                    ems_say('sample row: ' . json_encode($row, JSON_UNESCAPED_SLASHES));
                }
                $show->free();
            }
        } else {
            ems_say('probe selesai — TIDAK ada penulisan DB');
        }
        $rec->db->close();
        exit(0);
    }

    $attempt = 0;
    while (TRUE) {
        try {
            if ($attempt === 0) {
                ems_say('mode LISTEN (Ctrl+C untuk stop; crash maksimal kehilangan 1 menit buffer)');
            }
            $client->connect();
            $attempt = 0;
            ems_say("connected $host:$port | subscribe \"$pattern\" qos0");
            $client->subscribe($pattern);
            $lastMinute = (new DateTime('now', $EMS_TZ))->format('YmdHi');
            $lastPing = time();
            while (TRUE) {
                $pk = $client->readPacket();
                if (is_array($pk)) {
                    if ($pk[0] === 3) {
                        $msg = ems_parse_publish($pk[1]);
                        if ($msg !== NULL) {
                            $rec->onPublish($msg[0], $msg[1], $EMS_TZ);
                        }
                    } elseif ($pk[0] !== 13) {
                        continue;
                    }
                }
                if (time() - $lastPing >= 20) {
                    $lastPing = time();
                    $client->ping();
                }
                $cur = (new DateTime('now', $EMS_TZ))->format('YmdHi');
                if ($cur !== $lastMinute) {
                    $lastMinute = $cur;
                    $now = new DateTime('now', $EMS_TZ);
                    list($r, $u, $d) = $rec->flush($EMS_TZ, $now);
                    ems_say('flush ' . $now->format('Y-m-d H:i:s') . ': rows=' . $r
                        . ' buffer_left=' . $rec->bufferCount()
                        . ($u > 0 ? ' unknown=' . $u : '')
                        . ' | ' . $rec->countersLine()
                        . ($d !== NULL ? ' | drift_terminal=' . $d . 's' : ''));
                }
            }
        } catch (Throwable $t) {
            $attempt++;
            $sleep = min(30, 2 * $attempt);
            ems_say('DOWN: ' . $t->getMessage() . ' — reconnect dalam ' . $sleep . ' s');
            $client->close();
            sleep($sleep);
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[FATAL] ' . $e->getMessage() . "\n");
    exit(1);
}
