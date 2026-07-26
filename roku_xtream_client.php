<?php

declare(strict_types=1);

final class RokuXtreamException extends RuntimeException
{
    private const CATEGORIAS_PERMITIDAS = [
        'PROVIDER_UNAVAILABLE',
        'PROVIDER_TIMEOUT',
        'INVALID_RESPONSE',
        'RESPONSE_TOO_LARGE',
        'URL_REJECTED',
        'URL_SCHEME_REJECTED',
        'URL_COMPONENT_REJECTED',
        'DNS_NO_RESULTS',
        'DNS_NON_PUBLIC_IP',
        'SSRF_BLOCKED',
        'CURL_DNS_ERROR',
        'CURL_CONNECTION_ERROR',
        'CURL_TLS_ERROR',
        'PROVIDER_HTTP_NON_2XX',
        'CURL_OTHER_ERROR',
    ];

    private int $statusHttp;
    private string $codigoPublico;
    private string $mensagemPublica;
    private string $categoriaInterna;

    public function __construct(
        int $statusHttp,
        string $codigoPublico,
        string $mensagemPublica,
        string $categoriaInterna
    ) {
        if (!in_array($categoriaInterna, self::CATEGORIAS_PERMITIDAS, true)) {
            throw new InvalidArgumentException('Categoria interna inválida');
        }

        parent::__construct($mensagemPublica);
        $this->statusHttp = $statusHttp;
        $this->codigoPublico = $codigoPublico;
        $this->mensagemPublica = $mensagemPublica;
        $this->categoriaInterna = $categoriaInterna;
    }

    public function getStatusHttp(): int
    {
        return $this->statusHttp;
    }

    public function getCodigoPublico(): string
    {
        return $this->codigoPublico;
    }

    public function getMensagemPublica(): string
    {
        return $this->mensagemPublica;
    }

    public function getCategoriaInterna(): string
    {
        return $this->categoriaInterna;
    }
}

/**
 * Compara endereços IPv4 ou IPv6 sem expor os valores tratados.
 */
function ipPertenceCidrRoku(string $ip, string $cidr): bool
{
    $partes = explode('/', $cidr, 2);

    if (count($partes) !== 2 || !ctype_digit($partes[1])) {
        return false;
    }

    $ipBinario = @inet_pton($ip);
    $redeBinaria = @inet_pton($partes[0]);

    if ($ipBinario === false || $redeBinaria === false) {
        return false;
    }

    if (strlen($ipBinario) !== strlen($redeBinaria)) {
        return false;
    }

    $prefixo = (int) $partes[1];
    $totalBits = strlen($ipBinario) * 8;

    if ($prefixo < 0 || $prefixo > $totalBits) {
        return false;
    }

    $bytesCompletos = intdiv($prefixo, 8);
    $bitsRestantes = $prefixo % 8;

    if (
        $bytesCompletos > 0
        && substr($ipBinario, 0, $bytesCompletos) !== substr($redeBinaria, 0, $bytesCompletos)
    ) {
        return false;
    }

    if ($bitsRestantes === 0) {
        return true;
    }

    $mascara = (0xFF << (8 - $bitsRestantes)) & 0xFF;

    return (ord($ipBinario[$bytesCompletos]) & $mascara)
        === (ord($redeBinaria[$bytesCompletos]) & $mascara);
}

/**
 * Identifica formatos IPv4 históricos que podem ter interpretação ambígua.
 */
function hostIpv4NumericoAmbiguoRoku(string $host): bool
{
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return false;
    }

    if (preg_match('/^[0-9.]+$/', $host) === 1) {
        return true;
    }

    $campos = explode('.', $host);

    if (count($campos) < 1 || count($campos) > 4) {
        return false;
    }

    foreach ($campos as $campo) {
        if (
            $campo === ''
            || preg_match('/^(?:[0-9]+|0[xX][0-9a-fA-F]+)$/', $campo) !== 1
        ) {
            return false;
        }
    }

    return true;
}

/**
 * Valida se um endereço pode ser usado em uma conexão externa do backend.
 */
