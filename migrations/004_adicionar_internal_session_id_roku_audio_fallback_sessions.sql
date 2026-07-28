DO $migration$
DECLARE
    column_added BOOLEAN := FALSE;
    column_data_type TEXT;
    column_maximum_length INTEGER;
    column_is_nullable TEXT;
    column_default TEXT;
    constraint_type "char";
    constraint_definition TEXT;
    internal_session_id_attnum SMALLINT;
BEGIN
    IF to_regclass('public.roku_audio_fallback_sessions') IS NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = 'P0001',
            MESSAGE = 'MIGRATION_004_REQUIRED_TABLE_MISSING';
    END IF;

    SELECT
        data_type,
        character_maximum_length,
        is_nullable,
        column_default
    INTO
        column_data_type,
        column_maximum_length,
        column_is_nullable,
        column_default
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND table_name = 'roku_audio_fallback_sessions'
      AND column_name = 'internal_session_id';

    IF NOT FOUND THEN
        ALTER TABLE public.roku_audio_fallback_sessions
            ADD COLUMN internal_session_id VARCHAR(128);
        column_added := TRUE;
    ELSIF column_data_type <> 'character varying'
       OR column_maximum_length <> 128
       OR column_is_nullable <> 'NO'
       OR column_default IS NOT NULL THEN
        RAISE EXCEPTION USING
            ERRCODE = 'P0001',
            MESSAGE = 'MIGRATION_004_INCOMPATIBLE_COLUMN';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.roku_audio_fallback_sessions
        WHERE internal_session_id IS NULL
           OR CHAR_LENGTH(internal_session_id) < 16
           OR CHAR_LENGTH(internal_session_id) > 128
           OR internal_session_id COLLATE "C" !~ '^[A-Za-z0-9_-]+$'
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = 'P0001',
            MESSAGE = 'MIGRATION_004_INCOMPATIBLE_EXISTING_ROWS';
    END IF;

    IF column_added THEN
        ALTER TABLE public.roku_audio_fallback_sessions
            ALTER COLUMN internal_session_id SET NOT NULL;
    END IF;

    SELECT a.attnum
    INTO internal_session_id_attnum
    FROM pg_catalog.pg_attribute AS a
    WHERE a.attrelid = 'public.roku_audio_fallback_sessions'::regclass
      AND a.attname = 'internal_session_id'
      AND NOT a.attisdropped;

    SELECT c.contype, pg_catalog.pg_get_constraintdef(c.oid)
    INTO constraint_type, constraint_definition
    FROM pg_catalog.pg_constraint AS c
    WHERE c.conrelid = 'public.roku_audio_fallback_sessions'::regclass
      AND c.conname = 'chk_roku_audio_fallback_sessions_internal_session_id';

    IF NOT FOUND THEN
        IF EXISTS (
            SELECT 1
            FROM pg_catalog.pg_constraint AS c
            WHERE c.conrelid = 'public.roku_audio_fallback_sessions'::regclass
              AND c.contype = 'c'
              AND c.conkey @> ARRAY[internal_session_id_attnum]::SMALLINT[]
        ) THEN
            RAISE EXCEPTION USING
                ERRCODE = 'P0001',
                MESSAGE = 'MIGRATION_004_UNEXPECTED_CHECK_CONSTRAINT';
        END IF;
        ALTER TABLE public.roku_audio_fallback_sessions
            ADD CONSTRAINT chk_roku_audio_fallback_sessions_internal_session_id
            CHECK (
                CHAR_LENGTH(internal_session_id) >= 16
                AND CHAR_LENGTH(internal_session_id) <= 128
                AND internal_session_id COLLATE "C" ~ '^[A-Za-z0-9_-]+$'
            );
    ELSIF constraint_type <> 'c'
       OR POSITION('char_length' IN LOWER(constraint_definition)) = 0
       OR POSITION('>= 16' IN constraint_definition) = 0
       OR POSITION('<= 128' IN constraint_definition) = 0
       OR POSITION('^[A-Za-z0-9_-]+$' IN constraint_definition) = 0
       OR POSITION('COLLATE "C"' IN constraint_definition) = 0 THEN
        RAISE EXCEPTION USING
            ERRCODE = 'P0001',
            MESSAGE = 'MIGRATION_004_INCOMPATIBLE_CHECK_CONSTRAINT';
    END IF;

    SELECT c.contype, pg_catalog.pg_get_constraintdef(c.oid)
    INTO constraint_type, constraint_definition
    FROM pg_catalog.pg_constraint AS c
    WHERE c.conrelid = 'public.roku_audio_fallback_sessions'::regclass
      AND c.conname = 'uq_roku_audio_fallback_sessions_internal_session_id';

    IF NOT FOUND THEN
        IF EXISTS (
            SELECT 1
            FROM pg_catalog.pg_constraint AS c
            WHERE c.conrelid = 'public.roku_audio_fallback_sessions'::regclass
              AND c.contype = 'u'
              AND c.conkey = ARRAY[internal_session_id_attnum]::SMALLINT[]
        ) THEN
            RAISE EXCEPTION USING
                ERRCODE = 'P0001',
                MESSAGE = 'MIGRATION_004_UNEXPECTED_UNIQUE_CONSTRAINT';
        END IF;
        ALTER TABLE public.roku_audio_fallback_sessions
            ADD CONSTRAINT uq_roku_audio_fallback_sessions_internal_session_id
            UNIQUE (internal_session_id);
    ELSIF constraint_type <> 'u'
       OR NOT EXISTS (
            SELECT 1
            FROM pg_catalog.pg_constraint AS c
            WHERE c.conrelid = 'public.roku_audio_fallback_sessions'::regclass
              AND c.conname = 'uq_roku_audio_fallback_sessions_internal_session_id'
              AND c.conkey = ARRAY[internal_session_id_attnum]::SMALLINT[]
       ) THEN
        RAISE EXCEPTION USING
            ERRCODE = 'P0001',
            MESSAGE = 'MIGRATION_004_INCOMPATIBLE_UNIQUE_CONSTRAINT';
    END IF;
END
$migration$;
