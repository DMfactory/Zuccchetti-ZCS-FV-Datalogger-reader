<?php
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    try {
        $g = $_GET['giorno'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $g)) throw new Exception('Data non valida');
        $b = (int)($_GET['b'] ?? 300);
        if (!in_array($b, [60,120, 300, 900], true)) $b = 300;

        $a = strtotime("$g 00:00:00");
        $z = strtotime('+1 day', $a);
        $pdo = db();

        // media per intervallo di $b secondi
        $st = $pdo->prepare(
            "SELECT ts - (ts % $b) AS b, AVG(fv) AS fv, AVG(casa) AS casa, AVG(rete) AS rete,
                    AVG(batt_kw) AS batt_kw, AVG(batt_pct) AS batt_pct
             FROM misure WHERE ts >= ? AND ts < ? GROUP BY b ORDER BY b");
        $st->execute([$a, $z]);
        $righe = [];
        foreach ($st as $r) $righe[(int)$r['b']] = $r;

        $lab = []; $s = ['fv' => [], 'casa' => [], 'rete' => [], 'batt_kw' => [], 'batt_pct' => []];
        for ($t = $a - ($a % $b); $t < $z; $t += $b) {
            $lab[] = date('H:i', $t);
            foreach ($s as $k => &$arr) $arr[] = isset($righe[$t]) ? round((float)$righe[$t][$k], 2) : null;
            unset($arr);
        }

        // contatori giornalieri: ultima riga del giorno
        $st = $pdo->prepare('SELECT fv_day, casa_day, prel_day, imm_day, bc_day, bs_day
                             FROM misure WHERE ts >= ? AND ts < ? ORDER BY ts DESC LIMIT 1');
        $st->execute([$a, $z]);
        $tot = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        echo json_encode(['ok' => true, 'labels' => $lab, 's' => $s, 'tot' => $tot, 'giorno' => $g]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'errore' => $e->getMessage()]);
    }
    exit;
}
?>
<!doctype html>
<html lang="it">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Storico energia</title>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
        <style>
            :root{
                --bg:#f3f5f9; --card:#fff; --txt:#1c2434; --mut:#7a8499; --line:#e3e7ef;
                --fv:#f5a623; --casa:#3b82f6; --batt:#22c55e; --rete:#8b5cf6;
            }
            @media (prefers-color-scheme: dark){
                :root{ --bg:#0f1420; --card:#182033; --txt:#e8ecf5; --mut:#8a94ab; --line:#26304a; }
            }
            *{box-sizing:border-box}
            body{margin:0;background:var(--bg);color:var(--txt);font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
            .wrap{max-width:980px;margin:0 auto;padding:20px 16px 40px}
            header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px}
            h1{font-size:1.2rem;margin:0}
            a{color:var(--casa);text-decoration:none;font-size:.9rem}
            .ctrl{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
            button,input,select{font:inherit;padding:6px 10px;border-radius:10px;border:1px solid var(--line);background:var(--card);color:var(--txt)}
            button{cursor:pointer}
            .box{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:14px;margin-bottom:14px}
            .box h2{font-size:.9rem;margin:0 0 8px;color:var(--mut);font-weight:600;text-transform:uppercase;letter-spacing:.05em}
            .canv{position:relative;height:320px}
            .canv.piccolo{height:180px}
            .tot{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:14px}
            .tot div{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:10px}
            .tot b{display:block;font-size:1.1rem}
            .tot span{font-size:.72rem;color:var(--mut)}
            #err{color:#ef4444;font-size:.9rem;margin-bottom:10px}
            @media(max-width:700px){.tot{grid-template-columns:repeat(2,1fr)}}
        </style>
    </head>
    <body>
        <div class="wrap">
            <header>
                <h1>📈 Storico energia</h1>
                <div class="ctrl">
                    <a href="live.php">← Live</a>
                    <button id="prev">◀</button>
                    <input type="date" id="giorno" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                    <button id="next">▶</button>
                    <select id="res">
                        <option value="60">1 min</option>
                        <option value="120" selected>2 min</option>
                        <option value="300" selected>5 min</option>
                        <option value="900">15 min</option>
                    </select>
                </div>
            </header>

            <div id="err"></div>

            <div class="tot">
                <div><b id="t_fv_day">–</b><span>Prodotta FV kWh</span></div>
                <div><b id="t_casa_day">–</b><span>Consumata casa kWh</span></div>
                <div><b id="t_prel_day">–</b><span>Prelevata da rete kWh</span></div>
                <div><b id="t_imm_day">–</b><span>Immessa in rete kWh</span></div>
                <div><b id="t_bc_day">–</b><span>Batteria caricata kWh</span></div>
                <div><b id="t_bs_day">–</b><span>Batteria scaricata kWh</span></div>
            </div>

            <div class="box"><h2>Potenze (kW)</h2><div class="canv"><canvas id="c1"></canvas></div></div>
            <div class="box"><h2>Carica batteria (%)</h2><div class="canv piccolo"><canvas id="c2"></canvas></div></div>
        </div>

        <script>
            const $ = id => document.getElementById(id);
            const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
            Chart.defaults.color = css('--mut');
            Chart.defaults.borderColor = css('--line');
            Chart.defaults.font.family = 'system-ui, sans-serif';

            const opz = (extra = {}) => ({
                responsive: true, maintainAspectRatio: false, animation: false, spanGaps: false,
                interaction: { mode: 'index', intersect: false },
                elements: { point: { radius: 0 }, line: { borderWidth: 2, tension: .25 } },
                scales: { x: { ticks: { maxTicksLimit: 13 } }, ...extra },
            });

            const c1 = new Chart($('c1'), {
                type: 'line',
                data: { labels: [], datasets: [
                    { label: 'Fotovoltaico', borderColor: css('--fv'),   backgroundColor: css('--fv') + '33', fill: true, data: [] },
                    { label: 'Casa',         borderColor: css('--casa'), data: [] },
                    { label: 'Batteria (+carica / −scarica)', borderColor: css('--batt'), data: [] },
                    { label: 'Rete (+immissione / −prelievo)', borderColor: css('--rete'), data: [] },
                ]},
                options: opz(),
            });
            const c2 = new Chart($('c2'), {
                type: 'line',
                data: { labels: [], datasets: [{ label: 'Batteria %', borderColor: css('--batt'), backgroundColor: css('--batt') + '33', fill: true, data: [] }] },
                options: opz({ y: { min: 0, max: 100 } }),
            });

            async function carica(){
                try{
                    const r = await (await fetch(`?json=1&giorno=${$('giorno').value}&b=${$('res').value}`, {cache: 'no-store'})).json();
                    if (!r.ok){ $('err').textContent = 'Errore: ' + r.errore; return; }
                    $('err').textContent = '';
                    c1.data.labels = c2.data.labels = r.labels;
                    c1.data.datasets[0].data = r.s.fv;
                    c1.data.datasets[1].data = r.s.casa;
                    c1.data.datasets[2].data = r.s.batt_kw;
                    c1.data.datasets[3].data = r.s.rete;
                    c2.data.datasets[0].data = r.s.batt_pct;
                    c1.update(); c2.update();
                    ['fv_day','casa_day','prel_day','imm_day','bc_day','bs_day'].forEach(k =>
                                                                                         $('t_' + k).textContent = r.tot ? Number(r.tot[k]).toFixed(2) : '–');
                } catch(e){ $('err').textContent = 'Errore di rete'; }
            }

            function sposta(g){
                const d = new Date($('giorno').value + 'T12:00:00');
                d.setDate(d.getDate() + g);
                const s = d.toISOString().slice(0, 10);
                if (s > $('giorno').max) return;
                $('giorno').value = s; carica();
            }
            $('prev').onclick = () => sposta(-1);
            $('next').onclick = () => sposta(1);
            $('giorno').onchange = $('res').onchange = carica;

            carica();
            setInterval(() => { if ($('giorno').value === $('giorno').max) carica(); }, 60000);  // oggi: aggiorna ogni minuto
        </script>
    </body>
</html>
