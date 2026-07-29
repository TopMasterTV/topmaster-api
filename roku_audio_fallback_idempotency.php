<?php

declare(strict_types=1);

final class RokuAudioFallbackIdempotencyException extends RuntimeException
{
    private const CODIGOS_PERMITIDOS = [
        'ROKU_AUDIO_FALLBACK_INVALID_ARGUMENT',
        'ROKU_AUDIO_FALLBACK_INVALID_REQUEST_ID',
        'ROKU_AUDIO_FALLBACK_INVALID_CLIENT_ID',
        'ROKU_AUDIO_FALLBACK_INVALID_SYSTEM_ID',
        'ROKU_AUDIO_FALLBACK_INVALID_STREAM_ID',
        'ROKU_AUDIO_FALLBACK_INVALID_EXTENSION',
        'ROKU_AUDIO_FALLBACK_INVALID_DERIVATION_SECRET',
        'ROKU_AUDIO_FALLBACK_DERIVATION_FAILED',
    ];

    private string $codigoPublico;

    public function __construct(string $codigoPublico)
    {
        if (!in_array($codigoPublico, self::CODIGOS_PERMITIDOS, true)) {
            $codigoPublico = 'ROKU_AUDIO_FALLBACK_INVALID_ARGUMENT';
        }

        parent::__construct($codigoPublico);
        $this->codigoPublico = $codigoPublico;
    }

    public function getCodigoPublico(): string
    {
        return $this->codigoPublico;
    }
}

final class RokuAudioFallbackIdempotency
{
    private const CLIENTE_SISTEMA_ID_MAXIMO = 2147483647;
    private const STREAM_ID_MAXIMO_BYTES = 512;
    private const REQUEST_ID_TAMANHO = 43;
    private const SEGREDO_MINIMO_BYTES = 32;
    private const DOMINIO_CANONICO = 'TOPMASTER_ROKU_AUDIO_FALLBACK';
    private const VERSAO_CANONICA = 'v1';
    private const FALLBACK_KIND = 'vod_audio_stereo';
    private const DOMINIO_INTERNAL_SESSION = 'internal-session';
    private const DOMINIO_PUBLIC_TOKEN = 'public-token';

    private function __construct()
    {
    }

    public static function validarRequestId(mixed $requestId): string
    {
        if (
            !is_string($requestId)
            || strlen($requestId) !== self::REQUEST_ID_TAMANHO
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $requestId) !== 1
        ) {
            throw new RokuAudioFallbackIdempotencyException(
                'ROKU_AUDIO_FALLBACK_INVALID_REQUEST_ID'
            );
        }

        $base64 = strtr($requestId, '-_', '+/') . '=';
        $bytes = base64_decode($base64, true);

        if (
            $bytes === false
            || strlen($bytes) !== 32
            || self::codificarBase64Url($bytes) !== $requestId
        ) {
            throw new RokuAudioFallbackIdempotencyException(
                'ROKU_AUDIO_FALLBACK_INVALID_REQUEST_ID'
            );
        }

