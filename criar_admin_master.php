<?php

declare(strict_types=1);

final class CriarAdminMasterException extends RuntimeException
{
    private const CODES = [
        'ADMIN_MASTER_PROVISION_INVALID_CONFIGURATION',
        'ADMIN_MASTER_PROVISION_ALREADY_EXISTS',
        'ADMIN_MASTER_PROVISION_FAILED',
    ];

    public function __construct(string $code)
    {
        $this->publicCode = in_array($code, self::CODES, true)
            ? $code
            : 'ADMIN_MASTER_PROVISION_FAILED';
        parent::__construct($this->publicCode);
    }

    private readonly string $publicCode;

    public function publicCode(): string
    {
        return $this->publicCode;
    }
}

interface CriarAdminMasterValueProvider
{
    public function get(string $name): ?string;
}

final class CriarAdminMasterGetenvValueProvider
    implements CriarAdminMasterValueProvider
{
    public const CONFIRM = 'TOPMASTER_ADMIN_PROVISION_CONFIRM';
    public const USERNAME = 'TOPMASTER_ADMIN_PROVISION_USERNAME';
    public const PASSWORD = 'TOPMASTER_ADMIN_PROVISION_PASSWORD';

    private const ALLOWED_NAMES = [
        self::CONFIRM,
        self::USERNAME,
        self::PASSWORD,
    ];

    private readonly Closure $reader;

    public function __construct(?callable $reader = null)
    {
        $this->reader = Closure::fromCallable(
            $reader ?? static fn (string $name): string|false => getenv($name)
        );
    }

    public function get(string $name): ?string
    {
        if (!in_array($name, self::ALLOWED_NAMES, true)) {
            throw new CriarAdminMasterException(
                'ADMIN_MASTER_PROVISION_INVALID_CONFIGURATION'
            );
        }
        $value = ($this->reader)($name);
        if ($value === false) {
            return null;
        }
        if (!is_string($value)) {
            throw new CriarAdminMasterException(
                'ADMIN_MASTER_PROVISION_INVALID_CONFIGURATION'
            );
        }
        return $value;
    }
}

final readonly class CriarAdminMasterInput
{
    private const CONFIRMATION = 'PROVISION_ADMIN_ONCE_V1';
    private const MAX_USERNAME_BYTES = 254;
    private const MIN_PASSWORD_BYTES = 16;
    private const MAX_PASSWORD_BYTES = 4096;

    private function __construct(
        private string $username,
        private string $password
    ) {
    }

    public static function fromProvider(CriarAdminMasterValueProvider $provider): self
    {
        try {
            $confirmation = $provider->get(CriarAdminMasterGetenvValueProvider::CONFIRM);
            $username = $provider->get(CriarAdminMasterGetenvValueProvider::USERNAME);
            $password = $provider->get(CriarAdminMasterGetenvValueProvider::PASSWORD);
        } catch (CriarAdminMasterException $exception) {
            throw $exception;
        } catch (Throwable) {
            self::invalid();
        }

        if ($confirmation !== self::CONFIRMATION) {
            self::invalid();
        }
        if (
            !is_string($username)
            || $username === ''
            || strlen($username) > self::MAX_USERNAME_BYTES
            || preg_match('/[\x00-\x1F\x7F]/', $username) === 1
            || trim($username) !== $username
        ) {
            self::invalid();
        }
        if (
            !is_string($password)
            || strlen($password) < self::MIN_PASSWORD_BYTES
            || strlen($password) > self::MAX_PASSWORD_BYTES
            || preg_match('/[\x00\r\n]/', $password) === 1
        ) {
            self::invalid();
        }
        return new self($username, $password);
    }

    public function username(): string
    {
        return $this->username;
    }

    public function password(): string
    {
        return $this->password;
    }

    private static function invalid(): never
    {
        throw new CriarAdminMasterException(
            'ADMIN_MASTER_PROVISION_INVALID_CONFIGURATION'
        );
    }
}

interface CriarAdminMasterRepository
{
    public function provision(string $username, string $passwordHash): bool;
}

