<?php
// config.example.php — SelfStats
// Copiá este archivo como config.php y completá los valores.
// config.php está en .gitignore y nunca debe subirse al repo.

return [

    // Clave para acceder al dashboard y al collector vía HTTP
    // Generá con: openssl rand -hex 16
    'admin_key' => 'CLAVE_ADMIN_AQUI',

    // ── GitHub ────────────────────────────────────────────────────────────────
    // Token personal con scope 'repo' (necesario para leer tráfico)
    // Generá en: https://github.com/settings/tokens
    'github' => [
        'enabled' => true,
        'token'   => 'ghp_TU_TOKEN_AQUI',
        'user'    => 'tu_usuario_github',
        // 'all' para rastrear todos los repos públicos,
        // o un array: ['repo1', 'repo2', ...]
        'repos'   => 'all',
    ],

    // ── Fuentes de datos propias ──────────────────────────────────────────────
    // Cada fuente puede ser: sqlite | mysql | http
    'sources' => [

        // Ejemplo: herramienta con SQLite en el mismo servidor
        [
            'name'    => 'Mi herramienta SQLite',
            'enabled' => true,
            'type'    => 'sqlite',
            'path'    => '/ruta/absoluta/a/herramienta.db',
            'metrics' => [
                // Sin '?' en la query → no recibe parámetros
                ['label' => 'Total registros', 'query' => 'SELECT COUNT(*) FROM tabla'],
                // Con '?' → recibe la fecha de hoy (YYYY-MM-DD) como parámetro
                ['label' => 'Creados hoy',     'query' => 'SELECT COUNT(*) FROM tabla WHERE date(created_at) = ?'],
                // Cualquier query que devuelva un número funciona
                ['label' => 'Activos',         'query' => "SELECT COUNT(*) FROM tabla WHERE status = 'active'"],
            ],
        ],

        // Ejemplo: herramienta con MySQL en el mismo servidor
        [
            'name'    => 'Mi herramienta MySQL',
            'enabled' => false,
            'type'    => 'mysql',
            'dsn'     => 'mysql:host=localhost;dbname=mi_db;charset=utf8mb4',
            'user'    => 'usuario_db',
            'pass'    => 'contraseña_db',
            'metrics' => [
                ['label' => 'Usuarios',         'query' => 'SELECT COUNT(*) FROM users'],
                ['label' => 'Registros hoy',    'query' => 'SELECT COUNT(*) FROM users WHERE date(created_at) = ?'],
                ['label' => 'Sesiones activas', 'query' => 'SELECT COUNT(*) FROM sessions WHERE expires_at > NOW()'],
            ],
        ],

        // Ejemplo: herramienta en otro servidor via HTTP
        // El endpoint debe devolver: {"ok":true,"metrics":[{"label":"...","value":42},...]}
        [
            'name'    => 'Herramienta remota',
            'enabled' => false,
            'type'    => 'http',
            'url'     => 'https://otroservidor.com/selfstats-endpoint.php',
            'secret'  => 'clave_compartida_con_el_endpoint',
        ],

    ],

];
