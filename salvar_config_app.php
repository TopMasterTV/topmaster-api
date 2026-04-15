<?php
header('Content-Type: application/json');

$qr_link = $_POST['qr_link'] ?? '';

if (empty($qr_link)) {
    echo json_encode(["success" => false, "msg" => "Link vazio"]);
    exit;
}

/* =========================
   CONEXÃO COM BANCO (RENDER)
   ========================= */
$DATABASE_URL = getenv("DATABASE_URL");

if (!$DATABASE_URL) {
    echo json_encode([
        "success" => false,
        "msg" => "DATABASE_URL não definida"
    ]);
    exit;
}

$db = parse_url($DATABASE_URL);

$host   = $db['host'];
$port   = $db['port'] ?? 5432;
$dbname = ltrim($db['path'], '/');
$user   = $db['user'];
$pass   = $db['pass'];

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    // 🔥 UPDATE
    $sql = "UPDATE configuracoes_app SET qr_link = :qr_link WHERE id = 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':qr_link' => $qr_link
    ]);

    echo json_encode([
        "success" => true,
        "msg" => "Link atualizado"
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "msg" => $e->getMessage()
    ]);
}
?>
