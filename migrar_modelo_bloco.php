<?php

declare(strict_types=1);

require_once __DIR__ . '/provedor_blocos_admin.php';

executarEndpointProvedorBlocos(static function (PDO $pdo, array $dados): array {
    $modeloId = idProvedorBlocos($dados, 'modelo_id');
    $blocoId = idProvedorBlocos($dados, 'bloco_id', true);
    $stmt = $pdo->prepare(
        'UPDATE modelos_sistemas
         SET bloco_id = :bloco_id
         WHERE id = :modelo_id
           AND (:bloco_id_nulo IS NULL OR EXISTS (
               SELECT 1 FROM provedor_blocos WHERE id = :bloco_id_existente
           ))
         RETURNING id, nome, url_padrao, bloco_id'
    );
    $stmt->bindValue(':modelo_id', $modeloId, PDO::PARAM_INT);
    $stmt->bindValue(':bloco_id', $blocoId, $blocoId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':bloco_id_nulo', $blocoId, $blocoId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':bloco_id_existente', $blocoId, $blocoId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->execute();
    $modelo = $stmt->fetch();
    if (!$modelo) {
        throw new ProvedorBlocosException(404, 'MODEL_OR_BLOCK_NOT_FOUND');
    }
    return [200, ['success' => true, 'modelo' => $modelo]];
});
