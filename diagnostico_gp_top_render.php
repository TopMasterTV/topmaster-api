<?php

declare(strict_types=1);

const GP_DIAGNOSTICO_AUTORIZACAO = 'AUTORIZADO_GP_TOP_UNICO';
const GP_DIAGNOSTICO_USER_AGENT = 'Dart/3.10.7 (dart:io)';

function gpFalhar(string $mensagem, int $codigo = 1): never
{
    fwrite(STDERR, $mensagem . PHP_EOL);
    exit($codigo);
}

function gpTextoObrigatorio(mixed $valor, string $nome): string
{
    $texto = trim((string) ($valor ?? ''));
    if ($texto === '') {
        throw new InvalidArgumentException($nome . ' ausente');
    }
    return $texto;
}

function gpConstruirUrl(string $url, string $usuario, string $senha): string
{
    $url = gpTextoObrigatorio($url, 'URL');
    $usuario = gpTextoObrigatorio($usuario, 'usuario');
    $senha = gpTextoObrigatorio($senha, 'senha');

    if (!str_contains($url, '://')
        && str_contains($url, '.')
        && !str_contains($url, ' ')
        && !str_starts_with($url, '/')) {
        $url = 'http://' . $url;
    }

    $partes = parse_url($url);
    if (!is_array($partes) || empty($partes['scheme']) || empty($partes['host'])) {
        throw new InvalidArgumentException('URL individual invalida');
    }

    $esquema = strtolower((string) $partes['scheme']);
    if (!in_array($esquema, ['http', 'https'], true)) {
        throw new InvalidArgumentException('Esquema de URL nao permitido');
    }

    $porta = isset($partes['port']) ? ':' . (int) $partes['port'] : '';
    $query = http_build_query(
        ['username' => $usuario, 'password' => $senha],
        '',
        '&',
        PHP_QUERY_RFC1738
    );

    return $esquema . '://' . $partes['host'] . $porta
        . '/player_api.php?' . $query;
}

function gpAnalisarResposta(
    int $codigoHttp,
    string $contentType,
    string $corpo,
    int $redirecionamentos,
    string $urlEfetiva,
    array $cabecalhos,
    float $tempoSegundos
): array {
    $json = json_decode($corpo, true);
    $jsonValido = is_array($json) && json_last_error() === JSON_ERROR_NONE;
    $expDatePresente = $jsonValido
        && isset($json['user_info'])
        && is_array($json['user_info'])
        && array_key_exists('exp_date', $json['user_info']);

    $hostFinal = parse_url($urlEfetiva, PHP_URL_HOST);
    $portaFinal = parse_url($urlEfetiva, PHP_URL_PORT);
    $indicadoresPermitidos = [
        'server', 'via', 'cf-ray', 'cf-cache-status', 'x-cache',
        'x-served-by', 'x-request-id', 'x-render-origin-server',
    ];
    $indicadores = [];
    foreach ($indicadoresPermitidos as $nome) {
        $indicadores[$nome] = array_key_exists($nome, $cabecalhos);
    }

    return [
        'http_status' => $codigoHttp,
        'content_type' => $contentType === '' ? null : $contentType,
        'body_size_bytes' => strlen($corpo),
        'body_sha256' => hash('sha256', $corpo),
        'redirect_count' => $redirecionamentos,
        'final_host' => is_string($hostFinal)
            ? $hostFinal . ($portaFinal !== null ? ':' . (int) $portaFinal : '')
            : null,
        'json_valid' => $jsonValido,
        'user_info_exp_date_present' => $expDatePresente,
        'safe_proxy_cdn_indicators' => $indicadores,
        'response_time_ms' => (int) round($tempoSegundos * 1000),
    ];
}

function gpNomeVersaoHttp(int $versao): string
{
    foreach ([
        'CURL_HTTP_VERSION_1_0',
        'CURL_HTTP_VERSION_1_1',
        'CURL_HTTP_VERSION_2_0',
        'CURL_HTTP_VERSION_2TLS',
        'CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE',
        'CURL_HTTP_VERSION_3',
    ] as $constante) {
        if (defined($constante) && constant($constante) === $versao) {
            return substr($constante, strlen('CURL_HTTP_VERSION_'));
        }
    }
    return 'DESCONHECIDA_' . $versao;
}

function gpFamiliaIp(string $ip): ?string
{
    if ($ip === '') return null;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) return 'IPv4';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) return 'IPv6';
    return 'desconhecida';
}

function gpProxyAmbientePresente(): bool
{
    foreach ([
        'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'NO_PROXY',
        'http_proxy', 'https_proxy', 'all_proxy', 'no_proxy',
    ] as $nome) {
        $valor = getenv($nome);
        if (is_string($valor) && trim($valor) !== '') return true;
    }
    return false;
}

