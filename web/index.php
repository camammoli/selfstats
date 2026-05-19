<?php
// index.php — SelfStats — dashboard

require_once __DIR__ . '/db.php';

$config_file = __DIR__ . '/config.php';
if (!file_exists($config_file)) {
    die('SelfStats no está configurado. Copiá config.example.php como config.php y completá los valores.');
}
$config = require $config_file;

// Auth — POST login → cookie → redirect limpio; GET key solo para links directos (collector cron)
$key = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = trim($_POST['key'] ?? '');
} elseif (isset($_COOKIE['ss_key'])) {
    $key = $_COOKIE['ss_key'];
} elseif (isset($_GET['key'])) {
    // Link directo con clave (ej: primer acceso desde Telegram)
    // Validar y redirigir a URL limpia para que no quede en historial
    $key = trim($_GET['key']);
    if (hash_equals($config['admin_key'] ?? '', $key)) {
        setcookie('ss_key', $key, time() + 86400 * 30, '/', '', true, true);
        $qs = http_build_query(array_diff_key($_GET, ['key' => '']));
        header('Location: ?' . $qs);
        exit;
    }
}

$authed = hash_equals($config['admin_key'] ?? '', $key);
if (!$authed) {
    http_response_code(401);
    die('<!doctype html><html><head><meta charset="utf-8"><title>SelfStats</title>
<style>*{box-sizing:border-box}body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#0f172a;color:#f1f5f9}
form{background:#1e293b;padding:2rem;border-radius:12px;border:1px solid #334155;min-width:280px;text-align:center}
h2{margin:0 0 1.5rem;font-size:1.1rem;letter-spacing:-.02em}span{color:#818cf8}
input{width:100%;padding:.6rem .8rem;background:#0f172a;border:1px solid #334155;border-radius:7px;color:#f1f5f9;font-size:.95rem;margin-bottom:1rem;outline:none}
input:focus{border-color:#818cf8}button{width:100%;padding:.6rem;background:#6366f1;color:#fff;border:none;border-radius:7px;font-size:.95rem;cursor:pointer}
button:hover{background:#818cf8}</style></head>
<body><form method="post">
    <h2>Self<span>Stats</span></h2>
    <input type="password" name="key" placeholder="Clave de acceso" autofocus>
    <button type="submit">Entrar</button>
</form></body></html>');
}
setcookie('ss_key', $key, time() + 86400 * 30, '/', '', true, true);

// Parámetros de vista
$days    = max(7, min(365, (int)($_GET['days'] ?? 30)));
$section = $_GET['s'] ?? 'overview';

// Datos
$gh_totals  = ss_get_github_totals($days);
$gh_repos   = ss_get_github_repos();
$sources    = ss_get_sources();
$metrics    = ss_get_latest_metrics();
$last_col   = ss_last_collection();

// Agrupar métricas por source
$metrics_by_source = [];
foreach ($metrics as $m) {
    $metrics_by_source[$m['source']][] = $m;
}

// Para el gráfico de GitHub: datos del repo seleccionado
$gh_repo_sel = $_GET['repo'] ?? ($gh_repos[0] ?? '');
$gh_history  = $gh_repo_sel ? ss_get_github_history($gh_repo_sel, $days) : [];

// Para el gráfico de métricas: source+metric seleccionados
$src_sel     = $_GET['src'] ?? ($sources[0] ?? '');
$src_metrics = $src_sel ? ss_get_source_metrics($src_sel) : [];
$metric_sel  = $_GET['metric'] ?? ($src_metrics[0] ?? '');
$metric_hist = ($src_sel && $metric_sel) ? ss_get_metric_history($src_sel, $metric_sel, $days) : [];

function base_url(): string {
    $k = $_GET['key'] ?? '';
    return '?key=' . urlencode($k);
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SelfStats</title>
<style>
:root {
    --bg: #f8fafc; --bg2: #fff; --border: #e2e8f0;
    --text: #1e293b; --text2: #64748b; --accent: #6366f1;
    --ok: #22c55e; --err: #ef4444; --warn: #f59e0b;
}
@media (prefers-color-scheme: dark) {
    :root {
        --bg: #0f172a; --bg2: #1e293b; --border: #334155;
        --text: #f1f5f9; --text2: #94a3b8; --accent: #818cf8;
        --ok: #4ade80; --err: #f87171; --warn: #fbbf24;
    }
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: system-ui, sans-serif; background: var(--bg); color: var(--text); font-size: 14px; }

header { background: var(--bg2); border-bottom: 1px solid var(--border); padding: 0 1.5rem; display: flex; align-items: center; gap: 1.5rem; height: 52px; }
header h1 { font-size: 1rem; font-weight: 700; letter-spacing: -.02em; }
header h1 span { color: var(--accent); }
.last-col { color: var(--text2); font-size: .8rem; margin-left: auto; }

nav { display: flex; gap: .25rem; padding: .75rem 1.5rem; border-bottom: 1px solid var(--border); background: var(--bg2); }
nav a { padding: .35rem .75rem; border-radius: 6px; text-decoration: none; color: var(--text2); font-size: .85rem; }
nav a.active, nav a:hover { background: var(--accent); color: #fff; }

.controls { display: flex; gap: .75rem; align-items: center; padding: .75rem 1.5rem; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.controls label { color: var(--text2); font-size: .8rem; }
.controls select { background: var(--bg2); color: var(--text); border: 1px solid var(--border); border-radius: 6px; padding: .3rem .5rem; font-size: .85rem; }

main { padding: 1.5rem; max-width: 1200px; }

.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.card { background: var(--bg2); border: 1px solid var(--border); border-radius: 10px; padding: 1rem 1.25rem; }
.card .label { font-size: .75rem; color: var(--text2); text-transform: uppercase; letter-spacing: .05em; margin-bottom: .35rem; }
.card .value { font-size: 1.75rem; font-weight: 700; line-height: 1; }
.card .sub { font-size: .75rem; color: var(--text2); margin-top: .35rem; }

.chart-wrap { background: var(--bg2); border: 1px solid var(--border); border-radius: 10px; padding: 1.25rem; margin-bottom: 1.5rem; }
.chart-wrap h2 { font-size: .85rem; color: var(--text2); margin-bottom: 1rem; text-transform: uppercase; letter-spacing: .05em; }
.chart-wrap canvas { width: 100% !important; }

.gh-table { width: 100%; border-collapse: collapse; background: var(--bg2); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; margin-bottom: 1.5rem; }
.gh-table th { text-align: left; padding: .6rem 1rem; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: var(--text2); border-bottom: 1px solid var(--border); }
.gh-table td { padding: .6rem 1rem; border-bottom: 1px solid var(--border); }
.gh-table tr:last-child td { border-bottom: none; }
.gh-table tr:hover td { background: var(--bg); }
.gh-table .repo-name { font-weight: 600; }
.gh-table .num { text-align: right; font-variant-numeric: tabular-nums; }

.log-table { width: 100%; border-collapse: collapse; font-size: .8rem; }
.log-table td { padding: .4rem .75rem; border-bottom: 1px solid var(--border); }
.log-table .ts { color: var(--text2); white-space: nowrap; }
.log-table .status-ok   { color: var(--ok); }
.log-table .status-error { color: var(--err); }
.log-table .status-skip  { color: var(--warn); }

.empty { color: var(--text2); padding: 2rem; text-align: center; }
.section-title { font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: var(--text2); margin-bottom: .75rem; margin-top: 1.5rem; }
.source-block { margin-bottom: 2rem; }
.source-block h3 { font-size: .9rem; margin-bottom: .75rem; padding-bottom: .5rem; border-bottom: 1px solid var(--border); }

@media (max-width: 600px) { .controls { gap: .5rem; } main { padding: 1rem; } }
</style>
</head>
<body>

<header>
    <h1>Self<span>Stats</span></h1>
    <?php if ($last_col): ?>
    <span class="last-col">Última recolección: <?= htmlspecialchars(substr($last_col, 0, 16)) ?> UTC</span>
    <?php endif; ?>
</header>

<nav>
    <?php
    $sections = ['overview' => 'Resumen', 'github' => 'GitHub', 'tools' => 'Herramientas', 'log' => 'Log'];
    foreach ($sections as $slug => $label):
        $active = ($section === $slug) ? ' class="active"' : '';
        echo "<a href=\"" . base_url() . "&s=$slug&days=$days\"$active>$label</a>";
    endforeach;
    ?>
</nav>

<div class="controls">
    <label>Período:</label>
    <select onchange="location.href=this.value">
        <?php foreach ([7, 14, 30, 90] as $d): ?>
        <option value="<?= base_url() . "&s=$section&days=$d" ?>" <?= $days == $d ? 'selected' : '' ?>>
            <?= $d ?> días
        </option>
        <?php endforeach; ?>
    </select>

    <?php if ($section === 'github' && $gh_repos): ?>
    <label>Repo:</label>
    <select onchange="location.href=this.value">
        <?php foreach ($gh_repos as $r): ?>
        <option value="<?= base_url() . "&s=github&days=$days&repo=" . urlencode($r) ?>" <?= $gh_repo_sel === $r ? 'selected' : '' ?>>
            <?= htmlspecialchars($r) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <?php if ($section === 'tools' && $sources): ?>
    <label>Fuente:</label>
    <select onchange="location.href=this.value">
        <?php foreach ($sources as $s): ?>
        <option value="<?= base_url() . "&s=tools&days=$days&src=" . urlencode($s) . "&metric=" . urlencode($metric_sel) ?>" <?= $src_sel === $s ? 'selected' : '' ?>>
            <?= htmlspecialchars($s) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php if ($src_metrics): ?>
    <label>Métrica:</label>
    <select onchange="location.href=this.value">
        <?php foreach ($src_metrics as $m): ?>
        <option value="<?= base_url() . "&s=tools&days=$days&src=" . urlencode($src_sel) . "&metric=" . urlencode($m) ?>" <?= $metric_sel === $m ? 'selected' : '' ?>>
            <?= htmlspecialchars($m) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php endif; ?>
</div>

<main>

<?php if ($section === 'overview'): ?>

    <p class="section-title">GitHub — últimos <?= $days ?> días</p>
    <?php if ($gh_totals): ?>
    <table class="gh-table">
        <thead><tr>
            <th>Repo</th>
            <th class="num">Vistas</th><th class="num">Únicas</th>
            <th class="num">Clones</th><th class="num">Únicos</th>
            <th>Último dato</th>
        </tr></thead>
        <tbody>
        <?php foreach ($gh_totals as $r): ?>
        <tr>
            <td class="repo-name"><?= htmlspecialchars($r['repo']) ?></td>
            <td class="num"><?= number_format($r['total_views']) ?></td>
            <td class="num"><?= number_format($r['total_views_unique']) ?></td>
            <td class="num"><?= number_format($r['total_clones']) ?></td>
            <td class="num"><?= number_format($r['total_clones_unique']) ?></td>
            <td><?= htmlspecialchars($r['last_date']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <p class="empty">Sin datos de GitHub. Ejecutá el collector para empezar.</p>
    <?php endif; ?>

    <?php if ($metrics_by_source): ?>
    <p class="section-title">Herramientas — último valor disponible</p>
    <?php foreach ($metrics_by_source as $src => $ms): ?>
    <div class="source-block">
        <h3><?= htmlspecialchars($src) ?></h3>
        <div class="grid">
        <?php foreach ($ms as $m): ?>
            <div class="card">
                <div class="label"><?= htmlspecialchars($m['metric']) ?></div>
                <div class="value"><?= number_format($m['value']) ?></div>
                <div class="sub"><?= htmlspecialchars($m['date']) ?></div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php elseif (empty($config['sources'])): ?>
    <p class="empty">No hay fuentes configuradas. Editá config.php para agregar herramientas.</p>
    <?php endif; ?>

<?php elseif ($section === 'github'): ?>

    <?php if ($gh_history && $gh_repo_sel): ?>
    <div class="chart-wrap">
        <h2><?= htmlspecialchars($gh_repo_sel) ?> — últimos <?= $days ?> días</h2>
        <canvas id="gh-chart" height="80"></canvas>
    </div>

    <div class="grid">
        <?php
        $total_v = array_sum(array_column($gh_history, 'views'));
        $total_c = array_sum(array_column($gh_history, 'clones'));
        $total_vu = array_sum(array_column($gh_history, 'views_unique'));
        $total_cu = array_sum(array_column($gh_history, 'clones_unique'));
        ?>
        <div class="card"><div class="label">Vistas</div><div class="value"><?= number_format($total_v) ?></div><div class="sub"><?= number_format($total_vu) ?> únicas</div></div>
        <div class="card"><div class="label">Clones</div><div class="value"><?= number_format($total_c) ?></div><div class="sub"><?= number_format($total_cu) ?> únicos</div></div>
    </div>

    <script>
    const ghLabels = <?= json_encode(array_column($gh_history, 'date')) ?>;
    const ghViews  = <?= json_encode(array_map('intval', array_column($gh_history, 'views'))) ?>;
    const ghClones = <?= json_encode(array_map('intval', array_column($gh_history, 'clones'))) ?>;
    </script>
    <?php elseif ($gh_repos): ?>
    <p class="empty">Sin datos para <strong><?= htmlspecialchars($gh_repo_sel) ?></strong> en los últimos <?= $days ?> días.</p>
    <?php else: ?>
    <p class="empty">Sin datos de GitHub. Ejecutá el collector para empezar.</p>
    <?php endif; ?>

<?php elseif ($section === 'tools'): ?>

    <?php if ($metric_hist && $src_sel && $metric_sel): ?>
    <div class="chart-wrap">
        <h2><?= htmlspecialchars("$src_sel — $metric_sel") ?> — últimos <?= $days ?> días</h2>
        <canvas id="metric-chart" height="80"></canvas>
    </div>

    <?php
    $last_val  = end($metric_hist)['value'] ?? 0;
    $first_val = reset($metric_hist)['value'] ?? 0;
    $delta     = $last_val - $first_val;
    ?>
    <div class="grid">
        <div class="card"><div class="label">Último valor</div><div class="value"><?= number_format($last_val) ?></div><div class="sub"><?= end($metric_hist)['date'] ?></div></div>
        <div class="card"><div class="label">Variación período</div><div class="value"><?= ($delta >= 0 ? '+' : '') . number_format($delta) ?></div><div class="sub">vs inicio del período</div></div>
    </div>

    <script>
    const mLabels = <?= json_encode(array_column($metric_hist, 'date')) ?>;
    const mValues = <?= json_encode(array_map('floatval', array_column($metric_hist, 'value'))) ?>;
    </script>
    <?php elseif ($sources): ?>
    <p class="empty">Sin datos para esta selección en los últimos <?= $days ?> días.</p>
    <?php else: ?>
    <p class="empty">No hay herramientas con datos aún. Ejecutá el collector o revisá tu config.php.</p>
    <?php endif; ?>

<?php elseif ($section === 'log'): ?>

    <?php $log = ss_get_log(100); ?>
    <?php if ($log): ?>
    <div class="chart-wrap">
        <h2>Últimas 100 ejecuciones del collector</h2>
        <table class="log-table" style="width:100%">
            <?php foreach ($log as $row): ?>
            <tr>
                <td class="ts"><?= htmlspecialchars(substr($row['collected_at'], 0, 16)) ?></td>
                <td class="status-<?= $row['status'] ?>"><?= $row['status'] ?></td>
                <td><?= htmlspecialchars($row['source']) ?></td>
                <td><?= htmlspecialchars($row['message'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php else: ?>
    <p class="empty">Sin registros de ejecuciones aún. Ejecutá el collector.</p>
    <?php endif; ?>

<?php endif; ?>

</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
const gridColor = isDark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)';
const textColor = isDark ? '#94a3b8' : '#64748b';

const baseOpts = {
    responsive: true,
    interaction: { mode: 'index', intersect: false },
    plugins: { legend: { labels: { color: textColor, boxWidth: 12 } } },
    scales: {
        x: { ticks: { color: textColor, maxTicksLimit: 10 }, grid: { color: gridColor } },
        y: { ticks: { color: textColor }, grid: { color: gridColor }, beginAtZero: true },
    }
};

if (typeof ghLabels !== 'undefined') {
    new Chart(document.getElementById('gh-chart'), {
        type: 'line',
        data: {
            labels: ghLabels,
            datasets: [
                { label: 'Vistas',  data: ghViews,  borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,.15)', tension: .3, fill: true },
                { label: 'Clones',  data: ghClones, borderColor: '#22c55e', backgroundColor: 'rgba(34,197,94,.10)',   tension: .3, fill: true },
            ]
        },
        options: baseOpts
    });
}

if (typeof mLabels !== 'undefined') {
    new Chart(document.getElementById('metric-chart'), {
        type: 'line',
        data: {
            labels: mLabels,
            datasets: [{ label: metricSel, data: mValues, borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,.15)', tension: .3, fill: true }]
        },
        options: baseOpts
    });
}
</script>

<?php
// Inyectar metricSel para el chart
if ($section === 'tools' && $metric_sel):
?>
<script>const metricSel = <?= json_encode($metric_sel) ?>;</script>
<?php endif; ?>

</body>
</html>
