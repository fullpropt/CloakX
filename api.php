<?php
/**
 * API de Cloaking - Retorna JSON para processamento via JavaScript
 * Uso: fetch('https://acessaragora.digital/cloaker/api.php?key=TOKEN')
 * VERSÃO CORRIGIDA - Detecção de bots mais precisa
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Inclui o autoloader do Composer para carregar a biblioteca GeoIP2
require __DIR__ . '/vendor/autoload.php';

// Inclui a conexão com o banco de dados
require __DIR__ . '/conexao.php';

use GeoIp2\Database\Reader;

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

// Função para detectar bots e crawlers (CORRIGIDA - mais restritiva)
function is_bot_or_spy($ua, $ip, $ref) {
    $ua = strtolower($ua);
    $ref = strtolower($ref);
    
    // Lista de bots conhecidos (REDUZIDA - apenas bots reais)
    $bots = [
        'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider', 'yandexbot',
        'sogou', 'exabot', 'facebot', 'ia_archiver', 'adsbot-google', 'mediapartners-google',
        'facebookexternalhit', 'facebookcatalog', 'twitterbot', 'linkedinbot', 'pinterestbot',
        'whatsapp', 'telegrambot', 'slackbot', 'discordbot', 'applebot', 'petalbot',
        'semrushbot', 'ahrefsbot', 'mj12bot', 'dotbot', 'rogerbot', 'screaming frog',
        'headless', 'phantomjs', 'selenium', 'webdriver', 'puppeteer', 'playwright',
        'python-requests', 'curl', 'wget', 'scrapy', 'java/', 'go-http-client'
    ];
    
    foreach ($bots as $bot) {
        if (strpos($ua, $bot) !== false) {
            return true;
        }
    }
    
    // Verificar se o user agent está vazio ou muito curto (suspeito)
    if (empty($ua) || strlen($ua) < 20) {
        return true;
    }
    
    // Verificar se não tem características de navegador real
    // Navegadores reais geralmente têm "Mozilla" e informações de versão
    if (strpos($ua, 'mozilla') === false && strpos($ua, 'chrome') === false && strpos($ua, 'safari') === false && strpos($ua, 'firefox') === false && strpos($ua, 'edge') === false) {
        return true;
    }
    
    return false;
}

// Função para detectar tipo de dispositivo (mobile ou desktop)
function detectar_tipo_dispositivo($ua) {
    $ua_lower = strtolower($ua);
    
    // Palavras-chave que indicam dispositivo móvel
    $mobile_keywords = [
        'mobile', 'android', 'iphone', 'ipad', 'ipod', 'blackberry', 
        'windows phone', 'webos', 'opera mini', 'opera mobi'
    ];
    
    foreach ($mobile_keywords as $keyword) {
        if (strpos($ua_lower, $keyword) !== false) {
            return 'mobile';
        }
    }
    
    return 'desktop';
}

// Função para registrar log de acesso
function log_access($ip, $ua, $status, $path, $token, $country, $dominio_id, $usuario_id, $conn, $device_type = 'desktop') {
    // Log em arquivo
    $log_dir = __DIR__ . '/logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $log_file = $log_dir . '/access.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_line = "[$timestamp] TOKEN: $token | IP: $ip | Localização: $country | Status: $status | UA: $ua | Path: $path\n";
    
    file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);
    
    // Log no banco de dados
    if ($conn && $dominio_id && $usuario_id) {
        $sql_log = "INSERT INTO dominio_logs (usuario_id, dominio_id, ip, pais, status, user_agent, device_type, timestamp) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt_log = $conn->prepare($sql_log);
        if ($stmt_log) {
            $stmt_log->bind_param("iisssss", $usuario_id, $dominio_id, $ip, $country, $status, $ua, $device_type);
            $stmt_log->execute();
            $stmt_log->close();
        }
        
        // Atualizar estatísticas agregadas
        $data_hoje = date('Y-m-d');
        
        // Determinar tipo de acesso para estatísticas
        $is_bot_stat = (stripos($status, 'BOT') !== false);
        $is_brasil = (stripos($status, 'BRASIL') !== false);
        $is_internacional = (stripos($status, 'INTERNACIONAL') !== false);
        
        // Valores para inserção
        $bot_count = $is_bot_stat ? 1 : 0;
        $br_count = $is_brasil ? 1 : 0;
        $int_count = $is_internacional ? 1 : 0;
        $mobile_count = ($device_type === 'mobile') ? 1 : 0;
        $desktop_count = ($device_type === 'desktop') ? 1 : 0;
        
        // SQL unificado com valores calculados
        $sql_stats = "INSERT INTO dominio_visitas (usuario_id, dominio_id, data, total_acessos, bots_bloqueados, humanos_br, humanos_internacional, acessos_mobile, acessos_desktop)
                      VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE 
                        total_acessos = total_acessos + 1,
                        bots_bloqueados = bots_bloqueados + ?,
                        humanos_br = humanos_br + ?,
                        humanos_internacional = humanos_internacional + ?,
                        acessos_mobile = acessos_mobile + ?,
                        acessos_desktop = acessos_desktop + ?";
        
        $stmt_stats = $conn->prepare($sql_stats);
        if ($stmt_stats) {
            // Bind: usuario_id, dominio_id, data, bot_count (INSERT), br_count (INSERT), int_count (INSERT), mobile_count (INSERT), desktop_count (INSERT),
            //       bot_count (UPDATE), br_count (UPDATE), int_count (UPDATE), mobile_count (UPDATE), desktop_count (UPDATE)
            $stmt_stats->bind_param("iisiiiiiiiiii", $usuario_id, $dominio_id, $data_hoje, 
                                    $bot_count, $br_count, $int_count, $mobile_count, $desktop_count,
                                    $bot_count, $br_count, $int_count, $mobile_count, $desktop_count);
            $stmt_stats->execute();
            
            // Log de debug para verificar se a estatística foi registrada
            if ($stmt_stats->affected_rows > 0) {
                $debug_msg = "[STATS OK] Usuario: $usuario_id, Dominio: $dominio_id, Status: $status, Bot: $bot_count, BR: $br_count, Int: $int_count\n";
                file_put_contents($log_dir . '/stats_debug.log', $debug_msg, FILE_APPEND | LOCK_EX);
            }
            
            $stmt_stats->close();
        }
        
        // Registrar dados para o gráfico de acessos por hora
        $hora_atual = (int)date('G'); // Hora do dia (0-23)
        
        $sql_hora = "INSERT INTO dominio_acessos_hora (dominio_id, usuario_id, hora, total_acessos, data, data_criacao)
                     VALUES (?, ?, ?, 1, ?, NOW())
                     ON DUPLICATE KEY UPDATE 
                       total_acessos = total_acessos + 1";
        
        $stmt_hora = $conn->prepare($sql_hora);
        if ($stmt_hora) {
            $stmt_hora->bind_param("iiis", $dominio_id, $usuario_id, $hora_atual, $data_hoje);
            $stmt_hora->execute();
            $stmt_hora->close();
        }
    }
}

// ============================================
// CAPTURAR TOKEN DO USUÁRIO DA URL
// ============================================
$user_token = isset($_GET['key']) ? trim($_GET['key']) : '';

if (empty($user_token)) {
    echo json_encode(['action' => 'allow', 'message' => 'Token não fornecido']);
    exit;
}

// ============================================
// BUSCAR DADOS DO USUÁRIO E DOMÍNIO NO BANCO
// ============================================
$usuario_id = null;
$url_redirecionamento = null;

// Detectar domínio de origem
$dominio_atual = '';
if (!empty($_SERVER['HTTP_REFERER'])) {
    $parsed = parse_url($_SERVER['HTTP_REFERER']);
    $dominio_atual = $parsed['host'] ?? '';
} elseif (!empty($_SERVER['HTTP_HOST'])) {
    $dominio_atual = $_SERVER['HTTP_HOST'];
}

$dominio_id = null;

// Buscar usuário pelo token
$sql = "SELECT id FROM usuarios WHERE token = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $user_token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $usuario_data = $result->fetch_assoc();
    $usuario_id = $usuario_data['id'];
    
    // Buscar ou criar domínio
    $sql_dominio = "SELECT id, url_redirecionamento, ativo FROM dominios WHERE usuario_id = ? AND dominio = ? LIMIT 1";
    $stmt_dominio = $conn->prepare($sql_dominio);
    $stmt_dominio->bind_param("is", $usuario_id, $dominio_atual);
    $stmt_dominio->execute();
    $result_dominio = $stmt_dominio->get_result();
    
    if ($result_dominio->num_rows > 0) {
        $dominio_data = $result_dominio->fetch_assoc();
        $dominio_id = $dominio_data['id'];
        $url_redirecionamento = $dominio_data['url_redirecionamento'];
    } else {
        // Criar novo domínio automaticamente
        $sql_insert = "INSERT INTO dominios (usuario_id, dominio, url_redirecionamento, ativo, criado_em) 
                       VALUES (?, ?, '', 1, NOW())";
        $stmt_insert = $conn->prepare($sql_insert);
        $stmt_insert->bind_param("is", $usuario_id, $dominio_atual);
        $stmt_insert->execute();
        $dominio_id = $stmt_insert->insert_id;
        $stmt_insert->close();
    }
}

// ============================================
// CAPTURAR INFORMAÇÕES DO VISITANTE
// ============================================
$ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
$ref = $_SERVER['HTTP_REFERER'] ?? '';
$path = $_SERVER['REQUEST_URI'] ?? '/';

// ============================================
// DETECTAR LOCALIZAÇÃO (PAÍS) VIA GEOIP
// ============================================
$country = '';
try {
    $reader = new Reader(__DIR__ . '/GeoLite2-Country.mmdb');
    $record = $reader->country($ip);
    $country = $record->country->isoCode ?? '';
} catch (Exception $e) {
    // Se falhar, considerar como país desconhecido
    $country = '';
}

// ============================================
// DETECTAR SE É BOT
// ============================================
$is_bot = is_bot_or_spy($ua, $ip, $ref);

// ============================================
// DETECTAR TIPO DE DISPOSITIVO
// ============================================
$device_type = detectar_tipo_dispositivo($ua);

// ============================================
// LÓGICA DE DECISÃO (CORRIGIDA)
// ============================================

// Se for bot - deixar passar (ver site normal) - SEM REDIRECIONAMENTO
if ($is_bot) {
    log_access($ip, $ua, 'BOT - SITE NORMAL', $path, $user_token, $country, $dominio_id, $usuario_id, $conn, $device_type);
    echo json_encode(['action' => 'allow', 'reason' => 'bot']);
    exit;
}

// Se for do Brasil - deixar passar (ver site normal)
if ($country === 'BR') {
    log_access($ip, $ua, 'BRASIL - SITE NORMAL', $path, $user_token, $country, $dominio_id, $usuario_id, $conn, $device_type);
    echo json_encode(['action' => 'allow', 'reason' => 'brazil']);
    exit;
}

// Se país desconhecido (localhost, VPN, erro de detecção) - deixar passar
if (empty($country)) {
    log_access($ip, $ua, 'PAÍS DESCONHECIDO - SITE NORMAL', $path, $user_token, $country, $dominio_id, $usuario_id, $conn, $device_type);
    echo json_encode(['action' => 'allow', 'reason' => 'unknown_country']);
    exit;
}

// Se for de fora do Brasil e tem URL configurada - REDIRECIONAR
if (!empty($url_redirecionamento)) {
    log_access($ip, $ua, 'INTERNACIONAL - REDIRECIONADO', $path, $user_token, $country, $dominio_id, $usuario_id, $conn, $device_type);
    echo json_encode(['action' => 'redirect', 'url' => $url_redirecionamento, 'country' => $country]);
    exit;
} else {
    // Se não tem URL configurada - deixar passar
    log_access($ip, $ua, 'INTERNACIONAL - SITE NORMAL (SEM URL)', $path, $user_token, $country, $dominio_id, $usuario_id, $conn, $device_type);
    echo json_encode(['action' => 'allow', 'reason' => 'no_url']);
    exit;
}
?>