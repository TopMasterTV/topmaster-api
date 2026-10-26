<?php

declare(strict_types=1);

require_once __DIR__ . '/samsung_token_auth.php';

samsungRequireMethod('GET');
if ($_GET !== []) {
    samsungJsonError(400, 'INVALID_REQUEST', 'Requisição inválida');
}

try {
    $pdo = samsungDatabase();
    $session = samsungAuthenticate($pdo);

    $statement = $pdo->prepare(<<<'SQL'
        SELECT
            sistema.id,
            COALESCE(
                NULLIF(TRIM(modelo.nome), ''),
                NULLIF(TRIM(sistema.nome_sistema), ''),
                'Sistema'
            ) AS nome,
            COALESCE(NULLIF(TRIM(sistema.status), ''), 'Unknown') AS status,
            sistema.vencimento
        FROM sistemas AS sistema
        LEFT JOIN modelos_sistemas AS modelo ON modelo.id = sistema.modelo_id
        WHERE sistema.cliente_id = :cliente_id
        ORDER BY sistema.id ASC
        SQL);
    $statement->execute([':cliente_id' => $session['cliente_id']]);

    $systems = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $system) {
        $systems[] = [
            'id' => (int) $system['id'],
            'nome' => (string) $system['nome'],
            'status' => (string) $system['status'],
            'vencimento' => $system['vencimento'] !== null ? (string) $system['vencimento'] : null,
        ];
    }

    samsungJson(200, [
        'success' => true,
        'data' => [
            'cliente' => [
                'id' => $session['cliente_id'],
                'nome' => $session['nome'],
                'usuario' => $session['usuario'],
                'plano' => $session['plano'],
            ],
            'sistemas' => $systems,
        ],
    ]);
} catch (SamsungAuthException $error) {
    samsungRespondAuthError($error);
} catch (Throwable) {
    samsungJsonError(500, 'INTERNAL_ERROR', 'Não foi possível carregar os sistemas');
}
