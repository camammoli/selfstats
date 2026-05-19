# SelfStats

Seguí el tráfico de tus repos de GitHub y el uso real de tus herramientas — todo en un mismo dashboard.

**Auto-hospedable · PHP 8 · SQLite · Sin framework · Sin rastreo externo**

---

## El problema que resuelve

GitHub solo guarda datos de tráfico por 14 días. Después desaparecen para siempre. SelfStats corre un recolector diario que guarda cada vista y cada clon en una base de datos SQLite local — construyendo un historial que GitHub no te da.

Además, lee métricas de uso directamente desde tus propias herramientas (SQLite, MySQL o endpoint HTTP) y las grafica junto a las tendencias de tus repos.

## Características

- **Historial de tráfico GitHub** — vistas y clones por repo, guardados diariamente antes de que venzan los 14 días
- **Métricas de herramientas** — consultá tus propias bases (SQLite, MySQL) o llamá un endpoint HTTP
- **Dashboard** — gráficos + tarjetas de resumen, filtrables por período y fuente
- **Log de recolección** — qué se ejecutó, qué falló, cuándo
- **Fuentes enchufables** — agregá cualquier herramienta con unas pocas líneas en `config.php`

## Requisitos

- PHP 8.0+
- Extensión PDO SQLite
- Token personal de GitHub con scope `repo`

## Instalación

```bash
git clone https://github.com/camammoli/selfstats.git
cd selfstats/web
cp config.example.php config.php
chmod 750 data/
```

Editá `config.php` con tu token de GitHub y las fuentes de datos. Generá la clave de admin:

```bash
openssl rand -hex 16
```

## Configurar el cron (cPanel o sistema)

Ejecutar el recolector una vez por día:

```
0 6 * * * php /ruta/a/selfstats/web/collector.php >> /tmp/selfstats.log 2>&1
```

O llamarlo via HTTP (protegido por clave):

```
https://tudominio.com/selfstats/collector.php?key=TU_CLAVE_ADMIN
```

## Agregar una fuente de datos

### Herramienta SQLite en el mismo servidor

```php
[
    'name'    => 'Mi herramienta',
    'enabled' => true,
    'type'    => 'sqlite',
    'path'    => '/ruta/absoluta/a/herramienta.db',
    'metrics' => [
        ['label' => 'Total registros', 'query' => 'SELECT COUNT(*) FROM registros'],
        ['label' => 'Creados hoy',     'query' => 'SELECT COUNT(*) FROM registros WHERE date(created_at) = ?'],
    ],
]
```

Las queries con `?` reciben la fecha de hoy (`YYYY-MM-DD`) como parámetro. Cualquier query que devuelva un número funciona.

### Herramienta MySQL en el mismo servidor

```php
[
    'name'    => 'Mi herramienta MySQL',
    'enabled' => true,
    'type'    => 'mysql',
    'dsn'     => 'mysql:host=localhost;dbname=mi_db;charset=utf8mb4',
    'user'    => 'usuario',
    'pass'    => 'contraseña',
    'metrics' => [
        ['label' => 'Usuarios', 'query' => 'SELECT COUNT(*) FROM users'],
    ],
]
```

### Herramienta remota via HTTP

Agregá este endpoint a tu herramienta remota y devolvé:

```json
{"ok": true, "metrics": [{"label": "Total usuarios", "value": 42}]}
```

Luego configurá:

```php
[
    'name'    => 'Herramienta remota',
    'enabled' => true,
    'type'    => 'http',
    'url'     => 'https://ejemplo.com/selfstats-endpoint.php',
    'secret'  => 'clave_compartida',
]
```

El collector envía la clave en el header `X-SelfStats-Secret`.

## Acceso al dashboard

```
https://tudominio.com/selfstats/?key=TU_CLAVE_ADMIN
```

## Licencia

MIT · [Carlos Ariel Mammoli](https://mammoli.ar)
