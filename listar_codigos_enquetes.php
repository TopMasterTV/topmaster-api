<?php
header("Content-Type: application/json");

$campanha_id = $_REQUEST['campanha_id'] ?? '';

if ($campanha_id === '') {
    echo json_encode([
        "success" => false,
        "message" => "campanha_id obrigatório"
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
            ec.id,
            ec.campanha_id,
            ec.cliente_id,
            c.nome AS cliente_nome,
            ec.codigo,
            ec.ativo,
            ec.criado_em
        FROM public.enquete_codigos ec
        LEFT JOIN public.clientes c
            ON c.id = ec.cliente_id
        WHERE ec.campanha_id = :campanha_id
        ORDER BY ec.codigo ASC
    ");

    $stmt->execute([
        ':campanha_id' => $campanha_id
    ]);

    echo json_encode([
        "success" => true,
        "codigos" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar códigos",
        "error" => $e->getMessage()
    ]);
}
