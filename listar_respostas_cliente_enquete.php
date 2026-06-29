<?php
header("Content-Type: application/json");

$campanha_id = $_REQUEST['campanha_id'] ?? '';
$cliente_id = $_REQUEST['cliente_id'] ?? '';
$participacao_id = $_REQUEST['participacao_id'] ?? '';

$version_code_cliente = intval($_REQUEST['version_code_cliente'] ?? 0);

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
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );

    $stmtCampanha = $pdo->prepare("
        SELECT
            id,
            modo_participacao,
            exige_versao_minima,
            version_code_minimo,
            mensagem_app_desatualizado
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

    // Bloqueio por versão mínima da campanha
    if (
        isset($campanha['exige_versao_minima']) &&
        (
            $campanha['exige_versao_minima'] === true ||
            $campanha['exige_versao_minima'] === 't' ||
            $campanha['exige_versao_minima'] === '1' ||
            $campanha['exige_versao_minima'] === 1
        ) &&
        intval($campanha['version_code_minimo']) > $version_code_cliente
    ) {
        echo json_encode([
            "success" => false,
            "update_required" => true,
            "version_code_minimo" => intval($campanha['version_code_minimo']),
            "message" =>
                !empty($campanha['mensagem_app_desatualizado'])
                    ? $campanha['mensagem_app_desatualizado']
                    : "Atualize seu aplicativo para participar desta campanha."
        ]);
        exit;
    }

    $modo_participacao = $campanha['modo_participacao'] ?? '';

    // =========================
    // CAMPANHA POR CÓDIGO
    // =========================
    if ($modo_participacao === 'codigo') {

        if ($participacao_id === '') {
            echo json_encode([
                "success" => false,
                "message" => "participacao_id é obrigatório para campanha por código"
            ]);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT
                er.enquete_id,
                er.opcao_id
            FROM public.enquete_respostas er
            INNER JOIN public.enquetes e
                ON e.id = er.enquete_id
            WHERE e.campanha_id = :campanha_id
            AND er.cliente_id = :cliente_id
            AND er.participacao_id = :participacao_id
        ");

        $stmt->execute([
            ':campanha_id' => $campanha_id,
            ':cliente_id' => $cliente_id,
            ':participacao_id' => $participacao_id
        ]);

    }

    // =========================
    // CAMPANHA LIVRE
    // =========================
    else {

        $participacao_id_resolvida = $participacao_id;

        if ($participacao_id_resolvida === '') {
            $stmtParticipacaoLivre = $pdo->prepare("
                SELECT id
                FROM public.enquete_participacoes
                WHERE campanha_id = :campanha_id
                AND cliente_id = :cliente_id
                AND codigo IS NULL
                ORDER BY id ASC
                LIMIT 1
            ");

            $stmtParticipacaoLivre->execute([
                ':campanha_id' => $campanha_id,
                ':cliente_id' => $cliente_id
            ]);

            $participacaoLivre = $stmtParticipacaoLivre->fetch(PDO::FETCH_ASSOC);

            if ($participacaoLivre) {
                $participacao_id_resolvida = $participacaoLivre['id'];
            }
        }

        if ($participacao_id_resolvida !== '') {
            $stmt = $pdo->prepare("
                SELECT
                    er.enquete_id,
                    er.opcao_id
                FROM public.enquete_respostas er
                INNER JOIN public.enquetes e
                    ON e.id = er.enquete_id
                WHERE e.campanha_id = :campanha_id
                AND er.cliente_id = :cliente_id
                AND (
                    er.participacao_id = :participacao_id
                    OR er.participacao_id IS NULL
                )
            ");

            $stmt->execute([
                ':campanha_id' => $campanha_id,
                ':cliente_id' => $cliente_id,
                ':participacao_id' => $participacao_id_resolvida
            ]);
        } else {
            $stmt = $pdo->prepare("
                SELECT
                    er.enquete_id,
                    er.opcao_id
                FROM public.enquete_respostas er
                INNER JOIN public.enquetes e
                    ON e.id = er.enquete_id
                WHERE e.campanha_id = :campanha_id
                AND er.cliente_id = :cliente_id
                AND er.participacao_id IS NULL
            ");

            $stmt->execute([
                ':campanha_id' => $campanha_id,
                ':cliente_id' => $cliente_id
            ]);
        }
    }

    $respostas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "respostas" => $respostas
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "Erro ao listar respostas",
        "error" => $e->getMessage()
    ]);
}
