<?php

declare(strict_types=1);

ob_start();
require_once dirname(__DIR__) . '/criar_admin_master.php';
$includeOutput = ob_get_clean();

final class CriarAdminMasterSecurityTestProvider
    implements CriarAdminMasterValueProvider
{
    /** @param array<string,string|null> $values */
    public function __construct(private readonly array $values)
    {
    }

    public function get(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }
}

final class CriarAdminMasterSecurityTestRepository
    implements CriarAdminMasterRepository
{
    public int $calls = 0;
    public ?string $username = null;
    public ?string $hash = null;
    public bool $created = true;
    public bool $fail = false;

    public function provision(string $username, string $passwordHash): bool
    {
        $this->calls++;
        if ($this->fail) {
            throw new RuntimeException('SYNTHETIC_PRIVATE_FAILURE');
        }
        $this->username = $username;
        $this->hash = $passwordHash;
        return $this->created;
    }
}

final class CriarAdminMasterSecurityTestFailingHasher
    implements CriarAdminMasterPasswordHasher
{
    public function hash(string $password): string
    {
        throw new RuntimeException('SYNTHETIC_PRIVATE_HASH_FAILURE');
    }
}

function criar_admin_master_security_test_require(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('TEST_FAILURE');
    }
}

/** @return array<string,string|null> */
function criar_admin_master_security_test_values(array $changes = []): array
{
    return array_replace([
        CriarAdminMasterGetenvValueProvider::CONFIRM => 'PROVISION_ADMIN_ONCE_V1',
        CriarAdminMasterGetenvValueProvider::USERNAME =>
            'TEST_ONLY_DO_NOT_USE_admin',
        CriarAdminMasterGetenvValueProvider::PASSWORD =>
            'TEST_ONLY_DO_NOT_USE_password_16_bytes',
    ], $changes);
}

function criar_admin_master_security_test_provider(
    array $changes = []
): CriarAdminMasterSecurityTestProvider {
    return new CriarAdminMasterSecurityTestProvider(
        criar_admin_master_security_test_values($changes)
    );
}

