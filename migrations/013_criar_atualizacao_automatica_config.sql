BEGIN;

CREATE TABLE IF NOT EXISTS atualizacao_automatica_config (
    id SMALLINT PRIMARY KEY,
    ativada BOOLEAN NOT NULL DEFAULT FALSE,
    ativado_em TIMESTAMPTZ,
    desativado_em TIMESTAMPTZ,
    proxima_execucao TIMESTAMPTZ,
    versao_ciclo BIGINT NOT NULL DEFAULT 0,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    atualizado_por_admin_id INTEGER,
    CONSTRAINT ck_atualizacao_automatica_config_singleton CHECK (id = 1),
    CONSTRAINT ck_atualizacao_automatica_config_versao CHECK (versao_ciclo >= 0),
    CONSTRAINT ck_atualizacao_automatica_config_estado CHECK (
        (
            ativada
            AND ativado_em IS NOT NULL
            AND proxima_execucao IS NOT NULL
            AND (desativado_em IS NULL OR ativado_em >= desativado_em)
        )
        OR
        (
            NOT ativada
            AND proxima_execucao IS NULL
            AND (
                (ativado_em IS NULL AND desativado_em IS NULL)
                OR
                (
                    ativado_em IS NOT NULL
                    AND desativado_em IS NOT NULL
                    AND desativado_em >= ativado_em
                )
            )
        )
    ),
    CONSTRAINT fk_atualizacao_automatica_config_admin
        FOREIGN KEY (atualizado_por_admin_id)
        REFERENCES admins (id)
        ON UPDATE RESTRICT
        ON DELETE SET NULL
);

INSERT INTO atualizacao_automatica_config (
    id,
    ativada,
    ativado_em,
    desativado_em,
    proxima_execucao,
    versao_ciclo,
    atualizado_por_admin_id
) VALUES (1, FALSE, NULL, NULL, NULL, 0, NULL)
ON CONFLICT (id) DO NOTHING;

COMMIT;
