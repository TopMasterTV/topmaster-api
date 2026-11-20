<?php

declare(strict_types=1);

require_once __DIR__ . '/provedor_blocos_admin.php';

executarEndpointProvedorBlocos(static function (PDO $pdo, array $dados): array {
    $blocoId = idProvedorBlocos($dados, 'bloco_id', true);
    $nomeBloco = 'Sem bloco';
    if ($blocoId === null) {
        $stmt = $pdo->query(
            'SELECT id, nome, url_padrao, bloco_id
             FROM modelos_sistemas
             WHERE bloco_id IS NULL
             ORDER BY nome ASC, id ASC'
        );
    } else {
        $bloco = $pdo->prepare('SELECT nome FROM provedor_blocos WHERE id = :id');
        $bloco->execute([':id' => $blocoId]);
        $nomeBloco = $bloco->fetchColumn();
        if (!is_string($nomeBloco)) {
            throw new ProvedorBlocosException(404, 'BLOCK_NOT_FOUND');
        }
        $stmt = $pdo->prepare(
            'SELECT id, nome, url_padrao, bloco_id
             FROM modelos_sistemas
             WHERE bloco_id = :bloco_id
             ORDER BY nome ASC, id ASC'
        );
        $stmt->execute([':bloco_id' => $blocoId]);
    }
    return [200, [
        'success' => true,
        'bloco_id' => $blocoId,
        'nome_bloco' => $nomeBloco,
        'modelos' => $stmt->fetchAll(),
    ]];
});
