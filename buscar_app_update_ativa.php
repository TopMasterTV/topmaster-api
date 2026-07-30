<?php
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$DATABASE_URL = getenv("DATABASE_URL");

if (!$DATABASE_URL) {
    echo json_encode([
        "success" => false,
        "tem_atualizacao" => false,
        "message" => "DATABASE_URL não definida"
    ]);
    exit;
}

$app_tipo = $_GET["app_tipo"] ?? "celular_cliente";

$db = parse_url($DATABASE_URL);

try {
    $pdo = new PDO(
        "pgsql:host={$db['host']};port=" . ($db['port'] ?? 5432) .
        ";dbname=" . ltrim($db['path'], '/') .
        ";sslmode=require",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    /*
     * REGRA CORRETA:
     * Buscar a última configuração salva para esse tipo de app.
     * Se a última estiver ativa = false, não mostra atualização,
     * mesmo que exista uma versão anterior ativa no banco.
     */
    $stmt = $pdo->prepare("
        SELECT *
        FROM public.app_updates
        WHERE app_tipo = :app_tipo
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([
        ":app_tipo" => $app_tipo
    ]);

    $update = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$update) {
        echo json_encode([
            "success" => true,
            "tem_atualizacao" => false,
            "message" => "Nenhuma configuração de atualização encontrada.",
            "app_tipo" => $app_tipo
        ]);
        exit;
    }

    $ativa = filter_var($update["ativa"] ?? false, FILTER_VALIDATE_BOOLEAN);

    /*
     * Se a última configuração estiver desativada,
     * o aviso precisa parar completamente.
     */
    if (!$ativa) {
        echo json_encode([
            "success" => true,
            "tem_atualizacao" => false,
            "message" => "Atualização desativada no painel.",
            "app_tipo" => $app_tipo,
            "ativa" => false
        ]);
        exit;
    }

    $linkApk =
        $update["link_apk"] ??
        $update["link"] ??
        $update["apk_url"] ??
        $update["url_apk"] ??
        $update["link_download"] ??
        $update["download_url"] ??
        "";

    echo json_encode([
        "success" => true,
        "tem_atualizacao" => true,
        "update" => [
            "id" => isset($update["id"]) ? (int)$update["id"] : 0,
            "app_tipo" => $update["app_tipo"] ?? $app_tipo,
            "versao" => $update["versao"] ?? "",
            "version_code" => isset($update["version_code"]) ? (int)$update["version_code"] : 0,
            "mensagem" => $update["mensagem"] ?? "",
            "link_apk" => $linkApk,
            "obrigatoria" => filter_var($update["obrigatoria"] ?? false, FILTER_VALIDATE_BOOLEAN),
            "ativa" => true,
            "criado_em" => $update["criado_em"] ?? null
        ]
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "tem_atualizacao" => false,
        "message" => "BUSCAR_APP_UPDATE_ATIVA_INTERNAL_ERROR"
    ]);
    exit;
}
