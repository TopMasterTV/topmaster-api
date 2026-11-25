BEGIN;

CREATE TABLE IF NOT EXISTS atualizacao_automatica_ciclos (
    id BIGSERIAL PRIMARY KEY,
    versao_ciclo BIGINT NOT NULL,
    agendado_para TIMESTAMPTZ NOT NULL,
    iniciado_em TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    finalizado_em TIMESTAMPTZ,
    status VARCHAR(20) NOT NULL DEFAULT 'executando',
    tipo VARCHAR(20) NOT NULL DEFAULT 'grade',
    grupo VARCHAR(20) NOT NULL,
    total_elegiveis INTEGER NOT NULL DEFAULT 0,
    total_processados INTEGER NOT NULL DEFAULT 0,
    total_atualizados INTEGER NOT NULL DEFAULT 0,
    total_falhas INTEGER NOT NULL DEFAULT 0,
    CONSTRAINT uq_atualizacao_automatica_ciclo UNIQUE (versao_ciclo, agendado_para),
    CONSTRAINT ck_atualizacao_automatica_ciclo_status
        CHECK (status IN ('executando', 'concluido', 'falhou')),
    CONSTRAINT ck_atualizacao_automatica_ciclo_tipo
        CHECK (tipo IN ('grade', 'recuperacao')),
    CONSTRAINT ck_atualizacao_automatica_ciclo_totais CHECK (
        total_elegiveis >= 0
        AND total_processados >= 0
        AND total_atualizados >= 0
        AND total_falhas >= 0
    )
);

CREATE TABLE IF NOT EXISTS atualizacao_automatica_pendencias (
    cliente_id INTEGER PRIMARY KEY,
    ciclo_origem_id BIGINT NOT NULL,
    grupo_origem VARCHAR(20) NOT NULL,
    motivo VARCHAR(30) NOT NULL,
    criada_em TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    atualizada_em TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT fk_atualizacao_pendencia_cliente
        FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE,
    CONSTRAINT fk_atualizacao_pendencia_ciclo
        FOREIGN KEY (ciclo_origem_id) REFERENCES atualizacao_automatica_ciclos (id)
        ON DELETE CASCADE,
    CONSTRAINT ck_atualizacao_pendencia_motivo
        CHECK (motivo IN ('selecionado', 'excedente', 'falha', 'interrompido'))
);

CREATE INDEX IF NOT EXISTS idx_atualizacao_pendencias_ordem
    ON atualizacao_automatica_pendencias (criada_em, cliente_id);

CREATE TABLE IF NOT EXISTS atualizacao_automatica_resultados (
    id BIGSERIAL PRIMARY KEY,
    ciclo_id BIGINT NOT NULL,
    cliente_id INTEGER NOT NULL,
    sistema_id INTEGER NOT NULL,
    provedor VARCHAR(150) NOT NULL,
    status VARCHAR(20) NOT NULL,
    vencimento_anterior DATE,
    vencimento_novo DATE,
    codigo_falha VARCHAR(80),
    detalhe_falha VARCHAR(500),
    verificado_em TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    elegivel_novamente_em TIMESTAMPTZ NOT NULL,
    CONSTRAINT fk_atualizacao_resultado_ciclo
        FOREIGN KEY (ciclo_id) REFERENCES atualizacao_automatica_ciclos (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_atualizacao_resultado_cliente
        FOREIGN KEY (cliente_id) REFERENCES clientes (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_atualizacao_resultado_sistema
        FOREIGN KEY (sistema_id) REFERENCES sistemas (id)
        ON DELETE CASCADE,
    CONSTRAINT uq_atualizacao_resultado_sistema_ciclo UNIQUE (ciclo_id, sistema_id),
    CONSTRAINT ck_atualizacao_resultado_status
        CHECK (status IN ('atualizado', 'sem_alteracao', 'falha'))
);

CREATE INDEX IF NOT EXISTS idx_atualizacao_resultados_cliente
    ON atualizacao_automatica_resultados (cliente_id, verificado_em DESC);

CREATE INDEX IF NOT EXISTS idx_atualizacao_resultados_sistema
    ON atualizacao_automatica_resultados (sistema_id, verificado_em DESC);

COMMIT;
