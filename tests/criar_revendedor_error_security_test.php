<?php

declare(strict_types=1);

$ok = true;
$sourcePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'criar_revendedor.php';

try {
    $source = is_file($sourcePath) ? file_get_contents($sourcePath) : false;
    if (!is_string($source) || $source === '') {
        throw new RuntimeException();
    }

    try {
        $tokens = token_get_all($source, TOKEN_PARSE);
    } catch (ParseError) {
        throw new RuntimeException();
    }

    $tokenText = '';
    foreach ($tokens as $token) {
        $tokenText .= is_array($token) ? $token[1] : $token;
    }

    $catchPattern = '/\bcatch\s*\(\s*PDOException\s+\$[A-Za-z_][A-Za-z0-9_]*\s*\)\s*\{/';
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
    $diagnosticFields = '/[\'"](?:detail|details|internal_message|exception|trace|debug|sql|query|file|line|username|password|password_hash)[\'"]\s*=>/i';
    $sensitiveCatchData = '/\b(?:password_hash|bindValue|bindParam|execute)\s*\(/i';
    $structuralOperations = preg_match_all('/\b(?:CREATE|ALTER|SELECT|INSERT|UPDATE|DELETE|DROP)\b/i', $tokenText);
    $preparedStatements = preg_match_all('/->\s*prepare\s*\(/', $tokenText);
    $transactionCalls = preg_match_all('/\b(?:beginTransaction|commit|rollBack)\s*\(/', $tokenText);
    $passwordHashCalls = preg_match_all('/\bpassword_hash\s*\(/', $tokenText);
    $jsonResponses = preg_match_all('/\bjson_encode\s*\(/', $tokenText);
    $dependencies = preg_match_all('/\b(?:include|include_once|require|require_once)\b/', $tokenText);

    $ok = $tokenText === $source
        && $catchCount === 1
        && is_string($catchBody)
        && substr_count($source, 'CRIAR_REVENDEDOR_INTERNAL_ERROR') === 1
        && preg_match('/\bhttp_response_code\s*\(\s*500\s*\)\s*;/', $catchBody) === 1
        && strpos($catchBody, 'CRIAR_REVENDEDOR_INTERNAL_ERROR') !== false
        && preg_match('/\bjson_encode\s*\(/', $catchBody) === 1
        && preg_match($exceptionDetails, $catchBody) === 0
        && preg_match($observableThrowable, $catchBody) === 0
        && preg_match($serializedThrowable, $catchBody) === 0
        && preg_match($diagnosticFields, $catchBody) === 0
        && preg_match($sensitiveCatchData, $catchBody) === 0
        && preg_match('/\b(?:error_log|var_dump|print_r|phpinfo)\s*\(/i', $catchBody) === 0
        && preg_match('/\bhttp_response_code\s*\(\s*500\s*\)/', substr($tokenText, 0, $catchMatch[0][1])) === 0
        && $structuralOperations === 1
        && $preparedStatements === 1
        && $transactionCalls === 0
        && $passwordHashCalls === 0
        && $jsonResponses === 4
        && $dependencies === 0
        && preg_match('/\b(?:error_log|var_dump|print_r|phpinfo)\s*\(/i', $tokenText) === 0;
} catch (Throwable) {
    $ok = false;
}

if ($ok) {
    echo 'CRIAR_REVENDEDOR_ERROR_SECURITY_TEST_PASS', PHP_EOL;
    exit(0);
}

echo 'CRIAR_REVENDEDOR_ERROR_SECURITY_TEST_FAIL', PHP_EOL;
exit(1);
