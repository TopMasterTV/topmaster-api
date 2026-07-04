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
     * REGRA PRINCIPAL:
     * Só pode retornar atualização quando ativa = true.
     * Se estiver ativa = false, o app não deve receber atualização nenhuma,
     * mesmo que o version_code seja maior.
     */
    $stmt = $pdo->prepare("
        SELECT
            id,
            app_tipo,
            versao,
            version_code,
            mensagem,
            link_apk,
            obrigatoria,
            ativa,
            criado_em
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

    echo json_encode([
        "success" => true,
        "tem_atualizacao" => true,
        "update" => [
            "id" => (int)$update["id"],
            "app_tipo" => $update["app_tipo"],
            "versao" => $update["versao"],
            "version_code" => (int)$update["version_code"],
            "mensagem" => $update["mensagem"],
            "link_apk" => $update["link_apk"],
            "obrigatoria" => filter_var($update["obrigatoria"], FILTER_VALIDATE_BOOLEAN),
            "ativa" => filter_var($update["ativa"], FILTER_VALIDATE_BOOLEAN),
            "criado_em" => $update["criado_em"]
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
