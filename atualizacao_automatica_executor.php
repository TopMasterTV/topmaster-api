<?php

declare(strict_types=1);

interface AtualizacaoAutomaticaExecutorStore
{
    /** @return array{estado:string,ciclo_id?:int,versao_ciclo?:int,agendado_para?:string} */
    public function reservarCiclo(): array;

    /** @return list<array<string,mixed>> */
    public function listarSistemasElegiveis(int $limiteBusca): array;

    /** @param array<string,mixed> $sistema @param array<string,mixed> $resultado */
    public function registrarResultado(int $cicloId, array $sistema, array $resultado): void;

    /** @param array{elegiveis:int,processados:int,atualizados:int,falhas:int} $totais */
    public function finalizarCiclo(int $cicloId, array $totais): void;
}

interface AtualizacaoAutomaticaSistemaUpdater
{
    /** @param array<string,mixed> $sistema @return array<string,mixed> */
    public function atualizar(array $sistema): array;
}

final class AtualizacaoAutomaticaExecutor
{
    /** @param array<string,int> $limitesPorProvedor */
    public function __construct(
        private readonly AtualizacaoAutomaticaExecutorStore $store,
        private readonly AtualizacaoAutomaticaSistemaUpdater $updater,
        private readonly array $limitesPorProvedor = ['GP' => 5, '*' => 25],
        private readonly int $limiteBusca = 200,
    ) {
    }

    /** @return array<string,int|string> */
    public function executarUmCiclo(): array
    {
        $reserva = $this->store->reservarCiclo();
        if (($reserva['estado'] ?? '') !== 'reservado') {
            return ['estado' => (string) ($reserva['estado'] ?? 'indisponivel')];
        }

        $cicloId = (int) $reserva['ciclo_id'];
        $candidatos = $this->store->listarSistemasElegiveis($this->limiteBusca);
        $selecionados = $this->aplicarLimites($candidatos);
        $atualizados = 0;
        $falhas = 0;

        foreach ($selecionados as $sistema) {
            try {
                $resultado = $this->updater->atualizar($sistema);
                $status = (string) ($resultado['status'] ?? 'falha');
                if ($status === 'falha') {
                    $falhas++;
                } else {
                    $atualizados++;
                }
            } catch (Throwable $e) {
                $falhas++;
                $resultado = [
                    'status' => 'falha',
                    'codigo_falha' => 'UPDATER_EXCEPTION',
                    'detalhe_falha' => 'Falha isolada ao consultar o provedor',
                    'elegivel_novamente_em' => '+1 hour',
                ];
            }
            $this->store->registrarResultado($cicloId, $sistema, $resultado);
        }

        $totais = [
            'elegiveis' => count($candidatos),
            'processados' => count($selecionados),
            'atualizados' => $atualizados,
            'falhas' => $falhas,
        ];
        $this->store->finalizarCiclo($cicloId, $totais);
        return ['estado' => 'concluido', 'ciclo_id' => $cicloId] + $totais;
    }

    /** @param list<array<string,mixed>> $sistemas @return list<array<string,mixed>> */
    private function aplicarLimites(array $sistemas): array
    {
        $usados = [];
        $selecionados = [];
        foreach ($sistemas as $sistema) {
            $provedor = strtoupper(trim((string) ($sistema['provedor'] ?? 'SEM_PROVEDOR')));
            $chave = str_starts_with($provedor, 'GP') ? 'GP' : $provedor;
            $limite = $this->limitesPorProvedor[$chave]
                ?? $this->limitesPorProvedor['*']
                ?? 1;
            $usados[$chave] = ($usados[$chave] ?? 0) + 1;
            if ($usados[$chave] <= $limite) {
                $selecionados[] = $sistema;
            }
        }
        return $selecionados;
    }
}

