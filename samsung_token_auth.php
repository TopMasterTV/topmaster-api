<?php

declare(strict_types=1);

require_once __DIR__ . '/samsung_common.php';

final class SamsungAuthException extends RuntimeException
{
    public function __construct(
        private int $httpStatus,
        private string $publicCode,
        private string $publicMessage
    ) {
        parent::__construct($publicMessage);
    }

    public function getHttpStatus(): int { return $this->httpStatus; }
    public function getPublicCode(): string { return $this->publicCode; }
    public function getPublicMessage(): string { return $this->publicMessage; }
}

function samsungAuthorizationHeader(): ?string
{
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
            return $_SERVER[$key];
        }
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (is_string($name) && strcasecmp($name, 'Authorization') === 0 && is_string($value)) {
                    return $value;
                }
            }
        }
    }

    return null;
}

function samsungBearerToken(): string
{
    $header = samsungAuthorizationHeader();
    if ($header === null || preg_match('/\ABearer +([a-f0-9]{64})\z/iD', $header, $match) !== 1) {
        throw new SamsungAuthException(401, 'AUTH_REQUIRED', 'Autenticação necessária');
    }
    return strtolower($match[1]);
}

function samsungAuthenticate(PDO $pdo): array
{
    $tokenHash = hash('sha256', samsungBearerToken());
    $query = $pdo->prepare(<<<'SQL'
        SELECT
            token.id AS token_id,
            token.cliente_id,
            token.expira_em,
            token.revogado_em,
            token.app_tipo,
            cliente.nome,
            cliente.usuario,
            cliente.plano,
            cliente.ativo,
            (token.expira_em <= clock_timestamp()) AS expirado
        FROM cliente_tokens AS token
        INNER JOIN clientes AS cliente ON cliente.id = token.cliente_id
        WHERE token.token_hash = :token_hash
        LIMIT 1
        SQL);
    $query->execute([':token_hash' => $tokenHash]);
    $session = $query->fetch(PDO::FETCH_ASSOC);

    if (!$session || (string) $session['app_tipo'] !== SAMSUNG_APP_TYPE) {
        throw new SamsungAuthException(401, 'INVALID_TOKEN', 'Sessão inválida');
    }
    if ($session['revogado_em'] !== null) {
        throw new SamsungAuthException(401, 'INVALID_TOKEN', 'Sessão inválida');
    }
    if (samsungPgBool($session['expirado'])) {
        throw new SamsungAuthException(401, 'TOKEN_EXPIRED', 'Sessão expirada');
    }
    if (!samsungPgBool($session['ativo'])) {
        throw new SamsungAuthException(403, 'CLIENT_INACTIVE', 'Acesso indisponível. Entre em contato com o suporte.');
    }

    $touch = $pdo->prepare(<<<'SQL'
        UPDATE cliente_tokens
        SET ultimo_uso_em = clock_timestamp()
        WHERE id = :token_id
          AND app_tipo = 'samsung'
          AND revogado_em IS NULL
          AND expira_em > clock_timestamp()
          AND (ultimo_uso_em IS NULL OR ultimo_uso_em < clock_timestamp() - INTERVAL '5 minutes')
        SQL);
    $touch->execute([':token_id' => $session['token_id']]);

    return [
        'token_id' => (int) $session['token_id'],
        'cliente_id' => (int) $session['cliente_id'],
        'nome' => (string) $session['nome'],
        'usuario' => (string) $session['usuario'],
        'plano' => $session['plano'],
        'expira_em' => $session['expira_em'],
    ];
}

function samsungRespondAuthError(SamsungAuthException $error): never
{
    samsungJsonError($error->getHttpStatus(), $error->getPublicCode(), $error->getPublicMessage());
}
