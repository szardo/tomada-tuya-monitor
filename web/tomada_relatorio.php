<?php
// Relatório de consumo por período livre (ex.: início e fim de uma impressão).
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
    return number_format($w, 1, ',', '.') . ' W';
}
function fmtEn($k) {
    if ($k >= 1000) return number_format($k/1000, 2, ',', '.') . ' MWh';
    return number_format($k, 3, ',', '.') . ' kWh';
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtDur($seg) {
    $seg = (int)round($seg);
    $h = intdiv($seg, 3600); $m = intdiv($seg % 3600, 60); $s = $seg % 60;
    if ($h > 0) return "{$h}h {$m}min";
    if ($m > 0) return "{$m}min {$s}s";
    return "{$s}s";
}

$temTomada = $pdo->query("SHOW TABLES LIKE 'consumo_tomada'")->fetch();
if (!$temTomada) {
    die("<p style='font-family:sans-serif;padding:2rem'>Tabela <code>consumo_tomada</code> ainda não existe. <a href='tomada.php'>Voltar</a></p>");
}

// ---------- Período (padrão: últimas 3 horas) ----------
$agora = date('Y-m-d\TH:i');
$def_ini = date('Y-m-d\TH:i', strtotime('-3 hours'));
$ini = $_GET['ini'] ?? $def_ini;
$fim = $_GET['fim'] ?? $agora;
// aceita "YYYY-MM-DDTHH:MM" (datetime-local) e valida
$valido = function ($s) { return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $s); };
if (!$valido($ini)) $ini = $def_ini;
if (!$valido($fim)) $fim = $agora;
$iniSql = str_replace('T', ' ', $ini) . ':00';
$fimSql = str_replace('T', ' ', $fim) . ':00';
if (strtotime($iniSql) >= strtotime($fimSql)) {
    // troca se usuário inverteu
    [$iniSql, $fimSql] = [$fimSql, $iniSql];
    [$ini, $fim] = [$fim, $ini];
}

// Tarifa opcional pro custo estimado. Defina TARIFA_KWH no config.php
// (ex.: define('TARIFA_KWH', 0.95);) se quiser ver o valor em R$/kWh.
$tarifa = defined('TARIFA_KWH') ? (float)TARIFA_KWH : null;

// ---------- Dados do período ----------
$s = $pdo->prepare("SELECT data_hora, potencia_w, corrente_ma, tensao_v, ligado FROM consumo_tomada WHERE data_hora >= ? AND data_hora <= ? ORDER BY data_hora ASC");
$s->execute([$iniSql, $fimSql]);
$linhas = $s->fetchAll();

$temDados = count($linhas) >= 2;
$energiaWh = 0; $tempoLigadoSeg = 0; $potMax = null; $potMaxHora = ''; $potMin = null;
$somaPot = 0; $somaTensao = 0; $somaCorrente = 0; $gapMaxSeg = 0;
$labelsChart = []; $valoresChart = [];
$LIMITE_GAP = 180; // acima disso (3 min) não extrapola energia — provável desligamento/offline

