DO $migration$
DECLARE
    table_oid OID;
    old_index_oid OID;
    new_index_oid OID;
    expected_columns TEXT[] := ARRAY[
        'cliente_id',
        'sistema_id',
        'stream_id',
        'fallback_kind'
    ];
    expected_predicate TEXT :=
        'status=any(array[''created'',''validating'',''starting'',''preparing'','
        || '''ready'',''streaming'',''cancelling''])';
BEGIN
    table_oid := to_regclass('public.roku_audio_fallback_sessions');

    IF table_oid IS NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = 'P0001',
            MESSAGE = 'MIGRATION_005_TABLE_MISSING';
    END IF;

    SELECT c.oid
    INTO old_index_oid
    FROM pg_catalog.pg_class AS c
    INNER JOIN pg_catalog.pg_namespace AS n
        ON n.oid = c.relnamespace
    WHERE n.nspname = 'public'
      AND c.relname = 'uq_roku_audio_fallback_sessions_ativa'
      AND c.relkind = 'i';

    SELECT c.oid
    INTO new_index_oid
    FROM pg_catalog.pg_class AS c
    INNER JOIN pg_catalog.pg_namespace AS n
        ON n.oid = c.relnamespace
    WHERE n.nspname = 'public'
      AND c.relname = 'idx_roku_audio_fallback_sessions_ativas_lookup'
      AND c.relkind = 'i';

    IF old_index_oid IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM pg_catalog.pg_index AS i
        INNER JOIN pg_catalog.pg_class AS c
            ON c.oid = i.indexrelid
        INNER JOIN pg_catalog.pg_am AS am
            ON am.oid = c.relam
        WHERE i.indexrelid = old_index_oid
          AND i.indrelid = table_oid
          AND i.indisunique
          AND i.indisvalid
          AND i.indisready
          AND i.indislive
          AND am.amname = 'btree'
          AND i.indnkeyatts = 4
          AND i.indnatts = 4
          AND ARRAY(
              SELECT a.attname
              FROM unnest(i.indkey::SMALLINT[]) WITH ORDINALITY AS key(attnum, position)
              INNER JOIN pg_catalog.pg_attribute AS a
                  ON a.attrelid = i.indrelid
                 AND a.attnum = key.attnum
              ORDER BY key.position
          ) = expected_columns
          AND regexp_replace(
              regexp_replace(
                  lower(pg_catalog.pg_get_expr(i.indpred, i.indrelid)),
                  '::(character varying|text)(\[\])?',
                  '',
                  'g'
              ),
              '[[:space:]()]',
              '',
              'g'
          ) = expected_predicate
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = 'P0001',
            MESSAGE = 'MIGRATION_005_OLD_INDEX_INCOMPATIBLE';
    END IF;

    IF new_index_oid IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM pg_catalog.pg_index AS i
        INNER JOIN pg_catalog.pg_class AS c
            ON c.oid = i.indexrelid
        INNER JOIN pg_catalog.pg_am AS am
            ON am.oid = c.relam
        WHERE i.indexrelid = new_index_oid
          AND i.indrelid = table_oid
          AND NOT i.indisunique
          AND i.indisvalid
          AND i.indisready
          AND i.indislive
          AND am.amname = 'btree'
          AND i.indnkeyatts = 4
          AND i.indnatts = 4
          AND ARRAY(
              SELECT a.attname
              FROM unnest(i.indkey::SMALLINT[]) WITH ORDINALITY AS key(attnum, position)
              INNER JOIN pg_catalog.pg_attribute AS a
                  ON a.attrelid = i.indrelid
                 AND a.attnum = key.attnum
              ORDER BY key.position
          ) = expected_columns
          AND regexp_replace(
              regexp_replace(
                  lower(pg_catalog.pg_get_expr(i.indpred, i.indrelid)),
                  '::(character varying|text)(\[\])?',
                  '',
                  'g'
              ),
              '[[:space:]()]',
              '',
              'g'
          ) = expected_predicate
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = 'P0001',
            MESSAGE = 'MIGRATION_005_NEW_INDEX_INCOMPATIBLE';
    END IF;

    IF old_index_oid IS NULL AND new_index_oid IS NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = 'P0001',
            MESSAGE = 'MIGRATION_005_EXPECTED_INDEX_MISSING';
    END IF;

    IF old_index_oid IS NOT NULL THEN
        DROP INDEX public.uq_roku_audio_fallback_sessions_ativa;

        IF new_index_oid IS NULL THEN
            CREATE INDEX idx_roku_audio_fallback_sessions_ativas_lookup
                ON public.roku_audio_fallback_sessions (
                    cliente_id,
                    sistema_id,
                    stream_id,
                    fallback_kind
                )
                WHERE status IN (
                    'created',
                    'validating',
                    'starting',
                    'preparing',
                    'ready',
                    'streaming',
                    'cancelling'
                );
        END IF;
    END IF;

    SELECT c.oid
    INTO new_index_oid
    FROM pg_catalog.pg_class AS c
    INNER JOIN pg_catalog.pg_namespace AS n
        ON n.oid = c.relnamespace
    WHERE n.nspname = 'public'
      AND c.relname = 'idx_roku_audio_fallback_sessions_ativas_lookup'
      AND c.relkind = 'i';

    IF new_index_oid IS NULL OR NOT EXISTS (
        SELECT 1
        FROM pg_catalog.pg_index AS i
        INNER JOIN pg_catalog.pg_class AS c
            ON c.oid = i.indexrelid
        INNER JOIN pg_catalog.pg_am AS am
            ON am.oid = c.relam
        WHERE i.indexrelid = new_index_oid
          AND i.indrelid = table_oid
          AND NOT i.indisunique
          AND i.indisvalid
          AND i.indisready
          AND i.indislive
          AND am.amname = 'btree'
          AND i.indnkeyatts = 4
          AND i.indnatts = 4
          AND ARRAY(
              SELECT a.attname
              FROM unnest(i.indkey::SMALLINT[]) WITH ORDINALITY AS key(attnum, position)
              INNER JOIN pg_catalog.pg_attribute AS a
                  ON a.attrelid = i.indrelid
                 AND a.attnum = key.attnum
              ORDER BY key.position
          ) = expected_columns
          AND regexp_replace(
              regexp_replace(
                  lower(pg_catalog.pg_get_expr(i.indpred, i.indrelid)),
                  '::(character varying|text)(\[\])?',
                  '',
                  'g'
              ),
              '[[:space:]()]',
              '',
              'g'
          ) = expected_predicate
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = 'P0001',
            MESSAGE = 'MIGRATION_005_NEW_INDEX_INCOMPATIBLE';
    END IF;
END
$migration$;
