<?php
header("Content-Type: application/json");

$id      = $_REQUEST['id'] ?? '';
$nome    = $_REQUEST['nome'] ?? '';
$usuario = $_REQUEST['usuario'] ?? '';
$senha   = $_REQUEST['senha'] ?? '';

if ($id === '' || $nome === '' || $usuario === '') {
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
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao conectar ao banco"
    ]);
    exit;
}

try {

    if ($senha !== '') {

        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            UPDATE admins SET
                nome = :nome,
                usuario = :usuario,
                senha = :senha,
                senha_visivel = :senha_visivel
            WHERE id = :id
        ");

        $stmt->execute([
            ':nome' => $nome,
            ':usuario' => $usuario,
            ':senha' => $senha_hash,
            ':senha_visivel' => $senha,
            ':id' => $id
        ]);

    } else {

        $stmt = $pdo->prepare("
            UPDATE admins SET
                nome = :nome,
                usuario = :usuario
            WHERE id = :id
        ");

        $stmt->execute([
            ':nome' => $nome,
            ':usuario' => $usuario,
            ':id' => $id
        ]);
    }

    echo json_encode([
        "success" => true,
        "message" => "Dados atualizados com sucesso"
    ]);

} catch (PDOException $e) {

    echo json_encode([
        "success" => false,
        "message" => "Erro ao atualizar"
    ]);
}
