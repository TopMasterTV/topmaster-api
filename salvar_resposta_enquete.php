<?php

header('Content-Type: application/json');

include 'conexao.php';

// ==========================================
// FUSO HORÁRIO BRASIL
// ==========================================
date_default_timezone_set('America/Sao_Paulo');

// ==========================================
// DADOS
// ==========================================
$campanha_id = $_GET['campanha_id'] ?? null;
$enquete_id = $_GET['enquete_id'] ?? null;
$cliente_id = $_GET['cliente_id'] ?? null;
$participacao_id = $_GET['participacao_id'] ?? null;
$opcoes_ids = $_GET['opcoes_ids'] ?? null;

// ==========================================
// VALIDAÇÕES
// ==========================================
if (
    empty($campanha_id) ||
    empty($enquete_id) ||
    empty($cliente_id) ||
    empty($opcoes_ids)
) {
    echo json_encode([
        'success' => false,
        'message' => 'Dados obrigatórios'
    ]);
    exit;
}

// ==========================================
// BUSCAR CAMPANHA
// ==========================================
$sqlCampanha = "
SELECT 
    id,
    modo_participacao,
    encerramento_em
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
        'success' => false,
        'message' => 'Campanha não encontrada'
    ]);
    exit;
}

// ==========================================
// VERIFICAR ENCERRAMENTO
// ==========================================
$agora = date('Y-m-d H:i:s');

if (
    !empty($campanha['encerramento_em']) &&
    $agora > $campanha['encerramento_em']
) {
    echo json_encode([
        'success' => false,
        'message' => 'Campanha encerrada',
        'agora' => $agora,
        'encerra_em' => $campanha['encerramento_em']
    ]);
    exit;
}

// ==========================================
// MODO LIVRE
// ==========================================
if ($campanha['modo_participacao'] === 'livre') {

    $sqlExiste = "
    SELECT id
    FROM enquete_respostas
    WHERE campanha_id = :campanha_id
    AND cliente_id = :cliente_id
    LIMIT 1
    ";

    $stmtExiste = $pdo->prepare($sqlExiste);
    $stmtExiste->bindParam(':campanha_id', $campanha_id);
    $stmtExiste->bindParam(':cliente_id', $cliente_id);
    $stmtExiste->execute();

    if ($stmtExiste->fetch()) {
        echo json_encode([
            'success' => false,
            'message' => 'Cliente já votou nesta campanha'
        ]);
        exit;
    }
}

// ==========================================
// MODO CÓDIGO
// ==========================================
if ($campanha['modo_participacao'] === 'codigo') {

    if (empty($participacao_id)) {
        echo json_encode([
            'success' => false,
            'message' => 'participacao_id obrigatório'
        ]);
        exit;
    }

    $sqlExiste = "
    SELECT id
    FROM enquete_respostas
    WHERE participacao_id = :participacao_id
    LIMIT 1
    ";

    $stmtExiste = $pdo->prepare($sqlExiste);
    $stmtExiste->bindParam(':participacao_id', $participacao_id);
    $stmtExiste->execute();

    if ($stmtExiste->fetch()) {
        echo json_encode([
            'success' => false,
            'message' => 'Este código já votou'
        ]);
        exit;
    }
}

// ==========================================
// PROCESSAR OPÇÕES
// ==========================================
$listaOpcoes = explode(',', $opcoes_ids);

foreach ($listaOpcoes as $opcao_id) {

    $opcao_id = trim($opcao_id);

    if (empty($opcao_id)) {
        continue;
    }

    $sqlInsert = "
    INSERT INTO enquete_respostas (
        campanha_id,
        enquete_id,
        opcao_id,
        cliente_id,
        participacao_id,
        respondido_em
    )
    VALUES (
        :campanha_id,
        :enquete_id,
        :opcao_id,
        :cliente_id,
        :participacao_id,
        NOW()
    )
    ";

    $stmtInsert = $pdo->prepare($sqlInsert);

    $stmtInsert->bindParam(':campanha_id', $campanha_id);
    $stmtInsert->bindParam(':enquete_id', $enquete_id);
    $stmtInsert->bindParam(':opcao_id', $opcao_id);
    $stmtInsert->bindParam(':cliente_id', $cliente_id);

    if (empty($participacao_id)) {
        $stmtInsert->bindValue(':participacao_id', null, PDO::PARAM_NULL);
    } else {
        $stmtInsert->bindParam(':participacao_id', $participacao_id);
    }

    $stmtInsert->execute();
}

// ==========================================
// SUCESSO
// ==========================================
echo json_encode([
    'success' => true,
    'message' => 'Resposta salva com sucesso'
]);
