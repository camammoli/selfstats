<?php
// db.php — SelfStats — capa de base de datos

define('SS_DB_PATH', __DIR__ . '/data/selfstats.db');

function ss_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . SS_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
    _ss_init_schema($pdo);
    return $pdo;
}

function _ss_init_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS github_traffic (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            repo          TEXT NOT NULL,
            date          TEXT NOT NULL,
            views         INTEGER NOT NULL DEFAULT 0,
            views_unique  INTEGER NOT NULL DEFAULT 0,
            clones        INTEGER NOT NULL DEFAULT 0,
            clones_unique INTEGER NOT NULL DEFAULT 0,
            UNIQUE(repo, date)
        );

        CREATE TABLE IF NOT EXISTS metric_snapshots (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            source       TEXT NOT NULL,
            metric       TEXT NOT NULL,
            date         TEXT NOT NULL,
            value        REAL NOT NULL DEFAULT 0,
            UNIQUE(source, metric, date)
        );

        CREATE TABLE IF NOT EXISTS visits (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            source     TEXT NOT NULL,
            date       TEXT NOT NULL,
            visited_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_visits_source_date ON visits(source, date);

        CREATE TABLE IF NOT EXISTS collection_log (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            collected_at TEXT NOT NULL DEFAULT (datetime('now')),
            source       TEXT NOT NULL,
            status       TEXT NOT NULL,  -- ok | error | skip
            message      TEXT
        );
    ");
}

// ── GitHub ───────────────────────────────────────────────────────────────────

function ss_upsert_github(string $repo, string $date, int $views, int $vu, int $clones, int $cu): void {
    ss_db()->prepare("
        INSERT INTO github_traffic (repo, date, views, views_unique, clones, clones_unique)
        VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT(repo, date) DO UPDATE SET
            views         = excluded.views,
            views_unique  = excluded.views_unique,
            clones        = excluded.clones,
            clones_unique = excluded.clones_unique
    ")->execute([$repo, $date, $views, $vu, $clones, $cu]);
}

function ss_get_github_repos(): array {
    $st = ss_db()->query("SELECT DISTINCT repo FROM github_traffic ORDER BY repo");
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function ss_get_github_history(string $repo, int $days = 30): array {
    $st = ss_db()->prepare("
        SELECT date, views, views_unique, clones, clones_unique
        FROM github_traffic
        WHERE repo = ? AND date >= date('now', ? || ' days')
        ORDER BY date ASC
    ");
    $st->execute([$repo, "-$days"]);
    return $st->fetchAll();
}

function ss_get_github_totals(int $days = 14): array {
    $st = ss_db()->prepare("
        SELECT repo,
               SUM(views)         AS total_views,
               SUM(views_unique)  AS total_views_unique,
               SUM(clones)        AS total_clones,
               SUM(clones_unique) AS total_clones_unique,
               MAX(date)          AS last_date
        FROM github_traffic
        WHERE date >= date('now', ? || ' days')
        GROUP BY repo
        ORDER BY total_views DESC, total_clones DESC
    ");
    $st->execute(["-$days"]);
    return $st->fetchAll();
}

// ── Métricas de herramientas ──────────────────────────────────────────────────

function ss_upsert_metric(string $source, string $metric, string $date, float $value): void {
    ss_db()->prepare("
        INSERT INTO metric_snapshots (source, metric, date, value)
        VALUES (?, ?, ?, ?)
        ON CONFLICT(source, metric, date) DO UPDATE SET value = excluded.value
    ")->execute([$source, $metric, $date, $value]);
}

function ss_get_sources(): array {
    $st = ss_db()->query("SELECT DISTINCT source FROM metric_snapshots ORDER BY source");
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function ss_get_source_metrics(string $source): array {
    $st = ss_db()->prepare("SELECT DISTINCT metric FROM metric_snapshots WHERE source = ? ORDER BY metric");
    $st->execute([$source]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function ss_get_metric_history(string $source, string $metric, int $days = 30): array {
    $st = ss_db()->prepare("
        SELECT date, value
        FROM metric_snapshots
        WHERE source = ? AND metric = ? AND date >= date('now', ? || ' days')
        ORDER BY date ASC
    ");
    $st->execute([$source, $metric, "-$days"]);
    return $st->fetchAll();
}

function ss_get_latest_metrics(): array {
    $st = ss_db()->query("
        SELECT source, metric, value, date
        FROM metric_snapshots m1
        WHERE date = (
            SELECT MAX(date) FROM metric_snapshots m2
            WHERE m2.source = m1.source AND m2.metric = m1.metric
        )
        ORDER BY source, metric
    ");
    return $st->fetchAll();
}

// ── Log de colección ──────────────────────────────────────────────────────────

function ss_log(string $source, string $status, string $msg = ''): void {
    ss_db()->prepare("INSERT INTO collection_log (source, status, message) VALUES (?, ?, ?)")
        ->execute([$source, $status, $msg ?: null]);
}

function ss_get_log(int $limit = 50): array {
    $st = ss_db()->prepare("
        SELECT collected_at, source, status, message
        FROM collection_log
        ORDER BY id DESC
        LIMIT ?
    ");
    $st->execute([$limit]);
    return $st->fetchAll();
}

// ── Visitas (beacon JS) ───────────────────────────────────────────────────────

function ss_record_visit(string $source): void {
    ss_db()->prepare("INSERT INTO visits (source, date) VALUES (?, ?)")
        ->execute([$source, gmdate('Y-m-d')]);
}

function ss_get_visit_history(string $source, int $days = 30): array {
    $st = ss_db()->prepare("
        SELECT date, COUNT(*) AS visits
        FROM visits
        WHERE source = ? AND date >= date('now', ? || ' days')
        GROUP BY date ORDER BY date ASC
    ");
    $st->execute([$source, "-$days"]);
    return $st->fetchAll();
}

function ss_get_visit_totals(int $days = 30): array {
    $st = ss_db()->prepare("
        SELECT source, COUNT(*) AS total
        FROM visits
        WHERE date >= date('now', ? || ' days')
        GROUP BY source ORDER BY total DESC
    ");
    $st->execute(["-$days"]);
    return $st->fetchAll();
}

function ss_get_visit_sources(): array {
    $st = ss_db()->query("SELECT DISTINCT source FROM visits ORDER BY source");
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function ss_last_collection(): ?string {
    $row = ss_db()->query("SELECT MAX(collected_at) AS ts FROM collection_log WHERE status = 'ok'")->fetch();
    return $row['ts'] ?? null;
}
