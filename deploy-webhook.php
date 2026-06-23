<?php
// Token musí odpovídat hodnotě GitHub secret DEPLOY_SECRET.
// Po nahrání na server změň CHANGE_ME na náhodný řetězec.
define('DEPLOY_SECRET', 'UqU7tlOxzc');

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
echo 'OK – deployed ' . strlen($content) . ' bytes at ' . date('Y-m-d H:i:s');
