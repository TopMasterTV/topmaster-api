<?php

header('Content-Type: application/json');

include 'conexao.php';

try {

    // =========================
    // RECEBER DADOS
    // =========================

    $campanha_id = $_POST['campanha_id'] ?? null;
    $enquete_id = $_POST['enquete_id'] ?? null;

    $cliente_id = $_POST['cliente_id'] ?? null;

    $participacao_id = $_POST['participacao_id'] ?? null;

    $opcoes_ids = $_POST['opcoes_ids'] ?? '';

    if (
        empty($campanha_id) ||
        empty($enquete_id) ||
        empty($cliente_id) ||
        empty($opcoes_ids)
    ) {
        echo json_encode([
            "success" => false,
            "message" => "Dados obrigatórios ausentes"
        ]);
        exit;
    }

    // =========================
    // BUSCAR CAMPANHA
    // =========================

    $sqlCampanha = "
        SELECT *
        FROM enquete_campanhas
        WHERE id = :id
        LIMIT 1
    ";

    $stmtCampanha = $pdo->prepare($sqlCampanha);
    $stmtCampanha->bindParam(':id', $campanha_id);
    $stmtCampanha->execute();

    $campanha = $stmtCampanha->fetch(PDO::FETCH_ASSOC);

    if (!$campanha) {
        echo json_encode([
            "success" => false,
            "message" => "Campanha não encontrada"
        ]);
        exit;
    }

    // =========================
    // VERIFICAR ENCERRAMENTO
    // =========================

    $agora = date('Y-m-d H:i:s');

    $encerramento = $campanha['data_encerramento'] . ' ' . $campanha['hora_encerramento'];

    if ($agora > $encerramento) {
        echo json_encode([
            "success" => false,
            "message" => "Campanha encerrada"
        ]);
        exit;
    }

    // =========================
    // BUSCAR ENQUETE
    // =========================

    $sqlEnquete = "
        SELECT *
        FROM enquetes
        WHERE id = :id
        LIMIT 1
    ";

    $stmtEnquete = $pdo->prepare($sqlEnquete);
    $stmtEnquete->bindParam(':id', $enquete_id);
    $stmtEnquete->execute();

    $enquete = $stmtEnquete->fetch(PDO::FETCH_ASSOC);

    if (!$enquete) {
        echo json_encode([
            "success" => false,
            "message" => "Enquete não encontrada"
        ]);
        exit;
    }

    // =========================
    // VALIDAR LIMITE
    // =========================

    $listaOpcoes = explode(',', $opcoes_ids);

    $listaOpcoes = array_filter($listaOpcoes);

    $maxOpcoes = intval($enquete['max_opcoes']);

    if (count($listaOpcoes) > $maxOpcoes) {

        echo json_encode([
            "success" => false,
            "message" => "Limite de opções excedido"
        ]);

        exit;
    }

    // =========================
    // VERIFICAR MODO
    // =========================

    $modo = $campanha['modo_participacao'];

    // =========================
    // REMOVER VOTOS ANTIGOS
    // =========================

    if ($modo == 'livre') {

        $sqlDelete = "
            DELETE FROM enquete_respostas
            WHERE enquete_id = :enquete_id
            AND cliente_id = :cliente_id
        ";

        $stmtDelete = $pdo->prepare($sqlDelete);

        $stmtDelete->bindParam(':enquete_id', $enquete_id);
        $stmtDelete->bindParam(':cliente_id', $cliente_id);

        $stmtDelete->execute();

    } else {

        if (empty($participacao_id)) {

            echo json_encode([
                "success" => false,
                "message" => "Participação obrigatória"
            ]);

            exit;
        }

        $sqlDelete = "
            DELETE FROM enquete_respostas
            WHERE enquete_id = :enquete_id
            AND participacao_id = :participacao_id
        ";

        $stmtDelete = $pdo->prepare($sqlDelete);

        $stmtDelete->bindParam(':enquete_id', $enquete_id);
        $stmtDelete->bindParam(':participacao_id', $participacao_id);

        $stmtDelete->execute();
    }

    // =========================
    // SALVAR RESPOSTAS
    // =========================

    foreach ($listaOpcoes as $opcao_id) {

        $sqlInsert = "
            INSERT INTO enquete_respostas (
                enquete_id,
                opcao_id,
                cliente_id,
                participacao_id
            )
            VALUES (
                :enquete_id,
                :opcao_id,
                :cliente_id,
                :participacao_id
            )
        ";

        $stmtInsert = $pdo->prepare($sqlInsert);

        $stmtInsert->bindParam(':enquete_id', $enquete_id);
        $stmtInsert->bindParam(':opcao_id', $opcao_id);
        $stmtInsert->bindParam(':cliente_id', $cliente_id);

        if ($participacao_id) {
            $stmtInsert->bindParam(':participacao_id', $participacao_id);
        } else {
            $stmtInsert->bindValue(':participacao_id', null, PDO::PARAM_NULL);
        }

        $stmtInsert->execute();
    }

    // =========================
    // SUCESSO
    // =========================

    echo json_encode([
        "success" => true,
        "message" => "Voto salvo com sucesso"
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
