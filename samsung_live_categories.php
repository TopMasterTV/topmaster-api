<?php

declare(strict_types=1);

require_once __DIR__ . '/samsung_token_auth.php';
require_once __DIR__ . '/roku_sistema_context.php';
require_once __DIR__ . '/roku_xtream_categories.php';

samsungRequireMethod('GET');

if (
    count($_GET) !== 1
    || !array_key_exists('sistema_id', $_GET)
    || !is_string($_GET['sistema_id'])
    || preg_match('/^[1-9][0-9]{0,18}$/D', $_GET['sistema_id']) !== 1
) {
    samsungJsonError(400, 'INVALID_REQUEST', 'Requisição inválida');
}

$systemId = (int) $_GET['sistema_id'];
if ($systemId <= 0 || (string) $systemId !== $_GET['sistema_id']) {
    samsungJsonError(400, 'INVALID_REQUEST', 'Requisição inválida');
}

try {
    $pdo = samsungDatabase();
    $session = samsungAuthenticate($pdo);
    $context = obterContextoSistemaRoku($pdo, $session['cliente_id'], $systemId);

    if (!samsungSystemIsAuthorized($context)) {
        samsungJsonError(403, 'SYSTEM_NOT_AUTHORIZED', 'Sistema não autorizado');
    }
    if (($context['tipo_acesso'] ?? null) !== 'xtream') {
        samsungJsonError(409, 'SYSTEM_ACCESS_UNAVAILABLE', 'Sistema indisponível para esta operação');
    }

    $categories = obterCategoriasXtreamRoku(
        (string) $context['fornecedor_url'],
        (string) $context['usuario'],
        (string) $context['senha'],
        'live'
    );

    $publicCategories = [];
    foreach ($categories as $category) {
        $publicCategories[] = [
            'id' => $category['id'],
            'nome' => $category['nome'],
        ];
    }

    samsungJson(200, [
        'success' => true,
        'data' => ['categorias' => $publicCategories],
    ]);
} catch (SamsungAuthException $error) {
    samsungRespondAuthError($error);
} catch (RokuSistemaException $error) {
    samsungJsonError($error->getStatusHttp(), $error->getCodigoPublico(), $error->getMensagemPublica());
} catch (RokuXtreamException $error) {
    samsungJsonError($error->getStatusHttp(), $error->getCodigoPublico(), $error->getMensagemPublica());
} catch (Throwable) {
    samsungJsonError(500, 'INTERNAL_ERROR', 'Não foi possível carregar as categorias');
}
