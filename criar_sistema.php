<?php
header("Content-Type: application/json");

$cliente_id = $_POST['cliente_id'] ?? '';
$nome_sistema = $_POST['nome_sistema'] ?? '';
$usuario = $_POST['usuario'] ?? '';
$senha = $_POST['senha'] ?? '';
$url = $_POST['url'] ?? '';
$vencimento = $_POST['vencimento'] ?? '';
$m3u_url = $_POST['m3u_url'] ?? '';

if (
    $cliente_id === '' ||
    $nome_sistema === '' ||
    $usuario === '' ||
    $senha === '' ||
    $url === ''
) {
    echo json_encode([
        "success" => false,
        "message" => "Campos obrigatórios não preenchidos"
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
        "pgsql:host={$db['host']};port=" . ($db['port'] ?? 5432) . ";dbname=" . ltrim($db['path'], '/') . ";sslmode=require",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->prepare("
        INSERT INTO sistemas (
            cliente_id,
            nome_sistema,
            usuario,
            senha,
            url,
            vencimento,
            m3u_url
        ) VALUES (
            :cliente_id,
            :nome_sistema,
            :usuario,
            :senha,
            :url,
            :vencimento,
            :m3u_url
        )
    ");

    $stmt->execute([
        ':cliente_id' => $cliente_id,
        ':nome_sistema' => $nome_sistema,
        ':usuario' => $usuario,
        ':senha' => $senha,
        ':url' => $url,
        ':vencimento' => $vencimento,
        ':m3u_url' => $m3u_url
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Sistema criado com sucesso"
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao criar sistema",
        "error" => $e->getMessage()
    ]);
}