function enderecoIpPublicoPermitidoRoku(string $ip): bool
{
    if (!defined('FILTER_FLAG_GLOBAL_RANGE')) {
        throw new RuntimeException('Filtro de endereços globais indisponível');
    }

    $flagsValidacao = FILTER_FLAG_NO_PRIV_RANGE
        | FILTER_FLAG_NO_RES_RANGE
        | constant('FILTER_FLAG_GLOBAL_RANGE');

    if (
        filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            $flagsValidacao
        ) === false
    ) {
        return false;
    }

    $faixasBloqueadas = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.31.196.0/24',
        '192.52.193.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '192.175.48.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        '::/96',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '100:0:0:1::/64',
        '2001::/23',
        '2001::/32',
        '2001:2::/48',
        '2001:db8::/32',
        '2001:10::/28',
        '2001:20::/28',
        '2002::/16',
        '2620:4f:8000::/48',
        '3fff::/20',
        '5f00::/16',
        'fc00::/7',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    foreach ($faixasBloqueadas as $faixa) {
        if (ipPertenceCidrRoku($ip, $faixa)) {
            return false;
        }
    }

    return true;
}

/**
 * Resolve um hostname interno e retorna somente endereços públicos validados.
 *
 * @return list<string>
 */
function resolverHostPublicoRoku(string $host, ?callable $resolverDns = null): array
{
    $registros = $resolverDns !== null
        ? $resolverDns($host)
        : @dns_get_record($host, DNS_A | DNS_AAAA);

    if (!is_array($registros)) {
        throw new RokuXtreamException(
            502,
            'PROVIDER_UNAVAILABLE',
            'Não foi possível acessar o sistema',
            'DNS_NO_RESULTS'
        );
    }

    $enderecos = [];

    foreach ($registros as $registro) {
        if (isset($registro['ip']) && is_string($registro['ip'])) {
            $enderecos[] = $registro['ip'];
        }

        if (isset($registro['ipv6']) && is_string($registro['ipv6'])) {
            $enderecos[] = $registro['ipv6'];
        }
    }

    $enderecos = array_values(array_unique($enderecos));

    if ($enderecos === []) {
        throw new RokuXtreamException(
            502,
            'PROVIDER_UNAVAILABLE',
            'Não foi possível acessar o sistema',
            'DNS_NO_RESULTS'
        );
    }

    foreach ($enderecos as $endereco) {
        if (!enderecoIpPublicoPermitidoRoku($endereco)) {
            throw new RokuXtreamException(
                502,
                'PROVIDER_UNAVAILABLE',
                'Não foi possível acessar o sistema',
                'DNS_NON_PUBLIC_IP'
            );
        }
    }

    usort(
        $enderecos,
        static function (string $primeiro, string $segundo): int {
            $primeiroEhIpv4 = filter_var($primeiro, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $segundoEhIpv4 = filter_var($segundo, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;

            if ($primeiroEhIpv4 !== $segundoEhIpv4) {
                return $primeiroEhIpv4 ? -1 : 1;
            }

            return strcmp((string) inet_pton($primeiro), (string) inet_pton($segundo));
        }
    );

    return $enderecos;
}

/**
 * Valida e normaliza uma URL sensível de fornecedor para uso exclusivo interno.
 *
 * @return array{
 *     scheme: 'http'|'https',
 *     host: string,
 *     port: int,
 *     endpoint_url: string,
 *     ip_resolvido: string,
 *     host_eh_ip_literal: bool
 * }
 */
function validarUrlFornecedorRoku(string $url): array
{
    $url = trim($url);

    if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
        throw new RokuXtreamException(
            502,
            'PROVIDER_UNAVAILABLE',
            'Não foi possível acessar o sistema',
            'URL_REJECTED'
        );
    }

    $partes = parse_url($url);

    if (!is_array($partes)) {
        throw new RokuXtreamException(
            502,
            'PROVIDER_UNAVAILABLE',
            'Não foi possível acessar o sistema',
            'URL_REJECTED'
        );
    }

    $scheme = isset($partes['scheme']) ? strtolower((string) $partes['scheme']) : '';

    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RokuXtreamException(
            502,
            'PROVIDER_UNAVAILABLE',
            'Não foi possível acessar o sistema',
            'URL_SCHEME_REJECTED'
        );
    }

    if (
        array_key_exists('user', $partes)
        || array_key_exists('pass', $partes)
        || array_key_exists('query', $partes)
        || array_key_exists('fragment', $partes)
    ) {
        throw new RokuXtreamException(
            502,
            'PROVIDER_UNAVAILABLE',
            'Não foi possível acessar o sistema',
            'URL_COMPONENT_REJECTED'
        );
    }

    $hostOriginal = isset($partes['host']) ? (string) $partes['host'] : '';
    $hostSemColchetes = trim($hostOriginal, '[]');
    $host = strtolower($hostSemColchetes);

    if (str_ends_with($host, '.')) {
        $host = substr($host, 0, -1);
    }

    if ($host === '' || $host === 'localhost') {
        throw new RokuXtreamException(
            502,
            'PROVIDER_UNAVAILABLE',
            'Não foi possível acessar o sistema',
            'URL_REJECTED'
        );
    }

    $porta = isset($partes['port'])
        ? (int) $partes['port']
        : ($scheme === 'https' ? 443 : 80);

    if ($porta < 1 || $porta > 65535) {
        throw new RokuXtreamException(
            502,
            'PROVIDER_UNAVAILABLE',
            'Não foi possível acessar o sistema',
            'URL_REJECTED'
        );
    }

    $caminhoBase = isset($partes['path']) ? (string) $partes['path'] : '';
    $caminhoDecodificado = rawurldecode($caminhoBase);
    $possuiSegmentoAmbiguo = static function (string $caminho): bool {
        foreach (explode('/', $caminho) as $segmento) {
            if ($segmento === '.' || $segmento === '..') {
                return true;
            }
        }

        return false;
    };

    if (
        str_contains($caminhoBase, '\\')
        || str_contains($caminhoDecodificado, '\\')
        || preg_match('/%2f/i', $caminhoBase) === 1
        || preg_match('/[\x00-\x1F\x7F]/', $caminhoBase) === 1
        || preg_match('/[\x00-\x1F\x7F]/', $caminhoDecodificado) === 1
        || $possuiSegmentoAmbiguo($caminhoBase)
        || $possuiSegmentoAmbiguo($caminhoDecodificado)
    ) {
        throw new RokuXtreamException(
            502,
            'PROVIDER_UNAVAILABLE',
            'Não foi possível acessar o sistema',
            'URL_COMPONENT_REJECTED'
        );
    }

    $caminhoBase = rtrim($caminhoBase, '/');
    $caminhoEndpoint = str_ends_with($caminhoBase, '/player_api.php')
        ? $caminhoBase
        : $caminhoBase . '/player_api.php';

    if ($caminhoEndpoint === '') {
        $caminhoEndpoint = '/player_api.php';
    }

    $hostEhIpv4 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    $hostEhIpv6 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    $hostEhIpLiteral = $hostEhIpv4 || $hostEhIpv6;

    if ($hostEhIpv4) {
        $hostBinario = @inet_pton($host);
        $hostCanonico = $hostBinario !== false ? @inet_ntop($hostBinario) : false;

        if ($hostCanonico === false || $hostCanonico !== $host) {
            throw new RokuXtreamException(
                502,
                'PROVIDER_UNAVAILABLE',
                'Não foi possível acessar o sistema',
                'URL_REJECTED'
            );
        }

        if (!enderecoIpPublicoPermitidoRoku($host)) {
            throw new RokuXtreamException(
                502,
                'PROVIDER_UNAVAILABLE',
                'Não foi possível acessar o sistema',
                'SSRF_BLOCKED'
            );
        }

        $ipResolvido = $host;
    } elseif ($hostEhIpv6) {
        $hostBinario = @inet_pton($host);
        $hostCanonico = $hostBinario !== false ? @inet_ntop($hostBinario) : false;

        if ($hostCanonico === false) {
            throw new RokuXtreamException(
                502,
                'PROVIDER_UNAVAILABLE',
                'Não foi possível acessar o sistema',
                'URL_REJECTED'
            );
        }

        $host = strtolower($hostCanonico);

        if (!enderecoIpPublicoPermitidoRoku($host)) {
            throw new RokuXtreamException(
                502,
                'PROVIDER_UNAVAILABLE',
                'Não foi possível acessar o sistema',
                'SSRF_BLOCKED'
            );
        }

        $ipResolvido = $host;
    } else {
        if (hostIpv4NumericoAmbiguoRoku($host)) {
            throw new RokuXtreamException(
                502,
                'PROVIDER_UNAVAILABLE',
                'Não foi possível acessar o sistema',
                'URL_REJECTED'
            );
        }

        if (
            strlen($host) > 253
            || preg_match(
                '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*'
                . '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
                $host
            ) !== 1
        ) {
            throw new RokuXtreamException(
                502,
                'PROVIDER_UNAVAILABLE',
                'Não foi possível acessar o sistema',
                'URL_REJECTED'
            );
        }

        $enderecos = resolverHostPublicoRoku($host);
        $ipResolvido = $enderecos[0];
    }

    $hostNaUrl = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
        ? '[' . $host . ']'
        : $host;
    $portaPadrao = $scheme === 'https' ? 443 : 80;
    $sufixoPorta = $porta === $portaPadrao ? '' : ':' . $porta;
    $endpointUrl = $scheme . '://' . $hostNaUrl . $sufixoPorta . $caminhoEndpoint;

    return [
        'scheme' => $scheme,
        'host' => $host,
        'port' => $porta,
        'endpoint_url' => $endpointUrl,
        'ip_resolvido' => $ipResolvido,
        'host_eh_ip_literal' => $hostEhIpLiteral,
    ];
}

