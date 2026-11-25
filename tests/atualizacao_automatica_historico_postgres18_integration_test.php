<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/atualizacao_automatica_historico_repository.php';

function historicoPg18Ok(bool $condicao, string $mensagem): void
{
    if (!$condicao) {
        throw new RuntimeException('FALHA: ' . $mensagem);
    }
    fwrite(STDOUT, 'OK: ' . $mensagem . PHP_EOL);
}

$url = getenv('DATABASE_URL');
if (!is_string($url) || $url === '') {
    throw new RuntimeException('DATABASE_URL isolada obrigatoria');
}
$partes = parse_url($url);
$host = $partes['host'] ?? '';
$banco = ltrim((string) ($partes['path'] ?? ''), '/');
if (!in_array($host, ['127.0.0.1', 'localhost', 'postgres'], true)
    || $banco !== 'topmaster_auto_update_test') {
    throw new RuntimeException('Teste recusou banco que nao e local e isolado');
}
$porta = (int) ($partes['port'] ?? 5432);
$usuario = (string) ($partes['user'] ?? '');
$senha = (string) ($partes['pass'] ?? '');
$pdo = new PDO(
    "pgsql:host={$host};port={$porta};dbname={$banco}",
    $usuario,
    $senha,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public');
$pdo->exec(<<<'SQL'
CREATE TABLE admins (id SERIAL PRIMARY KEY);
CREATE TABLE clientes (id SERIAL PRIMARY KEY, nome VARCHAR(200) NOT NULL);
CREATE TABLE modelos_sistemas (id SERIAL PRIMARY KEY, nome VARCHAR(150), url_padrao TEXT);
CREATE TABLE sistemas (
    id SERIAL PRIMARY KEY,
    cliente_id INTEGER NOT NULL REFERENCES clientes(id),
    modelo_id INTEGER REFERENCES modelos_sistemas(id),
    usuario TEXT,
    senha TEXT,
    m3u_url TEXT,
    status TEXT,
    exp_date BIGINT,
    vencimento DATE
);
INSERT INTO admins(id) VALUES (1);
INSERT INTO modelos_sistemas(id, nome, url_padrao)
VALUES (1, 'Modelo Ficticio', 'https://credencial-interna.invalid');
SQL);

foreach ([
    '012_criar_provedor_blocos.sql',
    '013_criar_atualizacao_automatica_config.sql',
    '015_criar_atualizacao_automatica_execucoes.sql',
] as $migration) {
    $sql = file_get_contents(dirname(__DIR__) . '/migrations/' . $migration);
    if (!is_string($sql)) {
        throw new RuntimeException('Migration ausente: ' . $migration);
    }
    $pdo->exec($sql);
}

historicoPg18Ok(
    (int) $pdo->query("SELECT current_setting('server_version_num')")->fetchColumn() >= 180000,
    'consultas executam em PostgreSQL 18'
);
historicoPg18Ok(
    (bool) $pdo->query(<<<'SQL'
        SELECT
            to_regclass('public.atualizacao_automatica_config') IS NOT NULL
            AND to_regclass('public.atualizacao_automatica_ciclos') IS NOT NULL
            AND to_regclass('public.atualizacao_automatica_resultados') IS NOT NULL
            AND to_regclass('public.atualizacao_automatica_pendencias') IS NOT NULL
        SQL)->fetchColumn(),
    'migrations 012, 013 e 015 aplicam na ordem correta'
);

$repositorio = new AtualizacaoAutomaticaHistoricoRepository(
    new AtualizacaoAutomaticaHistoricoPdoDatabase($pdo)
);
$vazio = $repositorio->listar();
historicoPg18Ok(
    $vazio['ciclos'] === [] && $vazio['paginacao']['total'] === 0,
    'historico vazio e consultado no banco real'
);

$pdo->exec(<<<'SQL'
INSERT INTO clientes(id, nome) VALUES
    (1, 'Cliente Ficticio Alfa'),
    (2, 'Cliente Ficticio Beta');
INSERT INTO sistemas(id, cliente_id, modelo_id, usuario, senha, m3u_url, vencimento) VALUES
    (1, 1, 1, 'usuario-secreto-1', 'senha-secreta-1', 'https://provedor.invalid/um', '2026-11-20'),
    (2, 1, 1, 'usuario-secreto-2', 'senha-secreta-2', 'https://provedor.invalid/dois', '2026-11-20'),
    (3, 2, 1, 'usuario-secreto-3', 'senha-secreta-3', 'https://provedor.invalid/tres', NULL);
SQL);

$cicloStmt = $pdo->prepare(<<<'SQL'
    INSERT INTO atualizacao_automatica_ciclos (
        versao_ciclo, agendado_para, iniciado_em, finalizado_em,
        status, tipo, grupo, total_elegiveis, total_processados,
        total_atualizados, total_falhas
    ) VALUES (
        1, clock_timestamp() - INTERVAL '1 hour',
        clock_timestamp() - INTERVAL '59 minutes',
        clock_timestamp() - INTERVAL '55 minutes',
        'concluido', 'grade', 'A', 2, 2, 2, 1
    ) RETURNING id
    SQL);
$cicloStmt->execute();
$cicloPrincipal = (int) $cicloStmt->fetchColumn();

$resultadoStmt = $pdo->prepare(<<<'SQL'
    INSERT INTO atualizacao_automatica_resultados (
        ciclo_id, cliente_id, sistema_id, provedor, status,
        vencimento_anterior, vencimento_novo, codigo_falha,
        detalhe_falha, verificado_em, elegivel_novamente_em
    ) VALUES (
        :ciclo, :cliente, :sistema, 'Provedor Ficticio', :status,
        :anterior, :novo, :codigo, :detalhe,
        clock_timestamp() - INTERVAL '58 minutes',
        clock_timestamp() + INTERVAL '1 hour'
    )
    SQL);
foreach ([
    [1, 1, 'atualizado', '2026-11-20', '2026-12-20', null, null],
    [1, 2, 'falha', '2026-11-20', null, 'FALHA_FICTICIA', 'Falha operacional ficticia'],
    [2, 3, 'sem_alteracao', null, null, null, null],
] as [$cliente, $sistema, $status, $anterior, $novo, $codigo, $detalhe]) {
    $resultadoStmt->execute([
        ':ciclo' => $cicloPrincipal,
        ':cliente' => $cliente,
        ':sistema' => $sistema,
        ':status' => $status,
        ':anterior' => $anterior,
        ':novo' => $novo,
        ':codigo' => $codigo,
        ':detalhe' => $detalhe,
    ]);
}
$pendenciaStmt = $pdo->prepare(<<<'SQL'
    INSERT INTO atualizacao_automatica_pendencias (
        cliente_id, ciclo_origem_id, grupo_origem, motivo
    ) VALUES (1, :ciclo, 'A', 'falha')
    SQL);
$pendenciaStmt->execute([':ciclo' => $cicloPrincipal]);

$outrosCiclos = $pdo->prepare(<<<'SQL'
    WITH parametros AS (
        SELECT CAST(:versao AS BIGINT) AS versao, CAST(:horas AS INTEGER) AS horas
    )
    INSERT INTO atualizacao_automatica_ciclos (
        versao_ciclo, agendado_para, iniciado_em, finalizado_em,
        status, tipo, grupo
    ) SELECT
        parametros.versao,
        clock_timestamp() - (parametros.horas * INTERVAL '1 hour'),
        clock_timestamp() - (parametros.horas * INTERVAL '1 hour'),
        CASE WHEN parametros.versao = 55 THEN NULL
             ELSE clock_timestamp() - (parametros.horas * INTERVAL '1 hour') + INTERVAL '1 minute'
        END,
        CASE WHEN parametros.versao = 55 THEN 'executando' ELSE 'concluido' END,
        CASE WHEN parametros.versao % 5 = 0 THEN 'recuperacao' ELSE 'grade' END,
        CASE WHEN parametros.versao % 5 = 0 THEN '#' ELSE 'B' END
    FROM parametros
    SQL);
for ($versao = 2; $versao <= 55; $versao++) {
    $outrosCiclos->execute([':versao' => $versao, ':horas' => $versao + 1]);
}

$primeiraPagina = $repositorio->listar(1, 10);
$segundaPagina = $repositorio->listar(2, 10);
historicoPg18Ok(
    $primeiraPagina['paginacao']['total'] === 55
        && count($primeiraPagina['ciclos']) === 10
        && count($segundaPagina['ciclos']) === 10,
    'multiplos ciclos e paginacao executam com totais reais'
);
historicoPg18Ok(
    $primeiraPagina['ciclos'][0]['id'] === $cicloPrincipal
        && $primeiraPagina['ciclos'][0]['agendado_para'] > $primeiraPagina['ciclos'][1]['agendado_para'],
    'ciclos sao ordenados do mais recente para o mais antigo'
);
$limiteMaximo = $repositorio->listar(1, 50);
historicoPg18Ok(count($limiteMaximo['ciclos']) === 50, 'limite maximo de 50 funciona');
try {
    $repositorio->listar(1, 51);
    historicoPg18Ok(false, 'limite 51 deveria falhar');
} catch (InvalidArgumentException) {
    historicoPg18Ok(true, 'limite acima de 50 e rejeitado antes do SQL');
}

$antes = [
    'ciclos' => (int) $pdo->query('SELECT COUNT(*) FROM atualizacao_automatica_ciclos')->fetchColumn(),
    'resultados' => (int) $pdo->query('SELECT COUNT(*) FROM atualizacao_automatica_resultados')->fetchColumn(),
    'pendencias' => (int) $pdo->query('SELECT COUNT(*) FROM atualizacao_automatica_pendencias')->fetchColumn(),
];
$detalhe = $repositorio->detalhar($cicloPrincipal);
historicoPg18Ok($detalhe !== null, 'consulta detalhada encontra ciclo especifico');
historicoPg18Ok(
    count($detalhe['clientes']) === 2
        && count($detalhe['clientes'][0]['sistemas']) === 2,
    'cliente com varios sistemas nao e duplicado'
);
historicoPg18Ok(
    count($detalhe['pendencias_atuais']) === 1
        && $detalhe['pendencias_atuais'][0]['motivo'] === 'falha',
    'pendencia atualmente aberta e retornada com motivo'
);
historicoPg18Ok(
    $detalhe['ciclo']['total_clientes_processados'] === 2
        && $detalhe['ciclo']['total_sistemas_consultados'] === 3
        && $detalhe['ciclo']['total_sucessos'] === 2
        && $detalhe['ciclo']['total_falhas'] === 1
        && $detalhe['ciclo']['total_pendencias_atuais'] === 1,
    'totais permanecem integros mesmo com resultados e pendencias combinados'
);
historicoPg18Ok(
    $detalhe['clientes'][1]['sistemas'][0]['vencimento_anterior'] === null
        && $detalhe['clientes'][1]['sistemas'][0]['vencimento_novo'] === null
        && $detalhe['clientes'][1]['sistemas'][0]['codigo_falha'] === null,
    'campos nulos sao preservados'
);
historicoPg18Ok($repositorio->detalhar(999999) === null, 'ciclo inexistente retorna nulo');

$json = json_encode($detalhe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
historicoPg18Ok(
    is_string($json)
        && !str_contains($json, 'usuario-secreto')
        && !str_contains($json, 'senha-secreta')
        && !str_contains($json, 'provedor.invalid')
        && !str_contains($json, 'credencial-interna.invalid'),
    'resposta nao contem credenciais nem URLs internas'
);

$depois = [
    'ciclos' => (int) $pdo->query('SELECT COUNT(*) FROM atualizacao_automatica_ciclos')->fetchColumn(),
    'resultados' => (int) $pdo->query('SELECT COUNT(*) FROM atualizacao_automatica_resultados')->fetchColumn(),
    'pendencias' => (int) $pdo->query('SELECT COUNT(*) FROM atualizacao_automatica_pendencias')->fetchColumn(),
];
historicoPg18Ok($antes === $depois, 'consultas do repositorio nao alteram dados');

$fonte = file_get_contents(dirname(__DIR__) . '/atualizacao_automatica_historico_repository.php');
historicoPg18Ok(
    is_string($fonte)
        && preg_match('/\b(?:INSERT|UPDATE|DELETE|DROP|TRUNCATE|ALTER|CREATE)\b/i', $fonte) !== 1,
    'repositorio contem exclusivamente operacoes SELECT'
);
