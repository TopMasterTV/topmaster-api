-- ATENÇÃO:
-- Fixtures totalmente fictícias destinadas exclusivamente a testes locais do backend Roku.
-- Não aplicar no Render, em homologação compartilhada ou em banco de produção.
-- As senhas e URLs abaixo são falsas e não pertencem a clientes ou fornecedores reais.
-- Aplicar somente uma vez em um banco local novo e isolado.

BEGIN;

-- A senha em texto simples é proposital e exclusivamente local para testar
-- a compatibilidade legada e sua migração automática para password_hash.
INSERT INTO clientes (
    id,
    nome,
    usuario,
    senha,
    plano,
    ativo
) VALUES (
    1001,
    'Cliente Teste Ativo',
    'cliente_teste_1',
    'SenhaLocalRoku123!',
    'mensal_teste',
    TRUE
);

INSERT INTO clientes (
    id,
    nome,
    usuario,
    senha,
    plano,
    ativo
) VALUES (
    1002,
    'Cliente Teste Inativo',
    'cliente_teste_2',
    'SenhaLocalRoku456!',
    'mensal_teste',
    FALSE
);

INSERT INTO modelos_sistemas (
    id,
    nome,
    url_padrao
) VALUES (
    2001,
    'Modelo Xtream Teste',
    'https://fornecedor.invalid'
);

INSERT INTO sistemas (
    id,
    cliente_id,
    modelo_id,
    nome_sistema,
    url,
    usuario,
    senha,
    m3u_url,
    status,
    exp_date,
    vencimento
) VALUES (
    3001,
    1001,
    2001,
    'Sistema Teste Ativo',
    'https://fornecedor.invalid',
    'usuario_xtream_ficticio_1',
    'senha_xtream_ficticia_1',
    NULL,
    'Active',
    NULL,
    DATE '2099-12-31'
);

INSERT INTO sistemas (
    id,
    cliente_id,
    modelo_id,
    nome_sistema,
    url,
    usuario,
    senha,
    m3u_url,
    status,
    exp_date,
    vencimento
) VALUES (
    3002,
    1002,
    2001,
    'Sistema Teste Outro Cliente',
    'https://fornecedor.invalid',
    'usuario_xtream_ficticio_2',
    'senha_xtream_ficticia_2',
    NULL,
    'Active',
    NULL,
    DATE '2099-12-31'
);

SELECT setval(
    pg_get_serial_sequence('clientes', 'id'),
    (SELECT MAX(id) FROM clientes),
    true
);

SELECT setval(
    pg_get_serial_sequence('modelos_sistemas', 'id'),
    (SELECT MAX(id) FROM modelos_sistemas),
    true
);

SELECT setval(
    pg_get_serial_sequence('sistemas', 'id'),
    (SELECT MAX(id) FROM sistemas),
    true
);

COMMIT;

-- Cliente ativo local:
-- usuario: cliente_teste_1
-- senha inicial fictícia: SenhaLocalRoku123!
--
-- Cliente inativo local:
-- usuario: cliente_teste_2
-- senha inicial fictícia: SenhaLocalRoku456!
--
-- Sistema autorizado do cliente ativo: 3001
-- Sistema pertencente ao outro cliente: 3002
--
-- O primeiro login bem-sucedido do cliente ativo poderá substituir
-- a senha em texto simples por um hash seguro no banco local.
