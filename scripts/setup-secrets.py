#!/usr/bin/env python3
"""Generate local credentials for a NEW installation, without printing secrets."""
import os
from pathlib import Path
import secrets

root = Path(__file__).resolve().parent.parent
env_path = root / '.env'
secret_dir = root / '.local-secrets'
if env_path.exists() or secret_dir.exists():
    raise SystemExit('Local configuration already exists; refusing to overwrite credentials.')
os.umask(0o077)
secret_dir.mkdir(mode=0o700)
values = {
    'WEB_PORT': '8080', 'DB_PORT': '33066',
    'DB_NAME': 'testdb', 'DB_USER': 'testdb',
    'DB_PASSWORD': secrets.token_hex(32),
    'DB_ROOT_PASSWORD': secrets.token_hex(32),
    'AES_KEY': secrets.token_hex(32),
}
with env_path.open('x') as handle:
    handle.write(''.join(f'{name}={value}\n' for name, value in values.items()))
with (secret_dir / 'admin-password').open('x') as handle:
    handle.write(secrets.token_hex(24) + '\n')
print('Created .env and .local-secrets/admin-password (private, ignored by Git).')
