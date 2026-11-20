<?php

declare(strict_types=1);

require_once __DIR__ . '/provedor_blocos_admin.php';

executarEndpointProvedorBlocos(static function (PDO $pdo, array $dados): array {
    $blocoId = idProvedorBlocos($dados, 'bloco_id');
    $nome = textoProvedorBlocos($dados, 'nome', 150);
    $url = textoProvedorBlocos($dados, 'url_padrao', 2048);
    $stmt = $pdo->prepare(
        'INSERT INTO modelos_sistemas (nome, url_padrao, bloco_id)
         SELECT :nome, :url_padrao, b.id
         FROM provedor_blocos AS b
         WHERE b.id = :bloco_id
         RETURNING id, nome, url_padrao, bloco_id'
    );
    $stmt->execute([':nome' => $nome, ':url_padrao' => $url, ':bloco_id' => $blocoId]);
    $modelo = $stmt->fetch();
    if (!$modelo) {
        throw new ProvedorBlocosException(404, 'BLOCK_NOT_FOUND');
    }
    return [201, ['success' => true, 'modelo' => $modelo]];
});
