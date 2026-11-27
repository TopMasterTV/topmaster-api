<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Probe.php';

final class FakeProbeApi implements TopGestorProbeApiClient
{
    public int $calls = 0;
    /** @param array<string, mixed> $response */
    public function __construct(private readonly array $response) {}
    public function fetchCustomer(string $customerId): array { $this->calls++; return $this->response; }
}

function ok(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    fwrite(STDOUT, 'OK: ' . $message . PHP_EOL);
}

function fixture(string $productId = '4409', string $productName = 'Sistema GP'): array
{
    return ['success'=>true,'data'=>[
        'id'=>143528,'name'=>'PERSONAL_FIXTURE','usuario'=>'USER_FIXTURE','password'=>'PASSWORD_FIXTURE',
        'product'=>['id'=>(int)$productId,'name'=>$productName], 'product_name'=>$productName,
        'data_de_vencimento'=>'2027-02-25','updated_at'=>'2026-11-26T12:34:56Z','accesses'=>[],
    ]];
}

function signed(string $secret, int $customer = 143528, string $delivery = 'delivery-1', string $event = 'customer.renewed'): array
{
    $body = json_encode(['event'=>$event,'delivery_id'=>$delivery,'customer'=>['id'=>$customer]], JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) throw new RuntimeException('FIXTURE_INVALID');
    return ['body'=>$body,'signature'=>'sha256=' . hash_hmac('sha256', $body, $secret)];
}

function directory(string $name): string
{
    return sys_get_temp_dir() . '/topgestor-probe-' . $name . '-' . bin2hex(random_bytes(5));
}

function cleanup(string $directory): void
{
    foreach (glob($directory . '/*') ?: [] as $path) if (is_file($path)) unlink($path);
    if (is_dir($directory)) rmdir($directory);
}

function probe(FakeProbeApi $api, string $directory, string $secret, array &$logs): TopGestorWebhookProbe
{
    return new TopGestorWebhookProbe(
        $api, new TopGestorProbeDeliveryGuard($directory, $secret), $secret, '4409', 'Sistema GP', '2027-01-25',
        static function (array $metadata) use (&$logs): void { $logs[] = $metadata; },
    );
}

/** @return array{result:array{status:int,body:array<string,mixed>},logs:list<array<string,mixed>>} */
function diagnostic(array $headers, string $body): array
{
    $logs = [];
    $service = new TopGestorWebhookDiagnostic(static function (array $metadata) use (&$logs): void { $logs[] = $metadata; });
    return ['result'=>$service->observe('POST','/webhooks/top-gestor',$headers,$body),'logs'=>$logs];
}

$secret = 'TEST_ONLY_WEBHOOK_SECRET_WITH_AT_LEAST_32_BYTES';
$jsonHeaders = ['Content-Type'=>'application/json'];
$event = signed($secret);
$validHeaders = $jsonHeaders + ['X-Hub-Signature-256'=>$event['signature']];

// Diagnostic mode is opt-in and never authenticates or processes a request.
ok(TopGestorWebhookDiagnostic::isEnabled(false)===false,'modo diagnostico desativado por padrao');
ok(TopGestorWebhookDiagnostic::isEnabled('true')===false,'modo diagnostico exige valor exato');
ok(TopGestorWebhookDiagnostic::isEnabled('1')===true,'modo diagnostico ativado somente por 1');

$diagnostic=diagnostic($jsonHeaders,'{}');$diagnosticLog=$diagnostic['logs'][0]??[];
ok(($diagnosticLog['hmac_header_present']??true)===false,'diagnostico identifica HMAC ausente');
ok(($diagnosticLog['hmac_header_format']??'')==='absent','formato ausente registrado');
ok(($diagnosticLog['content_type']??'')==='application_json','tipo de conteudo categorizado sem valor livre');
ok(($diagnostic['result']['body']['accepted']??true)===false,'diagnostico nao aceita entrega');
ok(($diagnostic['result']['body']['authenticated']??true)===false,'diagnostico nao autentica entrega');
ok(($diagnostic['result']['body']['processed']??true)===false,'diagnostico nao processa entrega');

$diagnostic=diagnostic($jsonHeaders+['X-Hub-Signature-256'=>$event['signature']],$event['body']);
$diagnosticLog=$diagnostic['logs'][0]??[];
ok(($diagnosticLog['hmac_header_present']??false)===true,'diagnostico identifica HMAC presente');
ok(($diagnosticLog['hmac_header_format']??'')==='sha256_equals_hex_64_lowercase','formato HMAC esperado identificado');

$unexpectedSignature='not-a-supported-signature-format';
$diagnostic=diagnostic($jsonHeaders+['X-Hub-Signature-256'=>$unexpectedSignature],'{}');
ok((($diagnostic['logs'][0]??[])['hmac_header_format']??'')==='unexpected','formato HMAC inesperado identificado');

$headerSecret='TEST_ONLY_HEADER_SECRET_MUST_NOT_APPEAR';
$diagnostic=diagnostic($jsonHeaders+['X-Webhook-Secret'=>$headerSecret],'{}');
ok((($diagnostic['logs'][0]??[])['webhook_secret_header_present']??false)===true,'X-Webhook-Secret presente identificado');

