<?php
// Painel principal do monitor de tomada Tuya.
if (!file_exists(__DIR__ . '/config.php')) {
    die("Arquivo config.php não encontrado. Copie config.example.php para config.php e preencha (veja o README).");
}
require __DIR__ . '/config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET time_zone='" . DB_TIMEZONE . "'");
} catch (Exception $e) { die("Erro de conexão com o banco: " . htmlspecialchars($e->getMessage())); }

function fmtPot($w) {
    if ($w >= 1000000) return number_format($w/1000000, 2, ',', '.') . ' MW';
    if ($w >= 1000) return number_format($w/1000, 2, ',', '.') . ' kW';
    return number_format($w, 0, ',', '.') . ' W';
}
function fmtEn($k) {
    if ($k >= 1000) return number_format($k/1000, 2, ',', '.') . ' MWh';
    return number_format($k, 3, ',', '.') . ' kWh';
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function delta($atual, $ant, $inv = false) {
    $atual = (float)$atual; $ant = (float)$ant; // valores do banco chegam como texto ("0.0000" é truthy em PHP)
    if ($ant == 0) return '<span class="text-slate-400">sem base de comparação</span>';
    $p = ($atual - $ant) / $ant * 100;
    $bom = $inv ? $p <= 0 : $p >= 0;
    $cls = $bom ? 'text-emerald-600' : 'text-rose-600';
    return '<span class="' . $cls . ' font-semibold">' . ($p >= 0 ? '▲' : '▼') . ' ' . number_format(abs($p), 1, ',', '.') . '%</span>';
}

$data_sel = $_GET['data'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_sel)) $data_sel = date('Y-m-d');
$visao = in_array($_GET['visao'] ?? '', ['dia', 'semana', 'mes']) ? $_GET['visao'] : 'dia';
$ts = strtotime($data_sel);
$mes_filtro = date('Y-m', $ts);
$mesesLong = ["janeiro","fevereiro","março","abril","maio","junho","julho","agosto","setembro","outubro","novembro","dezembro"];
$diasSem = ["dom","seg","ter","qua","qui","sex","sáb"];
$hoje = date('Y-m-d');

$temTomada = $pdo->query("SHOW TABLES LIKE 'consumo_tomada'")->fetch();
if (!$temTomada) {
    die("<p style='font-family:sans-serif;padding:2rem'>Tabela <code>consumo_tomada</code> ainda não existe. Rode <code>schema.sql</code> e depois o coletor. Veja o README.</p>");
}

// ---------- Leitura mais recente ----------
$tom = $pdo->query("SELECT * FROM consumo_tomada ORDER BY data_hora DESC LIMIT 1")->fetch();
$idadeSeg = $tom ? time() - strtotime($tom['data_hora']) : PHP_INT_MAX;
$online = $idadeSeg < 180;
$idadeTxt = $idadeSeg < 60 ? 'agora' : ($idadeSeg < 3600 ? 'há ' . floor($idadeSeg/60) . ' min' : ($idadeSeg < 86400 ? 'há ' . floor($idadeSeg/3600) . ' h' : 'há ' . floor($idadeSeg/86400) . ' d'));

// ---------- Consumo diário (integração de potência ~1 amostra/min -> Wh = SUM(W)/60) ----------
function consumoDiario(PDO $pdo, $ini, $fimExcl) {
    $s = $pdo->prepare("SELECT DATE(data_hora) d, SUM(potencia_w)/60/1000 v FROM consumo_tomada WHERE data_hora >= ? AND data_hora < ? GROUP BY DATE(data_hora) ORDER BY d");
    $s->execute([$ini, $fimExcl]);
    return $s->fetchAll(PDO::FETCH_KEY_PAIR);
}
$iniMes = $mes_filtro . '-01';
$fimMes = date('Y-m-d', strtotime($iniMes . ' +1 month'));
$diasMes = consumoDiario($pdo, $iniMes, $fimMes);
$cMes = array_sum($diasMes);
$iniMesAnt = date('Y-m-d', strtotime($iniMes . ' -1 month'));
$diasMesAnt = consumoDiario($pdo, $iniMesAnt, $iniMes);
$cMesAnt = array_sum($diasMesAnt);
$cDia = $diasMes[$data_sel] ?? 0;
$ontem = date('Y-m-d', strtotime($data_sel . ' -1 day'));
$cOntem = $diasMes[$ontem] ?? ($diasMesAnt[$ontem] ?? 0);
$diaN = (int)date('j', $ts);
$cMesAntParcial = 0;
foreach ($diasMesAnt as $d => $v) if ((int)substr($d, 8, 2) <= $diaN) $cMesAntParcial += $v;
$cMesAntCmp = ($mes_filtro === date('Y-m')) ? $cMesAntParcial : $cMesAnt;

