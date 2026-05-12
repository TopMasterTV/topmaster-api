<?php
header("Content-Type: application/json");
date_default_timezone_set("America/Sao_Paulo");

$premio_id = $_REQUEST['premio_id'] ?? '';

if ($premio_id === '') {
    echo json_encode([
        "success" => false,
        "message" => "premio_id obrigatório"
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

    $stmtPremio = $pdo->prepare("
        SELECT *
        FROM public.enquete_premios
        WHERE id = :premio_id
        LIMIT 1
    ");

    $stmtPremio->execute([
        ':premio_id' => $premio_id
    ]);

    $premio = $stmtPremio->fetch(PDO::FETCH_ASSOC);

    if (!$premio) {
        echo json_encode([
            "success" => false,
            "message" => "Prêmio não encontrado"
        ]);
        exit;
    }

    if (!empty($premio['vencedor_cliente_id'])) {
        echo json_encode([
            "success" => false,
            "message" => "Este prêmio já possui vencedor"
        ]);
        exit;
    }

    $campanha_id = $premio['campanha_id'];

    $stmtCampanha = $pdo->prepare("
        SELECT
            tipo_classificacao,
            minimo_acertos
        FROM public.enquete_campanhas
        WHERE id = :campanha_id
        LIMIT 1
    ");

    $stmtCampanha->execute([
        ':campanha_id' => $campanha_id
    ]);

    $campanha = $stmtCampanha->fetch(PDO::FETCH_ASSOC);

    if (!$campanha) {
        echo json_encode([
            "success" => false,
            "message" => "Campanha não encontrada"
        ]);
        exit;
    }

    $tipoClassificacao = $campanha['tipo_classificacao'] ?? 'minimo_acertos';
    $minimoNecessario = intval($campanha['minimo_acertos'] ?? 6);

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

    if ($tipoClassificacao === 'todos') {
        $minimoNecessario = $totalEnquetes;
    }

    $stmtRanking = $pdo->prepare("
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
        WHERE rb.acertos >= :minimo_necessario
        ORDER BY RANDOM()
    ");

    $stmtRanking->execute([
        ':campanha_id' => $campanha_id,
        ':minimo_necessario' => $minimoNecessario
    ]);

    $classificados = $stmtRanking->fetchAll(PDO::FETCH_ASSOC);

    if (count($classificados) === 0) {
        echo json_encode([
            "success" => false,
            "message" => "Nenhum classificado disponível"
        ]);
        exit;
    }

    $disponiveis = [];

    foreach ($classificados as $classificado) {
        $stmtJaGanhou = $pdo->prepare("
            SELECT id
            FROM public.enquete_premios
            WHERE campanha_id = :campanha_id
            AND vencedor_participacao_id = :participacao_id
            LIMIT 1
        ");

        $stmtJaGanhou->execute([
            ':campanha_id' => $campanha_id,
            ':participacao_id' => $classificado['participacao_id']
        ]);

        if (!$stmtJaGanhou->fetch(PDO::FETCH_ASSOC)) {
            $disponiveis[] = $classificado;
        }
    }

    if (count($disponiveis) === 0) {
        echo json_encode([
            "success" => false,
            "message" => "Todos os classificados já ganharam"
        ]);
        exit;
    }

    shuffle($disponiveis);
    $vencedor = $disponiveis[0];

    $stmtUpdate = $pdo->prepare("
        UPDATE public.enquete_premios
        SET
            vencedor_cliente_id = :cliente_id,
            vencedor_nome = :nome,
            vencedor_participacao_id = :participacao_id
        WHERE id = :premio_id
        RETURNING *
    ");

    $stmtUpdate->execute([
        ':cliente_id' => $vencedor['cliente_id'],
        ':nome' => $vencedor['nome'],
        ':participacao_id' => $vencedor['participacao_id'],
        ':premio_id' => $premio_id
    ]);

    $premioAtualizado = $stmtUpdate->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "message" => "Prêmio sorteado com sucesso",
        "tipo_classificacao" => $tipoClassificacao,
        "total_enquetes" => $totalEnquetes,
        "minimo_acertos" => $minimoNecessario,
        "premio" => $premioAtualizado,
        "vencedor" => [
            "cliente_id" => intval($vencedor['cliente_id']),
            "participacao_id" => $vencedor['participacao_id'] !== null ? intval($vencedor['participacao_id']) : null,
            "codigo" => $vencedor['codigo'] ?? '',
            "codigo_texto" => !empty($vencedor['codigo']) ? "Código " . $vencedor['codigo'] : "Participação livre",
            "nome" => $vencedor['nome'],
            "usuario" => $vencedor['usuario'],
            "acertos" => intval($vencedor['acertos']),
            "total_respostas" => intval($vencedor['total_respostas'])
        ]
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "Erro ao sortear prêmio",
        "error" => $e->getMessage()
    ]);
}
