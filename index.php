<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

$dataDir  = __DIR__ . '/data';
$chatFile = $dataDir . '/chat.json';
if (!is_dir($dataDir)) @mkdir($dataDir, 0775, true);

$RAMAS = [
    'conciertos'  => ['label' => 'Conciertos',         'file' => 'conciertos.json',  'color' => '#22d3ee'],
    'escenicas'   => ['label' => 'Artes escénicas',    'file' => 'escenicas.json',   'color' => '#f472b6'],
    'plasticas'   => ['label' => 'Artes plásticas',    'file' => 'plasticas.json',   'color' => '#a78bfa'],
    'imagen'      => ['label' => 'Imagen',             'file' => 'imagen.json',      'color' => '#fb923c'],
    'tecnologias' => ['label' => 'Nuevas tecnologías', 'file' => 'tecnologias.json', 'color' => '#4ade80'],
    'informatica' => ['label' => 'Informática',        'file' => 'informatica.json', 'color' => '#60a5fa'],
    'foros'       => ['label' => 'Foros especiales',   'file' => 'foros.json',       'color' => '#fbbf24'],
    'fiestas'     => ['label' => 'Fiestas populares',  'file' => 'fiestas.json',     'color' => '#ef4444'],
];

$filtro = $_GET['rama'] ?? 'todas';
if ($filtro !== 'todas' && !isset($RAMAS[$filtro])) $filtro = 'todas';

