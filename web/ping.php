<?php
// ping.php — SelfStats — beacon de visitas JS
// Llamado via fetch() desde cada herramienta al cargar en el browser.
// Responde 204 inmediatamente; los bots que no ejecutan JS nunca llegan aquí.

http_response_code(204);
header('Content-Length: 0');
header('Access-Control-Allow-Origin: *');

// Terminar la respuesta HTTP antes de hacer trabajo de BD
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

// Filtro secundario: UAs que no son browsers reales
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (preg_match('/bot|crawler|spider|curl|wget|python|java|go-http|ruby|httpx/i', $ua)) exit;

$source = substr(preg_replace('/[^a-z0-9_\-]/i', '', $_GET['src'] ?? ''), 0, 50);
if (!$source) exit;

require_once __DIR__ . '/db.php';
ss_record_visit($source);
