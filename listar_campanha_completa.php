<?php
header("Content-Type: application/json");

$campanha_id = $_REQUEST['campanha_id'] ?? '';

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

    if ($campanha_id === '') {
        $stmtCampanha = $pdo->query("
            SELECT *
            FROM public.enquete_campanhas
            WHERE ativa = true
            ORDER BY id DESC
            LIMIT 1
        ");
    } else {
        $stmtCampanha = $pdo->prepare("
            SELECT *
            FROM public.enquete_campanhas
            WHERE id = :id
            LIMIT 1
        ");
        $stmtCampanha->execute([
            ':id' => $campanha_id
        ]);
    }

    $campanha = $stmtCampanha->fetch(PDO::FETCH_ASSOC);

    if (!$campanha) {
        echo json_encode([
            "success" => false,
            "message" => "Nenhuma campanha encontrada"
        ]);
        exit;
    }

    $stmtEnquetes = $pdo->prepare("
        SELECT *
        FROM public.enquetes
        WHERE campanha_id = :campanha_id
        AND ativa = true
        ORDER BY id ASC
    ");

    $stmtEnquetes->execute([
        ':campanha_id' => $campanha['id']
    ]);

    $enquetes = [];

    while ($enquete = $stmtEnquetes->fetch(PDO::FETCH_ASSOC)) {
        $stmtOpcoes = $pdo->prepare("
            SELECT *
            FROM public.enquete_opcoes
            WHERE enquete_id = :enquete_id
            ORDER BY id ASC
        ");

        $stmtOpcoes->execute([
            ':enquete_id' => $enquete['id']
        ]);

        $enquete['opcoes'] = $stmtOpcoes->fetchAll(PDO::FETCH_ASSOC);
        $enquetes[] = $enquete;
    }

    $agora = new DateTime();
    $encerra = new DateTime($campanha['encerra_em']);
    $encerrada = $agora > $encerra;

    echo json_encode([
        "success" => true,
        "campanha" => $campanha,
        "encerrada" => $encerrada,
        "enquetes" => $enquetes
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar campanha",
        "error" => $e->getMessage()
    ]);
}