/* Chat API */
if (isset($_GET['chat_history']) || (($_POST['action'] ?? '') === 'chat')) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=UTF-8');
    if (isset($_GET['chat_history'])) {
        $msgs = is_readable($chatFile) ? (json_decode((string)file_get_contents($chatFile), true) ?: []) : [];
        echo json_encode(['ok' => true, 'messages' => $msgs], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $msg = trim((string)($_POST['message'] ?? ''));
    if ($msg === '') { echo json_encode(['ok'=>false,'error'=>'vacío']); exit; }
    $msgs = is_readable($chatFile) ? (json_decode((string)file_get_contents($chatFile), true) ?: []) : [];
    $user = ['role'=>'user','text'=>mb_substr($msg,0,2000),'ts'=>date('Y-m-d H:i:s')];
    $low = mb_strtolower($msg);
    if (str_contains($low, 'ping')) $reply = 'pong ✓ '.date('H:i:s');
    elseif (str_contains($low, 'ayuda')) $reply = "Lista única con scroll.\nFiltro por color en el menú.\nComandos: ping · cuantos";
    elseif (str_contains($low, 'cuantos') || str_contains($low, 'cuántos')) {
        $n = 0;
        foreach ($RAMAS as $meta) {
            $f = $dataDir.'/'.$meta['file'];
            if (is_readable($f)) {
                $d = json_decode((string)file_get_contents($f), true);
                if (is_array($d)) $n += count($d);
            }
        }
        $reply = "Eventos cargados en total: $n";
    } else $reply = 'Anotado. Escribe ayuda o ping.';
    $bot = ['role'=>'bot','text'=>$reply."\n— bot local",'ts'=>date('Y-m-d H:i:s')];
    $msgs[] = $user; $msgs[] = $bot;
    @file_put_contents($chatFile, json_encode(array_slice($msgs, -80), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    echo json_encode(['ok'=>true,'user'=>$user,'bot'=>$bot], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: text/html; charset=UTF-8');

function tituloDe(array $c): string {
    return trim($c['titulo'] ?? $c['artista'] ?? '');
}
function fmtFecha($ymd) {
    $meses = [1=>'ene',2=>'feb',3=>'mar',4=>'abr',5=>'may',6=>'jun',7=>'jul',8=>'ago',9=>'sep',10=>'oct',11=>'nov',12=>'dic'];
    $dias = ['dom','lun','mar','mié','jue','vie','sáb'];
    $ts = strtotime($ymd);
    if (!$ts) return $ymd;
    return $dias[(int)date('w',$ts)].' '.(int)date('j',$ts).' '.$meses[(int)date('n',$ts)].' '.date('Y',$ts);
}
function esGratis($nota) {
    return $nota !== '' && preg_match('/gratis|free|gratuita/i', $nota);
}
function googleSearchUrl(array $c): string {
    $q = trim(tituloDe($c).' '.($c['lugar']??'').' '.($c['ciudad']??'').' '.($c['fecha']??''));
    return 'https://www.google.com/search?q='.rawurlencode($q);
}
function eventImageUrl(array $c): string {
    $img = trim($c['imagen'] ?? '');
    if ($img !== '') return $img;
    $name = tituloDe($c) ?: 'E';
    return 'https://ui-avatars.com/api/?name='.rawurlencode($name).'&size=128&background=1a2332&color=5eead4&bold=true&format=png';
}

/* Cargar TODOS los eventos de todas las ramas */
$todos = [];
foreach ($RAMAS as $key => $meta) {
    $f = $dataDir . '/' . $meta['file'];
    if ($key === 'conciertos' && !is_readable($f) && is_readable(__DIR__.'/conciertos.json')) {
        $f = __DIR__.'/conciertos.json';
    }
    if (!is_readable($f)) continue;
    $data = json_decode((string)file_get_contents($f), true);
    if (!is_array($data)) continue;
    foreach ($data as $ev) {
        $ev['_rama'] = $key;
        $ev['_color'] = $meta['color'];
        $ev['_label'] = $meta['label'];
        $todos[] = $ev;
    }
}

/* Filtro por rama (opcional) */
if ($filtro !== 'todas') {
    $todos = array_values(array_filter($todos, fn($e) => ($e['_rama'] ?? '') === $filtro));
}

/* Orden: fecha desc (más reciente / próximo arriba: primero futuros cercanos luego pasados)
   Mejor: futuros ASC + hoy, luego pasados DESC */
$hoy = date('Y-m-d');
usort($todos, function ($a, $b) use ($hoy) {
    $fa = $a['fecha'] ?? '';
    $fb = $b['fecha'] ?? '';
    $aFut = $fa >= $hoy;
    $bFut = $fb >= $hoy;
    if ($aFut && $bFut) return strcmp($fa, $fb);      // próximos: antes primero
    if (!$aFut && !$bFut) return strcmp($fb, $fa);    // pasados: más reciente primero
    return $aFut ? -1 : 1;                            // futuros antes que pasados
});
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Atiza Culto AMB</title>
<style>
:root {
  --bg:#07080c; --panel:#12141a; --border:#252a35; --text:#eef2f7;
  --muted:#8b93a7; --accent:#5eead4; --cyan:#22d3ee; --green:#4ade80;
  --chat-w:300px;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,Arial,sans-serif;min-height:100vh}
.layout{
  display:grid;
  grid-template-columns:1fr var(--chat-w);
  grid-template-rows:auto auto auto 1fr;
  grid-template-areas:
    "banner banner"
    "nav nav"
    "tfilters tfilters"
    "main chat";
  min-height:100vh;
}
.banner{grid-area:banner}
.nav{grid-area:nav}
.time-filters{grid-area:tfilters}
.main{grid-area:main}
.chat{grid-area:chat}
.banner{
  min-height:120px;position:relative;overflow:hidden;
  border-bottom:1px solid rgba(94,234,212,.12);
  display:flex;align-items:center;padding:20px 28px;
  background:
    radial-gradient(ellipse 80% 120% at 10% 50%, rgba(34,211,238,.14), transparent 50%),
    linear-gradient(180deg,#0a0e18,#07080c);
}
.banner-grid{
  position:absolute;inset:0;pointer-events:none;
  background-image:linear-gradient(rgba(94,234,212,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(94,234,212,.05) 1px,transparent 1px);
  background-size:48px 48px;
}
.banner-inner{position:relative;z-index:1}
.banner-kicker{font-size:.65rem;font-weight:600;letter-spacing:.28em;text-transform:uppercase;color:var(--cyan);margin:0 0 6px}
.banner h1{
  margin:0;font-size:clamp(1.8rem,4vw,2.6rem);font-weight:800;letter-spacing:-.04em;
  background:linear-gradient(105deg,#f0f9ff,#5eead4 50%,#22d3ee);
  -webkit-background-clip:text;background-clip:text;color:transparent;
}
.banner p{margin:6px 0 0;color:var(--muted);font-size:.9rem}

/* MENÚ RAMAS CON COLOR */
.nav{
  display:flex;flex-wrap:wrap;gap:8px;align-items:center;
  padding:12px 16px;
  background:#0a0c12;
  border-bottom:1px solid var(--border);
  position:sticky;top:0;z-index:20;
}
.nav a{
  text-decoration:none;color:var(--muted);
  font-size:.78rem;font-weight:600;
  padding:6px 12px;border-radius:999px;
  border:1px solid #2a3040;
  display:inline-flex;align-items:center;gap:6px;
}
.nav a .dot{
  width:8px;height:8px;border-radius:50%;flex-shrink:0;
}
.nav a:hover{color:var(--text);border-color:#3a4558}
.nav a.active{
  color:#fff;
  border-color:var(--rama, var(--cyan));
  background:color-mix(in srgb, var(--rama, var(--cyan)) 18%, transparent);
  box-shadow:0 0 0 1px color-mix(in srgb, var(--rama, var(--cyan)) 30%, transparent);
}
.nav a.todas.active{--rama:#5eead4}

.main{
  padding:10px 16px 32px;
  overflow-y:auto;
  max-height:calc(100vh - 160px);
  display:flex;
  flex-direction:column;
  align-items:center;
}
.list-meta{color:var(--muted);font-size:.82rem;margin:0 0 14px;width:100%;max-width:720px;}
.list{display:flex;flex-direction:column;gap:10px;width:100%;max-width:720px;}
.item{
  display:flex;gap:14px;align-items:flex-start;
  padding:14px;
  background:var(--panel);
  border:1px solid var(--border);
  border-radius:12px;
  border-left:3px solid var(--rama, #333);
}
.item:hover{border-color:#3a4558}
.logo-wrap{flex-shrink:0;border-radius:8px;overflow:hidden;line-height:0}
.logo{width:52px;height:52px;border-radius:8px;object-fit:cover;background:#1a1e28;border:1px solid var(--border);display:block}
.body{flex:1;min-width:0}
.top-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:4px}
.badge-rama{
  font-size:.65rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;
  padding:2px 8px;border-radius:999px;
  color:#0a0c12;background:var(--rama, #888);
}
.date{color:var(--accent);font-size:.78rem;font-weight:600}
.when{font-size:.72rem;padding:2px 7px;border-radius:4px;font-weight:600}
.when.fut{background:#0f2a22;color:#4ade80}
.when.hoy{background:#1a2a10;color:#a3e635}
.when.pas{background:#2a2010;color:#c4a574}
.countdown{
  font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
  font-size:.72rem;font-weight:600;letter-spacing:.02em;
  color:#94a3b8;padding:2px 8px;border-radius:4px;
  background:#0f1218;border:1px solid #1e2430;
}
.countdown.fut{color:#5eead4;border-color:#1a3a40}
.countdown.hoy{color:#a3e635;border-color:#2a3a10}
.countdown.pas{color:#c4a574;border-color:#3a3020}
.artist{font-weight:700;font-size:1.02rem;margin:2px 0}
.meta{color:var(--muted);font-size:.85rem}
.tags{margin-top:6px;display:flex;flex-wrap:wrap;gap:5px}
.tag{font-size:.68rem;padding:2px 7px;background:#1a1e28;border-radius:4px;color:#9aa3b5;border:1px solid #2a3040}
.tag.free{background:#14351a;color:#6dffa0;border-color:#2a5a35;font-weight:700}
.review{margin-top:8px;font-size:.78rem;color:#a0a8b8;line-height:1.4;border-top:1px solid #1c2030;padding-top:8px}
.review strong{color:#c8d0e0}
.actions{display:flex;flex-direction:column;gap:6px;flex-shrink:0}
.btn-link{
  display:inline-flex;align-items:center;justify-content:center;
  width:32px;height:32px;border-radius:8px;
  background:#15202b;border:1px solid #2a4050;color:var(--accent);
  text-decoration:none;font-size:.75rem;font-weight:700;
}
.btn-ext{color:#a5b4fc;border-color:#3a3a60}
.empty{color:#555;padding:40px;text-align:center}

.chat{
  border-left:1px solid var(--border);
  background:#0c0e14;
  display:flex;flex-direction:column;
  max-height:calc(100vh - 160px);
}
.chat-head{padding:12px;border-bottom:1px solid var(--border);font-weight:600;font-size:.88rem;display:flex;align-items:center;gap:8px}
.chat-head span{width:8px;height:8px;border-radius:50%;background:var(--green)}
.chat-msgs{flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:8px}
.bubble{max-width:92%;padding:9px 11px;border-radius:11px;font-size:.84rem;line-height:1.4;white-space:pre-wrap;word-break:break-word}
.bubble.user{align-self:flex-end;background:#132a32;border:1px solid #1e4a55}
.bubble.bot{align-self:flex-start;background:#141820;border:1px solid var(--border);color:#c5cdd8}
.bubble .ts{display:block;font-size:.65rem;color:#5a6578;margin-top:3px}
.chat-form{border-top:1px solid var(--border);padding:10px;display:flex;gap:8px}
.chat-form textarea{flex:1;resize:none;height:52px;padding:8px;border-radius:9px;border:1px solid var(--border);background:#12161f;color:var(--text);font-family:inherit;font-size:.84rem}
.chat-form button{align-self:flex-end;padding:8px 12px;border-radius:9px;border:1px solid #1a5a40;background:#122a1e;color:var(--green);font-weight:700;cursor:pointer}
.chat-hint{padding:0 10px 8px;font-size:.68rem;color:#4a5568}

@media(max-width:860px){
  .layout{
    grid-template-columns:1fr;
    grid-template-areas:
      "banner"
      "nav"
      "main"
      "chat";
  }
  .chat{border-left:none;border-top:1px solid var(--border);max-height:36vh}
  .main{max-height:none}
}

/* FIX layout centrado */
.layout{
  display:grid !important;
  grid-template-columns:1fr 300px !important;
  grid-template-rows:auto auto auto 1fr !important;
  grid-template-areas:
    "banner banner"
    "nav nav"
    "tfilters tfilters"
    "main chat" !important;
}
.banner{grid-area:banner !important}
.nav{grid-area:nav !important;grid-column:auto !important}
.main{
  grid-area:main !important;
  display:flex !important;
  flex-direction:column !important;
  align-items:center !important;
}
.chat{grid-area:chat !important;grid-row:auto !important}
.list{width:100%;max-width:720px !important;margin:0 auto}
.list-meta{width:100%;max-width:720px}


/* Banner mínimo */
.banner{
  grid-area:banner;
  min-height:0 !important;
  padding:8px 16px !important;
  display:flex !important;
  align-items:center !important;
  border-bottom:1px solid rgba(94,234,212,.1);
  background:#0a0c12 !important;
}
.banner-grid,.banner-scan{display:none !important}
.banner-inner{
  display:flex;align-items:baseline;gap:12px;width:100%;
  max-width:none;
}
.banner h1{
  margin:0 !important;
  font-size:1.15rem !important;
  font-weight:800;
  letter-spacing:-.03em;
  background:linear-gradient(105deg,#f0f9ff,#5eead4 50%,#22d3ee);
  -webkit-background-clip:text;background-clip:text;color:transparent;
}
.banner-kicker,.banner p{display:none !important}
.banner-sub{font-size:.7rem;color:#5eead4;letter-spacing:.2em;font-weight:600}

/* Filtros tiempo centrados */
.time-filters{
  grid-column:1 / -1;
  display:flex;justify-content:center;align-items:center;gap:10px;
  padding:12px 16px;
  background:#080a0e;
  border-bottom:1px solid #1c2030;
}
.tf{
  cursor:pointer;
  font-family:inherit;font-size:.82rem;font-weight:700;
  letter-spacing:.04em;text-transform:uppercase;
  padding:8px 20px;border-radius:999px;
  border:1px solid #2a3040;
  background:#12161f;color:#8b93a7;
}
.tf:hover{color:#eef2f7;border-color:#3a4558}
.tf.active{
  color:#0a0c12;
  border-color:transparent;
}
.tf[data-filter="pasado"].active{background:#c4a574}
.tf[data-filter="hoy"].active{background:#a3e635}
.tf[data-filter="proximos"].active{background:#5eead4}
.tf[data-filter="todos"].active{background:#94a3b8}

.item.is-hidden{display:none !important}


/* Banner ultra mínimo */
.banner{
  grid-area:banner !important;
  min-height:0 !important;
  height:auto !important;
  padding:4px 12px !important;
  display:flex !important;
  align-items:center !important;
  gap:10px !important;
  background:#080a0e !important;
  border-bottom:1px solid #1a1e28 !important;
}
.banner strong{
  font-size:.95rem;font-weight:800;letter-spacing:-.02em;
  background:linear-gradient(90deg,#e0f2fe,#2dd4bf);
  -webkit-background-clip:text;background-clip:text;color:transparent;
}
.banner span{font-size:.65rem;color:#2dd4bf;letter-spacing:.15em;font-weight:600}
.banner-inner,.banner h1,.banner-sub,.banner-kicker,.banner p,.banner-grid{display:none !important}

/* Calendario */
.cal-wrap{
  width:100%;max-width:720px;
  margin:0 0 14px;
  padding:12px;
  background:#0d0f14;
  border:1px solid #1c2030;
  border-radius:12px;
}
.cal-head{
  display:flex;align-items:center;justify-content:space-between;
  margin-bottom:10px;gap:8px;
}
.cal-head strong{font-size:.9rem;letter-spacing:.04em}
.cal-nav{
  display:flex;gap:6px;
}
.cal-nav button{
  cursor:pointer;border:1px solid #2a3040;background:#12161f;color:#94a3b8;
  border-radius:6px;padding:4px 10px;font-size:.8rem;font-weight:700;
}
.cal-nav button:hover{color:#e2e8f0;border-color:#3a4558}
.cal-grid{
  display:grid;grid-template-columns:repeat(7,1fr);gap:4px;
  text-align:center;
}
.cal-dow{
  font-size:.65rem;color:#64748b;font-weight:600;padding:4px 0;
  text-transform:uppercase;
}
.cal-day{
  position:relative;
  min-height:36px;
  border-radius:8px;
  border:1px solid transparent;
  background:transparent;
  color:#94a3b8;
  font-size:.8rem;font-weight:600;
  cursor:default;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:3px;padding:4px 2px;
}
.cal-day.muted{opacity:.25}
.cal-day.has{
  cursor:pointer;
  border-color:#2a3040;
  background:#12161f;
}
.cal-day.has:hover{border-color:#3a5568}
.cal-day.today{
  box-shadow:inset 0 0 0 1px #2dd4bf;
  color:#e2e8f0;
}
.cal-day.selected{
  background:#1a2332;
  border-color:#2dd4bf;
}
.cal-dots{display:flex;gap:2px;flex-wrap:wrap;justify-content:center;max-width:100%}
.cal-dot{
  width:6px;height:6px;border-radius:50%;
}
.cal-legend{
  display:flex;flex-wrap:wrap;gap:8px 12px;
  margin-top:10px;padding-top:10px;border-top:1px solid #1c2030;
}
.cal-legend span{
  display:inline-flex;align-items:center;gap:5px;
  font-size:.68rem;color:#94a3b8;
}


/* ===== LAYOUT LATERAL ===== */
:root { --side-w: 200px; --chat-w: 280px; }

.layout{
  display:grid !important;
  grid-template-columns: var(--side-w) 1fr var(--chat-w) !important;
  grid-template-rows: 36px 1fr !important;
  grid-template-areas:
    "top top top"
    "side main chat" !important;
  min-height:100vh !important;
  gap:0 !important;
}

.banner{
  grid-area:top !important;
  min-height:0 !important;
  height:36px !important;
  padding:0 12px !important;
  margin:0 !important;
  display:flex !important;
  align-items:center !important;
  gap:10px !important;
  background:#080a0e !important;
  border-bottom:1px solid #1a1e28 !important;
}
.banner strong{
  font-size:.9rem !important;
  font-weight:800;
  background:linear-gradient(90deg,#e0f2fe,#2dd4bf);
  -webkit-background-clip:text;background-clip:text;color:transparent;
}
.banner span{font-size:.65rem;color:#2dd4bf;letter-spacing:.12em}

/* Menú ramas = lateral */
.nav{
  grid-area:side !important;
  grid-column:auto !important;
  display:flex !important;
  flex-direction:column !important;
  flex-wrap:nowrap !important;
  align-items:stretch !important;
  gap:4px !important;
  padding:10px 8px !important;
  background:#0a0c12 !important;
  border-right:1px solid #1c2030 !important;
  border-bottom:none !important;
  position:sticky !important;
  top:36px !important;
  height:calc(100vh - 36px) !important;
  overflow-y:auto !important;
  z-index:10;
}
.nav a{
  display:flex !important;
  align-items:center !important;
  gap:8px !important;
  width:100%;
  border-radius:8px !important;
  padding:8px 10px !important;
  font-size:.75rem !important;
  text-align:left;
}
.nav-title{
  font-size:.65rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
  color:#64748b;padding:4px 10px 8px;
}

/* Filtros tiempo bajo el menú de ramas en el lateral */
.time-filters{
  grid-area:side !important;
  display:none !important; /* se mueven al sidebar vía JS/HTML si hace falta */
}

.side-time{
  display:flex;flex-direction:column;gap:4px;
  padding:10px 8px 0;
  border-top:1px solid #1c2030;
  margin-top:8px;
}
.side-time .tf{
  width:100%;
  text-align:center;
  padding:7px 8px !important;
  font-size:.72rem !important;
}

.main{
  grid-area:main !important;
  padding:12px 16px 28px !important;
  overflow-y:auto !important;
  max-height:calc(100vh - 36px) !important;
  display:flex !important;
  flex-direction:column !important;
  align-items:center !important;
}
.chat{
  grid-area:chat !important;
  max-height:calc(100vh - 36px) !important;
  border-left:1px solid #1c2030 !important;
}

@media(max-width:960px){
  .layout{
    grid-template-columns:1fr !important;
    grid-template-rows:36px auto auto 1fr auto !important;
    grid-template-areas:
      "top"
      "side"
      "main"
      "chat" !important;
  }
  .nav{
    position:relative !important;
    top:0 !important;
    height:auto !important;
    flex-direction:row !important;
    flex-wrap:wrap !important;
    border-right:none !important;
    border-bottom:1px solid #1c2030 !important;
  }
  .nav a{width:auto}
  .side-time{flex-direction:row;flex-wrap:wrap;border-top:none}
}


/* Timeline scroll horizontal */
.cal-wrap{display:none !important}
.tl-wrap{
  width:100%;max-width:720px;
  margin:0 0 14px;
  padding:10px 12px 12px;
  background:#0d0f14;
  border:1px solid #1c2030;
  border-radius:12px;
}
.tl-head{
  display:flex;align-items:center;justify-content:space-between;
  margin-bottom:8px;gap:8px;
}
.tl-hint{font-size:.72rem;color:#64748b;letter-spacing:.04em}
.tl-now{
  cursor:pointer;border:1px solid #2a3040;background:#12161f;color:#5eead4;
  border-radius:6px;padding:4px 12px;font-size:.75rem;font-weight:700;
}
.tl-now:hover{border-color:#3a5568}
.tl-track{
  position:relative;
  overflow-x:auto;
  overflow-y:hidden;
  height:72px;
  border-radius:8px;
  background:linear-gradient(180deg,#0a0c10,#10141c);
  border:1px solid #1a1e28;
  scrollbar-width:thin;
  scrollbar-color:#2a3040 transparent;
  cursor:grab;
}
.tl-track:active{cursor:grabbing}
.tl-line{
  position:relative;
  height:72px;
  min-width:100%;
}
.tl-nowmark{
  position:absolute;top:0;bottom:0;width:2px;
  background:#5eead4;
  box-shadow:0 0 12px #5eead4;
  pointer-events:none;z-index:5;
}
.tl-tick{
  position:absolute;top:0;bottom:0;
  width:1px;background:#1e2430;
  pointer-events:none;
}
.tl-tick.major{background:#2a3344}
.tl-tick-label{
  position:absolute;bottom:4px;left:50%;transform:translateX(-50%);
  font-size:.6rem;color:#64748b;white-space:nowrap;pointer-events:none;
}
.tl-event{
  position:absolute;
  top:10px;
  width:12px;height:12px;
  border-radius:50%;
  border:2px solid #0d0f14;
  transform:translateX(-50%);
  cursor:pointer;z-index:3;
  transition:transform .12s, box-shadow .12s;
}
.tl-event:hover, .tl-event.hot{
  transform:translateX(-50%) scale(1.45);
  box-shadow:0 0 0 3px color-mix(in srgb, var(--c, #5eead4) 35%, transparent);
  z-index:6;
}
.tl-hover{
  margin-top:8px;
  min-height:2.4em;
  font-size:.8rem;
  color:#94a3b8;
  line-height:1.35;
}
.tl-hover strong{color:#e2e8f0}
.tl-hover .tl-time{
  font-family:ui-monospace,Menlo,monospace;
  color:#5eead4;font-size:.78rem;
}


/* === LIMPIEZA PANEL DERECHO === */
.chat, aside.chat, .chat-head, .chat-msgs, .chat-form, .chat-hint {
  display: none !important;
  width: 0 !important;
  max-width: 0 !important;
  overflow: hidden !important;
  border: none !important;
  padding: 0 !important;
  margin: 0 !important;
}
.layout {
  display: grid !important;
  grid-template-columns: 200px 1fr !important;
  grid-template-rows: 36px 1fr !important;
  grid-template-areas:
    "top top"
    "side main" !important;
  min-height: 100vh !important;
}
.banner { grid-area: top !important; }
.nav { grid-area: side !important; }
.main {
  grid-area: main !important;
  max-width: none !important;
  max-height: calc(100vh - 36px) !important;
}
@media (max-width: 960px) {
  .layout {
    grid-template-columns: 1fr !important;
    grid-template-areas:
      "top"
      "side"
      "main" !important;
  }
}


/* ===== TEMAS ===== */
:root, [data-theme="dark"] {
  --bg: #07080c;
  --bg2: #0a0c12;
  --panel: #12141a;
  --border: #252a35;
  --text: #eef2f7;
  --muted: #8b93a7;
  --accent: #5eead4;
  --side-bg: #0a0c12;
  --track-bg: linear-gradient(180deg, #080a10, #121826);
  --card-bg: #12141a;
  --btn-bg: #12161f;
  --btn-border: #2a3040;
  --shadow: none;
}
[data-theme="light"] {
  --bg: #f4f5f7;
  --bg2: #ffffff;
  --panel: #ffffff;
  --border: #e2e5eb;
  --text: #1a1d26;
  --muted: #5c6578;
  --accent: #0d9488;
  --side-bg: #ffffff;
  --track-bg: linear-gradient(180deg, #eef1f6, #f8f9fb);
  --card-bg: #ffffff;
  --btn-bg: #f0f2f5;
  --btn-border: #d5d9e2;
  --shadow: 0 1px 3px rgba(0,0,0,.06);
}
html, body {
  background: var(--bg) !important;
  color: var(--text) !important;
}
.banner {
  background: var(--bg2) !important;
  border-bottom: 1px solid var(--border) !important;
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
  padding: 0 14px !important;
  height: 40px !important;
  min-height: 40px !important;
}
.banner-left { display: flex; align-items: baseline; gap: 10px; }
.banner strong {
  font-size: .95rem !important;
  font-weight: 800;
  letter-spacing: -.02em;
  color: var(--text) !important;
  background: none !important;
  -webkit-text-fill-color: unset !important;
}
[data-theme="dark"] .banner strong {
  background: linear-gradient(90deg, #e0f2fe, #2dd4bf) !important;
  -webkit-background-clip: text !important;
  background-clip: text !important;
  color: transparent !important;
  -webkit-text-fill-color: transparent !important;
}
.banner span { font-size: .65rem; color: var(--accent); letter-spacing: .12em; font-weight: 600; }

.theme-btn {
  display: inline-flex; align-items: center; gap: 6px;
  cursor: pointer;
  border: 1px solid var(--btn-border);
  background: var(--btn-bg);
  color: var(--text);
  border-radius: 999px;
  padding: 5px 12px;
  font-size: .75rem;
  font-weight: 600;
  font-family: inherit;
}
.theme-btn:hover { border-color: var(--accent); }
.theme-icon { font-size: .9rem; line-height: 1; }

.nav {
  background: var(--side-bg) !important;
  border-right: 1px solid var(--border) !important;
}
.nav a {
  color: var(--muted) !important;
  border-color: var(--btn-border) !important;
  background: transparent !important;
}
.nav a:hover { color: var(--text) !important; background: var(--btn-bg) !important; }
.nav-title { color: var(--muted) !important; }

.main { background: var(--bg) !important; }
.tl-wrap, .cal-wrap {
  background: var(--panel) !important;
  border: 1px solid var(--border) !important;
  box-shadow: var(--shadow);
}
.tl-track {
  background: var(--track-bg) !important;
  border: 1px solid var(--border) !important;
}
.tl-hint, .list-meta { color: var(--muted) !important; }
.tl-hover {
  color: var(--text) !important;
  background: var(--btn-bg) !important;
  border: 1px solid var(--border) !important;
}
.tl-tick { background: var(--border) !important; }
.tl-tick.major { background: var(--muted) !important; opacity: .5; }
.tl-tick-label { color: var(--muted) !important; text-shadow: none !important; }
.tl-now {
  background: var(--btn-bg) !important;
  border: 1px solid var(--btn-border) !important;
  color: var(--accent) !important;
}

.item {
  background: var(--card-bg) !important;
  border: 1px solid var(--border) !important;
  box-shadow: var(--shadow);
}
.item:hover { border-color: var(--accent) !important; }
.artist, .body { color: var(--text) !important; }
.meta, .date { color: var(--muted) !important; }
[data-theme="light"] .date { color: var(--accent) !important; }
.tag {
  background: var(--btn-bg) !important;
  border-color: var(--btn-border) !important;
  color: var(--muted) !important;
}
.btn-link {
  background: var(--btn-bg) !important;
  border-color: var(--btn-border) !important;
  color: var(--accent) !important;
}
.side-time .tf, .tf {
  background: var(--btn-bg) !important;
  border-color: var(--btn-border) !important;
  color: var(--muted) !important;
}
.logo { border-color: var(--border) !important; background: var(--btn-bg) !important; }
.review { color: var(--muted) !important; border-top-color: var(--border) !important; }


/* Modo claro: tipografías negras, sin cian en texto */
[data-theme="light"] {
  --accent: #1a1d26;
  --text: #12141a;
  --muted: #4a5160;
}
[data-theme="light"] .banner span,
[data-theme="light"] .date,
[data-theme="light"] .countdown,
[data-theme="light"] .countdown.fut,
[data-theme="light"] .countdown.hoy,
[data-theme="light"] .tl-hover .tl-time,
[data-theme="light"] .tl-now,
[data-theme="light"] .artist,
[data-theme="light"] .list-meta,
[data-theme="light"] .meta,
[data-theme="light"] .tl-hint,
[data-theme="light"] .nav a,
[data-theme="light"] .nav-title {
  color: #12141a !important;
}
[data-theme="light"] .meta,
[data-theme="light"] .tl-hint,
[data-theme="light"] .list-meta,
[data-theme="light"] .nav-title,
[data-theme="light"] .tag {
  color: #4a5160 !important;
}
[data-theme="light"] .banner strong {
  color: #0a0a0a !important;
  background: none !important;
  -webkit-text-fill-color: #0a0a0a !important;
  -webkit-background-clip: unset !important;
  background-clip: unset !important;
}
[data-theme="light"] .btn-link {
  color: #12141a !important;
  border-color: #c5cad3 !important;
}
[data-theme="light"] .theme-btn {
  color: #12141a !important;
}
[data-theme="light"] .tl-nowmark {
  background: #12141a !important;
  box-shadow: 0 0 8px rgba(0,0,0,.25) !important;
}
/* badges de rama mantienen color de categoría; countdown fondo sobrio */
[data-theme="light"] .countdown {
  background: #eef0f4 !important;
  border-color: #d5d9e2 !important;
  color: #12141a !important;
}
[data-theme="light"] .when.hoy {
  background: #e8f5e9 !important;
  color: #1b5e20 !important;
}
[data-theme="light"] .when.fut {
  background: #eceff3 !important;
  color: #12141a !important;
}
[data-theme="light"] .when.pas {
  background: #f5f0e8 !important;
  color: #5d4e37 !important;
}

</style>
</head>
<body>
<div class="layout">

  <header class="banner">
    <div class="banner-left">
      <strong>ATIZA CULTO</strong>
      <span>AMB</span>
    </div>
    <button type="button" class="theme-btn" id="themeToggle" title="Cambiar tema" aria-label="Cambiar tema">
      <span class="theme-icon" aria-hidden="true">☀</span>
      <span class="theme-label">Claro</span>
    </button>
  </header>

  <nav class="nav">
    <a href="?rama=todas" class="todas <?= $filtro === 'todas' ? 'active' : '' ?>" style="--rama:#5eead4">
      <span class="dot" style="background:#5eead4"></span> Todas
    </a>
    <?php foreach ($RAMAS as $key => $meta): ?>
      <a href="?rama=<?= urlencode($key) ?>"
         class="<?= $filtro === $key ? 'active' : '' ?>"
         style="--rama:<?= htmlspecialchars($meta['color']) ?>">
        <span class="dot" style="background:<?= htmlspecialchars($meta['color']) ?>"></span>
        <?= htmlspecialchars($meta['label']) ?>
      </a>
    <?php endforeach; ?>
  </nav>

  

  <main class="main">
    <div class="tl-wrap" id="tlWrap">
      <div class="tl-head">
        <span class="tl-hint">← futuro &nbsp;|&nbsp; pasado →</span>
        <button type="button" class="tl-now" id="tlNow">Ahora</button>
      </div>
      <div class="tl-track" id="tlTrack" tabindex="0">
        <div class="tl-line" id="tlLine"></div>
        <div class="tl-nowmark" id="tlNowMark" title="Ahora"></div>
      </div>
      <div class="tl-hover" id="tlHover">Pasa el cursor por la línea</div>
    </div>
    <p class="list-meta">
      <?= count($todos) ?> evento<?= count($todos) === 1 ? '' : 's' ?>
      <?php if ($filtro !== 'todas'): ?>
        · filtro: <?= htmlspecialchars($RAMAS[$filtro]['label']) ?>
        · <a href="?rama=todas" style="color:var(--cyan)">ver todas</a>
      <?php endif; ?>
    </p>

    <div class="list">
<?php if (!$todos): ?>
      <p class="empty">No hay eventos en esta vista.</p>
<?php else: foreach ($todos as $c):
    $f = $c['fecha'] ?? '';
    $when = ($f > $hoy) ? 'fut' : (($f === $hoy) ? 'hoy' : 'pas');
    $whenLabel = ($when === 'fut') ? 'Próximo' : (($when === 'hoy') ? 'Hoy' : 'Pasado');
    $color = $c['_color'] ?? '#555';
    $img = eventImageUrl($c);
    $gurl = googleSearchUrl($c);
    $url = trim($c['url'] ?? '');
    $nota = trim($c['nota'] ?? '');
    $titulo = tituloDe($c);
    $critica = trim($c['critica'] ?? '');
    $opinion = trim($c['opinion'] ?? '');
?>
      <?php
    $horaRaw = trim($c['hora'] ?? '');
    if ($horaRaw === '' || $horaRaw === '—') $horaRaw = '00:00';
    $tsIso = $f . ' ' . (preg_match('/^\d{1,2}:\d{2}/', $horaRaw) ? $horaRaw : '00:00') . ':00';
?>
      <article class="item" style="--rama:<?= htmlspecialchars($color) ?>" data-ts="<?= htmlspecialchars($tsIso) ?>" data-when="<?= htmlspecialchars($when) ?>" data-color="<?= htmlspecialchars($color) ?>" data-rama="<?= htmlspecialchars($c['_label'] ?? '') ?>">
        <a class="logo-wrap" href="<?= htmlspecialchars($gurl) ?>" target="_blank" rel="noopener">
          <img class="logo" src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy"
               onerror="this.src='https://ui-avatars.com/api/?name=E&size=128&background=1a2332&color=5eead4&bold=true'">
        </a>
        <div class="body">
          <div class="top-row">
            <span class="badge-rama" style="background:<?= htmlspecialchars($color) ?>"><?= htmlspecialchars($c['_label'] ?? '') ?></span>
            <span class="date"><?= htmlspecialchars(fmtFecha($f)) ?><?php if (($c['hora'] ?? '—') !== '—'): ?> · <?= htmlspecialchars($c['hora']) ?> h<?php endif; ?></span>
            <span class="when <?= $when ?>"><?= $whenLabel ?></span>
            <span class="countdown" title="Relativo al evento">—</span>
          </div>
          <div class="artist"><?= htmlspecialchars($titulo) ?></div>
          <div class="meta"><?= htmlspecialchars($c['lugar'] ?? '') ?> · <?= htmlspecialchars($c['ciudad'] ?? '') ?></div>
          <div class="tags">
            <?php if (!empty($c['genero'])): ?><span class="tag"><?= htmlspecialchars($c['genero']) ?></span><?php endif; ?>
            <?php if (esGratis($nota)): ?><span class="tag free">🆓 Gratis</span>
            <?php elseif ($nota !== ''): ?><span class="tag"><?= htmlspecialchars($nota) ?></span><?php endif; ?>
          </div>
          <?php if ($when === 'pas' && ($critica !== '' || $opinion !== '')): ?>
            <div class="review">
              <?php if ($critica !== ''): ?><div><strong>Crítica:</strong> <?= htmlspecialchars($critica) ?></div><?php endif; ?>
              <?php if ($opinion !== ''): ?><div><strong>Opinión:</strong> <?= htmlspecialchars($opinion) ?></div><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="actions">
          <a class="btn-link" href="<?= htmlspecialchars($gurl) ?>" target="_blank" rel="noopener" title="Google">G</a>
          <?php if ($url !== ''): ?>
            <a class="btn-link btn-ext" href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener">↗</a>
          <?php endif; ?>
        </div>
      </article>
<?php endforeach; endif; ?>
    </div>
  </main>

  </div>
<script>
(function () {
  const msgs = document.getElementById('chatMsgs');
  const form = document.getElementById('chatForm');
  const input = document.getElementById('chatInput');
  const btn = document.getElementById('chatBtn');
  const base = window.location.href.split('?')[0];
  function addBubble(role, text, ts) {
    const d = document.createElement('div');
    d.className = 'bubble ' + role;
    d.textContent = text;
    const t = document.createElement('span');
    t.className = 'ts'; t.textContent = ts || '';
    d.appendChild(t);
    msgs.appendChild(d);
    msgs.scrollTop = msgs.scrollHeight;
  }
  fetch(base + '?chat_history=1').then(r=>r.json()).then(data=>{
    if (!data.ok || !data.messages) return;
    data.messages.forEach(m => addBubble(m.role==='user'?'user':'bot', m.text, m.ts));
  }).catch(()=>{});
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = input.value.trim();
    if (!text) return;
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action','chat'); fd.append('message', text);
    try {
      const res = await fetch(base, { method:'POST', body: fd });
      const raw = await res.text();
      let data; try { data = JSON.parse(raw); } catch {
        addBubble('bot','No JSON: '+raw.slice(0,160),''); btn.disabled=false; return;
      }
      if (data.ok) {
        addBubble('user', data.user.text, data.user.ts);
        addBubble('bot', data.bot.text, data.bot.ts);
        input.value = '';
      }
    } catch (err) { addBubble('bot','Red: '+err.message,''); }
    btn.disabled = false; input.focus();
  });
  input.addEventListener('keydown', e => {
    if (e.key==='Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
  });
})();
</script>

<script>
(function () {
  function pad(n) { return String(n).padStart(2, '0'); }

  function diffParts(from, to) {
    // from y to en ms; to >= from
    let ms = Math.max(0, to - from);
    const sec = Math.floor(ms / 1000);
    const years = Math.floor(sec / (365.25 * 24 * 3600));
    let rest = sec - Math.floor(years * 365.25 * 24 * 3600);
    const months = Math.floor(rest / (30.44 * 24 * 3600));
    rest -= Math.floor(months * 30.44 * 24 * 3600);
    const days = Math.floor(rest / (24 * 3600));
    rest -= days * 24 * 3600;
    const hours = Math.floor(rest / 3600);
    rest -= hours * 3600;
    const mins = Math.floor(rest / 60);
    const secs = rest % 60;
    return { years, months, days, hours, mins, secs };
  }

  function fmt(p, prefix) {
    // tipo: 2a 03m 12d 04h 15:08
    const bits = [];
    if (p.years) bits.push(p.years + 'a');
    if (p.months || p.years) bits.push(pad(p.months) + 'm');
    if (p.days || p.months || p.years) bits.push(pad(p.days) + 'd');
    bits.push(pad(p.hours) + 'h');
    bits.push(pad(p.mins) + ':' + pad(p.secs));
    return prefix + bits.join(' ');
  }

  function tick() {
    const now = Date.now();
    document.querySelectorAll('.item[data-ts]').forEach(el => {
      const raw = el.getAttribute('data-ts');
      const t = Date.parse(raw.replace(' ', 'T'));
      const node = el.querySelector('.countdown');
      if (!node || isNaN(t)) return;
      if (t >= now) {
        const p = diffParts(now, t);
        node.textContent = fmt(p, '− ');
        node.className = 'countdown fut';
        if (p.years === 0 && p.months === 0 && p.days === 0) node.className = 'countdown hoy';
      } else {
        const p = diffParts(t, now);
        node.textContent = fmt(p, '+ ');
        node.className = 'countdown pas';
      }
    });
  }
  tick();
  setInterval(tick, 1000);
})();
</script>


<script>
(function () {
  const buttons = document.querySelectorAll('.tf');
  const items = () => document.querySelectorAll('.item[data-when]');

  function apply(filter) {
    buttons.forEach(b => b.classList.toggle('active', b.dataset.filter === filter));
    items().forEach(el => {
      const w = el.getAttribute('data-when'); // pas | hoy | fut
      let show = true;
      if (filter === 'pasado') show = w === 'pas';
      else if (filter === 'hoy') show = w === 'hoy';
      else if (filter === 'proximos') show = w === 'fut';
      else show = true; // todos
      el.classList.toggle('is-hidden', !show);
    });
    const visible = document.querySelectorAll('.item[data-when]:not(.is-hidden)').length;
    const meta = document.querySelector('.list-meta');
    if (meta) {
      const base = meta.getAttribute('data-base') || meta.textContent;
      if (!meta.getAttribute('data-base')) meta.setAttribute('data-base', meta.textContent);
      meta.textContent = visible + ' visibles · ' + (meta.getAttribute('data-base') || '');
    }
  }

  buttons.forEach(b => b.addEventListener('click', () => apply(b.dataset.filter)));
  // Por defecto: Hoy
  apply('todos');
})();
</script>





<script>
(function () {
  const track = document.getElementById('tlTrack');
  const line = document.getElementById('tlLine');
  const nowMark = document.getElementById('tlNowMark');
  const hoverBox = document.getElementById('tlHover');
  const btnNow = document.getElementById('tlNow');
  if (!track || !line) return;

  // Rango: 6 meses atrás … 6 meses adelante
  const DAY = 86400000;
  const now0 = Date.now();
  const start = now0 - 180 * DAY;
  const end = now0 + 180 * DAY;
  const span = end - start;
  const PX_PER_DAY = 14;
  const width = Math.ceil((span / DAY) * PX_PER_DAY);

  line.style.width = width + 'px';

  function xFor(ms) {
    return ((ms - start) / span) * width;
  }

  // Ticks cada día / etiqueta cada 7 días
  for (let t = start; t <= end; t += DAY) {
    const d = new Date(t);
    const x = xFor(t);
    const tick = document.createElement('div');
    tick.className = 'tl-tick' + (d.getDate() === 1 || d.getDay() === 1 ? ' major' : '');
    tick.style.left = x + 'px';
    line.appendChild(tick);
    if (d.getDate() === 1 || d.getDay() === 1) {
      const lab = document.createElement('div');
      lab.className = 'tl-tick-label';
      lab.style.left = x + 'px';
      const meses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
      lab.textContent = d.getDate() + ' ' + meses[d.getMonth()];
      line.appendChild(lab);
    }
  }

  // Eventos desde el DOM
  const events = [];
  document.querySelectorAll('.item[data-ts]').forEach(el => {
    const raw = el.getAttribute('data-ts') || '';
    const ms = Date.parse(raw.replace(' ', 'T'));
    if (isNaN(ms)) return;
    const color = el.getAttribute('data-color') || '#5eead4';
    const title = (el.querySelector('.artist') || {}).textContent || '';
    const rama = el.getAttribute('data-rama') || '';
    const dot = document.createElement('div');
    dot.className = 'tl-event';
    dot.style.left = xFor(ms) + 'px';
    dot.style.background = color;
    dot.style.setProperty('--c', color);
    dot.title = title;
    line.appendChild(dot);
    events.push({ ms, color, title, rama, el, dot });

    dot.addEventListener('mouseenter', () => {
      dot.classList.add('hot');
      el.classList.add('tl-highlight');
      const when = new Date(ms);
      const rel = relClock(ms);
      hoverBox.innerHTML =
        '<strong>' + escapeHtml(title) + '</strong> · ' + escapeHtml(rama) +
        '<br><span class="tl-time">' + formatAbs(when) + ' &nbsp;|&nbsp; ' + rel + '</span>';
    });
    dot.addEventListener('mouseleave', () => {
      dot.classList.remove('hot');
      el.classList.remove('tl-highlight');
    });
    dot.addEventListener('click', () => {
      el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      el.classList.add('tl-highlight');
      setTimeout(() => el.classList.remove('tl-highlight'), 1800);
      // filtrar lista a ese día
      const day = raw.slice(0, 10);
      document.querySelectorAll('.item[data-ts]').forEach(item => {
        const d = (item.getAttribute('data-ts') || '').slice(0, 10);
        item.classList.toggle('is-hidden', d !== day);
      });
      document.querySelectorAll('.tf').forEach(b => b.classList.remove('active'));
    });
  });

  function formatAbs(d) {
    const p = n => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + p(d.getMonth()+1) + '-' + p(d.getDate())
      + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }

  function relClock(ms) {
    const now = Date.now();
    const sign = ms >= now ? '−' : '+';
    let s = Math.floor(Math.abs(ms - now) / 1000);
    const y = Math.floor(s / (365.25*24*3600)); s -= Math.floor(y*365.25*24*3600);
    const mo = Math.floor(s / (30.44*24*3600)); s -= Math.floor(mo*30.44*24*3600);
    const d = Math.floor(s / 86400); s -= d*86400;
    const h = Math.floor(s / 3600); s -= h*3600;
    const m = Math.floor(s / 60); const sec = s % 60;
    const p = n => String(n).padStart(2, '0');
    const bits = [];
    if (y) bits.push(y + 'a');
    if (mo || y) bits.push(p(mo) + 'm');
    if (d || mo || y) bits.push(p(d) + 'd');
    bits.push(p(h) + 'h ' + p(m) + ':' + p(sec));
    return sign + ' ' + bits.join(' ');
  }

  function escapeHtml(t) {
    return String(t).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function placeNow() {
    const x = xFor(Date.now());
    nowMark.style.left = x + 'px';
  }
  placeNow();
  setInterval(placeNow, 1000);

  function scrollToNow(smooth) {
    const x = xFor(Date.now());
    const target = x - track.clientWidth / 2;
    track.scrollTo({ left: Math.max(0, target), behavior: smooth ? 'smooth' : 'auto' });
  }
  scrollToNow(false);
  btnNow && btnNow.addEventListener('click', () => scrollToNow(true));

  // Drag to scroll
  let down = false, startX = 0, scrollL = 0;
  track.addEventListener('mousedown', e => {
    down = true; startX = e.pageX; scrollL = track.scrollLeft;
  });
  window.addEventListener('mouseup', () => { down = false; });
  window.addEventListener('mousemove', e => {
    if (!down) return;
    track.scrollLeft = scrollL - (e.pageX - startX);
  });

  // Highlight CSS
  const st = document.createElement('style');
  st.textContent = '.item.tl-highlight{outline:1px solid #5eead4;box-shadow:0 0 0 3px rgba(94,234,212,.15)}';
  document.head.appendChild(st);
})();
</script>


<script>
(function () {
  // Mostrar TODOS los eventos al cargar
  document.querySelectorAll(".item.is-hidden").forEach(el => el.classList.remove("is-hidden"));
  document.querySelectorAll(".tf").forEach(b => {
    b.classList.toggle("active", b.getAttribute("data-filter") === "todos");
  });
  // Si existe apply global no siempre; re-bind click ya está
  var meta = document.querySelector(".list-meta");
  var total = document.querySelectorAll(".item[data-ts]").length;
  if (meta) meta.textContent = total + " visibles · " + total + " eventos";
})();
</script>


<script>
(function () {
  const root = document.documentElement;
  const btn = document.getElementById('themeToggle');
  const label = btn ? btn.querySelector('.theme-label') : null;
  const icon = btn ? btn.querySelector('.theme-icon') : null;

  function apply(theme) {
    root.setAttribute('data-theme', theme);
    try { localStorage.setItem('atiza-theme', theme); } catch (e) {}
    if (label) label.textContent = theme === 'dark' ? 'Claro' : 'Oscuro';
    if (icon) icon.textContent = theme === 'dark' ? '☀' : '☾';
  }

  let saved = 'dark';
  try {
    saved = localStorage.getItem('atiza-theme') || 'dark';
  } catch (e) {}
  apply(saved);

  if (btn) {
    btn.addEventListener('click', function () {
      const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      apply(next);
    });
  }
})();
</script>

</body>
</html>
