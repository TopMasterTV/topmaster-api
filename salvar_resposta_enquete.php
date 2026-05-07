<?php
header("Content-Type: application/json");

$campanha_id = $_REQUEST['campanha_id'] ?? '';
$enquete_id = $_REQUEST['enquete_id'] ?? '';
$cliente_id = $_REQUEST['cliente_id'] ?? '';
$participacao_id = $_REQUEST['participacao_id'] ?? '';
$opcoes_ids = $_REQUEST['opcoes_ids'] ?? '';

if ($campanha_id === '' || $enquete_id === '' || $cliente_id === '' || $opcoes_ids === '') {
    echo json_encode([
        "success" => false,
        "message" => "campanha_id, enquete_id, cliente_id e opcoes_ids são obrigatórios"
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
        SELECT id, ativa, encerra_em, modo_participacao
        FROM public.enquete_campanhas
        WHERE id = :campanha_id
        LIMIT 1
    ");
    $stmtCampanha->execute([':campanha_id' => $campanha_id]);
    $campanha = $stmtCampanha->fetch(PDO::FETCH_ASSOC);

    if (!$campanha) {
        echo json_encode(["success" => false, "message" => "Campanha não encontrada"]);
        exit;
    }

    if ($campanha['ativa'] !== true && $campanha['ativa'] !== 't' && $campanha['ativa'] !== '1') {
        echo json_encode(["success" => false, "message" => "Campanha inativa"]);
        exit;
    }

    $agora = new DateTime();
    $encerra = new DateTime($campanha['encerra_em']);

    if ($agora >= $encerra) {
        echo json_encode(["success" => false, "message" => "Campanha encerrada"]);
        exit;
    }

    $stmtEnquete = $pdo->prepare("
        SELECT id, max_opcoes
        FROM public.enquetes
        WHERE id = :enquete_id
        AND campanha_id = :campanha_id
        AND ativa = true
        LIMIT 1
    ");
    $stmtEnquete->execute([
        ':enquete_id' => $enquete_id,
        ':campanha_id' => $campanha_id
    ]);
    $enquete = $stmtEnquete->fetch(PDO::FETCH_ASSOC);

    if (!$enquete) {
        echo json_encode(["success" => false, "message" => "Enquete não encontrada ou inativa"]);
        exit;
    }

    $opcoes = array_filter(array_map('trim', explode(',', $opcoes_ids)));

    if (count($opcoes) === 0) {
        echo json_encode(["success" => false, "message" => "Nenhuma opção válida enviada"]);
        exit;
    }

    $max_opcoes = intval($enquete['max_opcoes']);

    if (count($opcoes) > $max_opcoes) {
        echo json_encode([
            "success" => false,
            "message" => "Esta enquete permite no máximo {$max_opcoes} opção/opções"
        ]);
        exit;
    }

    foreach ($opcoes as $opcao_id) {
        $stmtOpcao = $pdo->prepare("
            SELECT id
            FROM public.enquete_opcoes
            WHERE id = :opcao_id
            AND enquete_id = :enquete_id
            LIMIT 1
        ");
        $stmtOpcao->execute([
            ':opcao_id' => $opcao_id,
            ':enquete_id' => $enquete_id
        ]);

        if (!$
