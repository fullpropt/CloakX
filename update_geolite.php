<?php
// Script de Atualização Automática do GeoLite2 (update_geolite.php)

// Você DEVE obter sua chave de licença gratuita no site da MaxMind
// e substituí-la abaixo.
$LICENSE_KEY = 'SUA_CHAVE_DE_LICENCA_AQUI'; 

// URL de download do GeoLite2 Country (requer chave de licença)
$DOWNLOAD_URL = "https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-Country&license_key={$LICENSE_KEY}&suffix=tar.gz";

$TARGET_DIR = __DIR__;
$TEMP_FILE = $TARGET_DIR . '/GeoLite2-Country.tar.gz';
$MMDB_FILE = $TARGET_DIR . '/GeoLite2-Country.mmdb';

// Função para exibir o status
function log_status($message, $is_error = false) {
    $color = $is_error ? 'red' : 'green';
    echo "<p style='color: {$color}; font-weight: bold;'>[".date('Y-m-d H:i:s')."] {$message}</p>";
}

// 1. Verificação da Chave de Licença
if ($LICENSE_KEY === 'SUA_CHAVE_DE_LICENCA_AQUI' || empty($LICENSE_KEY)) {
    log_status("ERRO: Por favor, substitua 'SUA_CHAVE_DE_LICENCA_AQUI' pela sua chave de licença MaxMind.", true);
    exit;
}

log_status("Iniciando a atualização do GeoLite2 Country...");

// 2. Download do Arquivo
log_status("Baixando o arquivo GeoLite2...");
$ch = curl_init($DOWNLOAD_URL);
$fp = fopen($TEMP_FILE, 'w');

curl_setopt($ch, CURLOPT_FILE, $fp);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_FAILONERROR, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'GeoLite2 Updater'); // User-Agent recomendado pela MaxMind

$success = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);
fclose($fp);

if ($success === false || $http_code !== 200) {
    log_status("ERRO: Falha no download. Código HTTP: {$http_code}. Verifique sua chave de licença.", true);
    if (file_exists($TEMP_FILE)) {
        unlink($TEMP_FILE);
    }
    exit;
}

log_status("Download concluído com sucesso.");

// 3. Descompactação do Arquivo
log_status("Descompactando o arquivo...");

try {
    $phar = new PharData($TEMP_FILE);
    $phar->extractTo($TARGET_DIR, null, true); // Extrai tudo para o diretório atual, sobrescrevendo

    // O arquivo .mmdb está dentro de uma pasta (ex: GeoLite2-Country_20251031/)
    // Precisamos encontrar o nome dessa pasta
    $extracted_dir = '';
    $files = scandir($TARGET_DIR);
    foreach ($files as $file) {
        if (is_dir($TARGET_DIR . '/' . $file) && strpos($file, 'GeoLite2-Country_') === 0) {
            $extracted_dir = $file;
            break;
        }
    }

    if (empty($extracted_dir)) {
        log_status("ERRO: Não foi possível encontrar o diretório extraído.", true);
        unlink($TEMP_FILE);
        exit;
    }

    // 4. Substituição do Arquivo MMDB
    $new_mmdb_path = $TARGET_DIR . '/' . $extracted_dir . '/GeoLite2-Country.mmdb';

    if (!file_exists($new_mmdb_path)) {
        log_status("ERRO: Arquivo GeoLite2-Country.mmdb não encontrado no pacote extraído.", true);
        unlink($TEMP_FILE);
        rmdir($TARGET_DIR . '/' . $extracted_dir);
        exit;
    }

    // Move o novo arquivo para a raiz, sobrescrevendo o antigo
    rename($new_mmdb_path, $MMDB_FILE);
    log_status("Arquivo GeoLite2-Country.mmdb atualizado com sucesso!");

    // 5. Limpeza
    unlink($TEMP_FILE);
    rmdir($TARGET_DIR . '/' . $extracted_dir);
    log_status("Limpeza de arquivos temporários concluída.");

} catch (Exception $e) {
    log_status("ERRO na descompactação/substituição: " . $e->getMessage(), true);
    if (file_exists($TEMP_FILE)) {
        unlink($TEMP_FILE);
    }
    exit;
}

log_status("Atualização automática do GeoLite2 finalizada com sucesso!");

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Atualização GeoLite2</title>
</head>
<body>
    <h1>Log de Atualização do GeoLite2</h1>
    <?php // O log já foi exibido acima ?>
</body>
</html>
