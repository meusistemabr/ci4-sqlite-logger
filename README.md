
# CI4 SQLite Logger 🛡️

O CI4 SQLite Logger é uma biblioteca de logging de alta integridade para CodeIgniter 4. Diferente dos logs tradicionais em texto, ele armazena eventos em um banco de dados SQLite rotativo, utilizando uma Corrente de Custódia (Hash Chain) para garantir que os registros não sejam alterados ou deletados sem detecção.

[![CodeFactor](https://www.codefactor.io/repository/github/meusistemabr/ci4-sqlite-logger/badge/main)](https://www.codefactor.io/repository/github/meusistemabr/ci4-sqlite-logger/overview/main)

## ✨ Recursos Principais

- **Banco de Dados Rotativo:** Monitoramento automático do tamanho do arquivo com rotação inteligente.
- **Corrente de Custódia:** Cada log contém um hash SHA-256 que o vincula matematicamente ao log anterior.
- **Modo Performance (WAL):** Utiliza Write-Ahead Logging para permitir escritas rápidas sem travar a aplicação.
- **Dados Forenses:** Captura automática de IP (IPv4/IPv6), UUID, Device Info e porta remota.
- **Metadados de Segurança:** Identificação única do banco de dados para evitar substituição de arquivos.

A biblioteca pode trabalhar de duas formas:

1. Como handler do `log_message()` do CodeIgniter 4.
2. Como API direta por helpers globais: `msbr_log()` e `msbr_audit()`.

A forma direta é recomendada para auditoria de negócio, porque o terceiro parâmetro do `log_message()` é usado pelo Logger/PSR-3 para interpolação de placeholders e pode não chegar intacto ao handler.

## 🚀 Instalação

Instale a lib via Composer:

```bash
  composer require meusistemabr/ci4-sqlite-logger
```

Depois de atualizar a lib localmente, rode:

```bash
composer dump-autoload
php spark optimize:clear
```


    
## ⚙️ Configuração opcional do Logger.php

Arquivo: `app/Config/Logger.php`

Abra o arquivo ```app/Config/Logger.php``` instancie a biblioteca do SQLite Logger no início do arquivo, logo após as instâncias padrão do Codeigniter, adicione ```use MeusistemaBR\Ci4SqliteLogger\SqliteHandler;``` e, adicione o handler no array ```$handlers``` no mesmo arquivo, veja um exemplo:

```php
  ...
  ...
  ...
  ...
  ... // bastante codigo acima e algumas instruções
  SqliteHandler::class => [
        'handles' => [
            'critical',
            'alert',
            'emergency',
            'debug',
            'error',
            'info',
            'notice',
            'warning',
        ],
        'dbPath'       => WRITEPATH . 'database/system_logs.db',
        'maxFileSize'  => 10 * 1024 * 1024,
        'reportErrors' => true,
        'throwOnError' => false,
        'type'         => 'system',
    ],
```

Os parâmetros são opcionais, uma vez já definido quais os ```$handlers``` na classe ```SqliteHandler::class``` a biblioteca já funciona com seus valores padrões.

## Uso recomendado: log técnico direto

```php
msbr_log('warning', 'Falha ao processar pagamento', [
    'order_id'     => 123,
    'gateway'      => 'pagarme',
    'cluster_id'   => 3,
    'port_origin'  => '1234'
]);
```

Os dados são gravados na tabela `system_logs`.


## Uso recomendado: auditoria de negócio

```php
msbr_audit(
    'update',
    'Cadastro de Clientes',
    'Cliente alterado',
    [
        'user_id'   => auth()->user()->id ?? null,
        'table'     => 'clientes',
        'record_id' => $clienteId,
        'old'       => $oldData,
        'new'       => $newData,
    ]
);
```

Os dados são gravados na tabela `audit_logs`.


### Assinatura:

```php
msbr_audit(
    string $action,
    string $area,
    string $message,
    array $data = [],
    string $level = 'warning',
    array $config = []
): bool
```


## Tabelas criadas automaticamente

A biblioteca cria automaticamente:

- `system_logs`
- `audit_logs`
- `lib_metadata`

Caminho padrão:

```php
WRITEPATH . 'database/system_logs.db'
```

Normalmente equivale a:

```text
writable/database/system_logs.db
```

Por padrão, o tamanho máximo por cada bloco de Banco de Dados é de 10MB, você pode definir valores maiores ou menores. O sistema fará a rotatividade de forma automática quando atingir o limite.


## 🔍 Funções Forenses e Segurança

Pensamos nesta lib para logs no Codeigniter 4 principalmente não só para registrar e rastrear os registros de erros e ingestões de sua aplicação, mas também para oferecer garantia de, caso necessário, caso seu Software com nossa lib instalada passe por alguma perícia forense digita; ou análise de consistência, você poderá oferecer conformidade e confiabilidade.

### 1. Integridade de Dados (Hash Chain)
Cada linha inserida gera um hash baseado em: ```Hash Anterior + UUID + Nível + Mensagem + IP + Contexto```.Se um hacker apagar o log de um ataque, a corrente será quebrada, invalidando o banco.

A biblioteca mantém hash chain para `system_logs` e `audit_logs`.

```php
$handler = msbr_sqlite_logger();

$okSystem = $handler?->verifyIntegrity('system_logs');
$okAudit  = $handler?->verifyIntegrity('audit_logs');
```


### 2. Rastreabilidade Total
Diferente do log padrão, salvamos:
- **UUID:** Identificador único para rastrear uma falha específica.
- **Contexto JSON:** Dados estruturados para auditoria.
- **Fingerprint:** Informações do navegador e sistema operacional do originador.

### 3. Consulta dos Logs pelo Spark

Exiba os 10 registros mais recentes do banco SQLite em uso:

```bash
php spark logs:tail
```

Informe uma quantidade entre 1 e 1000 para alterar o limite:

```bash
php spark logs:tail 25
```

### 4. Seguro contra crash e erro 500

As helpers `msbr_log()` e `msbr_audit()` são protegidas com `Throwable` e retornam `false` em caso de falha. Por padrão, a lib não derruba a aplicação.

Para debug local:

```php
'throwOnError' => true,
```

Para produção:

```php
'throwOnError' => false,
```



## Apoiado e mantido por

<img src="https://cdn-a1-br-sl.meusistema.com.br/imagens/logo_ms.png" alt="MeuSistema sistemas online personalizados" style="width:150px;float:left;padding-left:0px;margin-right:20px;">
<div style="padding-left:50px;">
<p>
<strong>Meu Sistema - Sistemas online personalizados</strong><br>
Acesse: <a href="https://meusistema.com.br" target="_blank" title="Sistemas online personalizados">https://meusistema.com.br</a><br>
Fale consoco em: contato[at]meusistema.com.br
</p>
</div>

## Licença

[MIT](https://choosealicense.com/licenses/mit/)
