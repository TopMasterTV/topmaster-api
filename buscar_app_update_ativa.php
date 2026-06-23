<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once "db.php";

$app_tipo = isset($_GET["app_tipo"]) && $_GET["app_tipo"] !== ""
    ? $_GET["app_tipo"]
    : "celular_cliente";

try {
    $sql = "
        SELECT 
            id,
            app_tipo,
            versao,
            version_code,
            mensagem,
            url_apk,
            obrigatoria,
            ativa,
            criado_em,
            atualizado_em
        FROM app_updates
        WHERE app_tipo = :app_tipo
          AND ativa = true
        ORDER BY version_code DESC, id DESC
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ":app_tipo" => $app_tipo
    ]);

    $update = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "sucesso" => true,
        "update" => $update ?: null
    ]);
} catch (Exception $e) {
    echo json_encode([
        "sucesso" => false,
        "erro" => $e->getMessage()
    ]);
}
?>
