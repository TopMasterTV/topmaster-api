<?php

declare(strict_types=1);

function samsungContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function samsungDiagnosticMessageIsSanitized(string $message): bool
{
    return preg_match(
        '/\Aendpoint=samsung_xtream_context\.php '
        . 'authorization_reason=(?:STATUS_NOT_ACTIVE|EXP_DATE_INVALID|EXP_DATE_EXPIRED|VENCIMENTO_INVALID|VENCIMENTO_EXPIRED|UNKNOWN) '
        . 'status_class=(?:MISSING|ACTIVE|UNKNOWN|INACTIVE|EXPIRED|OTHER) '
        . 'exp_date_class=(?:ABSENT|INVALID|EXPIRED|FUTURE) '
        . 'vencimento_class=(?:ABSENT|INVALID|EXPIRED|FUTURE)\z/D',
        $message
    ) === 1;
}

$root = dirname(__DIR__);
$context = file_get_contents($root . '/samsung_sistema_context.php');
$endpoint = file_get_contents($root . '/samsung_xtream_context.php');
$token = file_get_contents($root . '/samsung_token_auth.php');
samsungContractAssert(is_string($context) && is_string($endpoint) && is_string($token), 'Fontes indisponiveis');
samsungContractAssert(str_contains($context, 's.id = :sistema_id'), 'Sistema selecionado sem filtro');
samsungContractAssert(str_contains($context, 's.cliente_id = :cliente_id'), 'Propriedade sem filtro');
samsungContractAssert(str_contains($endpoint, 'samsungAuthenticate($pdo)'), 'Bearer nao exigido');
samsungContractAssert(str_contains($endpoint, "samsungGetSystemContext(\$pdo, \$session['cliente_id'], \$systemId)"), 'Contexto nao usa a sessao e selecao');
samsungContractAssert(str_contains($endpoint, 'samsungSystemIsAuthorized($context, $authorizationReason)'), 'Regra administrativa ausente');
samsungContractAssert(str_contains($endpoint, "'SYSTEM_ACCESS_UNAVAILABLE'"), 'Erro especifico de contexto ausente');
samsungContractAssert(str_contains($token, "if (!samsungPgBool(\$session['ativo']))"), 'Bloqueio administrativo ausente');
samsungContractAssert(str_contains($endpoint, "getenv('SAMSUNG_AUTH_DIAGNOSTICS_ENABLED') === '1'"), 'Diagnostico nao esta opt-in');
$logStart = strpos($endpoint, 'function samsungLogAuthorizationDiagnostics');
$logEnd = strpos($endpoint, 'samsungRequireMethod', $logStart === false ? 0 : $logStart);
samsungContractAssert($logStart !== false && $logEnd !== false, 'Diagnostico sanitizado ausente');
$logSource = substr($endpoint, $logStart, $logEnd - $logStart);

samsungContractAssert(
    preg_match("/error_log\\(sprintf\\(\\s*'([^']+)'/", $logSource, $formatMatch) === 1,
    'Formato efetivo do diagnostico nao identificado'
);
$diagnosticMessage = sprintf(
    $formatMatch[1],
    'VENCIMENTO_EXPIRED',
    'UNKNOWN',
    'EXPIRED',
    'FUTURE'
);
samsungContractAssert(
    samsungDiagnosticMessageIsSanitized($diagnosticMessage),
    'Formato efetivo do diagnostico nao esta restrito a classificacoes sanitizadas'
);

foreach (["['senha']", "['usuario']", 'fornecedor_url', 'token_hash'] as $sensitiveSourceMarker) {
    samsungContractAssert(
        !str_contains($logSource, $sensitiveSourceMarker),
        'Composicao do diagnostico referencia campo sensivel'
    );
}

$unsafeDiagnosticMessages = [
    $diagnosticMessage . ' Authorization: Bearer 0123456789abcdef',
    $diagnosticMessage . ' username=cliente_xtream',
    $diagnosticMessage . ' password=senha_xtream',
    $diagnosticMessage . ' url=http://provedor.test/player_api.php?username=u&password=p',
    $diagnosticMessage . ' cliente_id=123 sistema_id=456',
    $diagnosticMessage . ' vencimento=2026-12-31 exp_date=1798675200',
];
foreach ($unsafeDiagnosticMessages as $unsafeMessage) {
    samsungContractAssert(
        !samsungDiagnosticMessageIsSanitized($unsafeMessage),
        'Validador aceitou mensagem ficticia com dado sensivel'
    );
}

echo "SAMSUNG_XTREAM_AUTHORIZATION_CONTRACT_TEST_PASS\n";
