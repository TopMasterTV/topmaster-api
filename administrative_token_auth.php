<?php

declare(strict_types=1);

const ADMINISTRATIVE_TOKEN_TTL_SECONDS = 604800;
const ADMINISTRATIVE_REFRESH_TOKEN_TTL_SECONDS = 7776000;

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
            clock_timestamp() + (
                CAST(:ttl_seconds AS BIGINT) * INTERVAL '1 second'
            )
        )
        SQL);
    $insercao->execute([
        ':actor_type' => $actorType,
        ':actor_id' => $actorId,
        ':token_hash' => $tokenHash,
        ':ttl_seconds' => ADMINISTRATIVE_TOKEN_TTL_SECONDS,
    ]);

    return [
        'access_token' => $tokenOriginal,
        'token_type' => 'Bearer',
        'expires_in' => ADMINISTRATIVE_TOKEN_TTL_SECONDS,
    ];
}

function emitirRefreshTokenMaster(PDO $pdo, int $adminId): array
{
    if ($adminId <= 0) {
        throw new InvalidArgumentException('Administrador Master invalido');
    }

    $tokenOriginal = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $tokenOriginal);

    $insercao = $pdo->prepare(<<<'SQL'
        INSERT INTO administrative_refresh_tokens (
            admin_id,
            token_hash,
            expira_em
        )
        SELECT
            id,
            :token_hash,
            clock_timestamp() + (
                CAST(:ttl_seconds AS BIGINT) * INTERVAL '1 second'
            )
        FROM admins
        WHERE id = :admin_id
          AND tipo = 'master'
        SQL);
    $insercao->execute([
        ':admin_id' => $adminId,
        ':token_hash' => $tokenHash,
        ':ttl_seconds' => ADMINISTRATIVE_REFRESH_TOKEN_TTL_SECONDS,
    ]);

    if ($insercao->rowCount() !== 1) {
        throw new InvalidArgumentException('Administrador Master invalido');
    }

    return [
        'refresh_token' => $tokenOriginal,
        'refresh_expires_in' => ADMINISTRATIVE_REFRESH_TOKEN_TTL_SECONDS,
    ];
}

function emitirSessaoMaster(PDO $pdo, int $adminId): array
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Emissao de sessao Master exige transacao propria');
    }

    $pdo->beginTransaction();
    try {
        if (!bloquearAdministradorMaster($pdo, $adminId)) {
            throw new InvalidArgumentException('Administrador Master invalido');
        }
        $access = emitirTokenAdministrativo($pdo, 'master', $adminId);
        $refresh = emitirRefreshTokenMaster($pdo, $adminId);
        $pdo->commit();
        return array_merge($access, $refresh);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function validarFormatoRefreshTokenMaster(string $refreshToken): string
{
    if (preg_match('/\A[a-f0-9]{64}\z/iD', $refreshToken) !== 1) {
        throw new AdministrativeAuthException(
            401,
            'INVALID_REFRESH_TOKEN',
            'Token de renovacao invalido'
        );
    }

    return strtolower($refreshToken);
}

function bloquearAdministradorMaster(PDO $pdo, int $adminId): bool
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Bloqueio de administrador Master exige transacao ativa');
    }

    $consulta = $pdo->prepare(<<<'SQL'
        SELECT tipo
        FROM admins
        WHERE id = :admin_id
        FOR UPDATE
        SQL);
    $consulta->execute([':admin_id' => $adminId]);

    return $consulta->fetchColumn() === 'master';
}

