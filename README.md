
# CI4 SQLite Logger 🛡️

O CI4 SQLite Logger é uma biblioteca de logging de alta integridade para CodeIgniter 4. Diferente dos logs tradicionais em texto, ele armazena eventos em um banco de dados SQLite rotativo, utilizando uma Corrente de Custódia (Hash Chain) para garantir que os registros não sejam alterados ou deletados sem detecção.

## ✨ Recursos Principais

- **Banco de Dados Rotativo:** Monitoramento automático do tamanho do arquivo com rotação inteligente.
- **Corrente de Custódia:** Cada log contém um hash SHA-256 que o vincula matematicamente ao log anterior.
- **Modo Performance (WAL):** Utiliza Write-Ahead Logging para permitir escritas rápidas sem travar a aplicação.
- **Dados Forenses:** Captura automática de IP (IPv4/IPv6), UUID, Device Info e porta remota.
- **Metadados de Segurança:** Identificação única do banco de dados para evitar substituição de arquivos.


## 🚀 Instalação

Instale a lib via Composer:

```bash
  composer require meusistemabr/ci4-sqlite-logger
```


    
## ⚙️ Configuração

Abra o arquivo ```app/Config/Logger.php``` e adicione o handler no array ```$handlers```:

```php
  public $handlers = [
    // ... outros handlers
    'MeusistemaBR\Ci4SqliteLogger\SqliteHandler' => [
        'handles'     => ['critical', 'error', 'debug', 'info', 'notice'],
        'dbPath'      => WRITEPATH . 'database/system_logs.sqlite',
        'maxFileSize' => 10 * 1024 * 1024, // 10MB para rotação automática
    ],
];
```


## 🔍 Funções Forenses e Segurança

Pensamos nesta lib para logs no Codeigniter 4 principalmente não só para registrar e rastrear os registros de erros e ingestões de sua aplicação, mas também para oferecer garantia de, caso necessário, caso seu Software com nossa lib instalada passe por alguma perícia forense digita; ou análise de consistência, você poderá oferecer conformidade e confiabilidade.

### 1. Integridade de Dados (Hash Chain)
Cada linha inserida gera um hash baseado em: ```Hash Anterior + UUID + Nível + Mensagem + IP + Contexto```.Se um hacker apagar o log de um ataque, a corrente será quebrada, invalidando o banco.

### 2. Rastreabilidade Total
Diferente do log padrão, salvamos:
- **UUID:** Identificador único para rastrear uma falha específica.
- **Contexto JSON:** Dados estruturados para auditoria.
- **Fingerprint:** Informações do navegador e sistema operacional do originador.

## Apoiado e mantido por

![App Screenshot](https://dummyimage.com/468x300?text=App+Screenshot+Here)
Meu Sistema - Sistemas online personalizados 
Acesse: https://meusistema.com.br
Fale consoco em: contato [at] meusistema.com.br

## Licença

[MIT](https://choosealicense.com/licenses/mit/)


