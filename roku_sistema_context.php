<?php

declare(strict_types=1);

final class RokuSistemaException extends RuntimeException
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

/**
 * Retorna um contexto sensível exclusivamente para uso interno do backend.
 * O array contém credenciais e nunca deve ser enviado diretamente à Roku.
 * O chamador é responsável por iniciar, confirmar ou desfazer transações quando necessário.
 *
 * @return array{
 *     sistema_id: int,
 *     cliente_id: int,
 *     nome: string,
 *     tipo_acesso: 'xtream'|'m3u'|'indisponivel',
 *     fornecedor_url: string|null,
 *     usuario: string|null,
 *     senha: string|null,
 *     m3u_url: string|null,
 *     status: string,
 *     exp_date: string|null,
 *     vencimento: string|null
 * }
 */
function obterContextoSistemaRoku(PDO $pdo, int $clienteId, int $sistemaId): array
{
    if ($clienteId <= 0 || $sistemaId <= 0) {
        throw new RokuSistemaException(400, 'INVALID_REQUEST', 'Requisição inválida');
    }

    $consulta = $pdo->prepare(<<<'SQL'
        SELECT
            s.id AS sistema_id,
            s.cliente_id,
            COALESCE(
                NULLIF(TRIM(m.nome), ''),
                NULLIF(TRIM(s.nome_sistema), ''),
                'Sistema'
            ) AS nome,
            COALESCE(
                NULLIF(TRIM(s.url), ''),
                NULLIF(TRIM(m.url_padrao), '')
            ) AS fornecedor_url,
            s.usuario,
            s.senha,
            s.m3u_url,
            s.status,
            s.exp_date,
            s.vencimento
        FROM sistemas AS s
        LEFT JOIN modelos_sistemas AS m
            ON m.id = s.modelo_id
        WHERE s.id = :sistema_id
          AND s.cliente_id = :cliente_id
        LIMIT 1
        SQL);
    $consulta->bindValue(':sistema_id', $sistemaId, PDO::PARAM_INT);
    $consulta->bindValue(':cliente_id', $clienteId, PDO::PARAM_INT);
    $consulta->execute();
    $contexto = $consulta->fetch(PDO::FETCH_ASSOC);

    if (!$contexto) {
        throw new RokuSistemaException(404, 'SYSTEM_NOT_FOUND', 'Sistema não encontrado');
    }

    $nome = (string) $contexto['nome'];

    if (trim($nome) === '') {
        $nome = 'Sistema';
    }

    $fornecedorUrl = $contexto['fornecedor_url'] !== null
        ? rtrim(trim((string) $contexto['fornecedor_url']), '/')
        : '';
    $fornecedorUrl = $fornecedorUrl !== '' ? $fornecedorUrl : null;

    $m3uUrl = $contexto['m3u_url'] !== null
        ? trim((string) $contexto['m3u_url'])
        : '';
    $m3uUrl = $m3uUrl !== '' ? $m3uUrl : null;

    $usuario = $contexto['usuario'] !== null ? (string) $contexto['usuario'] : null;
    $senha = $contexto['senha'] !== null ? (string) $contexto['senha'] : null;

    $xtreamCompleto = $fornecedorUrl !== null
        && $usuario !== null
        && $usuario !== ''
        && $senha !== null
        && $senha !== '';

    if ($xtreamCompleto) {
        $tipoAcesso = 'xtream';
    } elseif ($m3uUrl !== null) {
        $tipoAcesso = 'm3u';
    } else {
        $tipoAcesso = 'indisponivel';
    }

    $status = $contexto['status'] !== null ? trim((string) $contexto['status']) : '';

    if ($status === '') {
        $status = 'Unknown';
    }

    $expDate = $contexto['exp_date'] !== null ? trim((string) $contexto['exp_date']) : '';
    $expDate = $expDate !== '' ? $expDate : null;

    $vencimento = $contexto['vencimento'] !== null
        ? trim((string) $contexto['vencimento'])
        : '';
    $vencimento = $vencimento !== '' ? $vencimento : null;

    return [
        'sistema_id' => (int) $contexto['sistema_id'],
        'cliente_id' => (int) $contexto['cliente_id'],
        'nome' => $nome,
        'tipo_acesso' => $tipoAcesso,
        'fornecedor_url' => $fornecedorUrl,
        'usuario' => $usuario,
        'senha' => $senha,
        'm3u_url' => $m3uUrl,
        'status' => $status,
        'exp_date' => $expDate,
        'vencimento' => $vencimento,
    ];
}
