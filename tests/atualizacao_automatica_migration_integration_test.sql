DO $$
BEGIN
    IF to_regclass('public.atualizacao_automatica_config') IS NULL THEN
        RAISE EXCEPTION 'atualizacao_automatica_config nao foi criada';
    END IF;

    IF (SELECT COUNT(*) FROM public.atualizacao_automatica_config) <> 1 THEN
        RAISE EXCEPTION 'tabela nao possui exatamente uma linha singleton';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM public.atualizacao_automatica_config
        WHERE id = 1
          AND ativada = FALSE
          AND ativado_em IS NULL
          AND desativado_em IS NULL
          AND proxima_execucao IS NULL
          AND versao_ciclo = 0
          AND atualizado_por_admin_id IS NULL
    ) THEN
        RAISE EXCEPTION 'estado inicial do singleton e invalido';
    END IF;
END
$$;

DO $$
DECLARE
    campo TEXT;
BEGIN
    FOREACH campo IN ARRAY ARRAY[
        'ativado_em',
        'desativado_em',
        'proxima_execucao',
        'atualizado_em'
    ]
    LOOP
        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = 'atualizacao_automatica_config'
              AND column_name = campo
              AND data_type = 'timestamp with time zone'
        ) THEN
            RAISE EXCEPTION 'campo % nao usa TIMESTAMPTZ', campo;
        END IF;
    END LOOP;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_catalog.pg_constraint
        WHERE conrelid = 'public.atualizacao_automatica_config'::regclass
          AND conname = 'fk_atualizacao_automatica_config_admin'
          AND contype = 'f'
          AND confrelid = 'public.admins'::regclass
    ) THEN
        RAISE EXCEPTION 'chave estrangeira para admins(id) ausente';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_catalog.pg_constraint
        WHERE conrelid = 'public.atualizacao_automatica_config'::regclass
          AND conname = 'ck_atualizacao_automatica_config_singleton'
          AND contype = 'c'
    ) OR NOT EXISTS (
        SELECT 1
        FROM pg_catalog.pg_constraint
        WHERE conrelid = 'public.atualizacao_automatica_config'::regclass
          AND conname = 'ck_atualizacao_automatica_config_versao'
          AND contype = 'c'
    ) OR NOT EXISTS (
        SELECT 1
        FROM pg_catalog.pg_constraint
        WHERE conrelid = 'public.atualizacao_automatica_config'::regclass
          AND conname = 'ck_atualizacao_automatica_config_estado'
          AND contype = 'c'
    ) THEN
        RAISE EXCEPTION 'uma ou mais CHECK constraints estao ausentes';
    END IF;
END
$$;

BEGIN;
DO $$
BEGIN
    BEGIN
        INSERT INTO public.atualizacao_automatica_config (id) VALUES (2);
        RAISE EXCEPTION 'id diferente de 1 foi aceito';
    EXCEPTION
        WHEN check_violation THEN NULL;
    END;

    BEGIN
        UPDATE public.atualizacao_automatica_config
        SET ativada = TRUE,
            ativado_em = NULL,
            proxima_execucao = NULL
        WHERE id = 1;
        RAISE EXCEPTION 'estado ativo sem horarios obrigatorios foi aceito';
    EXCEPTION
        WHEN check_violation THEN NULL;
    END;

    BEGIN
        UPDATE public.atualizacao_automatica_config
        SET ativada = FALSE,
            proxima_execucao = clock_timestamp() + INTERVAL '1 hour'
        WHERE id = 1;
        RAISE EXCEPTION 'estado inativo com proxima execucao foi aceito';
    EXCEPTION
        WHEN check_violation THEN NULL;
    END;

    BEGIN
        UPDATE public.atualizacao_automatica_config
        SET versao_ciclo = -1
        WHERE id = 1;
        RAISE EXCEPTION 'versao de ciclo negativa foi aceita';
    EXCEPTION
        WHEN check_violation THEN NULL;
    END;
END
$$;
ROLLBACK;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM public.atualizacao_automatica_config
        WHERE id = 1
          AND ativada = FALSE
          AND proxima_execucao IS NULL
          AND versao_ciclo = 0
    ) THEN
        RAISE EXCEPTION 'testes de rejeicao alteraram o singleton';
    END IF;

    IF to_regclass('public.clientes') IS NOT NULL
       OR to_regclass('public.sistemas') IS NOT NULL THEN
        RAISE EXCEPTION 'tabelas de clientes ou sistemas foram criadas';
    END IF;
END
$$;
