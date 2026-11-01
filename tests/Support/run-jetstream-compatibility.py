#!/usr/bin/env python3
"""Run JetStream N1 and existing regressions on fixed NATS versions and real prefixes."""
import argparse
from pathlib import Path
import subprocess
import tempfile
import time
import uuid

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--image', default='nats-client')
parser.add_argument('--version', action='append', choices=['2.12.6', '2.14.0', '2.15.2'])
args = parser.parse_args()
network = 'nats-js-compat-' + uuid.uuid4().hex[:10]
containers = []

def docker(*arguments, **kwargs):
    return subprocess.run(['docker', *arguments], check=True, **kwargs)

with tempfile.TemporaryDirectory(prefix='nats-js-compat-') as directory:
    try:
        docker('network', 'create', network, stdout=subprocess.DEVNULL)
        config = Path(directory) / 'server.conf'
        config.write_text('''port: 4222
http_port: 8222
jetstream { domain: EU, store_dir: /tmp/jetstream }
no_auth_user: local
accounts {
    HUB {
        jetstream: enabled
        users: [{user: local}]
        exports: [{service: "$JS.API.>", response: stream}]
    }
    APP {
        users: [{user: importer, password: synthetic-n1-password}]
        imports: [{service: {account: HUB, subject: "$JS.API.>"}, to: "IMPORT@EU+API.>"}]
    }
}
''')
        for version in args.version or ['2.12.6', '2.14.0', '2.15.2']:
            name = network + '-' + version.replace('.', '-')
            containers.append(name)
            docker('run', '-d', '--name', name, '--network', network, '--network-alias', 'nats-' + version,
                   '-v', str(config) + ':/etc/nats.conf:ro', 'nats:' + version + '-alpine', '-c', '/etc/nats.conf', stdout=subprocess.DEVNULL)
            deadline = time.monotonic() + 20
            while subprocess.run(['docker', 'exec', name, 'wget', '-qO-', 'http://127.0.0.1:8222/healthz'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode:
                if time.monotonic() >= deadline:
                    docker('logs', name)
                    raise RuntimeError('NATS not ready: ' + version)
                time.sleep(.1)
            print('NATS:', docker('exec', name, 'nats-server', '-v', capture_output=True, text=True).stdout.strip(), flush=True)
            # Original suites run as well, using the unchanged standard prefix and no credentials.
            socket = '/var/run/docker.sock' if Path('/var/run/docker.sock').exists() else str(Path.home() / '.docker/run/docker.sock')
            docker('run', '--rm', '--user', 'root', '--network', network,
                   '-v', socket + ':/var/run/docker.sock', '-e', 'NATS_CONTAINER=' + name,
                   '-e', 'NATS_HOST=nats-' + version,
                   '-e', 'NATS_PORT=4222', '-e', 'NATS_JS=1', '-e', 'NATS_JS_COMPATIBILITY=1',
                   '-e', 'NATS_EXPECTED_VERSION=' + version, args.image, 'vendor/bin/phpunit',
                   '--testsuite', 'Integration', '--fail-on-skipped',
                   'tests/Integration/JetStreamCompatibilityIntegrationTest.php',
                   'tests/Integration/JetStreamAdminIntegrationTest.php',
                   'tests/Integration/JetStreamPublishIntegrationTest.php',
                   'tests/Integration/JetStreamPullConsumerIntegrationTest.php',
                   'tests/Integration/JetStreamPullConsumeIntegrationTest.php')
    finally:
        for name in reversed(containers):
            subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        subprocess.run(['docker', 'network', 'rm', network], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
