-- ============================================
-- ESTRUTURA DE BANCO DE DADOS PARA TOKENS
-- ============================================

-- Adicionar coluna de token na tabela de usuários (se ainda não existir)
ALTER TABLE usuarios 
ADD COLUMN token VARCHAR(64) UNIQUE NULL AFTER senha,
ADD COLUMN data_token_criado TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- Criar índice para busca rápida por token
CREATE INDEX idx_token ON usuarios(token);

-- ============================================
-- SCRIPT PARA GERAR TOKENS PARA USUÁRIOS EXISTENTES
-- ============================================

-- Este UPDATE gera tokens únicos para usuários que ainda não têm
UPDATE usuarios 
SET token = MD5(CONCAT(id, email, UNIX_TIMESTAMP()))
WHERE token IS NULL OR token = '';
