<?php

namespace MeusistemaBR\Ci4SqliteLogger;
// use to referency: MeusistemaBR\Ci4SqliteLogger\SqliteHandler;
use CodeIgniter\Log\Handlers\BaseHandler;
use PDO;
use Exception;

class SqliteHandler extends BaseHandler
{
    const VERSION = '1.0.0';
    const APP_ID  = 0x4D534252;
    protected $dbPath;
    protected $maxFileSize; // em bytes (ex: 10MB)

    public function __construct(array $config)
    {
        parent::__construct($config);
        
        $this->dbPath      = $config['dbPath'] ?? WRITEPATH . 'database/system_logs.db';
        $this->maxFileSize = $config['maxFileSize'] ?? 10 * 1024 * 1024; // 10MB padrão
        $this->validateStorage();
        $this->initDatabase();
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

        try {
            $db = new PDO("sqlite:" . $this->dbPath);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
            $db->exec("PRAGMA wal_checkpoint(FULL);");

        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            // log_message('error', '[SQLiteLogger] Error: ' . $e->getMessage());
            // podemos assumir um log universal ou notificado de forma diferente, mas não podemos deixar de registrar o erro.
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
            if (strpos($os, 'WIN') !== false) {
                $id = shell_exec('wmic path win32_computersystemproduct get uuid');
                $id = str_replace(["UUID", "\r", "\n", " "], "", $id);
            } elseif (strpos($os, 'LINUX') !== false) {
                if (file_exists('/etc/machine-id')) {
                    $id = file_get_contents('/etc/machine-id');
                } elseif (file_exists('/var/lib/dbus/machine-id')) {
                    $id = file_get_contents('/var/lib/dbus/machine-id');
                }
            } elseif (strpos($os, 'DARWIN') !== false) {
                $id = shell_exec('ioreg -rd1 -c IOPlatformExpertDevice | grep -i IOPlatformUUID');
                preg_match('/"IOPlatformUUID" = "([^"]+)"/', $id, $matches);
                $id = $matches[1] ?? '';
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
            $db = new PDO("sqlite:" . $this->dbPath);
            $db->exec('PRAGMA wal_checkpoint(FULL);');
            $db = null;
            $backupPath = str_replace('.db', '_' . date('Ymd_His') . '.db', $this->dbPath);
            
            rename($this->dbPath, $backupPath);
        } catch (Exception $e) {
            // Se falhar a rotação, o CI4 pode registrar isso no log padrão de erro
        }
    }

    public function handle($level, $message, array $context = []): bool
    {
        try {
            $db = new PDO("sqlite:" . $this->dbPath);
            
            $request = \Config\Services::request();
            $agent   = $request->getUserAgent();

            $bytes      = random_bytes(16);
            $bytes[6]   = chr(ord($bytes[6]) & 0x0f | 0x40); // v4
            $bytes[8]   = chr(ord($bytes[8]) & 0x3f | 0x80); // variant
            $uuidStr    = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
            $ipAddress   = $request->getIPAddress();
            $remotePort  = $_SERVER['REMOTE_PORT'] ?? 0;
            $deviceInfo  = $agent->getBrowser() . ' ' . $agent->getVersion() . ' on ' . $agent->getPlatform();

            $type    = $this->config['type'] ?? 'system'; 
            $context = json_encode($this->config['context'] ?? []);
            
            $stmtLast = $db->query("SELECT hash_chain FROM system_logs ORDER BY id DESC LIMIT 1");
            $lastHash = $stmtLast->fetchColumn() ?: 'genesis_block';
            $newHash = hash('sha256', $lastHash . $level . $message);
            $sql = "INSERT INTO system_logs 
                (uuid, level, message, type, context, ip_address, device_info, remote_port, hash_chain) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $db->prepare($sql);
            return $stmt->execute([$uuidStr, $level, $message, $type, $context, $ipAddress, $deviceInfo, $remotePort, $newHash]);
        } catch (Exception $e) {
            return false;
        }
    }



    /**
     * Verifica se a integridade dos logs foi comprometida.
     * Retorna true se tudo estiver OK ou false se a corrente foi quebrada.
     */
    public function verifyIntegrity(): bool
    {
        try {
            $db = new PDO("sqlite:" . $this->dbPath);
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
        } catch (Exception $e) {
            return false;
        }
    }
}