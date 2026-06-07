<?php

namespace MeusistemaBR\Ci4SqliteLogger;
// use to referency: MeusistemaBR\Ci4SqliteLogger\SqliteHandler;
use CodeIgniter\Log\Handlers\BaseHandler;
use CodeIgniter\Log\Logger;
use PDO;
use Exception;
use RuntimeException;
use Throwable;

class SqliteHandler extends BaseHandler
{
    const VERSION = '1.2.6';
    const APP_ID  = 0x4D534252;
    protected $dbPath;
    protected $maxFileSize; // em bytes (ex: 10MB)
    protected bool $available = true;
    protected bool $reportErrors = true;
    protected bool $throwOnError = false;
    protected ?string $lastError = null;

    public function __construct(array $config)
    {
        parent::__construct($config);
        
        $this->dbPath      = $config['dbPath'] ?? WRITEPATH . 'database/system_logs.db';
        $this->maxFileSize = $config['maxFileSize'] ?? 10 * 1024 * 1024; // 10MB padrão
        $this->reportErrors = (bool) ($config['reportErrors'] ?? true);
        $this->throwOnError = (bool) ($config['throwOnError'] ?? false);

        try {
            $this->validateStorage();
            $this->initDatabase();
        } catch (Throwable $e) {
            $this->fail('Falha ao inicializar o banco SQLite de logs.', $e);
        }
    }


