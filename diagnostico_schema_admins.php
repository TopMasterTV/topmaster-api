<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/administrative_token_auth.php';

function responderDiagnosticoSchemaAdmins(int $statusHttp, array $conteudo): never
{
    http_response_code($statusHttp);
    echo json_encode($conteudo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderDiagnosticoSchemaAdmins(405, [
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => 'Metodo nao permitido',
    ]);
}

try {
    $tokenOriginal = extrairTokenBearerAdministrativo();
    $tokenHash = hash('sha256', $tokenOriginal);

    // Validacao administrativa deliberadamente read-only. A funcao
    // autenticarTokenAdministrativo() nao e usada aqui porque atualiza
    // administrative_tokens.ultimo_uso_em periodicamente.
    $consultaSessao = $pdo->prepare(<<<'SQL'
        SELECT
            token.actor_type,
            token.actor_id
        FROM administrative_tokens AS token
        INNER JOIN admins AS actor
            ON actor.id = token.actor_id
           AND actor.tipo = token.actor_type
        WHERE token.token_hash = :token_hash
          AND token.revogado_em IS NULL
          AND token.expira_em > clock_timestamp()
        LIMIT 1
        SQL);
    $consultaSessao->execute([':token_hash' => $tokenHash]);
    $ator = $consultaSessao->fetch(PDO::FETCH_ASSOC);
} catch (AdministrativeAuthException $e) {
    responderDiagnosticoSchemaAdmins($e->getStatusHttp(), [
        'success' => false,
        'code' => $e->getCodigoPublico(),
        'message' => $e->getMensagemPublica(),
    ]);
} catch (Throwable $e) {
    responderDiagnosticoSchemaAdmins(500, [
        'success' => false,
        'code' => 'INTERNAL_ERROR',
        'message' => 'Erro interno',
    ]);
}

if (!$ator) {
    responderDiagnosticoSchemaAdmins(401, [
        'success' => false,
        'code' => 'INVALID_TOKEN',
        'message' => 'Sessao invalida',
    ]);
}

if (($ator['actor_type'] ?? null) !== 'master') {
    responderDiagnosticoSchemaAdmins(403, [
        'success' => false,
        'code' => 'FORBIDDEN',
        'message' => 'Acesso nao autorizado',
    ]);
}

try {
    $consultaTabela = $pdo->query(<<<'SQL'
        SELECT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = 'admins'
              AND table_type = 'BASE TABLE'
        ) AS table_exists
        SQL);
    $tableExistsRaw = $consultaTabela->fetchColumn();
    $tableExists = in_array($tableExistsRaw, [true, 1, '1', 't'], true);

    $colunas = [];
    $restricoes = [];

    if ($tableExists) {
        $consultaColunas = $pdo->query(<<<'SQL'
            SELECT
                column_name,
                data_type,
                character_maximum_length,
                is_nullable,
                column_default
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = 'admins'
            ORDER BY ordinal_position
            SQL);
        $colunas = $consultaColunas->fetchAll(PDO::FETCH_ASSOC);

        $consultaRestricoes = $pdo->query(<<<'SQL'
            SELECT
                indice.relname AS name,
                CASE
                    WHEN identificacao.indisprimary THEN 'PRIMARY KEY'
                    WHEN restricao.contype = 'u' THEN 'UNIQUE CONSTRAINT'
                    ELSE 'UNIQUE INDEX'
                END AS type,
                json_agg(atributo.attname ORDER BY chave.ordinality) AS columns
            FROM pg_catalog.pg_class AS tabela
            INNER JOIN pg_catalog.pg_namespace AS namespace
                ON namespace.oid = tabela.relnamespace
            INNER JOIN pg_catalog.pg_index AS identificacao
                ON identificacao.indrelid = tabela.oid
            INNER JOIN pg_catalog.pg_class AS indice
                ON indice.oid = identificacao.indexrelid
            INNER JOIN LATERAL unnest(identificacao.indkey)
                WITH ORDINALITY AS chave(attnum, ordinality)
                ON chave.attnum > 0
            INNER JOIN pg_catalog.pg_attribute AS atributo
                ON atributo.attrelid = tabela.oid
               AND atributo.attnum = chave.attnum
            LEFT JOIN pg_catalog.pg_constraint AS restricao
                ON restricao.conrelid = tabela.oid
               AND restricao.conindid = identificacao.indexrelid
            WHERE namespace.nspname = 'public'
              AND tabela.relname = 'admins'
              AND tabela.relkind IN ('r', 'p')
              AND (identificacao.indisprimary OR identificacao.indisunique)
            GROUP BY
                indice.relname,
                identificacao.indisprimary,
                restricao.contype
            ORDER BY
                identificacao.indisprimary DESC,
                indice.relname
            SQL);
        $restricoesBrutas = $consultaRestricoes->fetchAll(PDO::FETCH_ASSOC);

        foreach ($restricoesBrutas as $restricao) {
            $colunasRestricao = json_decode(
                (string) ($restricao['columns'] ?? '[]'),
                true
            );
            $restricoes[] = [
                'name' => (string) ($restricao['name'] ?? ''),
                'type' => (string) ($restricao['type'] ?? ''),
                'columns' => is_array($colunasRestricao) ? $colunasRestricao : [],
            ];
        }
    }
} catch (Throwable $e) {
    responderDiagnosticoSchemaAdmins(500, [
        'success' => false,
        'code' => 'SCHEMA_DIAGNOSTIC_FAILED',
        'message' => 'Nao foi possivel consultar o schema',
    ]);
}

responderDiagnosticoSchemaAdmins(200, [
    'success' => true,
    'table_exists' => $tableExists,
    'columns' => $colunas,
    'constraints' => $restricoes,
]);
