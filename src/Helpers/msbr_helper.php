<?php

use MeusistemaBR\Ci4SqliteLogger\SqliteHandler;

if (! function_exists('msbr_sqlite_logger')) {
    /**
     * Retorna uma instância segura do SqliteHandler.
     * Pode receber config manual, mas tenta reaproveitar a config do Logger.php quando disponível.
     */
    function msbr_sqlite_logger(array $config = []): ?SqliteHandler
    {
        static $instances = [];

        try {
            if (! class_exists(SqliteHandler::class)) {
                return null;
            }

            $defaultConfig = [
                'handles'      => ['critical', 'alert', 'emergency', 'debug', 'error', 'info', 'notice', 'warning'],
                'dbPath'       => defined('WRITEPATH')
                    ? WRITEPATH . 'database/system_logs.db'
                    : getcwd() . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'system_logs.db',
                'maxFileSize'  => 10 * 1024 * 1024,
                'reportErrors' => true,
                'throwOnError' => false,
                'type'         => 'system',
            ];

            $loggerConfig = [];

            try {
                if (function_exists('config')) {
                    $logger = config('Logger');

                    if (is_object($logger) && isset($logger->handlers[SqliteHandler::class]) && is_array($logger->handlers[SqliteHandler::class])) {
                        $loggerConfig = $logger->handlers[SqliteHandler::class];
                    }
                }
            } catch (Throwable $e) {
                $loggerConfig = [];
            }

            $finalConfig = array_replace($defaultConfig, $loggerConfig, $config);
            $cacheKey = md5(json_encode($finalConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: serialize($finalConfig));

            if (! isset($instances[$cacheKey])) {
                $instances[$cacheKey] = new SqliteHandler($finalConfig);
            }

            return $instances[$cacheKey];
        } catch (Throwable $e) {
            error_log('[SQLiteLogger][HELPER] Falha ao instanciar SqliteHandler: ' . $e->getMessage());
            return null;
        }
    }
}

if (! function_exists('msbr_log')) {
    /**
     * Log técnico direto no SQLite, sem depender de log_message() nem do contexto PSR-3.
     */
    function msbr_log(string $level, string $message, array $context = [], array $config = []): bool
    {
        try {
            $handler = msbr_sqlite_logger($config);

            if (! $handler instanceof SqliteHandler) {
                return false;
            }

            return $handler->msbrLog($level, $message, $context, (string) ($config['type'] ?? 'system'));
        } catch (Throwable $e) {
            error_log('[SQLiteLogger][msbr_log] ' . $e->getMessage());
            return false;
        }
    }
}

if (! function_exists('msbr_audit')) {
    /**
     * Auditoria de negócio direta no SQLite.
     *
     * Exemplo:
     * msbr_audit('update', 'Cadastro de Clientes', 'Cliente alterado', [
     *     'table' => 'clientes',
     *     'record_id' => 10,
     *     'old' => $old,
     *     'new' => $new,
     * ]);
     */
    function msbr_audit(
        string $action,
        string $area,
        string $message,
        array $data = [],
        string $level = 'warning',
        array $config = []
    ): bool {
        try {
            $handler = msbr_sqlite_logger($config);

            if (! $handler instanceof SqliteHandler) {
                return false;
            }

            return $handler->msbrAudit($action, $area, $message, $data, $level);
        } catch (Throwable $e) {
            error_log('[SQLiteLogger][msbr_audit] ' . $e->getMessage());
            return false;
        }
    }
}