        return $requestId;
    }

    public static function normalizarExtensao(mixed $extensao): string
    {
        if (!is_string($extensao) || $extensao === '') {
            throw new RokuAudioFallbackIdempotencyException(
                'ROKU_AUDIO_FALLBACK_INVALID_EXTENSION'
            );
        }

        $normalizada = strtr(
            $extensao,
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'abcdefghijklmnopqrstuvwxyz'
        );

        if (!in_array($normalizada, ['mp4', 'mov', 'm4v', 'mkv'], true)) {
            throw new RokuAudioFallbackIdempotencyException(
                'ROKU_AUDIO_FALLBACK_INVALID_EXTENSION'
            );
        }

        return $normalizada;
    }

    public static function canonicalizar(
        mixed $clienteId,
        mixed $sistemaId,
        mixed $streamId,
        mixed $extensao,
        mixed $requestId
    ): string {
        $clienteIdValidado = self::validarId(
            $clienteId,
            'ROKU_AUDIO_FALLBACK_INVALID_CLIENT_ID'
        );
        $sistemaIdValidado = self::validarId(
            $sistemaId,
            'ROKU_AUDIO_FALLBACK_INVALID_SYSTEM_ID'
        );
        $streamIdValidado = self::validarStreamId($streamId);
        $extensaoNormalizada = self::normalizarExtensao($extensao);
        $requestIdValidado = self::validarRequestId($requestId);

        return implode("\n", [
            self::DOMINIO_CANONICO,
            self::VERSAO_CANONICA,
            (string) $clienteIdValidado,
            (string) $sistemaIdValidado,
            $streamIdValidado,
            $extensaoNormalizada,
            $requestIdValidado,
            self::FALLBACK_KIND,
        ]);
    }

    /**
     * @return array{
     *     canonical: string,
     *     internal_session_id: string,
     *     public_token: string,
     *     public_token_hash: string,
     *     extension: string
     * }
     */
    public static function derivar(
        mixed $clienteId,
        mixed $sistemaId,
        mixed $streamId,
        mixed $extensao,
        mixed $requestId,
        mixed $segredoDerivacao
    ): array {
        $segredoValidado = self::validarSegredoDerivacao($segredoDerivacao);
        $extensaoNormalizada = self::normalizarExtensao($extensao);
        $canonical = self::canonicalizar(
            $clienteId,
            $sistemaId,
            $streamId,
            $extensaoNormalizada,
            $requestId
        );

        $internalMac = hash_hmac(
            'sha256',
            self::DOMINIO_INTERNAL_SESSION . "\n" . $canonical,
            $segredoValidado,
            true
        );
        $publicMac = hash_hmac(
            'sha256',
            self::DOMINIO_PUBLIC_TOKEN . "\n" . $canonical,
            $segredoValidado,
            true
        );

        $internalSessionId = 'raf_' . self::codificarBase64Url($internalMac);
        $publicToken = self::codificarBase64Url($publicMac);

        if (
            strlen($internalSessionId) !== 47
            || preg_match('/\Araf_[A-Za-z0-9_-]{43}\z/D', $internalSessionId) !== 1
            || strlen($publicToken) !== 43
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $publicToken) !== 1
        ) {
            throw new RokuAudioFallbackIdempotencyException(
                'ROKU_AUDIO_FALLBACK_DERIVATION_FAILED'
            );
        }

        $publicTokenHash = hash('sha256', $publicToken);

        if (
            strlen($publicTokenHash) !== 64
            || preg_match('/\A[0-9a-f]{64}\z/D', $publicTokenHash) !== 1
        ) {
            throw new RokuAudioFallbackIdempotencyException(
                'ROKU_AUDIO_FALLBACK_DERIVATION_FAILED'
            );
        }

        return [
            'canonical' => $canonical,
            'internal_session_id' => $internalSessionId,
            'public_token' => $publicToken,
            'public_token_hash' => $publicTokenHash,
            'extension' => $extensaoNormalizada,
        ];
    }

    public static function compararPublicTokenHash(
        mixed $hashDerivado,
        mixed $hashPersistido
    ): bool {
        if (
            !self::hashPublicoPossuiFormatoValido($hashDerivado)
            || !self::hashPublicoPossuiFormatoValido($hashPersistido)
        ) {
            throw new RokuAudioFallbackIdempotencyException(
                'ROKU_AUDIO_FALLBACK_INVALID_ARGUMENT'
            );
        }

        return hash_equals($hashPersistido, $hashDerivado);
    }

    private static function validarId(mixed $id, string $codigoErro): int
    {
        if (
            !is_int($id)
            || $id < 1
            || $id > self::CLIENTE_SISTEMA_ID_MAXIMO
        ) {
            throw new RokuAudioFallbackIdempotencyException($codigoErro);
        }

        return $id;
    }

    private static function validarStreamId(mixed $streamId): string
    {
        if (
            !is_string($streamId)
            || $streamId === ''
            || strlen($streamId) > self::STREAM_ID_MAXIMO_BYTES
            || str_contains($streamId, "\0")
            || str_contains($streamId, "\r")
            || str_contains($streamId, "\n")
            || preg_match('/(*UTF)(*UCP)\A\s*\z/D', $streamId) === 1
            || preg_match('/\A[A-Za-z][A-Za-z0-9+.-]*:/D', $streamId) === 1
            || str_starts_with($streamId, '//')
        ) {
            throw new RokuAudioFallbackIdempotencyException(
                'ROKU_AUDIO_FALLBACK_INVALID_STREAM_ID'
            );
        }

        return $streamId;
    }

    private static function validarSegredoDerivacao(mixed $segredoDerivacao): string
    {
        if (
            !is_string($segredoDerivacao)
            || strlen($segredoDerivacao) < self::SEGREDO_MINIMO_BYTES
        ) {
            throw new RokuAudioFallbackIdempotencyException(
                'ROKU_AUDIO_FALLBACK_INVALID_DERIVATION_SECRET'
            );
        }

        return $segredoDerivacao;
    }

    private static function codificarBase64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function hashPublicoPossuiFormatoValido(mixed $hash): bool
    {
        return is_string($hash)
            && strlen($hash) === 64
            && preg_match('/\A[0-9a-f]{64}\z/D', $hash) === 1;
    }
}
