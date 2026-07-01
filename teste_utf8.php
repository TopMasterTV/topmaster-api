<?php
header("Content-Type: application/json; charset=UTF-8");

echo json_encode([
    "texto1" => "Votação",
    "texto2" => "Participação",
    "texto3" => "Opção",
    "texto4" => "Classificação"
], JSON_UNESCAPED_UNICODE);
