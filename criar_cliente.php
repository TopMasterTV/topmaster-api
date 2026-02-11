<?php
header("Content-Type: application/json");

/* =========================
   RECEBE DADOS (GET ou POST)
   ========================= */
$nome            = $_REQUEST['nome'] ?? '';
$usuario         = $_REQUEST['usuario'] ?? '';
$senha           = $_REQUEST['senha'] ?? '';
$m3u_url         = $_REQUEST['m3u_url'] ?? '';
$whatsapp        = $_REQUEST['whatsapp'] ?? '';
$admin_id        = $_REQUEST['admin_id'] ?? '';
$revendedor_id   = $_REQUEST['revendedor_id'] ?? null;
$revendedor_nome = $_REQUEST['revendedor_nome'] ?? null;

if (
    $nome === '' ||
    $usuario === '' ||
    $senha === '' ||
    $m3u_url === '' ||
    $admin_id === ''
) {
    echo json_encode([
        "success" => false,
        "message" => "Todos os campos obrigatórios devem ser preenchidos"
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

/* =========================
   HASH DA SENHA
   ========================= */
$senha_hash = password_hash($senha, PASSWORD_DEFAULT);

/* =========================
   INSERE CLIENTE
   ========================= */
try {
    $stmt = $pdo->prepare("
        INSERT INTO clientes (
            nome,
            usuario,
            senha,
            m3u_url,
            whatsapp,
            admin_id,
            revendedor_id,
            revendedor_nome
        ) VALUES (
            :nome,
            :usuario,
            :senha,
            :m3u_url,
            :whatsapp,
            :admin_id,
            :revendedor_id,
            :revendedor_nome
        )
    ");

    $stmt->execute([
        ':nome'            => $nome,
        ':usuario'         => $usuario,
        ':senha'           => $senha_hash,
        ':m3u_url'         => $m3u_url,
        ':whatsapp'        => $whatsapp,
        ':admin_id'        => $admin_id,
        ':revendedor_id'   => $revendedor_id,
        ':revendedor_nome' => $revendedor_nome
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Cliente criado com sucesso"
    ]);
} catch (PDOException $e) {
    echo json_encode([
        "success" => false,
        "message" => "Erro ao criar cliente"
    ]);
}
