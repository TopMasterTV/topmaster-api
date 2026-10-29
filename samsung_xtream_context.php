<?php

declare(strict_types=1);

require_once __DIR__ . '/samsung_token_auth.php';
require_once __DIR__ . '/samsung_sistema_context.php';

function samsungAuthorizationDiagnosticsEnabled(): bool
{
    return getenv('SAMSUNG_AUTH_DIAGNOSTICS_ENABLED') === '1';
}

function samsungAuthorizationReasonClass(?string $reason): string
{
    $allowed = [
        'STATUS_NOT_ACTIVE',
        'EXP_DATE_INVALID',
        'EXP_DATE_EXPIRED',
        'VENCIMENTO_INVALID',
        'VENCIMENTO_EXPIRED',
    ];

    return $reason !== null && in_array($reason, $allowed, true)
        ? $reason
        : 'UNKNOWN';
}

function samsungAuthorizationStatusClass(mixed $status): string
{
    $normalized = strtolower(trim((string) $status));

    if ($normalized === '') {
        return 'MISSING';
    }
    if ($normalized === 'active') {
        return 'ACTIVE';
    }
    if ($normalized === 'unknown') {
        return 'UNKNOWN';
    }
    if (in_array($normalized, ['inactive', 'inativo', 'inativa', 'disabled', 'blocked'], true)) {
        return 'INACTIVE';
    }
    if (in_array($normalized, ['expired', 'expirado', 'expirada'], true)) {
        return 'EXPIRED';
    }

    return 'OTHER';
}

function samsungAuthorizationExpDateClass(mixed $expirationTimestamp): string
{
    if ($expirationTimestamp === null) {
        return 'ABSENT';
    }

    $normalized = trim((string) $expirationTimestamp);
    if ($normalized === '' || !ctype_digit($normalized)) {
        return 'INVALID';
    }

    return (int) $normalized <= time() ? 'EXPIRED' : 'FUTURE';
}

function samsungAuthorizationExpirationClass(mixed $expirationDate): string
{
    if ($expirationDate === null) {
        return 'ABSENT';
    }

    $businessTimezone = new DateTimeZone('America/Sao_Paulo');
    $normalized = trim((string) $expirationDate);
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $normalized, $businessTimezone);
    $parseErrors = DateTimeImmutable::getLastErrors();
    if ($parsedDate === false
        || ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))) {
        return 'INVALID';
    }

    return $parsedDate < new DateTimeImmutable('today', $businessTimezone) ? 'EXPIRED' : 'FUTURE';
}

function samsungLogAuthorizationDiagnostics(array $context, ?string $reason): void
{
    if (!samsungAuthorizationDiagnosticsEnabled()) {
        return;
    }

    error_log(sprintf(
        'endpoint=samsung_xtream_context.php authorization_reason=%s status_class=%s exp_date_class=%s vencimento_class=%s',
        samsungAuthorizationReasonClass($reason),
        samsungAuthorizationStatusClass($context['status'] ?? null),
        samsungAuthorizationExpDateClass($context['exp_date'] ?? null),
        samsungAuthorizationExpirationClass($context['vencimento'] ?? null)
    ));
}

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
    $context = samsungGetSystemContext($pdo, $session['cliente_id'], $systemId);

    $authorizationReason = null;
    if (!samsungSystemIsAuthorized($context, $authorizationReason)) {
        samsungLogAuthorizationDiagnostics($context, $authorizationReason);
        samsungJsonError(403, 'SYSTEM_NOT_AUTHORIZED', 'Sistema não autorizado');
    }
    if (($context['tipo_acesso'] ?? null) !== 'xtream') {
        samsungJsonError(409, 'SYSTEM_ACCESS_UNAVAILABLE', 'Sistema indisponível para esta operação');
    }

    samsungJson(200, [
        'success' => true,
        'data' => [
            'base_url' => (string) $context['fornecedor_url'],
            'username' => (string) $context['usuario'],
            'password' => (string) $context['senha'],
        ],
    ]);
} catch (SamsungAuthException $error) {
    samsungRespondAuthError($error);
} catch (SamsungSistemaException $error) {
    samsungJsonError($error->getStatusHttp(), $error->getCodigoPublico(), $error->getMensagemPublica());
} catch (Throwable) {
    samsungJsonError(500, 'INTERNAL_ERROR', 'Não foi possível obter o contexto do sistema');
}
