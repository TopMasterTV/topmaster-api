CREATE TABLE IF NOT EXISTS administrative_tokens (
    id BIGSERIAL PRIMARY KEY,
    actor_type VARCHAR(20) NOT NULL,
    actor_id INTEGER NOT NULL,
    -- O token bruto e retornado somente no login; o banco guarda apenas SHA-256.
    token_hash CHAR(64) NOT NULL UNIQUE,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expira_em TIMESTAMPTZ NOT NULL,
    ultimo_uso_em TIMESTAMPTZ,
    revogado_em TIMESTAMPTZ,
    CONSTRAINT ck_administrative_tokens_actor_type
        CHECK (actor_type IN ('master', 'revendedor')),
    CONSTRAINT fk_administrative_tokens_actor
        FOREIGN KEY (actor_id)
        REFERENCES admins (id)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_administrative_tokens_actor
    ON administrative_tokens (actor_type, actor_id);

CREATE INDEX IF NOT EXISTS idx_administrative_tokens_expira_em
    ON administrative_tokens (expira_em);

CREATE INDEX IF NOT EXISTS idx_administrative_tokens_ativos_actor
    ON administrative_tokens (actor_type, actor_id)
    WHERE revogado_em IS NULL;
