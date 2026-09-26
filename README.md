# 🔌 Monitor de Tomada Tuya

Monitora o consumo de energia de uma **tomada inteligente Wi-Fi baseada em Tuya** (Smart Life, Tuya Smart e apps "de marca" que usam a plataforma Tuya), minuto a minuto, **direto pela rede local**, sem depender da nuvem no dia a dia.

Foi criado para medir quanto uma **impressora 3D** gasta em cada impressão, mas serve para qualquer aparelho ligado na tomada.

- **Coletor em Python** que lê potência, tensão, corrente e estado (ligada/desligada) a cada minuto e grava num banco MySQL/MariaDB.
- **Painel web** (PHP) com gráficos por dia, semana e mês, tema claro/escuro e atualização automática.
- **Relatório de período**: você escolhe início e fim (pode passar da meia-noite) e vê o consumo total, a potência média, mínima e máxima, o tempo ligada, o custo estimado e o gráfico da impressão.
- **Só faz leitura.** O projeto nunca envia comandos de ligar/desligar para a tomada.
- Funciona em **Linux** e **Windows**.

> Testado com a tomada **EKAZA Tomada Inteligente 20A** (modelo EKAT-T205-20A, protocolo Tuya 3.5), cadastrada no app **Smart Life**.

---

## Sumário