final class AtualizacaoAutomaticaPdoStore implements AtualizacaoAutomaticaExecutorStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function reservarCiclo(): array
    {
        $this->pdo->beginTransaction();
        try {
            $config = $this->pdo->query(
                'SELECT ativada, proxima_execucao, versao_ciclo
                 FROM atualizacao_automatica_config WHERE id = 1 FOR UPDATE'
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($config) || !in_array($config['ativada'], [true, 1, '1', 't'], true)) {
                $this->pdo->commit();
                return ['estado' => 'desativada'];
            }

            $agendado = new DateTimeImmutable((string) $config['proxima_execucao']);
            $agora = new DateTimeImmutable('now');
            if ($agendado > $agora) {
                $this->pdo->commit();
                return ['estado' => 'aguardando_hora_cheia'];
            }

            $inserir = $this->pdo->prepare(
                'INSERT INTO atualizacao_automatica_ciclos (versao_ciclo, agendado_para)
                 VALUES (:versao, :agendado)
                 ON CONFLICT (versao_ciclo, agendado_para) DO NOTHING
                 RETURNING id'
            );
            $inserir->execute([
                ':versao' => (int) $config['versao_ciclo'],
                ':agendado' => $agendado->format(DateTimeInterface::ATOM),
            ]);
            $cicloId = $inserir->fetchColumn();

            $this->pdo->exec(
                "UPDATE atualizacao_automatica_config
                 SET proxima_execucao = date_trunc(
                     'hour', clock_timestamp() AT TIME ZONE 'America/Sao_Paulo'
                 ) AT TIME ZONE 'America/Sao_Paulo' + INTERVAL '1 hour',
                 atualizado_em = clock_timestamp()
                 WHERE id = 1"
            );
            $this->pdo->commit();
            if ($cicloId === false) {
                return ['estado' => 'ciclo_ja_reservado'];
            }
            return [
                'estado' => 'reservado',
                'ciclo_id' => (int) $cicloId,
                'versao_ciclo' => (int) $config['versao_ciclo'],
                'agendado_para' => $agendado->format(DateTimeInterface::ATOM),
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function listarSistemasElegiveis(int $limiteBusca): array
    {
        $sql = <<<'SQL'
            SELECT
                s.id AS sistema_id,
                s.cliente_id,
                s.usuario,
                s.senha,
                s.m3u_url,
                s.vencimento AS vencimento_anterior,
                COALESCE(m.url_padrao, '') AS url,
                COALESCE(b.nome, m.nome, 'SEM_PROVEDOR') AS provedor
            FROM sistemas AS s
            INNER JOIN clientes AS c ON c.id = s.cliente_id
            LEFT JOIN modelos_sistemas AS m ON m.id = s.modelo_id
            LEFT JOIN provedor_blocos AS b ON b.id = m.bloco_id
            LEFT JOIN LATERAL (
                SELECT r.elegivel_novamente_em
                FROM atualizacao_automatica_resultados AS r
                WHERE r.sistema_id = s.id
                ORDER BY r.verificado_em DESC
                LIMIT 1
            ) AS ultimo ON TRUE
            WHERE (s.vencimento IS NULL OR s.vencimento <= CURRENT_DATE)
              AND (ultimo.elegivel_novamente_em IS NULL
                   OR ultimo.elegivel_novamente_em <= clock_timestamp())
            ORDER BY s.vencimento ASC NULLS FIRST, s.id ASC
            LIMIT :limite
            SQL;
        $consulta = $this->pdo->prepare($sql);
        $consulta->bindValue(':limite', $limiteBusca, PDO::PARAM_INT);
        $consulta->execute();
        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    public function registrarResultado(int $cicloId, array $sistema, array $resultado): void
    {
        $intervalo = (string) ($resultado['elegivel_novamente_em'] ?? '+24 hours');
        $proxima = (new DateTimeImmutable('now'))->modify($intervalo);
        $sql = <<<'SQL'
            INSERT INTO atualizacao_automatica_resultados (
                ciclo_id, cliente_id, sistema_id, provedor, status,
                vencimento_anterior, vencimento_novo, codigo_falha,
                detalhe_falha, elegivel_novamente_em
            ) VALUES (
                :ciclo_id, :cliente_id, :sistema_id, :provedor, :status,
                :anterior, :novo, :codigo, :detalhe, :proxima
            )
            SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':ciclo_id' => $cicloId,
            ':cliente_id' => (int) $sistema['cliente_id'],
            ':sistema_id' => (int) $sistema['sistema_id'],
            ':provedor' => (string) $sistema['provedor'],
            ':status' => (string) ($resultado['status'] ?? 'falha'),
            ':anterior' => $sistema['vencimento_anterior'] ?? null,
            ':novo' => $resultado['vencimento_novo'] ?? null,
            ':codigo' => $resultado['codigo_falha'] ?? null,
            ':detalhe' => $resultado['detalhe_falha'] ?? null,
            ':proxima' => $proxima->format(DateTimeInterface::ATOM),
        ]);
    }

    public function finalizarCiclo(int $cicloId, array $totais): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE atualizacao_automatica_ciclos
             SET finalizado_em = clock_timestamp(), status = 'concluido',
                 total_elegiveis = :elegiveis, total_processados = :processados,
                 total_atualizados = :atualizados, total_falhas = :falhas
             WHERE id = :id"
        );
        $stmt->execute($totais + [':id' => $cicloId]);
    }
}

