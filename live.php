<?php
// Frontend: non parla mai con la chiavetta, legge solo data.json scritto da poller.php
require __DIR__ . '/config.php';

if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $raw = @file_get_contents(FILE_DATI);
    $c = $raw ? json_decode($raw, true) : null;
    if (!$c || empty($c['d'])) {
        echo json_encode(['ok' => false, 'errore' => 'Nessun dato: il poller è avviato?']);
        exit;
    }
    echo json_encode([
        'ok'     => true,
        'd'      => $c['d'],
        'eta'    => time() - $c['ts'],              // secondi dall'ultima lettura riuscita
        'errore' => $c['ok'] ? null : ($c['errore'] ?? 'errore'),
        'ora'    => date('H:i:s', $c['ts']),
    ]);
    exit;
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Energia live</title>
<style>
:root{
  --bg:#f3f5f9; --card:#fff; --txt:#1c2434; --mut:#7a8499; --line:#e3e7ef;
  --fv:#f5a623; --casa:#3b82f6; --batt:#22c55e; --rete:#8b5cf6; --warn:#ef4444;
}
@media (prefers-color-scheme: dark){
  :root{ --bg:#0f1420; --card:#182033; --txt:#e8ecf5; --mut:#8a94ab; --line:#26304a; }
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--txt);font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
.wrap{max-width:760px;margin:0 auto;padding:20px 16px 40px}
header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
h1{font-size:1.2rem;margin:0}
#stato{font-size:.8rem;color:var(--mut);display:flex;align-items:center;gap:6px}
#dot{width:8px;height:8px;border-radius:50%;background:var(--mut)}
#dot.ok{background:var(--batt);animation:pulse 2s infinite}
#dot.err{background:var(--warn)}
@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(34,197,94,.6)}100%{box-shadow:0 0 0 8px rgba(34,197,94,0)}}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.card{background:var(--card);border-radius:18px;padding:16px;border:1px solid var(--line);position:relative;overflow:hidden}
.card::before{content:"";position:absolute;left:0;top:0;bottom:0;width:5px;background:var(--c)}
.card .t{font-size:.8rem;color:var(--mut);text-transform:uppercase;letter-spacing:.05em}
.card .v{font-size:2rem;font-weight:700;margin:6px 0 2px}
.card .v small{font-size:1rem;font-weight:500;color:var(--mut)}
.card .s{font-size:.85rem;font-weight:600;color:var(--c)}
.fv{--c:var(--fv)} .casa{--c:var(--casa)} .batt{--c:var(--batt)} .rete{--c:var(--rete)}
.bar{height:12px;background:var(--line);border-radius:99px;margin-top:12px;overflow:hidden}
.bar i{display:block;height:100%;width:0;background:var(--batt);border-radius:99px;transition:width .8s}
.bar i.low{background:var(--warn)} .bar i.mid{background:var(--fv)}
.flow{margin:14px 0;background:var(--card);border:1px solid var(--line);border-radius:18px;padding:14px 16px;font-size:.95rem;text-align:center;line-height:1.6}
h2{font-size:.95rem;margin:22px 0 10px}
.day{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.day div{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px}
.day b{display:block;font-size:1.15rem}
.day span{font-size:.75rem;color:var(--mut)}
.old .card .v, .old .flow{opacity:.45}
@media(max-width:520px){.grid{grid-template-columns:1fr}.day{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<div class="wrap" id="wrap">
  <header>
    <h1>⚡ Energia di casa</h1>
    <div id="stato"><a href="grafico.php" style="color:var(--casa);text-decoration:none;margin-right:10px">📈 Storico</a><span id="dot"></span><span id="ora">in attesa…</span></div>
  </header>

  <div class="grid">
    <div class="card fv"><div class="t">Fotovoltaico</div><div class="v"><span id="fv">–</span> <small>kW</small></div><div class="s" id="fv_s">&nbsp;</div></div>
    <div class="card casa"><div class="t">Casa</div><div class="v"><span id="casa">–</span> <small>kW</small></div><div class="s">Consumo</div></div>
    <div class="card batt">
      <div class="t">Batteria</div>
      <div class="v"><span id="batt_pct">–</span> <small>%</small></div>
      <div class="s" id="batt_s">&nbsp;</div>
      <div class="bar"><i id="barra"></i></div>
    </div>
    <div class="card rete"><div class="t">Rete</div><div class="v"><span id="rete">–</span> <small>kW</small></div><div class="s" id="rete_s">&nbsp;</div></div>
  </div>

  <div class="flow" id="flow">…</div>

  <h2>Oggi</h2>
  <div class="day">
    <div><b id="fv_day">–</b><span>Prodotta FV kWh</span></div>
    <div><b id="casa_day">–</b><span>Consumata casa kWh</span></div>
    <div><b id="prel_day">–</b><span>Prelevata da rete kWh</span></div>
    <div><b id="imm_day">–</b><span>Immessa in rete kWh</span></div>
    <div><b id="bc_day">–</b><span>Batteria caricata kWh</span></div>
    <div><b id="bs_day">–</b><span>Batteria scaricata kWh</span></div>
  </div>
</div>

<script>
const $ = id => document.getElementById(id);
const f = (v, d = 2) => Number(v).toFixed(d);
const SOGLIA = 0.05;      // kW sotto cui si considera "fermo"
const MAX_ETA = 70;       // oltre questi secondi i dati sono considerati vecchi

function mostra(d){
  $('fv').textContent = f(d.fv);
  $('casa').textContent = f(d.casa);
  $('batt_pct').textContent = d.batt_pct;
  $('rete').textContent = f(Math.abs(d.rete));
  ['fv_day','casa_day','prel_day','imm_day','bc_day','bs_day'].forEach(k => $(k).textContent = f(d[k]));

  $('fv_s').textContent = d.fv > SOGLIA ? 'In produzione' : 'Inattivo';

  const b = d.batt_kw;
  $('batt_s').textContent = b > SOGLIA ? `In carica · ${f(b)} kW`
                          : b < -SOGLIA ? `In scarica · ${f(-b)} kW` : 'In attesa';
  const barra = $('barra');
  barra.style.width = Math.min(100, d.batt_pct) + '%';
  barra.className = d.batt_pct < 15 ? 'low' : d.batt_pct < 40 ? 'mid' : '';

  const r = d.rete;
  $('rete_s').textContent = r > SOGLIA ? 'Immissione' : r < -SOGLIA ? 'Prelievo' : 'Nessuno scambio';

  const righe = [];
  if (d.fv > SOGLIA) righe.push(`☀️ Il fotovoltaico produce <b>${f(d.fv)} kW</b>`);
  righe.push(`🏠 La casa consuma <b>${f(d.casa)} kW</b>`);
  if (b > SOGLIA) righe.push(`🔋 La batteria si sta caricando (<b>${f(b)} kW</b>)`);
  if (b < -SOGLIA) righe.push(`🔋 La batteria sta alimentando la casa (<b>${f(-b)} kW</b>)`);
  if (r < -SOGLIA) righe.push(`🔌 Stai prelevando <b>${f(-r)} kW</b> dalla rete`);
  if (r > SOGLIA) righe.push(`🔌 Stai immettendo <b>${f(r)} kW</b> in rete`);
  $('flow').innerHTML = righe.join('<br>');
}

async function agg(){
  try{
    const r = await (await fetch('?json=1', {cache: 'no-store'})).json();
    if (!r.ok){
      $('dot').className = 'err';
      $('ora').textContent = r.errore;
      return;
    }
    mostra(r.d);
    const vecchio = r.eta > MAX_ETA;
    $('wrap').classList.toggle('old', vecchio);
    $('dot').className = vecchio || r.errore ? 'err' : 'ok';
    $('ora').textContent = vecchio
      ? `Dati vecchi di ${r.eta} s (ultimo: ${r.ora})`
      : r.errore ? `Chiavetta non risponde (ultimo: ${r.ora})`
                 : `Aggiornato alle ${r.ora}`;
  } catch(e){
    $('dot').className = 'err';
    $('ora').textContent = 'Errore di rete';
  }
}
agg();
setInterval(agg, 5000);   // legge solo il file in cache: costo trascurabile
</script>
</body>
</html>
