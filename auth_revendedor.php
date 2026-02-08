<?php
header("Content-Type: application/json");

/* =========================
   RECEBE DADOS
   ========================= */
$usuario = $_REQUEST['usuario'] ?? '';
$senha   = $_REQUEST['senha']   ?? '';

if ($usuario === '' || $senha === '') {
    echo json_encode([
        "success" => false,
        "message" => "Usuário e senha são obrigatórios"
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
        "pgsql:host={$db['host']};port={$db['port']};dbname=" . ltrim($db['path'], '/') . ";sslmode=require",
        $db['user'],
        $db['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao conectar ao banco"
    ]);
    exit;
}

/* =========================
   BUSCA REVENDEDOR
   ========================= */
$stmt = $pdo->prepare("
    SELECT id, nome, usuario, senha
    FROM admins
    WHERE usuario = :usuario
      AND tipo = 'revendedor'
    LIMIT 1
");
$stmt->execute([':usuario' => $usuario]);
$revendedor = $stmt->fetch();

if (!$revendedor) {
    echo json_encode([
        "success" => false,
        "message" => "Usuário ou senha inválidos"
    ]);
    exit;
}

/* =========================
   VERIFICA SENHA (COMPATÍVEL)
   ========================= */
$senha_ok = false;

// Caso 1: senha em hash
if (password_verify($senha, $revendedor['senha'])) {
    $senha_ok = true;
}
// Caso 2: senha antiga em texto puro
elseif ($senha === $revendedor['senha']) {
    $senha_ok = true;

    // Atualiza para hash
    $nova_hash = password_hash($senha, PASSWORD_DEFAULT);
    $upd = $pdo->prepare("UPDATE admins SET senha = :senha WHERE id = :id");
    $upd->execute([
        ':senha' => $nova_hash,
        ':id'    => $revendedor['id']
    ]);
}

if (!$senha_ok) {
    echo json_encode([
        "success" => false,
        "message" => "Usuário ou senha inválidos"
    ]);
    exit;
}

/* =========================
   LOGIN OK
   ========================= */
echo json_encode([
    "success" => true,
    "revendedor" => [
        "id"      => (int) $revendedor['id'],
        "nome"    => $revendedor['nome'],
        "usuario" => $revendedor['usuario']
    ]
]);
exit;
