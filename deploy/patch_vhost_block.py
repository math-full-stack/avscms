#!/usr/bin/env python3
"""AVSCMS — adiciona o bloco de segurança ao vhost Apache da VM (idempotente).

Uso (na VM): sudo python3 patch_vhost_block.py
Cria backup em avscms.conf.bak-sec na primeira execução.
"""
import os
import shutil

P = "/etc/apache2/sites-available/avscms.conf"
BACKUP = P + ".bak-sec"

BLOCK = """
    # ---- SECURITY: never serve code/secret dirs over HTTP ----
    # include/ holds config.*.php and gcs-service-account.json (private
    # service-account key). deploy/ and sql/ are deploy-time only.
    # Must sit OUTSIDE <Directory> (DirectoryMatch is not allowed nested).
    <DirectoryMatch "^/var/www/avscms/(include|deploy|sql)(/|$)">
        Require all denied
    </DirectoryMatch>
"""

with open(P) as f:
    src = f.read()

if "DirectoryMatch" in src and "Require all denied" in src:
    print("bloco ja presente; nada a fazer")
    raise SystemExit(0)

if not os.path.exists(BACKUP):
    shutil.copy2(P, BACKUP)
    print("backup criado:", BACKUP)

marker = "    <Directory /var/www/avscms>"
if marker not in src:
    print("ERRO: marcador '<Directory /var/www/avscms>' nao encontrado; vhost diferente do esperado")
    raise SystemExit(1)

src = src.replace(marker, BLOCK + "\n" + marker, 1)
with open(P, "w") as f:
    f.write(src)
print("bloco inserido em", P)