<?php
header("Content-Type: application/json");

$campanha_id = $_REQUEST['campanha_id'] ?? '';
$version_code_cliente = intval($_REQUEST['version_code_cliente'] ?? 0);

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

    if ($campanha_id !== '') {
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
            SELECT *
            FROM public.enquetes
            WHERE ativa = true
            AND campanha_id = :campanha_id
            ORDER BY id DESC
        ");

        $stmt->execute([
            ':campanha_id' => $campanha_id
        ]);
    } else {
        $stmt = $pdo->query("
            SELECT *
            FROM public.enquetes
            WHERE ativa = true
            ORDER BY id DESC
        ");
    }

    $enquetes = [];

    while ($e = $stmt->fetch(PDO::FETCH_ASSOC)) {

        $stmtOpcoes = $pdo->prepare("
            SELECT *
            FROM public.enquete_opcoes
            WHERE enquete_id = :id
            ORDER BY id ASC
        ");

        $stmtOpcoes->execute([
            ':id' => $e['id']
        ]);

        $e['opcoes'] = $stmtOpcoes->fetchAll(PDO::FETCH_ASSOC);

        $enquetes[] = $e;
    }

    echo json_encode([
        "success" => true,
        "enquetes" => $enquetes
    ]);

} catch (Exception $e) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar enquetes",
        "error" => "LISTAR_ENQUETES_INTERNAL_ERROR"
    ]);
}
