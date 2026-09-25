---
layout: default
title: Visão Geral
nav_order: 1
description: "Documentação oficial do CI4 SQLite Logger. Sistema de logging forense rotativo em SQLite para CodeIgniter 4 com hash chain SHA-256 e auditoria estruturada."
permalink: /
---

# CI4 SQLite Logger 🛡️
{: .no_toc }

Biblioteca de alta integridade e auditoria estruturada para o framework [CodeIgniter 4](https://codeigniter.com/ "CodeIgniter Official Site"). Projetada para substituir arquivos de texto planos (`.log`) por bancos relacionais [SQLite](https://www.sqlite.org/ "SQLite Database Engine") rotativos criptograficamente encadeados.

## Sumário
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Por que trocar logs em texto por SQLite?

Arquivos tradicionais de log em texto puro (`.log`) apresentam vulnerabilidades graves de segurança e limitações de escala em ambientes de produção:

* **Adulteração Silenciosa:** Se um atacante obtiver privilégios no servidor, linhas de log podem ser apagadas ou alteradas sem deixar vestígios.
* **Leitura Ineficiente:** Fazer buscas por data, nível de erro ou usuário em arquivos de texto de centenas de megabytes exige comandos pesados de I/O (`grep`, `awk`, `sed`).
* **Concorrência:** Escritas concorrentes frequentes geram bloqueios ou corrupção de arquivos planos.

O **CI4 SQLite Logger** soluciona esses problemas gravando eventos diretamente em tabelas relacionais SQLite indexadas, implementando verificação matemática de custódia e execução em modo **WAL (Write-Ahead Logging)**.

---

## Recursos Principais

### 1. Corrente de Custódia (Hash Chain SHA-256)
Cada evento gravado calcula um hash criptográfico baseado no hash da linha imediatamente anterior combinado aos dados atuais:

\[\text{Hash Atual} = \text{SHA256}(\text{Hash Anterior} + \text{UUID} + \text{Nível} + \text{Mensagem} + \text{IP} + \text{Contexto})\]

Se qualquer registro for excluído ou editado manualmente via terminal ou injeção, a cadeia se rompe, invalidando o banco de dados perante perícias digitais.

### 2. Banco de Dados Rotativo Automático
Monitoramento contínuo do tamanho do arquivo `.db`. Ao atingir o teto parametrizado (padrão de **10 MB**), o arquivo atual é rotacionado de forma atômica e um novo banco é iniciado sem derrubar a aplicação.

### 3. Modo Performance (Write-Ahead Logging)
Configurado com `PRAGMA journal_mode = WAL;`, permitindo que leituras e escritas aconteçam simultaneamente em threads distintas sem causar gargalos (`database locked`).

### 4. Metadados e Trilha Forense
Captura automática de:
* Endereço IP do cliente (compatível com IPv4 e IPv6).
* Porta remota de conexão (`REMOTE_PORT`).
* *User-Agent* detalhado (navegador, dispositivo e sistema operacional).
* Identificador único universal (**UUIDv4**) por linha de log.
* Metadados de segurança para prevenir a substituição física do arquivo do banco.

{: .note }
> **Requisitos do Ambiente:** PHP 8.1+, extensão `ext-sqlite3` habilitada e [CodeIgniter 4.x](https://codeigniter.com/user_guide/ "CodeIgniter 4 User Guide") instalado.
