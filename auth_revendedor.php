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
   CONEXÃO COM BANCO (RENDER)
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

$host = $db['host'];
$port = $db['port'] ?? 5432; // 🔥 CORREÇÃO CRÍTICA
$dbname = ltrim($db['path'], '/');
$user = $db['user'];
$pass = $db['pass'];

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
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
$senhaValida = false;

// senha com hash
if (password_verify($senha, $revendedor['senha'])) {
    $senhaValida = true;
}
// fallback senha antiga
elseif ($senha === $revendedor['senha']) {
    $senhaValida = true;

    // atualiza para hash
    $novoHash = password_hash($senha, PASSWORD_DEFAULT);
    $upd = $pdo->prepare("UPDATE admins SET senha = :senha WHERE id = :id");
    $upd->execute([
        ':senha' => $novoHash,
        ':id'    => $revendedor['id']
    ]);
}

if (!$senhaValida) {
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
