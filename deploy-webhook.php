<?php
// Token je uložen v deploy-secret.php (není v gitu).
// Při prvním nasazení zkopíruj deploy-secret.php.example → deploy-secret.php
// a nastav náhodný token. Stejný token vlož jako GitHub Secret DEPLOY_SECRET.
$cfg = __DIR__ . '/deploy-secret.php';
if (!file_exists($cfg)) { http_response_code(500); exit('Config missing'); }
require $cfg;

if (($_GET['token'] ?? '') !== DEPLOY_SECRET) {
    http_response_code(403);
    exit('Unauthorized');
}

$url     = 'https://raw.githubusercontent.com/outly-jan/rezervacni-system/main/rezervacni-system.php?nocache=' . time();
$content = @file_get_contents($url, false, stream_context_create(['http' => ['header' => 'Cache-Control: no-cache']]));

if (!$content || strlen($content) < 500) {
    http_response_code(500);
    exit('Download failed');
}

file_put_contents(__DIR__ . '/rezervacni-system.php', $content);
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__DIR__ . '/rezervacni-system.php', true);
}

// Flush WordPress page cache
$wp_load = dirname(dirname(dirname(__DIR__))) . '/wp-load.php';
$cache_log = [];
if (file_exists($wp_load)) {
    require_once $wp_load;
    wp_cache_flush();
    $cache_log[] = 'wp_cache_flush';
    if (function_exists('wp_cache_clear_cache')) { wp_cache_clear_cache(); $cache_log[] = 'wp_super_cache'; }
    if (function_exists('w3tc_flush_all'))        { w3tc_flush_all();        $cache_log[] = 'w3tc'; }
    if (function_exists('rocket_clean_domain'))   { rocket_clean_domain();   $cache_log[] = 'wp_rocket'; }
    do_action('litespeed_purge_all');
    $cache_log[] = 'litespeed_purge_all';
}

echo 'OK – deployed ' . strlen($content) . ' bytes at ' . date('Y-m-d H:i:s');
if ($cache_log) echo ' | cache flushed: ' . implode(', ', $cache_log);
