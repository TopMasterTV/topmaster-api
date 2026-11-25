<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/administrative_token_auth.php';
require_once __DIR__ . '/atualizacao_automatica_historico_repository.php';

function responderHistoricoAtualizacaoAutomatica(int $status, array $conteudo): never
{
    http_response_code($status);
    echo json_encode($conteudo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderHistoricoAtualizacaoAutomatica(405, [
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => 'Metodo nao permitido',
    ]);
}

try {
    $ator = autenticarTokenAdministrativo($pdo);
} catch (AdministrativeAuthException $e) {
    responderHistoricoAtualizacaoAutomatica($e->getStatusHttp(), [
        'success' => false,
        'code' => $e->getCodigoPublico(),
        'message' => $e->getMensagemPublica(),
    ]);
} catch (Throwable) {
    responderHistoricoAtualizacaoAutomatica(500, [
        'success' => false,
        'code' => 'INTERNAL_ERROR',
        'message' => 'Erro ao autenticar administrador',
    ]);
}

if (($ator['actor_type'] ?? null) !== 'master') {
    responderHistoricoAtualizacaoAutomatica(403, [
        'success' => false,
        'code' => 'FORBIDDEN',
        'message' => 'Acesso nao autorizado',
    ]);
}

function lerEntradaHistoricoAtualizacaoAutomatica(): array
{
    $tipo = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
    if (str_starts_with($tipo, 'application/json')) {
        $conteudo = file_get_contents('php://input');
        if (!is_string($conteudo) || trim($conteudo) === '') {
            return [];
        }
        $entrada = json_decode($conteudo, true);
        if (!is_array($entrada)) {
            throw new InvalidArgumentException('JSON invalido');
        }
        return $entrada;
    }
    return is_array($_POST) ? $_POST : [];
}

function inteiroPositivoHistorico(mixed $valor, string $campo, ?int $padrao = null): int
{
    if ($valor === null && $padrao !== null) {
        return $padrao;
    }
    if (is_int($valor) && $valor > 0) {
        return $valor;
    }
    if (is_string($valor) && preg_match('/\A[1-9][0-9]*\z/D', $valor) === 1) {
        $inteiro = filter_var($valor, FILTER_VALIDATE_INT);
        if (is_int($inteiro) && $inteiro > 0) {
            return $inteiro;
        }
    }
    throw new InvalidArgumentException("Campo {$campo} invalido");
}

try {
    $entrada = lerEntradaHistoricoAtualizacaoAutomatica();
    $cicloId = array_key_exists('ciclo_id', $entrada) && $entrada['ciclo_id'] !== null
        ? inteiroPositivoHistorico($entrada['ciclo_id'], 'ciclo_id')
        : null;
    $pagina = inteiroPositivoHistorico(
        $entrada['pagina'] ?? null,
        'pagina',
        1
    );
    $limite = inteiroPositivoHistorico(
        $entrada['limite'] ?? null,
        'limite',
        AtualizacaoAutomaticaHistoricoRepository::LIMITE_PADRAO
    );
    if (
        $pagina > AtualizacaoAutomaticaHistoricoRepository::PAGINA_MAXIMA
        || $limite > AtualizacaoAutomaticaHistoricoRepository::LIMITE_MAXIMO
    ) {
        throw new InvalidArgumentException('Limite invalido');
    }
} catch (InvalidArgumentException) {
    responderHistoricoAtualizacaoAutomatica(400, [
        'success' => false,
        'code' => 'INVALID_REQUEST',
        'message' => 'Parametros de consulta invalidos',
    ]);
}

try {
    $repositorio = new AtualizacaoAutomaticaHistoricoRepository(
        new AtualizacaoAutomaticaHistoricoPdoDatabase($pdo)
    );
    if ($cicloId !== null) {
        $detalhe = $repositorio->detalhar($cicloId);
        if ($detalhe === null) {
            responderHistoricoAtualizacaoAutomatica(404, [
                'success' => false,
                'code' => 'CYCLE_NOT_FOUND',
                'message' => 'Ciclo nao encontrado',
            ]);
        }
        responderHistoricoAtualizacaoAutomatica(200, [
            'success' => true,
            'code' => 'AUTOMATION_CYCLE_RETURNED',
            'message' => 'Detalhes do ciclo consultados',
            'historico' => $detalhe,
        ]);
    }

    $historico = $repositorio->listar($pagina, $limite);
} catch (Throwable) {
    responderHistoricoAtualizacaoAutomatica(500, [
        'success' => false,
        'code' => 'INTERNAL_ERROR',
        'message' => 'Erro ao consultar historico da atualizacao automatica',
    ]);
}

responderHistoricoAtualizacaoAutomatica(200, [
    'success' => true,
    'code' => 'AUTOMATION_HISTORY_RETURNED',
    'message' => 'Historico da atualizacao automatica consultado',
    'historico' => $historico,
]);
