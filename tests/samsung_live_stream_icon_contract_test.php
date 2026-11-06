<?php

declare(strict_types=1);

$failures = 0;
$assertions = 0;

function streamIconAssert(bool $condition, string $message): void
{
    global $failures, $assertions;
    $assertions++;
    if ($condition) {
        echo "PASS {$message}\n";
        return;
    }
    $failures++;
    echo "FAIL {$message}\n";
}

$endpointPath = dirname(__DIR__) . '/samsung_live_streams.php';
$endpoint = file_get_contents($endpointPath);
streamIconAssert(is_string($endpoint), 'endpoint Samsung pode ser lido');

$helperStart = is_string($endpoint) ? strpos($endpoint, 'function samsungNormalizeStreamId') : false;
$helperEnd = is_string($endpoint) ? strpos($endpoint, "samsungRequireMethod('GET');", $helperStart === false ? 0 : $helperStart) : false;
streamIconAssert($helperStart !== false && $helperEnd !== false, 'helpers de normalizacao estao isolados antes do endpoint');

if ($helperStart !== false && $helperEnd !== false) {
    eval(substr($endpoint, $helperStart, $helperEnd - $helperStart));
}

streamIconAssert(samsungNormalizeStreamIcon('https://cdn.example.com/logo.png') === 'https://cdn.example.com/logo.png', 'aceita logo HTTPS absoluta');
streamIconAssert(samsungNormalizeStreamIcon('http://images.example.com:8080/logo.png') === 'http://images.example.com:8080/logo.png', 'aceita logo HTTP absoluta com porta');
streamIconAssert(samsungNormalizeStreamIcon(null) === null, 'campo ausente permanece opcional');
streamIconAssert(samsungNormalizeStreamIcon('') === null && samsungNormalizeStreamIcon('   ') === null, 'campo vazio e ignorado');
streamIconAssert(samsungNormalizeStreamIcon('/logos/channel.png') === null, 'URL relativa e rejeitada');
streamIconAssert(samsungNormalizeStreamIcon('logo.png') === null, 'valor sem URL e rejeitado');
streamIconAssert(samsungNormalizeStreamIcon('javascript:alert(1)') === null, 'javascript e rejeitado');
streamIconAssert(samsungNormalizeStreamIcon('data:image/png;base64,AAAA') === null, 'data URL e rejeitada');
streamIconAssert(samsungNormalizeStreamIcon('ftp://cdn.example.com/logo.png') === null, 'FTP e rejeitado');
streamIconAssert(samsungNormalizeStreamIcon('https://user:secret@cdn.example.com/logo.png') === null, 'userinfo com credencial e rejeitado');
streamIconAssert(samsungNormalizeStreamIcon("https://cdn.example.com/logo.png\nX-Test: value") === null, 'caracter de controle e rejeitado');
streamIconAssert(samsungNormalizeStreamIcon('https://cdn.example.com/' . str_repeat('a', 2030)) === null, 'URL excessivamente longa e rejeitada');
streamIconAssert(samsungNormalizeStreamIcon('https://cdn.example.com/logo.png?signature=provider-value') === 'https://cdn.example.com/logo.png?signature=provider-value', 'query original e preservada sem alteracao');

streamIconAssert(
    strpos($endpoint, "\$stream = ['stream_id' => \$streamId, 'nome' => \$name];") !== false
    && strpos($endpoint, "\$stream['stream_icon'] = \$streamIcon;") !== false,
    'campos existentes sao preservados e stream_icon e opcional'
);
streamIconAssert(strpos($endpoint, 'error_log') === false, 'endpoint nao registra URL ou dados sensiveis');
$responseStart = strpos($endpoint, '$streams = [];');
$responseEnd = strpos($endpoint, 'samsungJson(200,', $responseStart === false ? 0 : $responseStart);
$responseSource = $responseStart !== false && $responseEnd !== false
    ? substr($endpoint, $responseStart, $responseEnd - $responseStart)
    : '';
streamIconAssert(
    $responseSource !== ''
    && strpos($responseSource, '$username') === false
    && strpos($responseSource, '$password') === false
    && strpos($responseSource, "['usuario']") === false
    && strpos($responseSource, "['senha']") === false,
    'resposta nao acrescenta credenciais Xtream'
);
streamIconAssert(
    strpos($endpoint, "if (\$streamIcon !== null)") !== false
    && strpos($endpoint, "'data' => ['canais' => \$streams]") !== false,
    'stream_icon opcional preserva o contrato data.canais do Samsung'
);

echo "RESULT assertions={$assertions} failures={$failures}\n";
exit($failures === 0 ? 0 : 1);
