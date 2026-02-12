<?php
header("Content-Type: application/json");

/* =========================
   RECEBE DADOS
   ========================= */
$cliente_id = $_POST['cliente_id'] ?? '';

if ($cliente_id === '') {
    echo json_encode([
        "success" => false,
        "message" => "cliente_id é obrigatório"
    ]);
    exit;
}

/* =========================
   CONEXÃO COM BANCO
   ========================= */
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
        ";dbname=" . ltrim($db['path'], '/') . ";sslmode=require",
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

/* =========================
   BUSCA PLANO DO CLIENTE
   ========================= */
$stmt = $pdo->prepare("
    SELECT plano
    FROM clientes
    WHERE id = :cliente_id
    LIMIT 1
");
$stmt->execute([':cliente_id' => $cliente_id]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cliente) {
    echo json_encode([
        "success" => false,
        "message" => "Cliente não encontrado"
    ]);
    exit;
}

$plano = strtolower(trim($cliente['plano'] ?? ''));

switch ($plano) {
    case 'mensal':
        $dias = 30;
        break;
    case 'trimestral':
        $dias = 90;
        break;
    case 'semestral':
        $dias = 180;
        break;
    case 'anual':
        $dias = 365;
        break;
    default:
        echo json_encode([
            "success" => false,
            "message" => "Plano inválido ou não definido"
        ]);
        exit;
}

/* =========================
   BUSCA SISTEMAS DO CLIENTE
   ========================= */
$stmt = $pdo->prepare("
    SELECT id, vencimento
    FROM sistemas
    WHERE cliente_id = :cliente_id
");
$stmt->execute([':cliente_id' => $cliente_id]);
$sistemas = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$sistemas) {
    echo json_encode([
        "success" => false,
        "message" => "Cliente não possui sistemas"
    ]);
    exit;
}

$hoje = new DateTime();
$hoje->setTime(0, 0, 0);

foreach ($sistemas as $sistema) {

    $vencimentoAtual = new DateTime($sistema['vencimento']);
    $vencimentoAtual->setTime(0, 0, 0);

    if ($vencimentoAtual < $hoje) {
        // Vencido → renova a partir de hoje
        $novaData = clone $hoje;
    } else {
        // Ainda ativo → renova a partir do vencimento atual
        $novaData = clone $vencimentoAtual;
    }

    $novaData->modify("+$dias days");

    $update = $pdo->prepare("
        UPDATE sistemas
        SET vencimento = :nova_data
        WHERE id = :sistema_id
    ");

    $update->execute([
        ':nova_data' => $novaData->format('Y-m-d'),
        ':sistema_id' => $sistema['id']
    ]);
}

echo json_encode([
    "success" => true,
    "message" => "Cliente renovado com sucesso"
]);
exit;
