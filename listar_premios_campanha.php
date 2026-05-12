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
            p.*,
            ep.codigo AS vencedor_codigo
        FROM public.enquete_premios p
        LEFT JOIN public.enquete_participacoes ep
            ON ep.id = p.vencedor_participacao_id
        WHERE p.campanha_id = :campanha_id
        ORDER BY p.ordem ASC, p.id ASC
    ");

    $stmt->execute([
        ':campanha_id' => $campanha_id
    ]);

    $premios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($premios as &$premio) {
        $premio['vencedor_codigo_texto'] =
            !empty($premio['vencedor_codigo'])
                ? "Código " . $premio['vencedor_codigo']
                : "";
    }

    echo json_encode([
        "success" => true,
        "total" => count($premios),
        "premios" => $premios
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar prêmios",
        "error" => $e->getMessage()
    ]);
}