if ($temDados) {
    $n = count($linhas);
    for ($i = 0; $i < $n; $i++) {
        $r = $linhas[$i];
        $p = (float)$r['potencia_w'];
        $somaPot += $p; $somaTensao += (float)$r['tensao_v']; $somaCorrente += (float)$r['corrente_ma'];
        if ($potMax === null || $p > $potMax) { $potMax = $p; $potMaxHora = $r['data_hora']; }
        if ($potMin === null || $p < $potMin) { $potMin = $p; }
        $labelsChart[] = date('d/m H:i', strtotime($r['data_hora']));
        $valoresChart[] = $p;

        if ($i < $n - 1) {
            $dt = strtotime($linhas[$i+1]['data_hora']) - strtotime($r['data_hora']);
            $gapMaxSeg = max($gapMaxSeg, $dt);
            if ($dt <= $LIMITE_GAP) {
                // integração trapezoidal entre amostras consecutivas
                $pMed = ($p + (float)$linhas[$i+1]['potencia_w']) / 2;
                $energiaWh += $pMed * $dt / 3600;
                if ($r['ligado']) $tempoLigadoSeg += $dt;
            }
        }
    }
    $potMedia = $somaPot / $n;
    $tensaoMedia = $somaTensao / $n;
    $correnteMedia = $somaCorrente / $n;
    $duracaoSeg = strtotime($fimSql) - strtotime($iniSql);
    $custoEstimado = $tarifa !== null ? ($energiaWh / 1000) * $tarifa : null;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Relatório de consumo · Monitor de Tomada</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' }</script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <style>body { font-feature-settings: "tnum"; }</style>
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
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-3 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-violet-500 to-fuchsia-500 flex items-center justify-center text-xl shadow">📊</div>
            <h1 class="text-lg font-extrabold leading-tight">Relatório de consumo</h1>
        </div>
        <a href="tomada.php" class="text-sm py-2 px-4 rounded-lg border border-slate-300 dark:border-slate-700 hover:bg-white dark:hover:bg-slate-800 transition">← Voltar</a>
    </div>
</header>

<main class="max-w-5xl mx-auto px-4 sm:px-6 py-6 space-y-6">

    <!-- Formulário do período -->
    <section class="card p-4 sm:p-5">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-bold text-slate-500 mb-1">Início</label>
                <input type="datetime-local" name="ini" id="ini" value="<?= h($ini) ?>" required class="border border-slate-300 dark:border-slate-700 bg-transparent rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-violet-400">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 mb-1">Fim</label>
                <input type="datetime-local" name="fim" id="fim" value="<?= h($fim) ?>" required class="border border-slate-300 dark:border-slate-700 bg-transparent rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-violet-400">
            </div>
            <button type="submit" class="bg-violet-600 hover:bg-violet-700 text-white font-semibold py-2 px-5 rounded-lg shadow transition">Gerar relatório</button>
            <div class="flex flex-wrap gap-1.5 ml-auto">
                <button type="button" data-preset="2h" class="preset text-xs py-1.5 px-3 rounded-full border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800">Últimas 2h</button>
                <button type="button" data-preset="6h" class="preset text-xs py-1.5 px-3 rounded-full border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800">Últimas 6h</button>
                <button type="button" data-preset="12h" class="preset text-xs py-1.5 px-3 rounded-full border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800">Últimas 12h</button>
                <button type="button" data-preset="24h" class="preset text-xs py-1.5 px-3 rounded-full border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800">Últimas 24h</button>
                <button type="button" data-preset="hoje" class="preset text-xs py-1.5 px-3 rounded-full border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800">Hoje</button>
            </div>
        </form>
    </section>

    <?php if (!$temDados): ?>
        <section class="card p-8 text-center text-slate-400 text-sm">
            Sem leituras suficientes nesse período (mínimo 2 amostras). Verifique as datas ou aguarde mais coletas.
        </section>
    <?php else: ?>

    <!-- Resumo do período -->
    <section class="card p-4 sm:p-5" style="border-left:4px solid #8b5cf6">
        <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Período analisado</p>
        <p class="text-lg font-black mt-1"><?= date('d/m/Y H:i', strtotime($iniSql)) ?> → <?= date('d/m/Y H:i', strtotime($fimSql)) ?> <span class="text-sm font-normal text-slate-400">(<?= fmtDur($duracaoSeg) ?>)</span></p>
        <?php if ($gapMaxSeg > $LIMITE_GAP): ?>
            <p class="text-xs text-amber-600 dark:text-amber-400 mt-2">⚠️ Houve uma falha de leitura de até <?= fmtDur($gapMaxSeg) ?> nesse período (tomada/rede offline?) — o consumo nesses intervalos não entrou na conta de energia, então o total pode estar levemente subestimado.</p>
        <?php endif; ?>
    </section>

    <!-- KPIs do relatório -->
    <section class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="card p-5 bg-gradient-to-br from-violet-50 to-white dark:from-violet-950/30 dark:to-slate-900">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Consumo total</p>
            <p class="text-3xl font-black mt-1"><?= fmtEn($energiaWh / 1000) ?></p>
            <p class="text-xs text-slate-400 mt-2"><?= $custoEstimado !== null ? '≈ R$ ' . number_format($custoEstimado, 2, ',', '.') . ' (tarifa R$ ' . number_format($tarifa, 4, ',', '.') . '/kWh)' : 'defina TARIFA_KWH no config.php pra ver o custo estimado' ?></p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Potência média</p>
            <p class="text-2xl font-black mt-1"><?= fmtPot($potMedia) ?></p>
            <p class="text-xs text-slate-400 mt-2">min <?= fmtPot($potMin) ?> · máx <?= fmtPot($potMax) ?></p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Pico de potência</p>
            <p class="text-2xl font-black mt-1"><?= fmtPot($potMax) ?></p>
            <p class="text-xs text-slate-400 mt-2">às <?= date('d/m H:i', strtotime($potMaxHora)) ?></p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Tempo ligada</p>
            <p class="text-2xl font-black mt-1"><?= fmtDur($tempoLigadoSeg) ?></p>
            <p class="text-xs text-slate-400 mt-2"><?= number_format($tempoLigadoSeg / max($duracaoSeg, 1) * 100, 0) ?>% do período</p>
        </div>
    </section>

    <!-- Tensão / corrente médias -->
    <section class="grid grid-cols-2 gap-4">
        <div class="card p-4 flex items-center justify-between">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Tensão média</span>
            <span class="text-lg font-black"><?= number_format($tensaoMedia, 1, ',', '.') ?> V</span>
        </div>
        <div class="card p-4 flex items-center justify-between">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Corrente média</span>
            <span class="text-lg font-black"><?= number_format($correnteMedia, 0, ',', '.') ?> mA</span>
        </div>
    </section>

    <!-- Gráfico do período -->
    <section class="card overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-slate-200 dark:border-slate-800">
            <h2 class="font-bold">Potência durante o período</h2>
            <p class="text-xs text-slate-500"><?= count($linhas) ?> leituras · ideal pra ver o perfil de uma impressão (aquecimento, platô, resfriamento)</p>
        </div>
        <div class="p-4 sm:p-5"><div class="h-72 sm:h-80"><canvas id="chartPeriodo"></canvas></div></div>
    </section>

    <?php endif; ?>

    <footer class="text-center text-xs text-slate-400 pb-4">Consumo calculado por integração trapezoidal da potência entre leituras</footer>
</main>

<script>
    document.querySelectorAll('.preset').forEach(btn => {
        btn.onclick = () => {
            const now = new Date();
            const pad = n => String(n).padStart(2, '0');
            const toLocal = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
            let ini = new Date(now);
            switch (btn.dataset.preset) {
                case '2h': ini.setHours(ini.getHours() - 2); break;
                case '6h': ini.setHours(ini.getHours() - 6); break;
                case '12h': ini.setHours(ini.getHours() - 12); break;
                case '24h': ini.setHours(ini.getHours() - 24); break;
                case 'hoje': ini.setHours(0, 0, 0, 0); break;
            }
            document.getElementById('ini').value = toLocal(ini);
            document.getElementById('fim').value = toLocal(now);
        };
    });

    <?php if ($temDados): ?>
    const root = document.documentElement;
    const dark = root.classList.contains('dark');
    Chart.defaults.color = dark ? '#94a3b8' : '#64748b';
    Chart.defaults.font.family = 'ui-sans-serif, system-ui, sans-serif';
    Chart.defaults.maintainAspectRatio = false;
    const grid = dark ? '#1e293b' : '#e2e8f0';
    const ctx = document.getElementById('chartPeriodo').getContext('2d');
    const grad = ctx.createLinearGradient(0, 0, 0, 320);
    grad.addColorStop(0, 'rgba(139,92,246,.45)'); grad.addColorStop(1, 'rgba(139,92,246,0)');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($labelsChart) ?>,
            datasets: [{
                label: 'Potência (W)',
                data: <?= json_encode($valoresChart) ?>,
                borderColor: '#8b5cf6', backgroundColor: grad, fill: true,
                tension: 0.3, pointRadius: 0, pointHoverRadius: 4, borderWidth: 2.5
            }]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => c.parsed.y.toLocaleString('pt-BR', {maximumFractionDigits:1}) + ' W' } } },
            scales: {
                x: { grid: { display: false }, ticks: { maxTicksLimit: 12, autoSkip: true } },
                y: { grid: { color: grid }, beginAtZero: true, title: { display: true, text: 'W' } }
            }
        }
    });
    <?php endif; ?>
</script>
</body>
</html>
