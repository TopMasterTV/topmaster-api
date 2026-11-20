<?php

declare(strict_types=1);

require_once __DIR__ . '/provedor_blocos_admin.php';

executarEndpointProvedorBlocos(static function (PDO $pdo, array $dados): array {
    $stmt = $pdo->query(
        'SELECT b.id, b.nome, b.descricao, b.criado_em, b.atualizado_em,
                COUNT(m.id)::INTEGER AS total_modelos
         FROM provedor_blocos AS b
         LEFT JOIN modelos_sistemas AS m ON m.bloco_id = b.id
         GROUP BY b.id
         ORDER BY b.nome ASC, b.id ASC'
    );
    $semBloco = (int) $pdo->query(
        'SELECT COUNT(*) FROM modelos_sistemas WHERE bloco_id IS NULL'
    )->fetchColumn();
    return [200, [
        'success' => true,
        'blocos' => $stmt->fetchAll(),
        'sem_bloco' => ['id' => null, 'nome' => 'Sem bloco', 'total_modelos' => $semBloco],
    ]];
});
