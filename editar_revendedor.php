<?php
header("Content-Type: application/json");

/* =========================
   RECEBE DADOS
   ========================= */
$id       = $_POST['id']       ?? '';
$nome     = $_POST['nome']     ?? '';
$usuario  = $_POST['usuario']  ?? '';
$senha    = $_POST['senha']    ?? '';
$whatsapp = $_POST['whatsapp'] ?? '';

if ($id === '' || $nome === '' || $usuario === '' || $senha === '' || $whatsapp === '') {
    echo json_encode([
        "success" => false,
        "message" => "Dados incompletos"
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
   VERIFICA SE USUÁRIO JÁ EXISTE (OUTRO ID)
   ========================= */
$check = $pdo->prepare("
    SELECT id FROM admins
    WHERE usuario = :usuario
      AND id <> :id
      AND tipo = 'revendedor'
");

$check->execute([
    ':usuario' => $usuario,
    ':id'      => $id
]);

if ($check->fetch()) {
    echo json_encode([
        "success" => false,
        "message" => "Usuário já existe"
    ]);
    exit;
}

/* =========================
   ATUALIZA REVENDEDOR
   ========================= */
$hash = password_hash($senha, PASSWORD_DEFAULT);

$upd = $pdo->prepare("
    UPDATE admins
    SET nome = :nome,
        usuario = :usuario,
        senha = :senha,
        whatsapp = :whatsapp
    WHERE id = :id
      AND tipo = 'revendedor'
");

$upd->execute([
    ':nome'     => $nome,
    ':usuario'  => $usuario,
    ':senha'    => $hash,
    ':whatsapp' => $whatsapp,
    ':id'       => $id
]);

echo json_encode([
    "success" => true
]);
exit;
