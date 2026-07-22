<?php

declare(strict_types=1);

final class RokuAuthException extends RuntimeException
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

function localizarCabecalhoAuthorizationRoku(): ?string
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

function extrairTokenBearerRoku(): string
{
    $cabecalho = localizarCabecalhoAuthorizationRoku();

    if (
        $cabecalho === null
        || preg_match('/\ABearer +([a-f0-9]{64})\z/iD', $cabecalho, $correspondencia) !== 1
    ) {
        throw new RokuAuthException(401, 'AUTH_REQUIRED', 'Autenticação necessária');
    }

    return strtolower($correspondencia[1]);
}

function interpretarBooleanoRoku(mixed $valor): bool
{
    if (is_bool($valor)) {
        return $valor;
    }

    if ($valor === 1 || $valor === '1' || $valor === 't' || $valor === 'true') {
        return true;
    }

    if ($valor === 0 || $valor === '0' || $valor === 'f' || $valor === 'false') {
        return false;
    }

    throw new UnexpectedValueException('Valor booleano inesperado');
}

function autenticarTokenRoku(PDO $pdo): array
{
    $transacaoIniciadaPeloHelper = false;

    try {
        $tokenOriginalNormalizado = extrairTokenBearerRoku();
        $tokenHash = hash('sha256', $tokenOriginalNormalizado);

        if (!$pdo->inTransaction()) {
            if (!$pdo->beginTransaction()) {
                throw new RuntimeException('Não foi possível iniciar a transação');
            }

            $transacaoIniciadaPeloHelper = true;
        }

        $consulta = $pdo->prepare(<<<'SQL'
            SELECT
                token.id AS token_id,
                token.cliente_id,
                cliente.nome,
                cliente.usuario,
                cliente.plano,
                token.app_tipo,
                token.expira_em,
                token.revogado_em,
                cliente.ativo,
                token.ultimo_uso_em,
                (token.expira_em <= clock_timestamp()) AS expirado
            FROM cliente_tokens AS token
            INNER JOIN clientes AS cliente
                ON token.cliente_id = cliente.id
            WHERE token.token_hash = :token_hash
            LIMIT 1
            FOR UPDATE OF token
            SQL);
        $consulta->execute([':token_hash' => $tokenHash]);
        $sessao = $consulta->fetch(PDO::FETCH_ASSOC);

        if (!$sessao) {
            throw new RokuAuthException(401, 'INVALID_TOKEN', 'Sessão inválida');
        }

        if ((string) $sessao['app_tipo'] !== 'roku') {
            throw new RokuAuthException(401, 'INVALID_TOKEN', 'Sessão inválida');
        }

        if ($sessao['revogado_em'] !== null) {
            throw new RokuAuthException(401, 'INVALID_TOKEN', 'Sessão inválida');
        }

        if (interpretarBooleanoRoku($sessao['expirado'])) {
            throw new RokuAuthException(401, 'TOKEN_EXPIRED', 'Sessão expirada');
        }

        if (!interpretarBooleanoRoku($sessao['ativo'])) {
            throw new RokuAuthException(
                403,
                'CLIENT_INACTIVE',
                'Acesso indisponível. Entre em contato com o suporte.'
            );
        }

        $atualizacaoUltimoUso = $pdo->prepare(<<<'SQL'
            WITH momento AS (
                SELECT clock_timestamp() AS agora
            )
            UPDATE cliente_tokens AS token
            SET ultimo_uso_em = momento.agora
            FROM momento
            WHERE token.id = :token_id
              AND token.app_tipo = 'roku'
              AND token.revogado_em IS NULL
              AND token.expira_em > momento.agora
              AND (
                  token.ultimo_uso_em IS NULL
                  OR token.ultimo_uso_em < momento.agora - INTERVAL '5 minutes'
              )
            SQL);
        $atualizacaoUltimoUso->execute([':token_id' => $sessao['token_id']]);

        $dadosAutenticados = [
            'token_id' => (int) $sessao['token_id'],
            'cliente_id' => (int) $sessao['cliente_id'],
            'nome' => (string) $sessao['nome'],
            'usuario' => (string) $sessao['usuario'],
            'plano' => $sessao['plano'],
            'expira_em' => $sessao['expira_em'],
        ];

        if ($transacaoIniciadaPeloHelper) {
            if (!$pdo->commit()) {
                throw new RuntimeException('Não foi possível confirmar a transação');
            }
        }

        return $dadosAutenticados;
    } catch (RokuAuthException $e) {
        if ($transacaoIniciadaPeloHelper && $pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable $rollbackError) {
                // Preserva a exceção pública original mesmo se o rollback falhar.
            }
        }

        throw $e;
    } catch (Throwable $e) {
        if ($transacaoIniciadaPeloHelper && $pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable $rollbackError) {
                // Preserva o erro interno original mesmo se o rollback falhar.
            }
        }

        throw $e;
    }
}
