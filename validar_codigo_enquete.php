<?php
header("Content-Type: application/json");
date_default_timezone_set("America/Sao_Paulo");

$codigo = trim($_REQUEST['codigo'] ?? '');
$cliente_id = $_REQUEST['cliente_id'] ?? '';

$version_code_cliente = intval($_REQUEST['version_code_cliente'] ?? 0);

if ($codigo === '' || $cliente_id === '') {
    echo json_encode([
        "success" => false,
        "message" => "codigo e cliente_id são obrigatórios"
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
            ec.id AS codigo_id,
            ec.codigo,
            ec.campanha_id,
            ec.cliente_id,
            ec.ativo,

            c.nome AS cliente_nome,
            c.usuario AS cliente_usuario,

            camp.titulo AS campanha_titulo,
            camp.descricao AS campanha_descricao,
            camp.ativa AS campanha_ativa,
            camp.encerra_em,

            camp.resultado_titulo,
            camp.resultado_descricao,
            camp.resultado_link,
            camp.resultado_publicado,

            camp.exige_versao_minima,
            camp.version_code_minimo,
            camp.mensagem_app_desatualizado

        FROM public.enquete_codigos ec
        INNER JOIN public.clientes c
            ON c.id = ec.cliente_id
        INNER JOIN public.enquete_campanhas camp
            ON camp.id = ec.campanha_id
        WHERE ec.codigo = :codigo
        LIMIT 1
    ");

    $stmt->execute([
        ':codigo' => $codigo
    ]);

    $dados = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dados) {
        echo json_encode([
            "success" => false,
            "message" => "Código inválido"
        ]);
        exit;
    }

    if (strval($dados['cliente_id']) !== strval($cliente_id)) {
        echo json_encode([
            "success" => false,
            "message" => "Este código é exclusivo de outro cliente",
            "cliente_vinculado" => [
                "id" => $dados['cliente_id'],
                "nome" => $dados['cliente_nome'],
                "usuario" => $dados['cliente_usuario']
            ]
        ]);
        exit;
    }

    // Bloqueio por versão mínima da campanha
    if (
        isset($dados['exige_versao_minima']) &&
        (
            $dados['exige_versao_minima'] === true ||
            $dados['exige_versao_minima'] === 't' ||
            $dados['exige_versao_minima'] === '1' ||
            $dados['exige_versao_minima'] === 1
        ) &&
        intval($dados['version_code_minimo']) > $version_code_cliente
    ) {
        echo json_encode([
            "success" => false,
            "update_required" => true,
            "version_code_minimo" => intval($dados['version_code_minimo']),
            "message" =>
                !empty($dados['mensagem_app_desatualizado'])
                    ? $dados['mensagem_app_desatualizado']
                    : "Atualize seu aplicativo para participar desta campanha."
        ]);
        exit;
    }

    if (
        $dados['ativo'] !== true &&
        $dados['ativo'] !== 't' &&
        $dados['ativo'] !== '1'
    ) {
        echo json_encode([
            "success" => false,
            "message" => "Código desativado"
        ]);
        exit;
    }

    if (
        $dados['campanha_ativa'] !== true &&
        $dados['campanha_ativa'] !== 't' &&
        $dados['campanha_ativa'] !== '1'
    ) {
        echo json_encode([
            "success" => false,
            "message" => "Campanha inativa"
        ]);
        exit;
    }

    $agora = new DateTime("now", new DateTimeZone("America/Sao_Paulo"));
    $encerra = new DateTime($dados['encerra_em'], new DateTimeZone("America/Sao_Paulo"));

    if ($agora >= $encerra) {
        echo json_encode([
            "success" => false,
            "message" => "Campanha encerrada"
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => "Código válido",
        "mensagem_vinculo" => "Código vinculado exclusivamente ao cliente " . $dados['cliente_nome'],
        "dados" => $dados
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao validar código",
        "error" => $e->getMessage()
    ]);
}
