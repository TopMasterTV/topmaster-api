<?php
header("Content-Type: application/json");

$campanha_id = $_REQUEST['campanha_id'] ?? '';
$cliente_id  = $_REQUEST['cliente_id'] ?? '';
$codigo      = trim($_REQUEST['codigo'] ?? '');

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
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Busca a campanha para validar versão mínima antes de registrar participação
    $stmtCampanha = $pdo->prepare("
        SELECT
            id,
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
