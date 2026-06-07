<?php

namespace MeusistemaBR\Ci4SqliteLogger\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use PDO;
use Throwable;

class LogsTail extends BaseCommand
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT     = 1000;

    protected $group       = 'Logs';
    protected $name        = 'logs:tail';
    protected $description = 'Exibe os registros mais recentes do banco de logs SQLite em uso.';
    protected $usage       = 'logs:tail [quantidade]';
    protected $arguments   = [
        'quantidade' => 'Quantidade de registros a exibir. Padrão: 10. Máximo: 1000.',
    ];

    public function run(array $params)
    {
        $limit = $this->parseLimit($params[0] ?? null);
        if ($limit === null) {
            return;
        }

        $config        = config('Logger');
        $handlerConfig = $config->handlers['MeusistemaBR\Ci4SqliteLogger\SqliteHandler'] ?? null;

        if (!$handlerConfig) {
            CLI::error('Configuração do SqliteHandler não encontrada em app/Config/Logger.php');
            return;
        }

        $dbPath = $handlerConfig['dbPath'] ?? WRITEPATH . 'database/system_logs.db';
        if (!is_file($dbPath)) {
            CLI::error("Banco de logs SQLite não encontrado: {$dbPath}");
            return;
        }

        if (!extension_loaded('pdo_sqlite')) {
            CLI::error(
                'A extensão pdo_sqlite não está carregada no PHP CLI: ' . PHP_BINARY
            );
            return;
        }

        try {
            $db = new PDO('sqlite:' . $dbPath);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $db->prepare(
                'SELECT id, timestamp, level, type, message, context, ip_address, remote_port
                 FROM system_logs
                 ORDER BY id DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($logs === []) {
                CLI::write('Nenhum registro de log encontrado.', 'yellow');
                return;
            }

            CLI::write("Últimos {$limit} registros de log:", 'yellow');
            CLI::table($logs, ['ID', 'Data', 'Nível', 'Tipo', 'Mensagem', 'Contexto', 'IP', 'Porta']);
        } catch (Throwable $e) {
            CLI::error('Falha ao consultar os logs SQLite: ' . $e->getMessage());
        }
    }

    private function parseLimit($value): ?int
    {
        if ($value === null) {
            return self::DEFAULT_LIMIT;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            CLI::error('A quantidade deve ser um número inteiro.');
            return null;
        }

        $limit = (int) $value;
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            CLI::error('A quantidade deve estar entre 1 e ' . self::MAX_LIMIT . '.');
            return null;
        }

        return $limit;
    }
}
