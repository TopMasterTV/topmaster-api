<?php
header("Content-Type: application/json");
date_default_timezone_set("America/Sao_Paulo");

$campanha_id = $_REQUEST['campanha_id'] ?? '';
$nome_premio = trim($_REQUEST['nome_premio'] ?? '');
$ordem = $_REQUEST['ordem'] ?? 1;

if ($campanha_id === '' || $nome_premio === '') {
    echo json_encode([
        "success" => false,
        "message" => "campanha_id e nome_premio são obrigatórios"
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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS public.enquete_premios (
            id SERIAL PRIMARY KEY,
            campanha_id INTEGER NOT NULL,
            nome_premio TEXT NOT NULL,
            ordem INTEGER DEFAULT 1,
            vencedor_cliente_id INTEGER,
            vencedor_nome TEXT,
            criado_em TIMESTAMP DEFAULT (NOW() AT TIME ZONE 'America/Sao_Paulo')
        )
    ");

    $stmt = $pdo->prepare("
        INSERT INTO public.enquete_premios
        (
            campanha_id,
            nome_premio,
            ordem
        )
        VALUES
        (
            :campanha_id,
            :nome_premio,
            :ordem
        )
        RETURNING *
    ");

    $stmt->execute([
        ':campanha_id' => $campanha_id,
        ':nome_premio' => $nome_premio,
        ':ordem' => intval($ordem)
    ]);

    $premio = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "message" => "Prêmio criado com sucesso",
        "premio" => $premio
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "Erro ao criar prêmio",
        "error" => $e->getMessage()
    ]);
}