// Consumo de hoje (dado ao vivo, não depende do cache de $diasMes)
$te = $pdo->prepare("SELECT SUM(potencia_w)/60/1000 v FROM consumo_tomada WHERE data_hora >= ? AND data_hora < ?");
$te->execute([$hoje, date('Y-m-d', strtotime('+1 day'))]);
$cHoje = (float)($te->fetch()['v'] ?? 0);

// Pico de hoje e tempo ligada hoje
$sp = $pdo->prepare("SELECT potencia_w v, DATE_FORMAT(data_hora, '%H:%i') h FROM consumo_tomada WHERE data_hora >= ? ORDER BY potencia_w DESC LIMIT 1");
$sp->execute([$hoje]);
$picoHoje = $sp->fetch();
$sl = $pdo->prepare("SELECT COUNT(*) n FROM consumo_tomada WHERE data_hora >= ? AND ligado = 1");
$sl->execute([$hoje]);
$minLigadaHoje = (int)($sl->fetch()['n'] ?? 0); // ~1 amostra/min

// ---------- Gráfico principal ----------
$labelsGen = []; $valoresGen = [];
$tipoGen = ($visao === 'dia') ? 'line' : 'bar';
if ($visao === 'dia') {
    $s = $pdo->prepare("SELECT DATE_FORMAT(data_hora, '%H:%i') l, potencia_w v FROM consumo_tomada WHERE data_hora >= ? AND data_hora < ? ORDER BY data_hora");
    $s->execute([$data_sel, date('Y-m-d', strtotime($data_sel . ' +1 day'))]);
    foreach ($s->fetchAll() as $r) { $labelsGen[] = $r['l']; $valoresGen[] = (float)$r['v']; }
    $titGen = "Potência ao longo do dia";
    $subGen = $diasSem[date('w', $ts)] . ", " . date('d/m/Y', $ts);
    $unid = 'W';
} elseif ($visao === 'semana') {
    $dw = (int)date('w', $ts);
    $ini_sem = date('Y-m-d', strtotime("-" . (($dw + 6) % 7) . " days", $ts));
    $fim_sem = date('Y-m-d', strtotime($ini_sem . ' +6 days'));
    $dadosSem = consumoDiario($pdo, $ini_sem, date('Y-m-d', strtotime($fim_sem . ' +1 day')));
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime($ini_sem . " +$i days"));
        $labelsGen[] = $diasSem[date('w', strtotime($d))] . ' ' . date('d/m', strtotime($d));
        $valoresGen[] = round((float)($dadosSem[$d] ?? 0), 3);
    }
    $titGen = "Consumo diário da semana";
    $subGen = date('d/m', strtotime($ini_sem)) . " a " . date('d/m/Y', strtotime($fim_sem)) . " · total " . fmtEn(array_sum($valoresGen));
    $unid = 'kWh';
} else {
    $nd = (int)date('t', strtotime($iniMes));
    for ($i = 1; $i <= $nd; $i++) {
        $d = $mes_filtro . '-' . str_pad($i, 2, '0', STR_PAD_LEFT);
        $labelsGen[] = str_pad($i, 2, '0', STR_PAD_LEFT);
        $valoresGen[] = round((float)($diasMes[$d] ?? 0), 3);
    }
    $titGen = "Consumo diário do mês";
    $subGen = ucfirst($mesesLong[(int)date('n', $ts) - 1]) . " · total " . fmtEn($cMes);
    $unid = 'kWh';
}

