CREATE TABLE IF NOT EXISTS login_rate_limits (
    id BIGSERIAL PRIMARY KEY,
    app_tipo VARCHAR(30) NOT NULL,
    escopo VARCHAR(20) NOT NULL,
    -- Contém somente o HMAC-SHA-256 do identificador; usuário e IP nunca são armazenados em texto simples.
    chave_hash CHAR(64) NOT NULL,
    tentativas_falhas INTEGER NOT NULL DEFAULT 0,
    janela_inicio TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    bloqueado_ate TIMESTAMPTZ,
    ultima_tentativa_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_login_rate_limits_escopo
        CHECK (escopo IN ('usuario', 'ip')),
    CONSTRAINT chk_login_rate_limits_tentativas_falhas
        CHECK (tentativas_falhas >= 0),
    CONSTRAINT uq_login_rate_limits_app_escopo_chave
        UNIQUE (app_tipo, escopo, chave_hash)
);

CREATE INDEX IF NOT EXISTS idx_login_rate_limits_bloqueado_ate
    ON login_rate_limits (bloqueado_ate)
    WHERE bloqueado_ate IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_login_rate_limits_atualizado_em
    ON login_rate_limits (atualizado_em);

CREATE INDEX IF NOT EXISTS idx_login_rate_limits_app_tipo_escopo
    ON login_rate_limits (app_tipo, escopo);