function renovarSessaoMaster(PDO $pdo, string $refreshToken): array
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Renovacao de sessao Master exige transacao propria');
    }

    $refreshToken = validarFormatoRefreshTokenMaster($refreshToken);
    $tokenHash = hash('sha256', $refreshToken);

    $pdo->beginTransaction();
    try {
        $localizacao = $pdo->prepare(<<<'SQL'
            SELECT admin_id
            FROM administrative_refresh_tokens
            WHERE token_hash = :token_hash
            LIMIT 1
            SQL);
        $localizacao->execute([':token_hash' => $tokenHash]);
        $adminId = $localizacao->fetchColumn();

        if ($adminId === false || !bloquearAdministradorMaster($pdo, (int) $adminId)) {
            throw new AdministrativeAuthException(
                401,
                'INVALID_REFRESH_TOKEN',
                'Token de renovacao invalido'
            );
        }

        $consulta = $pdo->prepare(<<<'SQL'
            SELECT
                token.id AS refresh_id,
                token.admin_id,
                token.expira_em,
                token.revogado_em,
                (token.expira_em <= clock_timestamp()) AS expirado
            FROM administrative_refresh_tokens AS token
            WHERE token.token_hash = :token_hash
              AND token.admin_id = :admin_id
            LIMIT 1
            FOR UPDATE
            SQL);
        $consulta->execute([
            ':token_hash' => $tokenHash,
            ':admin_id' => (int) $adminId,
        ]);
        $sessao = $consulta->fetch(PDO::FETCH_ASSOC);

        if (!$sessao) {
            throw new AdministrativeAuthException(
                401,
                'INVALID_REFRESH_TOKEN',
                'Token de renovacao invalido'
            );
        }

        if ($sessao['revogado_em'] !== null) {
            throw new AdministrativeAuthException(
                401,
                'REFRESH_TOKEN_REVOKED',
                'Token de renovacao revogado'
            );
        }

        $expirado = $sessao['expirado'];
        if ($expirado === true || $expirado === 1 || $expirado === '1' || $expirado === 't') {
            throw new AdministrativeAuthException(
                401,
                'REFRESH_TOKEN_EXPIRED',
                'Token de renovacao expirado'
            );
        }

        $access = emitirTokenAdministrativo($pdo, 'master', (int) $sessao['admin_id']);
        $atualizacao = $pdo->prepare(<<<'SQL'
            UPDATE administrative_refresh_tokens
            SET ultimo_uso_em = clock_timestamp()
            WHERE id = :refresh_id
              AND revogado_em IS NULL
              AND expira_em > clock_timestamp()
            SQL);
        $atualizacao->execute([':refresh_id' => $sessao['refresh_id']]);
        if ($atualizacao->rowCount() !== 1) {
            throw new RuntimeException('Falha ao registrar renovacao Master');
        }

        $pdo->commit();
        return array_merge([
            'success' => true,
            'admin_id' => (int) $sessao['admin_id'],
            'tipo' => 'master',
        ], $access);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
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

function revogarTokenAdministrativoApresentado(PDO $pdo): void
{
    $tokenOriginal = extrairTokenBearerAdministrativo();
    $tokenHash = hash('sha256', $tokenOriginal);

    $revogacao = $pdo->prepare(<<<'SQL'
        UPDATE administrative_tokens
        SET revogado_em = COALESCE(revogado_em, clock_timestamp())
        WHERE token_hash = :token_hash
        SQL);
    $revogacao->execute([':token_hash' => $tokenHash]);
}

function revogarCredenciaisAdministrativasApresentadas(
    PDO $pdo,
    ?string $refreshToken = null
): void {
    $cabecalho = localizarCabecalhoAuthorizationAdministrativo();
    $tokenHash = null;
    if ($cabecalho !== null) {
        if (preg_match('/\ABearer +([a-f0-9]{64})\z/iD', $cabecalho, $correspondencia) !== 1) {
            throw new AdministrativeAuthException(401, 'AUTH_REQUIRED', 'Autenticacao necessaria');
        }
        $tokenHash = hash('sha256', strtolower($correspondencia[1]));
    }

    $refreshHash = null;
    if ($refreshToken !== null && $refreshToken !== '') {
        $refreshHash = hash('sha256', validarFormatoRefreshTokenMaster($refreshToken));
    }

    if ($tokenHash === null && $refreshHash === null) {
        throw new AdministrativeAuthException(401, 'AUTH_REQUIRED', 'Autenticacao necessaria');
    }

    if ($pdo->inTransaction()) {
        throw new LogicException('Logout administrativo exige transacao propria');
    }

    $pdo->beginTransaction();
    try {
        $ator = false;
        if ($tokenHash !== null) {
            $consulta = $pdo->prepare(<<<'SQL'
                SELECT actor_type, actor_id
                FROM administrative_tokens
                WHERE token_hash = :token_hash
                LIMIT 1
                SQL);
            $consulta->execute([':token_hash' => $tokenHash]);
            $ator = $consulta->fetch(PDO::FETCH_ASSOC);
        }

        $adminIdRefresh = false;
        if ($refreshHash !== null) {
            $consultaRefresh = $pdo->prepare(<<<'SQL'
                SELECT admin_id
                FROM administrative_refresh_tokens
                WHERE token_hash = :token_hash
                LIMIT 1
                SQL);
            $consultaRefresh->execute([':token_hash' => $refreshHash]);
            $adminIdRefresh = $consultaRefresh->fetchColumn();
        }

        if (
            $tokenHash !== null
            && $refreshHash !== null
            && (
                !$ator
                || $adminIdRefresh === false
                || ($ator['actor_type'] ?? null) !== 'master'
                || (int) $ator['actor_id'] !== (int) $adminIdRefresh
            )
        ) {
            throw new AdministrativeAuthException(
                401,
                'CREDENTIAL_MISMATCH',
                'Credenciais administrativas nao correspondem'
            );
        }

        if ($adminIdRefresh !== false) {
            $adminId = (int) $adminIdRefresh;
            bloquearAdministradorMaster($pdo, $adminId);

            $bloqueioRefresh = $pdo->prepare(<<<'SQL'
                SELECT admin_id
                FROM administrative_refresh_tokens
                WHERE token_hash = :token_hash
                  AND admin_id = :admin_id
                FOR UPDATE
                SQL);
            $bloqueioRefresh->execute([
                ':token_hash' => $refreshHash,
                ':admin_id' => $adminId,
            ]);

            $revogacaoAccessMaster = $pdo->prepare(<<<'SQL'
                UPDATE administrative_tokens
                SET revogado_em = COALESCE(revogado_em, clock_timestamp())
                WHERE actor_type = 'master'
                  AND actor_id = :admin_id
                SQL);
            $revogacaoAccessMaster->execute([':admin_id' => $adminId]);

            $revogacaoRefresh = $pdo->prepare(<<<'SQL'
                UPDATE administrative_refresh_tokens
                SET revogado_em = COALESCE(revogado_em, clock_timestamp())
                WHERE admin_id = :admin_id
                SQL);
            $revogacaoRefresh->execute([':admin_id' => $adminId]);
        } elseif ($tokenHash !== null) {
            if ($ator && ($ator['actor_type'] ?? null) === 'master') {
                bloquearAdministradorMaster($pdo, (int) $ator['actor_id']);
            }

            $revogacaoAccess = $pdo->prepare(<<<'SQL'
                UPDATE administrative_tokens
                SET revogado_em = COALESCE(revogado_em, clock_timestamp())
                WHERE token_hash = :token_hash
                SQL);
            $revogacaoAccess->execute([':token_hash' => $tokenHash]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function revogarTokensAdministrativosDoAtor(
    PDO $pdo,
    string $actorType,
    int $actorId
): void {
    if (!in_array($actorType, ['master', 'revendedor'], true) || $actorId <= 0) {
        throw new InvalidArgumentException('Ator administrativo invalido');
    }

    if ($actorType === 'master') {
        if (!$pdo->inTransaction()) {
            throw new LogicException('Revogacao de tokens Master exige transacao ativa');
        }
        bloquearAdministradorMaster($pdo, $actorId);
    }

    $revogacao = $pdo->prepare(<<<'SQL'
        UPDATE administrative_tokens
        SET revogado_em = clock_timestamp()
        WHERE actor_type = :actor_type
          AND actor_id = :actor_id
          AND revogado_em IS NULL
        SQL);
    $revogacao->execute([
        ':actor_type' => $actorType,
        ':actor_id' => $actorId,
    ]);

    if ($actorType === 'master') {
        $revogacaoRefresh = $pdo->prepare(<<<'SQL'
            UPDATE administrative_refresh_tokens
            SET revogado_em = clock_timestamp()
            WHERE admin_id = :admin_id
              AND revogado_em IS NULL
            SQL);
        $revogacaoRefresh->execute([':admin_id' => $actorId]);
    }
}
