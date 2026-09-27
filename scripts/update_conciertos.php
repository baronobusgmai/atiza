<?php
/**
 * Atiza — actualización automática de conciertos (Barcelona / AMB)
 * Uso CLI: php update_conciertos.php [--dry-run]
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$DATA = $ROOT . '/data/conciertos.json';
$LEGACY = $ROOT . '/conciertos.json';
$BACKUP_DIR = '/root/atiza-ctrl/backups';
$LOG = '/root/atiza-ctrl/logs/fetch_conciertos.log';

$dry = in_array('--dry-run', $argv ?? [], true);

function logmsg(string $m): void {
    global $LOG;
    $line = date('Y-m-d H:i:s') . ' ' . $m . "\n";
    echo $m . "\n";
    @file_put_contents($LOG, $line, FILE_APPEND);
}

function load_json(string $path): array {
    if (!is_readable($path)) return [];
    $d = json_decode((string)file_get_contents($path), true);
    return is_array($d) ? $d : [];
}

function event_key(array $e): string {
    $t = mb_strtolower(trim($e['titulo'] ?? $e['artista'] ?? ''));
    $f = trim($e['fecha'] ?? '');
    $l = mb_strtolower(trim($e['lugar'] ?? ''));
    return $f . '|' . $t . '|' . $l;
}

function norm_event(array $e): array {
    $titulo = trim($e['titulo'] ?? $e['artista'] ?? '');
    return [
        'fecha'   => trim($e['fecha'] ?? ''),
        'hora'    => trim($e['hora'] ?? '—') ?: '—',
        'titulo'  => $titulo,
        'lugar'   => trim($e['lugar'] ?? ''),
        'ciudad'  => trim($e['ciudad'] ?? 'Barcelona') ?: 'Barcelona',
        'genero'  => trim($e['genero'] ?? 'Música'),
        'nota'    => trim($e['nota'] ?? ''),
        'imagen'  => trim($e['imagen'] ?? ''),
        'url'     => trim($e['url'] ?? ''),
        'critica' => trim($e['critica'] ?? ''),
        'opinion' => trim($e['opinion'] ?? ''),
    ];
}

/** HTTP GET simple */
function http_get(string $url, int $timeout = 20): string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'AtizaCultoAMB/1.0 (+local; concert-agenda)',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) return '';
        return (string)$body;
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => $timeout, 'header' => "User-Agent: AtizaCultoAMB/1.0\r\n"],
        'ssl' => ['verify_peer' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? '' : $body;
}

/**
 * Fuente A: página agenda Barcelona en concerts.cat (HTML → regex tolerante)
 * Si falla, no rompe: devuelve [].
 */
function source_concerts_cat(): array {
    $urls = [
        'https://concerts.cat/es/cities/barcelona',
        'https://concerts.cat/es',
    ];
    $out = [];
    foreach ($urls as $url) {
        $html = http_get($url);
        if ($html === '') {
            logmsg("[src] concerts.cat vacío: $url");
            continue;
        }
        // Patrones amplios: fecha ISO o d/m/Y cerca de títulos
        // Enlaces tipo /es/events/... o tarjetas
        if (preg_match_all(
            '/(\d{4}-\d{2}-\d{2})[^<]{0,80}?(?:href="([^"]+)")?[^<]{0,120}?([A-ZÁÉÍÓÚÑ0-9][^<]{2,80})/u',
            $html,
            $m,
            PREG_SET_ORDER
        )) {
            foreach ($m as $row) {
                $fecha = $row[1];
                $titulo = trim(html_entity_decode(strip_tags($row[3]), ENT_QUOTES, 'UTF-8'));
                $titulo = preg_replace('/\s+/', ' ', $titulo);
                if (mb_strlen($titulo) < 3 || mb_strlen($titulo) > 90) continue;
                if (preg_match('/^(lunes|martes|miércoles|jueves|viernes|sábado|domingo|enero|febrero)/iu', $titulo)) continue;
                $out[] = norm_event([
                    'fecha' => $fecha,
                    'hora' => '—',
                    'titulo' => $titulo,
                    'lugar' => 'Barcelona (concerts.cat)',
                    'ciudad' => 'Barcelona',
                    'genero' => 'Música',
                    'url' => isset($row[2]) && str_starts_with($row[2], 'http') ? $row[2] : '',
                ]);
            }
        }
        logmsg('[src] concerts.cat parseados ~' . count($out) . " desde $url");
        if ($out) break;
    }
    return $out;
}

/**
 * Fuente B: seed curado local (siempre disponible offline)
 * Se fusiona; no sustituye lo que ya tengas.
 */
function source_seed_bcn(): array {
    $seedFile = dirname(__DIR__) . '/data/seed_conciertos_bcn.json';
    if (!is_readable($seedFile)) return [];
    $d = load_json($seedFile);
    logmsg('[src] seed local: ' . count($d) . ' ítems');
    return array_map('norm_event', $d);
}

// ----- main -----
logmsg('=== fetch conciertos start' . ($dry ? ' (dry-run)' : '') . ' ===');

$current = load_json($DATA);
if (!$current) $current = load_json($LEGACY);
logmsg('actuales: ' . count($current));

$incoming = [];
foreach (array_merge(source_seed_bcn(), source_concerts_cat()) as $e) {
    if (($e['fecha'] ?? '') === '' || ($e['titulo'] ?? '') === '') continue;
    $incoming[] = $e;
}
logmsg('entrantes brutos: ' . count($incoming));

$map = [];
foreach ($current as $e) {
    $e = norm_event($e);
    if ($e['fecha'] === '' || $e['titulo'] === '') continue;
    $map[event_key($e)] = $e;
}
$added = 0;
foreach ($incoming as $e) {
    $k = event_key($e);
    if (!isset($map[$k])) {
        $map[$k] = $e;
        $added++;
    } else {
        // Completar huecos (url, genero) sin pisar crítica/opinión humanas
        foreach (['url', 'genero', 'lugar', 'hora'] as $f) {
            if (($map[$k][$f] ?? '') === '' || ($map[$k][$f] ?? '') === '—') {
                if (($e[$f] ?? '') !== '' && ($e[$f] ?? '') !== '—') {
                    $map[$k][$f] = $e[$f];
                }
            }
        }
    }
}

$merged = array_values($map);
usort($merged, fn($a, $b) => strcmp($a['fecha'] . $a['titulo'], $b['fecha'] . $b['titulo']));

logmsg("merge: total=" . count($merged) . " añadidos=$added");

if ($dry) {
    logmsg('dry-run: no se escribe disco');
    exit(0);
}

if (!is_dir($BACKUP_DIR)) @mkdir($BACKUP_DIR, 0755, true);
if (is_readable($DATA)) {
    $bak = $BACKUP_DIR . '/conciertos_' . date('Ymd-His') . '.json';
    @copy($DATA, $bak);
    logmsg("backup: $bak");
}

$json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
if (file_put_contents($DATA, $json) === false) {
    logmsg('ERROR escribiendo ' . $DATA);
    exit(1);
}
@copy($DATA, $LEGACY);
@chmod($DATA, 0644);
@chmod($LEGACY, 0644);
// permisos web si es root
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    @chown($DATA, 'www-data');
    @chown($LEGACY, 'www-data');
}

logmsg('OK escrito ' . $DATA);
logmsg('=== fin ===');
