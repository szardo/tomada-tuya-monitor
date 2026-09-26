"""
Coletor de consumo de tomadas inteligentes Tuya (Smart Life / Tuya Smart / apps de marca)
via protocolo LOCAL (porta 6668) -- sem depender da nuvem Tuya no dia a dia.

So faz LEITURA (.status()). Nunca envia comandos de liga/desliga.

Le a configuracao de config.json (copie config.example.json e preencha).
Resolve o IP atual do dispositivo pelo MAC (o IP muda por DHCP na maioria
das redes domesticas), guardando o ultimo IP conhecido em tomada_state.json
para nao precisar escanear a rede toda vez.

Funciona em Linux e Windows (usa nmap se disponivel; senao cai para ping
sequencial, mais lento).
"""
import json
import logging
import os
import platform
import re
import subprocess
import sys
import time

import pymysql
import tinytuya

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_FILE = os.environ.get("TOMADA_CONFIG", os.path.join(BASE_DIR, "config.json"))
STATE_FILE = os.path.join(BASE_DIR, "tomada_state.json")

logging.basicConfig(
    level=logging.INFO,
    format='[%(asctime)s] %(levelname)s: %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)


def carregar_config():
    if not os.path.exists(CONFIG_FILE):
        logging.error(
            f"Arquivo de configuracao nao encontrado: {CONFIG_FILE}\n"
            f"Copie config.example.json para config.json e preencha os dados "
            f"(veja o README)."
        )
        sys.exit(1)
    with open(CONFIG_FILE, encoding="utf-8") as f:
        cfg = json.load(f)
    if cfg.get("timezone"):
        os.environ['TZ'] = cfg["timezone"]
        if hasattr(time, "tzset"):
            time.tzset()
    return cfg


def carregar_ip_conhecido():
    try:
        with open(STATE_FILE) as f:
            return json.load(f).get("ip")
    except Exception:
        return None


def salvar_ip(ip):
    try:
        with open(STATE_FILE, "w") as f:
            json.dump({"ip": ip}, f)
    except Exception as e:
        logging.warning(f"Nao foi possivel salvar tomada_state.json: {e}")


def _ping_um(ip, timeout_s=1):
    if platform.system() == "Windows":
        cmd = ["ping", "-n", "1", "-w", str(int(timeout_s * 1000)), ip]
    else:
        cmd = ["ping", "-c", "1", "-W", str(int(timeout_s)), ip]
    subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def resolver_ip_por_mac(subnet, mac):
    """Faz um ping sweep na sub-rede e consulta a tabela ARP do SO pelo MAC
    do dispositivo. Retorna o IP encontrado ou None."""
    usou_nmap = False
    try:
        subprocess.run(["nmap", "-sn", subnet], capture_output=True, timeout=30)
        usou_nmap = True
    except FileNotFoundError:
        pass
    except Exception as e:
        logging.warning(f"Falha no nmap, tentando ping sequencial: {e}")

    if not usou_nmap:
        base = subnet.split('/')[0].rsplit('.', 1)[0]
        for i in range(1, 255):
            _ping_um(f"{base}.{i}")

    try:
        if platform.system() == "Windows":
            saida = subprocess.run(["arp", "-a"], capture_output=True, text=True, timeout=10).stdout
            mac_alvo = mac.lower().replace(":", "-")
        else:
            saida = subprocess.run(["ip", "neigh"], capture_output=True, text=True, timeout=10).stdout
            mac_alvo = mac.lower()
    except Exception as e:
        logging.error(f"Falha ao ler tabela ARP: {e}")
        return None

    for linha in saida.splitlines():
        if mac_alvo in linha.lower():
            m = re.search(r"(\d+\.\d+\.\d+\.\d+)", linha)
            if m:
                return m.group(1)
    return None


def ler_status(device_id, ip, local_key, protocol_version):
    d = tinytuya.OutletDevice(device_id, ip, local_key)
    d.set_version(protocol_version)
    d.set_socketPersistent(False)
    d.set_socketTimeout(6)
    return d.status()


def coletar_dados(cfg):
    device_id = cfg["device_id"]
    device_mac = cfg["device_mac"]
    local_key = cfg["local_key"]
    protocol_version = cfg.get("protocol_version", 3.5)
    subnet = cfg.get("subnet", "192.168.1.0/24")

    ip = carregar_ip_conhecido()

    r = ler_status(device_id, ip, local_key, protocol_version) if ip else None
    if not r or "Error" in r:
        logging.info(f"IP conhecido ({ip}) falhou, refazendo descoberta por MAC...")
        ip_novo = resolver_ip_por_mac(subnet, device_mac)
        if not ip_novo:
            logging.info("Tomada nao encontrada na rede (offline ou fora do alcance).")
            return
        if ip_novo != ip:
            logging.info(f"IP da tomada mudou: {ip} -> {ip_novo}")
            salvar_ip(ip_novo)
        ip = ip_novo
        r = ler_status(device_id, ip, local_key, protocol_version)

    if not r or "Error" in r or "dps" not in r:
        logging.error(f"Falha ao ler status da tomada em {ip}: {r}")
        return

    dps = r["dps"]
    ligado = bool(dps.get("1"))
    corrente_ma = dps.get("18")
    potencia_w = dps.get("19", 0) / 10.0
    tensao_v = dps.get("20", 0) / 10.0
    energia_wh = dps.get("17")

    db = cfg["db"]
    conexao = pymysql.connect(
        host=db["host"], user=db["user"], password=db["password"], database=db["database"]
    )
    try:
        with conexao.cursor() as cursor:
            sql = """INSERT INTO consumo_tomada
                     (potencia_w, corrente_ma, tensao_v, energia_acumulada_wh, ligado)
                     VALUES (%s, %s, %s, %s, %s)"""
            cursor.execute(sql, (potencia_w, corrente_ma, tensao_v, energia_wh, ligado))
        conexao.commit()
        logging.info(
            f"Sucesso ({ip}): {'ON' if ligado else 'OFF'} | {potencia_w}W | "
            f"{tensao_v}V | {corrente_ma}mA"
        )
    finally:
        conexao.close()


if __name__ == "__main__":
    try:
        coletar_dados(carregar_config())
    except Exception as e:
        logging.error(f"Erro geral no coletor: {e}")