final class CriarAdminMasterPdoRepository implements CriarAdminMasterRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function connectFromEnvironment(): self
    {
        $databaseUrl = getenv('DATABASE_URL');
        if (!is_string($databaseUrl) || $databaseUrl === '') {
            throw new CriarAdminMasterException(
                'ADMIN_MASTER_PROVISION_FAILED'
            );
        }
        try {
            $parts = parse_url($databaseUrl);
        } catch (Throwable) {
            throw new CriarAdminMasterException(
                'ADMIN_MASTER_PROVISION_FAILED'
            );
        }
        if (
            !is_array($parts)
            || !is_string($parts['host'] ?? null)
            || !is_string($parts['path'] ?? null)
            || !is_string($parts['user'] ?? null)
            || !is_string($parts['pass'] ?? null)
        ) {
            throw new CriarAdminMasterException(
                'ADMIN_MASTER_PROVISION_FAILED'
            );
        }
        try {
            $pdo = new PDO(
                sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s',
                    $parts['host'],
                    is_int($parts['port'] ?? null) ? $parts['port'] : 5432,
                    ltrim($parts['path'], '/')
                ),
                $parts['user'],
                $parts['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (Throwable) {
            throw new CriarAdminMasterException(
                'ADMIN_MASTER_PROVISION_FAILED'
            );
        }
        return new self($pdo);
    }

    public function provision(string $username, string $passwordHash): bool
    {
        try {
            $this->pdo->beginTransaction();
            $check = $this->pdo->prepare(
                'SELECT 1
                 FROM admins
                 WHERE usuario = :usuario OR tipo = :tipo
                 LIMIT 1
                 FOR UPDATE'
            );
            if (!$check instanceof PDOStatement) {
                throw new RuntimeException();
            }
            $check->bindValue(':usuario', $username, PDO::PARAM_STR);
            $check->bindValue(':tipo', 'admin_master', PDO::PARAM_STR);
            if (!$check->execute()) {
                throw new RuntimeException();
            }
            if ($check->fetchColumn() !== false) {
                $this->pdo->rollBack();
                return false;
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO admins (nome, usuario, senha, tipo)
                 VALUES (:nome, :usuario, :senha, :tipo)'
            );
            if (!$insert instanceof PDOStatement) {
                throw new RuntimeException();
            }
            $insert->bindValue(
                ':nome',
                'Administrador Master',
                PDO::PARAM_STR
            );
            $insert->bindValue(':usuario', $username, PDO::PARAM_STR);
            $insert->bindValue(':senha', $passwordHash, PDO::PARAM_STR);
            $insert->bindValue(':tipo', 'admin_master', PDO::PARAM_STR);
            if (!$insert->execute()) {
                throw new RuntimeException();
            }
            $this->pdo->commit();
            return true;
        } catch (Throwable) {
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (Throwable) {
                    // Rollback errors remain private.
                }
            }
            throw new CriarAdminMasterException(
                'ADMIN_MASTER_PROVISION_FAILED'
            );
        }
    }
}

interface CriarAdminMasterPasswordHasher
{
    public function hash(string $password): string;
}

final class CriarAdminMasterNativePasswordHasher
    implements CriarAdminMasterPasswordHasher
{
    public function hash(string $password): string
    {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
        } catch (Throwable) {
            throw new CriarAdminMasterException(
                'ADMIN_MASTER_PROVISION_FAILED'
            );
        }
        if (!is_string($hash) || $hash === '') {
            throw new CriarAdminMasterException(
                'ADMIN_MASTER_PROVISION_FAILED'
            );
        }
        return $hash;
    }
}

final class CriarAdminMasterService
{
    public function __construct(
        private readonly CriarAdminMasterRepository $repository,
        private readonly CriarAdminMasterPasswordHasher $hasher
    ) {
    }

    public function provision(CriarAdminMasterInput $input): string
    {
        try {
            $hash = $this->hasher->hash($input->password());
            $created = $this->repository->provision(
                $input->username(),
                $hash
            );
        } catch (CriarAdminMasterException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new CriarAdminMasterException(
                'ADMIN_MASTER_PROVISION_FAILED'
            );
        }
        if (!$created) {
            throw new CriarAdminMasterException(
                'ADMIN_MASTER_PROVISION_ALREADY_EXISTS'
            );
        }
        return 'ADMIN_MASTER_PROVISIONED';
    }
}

final readonly class CriarAdminMasterHttpResponse
{
    /**
     * @param list<string> $headers
     * @param array<string,mixed> $body
     */
    public function __construct(
        public int $status,
        public array $headers,
        public array $body
    ) {
    }
}

final class CriarAdminMasterRunner
{
    public function httpResponse(): CriarAdminMasterHttpResponse
    {
        return new CriarAdminMasterHttpResponse(
            404,
            [
                'Content-Type: application/json; charset=utf-8',
                'Cache-Control: no-store, max-age=0',
                'Pragma: no-cache',
                'X-Content-Type-Options: nosniff',
            ],
            ['ok' => false, 'error' => 'NOT_FOUND']
        );
    }

    /**
     * @param callable(): CriarAdminMasterRepository $repositoryFactory
     */
    public function runCli(
        CriarAdminMasterValueProvider $provider,
        callable $repositoryFactory,
        ?CriarAdminMasterPasswordHasher $hasher = null
    ): string {
        try {
            $input = CriarAdminMasterInput::fromProvider($provider);
            $repository = $repositoryFactory();
            if (!$repository instanceof CriarAdminMasterRepository) {
                throw new CriarAdminMasterException(
                    'ADMIN_MASTER_PROVISION_FAILED'
                );
            }
            return (new CriarAdminMasterService(
                $repository,
                $hasher ?? new CriarAdminMasterNativePasswordHasher()
            ))->provision($input);
        } catch (CriarAdminMasterException $exception) {
            return $exception->publicCode();
        } catch (Throwable) {
            return 'ADMIN_MASTER_PROVISION_FAILED';
        }
    }
}

function criarAdminMasterExecutedDirectly(): bool
{
    $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
    return is_string($script)
        && $script !== ''
        && realpath($script) === __FILE__;
}

if (criarAdminMasterExecutedDirectly()) {
    $runner = new CriarAdminMasterRunner();
    if (PHP_SAPI !== 'cli') {
        $response = $runner->httpResponse();
        http_response_code($response->status);
        foreach ($response->headers as $header) {
            header($header);
        }
        echo json_encode(
            $response->body,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    $result = $runner->runCli(
        new CriarAdminMasterGetenvValueProvider(),
        static fn (): CriarAdminMasterRepository =>
            CriarAdminMasterPdoRepository::connectFromEnvironment()
    );
    fwrite(STDOUT, $result . PHP_EOL);
    exit($result === 'ADMIN_MASTER_PROVISIONED' ? 0 : 1);
}
