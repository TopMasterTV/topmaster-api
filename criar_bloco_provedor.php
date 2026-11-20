<?php

declare(strict_types=1);

require_once __DIR__ . '/provedor_blocos_admin.php';

executarEndpointProvedorBlocos(static function (PDO $pdo, array $dados): array {
    $nome = textoProvedorBlocos($dados, 'nome', 100);
    $descricao = textoProvedorBlocos($dados, 'descricao', 500, false);
    $stmt = $pdo->prepare(
        'INSERT INTO provedor_blocos (nome, descricao)
         VALUES (:nome, :descricao)
         RETURNING id, nome, descricao, criado_em, atualizado_em'
    );
    $stmt->execute([':nome' => $nome, ':descricao' => $descricao]);
    return [201, ['success' => true, 'bloco' => $stmt->fetch()]];
});
