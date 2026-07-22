CREATE TABLE IF NOT EXISTS cliente_tokens (
    id BIGSERIAL PRIMARY KEY,
    cliente_id INTEGER NOT NULL,
    -- Contém somente o SHA-256 do token; o token original nunca é armazenado.
    token_hash CHAR(64) NOT NULL UNIQUE,
    app_tipo VARCHAR(30) NOT NULL,
    app_version VARCHAR(30) NOT NULL,
    device_id_hash CHAR(64),
    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    -- Controla até quando a sessão pode ser considerada válida.
    expira_em TIMESTAMPTZ NOT NULL,
    ultimo_uso_em TIMESTAMPTZ,
    -- Permite o cancelamento imediato da sessão sem remover seu histórico.
    revogado_em TIMESTAMPTZ,
    motivo_revogacao VARCHAR(100),
    CONSTRAINT fk_cliente_tokens_cliente
        FOREIGN KEY (cliente_id)
        REFERENCES clientes (id)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_cliente_tokens_cliente_id
    ON cliente_tokens (cliente_id);

CREATE INDEX IF NOT EXISTS idx_cliente_tokens_expira_em
    ON cliente_tokens (expira_em);

CREATE INDEX IF NOT EXISTS idx_cliente_tokens_device_id_hash
    ON cliente_tokens (device_id_hash)
    WHERE device_id_hash IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_cliente_tokens_ativos_cliente
    ON cliente_tokens (cliente_id)
    WHERE revogado_em IS NULL;
