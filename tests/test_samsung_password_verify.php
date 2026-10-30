<?php

declare(strict_types=1);

$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
unset($_SERVER['HTTP_ORIGIN']);

require_once dirname(__DIR__) . '/samsung_common.php';

$password = 'Senha-Ficticia-32!';
$hash = password_hash($password, PASSWORD_DEFAULT);
$checks = [
    'hash correto' => samsungVerifyStoredClientPassword($password, $hash),
    'hash incorreto' => !samsungVerifyStoredClientPassword('incorreta', $hash),
    'legado correto' => samsungVerifyStoredClientPassword($password, $password),
    'legado incorreto' => !samsungVerifyStoredClientPassword('incorreta', $password),
];

foreach ($checks as $name => $passed) {
    fwrite(STDOUT, ($passed ? 'OK: ' : 'FALHA: ') . $name . PHP_EOL);
    if (!$passed) { exit(1); }
}
