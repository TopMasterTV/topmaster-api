<?php

declare(strict_types=1);

$ok = true;
$baseDirectory = dirname(__DIR__);
$targets = [
    [
        'path' => $baseDirectory . DIRECTORY_SEPARATOR . 'alterar_tabela_clientes_admin.php',
        'code' => 'ALTERAR_TABELA_CLIENTES_ADMIN_INTERNAL_ERROR',
        'catch_type' => 'Exception',
        'json_responses' => 2,
        'structural_operations' => 1,
        'prepared_statements' => 0,
        'transaction_calls' => 0,
        'existence_markers' => 1,
        'password_hash_calls' => 0,
        'dependencies' => 1,
    ],
    [
        'path' => $baseDirectory . DIRECTORY_SEPARATOR . 'atualizar_clientes_admin_padrao.php',
        'code' => 'ATUALIZAR_CLIENTES_ADMIN_PADRAO_INTERNAL_ERROR',
        'catch_type' => 'Exception',
        'json_responses' => 2,
        'structural_operations' => 1,
        'prepared_statements' => 0,
        'transaction_calls' => 0,
        'existence_markers' => 0,
        'password_hash_calls' => 0,
        'dependencies' => 1,
    ],
    [
        'path' => $baseDirectory . DIRECTORY_SEPARATOR . 'criar_admin.php',
        'code' => 'CRIAR_ADMIN_INTERNAL_ERROR',
        'catch_type' => 'PDOException',
        'json_responses' => 3,
        'structural_operations' => 1,
        'prepared_statements' => 1,
        'transaction_calls' => 0,
        'existence_markers' => 0,
        'password_hash_calls' => 1,
        'dependencies' => 1,
    ],
];

try {
    foreach ($targets as $target) {
        $source = is_file($target['path']) ? file_get_contents($target['path']) : false;
        if (!is_string($source) || $source === '') {
            $ok = false;
            continue;
        }

        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (ParseError) {
            $ok = false;
            continue;
        }

        $tokenText = '';
        foreach ($tokens as $token) {
            $tokenText .= is_array($token) ? $token[1] : $token;
        }

        $catchPattern = '/\bcatch\s*\(\s*' . preg_quote($target['catch_type'], '/') .
            '\s+\$[A-Za-z_][A-Za-z0-9_]*\s*\)\s*\{/';
        $catchCount = preg_match_all($catchPattern, $tokenText);
        $catchStart = preg_match($catchPattern, $tokenText, $catchMatch, PREG_OFFSET_CAPTURE);
        $catchBody = null;

        if ($catchStart === 1) {
            $openingBrace = strpos($tokenText, '{', $catchMatch[0][1]);
            if ($openingBrace !== false) {
                $depth = 0;
                $length = strlen($tokenText);
                for ($index = $openingBrace; $index < $length; $index++) {
                    if ($tokenText[$index] === '{') {
                        $depth++;
                    } elseif ($tokenText[$index] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $catchBody = substr($tokenText, $openingBrace + 1, $index - $openingBrace - 1);
                            break;
                        }
                    }
                }
            }
        }

        $exceptionDetails = '/->\s*(?:getMessage|getTrace|getTraceAsString|getFile|getLine)\s*\(/i';
        $observableThrowable = '/(?:echo|print|printf|die|exit|json_encode)\s*(?:\(|)\s*\$[A-Za-z_][A-Za-z0-9_]*/i';
        $serializedThrowable = '/\bserialize\s*\(\s*\$[A-Za-z_][A-Za-z0-9_]*/i';
        $diagnosticFields = '/[\'"](?:detail|details|internal_message|exception|trace|debug|sql|query|ddl|file|line|username|password|password_hash)[\'"]\s*=>/i';
        $sensitiveCatchData = '/\b(?:password_hash|bindValue|bindParam|execute)\s*\(/i';
        $structuralOperations = preg_match_all('/\b(?:CREATE|ALTER|SELECT|INSERT|UPDATE|DELETE|DROP)\b/i', $tokenText);
        $preparedStatements = preg_match_all('/->\s*prepare\s*\(/', $tokenText);
        $transactionCalls = preg_match_all('/\b(?:beginTransaction|commit|rollBack)\s*\(/', $tokenText);
        $existenceMarkers = preg_match_all('/\b(?:EXISTS|rowCount|fetch)\b/i', $tokenText);
        $passwordHashCalls = preg_match_all('/\bpassword_hash\s*\(/', $tokenText);
        $jsonResponses = preg_match_all('/\bjson_encode\s*\(/', $tokenText);
        $dependencies = preg_match_all('/\b(?:include|include_once|require|require_once)\b/', $tokenText);

        $ok = $ok
            && $tokenText === $source
            && $catchCount === 1
            && is_string($catchBody)
            && substr_count($source, $target['code']) === 1
            && preg_match('/\bhttp_response_code\s*\(\s*500\s*\)\s*;/', $catchBody) === 1
            && strpos($catchBody, $target['code']) !== false
            && preg_match('/\bjson_encode\s*\(/', $catchBody) === 1
            && preg_match($exceptionDetails, $catchBody) === 0
            && preg_match($observableThrowable, $catchBody) === 0
            && preg_match($serializedThrowable, $catchBody) === 0
            && preg_match($diagnosticFields, $catchBody) === 0
            && preg_match($sensitiveCatchData, $catchBody) === 0
            && preg_match('/\b(?:error_log|var_dump|print_r|phpinfo)\s*\(/i', $catchBody) === 0
            && preg_match('/\bhttp_response_code\s*\(\s*500\s*\)/', substr($tokenText, 0, $catchMatch[0][1])) === 0
            && $structuralOperations === $target['structural_operations']
            && $preparedStatements === $target['prepared_statements']
            && $transactionCalls === $target['transaction_calls']
            && $existenceMarkers === $target['existence_markers']
            && $passwordHashCalls === $target['password_hash_calls']
            && $jsonResponses === $target['json_responses']
            && $dependencies === $target['dependencies']
            && preg_match('/\b(?:error_log|var_dump|print_r|phpinfo)\s*\(/i', $tokenText) === 0;
    }
} catch (Throwable) {
    $ok = false;
}

if ($ok) {
    echo 'ADMIN_MANAGEMENT_ENDPOINTS_ERROR_SECURITY_TEST_PASS', PHP_EOL;
    exit(0);
}

echo 'ADMIN_MANAGEMENT_ENDPOINTS_ERROR_SECURITY_TEST_FAIL', PHP_EOL;
exit(1);
