<?php
header("Content-Type: application/json");

$revendedor_id = $_POST['revendedor_id'] ?? $_GET['revendedor_id'] ?? '';

if ($revendedor_id == '') {
    echo json_encode([
        "success" => false,
        "message" => "revendedor_id obrigatório"
    ]);
    exit;
}

$DATABASE_URL = getenv("DATABASE_URL");

if (!$DATABASE_URL) {
    echo json_encode([
        "success" => false,
        "message" => "DATABASE_URL não encontrada"
    ]);
    exit;
}

$db = parse_url($DATABASE_URL);

$host = $db['host'];
$dbname = ltrim($db['path'], '/');
$user = $db['user'];
$pass = $db['pass'];

try {
    $pdo = new PDO(
        "pgsql:host=$host;dbname=$dbname;sslmode=require",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao conectar ao banco"
    ]);
    exit;
}

// 🔥 BUSCA CLIENTES DO REVENDEDOR
$stmt = $pdo->prepare("
    SELECT * FROM clientes
    WHERE revendedor_id = :revendedor_id
    ORDER BY nome ASC
");

$stmt->execute([
    ':revendedor_id' => $revendedor_id
]);

$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 🔥 PARA CADA CLIENTE → CALCULAR SISTEMAS E VENCIMENTO
foreach ($clientes as &$cliente) {

    $stmtSis = $pdo->prepare("
        SELECT vencimento FROM sistemas
        WHERE cliente_id = :cliente_id
    ");

    $stmtSis->execute([
        ':cliente_id' => $cliente['id']
    ]);

    $sistemas = $stmtSis->fetchAll(PDO::FETCH_ASSOC);

    $cliente['total_sistemas'] = count($sistemas);

    $maiorVencimento = null;

    foreach ($sistemas as $s) {

        if (!empty($s['vencimento'])) {

            $data = strtotime($s['vencimento']);

            if ($maiorVencimento === null || $data > $maiorVencimento) {
                $maiorVencimento = $data;
            }
        }
    }

    if ($maiorVencimento) {
        $cliente['vencimento_principal'] = date('Y-m-d', $maiorVencimento);
    } else {
        $cliente['vencimento_principal'] = null;
    }
}

echo json_encode([
    "success" => true,
    "clientes" => $clientes
]);
