<?php
header("Content-Type: application/json");

$id = $_POST['id'] ?? '';
$nome_sistema = $_POST['nome_sistema'] ?? '';
$usuario = $_POST['usuario'] ?? '';
$senha = $_POST['senha'] ?? '';
$url = $_POST['url'] ?? '';
$vencimento = $_POST['vencimento'] ?? '';
$m3u_url = $_POST['m3u_url'] ?? '';

if (
    $id === '' ||
    $nome_sistema === '' ||
    $usuario === '' ||
    $senha === '' ||
    $url === '' ||
    $vencimento === ''
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
        "pgsql:host={$db['host']};port=" . ($db['port'] ?? 5432) .
        ";dbname=" . ltrim($db['path'], '/') .
        ";sslmode=require",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->prepare("
        UPDATE sistemas SET
            nome_sistema = :nome_sistema,
            usuario = :usuario,
            senha = :senha,
            url = :url,
            vencimento = :vencimento,
            m3u_url = :m3u_url
        WHERE id = :id
    ");

    $stmt->execute([
        ':id' => $id,
        ':nome_sistema' => $nome_sistema,
        ':usuario' => $usuario,
        ':senha' => $senha,
        ':url' => $url,
        ':vencimento' => $vencimento,
        ':m3u_url' => $m3u_url
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Sistema atualizado com sucesso"
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao atualizar sistema",
        "error" => $e->getMessage()
    ]);
}
