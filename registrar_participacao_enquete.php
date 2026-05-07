<?php
header("Content-Type: application/json");

$campanha_id = $_REQUEST['campanha_id'] ?? '';
$cliente_id  = $_REQUEST['cliente_id'] ?? '';
$codigo      = trim($_REQUEST['codigo'] ?? '');

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

    // verifica se já existe participação
    $check = $pdo->prepare("
        SELECT id
        FROM public.enquete_participacoes
        WHERE campanha_id = :campanha_id
        AND cliente_id = :cliente_id
        AND (
            (:codigo = '' AND codigo IS NULL)
            OR codigo = :codigo
        )
        LIMIT 1
    ");

    $check->execute([
        ':campanha_id' => $campanha_id,
        ':cliente_id' => $cliente_id,
        ':codigo' => $codigo
    ]);

    $participacao = $check->fetch(PDO::FETCH_ASSOC);

    // já existe
    if ($participacao) {

        echo json_encode([
            "success" => true,
            "message" => "Participação já existente",
            "participacao_id" => $participacao['id']
        ]);

        exit;
    }

    // cria nova participação
    $insert = $pdo->prepare("
        INSERT INTO public.enquete_participacoes
        (
            campanha_id,
            cliente_id,
            codigo
        )
        VALUES
        (
            :campanha_id,
            :cliente_id,
            :codigo
        )
        RETURNING id
    ");

    $insert->execute([
        ':campanha_id' => $campanha_id,
        ':cliente_id' => $cliente_id,
        ':codigo' => ($codigo === '' ? null : $codigo)
    ]);

    $nova = $insert->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "message" => "Participação criada",
        "participacao_id" => $nova['id']
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "Erro ao registrar participação",
        "error" => $e->getMessage()
    ]);
}
