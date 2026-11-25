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
        && preg_match('/catch \(Throwable \$e\).*?http_response_code\(500\).*?exit;/s', $loginMaster) === 1,
    'login Master falha fechado quando a emissao do token falha'
);
verificarSessaoAdministrativa(
    str_contains($loginRevendedor, "emitirTokenAdministrativo(")
        && preg_match('/catch \(Throwable \$e\).*?"success" => false.*?exit;/s', $loginRevendedor) === 1,
    'login Revendedor preserva contrato de falha fechada'
);
verificarSessaoAdministrativa(
    str_contains($logout, "!== 'POST'")
        && str_contains($logout, 'revogarTokenAdministrativoApresentado($pdo)')
        && str_contains($logout, "'code' => 'SESSION_CLOSED'")
        && !str_contains($logout, 'autenticarTokenAdministrativo($pdo)'),
    'logout e POST e permite repeticao sem autenticar token ja revogado'
);
verificarSessaoAdministrativa(
    str_contains($auth, 'WHERE token_hash = :token_hash')
        && !str_contains($auth, 'DELETE FROM administrative_tokens'),
    'logout limita a revogacao ao hash apresentado e nao remove sessoes'
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
    !str_contains($auth, 'cliente_tokens')
        && !str_contains($auth, 'roku')
        && !str_contains($auth, 'samsung')
        && !str_contains($auth, 'refresh_token'),
    'mudanca nao afeta tokens de clientes nem adiciona refresh'
);

exit($falhas === [] ? 0 : 1);
