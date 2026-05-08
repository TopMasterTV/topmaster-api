<?php
header("Content-Type: application/json");
date_default_timezone_set("America/Sao_Paulo");

$campanha_id = $_REQUEST['campanha_id'] ?? '';

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
