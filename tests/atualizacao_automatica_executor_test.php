<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/atualizacao_automatica_executor.php';

function exigirExecutor(bool $condicao, string $mensagem): void
{
    if (!$condicao) {
        throw new RuntimeException('FALHA: ' . $mensagem);
    }
    fwrite(STDOUT, 'OK: ' . $mensagem . PHP_EOL);
}

final class ExecutorStoreFake implements AtualizacaoAutomaticaExecutorStore
{
    public array $reserva = ['estado' => 'reservado', 'ciclo_id' => 10];
    public array $sistemas = [];
    public array $resultados = [];
    public ?array $totais = null;

    public function reservarCiclo(): array { return $this->reserva; }
    public function listarSistemasElegiveis(int $limiteBusca): array
    {
        return array_slice($this->sistemas, 0, $limiteBusca);
    }
    public function registrarResultado(int $cicloId, array $sistema, array $resultado): void
    {
        $this->resultados[] = compact('cicloId', 'sistema', 'resultado');
    }
    public function finalizarCiclo(int $cicloId, array $totais): void { $this->totais = $totais; }
}

final class ExecutorUpdaterFake implements AtualizacaoAutomaticaSistemaUpdater
{
    public array $processados = [];
    public array $falharIds = [];
    public function atualizar(array $sistema): array
    {
        $id = (int) $sistema['sistema_id'];
        $this->processados[] = $id;
        if (in_array($id, $this->falharIds, true)) {
            throw new RuntimeException('falha sintetica');
        }
        return ['status' => 'atualizado', 'vencimento_novo' => '2027-01-01'];
    }
}

function sistemaExecutor(int $id, string $provedor): array
{
    return ['sistema_id' => $id, 'cliente_id' => $id, 'provedor' => $provedor];
}

$storeInativo = new ExecutorStoreFake();
$storeInativo->reserva = ['estado' => 'desativada'];
$updaterInativo = new ExecutorUpdaterFake();
$resultadoInativo = (new AtualizacaoAutomaticaExecutor($storeInativo, $updaterInativo))->executarUmCiclo();
exigirExecutor($resultadoInativo['estado'] === 'desativada', 'funcao desativada impede ciclo');
exigirExecutor($updaterInativo->processados === [], 'funcao desativada nao consulta provedor');

$storeElegiveis = new ExecutorStoreFake();
$storeElegiveis->sistemas = [sistemaExecutor(1, 'GP TOP'), sistemaExecutor(2, 'OUTRO')];
$updaterElegiveis = new ExecutorUpdaterFake();
$resultadoElegiveis = (new AtualizacaoAutomaticaExecutor(
    $storeElegiveis,
    $updaterElegiveis,
    ['GP' => 5, '*' => 25],
))->executarUmCiclo();
exigirExecutor($updaterElegiveis->processados === [1, 2], 'somente elegiveis fornecidos sao processados');
exigirExecutor($resultadoElegiveis['processados'] === 2, 'total processado e registrado');

$storeLimites = new ExecutorStoreFake();
$storeLimites->sistemas = [
    sistemaExecutor(1, 'GP'), sistemaExecutor(2, 'GP TOP'), sistemaExecutor(3, 'GP'),
    sistemaExecutor(4, 'ALFA'), sistemaExecutor(5, 'ALFA'),
];
$updaterLimites = new ExecutorUpdaterFake();
(new AtualizacaoAutomaticaExecutor(
    $storeLimites,
    $updaterLimites,
    ['GP' => 2, '*' => 1],
))->executarUmCiclo();
exigirExecutor($updaterLimites->processados === [1, 2, 4], 'limites por provedor sao respeitados');

$storeFalha = new ExecutorStoreFake();
$storeFalha->sistemas = [sistemaExecutor(1, 'A'), sistemaExecutor(2, 'B')];
$updaterFalha = new ExecutorUpdaterFake();
$updaterFalha->falharIds = [1];
$resultadoFalha = (new AtualizacaoAutomaticaExecutor($storeFalha, $updaterFalha))->executarUmCiclo();
exigirExecutor($updaterFalha->processados === [1, 2], 'falha isolada nao interrompe ciclo');
exigirExecutor($resultadoFalha['falhas'] === 1 && $resultadoFalha['atualizados'] === 1, 'falha e sucesso sao contabilizados');
exigirExecutor(count($storeFalha->resultados) === 2, 'cada tentativa gera historico');