final class AtualizacaoAutomaticaXtreamUpdater implements AtualizacaoAutomaticaSistemaUpdater
{
    public function __construct(private readonly PDO $pdo, private readonly int $timeout = 15)
    {
    }

    public function atualizar(array $sistema): array
    {
        $url = rtrim(trim((string) ($sistema['url'] ?? '')), '/');
        $usuario = trim((string) ($sistema['usuario'] ?? ''));
        $senha = trim((string) ($sistema['senha'] ?? ''));
        if ($url === '' || $usuario === '' || $senha === '') {
            return $this->falha('CREDENCIAIS_INCOMPLETAS', 'URL, usuario ou senha ausente');
        }

        $api = $url . '/player_api.php?username=' . rawurlencode($usuario)
            . '&password=' . rawurlencode($senha);
        $contexto = stream_context_create(['http' => [
            'timeout' => $this->timeout,
            'ignore_errors' => true,
            'header' => "User-Agent: TopMaster-Automacao/1.0\r\n",
        ]]);
        $resposta = @file_get_contents($api, false, $contexto);
        if (!is_string($resposta) || $resposta === '') {
            return $this->falha('PROVEDOR_INDISPONIVEL', 'Provedor nao respondeu');
        }
        $dados = json_decode($resposta, true);
        $expDate = is_array($dados) ? ($dados['user_info']['exp_date'] ?? null) : null;
        if (!is_numeric($expDate) || (int) $expDate <= 0) {
            return $this->falha('RESPOSTA_SEM_VENCIMENTO', 'Resposta sem vencimento valido');
        }

        $vencimento = (new DateTimeImmutable('@' . (int) $expDate))
            ->setTimezone(new DateTimeZone('America/Sao_Paulo'))
            ->format('Y-m-d');
        $status = $vencimento >= (new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d')
            ? 'Active'
            : 'Expired';
        $stmt = $this->pdo->prepare(
            'UPDATE sistemas SET status = :status, exp_date = :exp_date,
             vencimento = :vencimento WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $status,
            ':exp_date' => (int) $expDate,
            ':vencimento' => $vencimento,
            ':id' => (int) $sistema['sistema_id'],
        ]);
        return [
            'status' => $vencimento === ($sistema['vencimento_anterior'] ?? null)
                ? 'sem_alteracao'
                : 'atualizado',
            'vencimento_novo' => $vencimento,
            'elegivel_novamente_em' => '+24 hours',
        ];
    }

    /** @return array<string,string> */
    private function falha(string $codigo, string $detalhe): array
    {
        return [
            'status' => 'falha',
            'codigo_falha' => $codigo,
            'detalhe_falha' => $detalhe,
            'elegivel_novamente_em' => '+1 hour',
        ];
    }
}
