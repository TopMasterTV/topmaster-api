<?php

declare(strict_types=1);

final class RokuTranscoderHmacException extends RuntimeException
{
    private const ALLOWED_CODES = [
        'ROKU_TRANSCODER_HMAC_INVALID_ARGUMENT',
        'ROKU_TRANSCODER_HMAC_INVALID_METHOD',
        'ROKU_TRANSCODER_HMAC_INVALID_PATH',
        'ROKU_TRANSCODER_HMAC_INVALID_TIMESTAMP',
        'ROKU_TRANSCODER_HMAC_INVALID_NONCE',
        'ROKU_TRANSCODER_HMAC_INVALID_BODY',
        'ROKU_TRANSCODER_HMAC_INVALID_SECRET',
        'ROKU_TRANSCODER_HMAC_INVALID_SIGNATURE',
        'ROKU_TRANSCODER_HMAC_FAILED',
    ];

    public function __construct(string $errorCode)
    {
        if (!in_array($errorCode, self::ALLOWED_CODES, true)) {
            $errorCode = 'ROKU_TRANSCODER_HMAC_FAILED';
        }

        parent::__construct($errorCode);
    }
}

final class RokuTranscoderHmac
{
    private const MAX_BODY_BYTES = 65536;
    private const MIN_SECRET_BYTES = 32;

    private function __construct()
    {
    }

    /**
     * @return array{timestamp:string,nonce:string,signature:string,body_hash:string,headers:array<string,string>}
     */
    public static function sign(
        mixed $method,
        mixed $path,
        mixed $timestamp,
        mixed $nonce,
        mixed $body,
        mixed $hmacSecret
    ): array {
        $normalizedMethod = self::validateMethodAndPath($method, $path);
        $validatedTimestamp = self::validateTimestamp($timestamp);
        $validatedNonce = self::validateNonce($nonce);
        $validatedBody = self::validateBody($normalizedMethod, $body);
        $validatedSecret = self::validateSecret($hmacSecret);
        $bodyHash = self::bodyHash($validatedBody);
        $canonical = self::buildCanonicalFromValidated(
            $normalizedMethod,
            $path,
            $validatedTimestamp,
            $validatedNonce,
            $bodyHash
        );
        $signature = hash_hmac('sha256', $canonical, $validatedSecret, false);

        if (!self::isLowerHexSha256($signature)) {
            throw new RokuTranscoderHmacException('ROKU_TRANSCODER_HMAC_FAILED');
        }

        $headers = [
            'X-TopMaster-Timestamp' => $validatedTimestamp,
            'X-TopMaster-Nonce' => $validatedNonce,
            'X-TopMaster-Signature' => $signature,
        ];

        return [
            'timestamp' => $validatedTimestamp,
            'nonce' => $validatedNonce,
            'signature' => $signature,
            'body_hash' => $bodyHash,
            'headers' => $headers,
        ];
    }

    /**
     * @return array{timestamp:string,nonce:string,signature:string,body_hash:string,headers:array<string,string>}
     */
    public static function signNow(mixed $method, mixed $path, mixed $body, mixed $hmacSecret): array
    {
        return self::sign($method, $path, time(), self::generateNonce(), $body, $hmacSecret);
    }

    public static function canonicalize(
        mixed $method,
        mixed $path,
        mixed $timestamp,
        mixed $nonce,
        mixed $body
    ): string {
        $normalizedMethod = self::validateMethodAndPath($method, $path);
        $validatedTimestamp = self::validateTimestamp($timestamp);
        $validatedNonce = self::validateNonce($nonce);
        $validatedBody = self::validateBody($normalizedMethod, $body);

        return self::buildCanonicalFromValidated(
            $normalizedMethod,
            $path,
            $validatedTimestamp,
            $validatedNonce,
            self::bodyHash($validatedBody)
        );
    }

    public static function generateNonce(): string
    {
        try {
            $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        } catch (Throwable) {
            throw new RokuTranscoderHmacException('ROKU_TRANSCODER_HMAC_FAILED');
        }

        return self::validateNonce($nonce);
    }

