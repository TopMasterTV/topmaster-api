<?php

declare(strict_types=1);

$raiz = dirname(__DIR__);
$endpoint = file_get_contents($raiz . '/consultar_historico_atualizacao_automatica.php');
$repositorio = file_get_contents($raiz . '/atualizacao_automatica_historico_repository.php');
$migration = file_get_contents($raiz . '/migrations/015_criar_atualizacao_automatica_execucoes.sql');
if (!is_string($endpoint) || !is_string($repositorio) || !is_string($migration)) {
    throw new RuntimeException('Arquivos do contrato ausentes');
}

$falhas = [];
function contratoHistorico(bool $condicao, string $mensagem): void
{
    global $falhas;
    if (!$condicao) {
        $falhas[] = $mensagem;
        fwrite(STDERR, "FALHA: {$mensagem}\n");
        return;
    }
    fwrite(STDOUT, "OK: {$mensagem}\n");
}

contratoHistorico(
    str_contains($endpoint, "!== 'POST'")
        && str_contains($endpoint, "header('Allow: POST')")
        && str_contains($endpoint, "header('Cache-Control: no-store')"),
    'endpoint e exclusivamente POST e sem cache'
);
contratoHistorico(
    str_contains($endpoint, 'autenticarTokenAdministrativo($pdo)')
        && str_contains($endpoint, "!== 'master'")
        && str_contains($endpoint, "'code' => 'FORBIDDEN'"),
    'Master e autorizado e Revendedor recebe 403'
);
contratoHistorico(
    str_contains($endpoint, 'AdministrativeAuthException')
        && str_contains($endpoint, 'getStatusHttp()')
        && str_contains($endpoint, 'getCodigoPublico()'),
    'token ausente invalido revogado ou expirado preserva respostas 401 existentes'
);
contratoHistorico(
    str_contains($endpoint, "'success'")
        && str_contains($endpoint, "'code'")
        && str_contains($endpoint, "'message'")
        && str_contains($endpoint, "'historico'"),
    'resposta segue envelope JSON administrativo'
);
contratoHistorico(
    str_contains($endpoint, "'ciclo_id'")
        && str_contains($endpoint, "'pagina'")
        && str_contains($endpoint, "'limite'")
        && str_contains($endpoint, 'PAGINA_MAXIMA')
        && str_contains($endpoint, 'LIMITE_MAXIMO'),
    'identificador paginacao e limite sao validados no servidor'
);
contratoHistorico(
    str_contains($repositorio, 'LIMIT CAST(:limite AS INTEGER)')
        && str_contains($repositorio, 'OFFSET CAST(:offset AS INTEGER)')
        && substr_count($repositorio, 'WHERE resultado.ciclo_id = :ciclo_id') === 1
        && str_contains($repositorio, 'WHERE ciclo.id = :ciclo_id'),
    'entrada do usuario chega ao SQL apenas por parametros preparados'
);
contratoHistorico(
    !preg_match('/\b(?:INSERT|UPDATE|DELETE|DROP|TRUNCATE|ALTER|CREATE)\b/i', $repositorio),
    'repositorio executa exclusivamente SELECT'
);
contratoHistorico(
    str_contains($migration, 'atualizacao_automatica_ciclos')
        && str_contains($migration, 'atualizacao_automatica_resultados')
        && str_contains($migration, 'atualizacao_automatica_pendencias')
        && str_contains($repositorio, 'atualizacao_automatica_ciclos')
        && str_contains($repositorio, 'atualizacao_automatica_resultados')
        && str_contains($repositorio, 'atualizacao_automatica_pendencias'),
    'consulta e compativel com as tres tabelas da migration 015'
);
contratoHistorico(
    !preg_match('/\b(?:senha|token_hash|refresh_token|m3u_url|url_padrao|usuario)\b/i', $repositorio),
    'consultas nao selecionam credenciais tokens ou URLs de provedores'
);
contratoHistorico(
    !str_contains($endpoint . $repositorio, 'atualizar_status_sistemas.php')
        && !str_contains($endpoint . $repositorio, 'atualizacao_automatica_executor.php')
        && !str_contains($endpoint . $repositorio, 'curl_')
        && !str_contains($endpoint . $repositorio, 'player_api.php'),
    'historico nao chama worker atualizacao manual ou provedores'
);
contratoHistorico(
    !str_contains($endpoint . $repositorio, 'getMessage()')
        && !str_contains($endpoint . $repositorio, 'getTrace'),
    'erros internos nao sao expostos na resposta'
);

exit($falhas === [] ? 0 : 1);
