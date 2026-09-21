<?php

function normalizarAvisoClienteButtonAction($valor): string
{
    $action = strtolower(trim((string)($valor ?? '')));

    return in_array($action, ['url', 'update'], true) ? $action : 'url';
}
