<?php

declare(strict_types=1);

require_once __DIR__ . '/samsung_token_auth.php';
require_once __DIR__ . '/roku_sistema_context.php';
require_once __DIR__ . '/roku_xtream_client.php';

/**
 * Consulta somente os canais live de uma categoria, reutilizando a validação
 * de URL/SSRF do cliente Xtream existente e seus mesmos limites operacionais.
 *
 * @return array<mixed>
 */
function samsungRequestLiveStreams(
    string $providerUrl,
    string $username,
    string $password,
    string $categoryId
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Extensão cURL indisponível');
    }

    $username = trim($username);
    if ($username === '' || $password === '') {
        throw new InvalidArgumentException('Credenciais Xtream inválidas');
    }

    $validatedUrl = validarUrlFornecedorRoku($providerUrl);
    $query = http_build_query([
        'username' => $username,
        'password' => $password,
        'action' => 'get_live_streams',
        'category_id' => $categoryId,
    ], '', '&', PHP_QUERY_RFC3986);
    $requestUrl = $validatedUrl['endpoint_url'] . '?' . $query;
    $responseLimit = 2 * 1024 * 1024;
    $responseBody = '';
    $receivedBytes = 0;
    $responseTooLarge = false;
    $curl = curl_init();

    if ($curl === false) {
        throw new RuntimeException('Não foi possível inicializar a extensão cURL');
    }

    $options = [
        CURLOPT_URL => $requestUrl,
        CURLOPT_HTTPGET => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_HEADER => false,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FAILONERROR => false,
        CURLOPT_PROXY => '',
        CURLOPT_NETRC => CURL_NETRC_IGNORED,
        CURLOPT_FRESH_CONNECT => true,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $chunk) use (
            &$responseBody,
            &$receivedBytes,
            &$responseTooLarge,
            $responseLimit
        ): int {
            $chunkLength = strlen($chunk);
            if ($receivedBytes + $chunkLength > $responseLimit) {
                $responseTooLarge = true;
                return 0;
            }
            $responseBody .= $chunk;
            $receivedBytes += $chunkLength;
            return $chunkLength;
        },
    ];

    if (defined('CURLOPT_PROTOCOLS_STR')) {
        $options[constant('CURLOPT_PROTOCOLS_STR')] = 'http,https';
    } else {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }

    $resolvedIp = $validatedUrl['ip_resolvido'];
    $isIpv6 = filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    $options[CURLOPT_IPRESOLVE] = $isIpv6 ? CURL_IPRESOLVE_V6 : CURL_IPRESOLVE_V4;
    if (!$validatedUrl['host_eh_ip_literal']) {
        $resolveIp = $isIpv6 ? '[' . $resolvedIp . ']' : $resolvedIp;
        $options[CURLOPT_RESOLVE] = [
            $validatedUrl['host'] . ':' . $validatedUrl['port'] . ':' . $resolveIp,
        ];
    }

    try {
        if (!curl_setopt_array($curl, $options)) {
            throw new RuntimeException('Não foi possível configurar a extensão cURL');
        }
        $executed = curl_exec($curl);
        $curlCode = curl_errno($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    } finally {
        curl_close($curl);
    }

    if ($responseTooLarge) {
        throw new RokuXtreamException(502, 'PROVIDER_RESPONSE_TOO_LARGE', 'Resposta do sistema muito grande', 'RESPONSE_TOO_LARGE');
    }
    if ($curlCode === CURLE_OPERATION_TIMEDOUT) {
        throw new RokuXtreamException(504, 'PROVIDER_TIMEOUT', 'O sistema demorou para responder', 'PROVIDER_TIMEOUT');
    }
    if ($executed === false || $curlCode !== CURLE_OK) {
        throw new RokuXtreamException(502, 'PROVIDER_UNAVAILABLE', 'Não foi possível acessar o sistema', classificarErroCurlXtreamRoku($curlCode));
    }
    $httpError = classificarStatusHttpFornecedorRoku($httpCode);
    if ($httpError !== null) {
        throw new RokuXtreamException(502, 'PROVIDER_UNAVAILABLE', 'Não foi possível acessar o sistema', $httpError);
    }
    if (trim($responseBody) === '') {
        throw new RokuXtreamException(502, 'INVALID_PROVIDER_RESPONSE', 'Resposta inválida do sistema', 'INVALID_RESPONSE');
    }

    try {
        $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RokuXtreamException(502, 'INVALID_PROVIDER_RESPONSE', 'Resposta inválida do sistema', 'INVALID_RESPONSE');
    }
    if (!is_array($decoded) || !array_is_list($decoded)) {
        throw new RokuXtreamException(502, 'INVALID_PROVIDER_RESPONSE', 'Resposta inválida do sistema', 'INVALID_RESPONSE');
    }
    return $decoded;
}

