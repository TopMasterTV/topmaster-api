BEGIN;

CREATE TABLE IF NOT EXISTS provedor_blocos (
    id BIGSERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descricao VARCHAR(500) NOT NULL DEFAULT '',
    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_provedor_blocos_nome UNIQUE (nome),
    CONSTRAINT ck_provedor_blocos_nome CHECK (BTRIM(nome) <> '')
);

ALTER TABLE modelos_sistemas
    ADD COLUMN IF NOT EXISTS bloco_id BIGINT;

CREATE INDEX IF NOT EXISTS idx_modelos_sistemas_bloco_id
    ON modelos_sistemas (bloco_id);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_modelos_sistemas_bloco'
          AND conrelid = 'modelos_sistemas'::regclass
    ) THEN
        ALTER TABLE modelos_sistemas
            ADD CONSTRAINT fk_modelos_sistemas_bloco
            FOREIGN KEY (bloco_id)
            REFERENCES provedor_blocos (id)
            ON UPDATE RESTRICT
            ON DELETE RESTRICT;
    END IF;
END
$$;

COMMIT;