1. [Como funciona](#como-funciona)
2. [O que você precisa](#o-que-você-precisa)
3. [Passo 1: pegar os dados da tomada no Tuya IoT](#passo-1-pegar-os-dados-da-tomada-no-tuya-iot)
4. [Passo 2: preencher o config.json](#passo-2-preencher-o-configjson)
5. [Passo 3: instalar no Linux](#passo-3-instalar-no-linux-debianubuntu)
6. [Alternativa: rodar no Windows](#alternativa-rodar-no-windows)
7. [Problemas comuns](#problemas-comuns)
8. [Estrutura do projeto](#estrutura-do-projeto)
9. [Segurança](#segurança)

---

## Como funciona

```
 ┌──────────────┐   rede local (TCP 6668)   ┌──────────────────────┐      ┌──────────────┐
 │ Tomada Tuya  │ ◄──────────────────────── │  coletor_tomada.py   │ ───► │ MySQL/MariaDB│
 │ (Wi-Fi)      │   só leitura, 1x/minuto   │  (cron / Agendador)  │      │consumo_tomada│
 └──────────────┘                           └──────────────────────┘      └──────┬───────┘
                                                                                 │
                                                                   ┌─────────────▼──────────┐
                                                                   │ Painel web (PHP)       │
                                                                   │ tomada.php             │
                                                                   │ tomada_relatorio.php   │
                                                                   └────────────────────────┘
```

A tomada aceita conexões locais criptografadas com uma chave própria, a **`local_key`**. Essa chave só pode ser obtida pela plataforma de desenvolvedor da Tuya, **uma única vez** (Passo 1). Depois disso, tudo roda na sua rede, sem nuvem.

O IP da tomada costuma mudar (DHCP do roteador). Por isso o coletor procura a tomada pelo **endereço MAC**, que não muda, e guarda o último IP encontrado para não precisar varrer a rede a cada minuto.

---

## O que você precisa

| Item | Observação |
|---|---|
| Tomada Wi-Fi Tuya **com medição de energia** | A tomada precisa mostrar potência/consumo no app. |
| App **Smart Life** no celular | Com a tomada cadastrada nele. |
| Conta gratuita na **Tuya Developer Platform** | Usada só no Passo 1. |
| Um computador/servidor **na mesma rede local** da tomada | Linux (recomendado, pode ser uma VM ou Raspberry Pi) ou Windows. Precisa ficar ligado para coletar. |
| **Python 3.9+** | |
| **MySQL** ou **MariaDB** | |
| Servidor web com **PHP 8+** e extensão `pdo_mysql` | Só se você quiser o painel web. |

---

## Passo 1: pegar os dados da tomada no Tuya IoT

Você só faz isso **uma vez por tomada**. O objetivo é descobrir três informações: **Device ID**, **MAC** e **local_key**.

### 1.1 Cadastre a tomada no app Smart Life

Se a tomada já está no Smart Life, pule esta etapa.

Se a tomada veio com o app de outra marca (vários fabricantes usam a Tuya por baixo), o caminho mais simples é cadastrá-la direto no **Smart Life**. O leitor de QR code dos apps de marca geralmente **não funciona** no Passo 1.5.

### 1.2 Crie uma conta de desenvolvedor

Acesse <https://platform.tuya.com> e crie uma conta gratuita.

### 1.3 Crie um projeto de nuvem

1. No menu lateral, vá em **Cloud → Development → Create Cloud Project**.
2. Preencha:
   - **Project Name**: qualquer nome (ex.: `Tomada`).
   - **Industry**: qualquer opção (ex.: *Smart Home*).
   - **Development Method**: *Smart Home*.
   - **Data Center**: o da sua região (veja a dica abaixo).
3. Na tela de serviços (APIs), mantenha os que vierem marcados e confirme.

> 💡 **Qual data center escolher?** A Tuya publica uma tabela de país → data center, mas **ela nem sempre acerta** para contas de apps. Em um teste com uma conta do Brasil, a tabela indicava *Eastern America* e o que funcionou foi **Western America**. Se o Passo 1.5 der erro, é só trocar (explicado lá).

### 1.4 Copie as credenciais do projeto

Na aba **Overview** do projeto, copie:

- **Access ID / Client ID**
- **Access Secret / Client Secret** (clique no ícone de olho para revelar)

> ⚠️ Use o **botão de copiar** da página. Não digite olhando para a tela: caracteres parecidos (`l`/`1`, `O`/`0`) causam o erro `clientId is invalid` no Passo 1.7.

### 1.5 Vincule sua conta do Smart Life ao projeto

1. Abra a aba **Devices → Link App Account**.
2. No canto superior direito da página, confira o **seletor de data center**.
3. Clique em **Add App Account → Tuya App Account Authorization**.
   - A opção *Configure OAuth 2.0 Authorization* **não** gera QR code. Não é ela.
4. Escolha o modo **Automatic** (e **Read Only**, se aparecer). Um **QR code** vai aparecer.
5. No celular, abra o **Smart Life → aba Eu (Me) → ícone de leitor no canto superior direito**, escaneie o QR code e confirme.
6. Volte à aba **All Devices**: sua tomada deve aparecer na lista.

**Se der erro:**

| Mensagem | O que fazer |
|---|---|
| `Data centers inconsistency, App account cannot be linked` | Troque o **seletor de data center no canto superior direito** da aba Devices e clique em *Add App Account* de novo. Teste um por um (Western America, Eastern America, Central Europe...) até o erro sumir. |
| `QR code has expired` | Clique em *Refresh QR Code* e escaneie logo (ele expira em cerca de 2 minutos). Deixe o app aberto antes de gerar. |
| Escaneou e nada aconteceu | Use o app **Smart Life** para escanear, não o app da marca da tomada. |

### 1.6 Instale o tinytuya

Em qualquer computador com Python:

```bash
pip install tinytuya
```

### 1.7 Rode o assistente (wizard) para baixar a local_key

```bash
python -m tinytuya wizard
```

Ele vai pedir:

- **API Key**: o *Access ID* do Passo 1.4.
- **API Secret**: o *Access Secret* do Passo 1.4.
- **Device ID**: o ID de qualquer dispositivo seu (aparece em *All Devices*), ou digite `scan`.
- **Region**: o código do data center que funcionou no Passo 1.5:

| Data center na Tuya | Código no wizard |
|---|---|
| Western America | `us` |
| Eastern America | `us-e` |
| Central Europe | `eu` |
| Western Europe | `eu-w` |
| India | `in` |
| China | `cn` |
| Singapore | `sg` |

Quando ele perguntar se quer fazer *poll* dos dispositivos, pode responder **n**.

O wizard gera um arquivo **`devices.json`**. Procure a sua tomada nele:

```json
{
    "name": "Minha Tomada",
    "id": "ebxxxxxxxxxxxxxxxxxxxx",      <-- device_id
    "key": "xxxxxxxxxxxxxxxx",          <-- local_key
    "mac": "aa:bb:cc:dd:ee:ff",         <-- device_mac
    ...
}
```

> 🔒 O `devices.json` contém a chave de acesso da tomada. **Não publique esse arquivo** (ele já está no `.gitignore`).

> ℹ️ O teste gratuito do *IoT Core* da Tuya expira depois de um tempo. **Isso não afeta o coletor**, que usa só a rede local. Você só precisaria da plataforma de novo se **recadastrar a tomada** (resetar/trocar o Wi-Fi), porque isso gera uma `local_key` nova. Para tomadas novas na mesma conta, basta rodar o wizard de novo: o projeto e o vínculo já estão feitos.

---

## Passo 2: preencher o config.json

Copie o arquivo de exemplo:

```bash
cp config.example.json config.json
```

No Windows: `copy config.example.json config.json`.

Depois edite o **`config.json`**:

```json
{
  "device_id": "ebxxxxxxxxxxxxxxxxxxxx",
  "device_mac": "aa:bb:cc:dd:ee:ff",
  "local_key": "xxxxxxxxxxxxxxxx",
  "protocol_version": 3.5,
  "subnet": "192.168.1.0/24",
  "timezone": "America/Sao_Paulo",
  "db": {
    "host": "localhost",
    "user": "tomada_user",
    "password": "sua_senha",
    "database": "tomada_db"
  }
}
```

| Campo | De onde vem |
|---|---|
| `device_id` | `"id"` no `devices.json` |
| `device_mac` | `"mac"` no `devices.json`. Pode ser com `:` ou `-`. É assim que o coletor acha a tomada na rede. |
| `local_key` | `"key"` no `devices.json` |
| `protocol_version` | Normalmente `3.5`, `3.4` ou `3.3`. Rode `python -m tinytuya scan` para ver a versão. Se a tomada não aparecer no scan (algumas não se anunciam), teste `3.5`, depois `3.4`, depois `3.3`. |
| `subnet` | A faixa da sua rede local. Se o seu IP é `192.168.0.15`, use `192.168.0.0/24`. |
| `timezone` | Seu fuso horário (ex.: `America/Sao_Paulo`, `America/Manaus`). |
| `db` | Usuário, senha e banco criados no Passo 3 (ou na seção do Windows). |

> 💡 **Recomendado:** reserve um IP fixo para a tomada no roteador (procure por "DHCP reservation" ou "reserva de endereço"). O coletor funciona sem isso, mas a descoberta pelo MAC fica sendo só um plano B.

---

## Passo 3: instalar no Linux (Debian/Ubuntu)

### 3.1 Pacotes do sistema

```bash
sudo apt update
sudo apt install -y git python3 python3-venv python3-pip mariadb-server nmap

# Somente se for usar o painel web:
sudo apt install -y apache2 php libapache2-mod-php php-mysql
```

O `nmap` é usado para achar a tomada na rede pelo MAC. Ele é opcional, mas deixa a descoberta muito mais rápida.

### 3.2 Baixe o projeto

```bash
sudo git clone https://github.com/szardo/tomada-tuya-monitor.git /opt/tomada-tuya-monitor
cd /opt/tomada-tuya-monitor

sudo python3 -m venv venv
sudo venv/bin/pip install -r requirements.txt

sudo cp config.example.json config.json
sudo nano config.json        # preencha conforme o Passo 2
```

### 3.3 Crie o banco de dados

Troque `troque_esta_senha` por uma senha sua. Use a mesma senha no `config.json`.

```bash
sudo mysql -e "CREATE DATABASE tomada_db CHARACTER SET utf8mb4;"
sudo mysql -e "CREATE USER 'tomada_user'@'localhost' IDENTIFIED BY 'troque_esta_senha';"
sudo mysql -e "GRANT ALL PRIVILEGES ON tomada_db.* TO 'tomada_user'@'localhost'; FLUSH PRIVILEGES;"
sudo mysql tomada_db < schema.sql
```

O horário gravado em cada leitura vem do banco. Confira se o fuso do servidor está certo:

```bash
timedatectl                                        # mostra o fuso atual
sudo timedatectl set-timezone America/Sao_Paulo    # ajuste se precisar
sudo systemctl restart mariadb
```

### 3.4 Teste o coletor manualmente

```bash
sudo /opt/tomada-tuya-monitor/venv/bin/python /opt/tomada-tuya-monitor/coletor_tomada.py
```

A saída esperada é algo assim:

```
[2026-09-23 20:05:31] INFO: IP conhecido (None) falhou, refazendo descoberta por MAC...
[2026-09-23 20:05:34] INFO: IP da tomada mudou: None -> 192.168.1.50
[2026-09-23 20:05:34] INFO: Sucesso (192.168.1.50): ON | 5.9W | 123.1V | 102mA
```

Na primeira execução é normal ele "descobrir" o IP. Nas próximas, ele usa o IP guardado.

### 3.5 Agende a coleta a cada minuto (crontab)

Abra o crontab do root:

```bash
sudo crontab -e
```

Adicione esta linha no final do arquivo:

```cron
* * * * * /opt/tomada-tuya-monitor/venv/bin/python /opt/tomada-tuya-monitor/coletor_tomada.py >> /opt/tomada-tuya-monitor/coletor_tomada.log 2>&1
```

Os cinco `*` significam "todo minuto, toda hora, todo dia". Salve e feche o editor. No `nano`, é `Ctrl+O`, `Enter`, `Ctrl+X`.

Para conferir se está rodando:

```bash
sudo crontab -l                                         # mostra a linha agendada
tail -f /opt/tomada-tuya-monitor/coletor_tomada.log     # uma linha nova por minuto (Ctrl+C para sair)
```

<details>
<summary><b>Opcional:</b> evitar que o log cresça sem limite (logrotate)</summary>

Crie o arquivo `/etc/logrotate.d/tomada-tuya-monitor` com:

```
/opt/tomada-tuya-monitor/coletor_tomada.log {
    weekly
    rotate 4
    compress
    missingok
    notifempty
    copytruncate
}
```

</details>

### 3.6 Painel web

```bash
sudo mkdir -p /var/www/html/tomada
sudo cp /opt/tomada-tuya-monitor/web/*.php /var/www/html/tomada/
sudo cp /var/www/html/tomada/config.example.php /var/www/html/tomada/config.php
sudo nano /var/www/html/tomada/config.php     # mesmos dados do banco + fuso horário
```

No `config.php`, você também pode ativar o **custo estimado** do relatório. Para isso, descomente a linha `TARIFA_KWH` e coloque o valor do kWh da sua conta de luz.

Acesse `http://IP-DO-SERVIDOR/tomada/`.

---

## Alternativa: rodar no Windows

Funciona sim. O coletor é o mesmo e detecta o sistema sozinho. O computador precisa ficar ligado e na mesma rede da tomada.

### W.1 Instale os programas

1. **Python 3.10+** em <https://www.python.org/downloads/>. Na instalação, marque **"Add python.exe to PATH"**.
2. **XAMPP** em <https://www.apachefriends.org>. Ele traz o **MariaDB**, o **Apache** e o **PHP** juntos. Depois de instalar, abra o *XAMPP Control Panel* e clique em **Start** no **MySQL** (e no **Apache**, se quiser o painel web).
3. Opcional: **Nmap** em <https://nmap.org/download.html#windows>. Ele deixa a descoberta da tomada muito mais rápida. Sem ele, o coletor testa os 254 endereços da rede um por um, o que pode levar alguns minutos, mas isso só acontece quando o IP da tomada muda.

### W.2 Baixe o projeto e instale as dependências

Baixe o ZIP pelo botão verde **Code → Download ZIP** e extraia em `C:\tomada-tuya-monitor`. Se preferir, use `git clone`.

Depois, abra o **Prompt de Comando** e rode:

```bat
cd C:\tomada-tuya-monitor
python -m venv venv
venv\Scripts\pip install -r requirements.txt
copy config.example.json config.json
notepad config.json
```

Preencha o `config.json` conforme o Passo 2.

### W.3 Crie o banco de dados

Abra <http://localhost/phpmyadmin>, vá na aba **SQL** e rode o código abaixo. Troque a senha e use a mesma no `config.json`:

```sql
CREATE DATABASE tomada_db CHARACTER SET utf8mb4;
CREATE USER 'tomada_user'@'localhost' IDENTIFIED BY 'troque_esta_senha';
GRANT ALL PRIVILEGES ON tomada_db.* TO 'tomada_user'@'localhost';
FLUSH PRIVILEGES;
```

Depois, selecione o banco **tomada_db** na lateral esquerda, abra a aba **SQL** de novo, cole o conteúdo do arquivo **`schema.sql`** e execute.

### W.4 Teste o coletor

```bat
cd C:\tomada-tuya-monitor
venv\Scripts\python coletor_tomada.py
```

A saída deve terminar com uma linha `Sucesso (...): ON | ...W | ...V | ...mA`.

### W.5 Agende a coleta a cada minuto (Agendador de Tarefas)

Abra o **Prompt de Comando como Administrador** e rode:

```bat
schtasks /Create /TN "Coletor Tomada Tuya" /SC MINUTE /MO 1 /TR "C:\tomada-tuya-monitor\rodar_coletor.bat" /RU SYSTEM /F
```

- O arquivo `rodar_coletor.bat` já vem no projeto. Ele usa o Python do `venv` e grava o resultado em `coletor_tomada.log`.
- O `/RU SYSTEM` faz a tarefa rodar **em segundo plano**, sem abrir janela a cada minuto, mesmo sem ninguém logado.
- Se você extraiu o projeto em outra pasta, ajuste o caminho no comando.

Para conferir ou remover a tarefa:

```bat
schtasks /Query /TN "Coletor Tomada Tuya"
type C:\tomada-tuya-monitor\coletor_tomada.log
schtasks /Delete /TN "Coletor Tomada Tuya" /F
```

### W.6 Painel web no Windows

1. Crie a pasta `C:\xampp\htdocs\tomada\` e copie para ela todos os arquivos `.php` da pasta `web\` do projeto.
2. Dentro dela, copie o `config.example.php` para `config.php` e preencha os dados do banco.
3. Acesse <http://localhost/tomada/>.

---

## Problemas comuns

| Sintoma | Causa provável / solução |
|---|---|
| `Tomada nao encontrada na rede` | O computador não está na mesma rede/sub-rede da tomada, o `subnet` está errado, o MAC está errado ou a tomada está desligada da energia/Wi-Fi. Confira se ela aparece online no app. |
| `Network Error: Device Unreachable` (`905`) | O IP guardado ficou velho. Na execução seguinte o coletor busca pelo MAC de novo. Se persistir, apague o `tomada_state.json`. |
| Erros de decodificação / `914` / resposta vazia | A `local_key` ou a `protocol_version` estão erradas. Se a tomada foi recadastrada no app, a `local_key` mudou: rode o wizard de novo (Passo 1.7). |
| `clientId is invalid` no wizard | O Access ID foi digitado errado. Copie de novo pelo botão de copiar (Passo 1.4). Também confira a região. |
| Potência/tensão com valores 10× maiores ou menores | Sua tomada usa códigos (DPS) diferentes. Veja a seção abaixo. |
| Horários errados no painel | Ajuste o fuso do servidor/banco (Passo 3.3) e o `date_default_timezone_set` e `DB_TIMEZONE` no `config.php`. |

### Tomadas com códigos de dados (DPS) diferentes

O coletor usa o padrão mais comum das tomadas Tuya com medição (categoria `cz`):

| DPS | Dado | Escala |
|---|---|---|
| `1` | ligada/desligada | — |
| `17` | energia acumulada | Wh |
| `18` | corrente | mA |
| `19` | potência | W × 10 (o coletor divide por 10) |
| `20` | tensão | V × 10 (o coletor divide por 10) |

Confira o bloco `"mapping"` da sua tomada no `devices.json`. Se for diferente, ajuste as linhas `dps.get("18")`, `dps.get("19")` e `dps.get("20")` no final do `coletor_tomada.py`.

### Sobre a precisão do consumo (kWh)

O consumo em kWh é **calculado a partir da potência** medida a cada minuto (integração ao longo do tempo), não vem de um medidor oficial. É ótimo para comparar dias e impressões, mas pode ter pequenas diferenças em relação à conta de luz. No relatório de período, intervalos sem leitura maiores que 3 minutos (tomada offline) não entram na conta, e a página avisa quando isso acontece.

### Mais de uma tomada

Hoje o projeto monitora **uma tomada por instalação**: a tabela não tem coluna de dispositivo. Suporte a várias tomadas é uma boa melhoria futura. Contribuições são bem-vindas.

---

## Estrutura do projeto

```
tomada-tuya-monitor/
├── coletor_tomada.py        # coletor (Linux/Windows), roda 1x por minuto
├── config.example.json      # modelo de configuração do coletor → copie para config.json
├── requirements.txt         # dependências Python (tinytuya, PyMySQL)
├── schema.sql               # criação da tabela consumo_tomada
├── rodar_coletor.bat        # atalho para o Agendador de Tarefas do Windows
└── web/
    ├── index.php            # redireciona para o painel
    ├── tomada.php           # painel: status ao vivo + gráficos dia/semana/mês
    ├── tomada_relatorio.php # relatório de consumo por período
    └── config.example.php   # modelo de configuração do painel → copie para config.php
```

---

## Segurança

- **Só leitura:** o coletor usa apenas `status()`. Nenhum comando de ligar/desligar é enviado à tomada.
- **A `local_key` dá controle total da tomada** para quem estiver na sua rede local. Nunca publique `config.json`, `devices.json` ou `web/config.php`. Todos já estão no `.gitignore`.
- **O painel web não tem login.** Deixe-o acessível só na sua rede local, sem expor na internet.

---

## Créditos

- [tinytuya](https://github.com/jasonacox/tinytuya): comunicação local com dispositivos Tuya.
- [Chart.js](https://www.chartjs.org/) e [Tailwind CSS](https://tailwindcss.com/): gráficos e visual do painel.
