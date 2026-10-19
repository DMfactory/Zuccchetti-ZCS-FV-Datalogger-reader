<?php
// Da cron, ogni minuto:   * * * * * /usr/bin/php /percorso/poller.php
// A ogni avvio resta attivo DURATA secondi, legge la chiavetta ogni INTERVALLO secondi,
// aggiorna data.json (pagina live) e salva una riga nello storico ogni DB_OGNI secondi.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo da riga di comando\n"); }

require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

function crc16(string $d): string {
    $crc = 0xFFFF;
    for ($i = 0; $i < strlen($d); $i++) {
        $crc ^= ord($d[$i]);
        for ($j = 0; $j < 8; $j++) $crc = ($crc & 1) ? (($crc >> 1) ^ 0xA001) : ($crc >> 1);
    }
    return pack('v', $crc);
}

function readExact($fp, int $n): string {
    $buf = '';
    while (strlen($buf) < $n) {
        $c = fread($fp, $n - strlen($buf));
        if ($c === '' || $c === false) throw new Exception('Timeout in lettura');
        $buf .= $c;
    }
    return $buf;
}

function leggi($fp, int $start, int $count): array {
    $mb = pack('CCnn', SLAVE_ID, 3, $start, $count);
    $mb .= crc16($mb);
    $payload = "\x02" . str_repeat("\0", 14) . $mb;
    $body = "\xA5" . pack('v', strlen($payload)) . "\x10\x45"
          . pack('v', random_int(1, 250)) . pack('V', LOGGER_SN) . $payload;
    fwrite($fp, $body . chr(array_sum(unpack('C*', substr($body, 1))) & 0xFF) . "\x15");

    for ($i = 0; $i < 4; $i++) {
        $h = readExact($fp, 11);
        $len = unpack('v', substr($h, 1, 2))[1];
        $rest = readExact($fp, $len + 2);
        if (substr($h, 3, 2) !== "\x10\x15") continue;
        $mbr = substr($rest, 14, $len - 14);
        if (strlen($mbr) < 5 || ord($mbr[1]) !== 3) throw new Exception('Risposta Modbus non valida');
        return array_values(unpack('n*', substr($mbr, 3, ord($mbr[2]))));
    }
    throw new Exception('Nessuna risposta valida');
}

function leggiTutto(array $registri): array {
    $fp = @stream_socket_client('tcp://' . LOGGER_IP . ':8899', $e, $es, 5);
    if (!$fp) throw new Exception("Connessione fallita: $es");
    stream_set_timeout($fp, 5);
    $out = [];
    try {
        foreach ($registri as $nome => [$addr, $n, $div, $signed]) {
            $w = leggi($fp, $addr, $n);
            $v = ($n === 2) ? (($w[0] << 16) | $w[1]) : $w[0];
            if ($signed && $n === 1 && $v > 32767) $v -= 65536;
            $out[$nome] = round($v / $div, 2);
            usleep(200000);
        }
    } finally { fclose($fp); }
    $out['rete']    *= SEGNO_RETE;
    $out['batt_kw'] *= SEGNO_BATT;
    return $out;
}

function salvaJson(array $dati): void {
    $tmp = FILE_DATI . '.tmp';
    file_put_contents($tmp, json_encode($dati));
    rename($tmp, FILE_DATI);          // scrittura atomica
}

function salvaDb(PDO $pdo, int $ts, array $d): void {
    $k = array_keys($d);
    $sql = 'INSERT INTO misure (ts, ' . implode(',', $k) . ') VALUES (?' . str_repeat(',?', count($k)) . ')';
    $pdo->prepare($sql)->execute(array_merge([$ts], array_values($d)));
}

// Evita sovrapposizioni tra due avvii di cron (la chiavetta accetta un solo client)
$lock = fopen(FILE_LOCK, 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit(0);

// Database (se non va, la pagina live continua a funzionare)
$pdo = null;
$ultimoDb = 0;
try {
    $pdo = db();
    $ultimoDb = (int)$pdo->query('SELECT COALESCE(MAX(ts), 0) FROM misure')->fetchColumn();
    if (CONSERVA_GIORNI > 0) {
        $pdo->prepare('DELETE FROM misure WHERE ts < ?')->execute([time() - CONSERVA_GIORNI * 86400]);
    }
} catch (Throwable $e) {
    fwrite(STDERR, date('H:i:s') . " Errore database: " . $e->getMessage() . "\n");
    $pdo = null;
}

$ultimo = ['ok' => false, 'd' => null, 'ts' => 0];
$inizio = microtime(true);

while (true) {
    $t0 = microtime(true);
    try {
        $ultimo = ['ok' => true, 'd' => leggiTutto($REGISTRI), 'ts' => time()];
    } catch (Exception $e) {
        $ultimo['ok'] = false;                        // mantiene l'ultimo valore buono
        $ultimo['errore'] = $e->getMessage();
        fwrite(STDERR, date('H:i:s') . " Errore lettura: " . $e->getMessage() . "\n");
    }

    if ($ultimo['d'] !== null) salvaJson($ultimo);

    if ($pdo && $ultimo['ok'] && $ultimo['ts'] - $ultimoDb >= DB_OGNI - 2) {
        try {
            salvaDb($pdo, $ultimo['ts'], $ultimo['d']);
            $ultimoDb = $ultimo['ts'];
        } catch (Throwable $e) {
            fwrite(STDERR, date('H:i:s') . " Errore salvataggio: " . $e->getMessage() . "\n");
        }
    }

    $prossimo = $t0 + INTERVALLO;
    if ($prossimo - $inizio > DURATA) break;          // il prossimo giro lo fa cron
    $attesa = $prossimo - microtime(true);
    if ($attesa > 0) usleep((int)($attesa * 1e6));
}
