<?php

declare(strict_types=1);

$raiz = dirname(__DIR__);
$falhas = [];

function verificarSessaoAdministrativa(bool $condicao, string $mensagem): void
{
    global $falhas;
    if (!$condicao) {
        $falhas[] = $mensagem;
        fwrite(STDERR, "FALHA: {$mensagem}\n");
        return;
    }
    fwrite(STDOUT, "OK: {$mensagem}\n");
}

function fonteSessaoAdministrativa(string $arquivo): string
{
    global $raiz;
    $fonte = file_get_contents($raiz . DIRECTORY_SEPARATOR . $arquivo);
    if (!is_string($fonte)) {
        throw new RuntimeException("Arquivo ausente: {$arquivo}");
    }
    return $fonte;
}

$auth = fonteSessaoAdministrativa('administrative_token_auth.php');
$loginMaster = fonteSessaoAdministrativa('auth_admin.php');
$loginRevendedor = fonteSessaoAdministrativa('auth_revendedor.php');
$logout = fonteSessaoAdministrativa('logout_administrativo.php');
$renovacaoMaster = fonteSessaoAdministrativa('renovar_sessao_master.php');
$migrationRefresh = fonteSessaoAdministrativa(
    'migrations/014_criar_administrative_refresh_tokens.sql'
);
$editarAdmin = fonteSessaoAdministrativa('editar_admin.php');
$editarRevendedor = fonteSessaoAdministrativa('editar_revendedor.php');
$resetMaster = fonteSessaoAdministrativa('reset_senha_admin_master.php');

verificarSessaoAdministrativa(
    str_contains($auth, 'ADMINISTRATIVE_TOKEN_TTL_SECONDS = 604800')
        && str_contains($auth, ':ttl_seconds')
        && str_contains($auth, "INTERVAL '1 second'")
        && !str_contains($auth, "INTERVAL '12 hours'"),
    'TTL de sete dias usa uma unica constante para resposta e banco'
);
verificarSessaoAdministrativa(
    str_contains($loginMaster, "'message' => 'Erro ao iniciar sessao administrativa'")
        && str_contains($loginMaster, 'emitirSessaoMaster(')
        && preg_match('/catch \(Throwable \$e\).*?http_response_code\(500\).*?exit;/s', $loginMaster) === 1,
    'login Master emite access e refresh atomicamente e falha fechado'
);
verificarSessaoAdministrativa(
    str_contains($loginRevendedor, "emitirTokenAdministrativo(")
        && preg_match('/catch \(Throwable \$e\).*?"success" => false.*?exit;/s', $loginRevendedor) === 1,
    'login Revendedor preserva contrato de falha fechada'
);
verificarSessaoAdministrativa(
    str_contains($logout, "!== 'POST'")
        && str_contains($logout, 'revogarCredenciaisAdministrativasApresentadas(')
        && str_contains($logout, "'code' => 'SESSION_CLOSED'")
        && !str_contains($logout, 'autenticarTokenAdministrativo($pdo)'),
    'logout e POST, aceita refresh opcional e preserva repeticao segura'
);
verificarSessaoAdministrativa(
    str_contains($auth, 'CREDENTIAL_MISMATCH')
        && str_contains($auth, "WHERE actor_type = 'master'")
        && str_contains($auth, 'WHERE admin_id = :admin_id')
        && !str_contains($auth, 'DELETE FROM administrative_tokens'),
    'logout pareia credenciais e revoga somente tokens do Master identificado'
);

foreach ([
    'editar_admin.php' => $editarAdmin,
    'editar_revendedor.php' => $editarRevendedor,
    'reset_senha_admin_master.php' => $resetMaster,
] as $arquivo => $fonte) {
    verificarSessaoAdministrativa(
        str_contains($fonte, 'beginTransaction()')
            && str_contains($fonte, 'revogarTokensAdministrativosDoAtor(')
            && str_contains($fonte, 'commit()')
            && str_contains($fonte, 'rollBack()'),
        "{$arquivo} altera senha e revoga tokens atomicamente"
    );
}

verificarSessaoAdministrativa(
    str_contains($auth, 'ADMINISTRATIVE_REFRESH_TOKEN_TTL_SECONDS = 7776000')
        && str_contains($auth, 'administrative_refresh_tokens')
        && str_contains($auth, 'emitirRefreshTokenMaster(')
        && str_contains($auth, 'renovarSessaoMaster(')
        && !str_contains($auth, 'SET expira_em ='),
    'refresh Master tem 90 dias absolutos e nao estende a validade'
);
verificarSessaoAdministrativa(
    str_contains($auth, 'function bloquearAdministradorMaster(')
        && str_contains($auth, 'FOR UPDATE')
        && preg_match(
            '/function renovarSessaoMaster.*?bloquearAdministradorMaster.*?FOR UPDATE.*?emitirTokenAdministrativo/s',
            $auth
        ) === 1
        && preg_match(
            '/function revogarTokensAdministrativosDoAtor.*?bloquearAdministradorMaster.*?UPDATE administrative_tokens/s',
            $auth
        ) === 1,
    'renovacao e revogacao Master compartilham a ordem de locks admins antes de tokens'
);
verificarSessaoAdministrativa(
    str_contains($renovacaoMaster, "!== 'POST'")
        && str_contains($renovacaoMaster, 'renovarSessaoMaster($pdo, $refreshToken)')
        && str_contains($renovacaoMaster, "'code' => 'REFRESH_REQUIRED'"),
    'endpoint de renovacao e POST e exige refresh token'
);
verificarSessaoAdministrativa(
    str_contains($migrationRefresh, 'CREATE TABLE IF NOT EXISTS administrative_refresh_tokens')
        && str_contains($migrationRefresh, 'REFERENCES admins (id)')
        && str_contains($migrationRefresh, 'token_hash CHAR(64) NOT NULL UNIQUE')
        && str_contains($migrationRefresh, 'BEGIN;')
        && str_contains($migrationRefresh, 'COMMIT;'),
    'migration refresh e transacional, isolada e vinculada a admins'
);
verificarSessaoAdministrativa(
    !str_contains($auth, 'cliente_tokens')
        && !str_contains($auth, 'roku')
        && !str_contains($auth, 'samsung')
        && !str_contains($loginRevendedor, 'refresh_token')
        && !str_contains($loginRevendedor, 'emitirSessaoMaster'),
    'mudanca nao afeta Cliente, Roku, Samsung ou login Revendedor'
);

exit($falhas === [] ? 0 : 1);
