<?php

declare(strict_types=1);

interface AtualizacaoAutomaticaHistoricoDatabase
{
    /** @param array<string,int|string|null> $parametros */
    public function fetchOne(string $sql, array $parametros = []): ?array;

    /** @param array<string,int|string|null> $parametros @return list<array<string,mixed>> */
    public function fetchAll(string $sql, array $parametros = []): array;
}

final class AtualizacaoAutomaticaHistoricoPdoDatabase implements AtualizacaoAutomaticaHistoricoDatabase
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function fetchOne(string $sql, array $parametros = []): ?array
    {
        $consulta = $this->pdo->prepare($sql);
        $consulta->execute($parametros);
        $linha = $consulta->fetch(PDO::FETCH_ASSOC);
        return is_array($linha) ? $linha : null;
    }

    public function fetchAll(string $sql, array $parametros = []): array
    {
        $consulta = $this->pdo->prepare($sql);
        $consulta->execute($parametros);
        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }
}

final class AtualizacaoAutomaticaHistoricoRepository
{
    public const LIMITE_PADRAO = 20;
    public const LIMITE_MAXIMO = 50;
    public const PAGINA_MAXIMA = 1000000;
    private const TIMEZONE = 'America/Sao_Paulo';

    public function __construct(private readonly AtualizacaoAutomaticaHistoricoDatabase $database)
    {
    }

    /** @return array{ciclos:list<array<string,mixed>>,paginacao:array<string,int>} */
    public function listar(int $pagina = 1, int $limite = self::LIMITE_PADRAO): array
    {
        $this->validarPaginacao($pagina, $limite);
        $total = (int) ($this->database->fetchOne(
            'SELECT COUNT(*) AS total FROM atualizacao_automatica_ciclos'
        )['total'] ?? 0);
        $offset = ($pagina - 1) * $limite;
        $linhas = $this->database->fetchAll(
            <<<'SQL'
            SELECT
                ciclo.id,
                ciclo.versao_ciclo,
                ciclo.agendado_para,
                ciclo.iniciado_em,
                ciclo.finalizado_em,
                ciclo.status,
                ciclo.tipo,
                ciclo.grupo,
                ciclo.total_elegiveis,
                ciclo.total_processados,
                COUNT(DISTINCT resultado.id) AS total_sistemas_consultados,
                COUNT(DISTINCT resultado.id) FILTER (
                    WHERE resultado.status IN ('atualizado', 'sem_alteracao')
                ) AS total_sucessos,
                COUNT(DISTINCT resultado.id) FILTER (
                    WHERE resultado.status = 'falha'
                ) AS total_falhas,
                COUNT(DISTINCT pendencia.cliente_id) AS total_pendencias_atuais
            FROM atualizacao_automatica_ciclos AS ciclo
            LEFT JOIN atualizacao_automatica_resultados AS resultado
                ON resultado.ciclo_id = ciclo.id
            LEFT JOIN atualizacao_automatica_pendencias AS pendencia
                ON pendencia.ciclo_origem_id = ciclo.id
            GROUP BY ciclo.id
            ORDER BY ciclo.agendado_para DESC, ciclo.id DESC
            LIMIT CAST(:limite AS INTEGER)
            OFFSET CAST(:offset AS INTEGER)
            SQL,
            [':limite' => $limite, ':offset' => $offset]
        );

        return [
            'ciclos' => array_map($this->normalizarCiclo(...), $linhas),
            'paginacao' => [
                'pagina' => $pagina,
                'limite' => $limite,
                'total' => $total,
                'total_paginas' => $total === 0 ? 0 : (int) ceil($total / $limite),
            ],
        ];
    }

