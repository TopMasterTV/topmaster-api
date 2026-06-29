<?php
header("Content-Type: application/json");
date_default_timezone_set("America/Sao_Paulo");

$campanha_id = $_REQUEST['campanha_id'] ?? '';
$cliente_id = $_REQUEST['cliente_id'] ?? '';

$version_code_cliente = intval($_REQUEST['version_code_cliente'] ?? 0);

if ($campanha_id === '' || $cliente_id === '') {
    echo json_encode([
        "success" => false,
        "message" => "campanha_id e cliente_id são obrigatórios"
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
            id AS participacao_id,
            codigo,
            criado_em
        FROM public.enquete_participacoes
        WHERE campanha_id = :campanha_id
        AND cliente_id = :cliente_id
        ORDER BY codigo ASC, id ASC
    ");

    $stmt->execute([
        ':campanha_id' => $campanha_id,
        ':cliente_id' => $cliente_id
    ]);

    $participacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($participacoes as &$item) {
        $item['participacao_id'] = intval($item['participacao_id']);
        $item['codigo'] = $item['codigo'] ?? '';
        $item['codigo_texto'] = $item['codigo'] !== ''
            ? "Código " . $item['codigo']
            : "Participação livre";
    }

    if (count($participacoes) === 0) {
        $stmtVotosLivres = $pdo->prepare("
            SELECT COUNT(*) AS total
            FROM public.enquete_respostas er
            INNER JOIN public.enquetes e
                ON e.id = er.enquete_id
            WHERE e.campanha_id = :campanha_id
            AND er.cliente_id = :cliente_id
            AND er.participacao_id IS NULL
        ");

        $stmtVotosLivres->execute([
            ':campanha_id' => $campanha_id,
            ':cliente_id' => $cliente_id
        ]);

        $totalVotosLivres = intval($stmtVotosLivres->fetchColumn());

        if ($totalVotosLivres > 0) {
            $participacoes[] = [
                "participacao_id" => "",
                "codigo" => "",
                "criado_em" => null,
                "codigo_texto" => "Participação livre"
            ];
        }
    }

    echo json_encode([
        "success" => true,
        "participacoes" => $participacoes
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar participações",
        "error" => $e->getMessage()
    ]);
}
