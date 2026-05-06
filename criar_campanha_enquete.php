<?php
header("Content-Type: application/json");

$titulo = $_REQUEST['titulo'] ?? '';
$descricao = $_REQUEST['descricao'] ?? '';
$encerra_em = $_REQUEST['encerra_em'] ?? '';

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

        ':resultado_titulo' => $resultado_titulo,
        ':resultado_descricao' => $resultado_descricao,
        ':resultado_link' => $resultado_link,
        ':resultado_publicado' => $resultado_publicado_bool
    ]);

    $campanha_id = $stmt->fetchColumn();

    echo json_encode([
        "success" => true,
        "message" => "Campanha criada com sucesso",
        "campanha_id" => $campanha_id
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "Erro ao criar campanha",
        "error" => $e->getMessage()
    ]);
}
