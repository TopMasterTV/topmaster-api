<?php

declare(strict_types=1);

require_once __DIR__ . '/samsung_token_auth.php';

samsungRequireMethod('GET');
if ($_GET !== []) {
    samsungJsonError(400, 'INVALID_REQUEST', 'Requisição inválida');
}

try {
    $session = samsungAuthenticate(samsungDatabase());
    samsungJson(200, [
        'success' => true,
        'data' => [
            'cliente' => [
                'id' => $session['cliente_id'],
                'nome' => $session['nome'],
                'usuario' => $session['usuario'],
                'plano' => $session['plano'],
            ],
            'expira_em' => $session['expira_em'],
        ],
    ]);
} catch (SamsungAuthException $error) {
    samsungRespondAuthError($error);
} catch (Throwable) {
    samsungJsonError(500, 'INTERNAL_ERROR', 'Não foi possível validar a sessão');
}