function criar_admin_master_security_test_main(): void
{
    global $includeOutput;
    criar_admin_master_security_test_require(
        $includeOutput === ''
        && headers_list() === []
    );

    $readerCalls = 0;
    $reader = static function (string $name) use (&$readerCalls): string {
        $readerCalls++;
        return 'TEST_ONLY_DO_NOT_USE_value';
    };
    $provider = new CriarAdminMasterGetenvValueProvider($reader);
    foreach ([
        CriarAdminMasterGetenvValueProvider::CONFIRM,
        CriarAdminMasterGetenvValueProvider::USERNAME,
        CriarAdminMasterGetenvValueProvider::PASSWORD,
    ] as $name) {
        criar_admin_master_security_test_require(
            $provider->get($name) === 'TEST_ONLY_DO_NOT_USE_value'
        );
    }
    criar_admin_master_security_test_require($readerCalls === 3);
    foreach ([
        '',
        'topmaster_admin_provision_confirm',
        'TOPMASTER_ADMIN_PROVISION',
        ' TOPMASTER_ADMIN_PROVISION_CONFIRM',
        "TOPMASTER_ADMIN_PROVISION_CONFIRM\0",
        "TOPMASTER_ADMIN_PROVISION_CONFIRM\r",
        "TOPMASTER_ADMIN_PROVISION_CONFIRM\n",
    ] as $invalidName) {
        try {
            $provider->get($invalidName);
        } catch (CriarAdminMasterException $exception) {
            criar_admin_master_security_test_require(
                $exception->getMessage()
                    === 'ADMIN_MASTER_PROVISION_INVALID_CONFIGURATION'
            );
            continue;
        }
        throw new RuntimeException('TEST_FAILURE');
    }
    criar_admin_master_security_test_require($readerCalls === 3);

    $http = (new CriarAdminMasterRunner())->httpResponse();
    criar_admin_master_security_test_require(
        $http->status === 404
        && $http->body === ['ok' => false, 'error' => 'NOT_FOUND']
        && $http->headers === [
            'Content-Type: application/json; charset=utf-8',
            'Cache-Control: no-store, max-age=0',
            'Pragma: no-cache',
            'X-Content-Type-Options: nosniff',
        ]
    );

    $runner = new CriarAdminMasterRunner();
    foreach ([
        [CriarAdminMasterGetenvValueProvider::CONFIRM, null],
        [CriarAdminMasterGetenvValueProvider::CONFIRM, 'INVALID_CONFIRMATION'],
        [CriarAdminMasterGetenvValueProvider::USERNAME, null],
        [CriarAdminMasterGetenvValueProvider::USERNAME, ''],
        [CriarAdminMasterGetenvValueProvider::USERNAME, "invalid\0name"],
        [CriarAdminMasterGetenvValueProvider::USERNAME, "invalid\rname"],
        [CriarAdminMasterGetenvValueProvider::USERNAME, "invalid\nname"],
        [CriarAdminMasterGetenvValueProvider::USERNAME, ' external-space'],
        [CriarAdminMasterGetenvValueProvider::USERNAME, 'external-space '],
        [CriarAdminMasterGetenvValueProvider::USERNAME, str_repeat('u', 255)],
        [CriarAdminMasterGetenvValueProvider::PASSWORD, null],
        [CriarAdminMasterGetenvValueProvider::PASSWORD, ''],
        [CriarAdminMasterGetenvValueProvider::PASSWORD, str_repeat('p', 15)],
        [CriarAdminMasterGetenvValueProvider::PASSWORD, "invalid\0password_value"],
        [CriarAdminMasterGetenvValueProvider::PASSWORD, "invalid\rpassword_value"],
        [CriarAdminMasterGetenvValueProvider::PASSWORD, "invalid\npassword_value"],
        [CriarAdminMasterGetenvValueProvider::PASSWORD, str_repeat('p', 4097)],
    ] as [$name, $value]) {
        $factoryCalls = 0;
        $result = $runner->runCli(
            criar_admin_master_security_test_provider([$name => $value]),
            static function () use (&$factoryCalls): CriarAdminMasterRepository {
                $factoryCalls++;
                return new CriarAdminMasterSecurityTestRepository();
            }
        );
        criar_admin_master_security_test_require(
            $result === 'ADMIN_MASTER_PROVISION_INVALID_CONFIGURATION'
            && $factoryCalls === 0
        );
    }

    foreach ([str_repeat('p', 16), str_repeat('p', 4096)] as $validPassword) {
        $input = CriarAdminMasterInput::fromProvider(
            criar_admin_master_security_test_provider([
                CriarAdminMasterGetenvValueProvider::PASSWORD => $validPassword,
            ])
        );
        criar_admin_master_security_test_require(
            $input->password() === $validPassword
        );
    }

    $repository = new CriarAdminMasterSecurityTestRepository();
    $password = 'TEST_ONLY_DO_NOT_USE_password_success';
    $success = $runner->runCli(
        criar_admin_master_security_test_provider([
            CriarAdminMasterGetenvValueProvider::PASSWORD => $password,
        ]),
        static fn (): CriarAdminMasterRepository => $repository
    );
    criar_admin_master_security_test_require(
        $success === 'ADMIN_MASTER_PROVISIONED'
        && $repository->calls === 1
        && is_string($repository->hash)
        && !hash_equals($password, $repository->hash)
        && password_verify($password, $repository->hash)
    );

    $existing = new CriarAdminMasterSecurityTestRepository();
    $existing->created = false;
    $alreadyExists = $runner->runCli(
        criar_admin_master_security_test_provider(),
        static fn (): CriarAdminMasterRepository => $existing
    );
    criar_admin_master_security_test_require(
        $alreadyExists === 'ADMIN_MASTER_PROVISION_ALREADY_EXISTS'
        && $existing->calls === 1
    );

    $failingRepository = new CriarAdminMasterSecurityTestRepository();
    $failingRepository->fail = true;
    criar_admin_master_security_test_require(
        $runner->runCli(
            criar_admin_master_security_test_provider(),
            static fn (): CriarAdminMasterRepository => $failingRepository
        ) === 'ADMIN_MASTER_PROVISION_FAILED'
        && $runner->runCli(
            criar_admin_master_security_test_provider(),
            static fn (): CriarAdminMasterRepository =>
                new CriarAdminMasterSecurityTestRepository(),
            new CriarAdminMasterSecurityTestFailingHasher()
        ) === 'ADMIN_MASTER_PROVISION_FAILED'
        && $runner->runCli(
            criar_admin_master_security_test_provider(),
            static function (): CriarAdminMasterRepository {
                throw new RuntimeException('SYNTHETIC_PRIVATE_FACTORY_FAILURE');
            }
        ) === 'ADMIN_MASTER_PROVISION_FAILED'
    );

    $source = file_get_contents(dirname(__DIR__) . '/criar_admin_master.php');
    criar_admin_master_security_test_require(is_string($source));
    foreach ([
        '$_GET', '$_POST', '$_COOKIE', '$_ENV', 'putenv(', 'php://input',
        'getopt(', '$argv', 'var_dump(', 'print_r(', 'phpinfo(',
        'getTrace', 'error_log(', 'shell_exec(', 'proc_open(',
    ] as $forbidden) {
        criar_admin_master_security_test_require(
            !str_contains($source, $forbidden)
        );
    }
    criar_admin_master_security_test_require(
        preg_match(
            '/\$(?:usuario|username|senha|password)\s*=\s*["\'][^"\']+["\']/',
            $source
        ) !== 1
        && substr_count($source, 'getenv(') === 2
        && str_contains($source, 'PHP_SAPI !== \'cli\'')
        && str_contains($source, 'realpath($script) === __FILE__')
        && !str_contains($source, 'Exception::getMessage()')
    );
}

try {
    criar_admin_master_security_test_main();
    fwrite(STDOUT, "CRIAR_ADMIN_MASTER_SECURITY_TEST_PASS\n");
    exit(0);
} catch (Throwable) {
    fwrite(STDOUT, "CRIAR_ADMIN_MASTER_SECURITY_TEST_FAIL\n");
    exit(1);
}
