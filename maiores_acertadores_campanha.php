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
        FROM public.enquetes e
        WHERE e.campanha_id = :campanha_id
        AND EXISTS (
            SELECT 1
            FROM public.enquete_opcoes_corretas oc
            WHERE oc.enquete_id = e.id
        )
    ");

    $stmtTotal->execute([
        ':campanha_id' => $campanha_id
    ]);

    $totalEnquetes = intval($stmtTotal->fetch(PDO::FETCH_ASSOC)['total_enquetes'] ?? 0);

    $stmt = $pdo->prepare("
        WITH respostas_por_enquete AS (
            SELECT
                r.cliente_id,
                r.participacao_id,
                r.enquete_id,
                COUNT(*) AS total_respostas,
                COUNT(*) FILTER (
                    WHERE EXISTS (
                        SELECT 1
                        FROM public.enquete_opcoes_corretas oc
                        WHERE oc.enquete_id = r.enquete_id
                        AND oc.opcao_id = r.opcao_id
                    )
                ) AS respostas_corretas,
                COUNT(*) FILTER (
                    WHERE NOT EXISTS (
                        SELECT 1
                        FROM public.enquete_opcoes_corretas oc
                        WHERE oc.enquete_id = r.enquete_id
                        AND oc.opcao_id = r.opcao_id
                    )
                ) AS respostas_erradas
            FROM public.enquete_respostas r
            INNER JOIN public.enquetes e
                ON e.id = r.enquete_id
            WHERE e.campanha_id = :campanha_id
            AND EXISTS (
                SELECT 1
                FROM public.enquete_opcoes_corretas oc2
                WHERE oc2.enquete_id = e.id
            )
            GROUP BY r.cliente_id, r.participacao_id, r.enquete_id
        ),

        ranking_base AS (
            SELECT
                rpe.cliente_id,
                rpe.participacao_id,
                COUNT(*) AS enquetes_respondidas,
                SUM(rpe.total_respostas) AS total_respostas,
                COUNT(*) FILTER (
                    WHERE rpe.total_respostas > 0
                    AND rpe.respostas_corretas = rpe.total_respostas
                    AND rpe.respostas_erradas = 0
                ) AS acertos
            FROM respostas_por_enquete rpe
            GROUP BY rpe.cliente_id, rpe.participacao_id
        )

        SELECT
            rb.cliente_id,
            rb.participacao_id,
            p.codigo,
            c.nome,
            c.usuario,
            rb.total_respostas,
            rb.enquetes_respondidas,
            rb.acertos
        FROM ranking_base rb
        LEFT JOIN public.clientes c
            ON c.id = rb.cliente_id
        LEFT JOIN public.enquete_participacoes p
            ON p.id = rb.participacao_id
        ORDER BY rb.acertos DESC, c.nome ASC, p.codigo ASC
    ");

    $stmt->execute([
        ':campanha_id' => $campanha_id
    ]);

    $ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $posicao = 1;

    foreach ($ranking as &$item) {
        $item['posicao'] = $posicao;
        $item['posicao_texto'] = $posicao . "º";

        $item['cliente_id'] = intval($item['cliente_id']);
        $item['participacao_id'] = $item['participacao_id'] !== null ? intval($item['participacao_id']) : null;

        $codigoReal = $item['codigo'] ?? '';

        $item['codigo_texto'] = $codigoReal !== ''
            ? "Código " . $codigoReal
            : "Participação livre";

        $item['total_respostas'] = intval($item['total_respostas']);
        $item['enquetes_respondidas'] = intval($item['enquetes_respondidas']);
        $item['acertos'] = intval($item['acertos']);
        $item['classificado_sorteio'] = $item['acertos'] >= intval($minimo_acertos);

        $posicao++;
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
