<?php
header('Content-Type: application/json');

require_once __DIR__ . '/administrative_token_auth.php';

$DATABASE_URL = getenv('DATABASE_URL');

if (!$DATABASE_URL) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não configurada"
    ]);
    exit;
}

$db = parse_url($DATABASE_URL);

$host = $db['host'];
$port = $db['port'] ?? 5432;
$dbname = ltrim($db['path'], '/');
$user = $db['user'];
$pass = $db['pass'];

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // 👉 DEFINA A NOVA SENHA AQUI
    $novaSenha = "123456";

    $hash = password_hash($novaSenha, PASSWORD_DEFAULT);
    if ($hash === false) {
        throw new RuntimeException('Falha ao gerar hash');
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE admins 
        SET senha = :senha 
        WHERE tipo = 'master'
        RETURNING id
    ");

    $stmt->execute([
        ':senha' => $hash
    ]);

    $mastersAtualizados = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($mastersAtualizados as $masterId) {
        revogarTokensAdministrativosDoAtor($pdo, 'master', (int) $masterId);
    }

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Senha do admin master redefinida com sucesso",
        "nova_senha" => $novaSenha
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        "success" => false,
        "message" => "Erro ao resetar senha"
    ]);
}
