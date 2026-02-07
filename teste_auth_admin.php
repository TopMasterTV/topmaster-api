<?php
// LIMPA QUALQUER SAÍDA ANTES
if (ob_get_length()) {
    ob_clean();
}

// HEADERS CORS + JSON
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

// TESTE DIRETO, SEM BANCO
echo json_encode([
    "success" => true,
    "admin_id" => 1,
    "usuario" => "admin",
    "tipo" => "master"
], JSON_UNESCAPED_UNICODE);
exit;
