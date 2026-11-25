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

$pdo->exec('DROP TABLE IF EXISTS administrative_tokens, admins CASCADE');
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
    INSERT INTO admins (usuario, senha, tipo) VALUES
        ('master_teste', 'hash_anterior', 'master'),
        ('revendedor_teste', 'hash_revendedor', 'revendedor');
    SQL);

$master = emitirTokenAdministrativo($pdo, 'master', 1);
$revendedor = emitirTokenAdministrativo($pdo, 'revendedor', 2);
exigirSessaoAdministrativa(
    $master['expires_in'] === 604800 && $revendedor['expires_in'] === 604800,
    'Master e Revendedor recebem expires_in de sete dias'
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

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $master['access_token'];
$ator = autenticarTokenAdministrativo($pdo);
exigirSessaoAdministrativa(
    $ator['actor_type'] === 'master' && $ator['actor_id'] === 1,
    'token Master autentica o ator correto'
);

revogarTokenAdministrativoApresentado($pdo);
revogarTokenAdministrativoApresentado($pdo);
$estadoLogout = $pdo->query(<<<'SQL'
    SELECT actor_type, revogado_em IS NOT NULL AS revogado
    FROM administrative_tokens
    ORDER BY actor_type
    SQL)->fetchAll();
exigirSessaoAdministrativa(
    $estadoLogout[0]['actor_type'] === 'master'
        && in_array($estadoLogout[0]['revogado'], [true, 1, '1', 't'], true)
        && $estadoLogout[1]['actor_type'] === 'revendedor'
        && in_array($estadoLogout[1]['revogado'], [false, 0, '0', 'f'], true),
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

$masterDois = emitirTokenAdministrativo($pdo, 'master', 1);
$revendedorDois = emitirTokenAdministrativo($pdo, 'revendedor', 2);
revogarTokensAdministrativosDoAtor($pdo, 'master', 1);
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

$tokenRollback = emitirTokenAdministrativo($pdo, 'master', 1);
$pdo->beginTransaction();
$pdo->exec("UPDATE admins SET senha = 'hash_temporario' WHERE id = 1");
revogarTokensAdministrativosDoAtor($pdo, 'master', 1);
$pdo->rollBack();
$aposRollback = $pdo->query("SELECT senha FROM admins WHERE id = 1")->fetchColumn();
$verificarRollback = $pdo->prepare(
    'SELECT revogado_em FROM administrative_tokens WHERE token_hash = :token_hash'
);
$verificarRollback->execute([':token_hash' => hash('sha256', $tokenRollback['access_token'])]);
exigirSessaoAdministrativa(
    $aposRollback === 'hash_anterior' && $verificarRollback->fetchColumn() === null,
    'rollback preserva senha e token juntos'
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
exigirSessaoAdministrativa(
    $aposCommit === 'hash_novo'
        && in_array($verificarCommit->fetchColumn(), [true, 1, '1', 't'], true),
    'commit confirma senha e revogacao juntos'
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
