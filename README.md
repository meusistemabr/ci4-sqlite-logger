
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

Abra o arquivo ```app/Config/Logger.php``` instancie a biblioteca do SQLite Logger no início do arquivo, logo após as instâncias padrão do Codeigniter, adicione ```use MeusistemaBR\Ci4SqliteLogger\SqliteHandler;``` e, adicione o handler no array ```$handlers``` no mesmo arquivo, veja um exemplo:

```php
  ...
  ...
  ...
  ...
  ... // bastante codigo acima e algumas instruções
  public $handlers = [
    // ... outros handlers
    SqliteHandler::class => [
        'handles'     => ['critical', 'error', 'debug', 'info', 'notice'],
        'dbPath'      => WRITEPATH . 'database/system_logs.db', // aqui você pode inserir o caminho do banco de dados que preferir, desde que possua permissão de leitura/escrita e espaço disponível. Você pode deixar essa variável vazia também, o caminho default é WRITEPATH . 'database/system_logs.db'
        'maxFileSize' => 10 * 1024 * 1024, // 10MB para rotação automática, você pode digitar valores maiores ou menores, ao chegar no limiar, o sistema "tranca" o Banco de Dados anterior e abre outro Banco de Dados para continuar.
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

### 3. Consulta dos Logs pelo Spark

Exiba os 10 registros mais recentes do banco SQLite em uso:

```bash
php spark logs:tail
```

Informe uma quantidade entre 1 e 1000 para alterar o limite:

```bash
php spark logs:tail 25
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
