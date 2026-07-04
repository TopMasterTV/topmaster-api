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
     * IMPORTANTE:
     * Usamos SELECT * para evitar erro caso o nome da coluna do link
     * seja diferente, como link, apk_url, url_apk, link_download etc.
     */
    $stmt = $pdo->prepare("
        SELECT *
        FROM public.app_updates
        WHERE app_tipo = :app_tipo
          AND ativa = TRUE
        ORDER BY version_code DESC, id DESC
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
            "message" => "Nenhuma atualização ativa encontrada.",
            "app_tipo" => $app_tipo
        ]);
        exit;
    }

    /*
     * Aqui tentamos descobrir qual é o nome real da coluna do link no banco.
     * O app continuará recebendo como link_apk.
     */
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
            "ativa" => filter_var($update["ativa"] ?? false, FILTER_VALIDATE_BOOLEAN),
            "criado_em" => $update["criado_em"] ?? null
        ]
    ]);
    exit;

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "tem_atualizacao" => false,
        "message" => "Erro ao buscar atualização: " . $e->getMessage()
    ]);
    exit;
}
