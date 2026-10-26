<?php

declare(strict_types=1);

require_once __DIR__ . '/samsung_token_auth.php';

samsungRequireMethod('POST');

$pdo = null;
try {
    $pdo = samsungDatabase();
    $pdo->beginTransaction();
    $session = samsungAuthenticate($pdo);

    $statement = $pdo->prepare(<<<'SQL'
        UPDATE cliente_tokens
        SET revogado_em = clock_timestamp(), motivo_revogacao = 'USER_LOGOUT'
        WHERE id = :token_id
          AND cliente_id = :cliente_id
          AND app_tipo = 'samsung'
          AND revogado_em IS NULL
        SQL);
    $statement->execute([
        ':token_id' => $session['token_id'],
        ':cliente_id' => $session['cliente_id'],
    ]);

    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Sessão não revogada');
    }
    $pdo->commit();
    samsungJson(200, ['success' => true]);
} catch (SamsungAuthException $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable) {}
    }
    samsungRespondAuthError($error);
} catch (Throwable) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable) {}
    }
    samsungJsonError(500, 'INTERNAL_ERROR', 'Não foi possível encerrar a sessão');
}
