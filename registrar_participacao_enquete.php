<?php

header("Content-Type: application/json");

include("conexao.php");

try {

    $campanha_id = $_GET['campanha_id'] ?? null;
    $cliente_id  = $_GET['cliente_id'] ?? null;
    $codigo      = $_GET['codigo'] ?? null;

    if (!$campanha_id || !$cliente_id) {
        echo json_encode([
            "success" => false,
            "message" => "Dados obrigatórios"
        ]);
        exit;
    }

    // verifica se já participa
    $check = $pdo->prepare("
        SELECT id
        FROM enquete_participacoes
        WHERE campanha_id = ?
        AND cliente_id = ?
        LIMIT 1
    ");

    $check->execute([
        $campanha_id,
        $cliente_id
    ]);

    $participacao = $check->fetch(PDO::FETCH_ASSOC);

    // já existe
    if ($participacao) {

        echo json_encode([
            "success" => true,
            "message" => "Participação já existente",
            "participacao_id" => $participacao['id']
        ]);

        exit;
    }

    // cria participação
    $insert = $pdo->prepare("
        INSERT INTO enquete_participacoes
        (
            campanha_id,
            cliente_id,
            codigo
        )
        VALUES
        (
            ?, ?, ?
        )
        RETURNING id
    ");

    $insert->execute([
        $campanha_id,
        $cliente_id,
        $codigo
    ]);

    $nova = $insert->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "message" => "Participação criada",
        "participacao_id" => $nova['id']
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "Erro ao registrar participação",
        "error" => $e->getMessage()
    ]);
}
