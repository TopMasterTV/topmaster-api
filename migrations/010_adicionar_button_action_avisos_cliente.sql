ALTER TABLE public.avisos_cliente
    ADD COLUMN IF NOT EXISTS button_action TEXT NOT NULL DEFAULT 'url';

ALTER TABLE public.avisos_cliente
    DROP CONSTRAINT IF EXISTS ck_avisos_cliente_button_action;

ALTER TABLE public.avisos_cliente
    ADD CONSTRAINT ck_avisos_cliente_button_action
    CHECK (button_action IN ('url', 'update'));
