<?php
header('Content-Type: application/json');
include 'conexao.php';

$qr_link = $_POST['qr_link'] ?? '';

if (empty($qr_link)) {
    echo json_encode(["success" => false, "msg" => "Link vazio"]);
    exit;
}

try {

    // 🔥 Atualiza sempre o ID 1
    $sql = "UPDATE configuracoes_app SET qr_link = ? WHERE id = 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$qr_link]);

    echo json_encode([
        "success" => true,
        "msg" => "Link atualizado"
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "msg" => $e->getMessage()
    ]);
}
?>
