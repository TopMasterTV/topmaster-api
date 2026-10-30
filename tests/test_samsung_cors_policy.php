<?php

declare(strict_types=1);

function testAssert(string $name, bool $passed): void
{
    fwrite(STDOUT, ($passed ? 'OK: ' : 'FALHA: ') . $name . PHP_EOL);
    if (!$passed) { exit(1); }
}

if (($argv[1] ?? '') === 'unauthorized-origin') {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
    $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/samsung_verify_password.php';
    $_SERVER['HTTP_ORIGIN'] = 'https://origem-nao-autorizada.invalid';
    $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] = 'POST';
    putenv('SAMSUNG_ALLOWED_ORIGIN=https://samsung-autorizado.example');
    require dirname(__DIR__) . '/samsung_common.php';
    exit(2);
}

$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
unset($_SERVER['HTTP_ORIGIN']);
require_once dirname(__DIR__) . '/samsung_common.php';

$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/samsung_verify_password.php';
$verifyPolicy = samsungCorsPolicy();
testAssert('POST permitido no endpoint de senha', $verifyPolicy['methods'] === ['POST']);
testAssert('Authorization permitido no endpoint de senha', in_array('Authorization', $verifyPolicy['headers'], true));
testAssert('Content-Type permitido no endpoint de senha', in_array('Content-Type', $verifyPolicy['headers'], true));
testAssert('OPTIONS incluido pela camada CORS', in_array('OPTIONS', array_merge($verifyPolicy['methods'], ['OPTIONS']), true));

$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/samsung_login.php';
testAssert('politica do login preservada', samsungCorsPolicy() === ['methods' => ['POST'], 'headers' => ['Content-Type']]);
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/samsung_session.php';
testAssert('politica GET existente preservada', samsungCorsPolicy() === ['methods' => ['GET'], 'headers' => ['Authorization']]);
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/arquivo_desconhecido.php';
testAssert('endpoint desconhecido continua bloqueado', samsungCorsPolicy() === ['methods' => [], 'headers' => []]);

$endpointSource = file_get_contents(dirname(__DIR__) . '/samsung_verify_password.php');
testAssert('POST efetivo exige autenticacao Bearer', is_string($endpointSource)
    && strpos($endpointSource, 'samsungAuthenticate($pdo)') !== false);
testAssert('preflight ocorre no bootstrap antes da autenticacao', is_string($endpointSource)
    && strpos($endpointSource, "require_once __DIR__ . '/samsung_token_auth.php'") !== false
    && strpos($endpointSource, 'samsungAuthenticate($pdo)') !== false);
testAssert('corpo efetivo exige JSON', is_string($endpointSource)
    && strpos($endpointSource, 'samsungReadJsonBody()') !== false);
testAssert('endpoint nao devolve senha ou credencial', is_string($endpointSource)
    && strpos($endpointSource, "'data' => ['verified' => true]") !== false
    && strpos($endpointSource, "'senha' =>") === false
    && strpos($endpointSource, 'access_token') === false);

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' unauthorized-origin';
exec($command, $output, $exitCode);
$joined = implode("\n", $output);
testAssert('origem nao autorizada rejeitada', $exitCode === 0
    && strpos($joined, 'ORIGIN_NOT_ALLOWED') !== false);

require_once dirname(__DIR__) . '/samsung_token_auth.php';
unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
try { samsungBearerToken(); testAssert('POST sem Bearer rejeitado', false); }
catch (SamsungAuthException $error) { testAssert('POST sem Bearer rejeitado', $error->getPublicCode() === 'AUTH_REQUIRED'); }
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer token-invalido';
try { samsungBearerToken(); testAssert('Bearer malformado rejeitado', false); }
catch (SamsungAuthException $error) { testAssert('Bearer malformado rejeitado', $error->getPublicCode() === 'AUTH_REQUIRED'); }
