-- ATENÇÃO:
-- Esquema mínimo destinado exclusivamente a testes locais do backend Roku.
-- Não representa necessariamente o esquema real de produção.
-- Não aplicar no Render, em homologação compartilhada ou em banco de produção.
-- Não contém dados, credenciais, owners ou privilégios.

CREATE TABLE IF NOT EXISTS clientes (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    usuario VARCHAR(100) NOT NULL,
    senha VARCHAR(255) NOT NULL,
    plano VARCHAR(50) NOT NULL,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT uq_clientes_usuario UNIQUE (usuario)
);

CREATE TABLE IF NOT EXISTS modelos_sistemas (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    url_padrao TEXT
);

CREATE TABLE IF NOT EXISTS sistemas (
    id SERIAL PRIMARY KEY,
    cliente_id INTEGER NOT NULL,
    modelo_id INTEGER,
    nome_sistema VARCHAR(150),
    url TEXT,
    usuario TEXT,
    senha TEXT,
    m3u_url TEXT,
    status VARCHAR(30) DEFAULT 'Unknown',
    exp_date BIGINT,
    vencimento DATE,
    CONSTRAINT fk_sistemas_cliente
        FOREIGN KEY (cliente_id)
        REFERENCES clientes (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_sistemas_modelo
        FOREIGN KEY (modelo_id)
        REFERENCES modelos_sistemas (id)
        ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_sistemas_cliente_id
    ON sistemas (cliente_id);

CREATE INDEX IF NOT EXISTS idx_sistemas_modelo_id
    ON sistemas (modelo_id);

-- Ordem futura de aplicação no banco local:
-- 1. tests/local/schema_minimo_roku.sql
-- 2. migrations/001_criar_cliente_tokens.sql
-- 3. migrations/002_criar_login_rate_limits.sql
-- 4. fixtures fictícias ainda não criadas
