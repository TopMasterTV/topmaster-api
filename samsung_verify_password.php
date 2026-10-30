<?php

declare(strict_types=1);

require_once __DIR__ . '/samsung_token_auth.php';

samsungRequireMethod('POST');
$input = samsungReadJsonBody();
if (array_keys($input) !== ['senha'] || !is_string($input['senha']) || $input['senha'] === '' || strlen($input['senha']) > 255) {
    samsungJsonError(400, 'INVALID_REQUEST', 'Requisição inválida');
}

$pdo = null;
try {
    $pdo = samsungDatabase();
    $pdo->beginTransaction();
    $session = samsungAuthenticate($pdo);
    $rateKey = hash('sha256', 'adult-password|' . (string) $session['cliente_id']);

    $insertRate = $pdo->prepare(<<<'SQL'
        INSERT INTO login_rate_limits (
            app_tipo, escopo, chave_hash, tentativas_falhas,
            janela_inicio, ultima_tentativa_em, criado_em, atualizado_em
        ) VALUES ('samsung_adult', 'usuario', :key_hash, 0,
            clock_timestamp(), clock_timestamp(), clock_timestamp(), clock_timestamp())
        ON CONFLICT (app_tipo, escopo, chave_hash) DO NOTHING
        SQL);
    $insertRate->execute([':key_hash' => $rateKey]);

    $resetRate = $pdo->prepare(<<<'SQL'
        UPDATE login_rate_limits
        SET tentativas_falhas = 0, janela_inicio = clock_timestamp(), bloqueado_ate = NULL,
            atualizado_em = clock_timestamp()
        WHERE app_tipo = 'samsung_adult' AND escopo = 'usuario' AND chave_hash = :key_hash
          AND ((bloqueado_ate IS NOT NULL AND bloqueado_ate <= clock_timestamp())
            OR (bloqueado_ate IS NULL AND janela_inicio < clock_timestamp() - INTERVAL '15 minutes'))
        SQL);
    $resetRate->execute([':key_hash' => $rateKey]);

    $selectRate = $pdo->prepare(<<<'SQL'
        SELECT tentativas_falhas,
            CASE WHEN bloqueado_ate > clock_timestamp()
                THEN GREATEST(1, CEIL(EXTRACT(EPOCH FROM (bloqueado_ate - clock_timestamp())))::INTEGER)
                ELSE 0 END AS retry_after
        FROM login_rate_limits
        WHERE app_tipo = 'samsung_adult' AND escopo = 'usuario' AND chave_hash = :key_hash
        FOR UPDATE
        SQL);
    $selectRate->execute([':key_hash' => $rateKey]);
    $rate = $selectRate->fetch(PDO::FETCH_ASSOC);
    if (!$rate) { throw new RuntimeException('Controle de tentativas indisponível'); }
    if ((int) $rate['retry_after'] > 0) {
        $pdo->commit();
        header('Retry-After: ' . (int) $rate['retry_after']);
        samsungJson(429, ['success' => false, 'error' => ['code' => 'RATE_LIMITED', 'message' => 'Muitas tentativas. Aguarde e tente novamente.', 'retry_after' => (int) $rate['retry_after']]]);
    }

    $query = $pdo->prepare('SELECT senha FROM clientes WHERE id = :cliente_id AND ativo = TRUE LIMIT 1');
    $query->execute([':cliente_id' => $session['cliente_id']]);
    $client = $query->fetch(PDO::FETCH_ASSOC);
    if (!$client) { throw new SamsungAuthException(403, 'CLIENT_INACTIVE', 'Acesso indisponível'); }

    if (!samsungVerifyStoredClientPassword($input['senha'], (string) $client['senha'])) {
        $attempts = (int) $rate['tentativas_falhas'] + 1;
        $updateRate = $pdo->prepare(<<<'SQL'
            UPDATE login_rate_limits
            SET tentativas_falhas = :attempts,
                bloqueado_ate = CASE WHEN CAST(:blocked AS INTEGER) = 1 THEN clock_timestamp() + INTERVAL '15 minutes' ELSE NULL END,
                ultima_tentativa_em = clock_timestamp(), atualizado_em = clock_timestamp()
            WHERE app_tipo = 'samsung_adult' AND escopo = 'usuario' AND chave_hash = :key_hash
            SQL);
        $updateRate->execute([':attempts' => $attempts, ':blocked' => $attempts >= 5 ? 1 : 0, ':key_hash' => $rateKey]);
        $pdo->commit();
        if ($attempts >= 5) {
            header('Retry-After: 900');
            samsungJson(429, ['success' => false, 'error' => ['code' => 'RATE_LIMITED', 'message' => 'Muitas tentativas. Aguarde e tente novamente.', 'retry_after' => 900]]);
        }
        samsungJsonError(401, 'INVALID_PASSWORD', 'Senha incorreta');
    }

    $clearRate = $pdo->prepare(<<<'SQL'
        UPDATE login_rate_limits
        SET tentativas_falhas = 0, janela_inicio = clock_timestamp(), bloqueado_ate = NULL,
            ultima_tentativa_em = clock_timestamp(), atualizado_em = clock_timestamp()
        WHERE app_tipo = 'samsung_adult' AND escopo = 'usuario' AND chave_hash = :key_hash
        SQL);
    $clearRate->execute([':key_hash' => $rateKey]);
    $pdo->commit();
    samsungJson(200, ['success' => true, 'data' => ['verified' => true]]);
} catch (SamsungAuthException $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable) {} }
    samsungRespondAuthError($error);
} catch (Throwable) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable) {} }
    samsungJsonError(500, 'INTERNAL_ERROR', 'Não foi possível verificar a senha');
}
