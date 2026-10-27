<?php

declare(strict_types=1);

require_once __DIR__ . '/samsung_token_auth.php';

samsungRequireMethod('GET');
if ($_GET !== []) {
    samsungJsonError(400, 'INVALID_REQUEST', 'Requisição inválida');
}

try {
    $pdo = samsungDatabase();
    $pdo->beginTransaction();
    $session = samsungAuthenticate($pdo);

    $renew = $pdo->prepare(<<<'SQL'
        UPDATE cliente_tokens AS token
        SET expira_em = clock_timestamp() + INTERVAL '30 days'
        WHERE token.id = :token_id
          AND token.cliente_id = :cliente_id
          AND token.app_tipo = 'samsung'
          AND token.revogado_em IS NULL
          AND token.expira_em > clock_timestamp()
          AND token.expira_em <= clock_timestamp() + INTERVAL '7 days'
          AND EXISTS (
              SELECT 1
              FROM clientes AS cliente
              WHERE cliente.id = token.cliente_id
                AND cliente.ativo = TRUE
          )
        RETURNING token.expira_em,
            GREATEST(1, CEIL(EXTRACT(EPOCH FROM (token.expira_em - clock_timestamp()))))::INTEGER AS expires_in
        SQL);
    $renew->execute([
        ':token_id' => $session['token_id'],
        ':cliente_id' => $session['cliente_id'],
    ]);
    $validity = $renew->fetch(PDO::FETCH_ASSOC);

    if (!$validity) {
        $current = $pdo->prepare(<<<'SQL'
            SELECT token.expira_em, token.revogado_em, cliente.ativo,
                (token.expira_em <= clock_timestamp()) AS expirado,
                GREATEST(1, CEIL(EXTRACT(EPOCH FROM (token.expira_em - clock_timestamp()))))::INTEGER AS expires_in
            FROM cliente_tokens AS token
            INNER JOIN clientes AS cliente ON cliente.id = token.cliente_id
            WHERE token.id = :token_id
              AND token.cliente_id = :cliente_id
              AND token.app_tipo = 'samsung'
            LIMIT 1
            FOR UPDATE OF token, cliente
            SQL);
        $current->execute([
            ':token_id' => $session['token_id'],
            ':cliente_id' => $session['cliente_id'],
        ]);
        $validity = $current->fetch(PDO::FETCH_ASSOC);

        if (!$validity || $validity['revogado_em'] !== null) {
            throw new SamsungAuthException(401, 'INVALID_TOKEN', 'Sessão inválida');
        }
        if (samsungPgBool($validity['expirado'])) {
            throw new SamsungAuthException(401, 'TOKEN_EXPIRED', 'Sessão expirada');
        }
        if (!samsungPgBool($validity['ativo'])) {
            throw new SamsungAuthException(403, 'CLIENT_INACTIVE', 'Acesso indisponível. Entre em contato com o suporte.');
        }
    }

    $pdo->commit();
    samsungJson(200, [
        'success' => true,
        'data' => [
            'cliente' => [
                'id' => $session['cliente_id'],
                'nome' => $session['nome'],
                'usuario' => $session['usuario'],
                'plano' => $session['plano'],
            ],
            'expires_in' => (int) $validity['expires_in'],
            'expira_em' => (string) $validity['expira_em'],
        ],
    ]);
} catch (SamsungAuthException $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable) {}
    }
    samsungRespondAuthError($error);
} catch (Throwable) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable) {}
    }
    samsungJsonError(500, 'INTERNAL_ERROR', 'Não foi possível validar a sessão');
}
