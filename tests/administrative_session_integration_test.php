<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/administrative_token_auth.php';

function exigirSessaoAdministrativa(bool $condicao, string $mensagem): void
{
    if (!$condicao) {
        throw new RuntimeException("FALHA: {$mensagem}");
    }
    fwrite(STDOUT, "OK: {$mensagem}\n");
}

function codigoFalhaAdministrativa(callable $acao): ?string
{
    try {
        $acao();
    } catch (AdministrativeAuthException $e) {
        return $e->getCodigoPublico();
    }
    return null;
}

function parametrosDsnPostgreSql(string $dsn): ?array
{
    if (!str_starts_with($dsn, 'pgsql:')) {
        return null;
    }

    $parametros = [];
    foreach (explode(';', substr($dsn, strlen('pgsql:'))) as $segmento) {
        $segmento = trim($segmento);
        if ($segmento === '') {
            continue;
        }

        $partes = explode('=', $segmento, 2);
        if (count($partes) !== 2) {
            return null;
        }

        $nome = strtolower(trim($partes[0]));
        $valor = trim($partes[1]);
        if ($nome === '' || array_key_exists($nome, $parametros)) {
            return null;
        }

        $parametros[$nome] = $valor;
    }

    return $parametros;
}

function dsnAdministrativoIsoladoValido(string $dsn): bool
{
    $parametros = parametrosDsnPostgreSql($dsn);

    return $parametros !== null
        && ($parametros['host'] ?? null) === '127.0.0.1'
        && ($parametros['dbname'] ?? null) === 'administrative_session_test';
}

function iniciarOperacaoConcorrente(string $operacao, string $token, string $aplicacao): array
{
    $ambiente = getenv();
    if (!is_array($ambiente)) {
        $ambiente = [];
    }
    $ambiente['ADMIN_SESSION_CHILD_OPERATION'] = $operacao;
    $ambiente['ADMIN_SESSION_CHILD_TOKEN'] = $token;
    $ambiente['ADMIN_SESSION_CHILD_APPLICATION'] = $aplicacao;

    $processo = proc_open(
        [PHP_BINARY, __FILE__, '--concurrency-child'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__),
        $ambiente
    );
    if (!is_resource($processo)) {
        throw new RuntimeException('Nao foi possivel iniciar processo concorrente');
    }
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 10);

    $inicio = microtime(true);
    do {
        $linha = fgets($pipes[1]);
        if ($linha === "READY\n") {
            return [$processo, $pipes];
        }
        if ($linha === false && !proc_get_status($processo)['running']) {
            $erro = stream_get_contents($pipes[2]);
            throw new RuntimeException('Processo concorrente encerrou antes do bloqueio: ' . trim($erro));
        }
    } while (microtime(true) - $inicio < 10);

    proc_terminate($processo);
    throw new RuntimeException('Timeout ao iniciar processo concorrente');
}

function aguardarBloqueioPostgreSql(PDO $pdo, string $aplicacao): void
{
    $consulta = $pdo->prepare(<<<'SQL'
        SELECT wait_event_type = 'Lock'
        FROM pg_stat_activity
        WHERE application_name = :application_name
        SQL);
    $inicio = microtime(true);
    do {
        $consulta->execute([':application_name' => $aplicacao]);
        if (in_array($consulta->fetchColumn(), [true, 1, '1', 't'], true)) {
            return;
        }
        usleep(50000);
    } while (microtime(true) - $inicio < 10);

    throw new RuntimeException('Timeout aguardando bloqueio concorrente deterministico');
}

