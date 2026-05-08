<?php
header("Content-Type: application/json");

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

    $stmtCampanhas = $pdo->query("
        SELECT
            id,
            titulo,
            descricao,
            ativa,
            encerra_em,
            criado_em,
            modo_participacao,
            resultado_titulo,
            resultado_descricao,
            resultado_link,
            resultado_publicado
        FROM public.enquete_campanhas
        ORDER BY id DESC
    ");

    $campanhas = $stmtCampanhas->fetchAll(PDO::FETCH_ASSOC);

    foreach ($campanhas as &$campanha) {
        $stmtEnquetes = $pdo->prepare("
            SELECT
                id,
                pergunta,
                ativa,
                max_opcoes,
                criado_em,
                campanha_id
            FROM public.enquetes
            WHERE campanha_id = :campanha_id
            AND ativa = true
            ORDER BY id ASC
        ");

        $stmtEnquetes->execute([
            ':campanha_id' => $campanha['id']
        ]);

        $enquetes = $stmtEnquetes->fetchAll(PDO::FETCH_ASSOC);

        foreach ($enquetes as &$enquete) {
            $stmtOpcoes = $pdo->prepare("
                SELECT
                    id,
                    enquete_id,
                    texto
                FROM public.enquete_opcoes
                WHERE enquete_id = :enquete_id
                ORDER BY id ASC
            ");

            $stmtOpcoes->execute([
                ':enquete_id' => $enquete['id']
            ]);

            $enquete['opcoes'] = $stmtOpcoes->fetchAll(PDO::FETCH_ASSOC);
        }

        $campanha['enquetes'] = $enquetes;
    }

    echo json_encode([
        "success" => true,
        "campanhas" => $campanhas
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar campanhas",
        "error" => $e->getMessage()
    ]);
}
