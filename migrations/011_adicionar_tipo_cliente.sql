ALTER TABLE public.clientes
    ADD COLUMN IF NOT EXISTS tipo_cliente VARCHAR(10) NOT NULL DEFAULT 'normal';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'ck_clientes_tipo_cliente'
          AND conrelid = 'public.clientes'::regclass
    ) THEN
        ALTER TABLE public.clientes
            ADD CONSTRAINT ck_clientes_tipo_cliente
            CHECK (tipo_cliente IN ('normal', 'teste'));
    END IF;
END
$$;