function samsungNormalizeStreamId(mixed $value): ?string
{
    if (is_int($value)) {
        return $value >= 0 ? (string) $value : null;
    }
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    return $value !== '' && strlen($value) <= 64 && ctype_digit($value) ? $value : null;
}

function samsungNormalizeStreamName(mixed $value): ?string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1) {
        return null;
    }
    $withoutTabs = str_replace("\t", '', $value);
    if (preg_match('/\p{Cc}/u', $withoutTabs) === 1) {
        return null;
    }
    $value = trim($value);
    return $value !== '' && strlen($value) <= 500 ? $value : null;
}

samsungRequireMethod('GET');

if (
    count($_GET) !== 2
    || !isset($_GET['sistema_id'], $_GET['category_id'])
    || !is_string($_GET['sistema_id'])
    || !is_string($_GET['category_id'])
    || preg_match('/^[1-9][0-9]{0,18}$/D', $_GET['sistema_id']) !== 1
    || preg_match('/^[0-9]{1,32}$/D', $_GET['category_id']) !== 1
) {
    samsungJsonError(400, 'INVALID_REQUEST', 'Requisição inválida');
}

$systemId = (int) $_GET['sistema_id'];
$categoryId = $_GET['category_id'];
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

    $rawStreams = samsungRequestLiveStreams(
        (string) $context['fornecedor_url'],
        (string) $context['usuario'],
        (string) $context['senha'],
        $categoryId
    );

    $streams = [];
    $seen = [];
    foreach ($rawStreams as $item) {
        if (!is_array($item)) {
            continue;
        }
        $streamId = samsungNormalizeStreamId($item['stream_id'] ?? null);
        $name = samsungNormalizeStreamName($item['name'] ?? null);
        if ($streamId === null || $name === null || isset($seen[$streamId])) {
            continue;
        }
        $seen[$streamId] = true;
        $streams[] = ['stream_id' => $streamId, 'nome' => $name];
        if (count($streams) > 10000) {
            throw new RokuXtreamException(502, 'INVALID_PROVIDER_RESPONSE', 'Resposta inválida do sistema', 'INVALID_RESPONSE');
        }
    }

    if ($rawStreams !== [] && $streams === []) {
        throw new RokuXtreamException(502, 'INVALID_PROVIDER_RESPONSE', 'Resposta inválida do sistema', 'INVALID_RESPONSE');
    }

    samsungJson(200, [
        'success' => true,
        'data' => ['canais' => $streams],
    ]);
} catch (SamsungAuthException $error) {
    samsungRespondAuthError($error);
} catch (RokuSistemaException $error) {
    samsungJsonError($error->getStatusHttp(), $error->getCodigoPublico(), $error->getMensagemPublica());
} catch (RokuXtreamException $error) {
    samsungJsonError($error->getStatusHttp(), $error->getCodigoPublico(), $error->getMensagemPublica());
} catch (Throwable) {
    samsungJsonError(500, 'INTERNAL_ERROR', 'Não foi possível carregar os canais');
}
