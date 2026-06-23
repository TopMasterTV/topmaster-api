<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

require_once "db.php";

try {

    $input = json_decode(file_get_contents("php://input"), true);

    if (!$input) {
        $input = $_POST;
    }

    $app_tipo = !empty($input["app_tipo"])
        ? trim($input["app_tipo"])
        : "celular_cliente";

    $versao = trim($input["versao"] ?? "");
    $version_code = intval($input["version_code"] ?? 0);
    $mensagem = trim($input["mensagem"] ?? "");
    $url_apk = trim($input["url_apk"] ?? "");

    $obrigatoria = filter_var(
        $input["obrigatoria"] ?? false,
        FILTER_VALIDATE_BOOLEAN
    );

    $ativa = filter_var(
        $input["ativa"] ?? true,
        FILTER_VALIDATE_BOOLEAN
    );

    if (
        empty($versao) ||
        empty($version_code) ||
        empty($mensagem) ||
        empty($url_apk)
    ) {
        throw new Exception("Campos obrigatórios não informados.");
    }

    $pdo->beginTransaction();

    if ($ativa) {
        $sqlDesativar = "
            UPDATE app_updates
            SET ativa = false,
                atualizado_em = NOW()
            WHERE app_tipo = :app_tipo
        ";

        $stmtDesativar = $pdo->prepare($sqlDesativar);
        $stmtDesativar->execute([
            ":app_tipo" => $app_tipo
        ]);
    }

    $sqlInsert = "
        INSERT INTO app_updates (
            app_tipo,
            versao,
            version_code,
            mensagem,
            url_apk,
            obrigatoria,
            ativa,
            criado_em,
            atualizado_em
        )
        VALUES (
            :app_tipo,
            :versao,
            :version_code,
            :mensagem,
            :url_apk,
            :obrigatoria,
            :ativa,
            NOW(),
            NOW()
        )
        RETURNING id
    ";

    $stmt = $pdo->prepare($sqlInsert);

    $stmt->execute([
        ":app_tipo" => $app_tipo,
        ":versao" => $versao,
        ":version_code" => $version_code,
        ":mensagem" => $mensagem,
        ":url_apk" => $url_apk,
        ":obrigatoria" => $obrigatoria,
        ":ativa" => $ativa
    ]);

    $id = $stmt->fetchColumn();

    $pdo->commit();

    echo json_encode([
        "sucesso" => true,
        "id" => $id,
        "mensagem" => "Atualização salva com sucesso."
    ]);

} catch (Exception $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        "sucesso" => false,
        "erro" => $e->getMessage()
    ]);
}
?>