function concluirOperacaoConcorrente(array $processoAberto): string
{
    [$processo, $pipes] = $processoAberto;
    stream_set_timeout($pipes[1], 15);
    $resultado = trim(stream_get_contents($pipes[1]));
    $erro = trim(stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $codigo = proc_close($processo);
    if ($codigo !== 0) {
        throw new RuntimeException('Processo concorrente falhou: ' . $erro);
    }
    return $resultado;
}

exigirSessaoAdministrativa(
    dsnAdministrativoIsoladoValido(
        'pgsql:host=127.0.0.1;port=5432;dbname=administrative_session_test'
    ),
    'DSN do banco local isolado e aceito'
);
exigirSessaoAdministrativa(
    !dsnAdministrativoIsoladoValido(
        'pgsql:host=localhost;port=5432;dbname=administrative_session_test'
    ),
    'DSN com host diferente e rejeitado'
);
exigirSessaoAdministrativa(
    !dsnAdministrativoIsoladoValido(
        'pgsql:host=127.0.0.1;port=5432;dbname=outro_banco'
    ),
    'DSN com banco diferente e rejeitado'
);
exigirSessaoAdministrativa(
    !dsnAdministrativoIsoladoValido(
        'pgsql:host=127.0.0.1.evil;port=5432;dbname=administrative_session_test'
    ),
    'DSN com host contendo apenas o prefixo permitido e rejeitado'
);
exigirSessaoAdministrativa(
    !dsnAdministrativoIsoladoValido(
        'pgsql:host=127.0.0.1;host=outro-host;dbname=administrative_session_test'
    ),
    'DSN com parametro duplicado e rejeitado'
);

$dsn = getenv('ADMIN_SESSION_TEST_DSN');
$usuario = getenv('ADMIN_SESSION_TEST_USER');
$senha = getenv('ADMIN_SESSION_TEST_PASSWORD');
if (
    !is_string($dsn)
    || !dsnAdministrativoIsoladoValido($dsn)
    || !is_string($usuario)
    || !is_string($senha)
) {
    throw new RuntimeException('Banco PostgreSQL local isolado nao configurado');
}

$pdo = new PDO($dsn, $usuario, $senha, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$bancoConectado = $pdo->query('SELECT current_database()')->fetchColumn();
if ($bancoConectado !== 'administrative_session_test') {
    throw new RuntimeException('Conexao recusada: banco PostgreSQL nao e o banco isolado esperado');
}

if (($argv[1] ?? null) === '--concurrency-child') {
    $operacao = getenv('ADMIN_SESSION_CHILD_OPERATION');
    $tokenFilho = getenv('ADMIN_SESSION_CHILD_TOKEN');
    $aplicacao = getenv('ADMIN_SESSION_CHILD_APPLICATION');
    if (!is_string($operacao) || !is_string($tokenFilho) || !is_string($aplicacao)) {
        throw new RuntimeException('Parametros do processo concorrente ausentes');
    }
    $configurarAplicacao = $pdo->prepare("SELECT set_config('application_name', :nome, false)");
    $configurarAplicacao->execute([':nome' => $aplicacao]);
    $pdo->exec("SET statement_timeout = '15s'");
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);

    if ($operacao === 'renew') {
        $codigo = codigoFalhaAdministrativa(fn () => renovarSessaoMaster($pdo, $tokenFilho));
        fwrite(STDOUT, $codigo ?? 'SUCCESS');
        exit(0);
    }
    if ($operacao === 'logout-refresh') {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        revogarCredenciaisAdministrativasApresentadas($pdo, $tokenFilho);
        fwrite(STDOUT, 'SUCCESS');
        exit(0);
    }
    throw new RuntimeException('Operacao concorrente desconhecida');
}

$pdo->exec(
    'DROP TABLE IF EXISTS administrative_refresh_tokens, administrative_tokens, admins CASCADE'
);
$pdo->exec(<<<'SQL'
    CREATE TABLE admins (
        id SERIAL PRIMARY KEY,
        usuario TEXT NOT NULL,
        senha TEXT NOT NULL,
        tipo VARCHAR(20) NOT NULL
    );
    CREATE TABLE administrative_tokens (
        id BIGSERIAL PRIMARY KEY,
        actor_type VARCHAR(20) NOT NULL,
        actor_id INTEGER NOT NULL REFERENCES admins(id) ON DELETE CASCADE,
        token_hash CHAR(64) NOT NULL UNIQUE,
        criado_em TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
        expira_em TIMESTAMPTZ NOT NULL,
        ultimo_uso_em TIMESTAMPTZ,
        revogado_em TIMESTAMPTZ,
        CONSTRAINT ck_administrative_tokens_actor_type
            CHECK (actor_type IN ('master', 'revendedor'))
    );
    SQL);

$migrationRefresh = file_get_contents(
    dirname(__DIR__) . '/migrations/014_criar_administrative_refresh_tokens.sql'
);
if (!is_string($migrationRefresh)) {
    throw new RuntimeException('Migration 014 nao encontrada');
}
$pdo->exec($migrationRefresh);
$pdo->exec(<<<'SQL'
    INSERT INTO admins (usuario, senha, tipo) VALUES
        ('master_teste', 'hash_anterior', 'master'),
        ('revendedor_teste', 'hash_revendedor', 'revendedor'),
        ('master_dois', 'hash_master_dois', 'master');
    SQL);

$pdo->exec('DROP TABLE administrative_refresh_tokens');
$emissaoParcialFalhou = false;
try {
    emitirSessaoMaster($pdo, 3);
} catch (Throwable) {
    $emissaoParcialFalhou = true;
}
$tokensParciais = $pdo->query(
    "SELECT COUNT(*) FROM administrative_tokens WHERE actor_type = 'master' AND actor_id = 3"
)->fetchColumn();
exigirSessaoAdministrativa(
    $emissaoParcialFalhou && (int) $tokensParciais === 0,
    'falha ao emitir refresh reverte o access e nao deixa sessao parcial'
);
$pdo->exec($migrationRefresh);

$master = emitirSessaoMaster($pdo, 1);
$revendedor = emitirTokenAdministrativo($pdo, 'revendedor', 2);
exigirSessaoAdministrativa(
    $master['expires_in'] === 604800 && $revendedor['expires_in'] === 604800,
    'Master e Revendedor recebem expires_in de sete dias'
);
exigirSessaoAdministrativa(
    $master['refresh_expires_in'] === 7776000
        && isset($master['refresh_token'])
        && !isset($revendedor['refresh_token']),
    'somente Master recebe refresh token de noventa dias'
);

$validade = $pdo->query(<<<'SQL'
    SELECT
        MIN(EXTRACT(EPOCH FROM (expira_em - criado_em))) AS minimo,
        MAX(EXTRACT(EPOCH FROM (expira_em - criado_em))) AS maximo
    FROM administrative_tokens
    SQL)->fetch();
exigirSessaoAdministrativa(
    (float) $validade['minimo'] >= 604799.9
        && (float) $validade['maximo'] <= 604800.1,
    'expira_em deriva exatamente da mesma constante'
);

$validadeRefresh = $pdo->query(<<<'SQL'
    SELECT EXTRACT(EPOCH FROM (expira_em - criado_em))
    FROM administrative_refresh_tokens
    WHERE admin_id = 1
    SQL)->fetchColumn();
exigirSessaoAdministrativa(
    (float) $validadeRefresh >= 7775999.9
        && (float) $validadeRefresh <= 7776000.1,
    'refresh Master recebe validade absoluta de noventa dias'
);

$expiracaoRefreshAntes = $pdo->query(<<<'SQL'
    SELECT expira_em
    FROM administrative_refresh_tokens
    WHERE admin_id = 1
    SQL)->fetchColumn();
$renovada = renovarSessaoMaster($pdo, $master['refresh_token']);
$expiracaoRefreshDepois = $pdo->query(<<<'SQL'
    SELECT expira_em
    FROM administrative_refresh_tokens
    WHERE admin_id = 1
    SQL)->fetchColumn();
exigirSessaoAdministrativa(
    $renovada['success'] === true
        && $renovada['tipo'] === 'master'
        && $renovada['admin_id'] === 1
        && $renovada['expires_in'] === 604800
        && !isset($renovada['refresh_token'])
        && $expiracaoRefreshDepois === $expiracaoRefreshAntes,
    'refresh valido emite somente novo access e preserva expiracao absoluta'
);

exigirSessaoAdministrativa(
    codigoFalhaAdministrativa(
        fn () => renovarSessaoMaster($pdo, $master['access_token'])
    ) === 'INVALID_REFRESH_TOKEN',
    'access token nao e aceito como refresh'
);

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $master['refresh_token'];
exigirSessaoAdministrativa(
    codigoFalhaAdministrativa(fn () => autenticarTokenAdministrativo($pdo)) === 'INVALID_TOKEN',
    'refresh token nao e aceito como Bearer administrativo'
);

$refreshExpirado = str_repeat('b', 64);
$inserirRefreshExpirado = $pdo->prepare(<<<'SQL'
    INSERT INTO administrative_refresh_tokens (admin_id, token_hash, expira_em)
    VALUES (1, :token_hash, clock_timestamp() - INTERVAL '1 second')
    SQL);
$inserirRefreshExpirado->execute([
    ':token_hash' => hash('sha256', $refreshExpirado),
]);
exigirSessaoAdministrativa(
    codigoFalhaAdministrativa(
        fn () => renovarSessaoMaster($pdo, $refreshExpirado)
    ) === 'REFRESH_TOKEN_EXPIRED',
    'refresh expirado nao renova a sessao'
);

$refreshRevogado = str_repeat('c', 64);
$inserirRefreshRevogado = $pdo->prepare(<<<'SQL'
    INSERT INTO administrative_refresh_tokens (
        admin_id,
        token_hash,
        expira_em,
        revogado_em
    ) VALUES (
        1,
        :token_hash,
        clock_timestamp() + INTERVAL '90 days',
        clock_timestamp()
    )
    SQL);
$inserirRefreshRevogado->execute([
    ':token_hash' => hash('sha256', $refreshRevogado),
]);
exigirSessaoAdministrativa(
    codigoFalhaAdministrativa(
        fn () => renovarSessaoMaster($pdo, $refreshRevogado)
    ) === 'REFRESH_TOKEN_REVOKED',
    'refresh revogado nao renova a sessao'
);

$sessaoLogoutMaster = emitirSessaoMaster($pdo, 1);
$sessaoOutroMaster = emitirSessaoMaster($pdo, 3);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $sessaoLogoutMaster['access_token'];
$credenciaisDivergentesRejeitadas = codigoFalhaAdministrativa(
    fn () => revogarCredenciaisAdministrativasApresentadas(
        $pdo,
        $sessaoOutroMaster['refresh_token']
    )
) === 'CREDENTIAL_MISMATCH';
$refreshOutroRevogado = $pdo->prepare(<<<'SQL'
    SELECT revogado_em IS NOT NULL
    FROM administrative_refresh_tokens
    WHERE token_hash = :token_hash
    SQL);
$refreshOutroRevogado->execute([
    ':token_hash' => hash('sha256', $sessaoOutroMaster['refresh_token']),
]);
exigirSessaoAdministrativa(
    $credenciaisDivergentesRejeitadas
        && in_array($refreshOutroRevogado->fetchColumn(), [false, 0, '0', 'f'], true),
    'logout rejeita par divergente e nao revoga refresh de outro Master'
);

$sessaoLogoutAccess = emitirSessaoMaster($pdo, 3);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $sessaoLogoutAccess['access_token'];
revogarCredenciaisAdministrativasApresentadas($pdo);
$estadoLogoutAccess = $pdo->prepare(<<<'SQL'
    SELECT revogado_em IS NOT NULL
    FROM administrative_tokens
    WHERE token_hash = :token_hash
    SQL);
$estadoLogoutAccess->execute([
    ':token_hash' => hash('sha256', $sessaoLogoutAccess['access_token']),
]);
exigirSessaoAdministrativa(
    in_array($estadoLogoutAccess->fetchColumn(), [true, 1, '1', 't'], true),
    'logout somente com access preserva o contrato anterior'
);

$sessaoLogoutRefresh = emitirSessaoMaster($pdo, 3);
unset($_SERVER['HTTP_AUTHORIZATION']);
revogarCredenciaisAdministrativasApresentadas(
    $pdo,
    $sessaoLogoutRefresh['refresh_token']
);

$sessaoConcorrenteLogout = emitirSessaoMaster($pdo, 3);
$pdo->beginTransaction();
exigirSessaoAdministrativa(
    bloquearAdministradorMaster($pdo, 3),
    'teste concorrente bloqueia primeiro a linha do Master'
);
$accessEmitidoDuranteRenovacao = emitirTokenAdministrativo($pdo, 'master', 3);
$aplicacaoLogout = 'admin_session_logout_' . bin2hex(random_bytes(4));
$logoutConcorrente = iniciarOperacaoConcorrente(
    'logout-refresh',
    $sessaoConcorrenteLogout['refresh_token'],
    $aplicacaoLogout
);
aguardarBloqueioPostgreSql($pdo, $aplicacaoLogout);
$pdo->commit();
$resultadoLogoutConcorrente = concluirOperacaoConcorrente($logoutConcorrente);
$verificarAccessConcorrente = $pdo->prepare(<<<'SQL'
    SELECT revogado_em IS NOT NULL
    FROM administrative_tokens
    WHERE token_hash = :token_hash
    SQL);
$verificarAccessConcorrente->execute([
    ':token_hash' => hash('sha256', $accessEmitidoDuranteRenovacao['access_token']),
]);
exigirSessaoAdministrativa(
    $resultadoLogoutConcorrente === 'SUCCESS'
        && in_array($verificarAccessConcorrente->fetchColumn(), [true, 1, '1', 't'], true),
    'logout concorrente aguarda renovacao e revoga o novo access antes de retornar'
);
$ativosLogoutRefresh = $pdo->query(<<<'SQL'
    SELECT
        COUNT(*) FILTER (WHERE actor_type = 'master' AND actor_id = 3 AND revogado_em IS NULL)
            AS access_ativos,
        (SELECT COUNT(*) FROM administrative_refresh_tokens
         WHERE admin_id = 3 AND revogado_em IS NULL) AS refresh_ativos
    FROM administrative_tokens
    SQL)->fetch();
exigirSessaoAdministrativa(
    (int) $ativosLogoutRefresh['access_ativos'] === 0
        && (int) $ativosLogoutRefresh['refresh_ativos'] === 0,
    'logout somente com refresh revoga todos os access e refresh do mesmo Master'
);
revogarCredenciaisAdministrativasApresentadas(
    $pdo,
    $sessaoLogoutRefresh['refresh_token']
);

$sessaoLogoutPareado = emitirSessaoMaster($pdo, 1);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $sessaoLogoutPareado['access_token'];
revogarCredenciaisAdministrativasApresentadas(
    $pdo,
    $sessaoLogoutPareado['refresh_token']
);
revogarCredenciaisAdministrativasApresentadas(
    $pdo,
    $sessaoLogoutPareado['refresh_token']
);
$estadoLogoutPareado = $pdo->prepare(<<<'SQL'
    SELECT
        access.revogado_em IS NOT NULL AS access_revogado,
        refresh.revogado_em IS NOT NULL AS refresh_revogado
    FROM administrative_tokens AS access
    CROSS JOIN administrative_refresh_tokens AS refresh
    WHERE access.token_hash = :access_hash
      AND refresh.token_hash = :refresh_hash
    SQL);
$estadoLogoutPareado->execute([
    ':access_hash' => hash('sha256', $sessaoLogoutPareado['access_token']),
    ':refresh_hash' => hash('sha256', $sessaoLogoutPareado['refresh_token']),
]);
$logoutPareado = $estadoLogoutPareado->fetch();
exigirSessaoAdministrativa(
    in_array($logoutPareado['access_revogado'], [true, 1, '1', 't'], true)
        && in_array($logoutPareado['refresh_revogado'], [true, 1, '1', 't'], true),
    'logout pareado e repetido revoga access e refresh do mesmo Master'
);

$masterAutenticacao = emitirSessaoMaster($pdo, 1);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $masterAutenticacao['access_token'];
$ator = autenticarTokenAdministrativo($pdo);
exigirSessaoAdministrativa(
    $ator['actor_type'] === 'master' && $ator['actor_id'] === 1,
    'token Master autentica o ator correto'
);

revogarTokenAdministrativoApresentado($pdo);
revogarTokenAdministrativoApresentado($pdo);
$estadoLogout = $pdo->prepare(<<<'SQL'
    SELECT
        (SELECT revogado_em IS NOT NULL
         FROM administrative_tokens WHERE token_hash = :master_hash) AS master_revogado,
        (SELECT revogado_em IS NOT NULL
         FROM administrative_tokens WHERE token_hash = :revendedor_hash) AS revendedor_revogado
    SQL);
$estadoLogout->execute([
    ':master_hash' => hash('sha256', $masterAutenticacao['access_token']),
    ':revendedor_hash' => hash('sha256', $revendedor['access_token']),
]);
$logoutAccess = $estadoLogout->fetch();
exigirSessaoAdministrativa(
    in_array($logoutAccess['master_revogado'], [true, 1, '1', 't'], true)
        && in_array($logoutAccess['revendedor_revogado'], [false, 0, '0', 'f'], true),
    'logout repetido revoga somente o token apresentado'
);
exigirSessaoAdministrativa(
    codigoFalhaAdministrativa(fn () => autenticarTokenAdministrativo($pdo)) === 'TOKEN_REVOKED',
    'token revogado nao autentica'
);

$tokenExpirado = str_repeat('a', 64);
$inserirExpirado = $pdo->prepare(<<<'SQL'
    INSERT INTO administrative_tokens (actor_type, actor_id, token_hash, expira_em)
    VALUES ('master', 1, :token_hash, clock_timestamp() - INTERVAL '1 second')
    SQL);
$inserirExpirado->execute([':token_hash' => hash('sha256', $tokenExpirado)]);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokenExpirado;
exigirSessaoAdministrativa(
    codigoFalhaAdministrativa(fn () => autenticarTokenAdministrativo($pdo)) === 'TOKEN_EXPIRED',
    'token expirado retorna TOKEN_EXPIRED'
);

$masterDois = emitirSessaoMaster($pdo, 1);
$outroMasterPreservado = emitirSessaoMaster($pdo, 3);
$revendedorDois = emitirTokenAdministrativo($pdo, 'revendedor', 2);
$pdo->beginTransaction();
revogarTokensAdministrativosDoAtor($pdo, 'master', 1);
$pdo->commit();
$ativosPorAtor = $pdo->query(<<<'SQL'
    SELECT actor_type, COUNT(*) FILTER (WHERE revogado_em IS NULL) AS ativos
    FROM administrative_tokens
    GROUP BY actor_type
    ORDER BY actor_type
    SQL)->fetchAll();
exigirSessaoAdministrativa(
    (int) $ativosPorAtor[0]['ativos'] === 0
        && (int) $ativosPorAtor[1]['ativos'] === 2,
    'revogacao por ator separa Master e Revendedor'
);
$refreshAtivosMaster = $pdo->query(<<<'SQL'
    SELECT COUNT(*)
    FROM administrative_refresh_tokens
    WHERE admin_id = 1
      AND revogado_em IS NULL
    SQL)->fetchColumn();
$refreshAtivosOutroMaster = $pdo->query(<<<'SQL'
    SELECT COUNT(*)
    FROM administrative_refresh_tokens
    WHERE admin_id = 3
      AND revogado_em IS NULL
    SQL)->fetchColumn();
exigirSessaoAdministrativa(
    (int) $refreshAtivosMaster === 0 && (int) $refreshAtivosOutroMaster === 1,
    'revogacao por ator remove refresh somente do Master afetado'
);

$tokenRollback = emitirSessaoMaster($pdo, 1);
$pdo->beginTransaction();
$pdo->exec("UPDATE admins SET senha = 'hash_temporario' WHERE id = 1");
revogarTokensAdministrativosDoAtor($pdo, 'master', 1);
$pdo->rollBack();
$aposRollback = $pdo->query("SELECT senha FROM admins WHERE id = 1")->fetchColumn();
$verificarRollback = $pdo->prepare(
    'SELECT revogado_em FROM administrative_tokens WHERE token_hash = :token_hash'
);
$verificarRollback->execute([':token_hash' => hash('sha256', $tokenRollback['access_token'])]);
$verificarRefreshRollback = $pdo->prepare(
    'SELECT revogado_em FROM administrative_refresh_tokens WHERE token_hash = :token_hash'
);
$verificarRefreshRollback->execute([
    ':token_hash' => hash('sha256', $tokenRollback['refresh_token']),
]);
exigirSessaoAdministrativa(
    $aposRollback === 'hash_anterior'
        && $verificarRollback->fetchColumn() === null
        && $verificarRefreshRollback->fetchColumn() === null,
    'rollback preserva senha, access e refresh juntos'
);

$pdo->beginTransaction();
$pdo->exec("UPDATE admins SET senha = 'hash_novo' WHERE id = 1");
revogarTokensAdministrativosDoAtor($pdo, 'master', 1);
$pdo->commit();
$aposCommit = $pdo->query("SELECT senha FROM admins WHERE id = 1")->fetchColumn();
$verificarCommit = $pdo->prepare(
    'SELECT revogado_em IS NOT NULL FROM administrative_tokens WHERE token_hash = :token_hash'
);
$verificarCommit->execute([':token_hash' => hash('sha256', $tokenRollback['access_token'])]);
$verificarRefreshCommit = $pdo->prepare(
    'SELECT revogado_em IS NOT NULL FROM administrative_refresh_tokens WHERE token_hash = :token_hash'
);
$verificarRefreshCommit->execute([
    ':token_hash' => hash('sha256', $tokenRollback['refresh_token']),
]);
exigirSessaoAdministrativa(
    $aposCommit === 'hash_novo'
        && in_array($verificarCommit->fetchColumn(), [true, 1, '1', 't'], true)
        && in_array($verificarRefreshCommit->fetchColumn(), [true, 1, '1', 't'], true),
    'commit confirma senha e revogacao de access e refresh juntos'
);

$sessaoConcorrenteSenha = emitirSessaoMaster($pdo, 1);
$pdo->beginTransaction();
$pdo->exec("UPDATE admins SET senha = 'hash_concorrente' WHERE id = 1");
revogarTokensAdministrativosDoAtor($pdo, 'master', 1);
$aplicacaoSenha = 'admin_session_password_' . bin2hex(random_bytes(4));
$renovacaoConcorrente = iniciarOperacaoConcorrente(
    'renew',
    $sessaoConcorrenteSenha['refresh_token'],
    $aplicacaoSenha
);
aguardarBloqueioPostgreSql($pdo, $aplicacaoSenha);
$pdo->commit();
$resultadoRenovacaoConcorrente = concluirOperacaoConcorrente($renovacaoConcorrente);
$accessAtivosAposSenha = $pdo->query(<<<'SQL'
    SELECT COUNT(*)
    FROM administrative_tokens
    WHERE actor_type = 'master'
      AND actor_id = 1
      AND revogado_em IS NULL
    SQL)->fetchColumn();
exigirSessaoAdministrativa(
    $resultadoRenovacaoConcorrente === 'REFRESH_TOKEN_REVOKED'
        && (int) $accessAtivosAposSenha === 0,
    'renovacao concorrente aguarda troca de senha e nenhum access escapa da revogacao'
);

$funcionarioRejeitado = false;
try {
    emitirTokenAdministrativo($pdo, 'funcionario', 1);
} catch (InvalidArgumentException) {
    $funcionarioRejeitado = true;
}
exigirSessaoAdministrativa(
    $funcionarioRejeitado,
    'tipo funcionario continua inelegivel para token administrativo'
);
