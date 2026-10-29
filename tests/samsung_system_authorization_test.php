<?php

declare(strict_types=1);

function samsungAuthorizationTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = file_get_contents(dirname(__DIR__) . '/samsung_common.php');
samsungAuthorizationTestAssert(is_string($source), 'samsung_common.php indisponivel');
$start = strpos($source, 'function samsungSystemIsAuthorized');
$brace = $start === false ? false : strpos($source, '{', $start);
samsungAuthorizationTestAssert($start !== false && $brace !== false, 'Helper de autorizacao ausente');
$depth = 0;
$end = null;
for ($index = $brace, $length = strlen($source); $index < $length; $index++) {
    if ($source[$index] === '{') {
        $depth++;
    } elseif ($source[$index] === '}') {
        $depth--;
        if ($depth === 0) {
            $end = $index + 1;
            break;
        }
    }
}
samsungAuthorizationTestAssert($end !== null, 'Corpo do helper incompleto');
eval(substr($source, $start, $end - $start));

$today = new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
$future = $today->modify('+30 days')->format('Y-m-d');
$past = $today->modify('-1 day')->format('Y-m-d');
$authorized = [
    'GP TOP' => ['status' => 'Active', 'exp_date' => '4102444800', 'vencimento' => $future],
    'INVICTUS Unknown' => ['status' => 'Unknown', 'exp_date' => null, 'vencimento' => $future],
    'outro sistema sem status' => ['status' => '', 'exp_date' => '', 'vencimento' => $future],
    'exp_date auxiliar antigo' => ['status' => 'Expired', 'exp_date' => '1', 'vencimento' => $future],
    'vence hoje no fuso oficial' => ['status' => 'Unknown', 'exp_date' => '1', 'vencimento' => $today->format('Y-m-d')],
];
foreach ($authorized as $name => $fixture) {
    $reason = null;
    samsungAuthorizationTestAssert(samsungSystemIsAuthorized($fixture, $reason), "$name deveria ser autorizado");
    samsungAuthorizationTestAssert($reason === null, "$name nao deveria produzir bloqueio");
}
$reason = null;
samsungAuthorizationTestAssert(!samsungSystemIsAuthorized(['vencimento' => $past], $reason), 'Vencido deveria ser negado');
samsungAuthorizationTestAssert($reason === 'VENCIMENTO_EXPIRED', 'Motivo de vencimento incorreto');
foreach ([null, '', '2026-02-30', '29/10/2026'] as $invalidDate) {
    $reason = null;
    samsungAuthorizationTestAssert(!samsungSystemIsAuthorized(['vencimento' => $invalidDate], $reason), 'Data invalida deveria ser negada');
    samsungAuthorizationTestAssert($reason === 'VENCIMENTO_INVALID', 'Motivo de data invalida incorreto');
}

echo "SAMSUNG_SYSTEM_AUTHORIZATION_TEST_PASS\n";
