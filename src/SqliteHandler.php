<?php

namespace MeusistemaBR\Ci4SqliteLogger;

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
        
        $this->dbPath      = $config['dbPath'] ?? WRITEPATH . 'database/logs.sqlite';
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
        // Se o arquivo atingiu o limite, rotaciona antes de qualquer coisa
        if (file_exists($this->dbPath) && filesize($this->dbPath) >= $this->maxFileSize) {
            $this->rotateLogs();
        }

        try {
            $db = new PDO("sqlite:" . $this->dbPath);
            $db->exec("PRAGMA journal_mode = WAL;");
            $db->exec("PRAGMA application_id = " . self::APP_ID);
            $db->exec("PRAGMA user_version = 1");
            $db->exec("CREATE TABLE IF NOT EXISTS system_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT,
                level TEXT,
                message TEXT,
                type TEXT,
                context TEXT,
                ip_address TEXT,
                device_info TEXT,
                remote_port INTEGER,
                hash_chain TEXT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

            $db->exec("CREATE TABLE IF NOT EXISTS lib_metadata (
                key TEXT PRIMARY KEY,
                value TEXT
            )");
            $stmt = $db->query("SELECT COUNT(*) FROM lib_metadata");
            if ($stmt->fetchColumn() == 0) {
                $this->saveMetadata($db);
            }
            $db = null;
        } catch (Exception $e) {
            // error handling, talvez logar isso no log padrão de erro do CI4
        }
    }

    protected function saveMetadata($db)
    {
        $metadata = [
            'lib_name'    => 'meusistemabr/ci4-sqlite-logger',
            'lib_version' => self::VERSION,
            'installed_at' => date('Y-m-d H:i:s'),
            'security_hash' => hash('sha256', $this->dbPath . php_uname('n'))
        ];

        $stmt = $db->prepare("INSERT INTO lib_metadata (key, value) VALUES (?, ?)");
        foreach ($metadata as $key => $val) {
            $stmt->execute([$key, $val]);
        }
    }

    protected function rotateLogs()
    {
        try {
            $db = new PDO("sqlite:" . $this->dbPath);
            $db->exec('PRAGMA wal_checkpoint(FULL);');
            $db = null;
            $backupPath = str_replace('.sqlite', '_' . date('Ymd_His') . '.sqlite', $this->dbPath);
            
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
