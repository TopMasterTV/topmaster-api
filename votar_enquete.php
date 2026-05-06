<?php
header("Content-Type: application/json");

$cliente_id = $_REQUEST['cliente_id'] ?? '';
$enquete_id = $_REQUEST['enquete_id'] ?? '';
$opcoes_raw = $_REQUEST['opcoes'] ?? ($_REQUEST['opcao_id'] ?? '');

if ($cliente_id === '' || $enquete_id === '' || $opcoes_raw === '') {
    echo json_encode([
        "success" => false,
        "message" => "cliente_id, enquete_id e opcoes são obrigatórios"
    ]);
    exit;
}

$opcoes = array_filter(array_map('trim', explode(',', $opcoes_raw)));

if (count($opcoes) === 0) {
    echo json_encode([
        "success" => false,
        "message" => "Nenhuma opção válida enviada"
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

    $stmtEnquete = $pdo->prepare("
        SELECT max_opcoes, ativa
        FROM public.enquetes
        WHERE id = :enquete_id
        LIMIT 1
    ");

    $stmtEnquete->execute([
        ':enquete_id' => $enquete_id
    ]);

    $enquete = $stmtEnquete->fetch(PDO::FETCH_ASSOC);

    if (!$enquete) {
        echo json_encode([
            "success" => false,
            "message" => "Enquete não encontrada"
        ]);
        exit;
    }

    if ($enquete['ativa'] !== true && $enquete['ativa'] !== 't' && $enquete['ativa'] !== '1') {
        echo json_encode([
            "success" => false,
            "message" => "Enquete inativa"
        ]);
        exit;
    }

    $max_opcoes = intval($enquete['max_opcoes']);

    if (count($opcoes) > $max_opcoes) {
        echo json_encode([
            "success" => false,
            "message" => "Você só pode escolher até {$max_opcoes} opção/opções"
        ]);
        exit;
    }

    $check = $pdo->prepare("
        SELECT id
        FROM public.enquete_respostas
        WHERE cliente_id = :cliente_id
        AND enquete_id = :enquete_id
        LIMIT 1
    ");

    $check->execute([
        ':cliente_id' => $cliente_id,
        ':enquete_id' => $enquete_id
    ]);

    if ($check->fetch()) {
        echo json_encode([
            "success" => false,
            "message" => "Cliente já votou nesta enquete"
        ]);
        exit;
    }

    $pdo->beginTransaction();

    foreach ($opcoes as $opcao_id) {
        $stmt = $pdo->prepare("
            INSERT INTO public.enquete_respostas
            (cliente_id, enquete_id, opcao_id)
            VALUES
            (:cliente_id, :enquete_id, :opcao_id)
        ");

        $stmt->execute([
            ':cliente_id' => $cliente_id,
            ':enquete_id' => $enquete_id,
            ':opcao_id' => $opcao_id
        ]);
    }

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Voto registrado com sucesso"
    ]);

} catch (Exception $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }

    echo json_encode([
        "success" => false,
        "message" => "Erro ao votar",
        "error" => $e->getMessage()
    ]);
}
