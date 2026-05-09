<?php

namespace MeusistemaBR\Ci4SqliteLogger\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use MeusistemaBR\Ci4SqliteLogger\SqliteHandler;

class LogsCheck extends BaseCommand
{
    protected $group       = 'Logs';
    protected $name        = 'logs:check';
    protected $description = 'Verifica a integridade da corrente de custódia dos logs armazenados no SQLite.';

    public function run(array $params)
    {
        $config = config('Logger');
        $handlerConfig = $config->handlers['MeusistemaBR\Ci4SqliteLogger\SqliteHandler'] ?? null;
        if (!$handlerConfig) {
            CLI::error("Configuração do SqliteHandler não encontrada em app/Config/Logger.php");
            return;
        }

        $handler = new SqliteHandler($handlerConfig);
        
        CLI::write('Iniciando verificação de integridade...', 'yellow');

        if ($handler->verifyIntegrity()) {
            CLI::write('✅ Sucesso: A corrente de custódia está intacta. Nenhum log foi alterado.', 'green');
        } else {
            CLI::error('❌ ALERTA: A integridade dos logs foi violada! A corrente de hashes foi quebrada.');
            CLI::write('Isso indica que registros foram apagados ou editados manualmente no banco de dados.', 'red');
        }
    }
}
