<?php
// collector.php — SelfStats — recolector de métricas
// Ejecutar via cron: php /ruta/collector.php
// O via HTTP (protegido): /selfstats/collector.php?key=ADMIN_KEY

require_once __DIR__ . '/db.php';

$config_file = __DIR__ . '/config.php';
if (!file_exists($config_file)) {
    die("Error: config.php no encontrado. Copiá config.example.php y completá los valores.\n");
}
$config = require $config_file;

// Autenticación si se llama por HTTP
if (php_sapi_name() !== 'cli') {
    $key = $_GET['key'] ?? '';
    if (!hash_equals($config['admin_key'] ?? '', $key)) {
        http_response_code(403);
        die(json_encode(['error' => 'Forbidden']));
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$today   = gmdate('Y-m-d');
$results = [];

// ── GitHub ────────────────────────────────────────────────────────────────────

if (!empty($config['github']['enabled'])) {
    $gh = $config['github'];

    $repos = $gh['repos'] ?? 'all';
    if ($repos === 'all') {
        $repos = _ss_github_list_repos($gh['user'], $gh['token']);
    }

    foreach ($repos as $repo) {
        try {
            $views_data  = _ss_github_api("/repos/{$gh['user']}/$repo/traffic/views",  $gh['token']);
            $clones_data = _ss_github_api("/repos/{$gh['user']}/$repo/traffic/clones", $gh['token']);

            $by_date = [];
            foreach ($views_data['views'] ?? [] as $day) {
                $d = substr($day['timestamp'], 0, 10);
                $by_date[$d]['views']        = $day['count'];
                $by_date[$d]['views_unique'] = $day['uniques'];
            }
            foreach ($clones_data['clones'] ?? [] as $day) {
                $d = substr($day['timestamp'], 0, 10);
                $by_date[$d]['clones']        = $day['count'];
                $by_date[$d]['clones_unique'] = $day['uniques'];
            }

            foreach ($by_date as $date => $data) {
                ss_upsert_github(
                    $repo, $date,
                    $data['views']        ?? 0,
                    $data['views_unique'] ?? 0,
                    $data['clones']        ?? 0,
                    $data['clones_unique'] ?? 0
                );
            }

            $days_saved = count($by_date);
            ss_log("github:$repo", 'ok', "$days_saved días guardados");
            $results[] = "✓ GitHub/$repo — $days_saved días";

        } catch (Throwable $e) {
            ss_log("github:$repo", 'error', $e->getMessage());
            $results[] = "✗ GitHub/$repo — " . $e->getMessage();
        }
    }
}

// ── Fuentes configuradas ──────────────────────────────────────────────────────

foreach ($config['sources'] ?? [] as $source) {
    if (empty($source['enabled'])) continue;

    $name = $source['name'] ?? 'unnamed';

    try {
        $metrics = match($source['type']) {
            'sqlite' => _ss_collect_sqlite($source, $today),
            'mysql'  => _ss_collect_mysql($source, $today),
            'http'   => _ss_collect_http($source),
            default  => throw new RuntimeException("Tipo desconocido: {$source['type']}"),
        };

        foreach ($metrics as $label => $value) {
            ss_upsert_metric($name, $label, $today, $value);
        }

        $n = count($metrics);
        ss_log($name, 'ok', "$n métricas guardadas");
        $results[] = "✓ $name — $n métricas";

    } catch (Throwable $e) {
        ss_log($name, 'error', $e->getMessage());
        $results[] = "✗ $name — " . $e->getMessage();
    }
}

// ── Salida ────────────────────────────────────────────────────────────────────

$output = implode("\n", $results);
echo $output . "\n";

// ── Adaptadores ───────────────────────────────────────────────────────────────

function _ss_github_api(string $endpoint, string $token): array {
    $url = "https://api.github.com$endpoint";
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => implode("\r\n", [
            "Authorization: Bearer $token",
            "Accept: application/vnd.github+json",
            "X-GitHub-Api-Version: 2022-11-28",
            "User-Agent: selfstats/1.0",
        ]),
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) throw new RuntimeException("No se pudo conectar con GitHub API");
    $data = json_decode($resp, true);
    if (!is_array($data)) throw new RuntimeException("Respuesta inválida de GitHub API");
    if (isset($data['message'])) throw new RuntimeException("GitHub API: {$data['message']}");
    return $data;
}

function _ss_github_list_repos(string $user, string $token): array {
    $repos = [];
    $page  = 1;
    do {
        $data = _ss_github_api("/users/$user/repos?type=public&per_page=100&page=$page", $token);
        foreach ($data as $r) $repos[] = $r['name'];
        $page++;
    } while (count($data) === 100);
    return $repos;
}

function _ss_collect_sqlite(array $source, string $today): array {
    if (!file_exists($source['path'])) {
        throw new RuntimeException("SQLite no encontrado: {$source['path']}");
    }
    $pdo = new PDO('sqlite:' . $source['path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return _ss_run_queries($pdo, $source['metrics'] ?? [], $today);
}

function _ss_collect_mysql(array $source, string $today): array {
    $pdo = new PDO($source['dsn'], $source['user'] ?? '', $source['pass'] ?? '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return _ss_run_queries($pdo, $source['metrics'] ?? [], $today);
}

function _ss_run_queries(PDO $pdo, array $metrics, string $today): array {
    $results = [];
    foreach ($metrics as $m) {
        $label = $m['label'] ?? 'metric';
        $query = $m['query'] ?? '';
        // Si la query tiene ?, pasa la fecha como parámetro
        $needs_date = str_contains($query, '?');
        $st = $pdo->prepare($query);
        $st->execute($needs_date ? [$today] : []);
        $results[$label] = (float)$st->fetchColumn();
    }
    return $results;
}

function _ss_collect_http(array $source): array {
    // El endpoint debe devolver: {"ok":true,"metrics":[{"label":"...","value":42},...]}
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => "X-SelfStats-Secret: " . ($source['secret'] ?? '') . "\r\nUser-Agent: selfstats/1.0",
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($source['url'], false, $ctx);
    if ($resp === false) throw new RuntimeException("No se pudo conectar: {$source['url']}");
    $data = json_decode($resp, true);
    if (!isset($data['ok']) || !$data['ok']) {
        throw new RuntimeException("Respuesta inválida del endpoint HTTP");
    }
    $results = [];
    foreach ($data['metrics'] ?? [] as $m) {
        $results[$m['label']] = (float)$m['value'];
    }
    return $results;
}
