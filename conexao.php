<?php
/**
 * Arquivo de Conexão com Banco de Dados
 * Sistema de Cloaking
 */

// Credenciais do banco de dados
$host = "localhost";
$usuario = "tuaces44_cloakx_user";
$senha = "8&2acB4F7~k;";
$banco = "tuaces44_cloakx_db";

// Criar conexão
$conn = new mysqli($host, $usuario, $senha, $banco);

// Configurar charset para UTF-8
$conn->set_charset("utf8mb4");

// Verificar conexão
if ($conn->connect_error) {
    // Em produção, não exibir detalhes do erro
    error_log("Erro na conexão com banco de dados: " . $conn->connect_error);
    die("Erro ao conectar com o banco de dados");
}
?>