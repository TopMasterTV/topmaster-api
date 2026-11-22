<?php

declare(strict_types=1);

interface AtualizacaoAutomaticaDatabase
{
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollBack(): void;
    public function inTransaction(): bool;

    /** @param array<string, int|string|null> $parametros */
    public function fetchOne(string $sql, array $parametros = []): ?array;
}

final class AtualizacaoAutomaticaPdoDatabase implements AtualizacaoAutomaticaDatabase
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function beginTransaction(): void
    {
        if (!$this->pdo->beginTransaction()) {
            throw new RuntimeException('Falha ao iniciar transacao');
        }
    }

    public function commit(): void
    {
        if (!$this->pdo->commit()) {
            throw new RuntimeException('Falha ao confirmar transacao');
        }
    }

    public function rollBack(): void
    {
        if (!$this->pdo->rollBack()) {
            throw new RuntimeException('Falha ao reverter transacao');
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function fetchOne(string $sql, array $parametros = []): ?array
    {
        $consulta = $this->pdo->prepare($sql);
        $consulta->execute($parametros);
        $linha = $consulta->fetch(PDO::FETCH_ASSOC);
        return is_array($linha) ? $linha : null;
    }
}

final class AtualizacaoAutomaticaConfigRepository
{
    private const TIMEZONE = 'America/Sao_Paulo';

    public function __construct(private readonly AtualizacaoAutomaticaDatabase $database)
    {
    }

    /** @return array<string, bool|int|string|null> */
    public function consultar(): array
    {
        $estado = $this->database->fetchOne($this->sqlSelecionar(false));
        return $this->normalizarEstado($this->exigirEstado($estado));
    }

    /** @return array{alterada:bool,estado:array<string,bool|int|string|null>} */
    public function ativar(int $adminId): array
    {
        if ($adminId <= 0) {
            throw new InvalidArgumentException('Administrador invalido');
        }

        $this->database->beginTransaction();
        try {
            $atual = $this->exigirEstado(
                $this->database->fetchOne($this->sqlSelecionar(true))
            );

            if ($this->valorBooleano($atual['ativada'] ?? false)) {
                $this->database->commit();
                return [
                    'alterada' => false,
                    'estado' => $this->normalizarEstado($atual),
                ];
            }

            $estado = $this->database->fetchOne(
                <<<'SQL'
                WITH referencia AS (
                    SELECT clock_timestamp() AS agora
                ), calculo AS (
                    SELECT
                        agora,
                        (
                            date_trunc(
                                'hour',
                                agora AT TIME ZONE 'America/Sao_Paulo'
                            ) + INTERVAL '1 hour'
                        ) AT TIME ZONE 'America/Sao_Paulo' AS proxima
                    FROM referencia
                )
                UPDATE atualizacao_automatica_config AS config
                SET ativada = TRUE,
                    ativado_em = calculo.agora,
                    proxima_execucao = calculo.proxima,
                    versao_ciclo = config.versao_ciclo + 1,
                    atualizado_em = calculo.agora,
                    atualizado_por_admin_id = :admin_id
                FROM calculo
                WHERE config.id = 1
                RETURNING
                    config.ativada,
                    config.ativado_em,
                    config.desativado_em,
                    config.proxima_execucao,
                    config.versao_ciclo,
                    config.atualizado_em,
                    config.atualizado_por_admin_id
                SQL,
                [':admin_id' => $adminId]
            );

            $estado = $this->exigirEstado($estado);
            $this->database->commit();
            return [
                'alterada' => true,
                'estado' => $this->normalizarEstado($estado),
            ];
        } catch (Throwable $e) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $e;
        }
    }

    /** @return array{alterada:bool,estado:array<string,bool|int|string|null>} */
    public function desativar(int $adminId): array
    {
        if ($adminId <= 0) {
            throw new InvalidArgumentException('Administrador invalido');
        }

        $this->database->beginTransaction();
        try {
            $atual = $this->exigirEstado(
                $this->database->fetchOne($this->sqlSelecionar(true))
            );

            if (!$this->valorBooleano($atual['ativada'] ?? false)) {
                $this->database->commit();
                return [
                    'alterada' => false,
                    'estado' => $this->normalizarEstado($atual),
                ];
            }

            $estado = $this->database->fetchOne(
                <<<'SQL'
                WITH referencia AS (
                    SELECT clock_timestamp() AS agora
                )
                UPDATE atualizacao_automatica_config AS config
                SET ativada = FALSE,
                    desativado_em = referencia.agora,
                    proxima_execucao = NULL,
                    atualizado_em = referencia.agora,
                    atualizado_por_admin_id = :admin_id
                FROM referencia
                WHERE config.id = 1
                RETURNING
                    config.ativada,
                    config.ativado_em,
                    config.desativado_em,
                    config.proxima_execucao,
                    config.versao_ciclo,
                    config.atualizado_em,
                    config.atualizado_por_admin_id
                SQL,
                [':admin_id' => $adminId]
            );

            $estado = $this->exigirEstado($estado);
            $this->database->commit();
            return [
                'alterada' => true,
                'estado' => $this->normalizarEstado($estado),
            ];
        } catch (Throwable $e) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $e;
        }
    }

    private function sqlSelecionar(bool $bloquear): string
    {
        return <<<'SQL'
            SELECT
                ativada,
                ativado_em,
                desativado_em,
                proxima_execucao,
                versao_ciclo,
                atualizado_em,
                atualizado_por_admin_id
            FROM atualizacao_automatica_config
            WHERE id = 1
            SQL . ($bloquear ? ' FOR UPDATE' : '');
    }

    /** @param array<string,mixed>|null $estado @return array<string,mixed> */
    private function exigirEstado(?array $estado): array
    {
        if ($estado === null) {
            throw new RuntimeException('Configuracao da automacao ausente');
        }
        return $estado;
    }

    private function valorBooleano(mixed $valor): bool
    {
        return $valor === true || $valor === 1 || $valor === '1' || $valor === 't';
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

    /** @param array<string,mixed> $estado @return array<string,bool|int|string|null> */
    private function normalizarEstado(array $estado): array
    {
        return [
            'ativada' => $this->valorBooleano($estado['ativada'] ?? false),
            'ativado_em' => $this->formatarHorario($estado['ativado_em'] ?? null),
            'desativado_em' => $this->formatarHorario($estado['desativado_em'] ?? null),
            'proxima_execucao' => $this->formatarHorario($estado['proxima_execucao'] ?? null),
            'versao_ciclo' => (int) ($estado['versao_ciclo'] ?? 0),
            'atualizado_em' => $this->formatarHorario($estado['atualizado_em'] ?? null),
            'atualizado_por_admin_id' => isset($estado['atualizado_por_admin_id'])
                ? (int) $estado['atualizado_por_admin_id']
                : null,
            'fuso_horario' => self::TIMEZONE,
        ];
    }
}
