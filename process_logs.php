<?php
/**
 * Processador de Logs Assíncronos
 * Executar via cron a cada minuto: * * * * * php /caminho/para/process_logs.php
 */

require __DIR__ . '/conexao.php';

$log_file = __DIR__ . '/logs/pending_logs.json';

if (!file_exists($log_file)) {
    exit("Nenhum log pendente.\n");
}

// Ler logs pendentes
$logs = json_decode(file_get_contents($log_file), true);

if (empty($logs)) {
    exit("Nenhum log pendente.\n");
}

echo "Processando " . count($logs) . " logs...\n";

foreach ($logs as $log) {
    $ip = $log['ip'] ?? 'UNKNOWN';
    $ua = $log['ua'] ?? 'UNKNOWN';
    $country = $log['country'] ?? '';
    $status = $log['status'] ?? '';
    $dominio_id = $log['dominio_id'] ?? null;
    $usuario_id = $log['usuario_id'] ?? null;
    
    // Inserir no banco
    if ($conn && $dominio_id && $usuario_id) {
        // Log detalhado
        $sql_log = "INSERT INTO dominio_logs (usuario_id, dominio_id, ip, pais, status, user_agent, timestamp) 
                    VALUES (?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?))";
        $stmt_log = $conn->prepare($sql_log);
        if ($stmt_log) {
            $timestamp = $log['timestamp'];
            $stmt_log->bind_param("iissssi", $usuario_id, $dominio_id, $ip, $country, $status, $ua, $timestamp);
            $stmt_log->execute();
            $stmt_log->close();
        }
        
        // Estatísticas agregadas
        $data_hoje = date('Y-m-d', $log['timestamp']);
        
        $is_bot_stat = (stripos($status, 'BOT') !== false);
        $is_brasil = (stripos($status, 'BRASIL') !== false);
        $is_internacional = (stripos($status, 'INTERNACIONAL') !== false);
        
        $bot_count = $is_bot_stat ? 1 : 0;
        $br_count = $is_brasil ? 1 : 0;
        $int_count = $is_internacional ? 1 : 0;
        
        $sql_stats = "INSERT INTO dominio_visitas (usuario_id, dominio_id, data, total_acessos, bots_bloqueados, humanos_br, humanos_internacional)
                      VALUES (?, ?, ?, 1, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE 
                          total_acessos = total_acessos + 1,
                          bots_bloqueados = bots_bloqueados + ?,
                          humanos_br = humanos_br + ?,
                          humanos_internacional = humanos_internacional + ?,
                          data_atualizacao = NOW()";
        
        $stmt_stats = $conn->prepare($sql_stats);
        if ($stmt_stats) {
            $stmt_stats->bind_param("iisiiii", $usuario_id, $dominio_id, $data_hoje, 
                                    $bot_count, $br_count, $int_count,
                                    $bot_count, $br_count, $int_count);
            $stmt_stats->execute();
            $stmt_stats->close();
        }
    }
}

// Limpar arquivo de logs
file_put_contents($log_file, json_encode([]));

echo "Logs processados com sucesso!\n";
?>
