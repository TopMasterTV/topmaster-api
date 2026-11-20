<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/provedor_blocos_admin.php';

$falhas = [];

function verificarBlocos(bool $condicao, string $mensagem): void
{
    global $falhas;
    if (!$condicao) {
        $falhas[] = $mensagem;
        fwrite(STDERR, "FALHA: {$mensagem}\n");
        return;
    }
    fwrite(STDOUT, "OK: {$mensagem}\n");
}

function fonteBlocos(string $arquivo): string
{
    $fonte = file_get_contents(dirname(__DIR__) . '/' . $arquivo);
    if (!is_string($fonte)) {
        throw new RuntimeException("Arquivo ausente: {$arquivo}");
    }
    return $fonte;
}

$migration = fonteBlocos('migrations/012_criar_provedor_blocos.sql');
$migracao = fonteBlocos('migrar_modelo_bloco.php');
$helper = fonteBlocos('provedor_blocos_admin.php');

verificarBlocos(
    str_contains($migration, 'CREATE TABLE IF NOT EXISTS provedor_blocos'),
    'migration cria provedor_blocos de forma aditiva'
);
verificarBlocos(
    preg_match('/ADD COLUMN IF NOT EXISTS bloco_id BIGINT\s*;/i', $migration) === 1,
    'bloco_id e anulavel para suportar Sem bloco'
);
verificarBlocos(
    str_contains($migration, 'idx_modelos_sistemas_bloco_id'),
    'migration cria indice para bloco_id'
);
verificarBlocos(
    str_contains($migration, 'ON DELETE RESTRICT'),
    'chave estrangeira impede exclusao indevida de bloco em uso'
);
verificarBlocos(
    preg_match('/\b(?:DROP\s+TABLE|TRUNCATE|DELETE\s+FROM)\b/i', $migration) !== 1,
    'migration nao exclui nem recria registros existentes'
);
verificarBlocos(
    preg_match('/\bUPDATE\s+modelos_sistemas\b/i', $migration) !== 1,
    'migration preserva IDs e classificacao atual dos modelos'
);
verificarBlocos(
    preg_match('/\b(?:UPDATE|DELETE|INSERT\s+INTO)\s+sistemas\b/i', $migration) !== 1,
    'migration nao altera sistemas dos clientes'
);

verificarBlocos(
    preg_match(
        '/UPDATE\s+modelos_sistemas\s+SET\s+bloco_id\s*=\s*:bloco_id\s+WHERE\s+id\s*=\s*:modelo_id/is',
        $migracao
    ) === 1,
    'migracao de modelo atualiza somente bloco_id pelo ID existente'
);
verificarBlocos(
    preg_match('/\bSET\s+(?!bloco_id\b)/i', $migracao) !== 1,
    'endpoint de migracao nao atualiza outros campos'
);
verificarBlocos(
    !str_contains($migracao, 'INSERT INTO modelos_sistemas')
        && !str_contains($migracao, 'DELETE FROM modelos_sistemas'),
    'endpoint de migracao nao recria nem exclui modelos'
);

$legadoListar = fonteBlocos('listar_modelos_sistemas.php');
$legadoCriar = fonteBlocos('criar_modelo_sistema.php');
$legadoEditar = fonteBlocos('editar_modelo_sistema.php');
verificarBlocos(
    str_contains($legadoListar, 'SELECT id, nome, url_padrao FROM modelos_sistemas'),
    'contrato legado de listagem continua com os mesmos campos'
);
verificarBlocos(
    str_contains($legadoCriar, 'INSERT INTO modelos_sistemas (nome, url_padrao)'),
    'contrato legado de criacao continua sem exigir bloco_id'
);
verificarBlocos(
    str_contains($legadoEditar, 'SET nome = ?, url_padrao = ? WHERE id = ?'),
    'contrato legado de edicao permanece inalterado'
);

verificarBlocos(
    str_contains($helper, 'autenticarTokenAdministrativo($pdo)'),
    'endpoints exigem autenticacao administrativa existente'
);
verificarBlocos(
    str_contains($helper, "!== 'master'")
        && str_contains($helper, "403, 'ACCESS_DENIED'"),
    'revendedor ou outro papel administrativo e rejeitado'
);
verificarBlocos(
    str_contains($helper, "401, 'AUTH_REQUIRED'") === false
        && str_contains($helper, 'AdministrativeAuthException'),
    'falhas 401 sao delegadas ao autenticador existente sem duplicar credenciais'
);

verificarBlocos(
    textoProvedorBlocos(['nome' => '  GP  '], 'nome', 100) === 'GP',
    'validacao normaliza nome de bloco'
);
try {
    textoProvedorBlocos(['nome' => ''], 'nome', 100);
    verificarBlocos(false, 'nome vazio e rejeitado');
} catch (ProvedorBlocosException $e) {
    verificarBlocos($e->statusHttp === 400, 'nome vazio e rejeitado');
}
try {
    idProvedorBlocos(['bloco_id' => '0'], 'bloco_id');
    verificarBlocos(false, 'ID invalido e rejeitado');
} catch (ProvedorBlocosException $e) {
    verificarBlocos($e->statusHttp === 400, 'ID invalido e rejeitado');
}
verificarBlocos(
    idProvedorBlocos(['bloco_id' => null], 'bloco_id', true) === null,
    'bloco nulo representa Sem bloco'
);

exit($falhas === [] ? 0 : 1);
