<?php

header('Content-Type: application/json');

require_once 'conexao.php';

try {

    $codigo = $_GET['codigo'] ?? '';

    if (empty($codigo)) {
        echo json_encode([
            "success" => false,
            "message" => "Código não informado"
        ]);
        exit;
    }

    $sql = "
        SELECT 
            ec.id,
            ec.codigo,
            ec.campanha_id,
            ec.cliente_id,
            ec.ativo,

            c.nome AS cliente_nome,

            camp.nome AS campanha_nome,
            camp.status,
            camp.data_encerramento

        FROM enquete_codigos ec

        INNER JOIN clientes c
            ON c.id = ec.cliente_id

        INNER JOIN enquete_campanhas camp
            ON camp.id = ec.campanha_id

        WHERE ec.codigo = :codigo
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->bindValue(':codigo', $codigo);

    $stmt->execute();

    $dados = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dados) {

        echo json_encode([
            "success" => false,
            "message" => "Código inválido"
        ]);

        exit;
    }

    if (!$dados['ativo']) {

        echo json_encode([
            "success" => false,
            "message" => "Código desativado"
        ]);

        exit;
    }

    if ($dados['status'] !== 'ativa') {

        echo json_encode([
            "success" => false,
            "message" => "Campanha encerrada"
        ]);

        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => "Código válido",
        "dados" => [
            "codigo_id" => $dados['id'],
            "codigo" => $dados['codigo'],

            "cliente_id" => $dados['cliente_id'],
            "cliente_nome" => $dados['cliente_nome'],

            "campanha_id" => $dados['campanha_id'],
            "campanha_nome" => $dados['campanha_nome'],

            "data_encerramento" => $dados['data_encerramento']
        ]
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
