<?php
header("Content-Type: application/json");

require_once __DIR__ . '/administrative_token_auth.php';

function responderErroListagem(int $statusHttp, string $codigo, string $mensagem): void
{
    http_response_code($statusHttp);
    echo json_encode([
        "success" => false,
        "code" => $codigo,
        "message" => $mensagem,
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

try {
    $ator = autenticarTokenAdministrativo($pdo);
} catch (AdministrativeAuthException $e) {
    responderErroListagem(
        $e->getStatusHttp(),
        $e->getCodigoPublico(),
        $e->getMensagemPublica()
    );
}

if ($ator['actor_type'] !== 'revendedor') {
    responderErroListagem(403, 'ACCESS_DENIED', 'Acesso nao autorizado');
}

// 🔥 BUSCA CLIENTES DO REVENDEDOR AUTENTICADO
$stmt = $pdo->prepare("
    SELECT
        id,
        nome,
        usuario,
        whatsapp,
        plano,
        link_pagamento,
        admin_id
    FROM clientes
    WHERE revendedor_id = :revendedor_id
    ORDER BY nome ASC
");

$stmt->execute([
    ':revendedor_id' => $ator['actor_id']
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