function classificarErroCurlXtreamRoku(int $codigoCurl): string
{
    $categoriasPorConstante = [
        'CURL_DNS_ERROR' => [
            'CURLE_COULDNT_RESOLVE_HOST',
        ],
        'CURL_CONNECTION_ERROR' => [
            'CURLE_COULDNT_CONNECT',
            'CURLE_SEND_ERROR',
            'CURLE_RECV_ERROR',
            'CURLE_GOT_NOTHING',
        ],
        'CURL_TLS_ERROR' => [
            'CURLE_SSL_CONNECT_ERROR',
            'CURLE_PEER_FAILED_VERIFICATION',
            'CURLE_SSL_CERTPROBLEM',
            'CURLE_SSL_CIPHER',
        ],
    ];

    foreach ($categoriasPorConstante as $categoria => $nomesConstantes) {
        foreach ($nomesConstantes as $nomeConstante) {
            if (defined($nomeConstante) && $codigoCurl === constant($nomeConstante)) {
                return $categoria;
            }
        }
    }

    return 'CURL_OTHER_ERROR';
}

function classificarStatusHttpFornecedorRoku(int $codigoHttp): ?string
{
    return $codigoHttp >= 200 && $codigoHttp <= 299
        ? null
        : 'PROVIDER_HTTP_NON_2XX';
}

