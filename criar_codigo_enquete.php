<?php
header("Content-Type: application/json");

$campanha_id = $_REQUEST['campanha_id'] ?? '';
$cliente_id  = $_REQUEST['cliente_id'] ?? '';
$codigo      = trim($_REQUEST['codigo'] ?? '');

if ($campanha_id === '' || $cliente_id === '' || $codigo === '') {
    echo json_encode([
        "success" => false,
        "message" => "campanha_id, cliente_id e codigo são obrigatórios"
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
        INSERT INTO public.enquete_codigos
        (campanha_id, cliente_id, codigo)
        VALUES
        (:campanha_id, :cliente_id, :codigo)
        RETURNING id
    ");

    $stmt->execute([
        ':campanha_id' => $campanha_id,
        ':cliente_id' => $cliente_id,
        ':codigo' => $codigo
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Código criado com sucesso",
        "codigo_id" => $stmt->fetchColumn()
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao criar código. Talvez este código já esteja em uso nesta campanha.",
        "error" => $e->getMessage()
    ]);
}
