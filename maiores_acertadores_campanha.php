<?php
header("Content-Type: application/json");
date_default_timezone_set("America/Sao_Paulo");

$campanha_id = $_REQUEST['campanha_id'] ?? '';
$minimo_acertos = $_REQUEST['minimo_acertos'] ?? 6;

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

    $stmtTotal = $pdo->prepare("
        SELECT COUNT(*) AS total_enquetes
        FROM public.enquetes
        WHERE campanha_id = :campanha_id
    ");

    $stmtTotal->execute([
        ':campanha_id' => $campanha_id
    ]);

    $totalEnquetes = intval($stmtTotal->fetch(PDO::FETCH_ASSOC)['total_enquetes'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT
            r.cliente_id,
            c.nome,
            c.usuario,
            COUNT(*) AS total_respostas,
            SUM(
                CASE 
                    WHEN r.opcao_id = e.opcao_correta_id THEN 1 
                    ELSE 0 
                END
            ) AS acertos
        FROM public.enquete_respostas r
        INNER JOIN public.enquetes e
            ON e.id = r.enquete_id
        LEFT JOIN public.clientes c
            ON c.id = r.cliente_id
        WHERE e.campanha_id = :campanha_id
        AND e.opcao_correta_id IS NOT NULL
        GROUP BY r.cliente_id, c.nome, c.usuario
        ORDER BY acertos DESC, c.nome ASC
    ");

    $stmt->execute([
        ':campanha_id' => $campanha_id
    ]);

    $ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($ranking as &$item) {
        $item['total_respostas'] = intval($item['total_respostas']);
        $item['acertos'] = intval($item['acertos']);
        $item['classificado_sorteio'] = $item['acertos'] >= intval($minimo_acertos);
    }

    echo json_encode([
        "success" => true,
        "campanha_id" => intval($campanha_id),
        "total_enquetes" => $totalEnquetes,
        "minimo_acertos" => intval($minimo_acertos),
        "ranking" => $ranking
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao calcular maiores acertadores",
        "error" => $e->getMessage()
    ]);
}
