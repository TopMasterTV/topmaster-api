<?php

declare(strict_types=1);

require_once __DIR__ . '/provedor_blocos_admin.php';

executarEndpointProvedorBlocos(static function (PDO $pdo, array $dados): array {
    $blocoId = idProvedorBlocos($dados, 'id');
    $pdo->beginTransaction();
    try {
        $pdo->exec('LOCK TABLE modelos_sistemas IN SHARE ROW EXCLUSIVE MODE');

        $consultar = $pdo->prepare(
            'SELECT id, (
                SELECT COUNT(*) FROM modelos_sistemas WHERE bloco_id = :bloco_id_contagem
             ) AS total_modelos
             FROM provedor_blocos
             WHERE id = :bloco_id
             FOR UPDATE'
        );
        $consultar->bindValue(':bloco_id_contagem', $blocoId, PDO::PARAM_INT);
        $consultar->bindValue(':bloco_id', $blocoId, PDO::PARAM_INT);
        $consultar->execute();
        $bloco = $consultar->fetch();
        if (!$bloco) {
            throw new ProvedorBlocosException(404, 'BLOCK_NOT_FOUND');
        }

        $totalModelos = (int) $bloco['total_modelos'];
        $transferir = $pdo->prepare(
            'UPDATE modelos_sistemas SET bloco_id = NULL WHERE bloco_id = :bloco_id'
        );
        $transferir->bindValue(':bloco_id', $blocoId, PDO::PARAM_INT);
        $transferir->execute();
        if ($transferir->rowCount() !== $totalModelos) {
            throw new ProvedorBlocosException(409, 'MODEL_COUNT_MISMATCH');
        }

        $excluir = $pdo->prepare('DELETE FROM provedor_blocos WHERE id = :bloco_id');
        $excluir->bindValue(':bloco_id', $blocoId, PDO::PARAM_INT);
        $excluir->execute();
        if ($excluir->rowCount() !== 1) {
            throw new ProvedorBlocosException(409, 'BLOCK_DELETE_CONFLICT');
        }

        $pdo->commit();
        return [200, [
            'success' => true,
            'bloco_id' => $blocoId,
            'modelos_transferidos' => $totalModelos,
        ]];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
});