function gpMetadadosConexao(CurlHandle $ch): array
{
    $ipRemoto = (string) (curl_getinfo($ch, CURLINFO_PRIMARY_IP) ?: '');
    $versaoCurl = curl_version();
    $versaoHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_VERSION);
    $proxyUsado = defined('CURLINFO_USED_PROXY')
        ? (bool) curl_getinfo($ch, constant('CURLINFO_USED_PROXY'))
        : null;

    return [
        'remote_ip_family' => gpFamiliaIp($ipRemoto),
        'remote_ip_sha256' => $ipRemoto === '' ? null : hash('sha256', $ipRemoto),
        'remote_port' => (int) curl_getinfo($ch, CURLINFO_PRIMARY_PORT),
        'http_version' => gpNomeVersaoHttp($versaoHttp),
        'curl_version' => (string) ($versaoCurl['version'] ?? 'desconhecida'),
        'tls_backend_version' => (string) ($versaoCurl['ssl_version'] ?? 'desconhecida'),
        'dns_time_ms' => (int) round((float) curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME) * 1000),
        'connect_time_ms' => (int) round((float) curl_getinfo($ch, CURLINFO_CONNECT_TIME) * 1000),
        'first_byte_time_ms' => (int) round((float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) * 1000),
        'tls_verify_result' => (int) curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT),
        'proxy_environment_present' => gpProxyAmbientePresente(),
        'proxy_bypass_forced' => true,
        'proxy_used' => $proxyUsado,
    ];
}

function gpExecutarConsulta(string $api): array
{
    $cabecalhos = [];
    $ch = curl_init($api);
    if ($ch === false) {
        throw new RuntimeException('Nao foi possivel iniciar o transporte HTTP');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPGET => true,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_NOPROXY => '*',
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_USERAGENT => GP_DIAGNOSTICO_USER_AGENT,
        CURLOPT_ENCODING => 'gzip',
        CURLOPT_HEADERFUNCTION => static function ($handle, string $linha) use (&$cabecalhos): int {
            $tamanho = strlen($linha);
            $linhaLimpa = trim($linha);
            if (str_starts_with($linhaLimpa, 'HTTP/')) {
                $cabecalhos = [];
                return $tamanho;
            }
            $posicao = strpos($linhaLimpa, ':');
            if ($posicao !== false) {
                $nome = strtolower(trim(substr($linhaLimpa, 0, $posicao)));
                $cabecalhos[$nome] = true;
            }
            return $tamanho;
        },
    ]);

    $corpo = curl_exec($ch);
    $erro = curl_errno($ch);
    $codigoHttp = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string) (curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '');
    $redirecionamentos = (int) curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
    $urlEfetiva = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $tempo = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $metadadosConexao = gpMetadadosConexao($ch);
    curl_close($ch);

    if ($erro !== 0 || !is_string($corpo)) {
        return [
            'transport_ok' => false,
            'transport_error_code' => $erro,
            'http_status' => $codigoHttp,
            'response_time_ms' => (int) round($tempo * 1000),
        ] + $metadadosConexao;
    }

    return ['transport_ok' => true] + gpAnalisarResposta(
        $codigoHttp,
        $contentType,
        $corpo,
        $redirecionamentos,
        $urlEfetiva,
        $cabecalhos,
        $tempo
    ) + $metadadosConexao;
}

function gpSelfTest(): void
{
    $url = gpConstruirUrl(
        'painel.exemplo.invalid:8443/caminho/get.php?ignorar=1',
        'usuario teste',
        'senha&teste'
    );
    $esperada = 'http://painel.exemplo.invalid:8443/player_api.php'
        . '?username=usuario+teste&password=senha%26teste';
    if ($url !== $esperada) {
        throw new RuntimeException('Falha na construcao simulada da URL');
    }

    $resultado = gpAnalisarResposta(
        503,
        'application/json; charset=utf-8',
        '{"user_info":{"exp_date":"valor-ficticio"}}',
        1,
        'https://destino.exemplo.invalid/player_api.php?username=oculto&password=oculta',
        ['server' => true, 'cf-ray' => true, 'authorization' => true],
        0.321
    );

    if ($resultado['http_status'] !== 503
        || $resultado['final_host'] !== 'destino.exemplo.invalid'
        || $resultado['json_valid'] !== true
        || $resultado['user_info_exp_date_present'] !== true
        || array_key_exists('authorization', $resultado['safe_proxy_cdn_indicators'])
        || array_key_exists('body', $resultado)
        || gpFamiliaIp('192.0.2.10') !== 'IPv4'
        || strlen(hash('sha256', '192.0.2.10')) !== 64
        || gpNomeVersaoHttp(CURL_HTTP_VERSION_1_1) !== '1_1') {
        throw new RuntimeException('Falha na analise simulada e segura');
    }

    fwrite(STDOUT, "OK: diagnostico GP TOP validado sem rede e sem banco\n");
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$modo = $argv[1] ?? '';
if ($modo === '--self-test') {
    gpSelfTest();
    exit;
}

if ($modo !== '--execute-once') {
    fwrite(STDOUT, "Nenhuma consulta executada. Use --self-test ou aguarde autorizacao para --execute-once.\n");
    exit;
}

if (getenv('GP_DIAGNOSTIC_AUTHORIZATION') !== GP_DIAGNOSTICO_AUTORIZACAO) {
    gpFalhar('Execucao bloqueada: autorizacao explicita ausente.');
}
if (trim((string) getenv('GP_DIAGNOSTIC_SYSTEM')) !== 'GP TOP') {
    gpFalhar('Execucao bloqueada: sistema autorizado deve ser GP TOP.');
}

try {
    $api = gpConstruirUrl(
        (string) getenv('GP_DIAGNOSTIC_URL'),
        (string) getenv('GP_DIAGNOSTIC_USERNAME'),
        (string) getenv('GP_DIAGNOSTIC_PASSWORD')
    );
    $resultado = gpExecutarConsulta($api);
    fwrite(STDOUT, json_encode($resultado, JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $erro) {
    gpFalhar('Diagnostico falhou de forma segura: ' . $erro->getMessage());
}
