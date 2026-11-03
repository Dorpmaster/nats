#!/usr/bin/env python3
"""Run N7 source/mirror ownership and copying on pinned releases or an R3 cluster."""
import argparse
from pathlib import Path
import subprocess
import tempfile
import time
import uuid

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--image', default='nats-client')
parser.add_argument('--version', action='append', choices=['2.12.6', '2.14.0', '2.15.2'])
parser.add_argument('--cluster', action='store_true')
parser.add_argument('--output', type=Path)
args = parser.parse_args()
network = 'nats-sources-' + uuid.uuid4().hex[:10]
containers = []


def docker(*arguments, **kwargs):
    return subprocess.run(['docker', *arguments], check=True, **kwargs)


def ready(name):
    deadline = time.monotonic() + 30
    while subprocess.run(['docker', 'exec', name, 'wget', '-qO-', 'http://127.0.0.1:8222/healthz'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode:
        if time.monotonic() >= deadline:
            docker('logs', name)
            raise RuntimeError('NATS not ready: ' + name)
        time.sleep(.1)


def test(version, host, cluster=False):
    options = ['run', '--rm', '--user', 'root', '--network', network,
               '-e', 'NATS_HOST=' + host, '-e', 'NATS_PORT=4222', '-e', 'NATS_EXPECTED_VERSION=' + version,
               '-e', 'NATS_SOURCES=' + ('0' if cluster else '1'), '-e', 'NATS_SOURCES_CLUSTER=' + ('1' if cluster else '0'),
               '-e', 'NATS_RESET_CLUSTER=' + ('1' if cluster else '0')]
    label = 'cluster' if cluster else version
    if args.output:
        directory = args.output.resolve() / label
        directory.mkdir(parents=True, exist_ok=True)
        options += ['-v', str(directory) + ':/source-traces', '-e', 'NATS_SOURCES_TRACE_DIR=/source-traces']
    print('SOURCES matrix:', version, label, flush=True)
    docker(*options, args.image, 'vendor/bin/phpunit', '--fail-on-skipped', '--filter',
           '::testReplicated' if cluster else '::test(?!Replicated)',
           'tests/Integration/JetStreamSourcesIntegrationTest.php')


with tempfile.TemporaryDirectory(prefix='nats-sources-') as directory:
    try:
        docker('network', 'create', network, stdout=subprocess.DEVNULL)
        if args.cluster:
            for index in range(1, 4):
                node = 'n' + str(index)
                config = Path(directory) / (node + '.conf')
                routes = ','.join('"nats://n' + str(other) + ':6222"' for other in range(1, 4) if other != index)
                config.write_text('server_name: ' + node + '\nport: 4222\nhttp_port: 8222\njetstream {domain: EU, store_dir: /tmp/js, max_file_store: 1GB, max_memory_store: 128MB}\ncluster {name: N3, listen: "0.0.0.0:6222", routes: [' + routes + ']}\n')
                name = network + '-' + node
                containers.append(name)
                docker('run', '-d', '--name', name, '--network', network, '--network-alias', node,
                       '-v', str(config) + ':/etc/nats.conf:ro', 'nats:2.15.2-alpine', '-c', '/etc/nats.conf', stdout=subprocess.DEVNULL)
            for name in containers:
                ready(name)
            test('2.15.2', 'n1', cluster=True)
        else:
            config = Path(directory) / 'server.conf'
            config.write_text('port: 4222\nhttp_port: 8222\njetstream {domain: EU, store_dir: /tmp/jetstream}\n')
            for version in args.version or ['2.12.6', '2.14.0', '2.15.2']:
                name = network + '-' + version.replace('.', '-')
                containers.append(name)
                docker('run', '-d', '--name', name, '--network', network, '--network-alias', 'server',
                       '-v', str(config) + ':/etc/nats.conf:ro', 'nats:' + version + '-alpine', '-c', '/etc/nats.conf', stdout=subprocess.DEVNULL)
                ready(name)
                print(docker('exec', name, 'nats-server', '-v', capture_output=True, text=True).stdout.strip(), flush=True)
                test(version, 'server')
                docker('rm', '-f', name, stdout=subprocess.DEVNULL)
                containers.remove(name)
    finally:
        for name in reversed(containers):
            subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        subprocess.run(['docker', 'network', 'rm', network], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
