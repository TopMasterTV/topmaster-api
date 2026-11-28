<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Probe.php';

final class FakeProbeApi implements TopGestorProbeApiClient
{
    public int $calls = 0;
    /** @param array<string, mixed>|Throwable $response */
    public function __construct(private readonly array|Throwable $response) {}
    public function fetchCustomer(string $customerId): array
    {
        $this->calls++;
        if ($this->response instanceof Throwable) throw $this->response;
        return $this->response;
    }
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

function probe(
    FakeProbeApi $api,
    string $directory,
    string $secret,
    array &$logs,
    ?TopGestorRenewalCapture $capture = null,
): TopGestorWebhookProbe
{
    return new TopGestorWebhookProbe(
        $api, new TopGestorProbeDeliveryGuard($directory, $secret), $secret, '4409', 'Sistema GP', '2027-01-25',
        static function (array $metadata) use (&$logs): void { $logs[] = $metadata; },
        $capture,
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
ok(TopGestorRenewalCapture::isEnabled(false)===false,'captura de renovacao desativada por padrao');
ok(TopGestorRenewalCapture::isEnabled('true')===false,'captura exige valor exato');
ok(TopGestorRenewalCapture::isEnabled('1')===true,'captura ativada somente por 1');

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

// Sanitized customer.renewed capture.
$correlationKey='TEST_ONLY_DISTINCT_CORRELATION_KEY_32_BYTES_MINIMUM';
$capture=new TopGestorRenewalCapture($correlationKey);
$sameKeyRejected=false;
$sameKeyDirectory=directory('same-key');
try {
    $sameKeyLogs=[];
    probe(new FakeProbeApi(fixture()),$sameKeyDirectory,$secret,$sameKeyLogs,new TopGestorRenewalCapture($secret));
} catch (InvalidArgumentException $exception) {
    $sameKeyRejected=$exception->getMessage()==='CAPTURE_CORRELATION_KEY_MUST_BE_DISTINCT';
}
ok($sameKeyRejected,'chave de correlacao igual ao segredo HMAC rejeitada');
cleanup($sameKeyDirectory);
$captureBody=json_encode([
    'event'=>'customer.renewed','delivery_id'=>'delivery_capture_opaque_001','customer'=>[
        'id'=>143528,'name'=>'PERSONAL_CAPTURE_FIXTURE','email'=>'private@example.invalid',
    ],
    'renewal'=>[
        'id'=>'renewal_capture_opaque_001','created_at'=>'2026-11-27T12:00:00Z',
        'new_expires_at'=>'2027-02-25','username'=>'PRIVATE_USER','password'=>'PRIVATE_PASSWORD',
    ],
    'timestamp'=>1764244800,'token'=>'PRIVATE_TOKEN','accesses'=>[['username'=>'PRIVATE_ACCESS_USER']],
],JSON_UNESCAPED_SLASHES);
if(!is_string($captureBody))throw new RuntimeException('CAPTURE_FIXTURE_INVALID');
$captureSignature='sha256='.hash_hmac('sha256',$captureBody,$secret);
$captureHeaders=$jsonHeaders+['X-Hub-Signature-256'=>$captureSignature];
$dir=directory('capture-valid');$logs=[];$api=new FakeProbeApi(fixture());$service=probe($api,$dir,$secret,$logs,$capture);
$result=$service->handle('POST','/webhooks/top-gestor',$captureHeaders,$captureBody);
ok($result['status']===200&&($result['body']['code']??'')==='RENEWAL_CAPTURE_CONFIRMED','captura valida confirmada');
ok(($result['body']['accepted']??true)===false&&($result['body']['processed']??true)===false,'captura nao aceita nem processa renovacao');
ok(($result['body']['authenticated']??false)===true,'captura confirma HMAC valido');
ok($api->calls===1,'captura consulta REST uma vez');
$captureLog=$logs[0]??[];$encodedCapture=json_encode($logs);
ok(($captureLog['expiration_vs_baseline']??'')==='after','vencimento comparado somente como after');
ok((($captureLog['rest_updated_at']??[])['format']??'')==='iso8601','updated_at registrado somente por formato');
ok((($captureLog['capture']['temporal_fields']['timestamp']??[])['format']??'')==='unix_seconds','timestamp classificado sem valor');
ok((($captureLog['capture']['identifiers']['renewal.id']??[])['format']??'')==='prefixed_opaque','identificador de renovacao classificado');
$mainIndicators=$captureLog['main_access_indicators']??[];
ok(($mainIndicators['usuario']['present']??false)===true&&($mainIndicators['usuario']['filled']??false)===true,'usuario principal indicado somente por booleanos');
ok(($mainIndicators['password']['present']??false)===true&&($mainIndicators['password']['filled']??false)===true,'password principal indicado somente por booleanos');
ok(($mainIndicators['senha']['present']??true)===false&&($mainIndicators['senha']['filled']??true)===false,'senha principal ausente indicada somente por booleanos');
ok(($mainIndicators['data_de_vencimento']['present']??false)===true&&($mainIndicators['data_de_vencimento']['valid_format']??false)===true,'vencimento principal indicado como valido');
ok(($mainIndicators['campos_no_mesmo_objeto_principal']??false)===true,'campos confirmados no mesmo objeto principal');
$deliveryCorrelation=$captureLog['capture']['identifiers']['delivery_id']['correlation_hmac']??'';
ok(is_string($deliveryCorrelation)&&strlen($deliveryCorrelation)===64,'identificador correlacionado por HMAC');
ok($deliveryCorrelation!==hash('sha256','delivery_capture_opaque_001'),'correlacao nao usa hash simples');
ok($deliveryCorrelation!==hash_hmac('sha256','delivery_capture_opaque_001',$secret),'correlacao nao reutiliza segredo HMAC do webhook');
foreach(['PERSONAL_CAPTURE_FIXTURE','private@example.invalid','PRIVATE_USER','PRIVATE_PASSWORD','PRIVATE_TOKEN','PRIVATE_ACCESS_USER','USER_FIXTURE','PASSWORD_FIXTURE','2027-02-25',$captureBody,$captureSignature,'delivery_capture_opaque_001','renewal_capture_opaque_001','2026-11-27T12:00:00Z'] as $forbidden){
    ok(is_string($encodedCapture)&&!str_contains($encodedCapture,$forbidden),'dado sensivel ausente do log de captura');
}
ok(($captureLog['database_accessed']??true)===false,'captura nao acessa PostgreSQL');cleanup($dir);

// Optional structural fields absent.
$dir=directory('capture-minimal');$logs=[];$api=new FakeProbeApi(fixture());$service=probe($api,$dir,$secret,$logs,$capture);
$result=$service->handle('POST','/webhooks/top-gestor',$validHeaders,$event['body']);$minimalLog=$logs[0]??[];
ok($result['status']===200,'captura aceita campos opcionais ausentes');
ok((($minimalLog['capture']['identifiers']['renewal.id']??[])['present']??true)===false,'identificador opcional ausente registrado');
ok((($minimalLog['capture']['temporal_fields']['timestamp']??[])['present']??true)===false,'timestamp opcional ausente registrado');cleanup($dir);

// Main access indicators cover absent, empty, unexpected and alternate password fields without values.
$indicatorCases=[];
$absentFixture=fixture();
unset($absentFixture['data']['usuario'],$absentFixture['data']['password'],$absentFixture['data']['data_de_vencimento']);
$indicatorCases[]=['absent',$absentFixture,false,false,false,false,false,false,false,false,502];
$emptyFixture=fixture();
$emptyFixture['data']['usuario']='  ';$emptyFixture['data']['password']='';$emptyFixture['data']['senha']=' ';
$indicatorCases[]=['empty',$emptyFixture,true,false,true,false,true,false,true,false,200];
$unexpectedCaptureFixture=fixture();
$unexpectedCaptureFixture['data']['usuario']=['unexpected'];$unexpectedCaptureFixture['data']['password']=123;$unexpectedCaptureFixture['data']['senha']=true;
$indicatorCases[]=['unexpected',$unexpectedCaptureFixture,true,false,true,false,true,false,true,false,200];
$senhaCaptureFixture=fixture();
unset($senhaCaptureFixture['data']['password']);$senhaCaptureFixture['data']['senha']='SENHA_CAPTURE_FIXTURE';
$indicatorCases[]=['senha',$senhaCaptureFixture,true,true,false,false,true,true,true,true,200];
$invalidDateFixture=fixture();$invalidDateFixture['data']['data_de_vencimento']='2027-02-30';
$indicatorCases[]=['invalid-date',$invalidDateFixture,true,true,true,true,false,false,false,false,502];
foreach($indicatorCases as [$caseName,$caseFixture,$usuarioPresent,$usuarioFilled,$passwordPresent,$passwordFilled,$senhaPresent,$senhaFilled,$dateValid,$sameObject,$expectedStatus]){
    $caseEvent=signed($secret,143528,'indicator-'.$caseName);
    $caseHeaders=$jsonHeaders+['X-Hub-Signature-256'=>$caseEvent['signature']];
    $dir=directory('indicator-'.$caseName);$logs=[];$api=new FakeProbeApi($caseFixture);$service=probe($api,$dir,$secret,$logs,$capture);
    $result=$service->handle('POST','/webhooks/top-gestor',$caseHeaders,$caseEvent['body']);$indicatorLog=$logs[0]??[];
    $indicators=$indicatorLog['main_access_indicators']??[];
    ok($result['status']===$expectedStatus&&$api->calls===1,'caso '.$caseName.' reutiliza uma unica REST');
    ok(($indicators['usuario']['present']??null)===$usuarioPresent&&($indicators['usuario']['filled']??null)===$usuarioFilled,'caso '.$caseName.' descreve usuario');
    ok(($indicators['password']['present']??null)===$passwordPresent&&($indicators['password']['filled']??null)===$passwordFilled,'caso '.$caseName.' descreve password');
    ok(($indicators['senha']['present']??null)===$senhaPresent&&($indicators['senha']['filled']??null)===$senhaFilled,'caso '.$caseName.' descreve senha');
    ok(($indicators['data_de_vencimento']['valid_format']??null)===$dateValid,'caso '.$caseName.' valida formato do vencimento');
    ok(($indicators['campos_no_mesmo_objeto_principal']??null)===$sameObject,'caso '.$caseName.' descreve mesmo objeto principal');
    $caseLog=json_encode($logs);
    foreach(['USER_FIXTURE','PASSWORD_FIXTURE','SENHA_CAPTURE_FIXTURE','2027-02-25','2027-02-30'] as $privateValue){
        ok(is_string($caseLog)&&!str_contains($caseLog,$privateValue),'caso '.$caseName.' nao registra valores privados');
    }
    cleanup($dir);
}

// Capture mode still rejects invalid authentication and unauthorized input before REST.
$dir=directory('capture-signature');$logs=[];$api=new FakeProbeApi(fixture());$service=probe($api,$dir,$secret,$logs,$capture);
$result=$service->handle('POST','/webhooks/top-gestor',$jsonHeaders+['X-Hub-Signature-256'=>'sha256='.str_repeat('0',64)],$captureBody);
ok($result['status']===401&&$api->calls===0,'captura rejeita HMAC invalido sem REST');
ok(!array_key_exists('main_access_indicators',$logs[0]??[]),'HMAC invalido nao gera indicadores REST');cleanup($dir);

$foreignCapture=signed($secret,999999,'foreign-capture');
$dir=directory('capture-foreign');$logs=[];$api=new FakeProbeApi(fixture());$service=probe($api,$dir,$secret,$logs,$capture);
$result=$service->handle('POST','/webhooks/top-gestor',$jsonHeaders+['X-Hub-Signature-256'=>$foreignCapture['signature']],$foreignCapture['body']);
ok($result['status']===403&&$api->calls===0,'captura rejeita cliente diferente sem REST');
ok(!array_key_exists('main_access_indicators',$logs[0]??[]),'cliente diferente nao gera indicadores REST');
ok(!array_key_exists('capture',$logs[0]??[]),'cliente diferente nao gera estrutura de captura');cleanup($dir);

$otherCapture=signed($secret,143528,'test-delivery','webhook.test');
$dir=directory('capture-other');$logs=[];$api=new FakeProbeApi(fixture());$service=probe($api,$dir,$secret,$logs,$capture);
$result=$service->handle('POST','/webhooks/top-gestor',$jsonHeaders+['X-Hub-Signature-256'=>$otherCapture['signature']],$otherCapture['body']);
ok($result['status']===202&&($result['body']['code']??'')==='EVENT_IGNORED','webhook.test preserva HTTP 202');
ok($api->calls===0&&!array_key_exists('capture',$logs[0]??[]),'evento diferente nao captura nem consulta REST');cleanup($dir);

// REST failure remains safe and retryable.
$dir=directory('capture-rest-failure');$logs=[];$api=new FakeProbeApi(new RuntimeException('TOPGESTOR_API_TRANSPORT_FAILED'));$service=probe($api,$dir,$secret,$logs,$capture);
$result=$service->handle('POST','/webhooks/top-gestor',$captureHeaders,$captureBody);
ok($result['status']===502&&($result['body']['code']??'')==='RENEWAL_CAPTURE_REST_FAILED','falha REST retorna erro seguro');
ok($api->calls===1&&($result['body']['accepted']??true)===false,'falha REST nao aceita renovacao');
$failedLog=json_encode($logs);ok(is_string($failedLog)&&!str_contains($failedLog,$captureBody)&&!str_contains($failedLog,$captureSignature),'falha REST nao registra corpo ou assinatura');cleanup($dir);

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
