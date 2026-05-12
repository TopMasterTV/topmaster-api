<?php
header("Content-Type: application/json");
date_default_timezone_set("America/Sao_Paulo");

$campanha_id = $_REQUEST['campanha_id'] ?? '';
$cliente_id = $_REQUEST['cliente_id'] ?? '';
$participacao_id = $_REQUEST['participacao_id'] ?? '';

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

    $stmtCampanha = $pdo->prepare("
        SELECT
            id,
            titulo,
            descricao,
            resultado_titulo,
            resultado_descricao,
            resultado_link,
            resultado_publicado
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

    $stmtEnquetes = $pdo->prepare("
        SELECT
            id,
            pergunta
        FROM public.enquetes
        WHERE campanha_id = :campanha_id
        ORDER BY id ASC
    ");

    $stmtEnquetes->execute([
        ':campanha_id' => $campanha_id
    ]);

    $enquetes = $stmtEnquetes->fetchAll(PDO::FETCH_ASSOC);

    $resultados = [];

    $total_enquetes = count($enquetes);
    $total_respondidas = 0;
    $total_acertos = 0;
    $total_erros = 0;

    foreach ($enquetes as $enquete) {
        $enquete_id = $enquete['id'];

        if ($participacao_id !== '') {
            $stmtRespostas = $pdo->prepare("
                SELECT
                    er.opcao_id,
                    o.texto
                FROM public.enquete_respostas er
                INNER JOIN public.enquete_opcoes o
                    ON o.id = er.opcao_id
                WHERE er.enquete_id = :enquete_id
                AND er.cliente_id = :cliente_id
                AND er.participacao_id = :participacao_id
                ORDER BY er.opcao_id ASC
            ");

            $stmtRespostas->execute([
                ':enquete_id' => $enquete_id,
                ':cliente_id' => $cliente_id,
                ':participacao_id' => $participacao_id
            ]);
        } else {
            $stmtRespostas = $pdo->prepare("
                SELECT
                    er.opcao_id,
                    o.texto
                FROM public.enquete_respostas er
                INNER JOIN public.enquete_opcoes o
                    ON o.id = er.opcao_id
                WHERE er.enquete_id = :enquete_id
                AND er.cliente_id = :cliente_id
                AND er.participacao_id IS NULL
                ORDER BY er.opcao_id ASC
            ");

            $stmtRespostas->execute([
                ':enquete_id' => $enquete_id,
                ':cliente_id' => $cliente_id
            ]);
        }

        $respostas_cliente = $stmtRespostas->fetchAll(PDO::FETCH_ASSOC);

        $stmtCorretas = $pdo->prepare("
            SELECT
                oc.opcao_id AS id,
                o.texto
            FROM public.enquete_opcoes_corretas oc
            INNER JOIN public.enquete_opcoes o
                ON o.id = oc.opcao_id
            WHERE oc.enquete_id = :enquete_id
            ORDER BY oc.opcao_id ASC
        ");

        $stmtCorretas->execute([
            ':enquete_id' => $enquete_id
        ]);

        $opcoes_corretas = $stmtCorretas->fetchAll(PDO::FETCH_ASSOC);

        $ids_corretas = array_map(function ($item) {
            return (int)$item['id'];
        }, $opcoes_corretas);

        $respondeu = count($respostas_cliente) > 0;
        $acertou = false;

        if ($respondeu) {
            $total_respondidas++;

            foreach ($respostas_cliente as $resposta) {
                if (in_array((int)$resposta['opcao_id'], $ids_corretas)) {
                    $acertou = true;
                    break;
                }
            }

            if ($acertou) {
                $total_acertos++;
            } else {
                $total_erros++;
            }
        }

        $resultados[] = [
            "enquete_id" => (int)$enquete_id,
            "pergunta" => $enquete['pergunta'],
            "respondeu" => $respondeu,
            "acertou" => $acertou,

            "respostas_cliente" => array_map(function ($item) {
                return [
                    "opcao_id" => (int)$item['opcao_id'],
                    "texto" => $item['texto']
                ];
            }, $respostas_cliente),

            "resposta_cliente_texto" => implode(", ", array_map(function ($item) {
                return $item['texto'];
            }, $respostas_cliente)),

            "opcoes_corretas" => array_map(function ($item) {
                return [
                    "id" => (int)$item['id'],
                    "texto" => $item['texto']
                ];
            }, $opcoes_corretas),

            "opcoes_corretas_texto" => implode(", ", array_map(function ($item) {
                return $item['texto'];
            }, $opcoes_corretas))
        ];
    }

    echo json_encode([
        "success" => true,
        "campanha" => [
            "id" => (int)$campanha['id'],
            "titulo" => $campanha['titulo'],
            "descricao" => $campanha['descricao'],
            "resultado_titulo" => $campanha['resultado_titulo'],
            "resultado_descricao" => $campanha['resultado_descricao'],
            "resultado_link" => $campanha['resultado_link'],
            "resultado_publicado" => (
                $campanha['resultado_publicado'] === true ||
                $campanha['resultado_publicado'] === 't' ||
                $campanha['resultado_publicado'] === '1'
            )
        ],
        "resumo" => [
            "total_enquetes" => $total_enquetes,
            "total_respondidas" => $total_respondidas,
            "total_acertos" => $total_acertos,
            "total_erros" => $total_erros
        ],
        "resultados" => $resultados
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao carregar resultado do cliente",
        "error" => $e->getMessage()
    ]);
}