    /** @return array{ciclo:array<string,mixed>,clientes:list<array<string,mixed>>,pendencias_atuais:list<array<string,mixed>>}|null */
    public function detalhar(int $cicloId): ?array
    {
        if ($cicloId <= 0) {
            throw new InvalidArgumentException('Ciclo invalido');
        }

        $ciclo = $this->database->fetchOne(
            <<<'SQL'
            SELECT
                ciclo.id,
                ciclo.versao_ciclo,
                ciclo.agendado_para,
                ciclo.iniciado_em,
                ciclo.finalizado_em,
                ciclo.status,
                ciclo.tipo,
                ciclo.grupo,
                ciclo.total_elegiveis,
                ciclo.total_processados,
                COUNT(DISTINCT resultado.id) AS total_sistemas_consultados,
                COUNT(DISTINCT resultado.id) FILTER (
                    WHERE resultado.status IN ('atualizado', 'sem_alteracao')
                ) AS total_sucessos,
                COUNT(DISTINCT resultado.id) FILTER (
                    WHERE resultado.status = 'falha'
                ) AS total_falhas,
                COUNT(DISTINCT pendencia.cliente_id) AS total_pendencias_atuais
            FROM atualizacao_automatica_ciclos AS ciclo
            LEFT JOIN atualizacao_automatica_resultados AS resultado
                ON resultado.ciclo_id = ciclo.id
            LEFT JOIN atualizacao_automatica_pendencias AS pendencia
                ON pendencia.ciclo_origem_id = ciclo.id
            WHERE ciclo.id = :ciclo_id
            GROUP BY ciclo.id
            SQL,
            [':ciclo_id' => $cicloId]
        );
        if ($ciclo === null) {
            return null;
        }

        $resultados = $this->database->fetchAll(
            <<<'SQL'
            SELECT
                resultado.cliente_id,
                cliente.nome AS cliente_nome,
                resultado.sistema_id,
                modelo.nome AS sistema_nome,
                resultado.provedor,
                resultado.status,
                resultado.vencimento_anterior,
                resultado.vencimento_novo,
                resultado.codigo_falha,
                resultado.detalhe_falha,
                resultado.verificado_em
            FROM atualizacao_automatica_resultados AS resultado
            INNER JOIN clientes AS cliente ON cliente.id = resultado.cliente_id
            INNER JOIN sistemas AS sistema ON sistema.id = resultado.sistema_id
            LEFT JOIN modelos_sistemas AS modelo ON modelo.id = sistema.modelo_id
            WHERE resultado.ciclo_id = :ciclo_id
            ORDER BY cliente.nome, resultado.cliente_id, resultado.sistema_id
            SQL,
            [':ciclo_id' => $cicloId]
        );
        $pendencias = $this->database->fetchAll(
            <<<'SQL'
            SELECT
                pendencia.cliente_id,
                cliente.nome AS cliente_nome,
                pendencia.grupo_origem,
                pendencia.motivo,
                pendencia.criada_em,
                pendencia.atualizada_em
            FROM atualizacao_automatica_pendencias AS pendencia
            INNER JOIN clientes AS cliente ON cliente.id = pendencia.cliente_id
            WHERE pendencia.ciclo_origem_id = :ciclo_id
            ORDER BY pendencia.criada_em, pendencia.cliente_id
            SQL,
            [':ciclo_id' => $cicloId]
        );

        return [
            'ciclo' => $this->normalizarCiclo($ciclo),
            'clientes' => $this->agruparResultados($resultados),
            'pendencias_atuais' => array_map($this->normalizarPendencia(...), $pendencias),
        ];
    }

    private function validarPaginacao(int $pagina, int $limite): void
    {
        if (
            $pagina < 1
            || $pagina > self::PAGINA_MAXIMA
            || $limite < 1
            || $limite > self::LIMITE_MAXIMO
        ) {
            throw new InvalidArgumentException('Paginacao invalida');
        }
    }

    /** @param array<string,mixed> $linha @return array<string,mixed> */
    private function normalizarCiclo(array $linha): array
    {
        return [
            'id' => (int) $linha['id'],
            'versao_ciclo' => (int) $linha['versao_ciclo'],
            'agendado_para' => $this->formatarHorario($linha['agendado_para'] ?? null),
            'iniciado_em' => $this->formatarHorario($linha['iniciado_em'] ?? null),
            'finalizado_em' => $this->formatarHorario($linha['finalizado_em'] ?? null),
            'status' => (string) $linha['status'],
            'tipo' => ($linha['tipo'] ?? '') === 'recuperacao' ? 'recuperacao' : 'regular',
            'grupo' => (string) $linha['grupo'],
            'total_elegiveis' => (int) $linha['total_elegiveis'],
            'total_clientes_processados' => (int) $linha['total_processados'],
            'total_sistemas_consultados' => (int) $linha['total_sistemas_consultados'],
            'total_sucessos' => (int) $linha['total_sucessos'],
            'total_falhas' => (int) $linha['total_falhas'],
            'total_pendencias_atuais' => (int) $linha['total_pendencias_atuais'],
        ];
    }

    /** @param list<array<string,mixed>> $linhas @return list<array<string,mixed>> */
    private function agruparResultados(array $linhas): array
    {
        $clientes = [];
        foreach ($linhas as $linha) {
            $clienteId = (int) $linha['cliente_id'];
            if (!isset($clientes[$clienteId])) {
                $clientes[$clienteId] = [
                    'cliente_id' => $clienteId,
                    'cliente_nome' => (string) $linha['cliente_nome'],
                    'sistemas' => [],
                ];
            }
            $clientes[$clienteId]['sistemas'][] = [
                'sistema_id' => (int) $linha['sistema_id'],
                'sistema_nome' => isset($linha['sistema_nome']) ? (string) $linha['sistema_nome'] : null,
                'provedor' => (string) $linha['provedor'],
                'status' => (string) $linha['status'],
                'vencimento_anterior' => $linha['vencimento_anterior'],
                'vencimento_novo' => $linha['vencimento_novo'],
                'codigo_falha' => $linha['codigo_falha'],
                'detalhe_falha' => $linha['detalhe_falha'],
                'verificado_em' => $this->formatarHorario($linha['verificado_em'] ?? null),
            ];
        }
        return array_values($clientes);
    }

    /** @param array<string,mixed> $linha @return array<string,mixed> */
    private function normalizarPendencia(array $linha): array
    {
        return [
            'cliente_id' => (int) $linha['cliente_id'],
            'cliente_nome' => (string) $linha['cliente_nome'],
            'grupo_origem' => (string) $linha['grupo_origem'],
            'motivo' => (string) $linha['motivo'],
            'criada_em' => $this->formatarHorario($linha['criada_em'] ?? null),
            'atualizada_em' => $this->formatarHorario($linha['atualizada_em'] ?? null),
        ];
    }

    private function formatarHorario(mixed $valor): ?string
    {
        if (!is_string($valor) || trim($valor) === '') {
            return null;
        }
        return (new DateTimeImmutable($valor))
            ->setTimezone(new DateTimeZone(self::TIMEZONE))
            ->format(DateTimeInterface::ATOM);
    }
}
