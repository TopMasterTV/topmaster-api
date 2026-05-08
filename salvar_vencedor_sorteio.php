<?php
header("Content-Type: application/json");
date_default_timezone_set("America/Sao_Paulo");

$campanha_id = $_REQUEST['campanha_id'] ?? '';
$cliente_id = $_REQUEST['cliente_id'] ?? '';
$nome = $_REQUEST['nome'] ?? '';
$usuario = $_REQUEST['usuario'] ?? '';
$acertos = $_REQUEST['acertos'] ?? '';

if ($campanha_id === '' || $cliente_id === '' || $nome === '') {
    echo json_encode([
        "success" => false,
        "message" => "campanha_id, cliente_id e nome são obrigatórios"
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
        CREATE TABLE IF NOT EXISTS public.enquete_sorteios_vencedores (
            id SERIAL PRIMARY KEY,
            campanha_id INTEGER NOT NULL,
            cliente_id INTEGER NOT NULL,
            nome TEXT NOT NULL,
            usuario TEXT,
            acertos INTEGER DEFAULT 0,
            sorteado_em TIMESTAMP DEFAULT (NOW() AT TIME ZONE 'America/Sao_Paulo')
        )
    ");

    $stmt = $pdo->prepare("
        INSERT INTO public.enquete_sorteios_vencedores
        (
            campanha_id,
            cliente_id,
            nome,
            usuario,
            acertos,
            sorteado_em
        )
        VALUES
        (
            :campanha_id,
            :cliente_id,
            :nome,
            :usuario,
            :acertos,
            (NOW() AT TIME ZONE 'America/Sao_Paulo')
        )
        RETURNING id, campanha_id, cliente_id, nome, usuario, acertos, sorteado_em
    ");

    $stmt->execute([
        ':campanha_id' => $campanha_id,
        ':cliente_id' => $cliente_id,
        ':nome' => $nome,
        ':usuario' => $usuario,
        ':acertos' => $acertos === '' ? 0 : intval($acertos)
    ]);

    $vencedor = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "message" => "Vencedor salvo com sucesso",
        "vencedor" => $vencedor
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao salvar vencedor",
        "error" => $e->getMessage()
    ]);
}
