<?php
header("Content-Type: application/json");

$enquete_id = $_REQUEST['enquete_id'] ?? '';

if ($enquete_id === '') {
    echo json_encode([
        "success" => false,
        "message" => "enquete_id obrigatório"
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
            r.cliente_id,
            c.nome AS cliente_nome,
            o.texto AS opcao

        FROM public.enquete_respostas r

        LEFT JOIN public.clientes c
            ON c.id = r.cliente_id

        LEFT JOIN public.enquete_opcoes o
            ON o.id = r.opcao_id

        WHERE r.enquete_id = :enquete_id

        ORDER BY c.nome ASC
    ");

    $stmt->execute([
        ':enquete_id' => $enquete_id
    ]);

    $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "respostas" => $dados
    ]);

} catch (Exception $e) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Erro ao carregar respostas",
        "error" => "RESPOSTAS_ENQUETE_INTERNAL_ERROR"
    ]);
}
