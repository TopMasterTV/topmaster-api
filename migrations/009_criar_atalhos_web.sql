CREATE TABLE IF NOT EXISTS atalhos_web (
    id VARCHAR(128) NOT NULL,
    owner_type VARCHAR(20) NOT NULL,
    owner_id INTEGER NOT NULL,
    tipo VARCHAR(20) NOT NULL,
    nome VARCHAR(200) NOT NULL,
    url TEXT NOT NULL,
    usuario VARCHAR(255) NOT NULL DEFAULT '',
    senha_criptografada TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT pk_atalhos_web
        PRIMARY KEY (owner_type, owner_id, id),
    CONSTRAINT ck_atalhos_web_id
        CHECK (BTRIM(id) <> ''),
    CONSTRAINT ck_atalhos_web_owner_type
        CHECK (owner_type IN ('master', 'revendedor')),
    CONSTRAINT ck_atalhos_web_owner_id
        CHECK (owner_id > 0),
    CONSTRAINT ck_atalhos_web_tipo
        CHECK (tipo IN ('painel', 'aplicativo')),
    CONSTRAINT ck_atalhos_web_nome
        CHECK (BTRIM(nome) <> ''),
    CONSTRAINT ck_atalhos_web_url
        CHECK (BTRIM(url) <> ''),
    CONSTRAINT fk_atalhos_web_owner
        FOREIGN KEY (owner_id)
        REFERENCES admins (id)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_atalhos_web_owner_tipo
    ON atalhos_web (owner_type, owner_id, tipo);