/**
 * Consulta a API Xtream com dados sensíveis exclusivamente em memória.
 *
 * A URL, as credenciais, o IP resolvido e o corpo bruto nunca devem ser
 * registrados, exibidos ou enviados diretamente à Roku.
 *
 * @return array<mixed>
 */
function requisitarJsonXtreamRoku(
    string $fornecedorUrl,
    string $usuario,
    string $senha,
    ?string $action = null,
    array $parametrosExtras = []
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Extensão cURL indisponível');
    }

    $actionsPermitidas = [
        null,
        'get_live_categories',
        'get_vod_categories',
        'get_series_categories',
    ];

    if (!in_array($action, $actionsPermitidas, true)) {
        throw new InvalidArgumentException('Action Xtream não permitida');
    }

    if ($parametrosExtras !== []) {
        throw new InvalidArgumentException('Parâmetros extras não permitidos');
    }

    $usuarioNormalizado = trim($usuario);

    if ($usuarioNormalizado === '') {
        throw new InvalidArgumentException('Usuário Xtream inválido');
    }

    if ($senha === '') {
        throw new InvalidArgumentException('Senha Xtream inválida');
    }

    $urlValidada = validarUrlFornecedorRoku($fornecedorUrl);
    $parametros = [
        'username' => $usuarioNormalizado,
        'password' => $senha,
    ];

    if ($action !== null) {
        $parametros['action'] = $action;
    }

    $query = http_build_query($parametros, '', '&', PHP_QUERY_RFC3986);
    $urlRequisicao = $urlValidada['endpoint_url'] . '?' . $query;
    $limiteResposta = 2 * 1024 * 1024;
    $corpoResposta = '';
    $bytesRecebidos = 0;
    $respostaGrandeDemais = false;

    $curl = curl_init();

    if ($curl === false) {
        throw new RuntimeException('Não foi possível inicializar a extensão cURL');
    }

    $opcoes = [
        CURLOPT_URL => $urlRequisicao,
        CURLOPT_HTTPGET => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'TopMaster-Roku-Backend/1.0',
        CURLOPT_HEADER => false,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FAILONERROR => false,
        CURLOPT_PROXY => '',
        CURLOPT_NETRC => CURL_NETRC_IGNORED,
        CURLOPT_FRESH_CONNECT => true,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_WRITEFUNCTION => static function (
            CurlHandle $handle,
            string $chunk
        ) use (
            &$corpoResposta,
            &$bytesRecebidos,
            &$respostaGrandeDemais,
            $limiteResposta
        ): int {
            $tamanhoChunk = strlen($chunk);

            if ($bytesRecebidos + $tamanhoChunk > $limiteResposta) {
                $respostaGrandeDemais = true;

                return 0;
            }

            $corpoResposta .= $chunk;
            $bytesRecebidos += $tamanhoChunk;

            return $tamanhoChunk;
        },
    ];

    if (defined('CURLOPT_PROTOCOLS_STR')) {
        $opcoes[constant('CURLOPT_PROTOCOLS_STR')] = 'http,https';
    } else {
        $opcoes[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }

    $ipResolvido = $urlValidada['ip_resolvido'];
    $ipEhIpv6 = filter_var($ipResolvido, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    $opcoes[CURLOPT_IPRESOLVE] = $ipEhIpv6 ? CURL_IPRESOLVE_V6 : CURL_IPRESOLVE_V4;

    if (!$urlValidada['host_eh_ip_literal']) {
        $ipNaResolucao = $ipEhIpv6 ? '[' . $ipResolvido . ']' : $ipResolvido;
        $opcoes[CURLOPT_RESOLVE] = [
            $urlValidada['host'] . ':' . $urlValidada['port'] . ':' . $ipNaResolucao,
        ];
    }

    try {
        if (!curl_setopt_array($curl, $opcoes)) {
            throw new RuntimeException('Não foi possível configurar a extensão cURL');
        }

        $executado = curl_exec($curl);
        $codigoCurl = curl_errno($curl);
        $codigoHttp = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    } finally {
        curl_close($curl);
    }

    if ($respostaGrandeDemais) {
        throw new RokuXtreamException(
            502,
            'PROVIDER_RESPONSE_TOO_LARGE',
            'Resposta do sistema muito grande',
            'RESPONSE_TOO_LARGE'
        );
    }

    if ($codigoCurl === CURLE_OPERATION_TIMEDOUT) {
        throw new RokuXtreamException(
            504,
            'PROVIDER_TIMEOUT',
            'O sistema demorou para responder',
            'PROVIDER_TIMEOUT'
        );
    }

    if ($executado === false || $codigoCurl !== CURLE_OK) {
        throw new RokuXtreamException(
            502,
            'PROVIDER_UNAVAILABLE',
            'Não foi possível acessar o sistema',
            classificarErroCurlXtreamRoku($codigoCurl)
        );
    }

    $categoriaErroHttp = classificarStatusHttpFornecedorRoku($codigoHttp);

    if ($categoriaErroHttp !== null) {
        throw new RokuXtreamException(
            502,
            'PROVIDER_UNAVAILABLE',
            'Não foi possível acessar o sistema',
            $categoriaErroHttp
        );
    }

    if (trim($corpoResposta) === '') {
        throw new RokuXtreamException(
            502,
            'INVALID_PROVIDER_RESPONSE',
            'Resposta inválida do sistema',
            'INVALID_RESPONSE'
        );
    }

    try {
        $respostaDecodificada = json_decode(
            $corpoResposta,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        throw new RokuXtreamException(
            502,
            'INVALID_PROVIDER_RESPONSE',
            'Resposta inválida do sistema',
            'INVALID_RESPONSE'
        );
    }

    if (!is_array($respostaDecodificada)) {
        throw new RokuXtreamException(
            502,
            'INVALID_PROVIDER_RESPONSE',
            'Resposta inválida do sistema',
            'INVALID_RESPONSE'
        );
    }

    return $respostaDecodificada;
}
