<?php
header("Content-Type: application/json");
date_default_timezone_set("America/Sao_Paulo");

$premio_id = $_REQUEST['premio_id'] ?? '';
$minimo_acertos = $_REQUEST['minimo_acertos'] ?? 6;

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

    // BUSCA PRÊMIO
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

    // VERIFICA SE JÁ TEM VENCEDOR
    if (!empty($premio['vencedor_cliente_id'])) {

        echo json_encode([
            "success" => false,
            "message" => "Este prêmio já possui vencedor"
        ]);

        exit;
    }

    $campanha_id = $premio['campanha_id'];

    // BUSCA CLASSIFICADOS
    $stmtRanking = $pdo->prepare("
        SELECT
            r.cliente_id,
            r.participacao_id,
            c.nome,
            c.usuario,

            COUNT(DISTINCT CASE
                WHEN oc.opcao_id IS NOT NULL
                THEN r.enquete_id
            END) AS acertos,

            COUNT(*) AS total_respostas

        FROM public.enquete_respostas r

        INNER JOIN public.enquetes e
            ON e.id = r.enquete_id

        LEFT JOIN public.clientes c
            ON c.id = r.cliente_id

        LEFT JOIN public.enquete_opcoes_corretas oc
            ON oc.enquete_id = r.enquete_id
            AND oc.opcao_id = r.opcao_id

        WHERE e.campanha_id = :campanha_id

        GROUP BY
            r.cliente_id,
            r.participacao_id,
            c.nome,
            c.usuario

        HAVING COUNT(DISTINCT CASE
            WHEN oc.opcao_id IS NOT NULL
            THEN r.enquete_id
        END) >= :minimo_acertos
    ");

    $stmtRanking->execute([
        ':campanha_id' => $campanha_id,
        ':minimo_acertos' => intval($minimo_acertos)
    ]);

    $classificados = $stmtRanking->fetchAll(PDO::FETCH_ASSOC);

    if (count($classificados) === 0) {

        echo json_encode([
            "success" => false,
            "message" => "Nenhum classificado disponível"
        ]);

        exit;
    }

    // REMOVE QUEM JÁ GANHOU
    $disponiveis = [];

    foreach ($classificados as $classificado) {

        $stmtJaGanhou = $pdo->prepare("
            SELECT id
            FROM public.enquete_premios
            WHERE campanha_id = :campanha_id
            AND vencedor_cliente_id = :cliente_id
            LIMIT 1
        ");

        $stmtJaGanhou->execute([
            ':campanha_id' => $campanha_id,
            ':cliente_id' => $classificado['cliente_id']
        ]);

        $jaGanhou = $stmtJaGanhou->fetch(PDO::FETCH_ASSOC);

        if (!$jaGanhou) {
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

    // SORTEIA
    shuffle($disponiveis);

    $vencedor = $disponiveis[0];

    // SALVA VENCEDOR
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

        "premio" => $premioAtualizado,

        "vencedor" => [
            "cliente_id" => intval($vencedor['cliente_id']),
            "participacao_id" => intval($vencedor['participacao_id']),
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