$passo = ['dia' => '1 day', 'semana' => '7 days', 'mes' => '1 month'][$visao];
$prev = date('Y-m-d', strtotime($data_sel . " -$passo"));
$next = date('Y-m-d', strtotime($data_sel . " +$passo"));
function url($d, $v) { return '?data=' . $d . '&visao=' . $v; }
$diasComDado = count($diasMes);
$mediaDia = $diasComDado ? $cMes / $diasComDado : 0;
$melhorDia = $diasMes ? array_keys($diasMes, max($diasMes))[0] : null;
$fmtMaxDia = $melhorDia ? date('d/m', strtotime($melhorDia)) . ' · ' . fmtEn($diasMes[$melhorDia]) : '---';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Monitor de Tomada Tuya</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' }</script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <style>
        body { font-feature-settings: "tnum"; }
        @keyframes pulse-dot { 0%,100% { opacity: 1 } 50% { opacity: .35 } }
        .live-dot { animation: pulse-dot 1.8s ease-in-out infinite; }
    </style>
    <script>
        try { const t = localStorage.getItem('tomadaTheme'); if (t === 'dark' || (!t && matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.classList.add('dark'); } catch (e) {}
    </script>
</head>
<body class="bg-slate-100 dark:bg-slate-950 text-slate-800 dark:text-slate-100 font-sans antialiased">
<style>
    .card { background:#fff; border:1px solid #e2e8f0; border-radius:1rem; box-shadow:0 1px 2px rgb(0 0 0 / .05); }
    .dark .card { background:#0f172a; border-color:#1e293b; }
</style>

<header class="sticky top-0 z-20 backdrop-blur bg-slate-100/80 dark:bg-slate-950/80 border-b border-slate-200 dark:border-slate-800">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-3 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-violet-500 to-fuchsia-500 flex items-center justify-center text-xl shadow">🔌</div>
            <div>
                <h1 class="text-lg font-extrabold leading-tight">Monitor de Tomada</h1>
                <p class="text-xs text-slate-500 flex items-center gap-1.5">
                    <span class="inline-block w-2 h-2 rounded-full <?= $online ? 'bg-violet-500 live-dot' : 'bg-slate-400' ?>"></span>
                    <?= $online ? 'Tomada online' : 'Sem leitura recente' ?> · última leitura <?= $idadeTxt ?>
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button id="btnRefresh" class="text-sm font-semibold py-2 px-3 rounded-lg border border-slate-300 dark:border-slate-700 hover:bg-white dark:hover:bg-slate-800 transition">⏱️ Auto: OFF</button>
            <button id="btnTheme" title="Alternar tema" class="text-sm py-2 px-3 rounded-lg border border-slate-300 dark:border-slate-700 hover:bg-white dark:hover:bg-slate-800 transition">🌓</button>
            <a href="tomada_relatorio.php" class="text-sm font-semibold py-2 px-4 rounded-lg border border-violet-300 dark:border-violet-800 text-violet-700 dark:text-violet-300 hover:bg-violet-50 dark:hover:bg-violet-950/40 transition">📊 Relatório de período</a>
        </div>
    </div>
</header>

<main class="max-w-7xl mx-auto px-4 sm:px-6 py-6 space-y-6">

    <!-- KPIs -->
    <section class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="card p-5 col-span-2 bg-gradient-to-br from-violet-50 to-white dark:from-violet-950/30 dark:to-slate-900" style="border-left:4px solid #8b5cf6">
            <div class="flex items-center gap-2">
                <span class="inline-block w-2.5 h-2.5 rounded-full <?= ($online && $tom['ligado']) ? 'bg-violet-500 live-dot' : 'bg-slate-400' ?>"></span>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500"><?= ($online && $tom['ligado']) ? 'Ligada agora' : 'Desligada' ?></p>
            </div>
            <p class="text-4xl font-black mt-1"><?= $tom ? fmtPot($tom['potencia_w']) : '---' ?></p>
            <p class="text-xs text-slate-500 mt-2"><?= $picoHoje ? 'Pico de hoje: ' . fmtPot($picoHoje['v']) . ' às ' . h($picoHoje['h']) : 'Sem leitura hoje' ?></p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Consumo hoje (aprox.)</p>
            <p class="text-2xl font-black mt-1"><?= fmtEn($cHoje) ?></p>
            <p class="text-xs mt-2"><?= delta($cHoje, $cOntem) ?> <span class="text-slate-400">vs. ontem</span></p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Tempo ligada hoje</p>
            <p class="text-2xl font-black mt-1"><?= floor($minLigadaHoje/60) ?>h <?= $minLigadaHoje%60 ?>min</p>
            <p class="text-xs text-slate-400 mt-2">estimado por amostras/min</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Consumo do mês</p>
            <p class="text-2xl font-black mt-1"><?= fmtEn($cMes) ?></p>
            <p class="text-xs mt-2"><?= delta($cMes, $cMesAntCmp) ?> <span class="text-slate-400">vs. mês ant.</span></p>
        </div>
    </section>

    <!-- Tensão / corrente -->
    <section class="grid grid-cols-2 gap-4">
        <div class="card p-4 flex items-center justify-between">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Tensão</span>
            <span class="text-lg font-black"><?= $tom ? number_format($tom['tensao_v'], 1, ',', '.') . ' V' : '---' ?></span>
        </div>
        <div class="card p-4 flex items-center justify-between">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Corrente</span>
            <span class="text-lg font-black"><?= $tom ? number_format($tom['corrente_ma'], 0, ',', '.') . ' mA' : '---' ?></span>
        </div>
    </section>

    <!-- Gráfico principal -->
    <section class="card overflow-hidden">
        <div class="p-4 sm:p-5 flex flex-col md:flex-row md:items-center justify-between gap-3 border-b border-slate-200 dark:border-slate-800">
            <div>
                <h2 class="font-bold"><?= h($titGen) ?></h2>
                <p class="text-xs text-slate-500"><?= h($subGen) ?></p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="inline-flex rounded-lg border border-slate-300 dark:border-slate-700 overflow-hidden text-sm">
                    <?php foreach (['dia' => 'Dia', 'semana' => 'Semana', 'mes' => 'Mês'] as $k => $rot): ?>
                        <a href="<?= url($data_sel, $k) ?>" class="px-3 py-1.5 <?= $visao === $k ? 'bg-slate-800 text-white dark:bg-violet-500 dark:text-white font-semibold' : 'hover:bg-slate-100 dark:hover:bg-slate-800' ?>"><?= $rot ?></a>
                    <?php endforeach; ?>
                </div>
                <div class="inline-flex items-center gap-1 text-sm">
                    <a href="<?= url($prev, $visao) ?>" class="px-2.5 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800" title="Anterior">‹</a>
                    <form method="GET" class="inline">
                        <input type="hidden" name="visao" value="<?= h($visao) ?>">
                        <input type="date" name="data" value="<?= h($data_sel) ?>" onchange="this.form.submit()" class="border border-slate-300 dark:border-slate-700 bg-transparent rounded-lg px-2 py-1 text-sm outline-none focus:ring-2 focus:ring-violet-400">
                    </form>
                    <a href="<?= url($next, $visao) ?>" class="px-2.5 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800" title="Próximo">›</a>
                    <a href="<?= url($hoje, $visao) ?>" class="px-2.5 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800">Hoje</a>
                </div>
            </div>
        </div>
        <div class="p-4 sm:p-5">
            <?php if (!$valoresGen || !array_sum($valoresGen)): ?>
                <div class="h-72 flex items-center justify-center text-slate-400 text-sm">Sem dados de consumo neste período.</div>
            <?php else: ?>
                <div class="h-72 sm:h-80"><canvas id="chartTom"></canvas></div>
            <?php endif; ?>
        </div>
        <?php if ($visao !== 'dia'): ?>
        <div class="px-5 pb-5 grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
            <div class="rounded-xl bg-slate-50 dark:bg-slate-800/50 p-3"><p class="text-xs text-slate-500">Maior consumo do mês</p><p class="font-bold"><?= h($fmtMaxDia) ?></p></div>
            <div class="rounded-xl bg-slate-50 dark:bg-slate-800/50 p-3"><p class="text-xs text-slate-500">Média diária (mês)</p><p class="font-bold"><?= fmtEn($mediaDia) ?></p></div>
            <div class="rounded-xl bg-slate-50 dark:bg-slate-800/50 p-3"><p class="text-xs text-slate-500">Dias com dados</p><p class="font-bold"><?= $diasComDado ?></p></div>
            <div class="rounded-xl bg-slate-50 dark:bg-slate-800/50 p-3"><p class="text-xs text-slate-500">Mês anterior</p><p class="font-bold"><?= fmtEn($cMesAnt) ?></p></div>
        </div>
        <?php endif; ?>
    </section>

    <footer class="text-center text-xs text-slate-400 pb-4">Leitura local minuto a minuto, sem depender da nuvem Tuya · consumo em kWh é uma estimativa por integração da potência</footer>
</main>

<script>
    const root = document.documentElement;
    document.getElementById('btnTheme').onclick = () => {
        root.classList.toggle('dark');
        try { localStorage.setItem('tomadaTheme', root.classList.contains('dark') ? 'dark' : 'light'); } catch (e) {}
        location.reload();
    };
    const btn = document.getElementById('btnRefresh');
    let refresh = false;
    try { refresh = localStorage.getItem('tomadaRefresh') === 'true'; } catch (e) {}
    function upUI() {
        btn.textContent = refresh ? '⏱️ Auto: ON' : '⏱️ Auto: OFF';
        btn.classList.toggle('bg-emerald-600', refresh);
        btn.classList.toggle('text-white', refresh);
        btn.classList.toggle('border-emerald-600', refresh);
    }
    btn.onclick = () => { refresh = !refresh; try { localStorage.setItem('tomadaRefresh', refresh); } catch (e) {} upUI(); };
    upUI();
    setInterval(() => { if (refresh) location.reload(); }, 60000);

    const dark = root.classList.contains('dark');
    const txt = dark ? '#94a3b8' : '#64748b';
    const grid = dark ? '#1e293b' : '#e2e8f0';
    Chart.defaults.color = txt;
    Chart.defaults.font.family = 'ui-sans-serif, system-ui, sans-serif';
    Chart.defaults.maintainAspectRatio = false;
    const fmt = (v, d = 1) => Number(v).toLocaleString('pt-BR', { maximumFractionDigits: d });
    const baseOpts = {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: false } },
        scales: { x: { grid: { display: false } }, y: { grid: { color: grid }, beginAtZero: true } }
    };

    const elTom = document.getElementById('chartTom');
    if (elTom) {
        const ctx = elTom.getContext('2d');
        const isLine = '<?= $tipoGen ?>' === 'line';
        const grad = ctx.createLinearGradient(0, 0, 0, 320);
        grad.addColorStop(0, 'rgba(139,92,246,.45)'); grad.addColorStop(1, 'rgba(139,92,246,0)');
        new Chart(elTom, {
            type: '<?= $tipoGen ?>',
            data: {
                labels: <?= json_encode($labelsGen) ?>,
                datasets: [{
                    label: '<?= $unid ?>',
                    data: <?= json_encode($valoresGen) ?>,
                    borderColor: '#8b5cf6',
                    backgroundColor: isLine ? grad : '#8b5cf6',
                    borderRadius: 6, fill: isLine, tension: 0.35, pointRadius: 0, pointHoverRadius: 4, borderWidth: isLine ? 2.5 : 0
                }]
            },
            options: {
                ...baseOpts,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => fmt(c.parsed.y, 3) + ' <?= $unid ?>' } } },
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: isLine ? 12 : 31, autoSkip: true } },
                    y: { grid: { color: grid }, beginAtZero: true, title: { display: true, text: '<?= $unid ?>' } }
                }
            }
        });
    }
</script>
</body>
</html>
