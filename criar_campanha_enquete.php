<?php
header("Content-Type: application/json");

$titulo = $_REQUEST['titulo'] ?? '';
$descricao = $_REQUEST['descricao'] ?? '';
$encerra_em = $_REQUEST['encerra_em'] ?? '';

$modo_participacao = $_REQUEST['modo_participacao'] ?? 'codigo';

$tipo_classificacao = $_REQUEST['tipo_classificacao'] ?? 'minimo_acertos';
$minimo_acertos = $_REQUEST['minimo_acertos'] ?? 6;

$resultado_titulo = $_REQUEST['resultado_titulo'] ?? '';
$resultado_descricao = $_REQUEST['resultado_descricao'] ?? '';
$resultado_link = $_REQUEST['resultado_link'] ?? '';
$resultado_publicado = $_REQUEST['resultado_publicado'] ?? '0';

if ($titulo === '' || $encerra_em === '') {
    echo json_encode([
        "success" => false,
        "message" => "titulo e encerra_em são obrigatórios"
    ]);
    exit;
}

if ($modo_participacao !== 'codigo' && $modo_participacao !== 'livre') {
    echo json_encode([
        "success" => false,
        "message" => "modo_participacao inválido"
    ]);
    exit;
}

if (
    $tipo_classificacao !== 'minimo_acertos' &&
    $tipo_classificacao !== 'todos'
) {
    echo json_encode([
        "success" => false,
        "message" => "tipo_classificacao inválido"
    ]);
    exit;
}

if ($tipo_classificacao === 'todos') {
    $minimo_acertos = 0;
} else {
    $minimo_acertos = intval($minimo_acertos);
    if ($minimo_acertos < 1) {
        $minimo_acertos = 1;
    }
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

    $resultado_publicado_bool = (
        $resultado_publicado === '1' ||
        strtolower($resultado_publicado) === 'true'
    );

    $stmt = $pdo->prepare("
        INSERT INTO public.enquete_campanhas
        (
            titulo,
            descricao,
            encerra_em,
            modo_participacao,
            tipo_classificacao,
            minimo_acertos,
            resultado_titulo,
            resultado_descricao,
            resultado_link,
            resultado_publicado
        )
        VALUES
        (
            :titulo,
            :descricao,
            :encerra_em,
            :modo_participacao,
            :tipo_classificacao,
            :minimo_acertos,
            :resultado_titulo,
            :resultado_descricao,
            :resultado_link,
            :resultado_publicado
        )
        RETURNING id
    ");

    $stmt->execute([
        ':titulo' => $titulo,
        ':descricao' => $descricao,
        ':encerra_em' => $encerra_em,
        ':modo_participacao' => $modo_participacao,
        ':tipo_classificacao' => $tipo_classificacao,
        ':minimo_acertos' => $minimo_acertos,
        ':resultado_titulo' => $resultado_titulo,
        ':resultado_descricao' => $resultado_descricao,
        ':resultado_link' => $resultado_link,
        ':resultado_publicado' => $resultado_publicado_bool
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Campanha criada com sucesso",
        "campanha_id" => $stmt->fetchColumn()
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao criar campanha",
        "error" => $e->getMessage()
    ]);
}
