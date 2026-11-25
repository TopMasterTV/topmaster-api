BEGIN;

CREATE TABLE IF NOT EXISTS administrative_refresh_tokens (
    id BIGSERIAL PRIMARY KEY,
    admin_id INTEGER NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    expira_em TIMESTAMPTZ NOT NULL,
    ultimo_uso_em TIMESTAMPTZ,
    revogado_em TIMESTAMPTZ,
    CONSTRAINT fk_administrative_refresh_tokens_admin
        FOREIGN KEY (admin_id)
        REFERENCES admins (id)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_administrative_refresh_tokens_admin
    ON administrative_refresh_tokens (admin_id);

CREATE INDEX IF NOT EXISTS idx_administrative_refresh_tokens_expira_em
    ON administrative_refresh_tokens (expira_em);

CREATE INDEX IF NOT EXISTS idx_administrative_refresh_tokens_ativos_admin
    ON administrative_refresh_tokens (admin_id)
    WHERE revogado_em IS NULL;

COMMIT;
