# SelfStats

Track GitHub repo traffic and real usage of your self-hosted tools — all in one dashboard.

**Self-hostable · PHP 8 · SQLite · No framework · No tracking**

---

## The problem it solves

GitHub only keeps traffic data for 14 days. After that, it's gone. SelfStats runs a daily collector that saves every view and clone to a local SQLite database — building a historical record GitHub doesn't give you.

On top of that, it reads usage metrics directly from your own tools (SQLite, MySQL, or HTTP endpoint) and plots them alongside your repo trends.

## Features

- **GitHub traffic history** — views and clones per repo, saved daily before the 14-day window expires
- **Tool metrics** — query your own databases (SQLite, MySQL) or call an HTTP endpoint
- **Dashboard** — charts + summary cards, filter by period and source
- **Collection log** — see what ran, what failed, and when
- **Pluggable sources** — add any tool with a few lines in `config.php`

## Requirements

- PHP 8.0+
- PDO SQLite extension
- GitHub Personal Access Token with `repo` scope

## Installation

```bash
git clone https://github.com/camammoli/selfstats.git
cd selfstats/web
cp config.example.php config.php
chmod 750 data/
```

Edit `config.php` with your GitHub token and data sources. Generate the admin key:

```bash
openssl rand -hex 16
```

## Cron setup (cPanel or system cron)

Run the collector once a day:

```
0 6 * * * php /path/to/selfstats/web/collector.php >> /tmp/selfstats.log 2>&1
```

Or call it via HTTP (protected by admin key):

```
https://yourdomain.com/selfstats/collector.php?key=YOUR_ADMIN_KEY
```

## Adding a data source

### SQLite tool on the same server

```php
[
    'name'    => 'My Tool',
    'enabled' => true,
    'type'    => 'sqlite',
    'path'    => '/absolute/path/to/tool.db',
    'metrics' => [
        ['label' => 'Total records', 'query' => 'SELECT COUNT(*) FROM records'],
        ['label' => 'Created today', 'query' => 'SELECT COUNT(*) FROM records WHERE date(created_at) = ?'],
    ],
]
```

Queries with `?` receive today's date (`YYYY-MM-DD`) as a parameter. Any query that returns a single number works.

### MySQL tool on the same server

```php
[
    'name'    => 'My MySQL Tool',
    'enabled' => true,
    'type'    => 'mysql',
    'dsn'     => 'mysql:host=localhost;dbname=mydb;charset=utf8mb4',
    'user'    => 'dbuser',
    'pass'    => 'dbpass',
    'metrics' => [
        ['label' => 'Users', 'query' => 'SELECT COUNT(*) FROM users'],
    ],
]
```

### Remote tool via HTTP

Add this endpoint to your remote tool and return:

```json
{"ok": true, "metrics": [{"label": "Total users", "value": 42}]}
```

Then configure:

```php
[
    'name'    => 'Remote Tool',
    'enabled' => true,
    'type'    => 'http',
    'url'     => 'https://example.com/selfstats-endpoint.php',
    'secret'  => 'shared_secret',
]
```

The collector sends the secret in the `X-SelfStats-Secret` header.

## Dashboard access

```
https://yourdomain.com/selfstats/?key=YOUR_ADMIN_KEY
```

## License

MIT · [Carlos Ariel Mammoli](https://mammoli.ar)
