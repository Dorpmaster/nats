#!/usr/bin/env python3
"""Run the isolated TLS certificate matrix; requires Docker, Python 3 only."""
import argparse
from pathlib import Path
import subprocess
import tempfile
import time
import uuid

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--image', default='nats-client')
parser.add_argument('--filter')
args = parser.parse_args()
root = Path(__file__).resolve().parents[3]
fixtures = root / 'tests/Support/tls/self-signed'
network = 'nats-tls-matrix-' + uuid.uuid4().hex[:10]
containers = []

def docker(*args, **kwargs):
    return subprocess.run(['docker', *args], check=True, **kwargs)

with tempfile.TemporaryDirectory(prefix='nats-tls-matrix-') as directory:
    try:
        docker('network', 'create', network, stdout=subprocess.DEVNULL)
        for host, certificate in [('self', 'self'), ('self2', 'self'), ('expired', 'expired'), ('trusted', 'trusted'), ('untrusted', 'untrusted'), ('mtls', 'trusted'), ('weak', 'weak')]:
            name = network + '-' + host
            config = Path(directory) / (host + '.conf')
            tls = f'cert_file: "/certs/{certificate}.pem", key_file: "/certs/{certificate}-key.pem", timeout: 2'
            if host == 'mtls':
                tls += ', ca_file: "/certs/ca.pem", verify: true'
            config.write_text('port: 4222\nhttp_port: 8222\ntls { ' + tls + ' }\n')
            containers.append(name)
            docker('run', '-d', '--name', name, '--network', network, '--network-alias', host,
                   '-v', str(fixtures) + ':/certs:ro', '-v', str(config) + ':/etc/nats.conf:ro',
                   'nats:2.15.2-alpine', '-c', '/etc/nats.conf', stdout=subprocess.DEVNULL)
            deadline = time.monotonic() + 15
            while True:
                result = subprocess.run(['docker', 'exec', name, 'wget', '-q', '-O', '-', 'http://127.0.0.1:8222/healthz'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                if result.returncode == 0:
                    break
                if time.monotonic() >= deadline:
                    docker('logs', name)
                    raise RuntimeError('NATS did not become ready: ' + name)
                time.sleep(.1)
        print('NATS Server:', docker('exec', containers[0], 'nats-server', '-v', capture_output=True, text=True).stdout.strip(), flush=True)
        socket = '/var/run/docker.sock' if Path('/var/run/docker.sock').exists() else str(Path.home() / '.docker/run/docker.sock')
        command = ['run', '--rm', '--user', 'root', '--network', network,
                   '-v', socket + ':/var/run/docker.sock', '-e', 'NATS_TLS_MATRIX=1',
                   '-e', 'NATS_HOST=self', '-e', 'NATS_CONTAINER=' + containers[0],
                   args.image, 'vendor/bin/phpunit', '--testsuite', 'Integration',
                   '--fail-on-skipped', 'tests/Integration/TlsSelfSignedIntegrationTest.php']
        if args.filter:
            command += ['--filter', args.filter]
        result = subprocess.run(['docker', *command])
        raise SystemExit(result.returncode)
    finally:
        for name in reversed(containers):
            subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        subprocess.run(['docker', 'network', 'rm', network], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
