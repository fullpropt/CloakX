<?php
// Script de Health Check (check.php)

// =================================================================
// CONFIGURAÇÃO DE ALERTA POR E-MAIL
// =================================================================
$ALERT_EMAIL = 'smallsmilexgamer@gmail.com'; // E-mail do usuário para alertas
$EMAIL_SENT_FILE = __DIR__ . '/logs/check_alert_sent.log';
$EMAIL_COOLDOWN_HOURS = 24; // Intervalo mínimo entre alertas (para evitar spam)

function send_alert_email($subject, $body, $to_email, $log_file) {
    // Verifica se o e-mail já foi enviado recentemente
    if (file_exists($log_file)) {
        $last_sent = file_get_contents($log_file);
        if (time() - $last_sent < $GLOBALS['EMAIL_COOLDOWN_HOURS'] * 3600) {
            return false; // Não envia, ainda está no período de cooldown
        }
    }

    $headers = 'From: Health Check Cloaker <no-reply@' . $_SERVER['HTTP_HOST'] . ">\r\n" .
               'Reply-To: ' . $to_email . "\r\n" .
               'X-Mailer: PHP/' . phpversion();

    $success = mail($to_email, $subject, $body, $headers);

    if ($success) {
        file_put_contents($log_file, time()); // Registra o envio
    }
    return $success;
}
// =================================================================
// FIM DA CONFIGURAÇÃO DE ALERTA POR E-MAIL
// =================================================================

// Arquivos e diretórios críticos a serem verificados
$critical_files = [
    'index.php' => 'Arquivo principal do Cloaker',
    'GeoLite2-Country.mmdb' => 'Banco de dados de geolocalização',
    'vendor/autoload.php' => 'Autoloader da biblioteca GeoIP2',
    'site/index.html' => 'Página Branca (White Page)',
];

$critical_dirs = [
    'logs' => 'Diretório de Logs (Precisa de permissão de escrita)',
    'vendor' => 'Diretório de Dependências',
    'site' => 'Diretório da Página Branca',
];

$errors = [];
$warnings = [];

// 1. Verificação de Arquivos e Diretórios
foreach ($critical_files as $file => $description) {
    if (!file_exists($file)) {
        $errors[] = "FALHA: Arquivo crítico não encontrado: **{$file}** ({$description})";
    }
}

foreach ($critical_dirs as $dir => $description) {
    if (!is_dir($dir)) {
        $errors[] = "FALHA: Diretório crítico não encontrado: **{$dir}** ({$description})";
    }
}

// 2. Verificação de Permissões de Escrita
if (is_dir('logs') && !is_writable('logs')) {
    $errors[] = "FALHA: O diretório **logs** não tem permissão de escrita. Os logs não serão salvos. Permissão recomendada: 755 ou 777.";
}

// 3. Verificação de Leitura do Banco de Dados
if (file_exists('GeoLite2-Country.mmdb') && !is_readable('GeoLite2-Country.mmdb')) {
    $errors[] = "FALHA: O arquivo **GeoLite2-Country.mmdb** não tem permissão de leitura. A geolocalização falhará. Permissão recomendada: 644 ou 664.";
}

// 4. Teste de Geolocalização (Simulação)
if (empty($errors)) {
    // Inclui o autoloader e as classes necessárias
    $autoload_path = __DIR__ . '/vendor/autoload.php';
    
    if (!file_exists($autoload_path)) {
        $errors[] = "FALHA: Arquivo crítico não encontrado: **vendor/autoload.php**. A geolocalização falhará. Certifique-se de que a pasta 'vendor' foi enviada.";
    } else {
        require $autoload_path;
        use GeoIp2\Database\Reader;

        try {
        $reader = new Reader(__DIR__ . '/GeoLite2-Country.mmdb');
        $test_ip = '8.8.8.8'; // IP de teste (Google DNS - US)
        $record = $reader->country($test_ip);
        
        if ($record->country->isoCode !== 'US') {
            $warnings[] = "AVISO: Teste de geolocalização falhou. IP {$test_ip} retornou '{$record->country->isoCode}' (Esperado: US). O banco de dados pode estar desatualizado ou corrompido.";
        } else {
            $warnings[] = "SUCESSO: Teste de geolocalização (IP {$test_ip}) funcionou corretamente. País: US.";
        }
        
    } catch (\Exception $e) {
        $errors[] = "FALHA: O teste de geolocalização falhou com erro: " . $e->getMessage();
    }
}
}

// 5. Envio de Alerta por E-mail (se houver erros críticos)
if (!empty($errors) && $ALERT_EMAIL !== 'seu_email@exemplo.com') {
    $subject = "ALERTA CRÍTICO: Falha no Cloaker em " . $_SERVER['HTTP_HOST'];
    $body = "O Health Check do seu Cloaker encontrou os seguintes erros críticos:\n\n" . implode("\n", $errors) . "\n\nPor favor, acesse http://" . $_SERVER['HTTP_HOST'] . "/check.php para mais detalhes.";
    
    if (send_alert_email($subject, $body, $ALERT_EMAIL, $EMAIL_SENT_FILE)) {
        $warnings[] = "ALERTA: E-mail de falha crítica enviado para {$ALERT_EMAIL}.";
    } else {
        $warnings[] = "AVISO: Falha crítica detectada, mas o e-mail de alerta não foi enviado (cooldown ou falha no envio).";
    }
}

// 6. Exibição do Resultado
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Health Check do Cloaker</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f4f4f4; }
        .container { max-width: 800px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        .status { padding: 15px; margin-bottom: 15px; border-radius: 5px; font-weight: bold; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .warning { background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        ul { list-style-type: none; padding: 0; }
        li { margin-bottom: 5px; padding: 5px 0; border-bottom: 1px dotted #eee; }
        .error li { color: #721c24; }
        .warning li { color: #856404; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🩺 Health Check do Cloaker</h1>
        
        <?php if (empty($errors) && empty($warnings)): ?>
            <div class="status success">
                TUDO OK! O sistema de cloaking está funcionando corretamente.
            </div>
        <?php elseif (!empty($errors)): ?>
            <div class="status error">
                ERROS CRÍTICOS ENCONTRADOS! O cloaker pode estar falhando.
            </div>
            <h2>Erros Encontrados:</h2>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= $error ?></li>
                <?php endforeach; ?>
            </ul>
        <?php elseif (!empty($warnings)): ?>
            <div class="status warning">
                AVISOS ENCONTRADOS. O cloaker está funcionando, mas requer atenção.
            </div>
        <?php endif; ?>

        <?php if (!empty($warnings)): ?>
            <h2>Avisos e Informações:</h2>
            <ul>
                <?php foreach ($warnings as $warning): ?>
                    <li><?= $warning ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        
        <h2>Verificações Detalhadas:</h2>
        <ul>
            <li>**Arquivos Críticos:** <?= count($critical_files) ?> verificados.</li>
            <li>**Diretórios Críticos:** <?= count($critical_dirs) ?> verificados.</li>
            <li>**Permissão de Escrita (logs):** <?= is_writable('logs') ? 'OK' : 'FALHA' ?></li>
            <li>**Permissão de Leitura (GeoLite2):** <?= is_readable('GeoLite2-Country.mmdb') ? 'OK' : 'FALHA' ?></li>
        </ul>
        
        <p>Para executar, acesse: <code>http://seusite.com/check.php</code></p>
    </div>
</body>
</html>
