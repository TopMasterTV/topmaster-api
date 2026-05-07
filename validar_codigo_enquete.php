<?php
header("Content-Type: application/json");

$codigo = trim($_REQUEST['codigo'] ?? '');

if ($codigo === '') {
    echo json_encode([
        "success" => false,
        "message" => "codigo obrigatório"
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

            camp.titulo AS campanha_titulo,
            camp.descricao AS campanha_descricao,
            camp.ativa AS campanha_ativa,
            camp.encerra_em,

            camp.resultado_titulo,
            camp.resultado_descricao,
            camp.resultado_link,
            camp.resultado_publicado

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

    $agora = new DateTime();
    $encerra = new DateTime($dados['encerra_em']);

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
        "dados" => $dados
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao validar código",
        "error" => $e->getMessage()
    ]);
}