    public static function verify(
        mixed $method,
        mixed $path,
        mixed $timestamp,
        mixed $nonce,
        mixed $body,
        mixed $hmacSecret,
        mixed $receivedSignature
    ): bool {
        if (!is_string($receivedSignature) || !self::isLowerHexSha256($receivedSignature)) {
            throw new RokuTranscoderHmacException('ROKU_TRANSCODER_HMAC_INVALID_SIGNATURE');
        }

        $expected = self::sign($method, $path, $timestamp, $nonce, $body, $hmacSecret)['signature'];

        return hash_equals($expected, $receivedSignature);
    }

    private static function validateMethodAndPath(mixed $method, mixed $path): string
    {
        if (!is_string($method) || $method === '' || preg_match('/\A[A-Za-z]+\z/', $method) !== 1) {
            throw new RokuTranscoderHmacException('ROKU_TRANSCODER_HMAC_INVALID_METHOD');
        }
        if (!is_string($path) || $path === '') {
            throw new RokuTranscoderHmacException('ROKU_TRANSCODER_HMAC_INVALID_PATH');
        }

        $normalizedMethod = strtoupper($method);
        if (!in_array($normalizedMethod, ['POST', 'GET', 'DELETE'], true)) {
            throw new RokuTranscoderHmacException('ROKU_TRANSCODER_HMAC_INVALID_METHOD');
        }

        $isCreate = $path === '/internal/sessions';
        $isStatus = preg_match(
            '/\A\/internal\/sessions\/[A-Za-z0-9_-]{16,128}\/status\z/',
            $path
        ) === 1;
        $isCancel = preg_match(
            '/\A\/internal\/sessions\/[A-Za-z0-9_-]{16,128}\z/',
            $path
        ) === 1;

        $validCombination = ($normalizedMethod === 'POST' && $isCreate)
            || ($normalizedMethod === 'GET' && $isStatus)
            || ($normalizedMethod === 'DELETE' && $isCancel);

        if (!$validCombination) {
            throw new RokuTranscoderHmacException('ROKU_TRANSCODER_HMAC_INVALID_PATH');
        }

        return $normalizedMethod;
    }

    private static function validateTimestamp(mixed $timestamp): string
    {
        if (!is_int($timestamp) || $timestamp <= 0) {
            throw new RokuTranscoderHmacException('ROKU_TRANSCODER_HMAC_INVALID_TIMESTAMP');
        }

        return (string) $timestamp;
    }

    private static function validateNonce(mixed $nonce): string
    {
        if (
            !is_string($nonce)
            || strlen($nonce) < 16
            || strlen($nonce) > 128
            || preg_match('/\A[A-Za-z0-9_-]+\z/', $nonce) !== 1
        ) {
            throw new RokuTranscoderHmacException('ROKU_TRANSCODER_HMAC_INVALID_NONCE');
        }

        return $nonce;
    }

    private static function validateBody(string $method, mixed $body): string
    {
        if (!is_string($body) || strlen($body) > self::MAX_BODY_BYTES) {
            throw new RokuTranscoderHmacException('ROKU_TRANSCODER_HMAC_INVALID_BODY');
        }
        if ($method === 'POST' && $body === '') {
            throw new RokuTranscoderHmacException('ROKU_TRANSCODER_HMAC_INVALID_BODY');
        }
        if (($method === 'GET' || $method === 'DELETE') && $body !== '') {
            throw new RokuTranscoderHmacException('ROKU_TRANSCODER_HMAC_INVALID_BODY');
        }

        return $body;
    }

    private static function validateSecret(mixed $hmacSecret): string
    {
        if (!is_string($hmacSecret) || strlen($hmacSecret) < self::MIN_SECRET_BYTES) {
            throw new RokuTranscoderHmacException('ROKU_TRANSCODER_HMAC_INVALID_SECRET');
        }

        return $hmacSecret;
    }

    private static function bodyHash(string $body): string
    {
        $hash = hash('sha256', $body);
        if (!self::isLowerHexSha256($hash)) {
            throw new RokuTranscoderHmacException('ROKU_TRANSCODER_HMAC_FAILED');
        }

        return $hash;
    }

    private static function buildCanonicalFromValidated(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $bodyHash
    ): string {
        return $method . "\n"
            . $path . "\n"
            . $timestamp . "\n"
            . $nonce . "\n"
            . $bodyHash;
    }

    private static function isLowerHexSha256(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/', $value) === 1;
    }
}
