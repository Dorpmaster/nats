#!/usr/bin/env python3
"""Run N7 cross-domain UPDATE on real leaf-connected NATS servers."""
import argparse
import json
from pathlib import Path
import subprocess
import tempfile
import time
import uuid

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--image', default='nats-client')
parser.add_argument('--output', type=Path)
args = parser.parse_args()


def docker(*arguments, **kwargs):
    return subprocess.run(['docker', *arguments], check=True, **kwargs)


monitoring = (Path(__file__).parent / 'atomic-monitoring.conf').read_text()
for version in ['2.15.2']:
    network = 'nats-n7-external-' + uuid.uuid4().hex[:10]
    containers = []
    with tempfile.TemporaryDirectory(prefix='nats-n7-external-') as directory:
        try:
            docker('network', 'create', network, stdout=subprocess.DEVNULL)
            for host, release, domain, leaf in [('hub', version, 'ORIGIN', 'port: 7422'), ('leaf', '2.15.2', 'DEST', 'remotes: [{url: "nats://local@hub:7422", account: HUB}]')]:
                config = Path(directory) / (host + '.conf')
                config.write_text('port: 4222\nhttp_port: 8222\nserver_name: ' + host + '\njetstream { domain: ' + domain + ', store_dir: /tmp/jetstream }\nleafnodes { ' + leaf + ' }\n' + monitoring)
                name = network + '-' + host
                containers.append(name)
                docker('run', '-d', '--name', name, '--network', network, '--network-alias', host,
                       '-v', str(config) + ':/etc/nats.conf:ro', 'nats:' + release + '-alpine', '-c', '/etc/nats.conf', stdout=subprocess.DEVNULL)
                deadline = time.monotonic() + 20
                while True:
                    ready = subprocess.run(['docker', 'exec', name, 'wget', '-qO-', 'http://127.0.0.1:8222/leafz'], capture_output=True)
                    if ready.returncode == 0 and (host == 'hub' or json.loads(ready.stdout)['leafnodes']):
                        break
                    if time.monotonic() >= deadline:
                        docker('logs', name)
                        raise RuntimeError('Leaf route did not become ready')
                    time.sleep(.1)
                print(docker('exec', name, 'nats-server', '-v', capture_output=True, text=True).stdout.strip(), flush=True)
            options = ['run', '--rm', '--user', 'root', '--network', network, '-e', 'NATS_SOURCES_EXTERNAL=1', '-e', 'NATS_EXPECTED_VERSION=2.15.2']
            if args.output:
                args.output.mkdir(parents=True, exist_ok=True)
                options += ['-v', str(args.output.resolve()) + ':/external-traces', '-e', 'NATS_SOURCES_TRACE_DIR=/external-traces']
            docker(*options, args.image, 'vendor/bin/phpunit', '--fail-on-skipped', 'tests/Integration/JetStreamSourcesExternalIntegrationTest.php')

        finally:
            for name in reversed(containers):
                subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            subprocess.run(['docker', 'network', 'rm', network], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
