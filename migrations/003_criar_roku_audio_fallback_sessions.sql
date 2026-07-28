CREATE TABLE IF NOT EXISTS roku_audio_fallback_sessions (
    id BIGSERIAL PRIMARY KEY,
    -- O token público é persistido somente como hash SHA-256.
    public_token_hash CHAR(64) NOT NULL,
    cliente_id INTEGER NOT NULL,
    sistema_id INTEGER NOT NULL,
    stream_id TEXT NOT NULL,
    extensao_sanitizada VARCHAR(10) NOT NULL,
    status VARCHAR(20) NOT NULL,
    fallback_kind VARCHAR(30) NOT NULL DEFAULT 'vod_audio_stereo',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ NOT NULL,
    last_access_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    started_at TIMESTAMPTZ,
    ready_at TIMESTAMPTZ,
    finished_at TIMESTAMPTZ,
    cancelled_at TIMESTAMPTZ,
    failure_code VARCHAR(100),
    instance_id VARCHAR(100),
    process_id INTEGER,
    -- Dados internos temporários; nenhuma credencial é persistida.
    temporary_directory TEXT,
    playlist_relative_path TEXT,
    total_segmentos INTEGER NOT NULL DEFAULT 0,
    bytes_temporarios BIGINT NOT NULL DEFAULT 0,
    motivo_encerramento VARCHAR(100),
    tentativa INTEGER NOT NULL DEFAULT 1,
    CONSTRAINT uq_roku_audio_fallback_sessions_public_token_hash
        UNIQUE (public_token_hash),
    CONSTRAINT chk_roku_audio_fallback_sessions_public_token_hash
        CHECK (
            LENGTH(public_token_hash) = 64
            AND public_token_hash ~ '^[0-9a-f]{64}$'
        ),
    CONSTRAINT chk_roku_audio_fallback_sessions_stream_id
        CHECK (BTRIM(stream_id) <> ''),
    CONSTRAINT chk_roku_audio_fallback_sessions_extensao
        CHECK (extensao_sanitizada IN ('mp4', 'mov', 'm4v', 'mkv', 'm3u8')),
    CONSTRAINT chk_roku_audio_fallback_sessions_status
        CHECK (
            status IN (
                'created',
                'validating',
                'starting',
                'preparing',
                'ready',
                'streaming',
                'cancelling',
                'cancelled',
                'finished',
                'expired',
                'failed'
            )
        ),
    CONSTRAINT chk_roku_audio_fallback_sessions_kind
        CHECK (fallback_kind = 'vod_audio_stereo'),
    CONSTRAINT chk_roku_audio_fallback_sessions_expiracao
        CHECK (expires_at > created_at),
    CONSTRAINT chk_roku_audio_fallback_sessions_process_id
        CHECK (process_id IS NULL OR process_id > 0),
    CONSTRAINT chk_roku_audio_fallback_sessions_playlist_path
        CHECK (
            playlist_relative_path IS NULL
            OR (
                BTRIM(playlist_relative_path) <> ''
                AND LEFT(playlist_relative_path, 1) <> '/'
                AND playlist_relative_path !~ '^[A-Za-z]:'
                AND POSITION(CHR(92) IN playlist_relative_path) = 0
                AND POSITION('://' IN playlist_relative_path) = 0
                AND playlist_relative_path !~ '(^|/)\.\.?(/|$)'
            )
        ),
    CONSTRAINT chk_roku_audio_fallback_sessions_total_segmentos
        CHECK (total_segmentos >= 0),
    CONSTRAINT chk_roku_audio_fallback_sessions_bytes_temporarios
        CHECK (bytes_temporarios >= 0),
    CONSTRAINT chk_roku_audio_fallback_sessions_tentativa
        CHECK (tentativa >= 1),
    CONSTRAINT fk_roku_audio_fallback_sessions_cliente
        FOREIGN KEY (cliente_id)
        REFERENCES clientes (id)
        ON UPDATE NO ACTION
        ON DELETE CASCADE,
    CONSTRAINT fk_roku_audio_fallback_sessions_sistema
        FOREIGN KEY (sistema_id)
        REFERENCES sistemas (id)
        ON UPDATE NO ACTION
        ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_roku_audio_fallback_sessions_ativa
    ON roku_audio_fallback_sessions (
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

CREATE INDEX IF NOT EXISTS idx_roku_audio_fallback_sessions_cliente_status
    ON roku_audio_fallback_sessions (cliente_id, status);

CREATE INDEX IF NOT EXISTS idx_roku_audio_fallback_sessions_sistema_status
    ON roku_audio_fallback_sessions (sistema_id, status);

CREATE INDEX IF NOT EXISTS idx_roku_audio_fallback_sessions_expiracao_ativa
    ON roku_audio_fallback_sessions (expires_at)
    WHERE status IN (
        'created',
        'validating',
        'starting',
        'preparing',
        'ready',
        'streaming',
        'cancelling'
    );

CREATE INDEX IF NOT EXISTS idx_roku_audio_fallback_sessions_status_ultimo_acesso
    ON roku_audio_fallback_sessions (status, last_access_at);

CREATE INDEX IF NOT EXISTS idx_roku_audio_fallback_sessions_instance_id
    ON roku_audio_fallback_sessions (instance_id)
    WHERE instance_id IS NOT NULL;
