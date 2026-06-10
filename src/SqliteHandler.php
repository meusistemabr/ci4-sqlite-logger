<?php

namespace MeusistemaBR\Ci4SqliteLogger;

use CodeIgniter\Log\Handlers\BaseHandler;
use CodeIgniter\Log\Logger;
use PDO;
use RuntimeException;
use Throwable;

class SqliteHandler extends BaseHandler
{
    public const VERSION = '1.3.0';
    public const APP_ID  = 0x4D534252;

    protected string $dbPath;
    protected int $maxFileSize;
    protected bool $available = true;
    protected bool $reportErrors = true;
    protected bool $throwOnError = false;
    protected ?string $lastError = null;

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->dbPath       = (string) ($config['dbPath'] ?? $this->defaultDbPath());
        $this->maxFileSize  = (int) ($config['maxFileSize'] ?? 10 * 1024 * 1024);
        $this->reportErrors = (bool) ($config['reportErrors'] ?? true);
        $this->throwOnError = (bool) ($config['throwOnError'] ?? false);

        try {
            $this->validateStorage();
            $this->initDatabase();
        } catch (Throwable $e) {
            $this->fail('Falha ao inicializar o banco SQLite de logs.', $e);
        }
    }

    /**
     * Handler padrão do Logger do CodeIgniter 4.
     * Atenção: o contexto do log_message() pode chegar já consumido/interpolado pelo Logger.
     */
    public function handle($level, $message, array $context = []): bool
    {
        if (! $this->available) {
            return false;
        }

        try {
            $context = $this->getOriginalContext($context);

            return $this->writeSystemLog(
                (string) $level,
                (string) $message,
                $context,
                (string) ($this->config['type'] ?? 'system')
            );
        } catch (Throwable $e) {
            $this->fail('Falha ao gravar log no banco SQLite.', $e);
            return false;
        }
    }

    /**
     * Entrada direta para logs técnicos, sem depender do log_message() do CI4.
     * Usada pela helper msbr_log().
     */
    public function msbrLog(string $level, string $message, array $context = [], string $type = 'system'): bool
    {
        if (! $this->available) {
            return false;
        }

        try {
            return $this->writeSystemLog($level, $message, $context, $type);
        } catch (Throwable $e) {
            $this->fail('Falha ao gravar log direto no banco SQLite.', $e);
            return false;
        }
    }

    /**
     * Entrada direta para auditoria de negócio.
     * Usada pela helper msbr_audit().
     */
    public function msbrAudit(
        string $action,
        string $area,
        string $message,
        array $data = [],
        string $level = 'warning'
    ): bool {
        if (! $this->available) {
            return false;
        }

        try {
            $db = $this->connect();
            $requestInfo = $this->getRequestInfo();
            $uuid = $this->generateUuid();
            $payloadJson = $this->safeJsonEncode($data);

            $userId = $data['user_id'] ?? $data['userId'] ?? $this->detectUserId();
            $tableName = $data['table'] ?? $data['table_name'] ?? null;
            $recordId = $data['record_id'] ?? $data['id'] ?? null;

            $stmtLast = $db->query('SELECT hash_chain FROM audit_logs ORDER BY id DESC LIMIT 1');
            $lastHash = $stmtLast ? ($stmtLast->fetchColumn() ?: 'genesis_block') : 'genesis_block';
            $newHash = hash('sha256', $lastHash . $uuid . $level . $action . $area . $message . $payloadJson);

            $sql = 'INSERT INTO audit_logs
                (uuid, level, action, area, message, data, user_id, table_name, record_id, ip_address, device_info, remote_port, hash_chain)
                VALUES
                (:uuid, :level, :action, :area, :message, :data, :user_id, :table_name, :record_id, :ip_address, :device_info, :remote_port, :hash_chain)';

            $stmt = $db->prepare($sql);

            return $stmt->execute([
                ':uuid'        => $uuid,
                ':level'       => $level,
                ':action'      => $action,
                ':area'        => $area,
                ':message'     => $message,
                ':data'        => $payloadJson,
                ':user_id'     => $userId,
                ':table_name'  => $tableName !== null ? (string) $tableName : null,
                ':record_id'   => $recordId !== null ? (string) $recordId : null,
                ':ip_address'  => $requestInfo['ip_address'],
                ':device_info' => $requestInfo['device_info'],
                ':remote_port' => $requestInfo['remote_port'],
                ':hash_chain'  => $newHash,
            ]);
        } catch (Throwable $e) {
            $this->fail('Falha ao gravar auditoria no banco SQLite.', $e);
            return false;
        }
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Verifica a integridade da corrente de logs.
     * $table aceita: system_logs ou audit_logs.
     */
    public function verifyIntegrity(string $table = 'system_logs'): bool
    {
        try {
            $db = $this->connect();

            if ($table === 'audit_logs') {
                $stmt = $db->query('SELECT uuid, level, action, area, message, data, hash_chain FROM audit_logs ORDER BY id ASC');
                $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $prevHash = 'genesis_block';

                foreach ($logs as $log) {
                    $expectedHash = hash(
                        'sha256',
                        $prevHash
                        . $log['uuid']
                        . $log['level']
                        . $log['action']
                        . $log['area']
                        . $log['message']
                        . $log['data']
                    );

                    if (! hash_equals((string) $expectedHash, (string) $log['hash_chain'])) {
                        return false;
                    }

                    $prevHash = $log['hash_chain'];
                }

                return true;
            }

            $stmt = $db->query('SELECT level, message, hash_chain FROM system_logs ORDER BY id ASC');
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $prevHash = 'genesis_block';

            foreach ($logs as $log) {
                $expectedHash = hash('sha256', $prevHash . $log['level'] . $log['message']);

                if (! hash_equals((string) $expectedHash, (string) $log['hash_chain'])) {
                    return false;
                }

                $prevHash = $log['hash_chain'];
            }

            return true;
        } catch (Throwable $e) {
            $this->fail('Falha ao verificar a integridade do banco SQLite de logs.', $e);
            return false;
        }
    }

    protected function writeSystemLog(string $level, string $message, array $context = [], string $type = 'system'): bool
    {
        $db = $this->connect();
        $requestInfo = $this->getRequestInfo();
        $uuid = $this->generateUuid();
        $contextJson = $this->safeJsonEncode($context);

        $stmtLast = $db->query('SELECT hash_chain FROM system_logs ORDER BY id DESC LIMIT 1');
        $lastHash = $stmtLast ? ($stmtLast->fetchColumn() ?: 'genesis_block') : 'genesis_block';
        $newHash = hash('sha256', $lastHash . $level . $message);

        $sql = 'INSERT INTO system_logs
            (uuid, level, message, type, context, ip_address, device_info, remote_port, hash_chain)
            VALUES
            (:uuid, :level, :message, :type, :context, :ip_address, :device_info, :remote_port, :hash_chain)';

        $stmt = $db->prepare($sql);

        return $stmt->execute([
            ':uuid'        => $uuid,
            ':level'       => $level,
            ':message'     => $message,
            ':type'        => $type,
            ':context'     => $contextJson,
            ':ip_address'  => $requestInfo['ip_address'],
            ':device_info' => $requestInfo['device_info'],
            ':remote_port' => $requestInfo['remote_port'],
            ':hash_chain'  => $newHash,
        ]);
    }

    protected function initDatabase(): void
    {
        if (is_file($this->dbPath) && filesize($this->dbPath) >= $this->maxFileSize) {
            $this->rotateLogs();
        }

        $db = null;

        try {
            $db = $this->connect();
            $db->exec('PRAGMA journal_mode = DELETE;');
            $db->exec('PRAGMA synchronous = NORMAL;');
            $db->exec('PRAGMA secure_delete = OFF;');
            $db->exec('PRAGMA application_id = ' . self::APP_ID . ';');
            $db->exec('PRAGMA user_version = 2;');

            $db->beginTransaction();

            $db->exec('CREATE TABLE IF NOT EXISTS system_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT,
                level TEXT DEFAULT \'info\',
                message TEXT,
                type TEXT,
                context TEXT,
                ip_address TEXT,
                device_info TEXT,
                remote_port INTEGER,
                hash_chain TEXT,
                timestamp DATETIME DEFAULT (STRFTIME(\'%Y-%m-%d %H:%M:%f\', \'NOW\'))
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT,
                level TEXT DEFAULT \'warning\',
                action TEXT,
                area TEXT,
                message TEXT,
                data TEXT,
                user_id TEXT,
                table_name TEXT,
                record_id TEXT,
                ip_address TEXT,
                device_info TEXT,
                remote_port INTEGER,
                hash_chain TEXT,
                timestamp DATETIME DEFAULT (STRFTIME(\'%Y-%m-%d %H:%M:%f\', \'NOW\'))
            )');

            $db->exec('CREATE INDEX IF NOT EXISTS idx_system_logs_level ON system_logs(level)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_system_logs_timestamp ON system_logs(timestamp)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_action ON audit_logs(action)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_area ON audit_logs(area)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_user_id ON audit_logs(user_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_table_record ON audit_logs(table_name, record_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_timestamp ON audit_logs(timestamp)');

            $db->exec('CREATE TABLE IF NOT EXISTS lib_metadata (
                key TEXT PRIMARY KEY,
                value TEXT
            )');

            $db->commit();
            $this->saveMetadata($db);
        } catch (Throwable $e) {
            if ($db instanceof PDO && $db->inTransaction()) {
                $db->rollBack();
            }

            $this->fail('Falha ao criar ou preparar as tabelas do banco SQLite de logs.', $e);
        }
    }

    protected function validateStorage(): void
    {
        if ($this->dbPath === '') {
            throw new RuntimeException('O caminho do banco SQLite não foi informado.');
        }

        $dir = dirname($this->dbPath);

        if (! is_dir($dir)) {
            if (! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
                $error = error_get_last();

                throw new RuntimeException(
                    'O diretório de logs não existe e não pôde ser criado: '
                    . $dir
                    . '. Erro: '
                    . ($error['message'] ?? 'erro desconhecido')
                );
            }
        }

        if (! is_writable($dir)) {
            throw new RuntimeException('O diretório de logs não tem permissão de escrita: ' . $dir);
        }
    }

    protected function saveMetadata(PDO $db): void
    {
        $metadata = [
            'lib_name'     => 'meusistemabr/ci4-sqlite-logger',
            'lib_version'  => self::VERSION,
            'installed_at' => date('Y-m-d H:i:s'),
            'machine_guid' => $this->getMachineId(),
            'vendor_info'  => 'meusistema.com.br',
        ];

        $stmt = $db->prepare('INSERT OR REPLACE INTO lib_metadata (key, value) VALUES (?, ?)');

        foreach ($metadata as $key => $value) {
            $stmt->execute([$key, $value]);
        }
    }

    protected function rotateLogs(): void
    {
        try {
            $db = $this->connect();
            $db->exec('PRAGMA wal_checkpoint(FULL);');
            $db = null;

            $backupPath = preg_replace('/\.db$/', '_' . date('Ymd_His') . '.db', $this->dbPath);
            $backupPath = $backupPath ?: ($this->dbPath . '_' . date('Ymd_His'));

            if (! @rename($this->dbPath, $backupPath)) {
                throw new RuntimeException('Não foi possível rotacionar o banco de logs para: ' . $backupPath);
            }
        } catch (Throwable $e) {
            $this->fail('Falha ao rotacionar o banco SQLite de logs.', $e);
        }
    }

    protected function connect(): PDO
    {
        if (! extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('A extensão pdo_sqlite não está carregada.');
        }

        $db = new PDO('sqlite:' . $this->dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $db;
    }

    protected function fail(string $message, ?Throwable $e = null): void
    {
        $detail = $message;

        if ($e !== null) {
            $detail .= ' ' . $e->getMessage();
        }

        $detail .= ' Caminho: ' . $this->dbPath;

        $this->lastError = $detail;
        $this->available = false;

        if ($this->reportErrors) {
            error_log('[SQLiteLogger] ' . $detail);
        }

        if ($this->throwOnError && $e !== null) {
            throw $e;
        }
    }

    protected function getOriginalContext(array $context): array
    {
        if ($context !== []) {
            return $context;
        }

        try {
            foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) as $frame) {
                if (
                    ($frame['function'] ?? null) === 'log'
                    && ($frame['object'] ?? null) instanceof Logger
                    && isset($frame['args'][2])
                    && is_array($frame['args'][2])
                ) {
                    return $frame['args'][2];
                }
            }
        } catch (Throwable $e) {
            return $context;
        }

        return $context;
    }

    protected function getRequestInfo(): array
    {
        $request = null;
        $agent = null;

        try {
            if (class_exists('Config\\Services')) {
                $request = \Config\Services::request();
                $agent = method_exists($request, 'getUserAgent') ? $request->getUserAgent() : null;
            }
        } catch (Throwable $e) {
            $request = null;
            $agent = null;
        }

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

        try {
            if ($request !== null && method_exists($request, 'getIPAddress')) {
                $ipAddress = $request->getIPAddress();
            }
        } catch (Throwable $e) {
            // Mantém fallback.
        }

        $deviceInfo = php_sapi_name();

        try {
            if ($agent !== null) {
                $browser = method_exists($agent, 'getBrowser') ? $agent->getBrowser() : '';
                $version = method_exists($agent, 'getVersion') ? $agent->getVersion() : '';
                $platform = method_exists($agent, 'getPlatform') ? $agent->getPlatform() : '';
                $deviceInfo = trim($browser . ' ' . $version . ' on ' . $platform) ?: php_sapi_name();
            }
        } catch (Throwable $e) {
            $deviceInfo = php_sapi_name();
        }

        return [
            'ip_address'  => $ipAddress,
            'device_info' => $deviceInfo,
            'remote_port' => $this->getRemotePort($request),
        ];
    }

    protected function getRemotePort($request = null): ?int
    {
        $remotePort = null;

        try {
            if ($request !== null && method_exists($request, 'getServer')) {
                $remotePort = $request->getServer('REMOTE_PORT');
            }
        } catch (Throwable $e) {
            $remotePort = null;
        }

        if ($remotePort === null || $remotePort === '') {
            $remotePort = $_SERVER['REMOTE_PORT'] ?? null;
        }

        if (! is_numeric($remotePort)) {
            return null;
        }

        $remotePort = (int) $remotePort;

        return $remotePort >= 1 && $remotePort <= 65535 ? $remotePort : null;
    }

    protected function detectUserId()
    {
        try {
            if (function_exists('auth')) {
                $auth = auth();

                if (is_object($auth) && method_exists($auth, 'loggedIn') && $auth->loggedIn()) {
                    $user = $auth->user();

                    return $user->id ?? null;
                }
            }
        } catch (Throwable $e) {
            // Ignora e tenta session.
        }

        try {
            if (function_exists('session')) {
                return session()->get('user_id')
                    ?? session()->get('id_user')
                    ?? session()->get('id')
                    ?? null;
            }
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }

    protected function safeJsonEncode($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($json === false) {
            return json_encode([
                'error'      => 'Falha ao converter dados para JSON',
                'json_error' => json_last_error_msg(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        return $json;
    }

    protected function generateUuid(): string
    {
        try {
            $bytes = random_bytes(16);
        } catch (Throwable $e) {
            $bytes = hash('sha256', uniqid('', true) . mt_rand(), true);
            $bytes = substr($bytes, 0, 16);
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    protected function getMachineId(): string
    {
        $os = strtoupper(PHP_OS);
        $id = '';

        try {
            if (str_contains($os, 'DARWIN')) {
                $raw = shell_exec('ioreg -rd1 -c IOPlatformExpertDevice | grep -i IOPlatformUUID') ?: '';
                preg_match('/"IOPlatformUUID" = "([^"]+)"/', $raw, $matches);
                $id = $matches[1] ?? '';
            } elseif (strncmp($os, 'WIN', 3) === 0) {
                $raw = shell_exec('wmic path win32_computersystemproduct get uuid') ?: '';
                $id = str_replace(['UUID', "\r", "\n", ' '], '', $raw);
            } elseif (str_contains($os, 'LINUX')) {
                if (is_file('/etc/machine-id')) {
                    $id = (string) file_get_contents('/etc/machine-id');
                } elseif (is_file('/var/lib/dbus/machine-id')) {
                    $id = (string) file_get_contents('/var/lib/dbus/machine-id');
                }
            }
        } catch (Throwable $e) {
            $id = '';
        }

        if (trim($id) === '') {
            $fallbackFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . '.sys_machine_id';

            if (is_file($fallbackFile)) {
                $id = (string) file_get_contents($fallbackFile);
            } else {
                $id = hash('sha256', php_uname() . gethostname() . uniqid('', true));
                @file_put_contents($fallbackFile, $id);
            }
        }

        return hash('sha256', trim($id));
    }

    protected function defaultDbPath(): string
    {
        if (defined('WRITEPATH')) {
            return WRITEPATH . 'database/system_logs.db';
        }

        return getcwd() . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'system_logs.db';
    }
}