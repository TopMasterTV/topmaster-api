<?php
header("Content-Type: text/plain; charset=UTF-8");

$usuario = $_GET['usuario'] ?? '';
$senha   = $_GET['senha'] ?? '';

if ($usuario === 'admin' && $senha === '123456') {
    echo "success";
} else {
    echo "error";
}
