<?php

declare(strict_types=1);

require_once __DIR__ . '/provedor_blocos_admin.php';

executarEndpointProvedorBlocos(static function (PDO $pdo, array $dados): array {
    $id = idProvedorBlocos($dados, 'id');
    $nome = textoProvedorBlocos($dados, 'nome', 100);
    $descricao = textoProvedorBlocos($dados, 'descricao', 500, false);
    $stmt = $pdo->prepare(
        'UPDATE provedor_blocos
         SET nome = :nome, descricao = :descricao, atualizado_em = clock_timestamp()
         WHERE id = :id
         RETURNING id, nome, descricao, criado_em, atualizado_em'
    );
    $stmt->execute([':id' => $id, ':nome' => $nome, ':descricao' => $descricao]);
    $bloco = $stmt->fetch();
    if (!$bloco) {
        throw new ProvedorBlocosException(404, 'BLOCK_NOT_FOUND');
    }
    return [200, ['success' => true, 'bloco' => $bloco]];
});
