<?php
header("Content-Type: application/json");

require_once (getenv('TOPMASTER_PRIVATE_DIR') ?: __DIR__)
    . '/aviso_cliente_action.php';

$app_tipo = $_REQUEST['app_tipo'] ?? '';
$destino_exato = filter_var(
    $_REQUEST['destino_exato'] ?? false,
    FILTER_VALIDATE_BOOLEAN
);

if ($app_tipo === '') {
    echo json_encode([
        "success" => false,
        "message" => "app_tipo é obrigatório"
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

    if ($destino_exato) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM public.avisos_cliente
            WHERE ativo = true
              AND destino = :app_tipo
            ORDER BY id DESC
            LIMIT 1
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT *
            FROM public.avisos_cliente
            WHERE ativo = true
              AND destino IN ('todos', :app_tipo)
            ORDER BY
                CASE WHEN destino = :app_tipo THEN 0 ELSE 1 END,
                id DESC
            LIMIT 1
        ");
    }

    $stmt->execute([
        ':app_tipo' => $app_tipo
    ]);

    $aviso = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$aviso) {
        echo json_encode([
            "success" => true,
            "aviso" => null
        ]);
        exit;
    }

    $aviso['button_action'] = normalizarAvisoClienteButtonAction(
        $aviso['button_action'] ?? null
    );

    echo json_encode([
        "success" => true,
        "aviso" => $aviso
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Erro ao buscar aviso",
        "error" => "BUSCAR_AVISO_CLIENTE_ATIVO_INTERNAL_ERROR"
    ]);
}
