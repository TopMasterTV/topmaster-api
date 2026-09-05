<?php

declare(strict_types=1);

const ADMINISTRATIVE_TOKEN_TTL_SECONDS = 43200;

final class AdministrativeAuthException extends RuntimeException
{
    private int $statusHttp;
    private string $codigoPublico;
    private string $mensagemPublica;

    public function __construct(int $statusHttp, string $codigoPublico, string $mensagemPublica)
    {
        parent::__construct($mensagemPublica);
        $this->statusHttp = $statusHttp;
        $this->codigoPublico = $codigoPublico;
        $this->mensagemPublica = $mensagemPublica;
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
}

function emitirTokenAdministrativo(PDO $pdo, string $actorType, int $actorId): array
{
    if (!in_array($actorType, ['master', 'revendedor'], true) || $actorId <= 0) {
        throw new InvalidArgumentException('Ator administrativo invalido');
    }

    $tokenOriginal = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $tokenOriginal);

    $insercao = $pdo->prepare(<<<'SQL'
        INSERT INTO administrative_tokens (
            actor_type,
            actor_id,
            token_hash,
            expira_em
        ) VALUES (
            :actor_type,
            :actor_id,
            :token_hash,
            clock_timestamp() + INTERVAL '12 hours'
        )
        SQL);
    $insercao->execute([
        ':actor_type' => $actorType,
        ':actor_id' => $actorId,
        ':token_hash' => $tokenHash,
    ]);

    return [
        'access_token' => $tokenOriginal,
        'token_type' => 'Bearer',
        'expires_in' => ADMINISTRATIVE_TOKEN_TTL_SECONDS,
    ];
}

function localizarCabecalhoAuthorizationAdministrativo(): ?string
{
    if (isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }

    if (
        isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
        && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
    ) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if (function_exists('getallheaders')) {
        $cabecalhos = getallheaders();
        if (is_array($cabecalhos)) {
            foreach ($cabecalhos as $nome => $valor) {
                if (
                    is_string($nome)
                    && strcasecmp($nome, 'Authorization') === 0
                    && is_string($valor)
                ) {
                    return $valor;
                }
            }
        }
    }

    return null;
}

function extrairTokenBearerAdministrativo(): string
{
    $cabecalho = localizarCabecalhoAuthorizationAdministrativo();

    if (
        $cabecalho === null
        || preg_match('/\ABearer +([a-f0-9]{64})\z/iD', $cabecalho, $correspondencia) !== 1
    ) {
        throw new AdministrativeAuthException(401, 'AUTH_REQUIRED', 'Autenticacao necessaria');
    }

    return strtolower($correspondencia[1]);
}

function autenticarTokenAdministrativo(PDO $pdo): array
{
    $tokenOriginal = extrairTokenBearerAdministrativo();
    $tokenHash = hash('sha256', $tokenOriginal);

    $consulta = $pdo->prepare(<<<'SQL'
        SELECT
            token.id AS token_id,
            token.actor_type,
            token.actor_id,
            token.expira_em,
            token.revogado_em,
            token.ultimo_uso_em,
            (token.expira_em <= clock_timestamp()) AS expirado
        FROM administrative_tokens AS token
        INNER JOIN admins AS actor
            ON actor.id = token.actor_id
           AND actor.tipo = token.actor_type
        WHERE token.token_hash = :token_hash
        LIMIT 1
        SQL);
    $consulta->execute([':token_hash' => $tokenHash]);
    $sessao = $consulta->fetch(PDO::FETCH_ASSOC);

    if (!$sessao) {
        throw new AdministrativeAuthException(401, 'INVALID_TOKEN', 'Sessao invalida');
    }

    if ($sessao['revogado_em'] !== null) {
        throw new AdministrativeAuthException(401, 'TOKEN_REVOKED', 'Sessao revogada');
    }

    $expirado = $sessao['expirado'];
    if ($expirado === true || $expirado === 1 || $expirado === '1' || $expirado === 't') {
        throw new AdministrativeAuthException(401, 'TOKEN_EXPIRED', 'Sessao expirada');
    }

    $atualizacao = $pdo->prepare(<<<'SQL'
        UPDATE administrative_tokens
        SET ultimo_uso_em = clock_timestamp()
        WHERE id = :token_id
          AND revogado_em IS NULL
          AND expira_em > clock_timestamp()
          AND (
              ultimo_uso_em IS NULL
              OR ultimo_uso_em < clock_timestamp() - INTERVAL '5 minutes'
          )
        SQL);
    $atualizacao->execute([':token_id' => $sessao['token_id']]);

    return [
        'token_id' => (int) $sessao['token_id'],
        'actor_type' => (string) $sessao['actor_type'],
        'actor_id' => (int) $sessao['actor_id'],
        'expira_em' => $sessao['expira_em'],
    ];
}
