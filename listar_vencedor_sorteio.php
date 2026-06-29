<?php
header("Content-Type: application/json");
date_default_timezone_set("America/Sao_Paulo");

$campanha_id = $_REQUEST['campanha_id'] ?? '';

$version_code_cliente = intval($_REQUEST['version_code_cliente'] ?? 0);

if ($campanha_id === '') {
    echo json_encode([
        "success" => false,
        "message" => "campanha_id é obrigatório"
    ]);
    exit;
}

$DATABASE_URL = getenv("DATABASE_URL");

if (!$DATABASE_URL) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não definida"
    ]);
    exit;
}

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

    $stmtCampanha = $pdo->prepare("
        SELECT
            id,
            exige_versao_minima,
            version_code_minimo,
            mensagem_app_desatualizado
        FROM public.enquete_campanhas
        WHERE id = :campanha_id
        LIMIT 1
    ");

    $stmtCampanha->execute([
        ':campanha_id' => $campanha_id
    ]);

    $campanha = $stmtCampanha->fetch(PDO::FETCH_ASSOC);

    if (!$campanha) {
        echo json_encode([
            "success" => false,
            "message" => "Campanha não encontrada"
        ]);
        exit;
    }

    // Bloqueio por versão mínima da campanha
    if (
        isset($campanha['exige_versao_minima']) &&
        (
            $campanha['exige_versao_minima'] === true ||
            $campanha['exige_versao_minima'] === 't' ||
            $campanha['exige_versao_minima'] === '1' ||
            $campanha['exige_versao_minima'] === 1
        ) &&
        intval($campanha['version_code_minimo']) > $version_code_cliente
    ) {
        echo json_encode([
            "success" => false,
            "update_required" => true,
            "version_code_minimo" => intval($campanha['version_code_minimo']),
            "message" =>
                !empty($campanha['mensagem_app_desatualizado'])
                    ? $campanha['mensagem_app_desatualizado']
                    : "Atualize seu aplicativo para participar desta campanha."
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            campanha_id,
            cliente_id,
            nome,
            usuario,
            acertos,
            TO_CHAR(sorteado_em, 'DD/MM/YYYY HH24:MI') AS sorteado_em
        FROM public.enquete_sorteios_vencedores
        WHERE campanha_id = :campanha_id
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([
        ':campanha_id' => $campanha_id
    ]);

    $vencedor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vencedor) {
        echo json_encode([
            "success" => true,
            "tem_vencedor" => false,
            "message" => "Nenhum vencedor encontrado"
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "tem_vencedor" => true,
        "vencedor" => $vencedor
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar vencedor",
        "error" => $e->getMessage()
    ]);
}