$diagnostic=diagnostic($jsonHeaders+['X-Hub-Signature-256'=>$event['signature'],'X-Webhook-Secret'=>$headerSecret],$event['body']);
$diagnosticLog=$diagnostic['logs'][0]??[];
ok(($diagnosticLog['hmac_header_present']??false)===true&&($diagnosticLog['webhook_secret_header_present']??false)===true,'ambos os cabecalhos identificados');
$encodedDiagnostic=json_encode($diagnostic['logs']);
ok(is_string($encodedDiagnostic)&&!str_contains($encodedDiagnostic,$event['signature']),'assinatura ausente dos logs diagnosticos');
ok(is_string($encodedDiagnostic)&&!str_contains($encodedDiagnostic,$headerSecret),'segredo ausente dos logs diagnosticos');
ok(is_string($encodedDiagnostic)&&!str_contains($encodedDiagnostic,$event['body']),'corpo ausente dos logs diagnosticos');
ok(($diagnosticLog['database_accessed']??true)===false,'diagnostico nao acessa PostgreSQL');

$diagnostic=diagnostic($jsonHeaders,str_repeat('A',65537));
ok($diagnostic['result']['status']===413,'corpo acima do limite rejeitado no diagnostico');
ok((($diagnostic['logs'][0]??[])['validation_result']??'')==='DIAGNOSTIC_BODY_REJECTED','rejeicao por tamanho registrada');

// Valid event.
$dir = directory('valid'); $logs=[]; $api=new FakeProbeApi(fixture()); $service=probe($api,$dir,$secret,$logs);
$result=$service->handle('POST','/webhooks/top-gestor',$validHeaders,$event['body']);
ok($result['status']===200,'evento valido aceito');
ok($api->calls===1,'API consultada uma vez');
ok(($result['body']['code']??'')==='PROBE_CONFIRMED','GP principal confirmado');
ok(($result['body']['main_expiration']??'')==='2027-02-25','vencimento lido sem persistencia');
ok(($result['body']['database_accessed']??true)===false,'nenhum banco acessado');
$log=json_encode($logs);
ok(is_string($log)&&!str_contains($log,'PERSONAL_FIXTURE'),'nome pessoal ausente dos logs');
ok(is_string($log)&&!str_contains($log,'USER_FIXTURE'),'usuario ausente dos logs');
ok(is_string($log)&&!str_contains($log,'PASSWORD_FIXTURE'),'senha ausente dos logs');
cleanup($dir);

// Invalid signature.
$dir=directory('signature');$logs=[];$api=new FakeProbeApi(fixture());$service=probe($api,$dir,$secret,$logs);
$result=$service->handle('POST','/webhooks/top-gestor',$jsonHeaders+['X-Hub-Signature-256'=>'sha256='.str_repeat('0',64)],$event['body']);
ok($result['status']===401,'assinatura invalida rejeitada'); ok($api->calls===0,'assinatura invalida nao consulta API'); cleanup($dir);

// Duplicate.
$dir=directory('duplicate');$logs=[];$api=new FakeProbeApi(fixture());$service=probe($api,$dir,$secret,$logs);
$first=$service->handle('POST','/webhooks/top-gestor',$validHeaders,$event['body']);
$second=$service->handle('POST','/webhooks/top-gestor',$validHeaders,$event['body']);
ok($first['status']===200&&$second['status']===200,'duplicidade responde com sucesso seguro');
ok(($second['body']['code']??'')==='DUPLICATE_IGNORED','duplicidade identificada');
ok($api->calls===1,'duplicidade nao repete REST'); cleanup($dir);

// Foreign customer.
$dir=directory('foreign');$logs=[];$api=new FakeProbeApi(fixture());$service=probe($api,$dir,$secret,$logs);$foreign=signed($secret,999999,'foreign');
$result=$service->handle('POST','/webhooks/top-gestor',$jsonHeaders+['X-Hub-Signature-256'=>$foreign['signature']],$foreign['body']);
ok($result['status']===403,'cliente diferente rejeitado');ok($api->calls===0,'cliente diferente nao consulta API');cleanup($dir);

// Other event.
$dir=directory('event');$logs=[];$api=new FakeProbeApi(fixture());$service=probe($api,$dir,$secret,$logs);$other=signed($secret,143528,'other','renewal.panel_succeeded');
$result=$service->handle('POST','/webhooks/top-gestor',$jsonHeaders+['X-Hub-Signature-256'=>$other['signature']],$other['body']);
ok($result['status']===202,'evento diferente ignorado');ok($api->calls===0,'evento diferente nao consulta API');cleanup($dir);

// Main product mismatch.
$dir=directory('product');$logs=[];$api=new FakeProbeApi(fixture('9999','Sistema OUTRO'));$service=probe($api,$dir,$secret,$logs);$product=signed($secret,143528,'product');
$result=$service->handle('POST','/webhooks/top-gestor',$jsonHeaders+['X-Hub-Signature-256'=>$product['signature']],$product['body']);
ok($result['status']===502,'produto principal nao GP rejeitado');ok($api->calls===1,'produto validado pela REST');cleanup($dir);

fwrite(STDOUT,"TOP_GESTOR_WEBHOOK_PROBE_TEST_PASS\n");
