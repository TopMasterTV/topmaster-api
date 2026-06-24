<?php
header("Content-Type: application/json");
date_default_timezone_set("America/Sao_Paulo");

$campanha_id = $_REQUEST['campanha_id'] ?? '';
$enquete_id = $_REQUEST['enquete_id'] ?? '';
$cliente_id = $_REQUEST['cliente_id'] ?? '';
$participacao_id = $_REQUEST['participacao_id'] ?? '';
$opcoes_ids = $_REQUEST['opcoes_ids'] ?? '';

if ($campanha_id === '' || $enquete_id === '' || $cliente_id === '' || $opcoes_ids === '') {
    echo json_encode([
        "success" => false,
        "message" => "campanha_id, enquete_id, cliente_id e opcoes_ids são obrigatórios"
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
        SELECT id, ativa, encerra_em, modo_participacao
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

    if (
        $campanha['ativa'] !== true &&
        $campanha['ativa'] !== 't' &&
        $campanha['ativa'] !== '1'
    ) {
        echo json_encode([
            "success" => false,
            "message" => "Campanha inativa"
        ]);
        exit;
    }

    $agora = new DateTime("now", new DateTimeZone("America/Sao_Paulo"));
    $encerra = new DateTime($campanha['encerra_em'], new DateTimeZone("America/Sao_Paulo"));

    if ($agora >= $encerra) {
        echo json_encode([
            "success" => false,
            "message" => "Campanha encerrada",
            "agora" => $agora->format("Y-m-d H:i:s"),
            "encerra_em" => $encerra->format("Y-m-d H:i:s")
        ]);
        exit;
    }

    $stmtEnquete = $pdo->prepare("
        SELECT id, max_opcoes
        FROM public.enquetes
        WHERE id = :enquete_id
        AND campanha_id = :campanha_id
        AND ativa = true
        LIMIT 1
    ");

    $stmtEnquete->execute([
        ':enquete_id' => $enquete_id,
        ':campanha_id' => $campanha_id
    ]);

    $enquete = $stmtEnquete->fetch(PDO::FETCH_ASSOC);

    if (!$enquete) {
        echo json_encode([
            "success" => false,
            "message" => "Enquete não encontrada ou inativa"
        ]);
        exit;
    }

    $opcoes = array_filter(array_map('trim', explode(',', $opcoes_ids)));

    if (count($opcoes) === 0) {
        echo json_encode([
            "success" => false,
            "message" => "Nenhuma opção válida enviada"
        ]);
        exit;
    }

    $max_opcoes = intval($enquete['max_opcoes']);

    if (count($opcoes) !== $max_opcoes) {
        echo json_encode([
            "success" => false,
            "message" => "Esta enquete exige exatamente {$max_opcoes} opção/opções"
        ]);
        exit;
    }

    foreach ($opcoes as $opcao_id) {
        $stmtOpcao = $pdo->prepare("
            SELECT id
            FROM public.enquete_opcoes
            WHERE id = :opcao_id
            AND enquete_id = :enquete_id
            LIMIT 1
        ");

        $stmtOpcao->execute([
            ':opcao_id' => $opcao_id,
            ':enquete_id' => $enquete_id
        ]);

        if (!$stmtOpcao->fetch()) {
            echo json_encode([
                "success" => false,
                "message" => "Opção inválida para esta enquete"
            ]);
            exit;
        }
    }

    $modo = $campanha['modo_participacao'];

    if ($modo === 'codigo') {
        if ($participacao_id === '') {
            echo json_encode([
                "success" => false,
                "message" => "participacao_id é obrigatório para campanha por código"
            ]);
            exit;
        }

        $stmtParticipacao = $pdo->prepare("
            SELECT id
            FROM public.enquete_participacoes
            WHERE id = :participacao_id
            AND campanha_id = :campanha_id
            AND cliente_id = :cliente_id
            LIMIT 1
        ");

        $stmtParticipacao->execute([
            ':participacao_id' => $participacao_id,
            ':campanha_id' => $campanha_id,
            ':cliente_id' => $cliente_id
        ]);

        if (!$stmtParticipacao->fetch()) {
            echo json_encode([
                "success" => false,
                "message" => "Código/participação inválida para este cliente"
            ]);
            exit;
        }
    } else {
        $participacao_id = null;
    }

    $pdo->beginTransaction();

    if ($modo === 'livre') {
        $stmtDelete = $pdo->prepare("
            DELETE FROM public.enquete_respostas
            WHERE enquete_id = :enquete_id
            AND cliente_id = :cliente_id
            AND participacao_id IS NULL
        ");

        $stmtDelete->execute([
            ':enquete_id' => $enquete_id,
            ':cliente_id' => $cliente_id
        ]);
    } else {
        $stmtDelete = $pdo->prepare("
            DELETE FROM public.enquete_respostas
            WHERE enquete_id = :enquete_id
            AND participacao_id = :participacao_id
        ");

        $stmtDelete->execute([
            ':enquete_id' => $enquete_id,
            ':participacao_id' => $participacao_id
        ]);
    }

    foreach ($opcoes as $opcao_id) {
        $stmtInsert = $pdo->prepare("
            INSERT INTO public.enquete_respostas
            (
                enquete_id,
                opcao_id,
                cliente_id,
                participacao_id,
                respondido_em
            )
            VALUES
            (
                :enquete_id,
                :opcao_id,
                :cliente_id,
                :participacao_id,
                (NOW() AT TIME ZONE 'America/Sao_Paulo')
            )
        ");

        $stmtInsert->execute([
            ':enquete_id' => $enquete_id,
            ':opcao_id' => $opcao_id,
            ':cliente_id' => $cliente_id,
            ':participacao_id' => $participacao_id
        ]);
    }

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Voto salvo com sucesso"
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        "success" => false,
        "message" => "Erro ao salvar voto",
        "error" => $e->getMessage()
    ]);
}