    protected function validateStorage()
    {
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException("O diretório de logs não existe e não pôde ser criado: {$dir}");
            }
        }

        if (!is_writable($dir)) {
            throw new RuntimeException("O diretório de logs não tem permissão de escrita: {$dir}");
        }
    }
    protected function initDatabase()
    {
        if (file_exists($this->dbPath) && filesize($this->dbPath) >= $this->maxFileSize) {
            $this->rotateLogs();
        }

        $db = null;

        try {
            $db = $this->connect();
            $db->exec("PRAGMA journal_mode = DELETE;");
            $db->exec("PRAGMA synchronous = NORMAL;");
            $db->exec("PRAGMA secure_delete = OFF;");
            $db->exec("PRAGMA application_id = " . self::APP_ID . ";");
            $db->exec("PRAGMA user_version = 1;");
            $db->beginTransaction();
            $db->exec("CREATE TABLE IF NOT EXISTS system_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT,
                level TEXT DEFAULT 'info',
                message TEXT,
                type TEXT,
                context TEXT,
                ip_address TEXT,
                device_info TEXT,
                remote_port INTEGER,
                hash_chain TEXT,
                timestamp DATETIME DEFAULT (STRFTIME('%Y-%m-%d %H:%M:%f', 'NOW'))
            )");

            $db->exec("CREATE TABLE IF NOT EXISTS lib_metadata (
                key TEXT PRIMARY KEY,
                value TEXT
            )");
            $db->commit();
            $stmt = $db->query("SELECT COUNT(*) FROM lib_metadata");
            if ($stmt->fetchColumn() == 0) {
                $this->saveMetadata($db);
            }
        } catch (Throwable $e) {
            if ($db instanceof PDO && $db->inTransaction()) {
                $db->rollBack();
            }
            $this->fail('Falha ao criar ou preparar as tabelas do banco SQLite de logs.', $e);
        }
    }

    protected function saveMetadata($db)
    {
        $metadata = [
            'lib_name'    => 'meusistemabr/ci4-sqlite-logger',
            'lib_version' => self::VERSION,
            'installed_at' => date('Y-m-d H:i:s'),
            'machine_guid' => $this->get_machine_id(),
            'vendor_info' => 'meusistema.com.br'
        ];

        $stmt = $db->prepare("INSERT INTO lib_metadata (key, value) VALUES (?, ?)");
        foreach ($metadata as $key => $val) {
            $stmt->execute([$key, $val]);
        }
    }

    private function get_machine_id() {
        $os = strtoupper(PHP_OS);
        $id = '';

        try {
            if (strpos($os, 'DARWIN') !== false) {
                $id = shell_exec('ioreg -rd1 -c IOPlatformExpertDevice | grep -i IOPlatformUUID') ?: '';
                preg_match('/"IOPlatformUUID" = "([^"]+)"/', $id, $matches);
                $id = $matches[1] ?? '';
            } elseif (strncmp($os, 'WIN', 3) === 0) {
                $id = shell_exec('wmic path win32_computersystemproduct get uuid');
                $id = str_replace(["UUID", "\r", "\n", " "], "", $id);
            } elseif (strpos($os, 'LINUX') !== false) {
                if (file_exists('/etc/machine-id')) {
                    $id = file_get_contents('/etc/machine-id');
                } elseif (file_exists('/var/lib/dbus/machine-id')) {
                    $id = file_get_contents('/var/lib/dbus/machine-id');
                }
            }
        } catch (Exception $e) {
            $id = null;
        }

        // fallback
        if (empty(trim($id))) {
            $fallbackFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . '.sys_machine_id';
            
            if (file_exists($fallbackFile)) {
                $id = file_get_contents($fallbackFile);
            } else {
                $entropy = php_uname() . gethostname();
                $id = hash('sha256', $entropy . uniqid(mt_rand(), true));
                @file_put_contents($fallbackFile, $id);
            }
        }

        return hash('sha256', trim($id));
    }

    protected function rotateLogs()
    {
        try {
            $db = $this->connect();
            $db->exec('PRAGMA wal_checkpoint(FULL);');
            $db = null;
            $backupPath = str_replace('.db', '_' . date('Ymd_His') . '.db', $this->dbPath);
            
            if (!rename($this->dbPath, $backupPath)) {
                throw new RuntimeException("Não foi possível rotacionar o banco de logs para: {$backupPath}");
            }
        } catch (Throwable $e) {
            $this->fail('Falha ao rotacionar o banco SQLite de logs.', $e);
        }
    }

    public function handle($level, $message, array $context = []): bool
    {
        if (!$this->available) {
            return false;
        }

        try {
            $db = $this->connect();
            
            $request = null;
            $agent   = null;

            try {
                if (class_exists('\Config\Services')) {
                    $request = \Config\Services::request();
                    $agent   = method_exists($request, 'getUserAgent') ? $request->getUserAgent() : null;
                }
            } catch (Throwable $e) {
                $request = null;
                $agent   = null;
            }

            $bytes      = random_bytes(16);
            $bytes[6]   = chr(ord($bytes[6]) & 0x0f | 0x40); // v4
            $bytes[8]   = chr(ord($bytes[8]) & 0x3f | 0x80); // variant
            $uuidStr    = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
            $ipAddress = null;

            if ($request !== null && method_exists($request, 'getIPAddress')) {
                $ipAddress = $request->getIPAddress();
            } else {
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            }

            $remotePort = $this->getRemotePort($request);

            $deviceInfo = php_sapi_name();

            if ($agent !== null) {
                $deviceInfo = trim($agent->getBrowser() . ' ' . $agent->getVersion() . ' on ' . $agent->getPlatform());
            }

            $type    = $this->config['type'] ?? 'system';
            $context = $this->getOriginalContext($context);
            $context = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($context === false) {
                $context = json_encode(['json_error' => json_last_error_msg()]);
            }
            
            $stmtLast = $db->query("SELECT hash_chain FROM system_logs ORDER BY id DESC LIMIT 1");
            $lastHash = $stmtLast->fetchColumn() ?: 'genesis_block';
            $newHash = hash('sha256', $lastHash . $level . $message);
            $sql = "INSERT INTO system_logs 
                (uuid, level, message, type, context, ip_address, device_info, remote_port, hash_chain) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $db->prepare($sql);
            return $stmt->execute([$uuidStr, $level, $message, $type, $context, $ipAddress, $deviceInfo, $remotePort, $newHash]);
        } catch (Throwable $e) {
            $this->fail('Falha ao gravar log no banco SQLite.', $e);
            return false;
        }
    }

    /**
     * O Logger do CI4 não encaminha o contexto aos handlers. Enquanto o
     * Logger::log() ainda está na pilha, recuperamos seu terceiro argumento.
     */
    protected function getOriginalContext(array $context): array
    {
        if ($context !== []) {
            return $context;
        }

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

        return $context;
    }

    protected function getRemotePort($request = null): ?int
    {
        $remotePort = null;

        if ($request !== null && method_exists($request, 'getServer')) {
            $remotePort = $request->getServer('REMOTE_PORT');
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

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    protected function connect(): PDO
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('A extensão pdo_sqlite não está carregada.');
        }

        $db = new PDO("sqlite:" . $this->dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $db;
    }

    protected function fail(string $message, ?Throwable $e = null): void
    {
        $detail = $message;
        if ($e !== null) {
            $detail .= ' ' . $e->getMessage();
        }
        $detail .= " Caminho: {$this->dbPath}";

        $this->lastError = $detail;
        $this->available = false;

        if ($this->reportErrors) {
            error_log('[SQLiteLogger] ' . $detail);
        }

        if ($this->throwOnError && $e !== null) {
            throw $e;
        }
    }



    /**
     * Verifica se a integridade dos logs foi comprometida.
     * Retorna true se tudo estiver OK ou false se a corrente foi quebrada.
     */
    public function verifyIntegrity(): bool
    {
        try {
            $db = $this->connect();
            $stmt = $db->query("SELECT level, message, hash_chain FROM system_logs ORDER BY id ASC");
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $prevHash = 'genesis_block';

            foreach ($logs as $log) {
                $expectedHash = hash('sha256', $prevHash . $log['level'] . $log['message']);
                
                if ($log['hash_chain'] !== $expectedHash) {
                    return false; // ⛔️ Corrente quebrada, integridade comprometida ⛔️
                }
                $prevHash = $log['hash_chain'];
            }

            return true;
        } catch (Throwable $e) {
            $this->fail('Falha ao verificar a integridade do banco SQLite de logs.', $e);
            return false;
        }
    }
}
