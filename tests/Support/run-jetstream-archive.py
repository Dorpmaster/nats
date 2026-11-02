#!/usr/bin/env python3
"""Run streaming V2 archive checks on pinned NATS versions or an R3 cluster."""
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
network = 'nats-archive-' + uuid.uuid4().hex[:10]
containers = []


def docker(*arguments, **kwargs):
    return subprocess.run(['docker', *arguments], check=True, **kwargs)


def ready(name):
    end = time.monotonic() + 30
    while subprocess.run(['docker', 'exec', name, 'wget', '-qO-', 'http://localhost:8222/healthz'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode:
        if time.monotonic() >= end:
            docker('logs', name)
            raise RuntimeError('NATS startup timed out')
        time.sleep(.1)


def test(version, cluster=False):
    mode = 'cluster' if cluster else ('modern' if version == '2.15.2' else 'old')
    pattern = 'testV2Cluster' if cluster else ('testV2(?!Cluster)' if mode == 'modern' else 'testOld')
    options = ['run', '--rm', '--user', 'root', '--network', network,
               '-e', 'NATS_HOST=' + ('n1' if cluster else 'server'), '-e', 'NATS_PORT=4222',
               '-e', 'NATS_ARCHIVE_MODE=' + mode, '-e', 'NATS_RESET_CLUSTER=' + ('1' if cluster else '0')]
    if args.output:
        output = args.output.resolve() / ('cluster' if cluster else version)
        output.mkdir(parents=True, exist_ok=True)
        options += ['-v', str(output) + ':/archive-traces', '-e', 'NATS_ARCHIVE_TRACE_DIR=/archive-traces']
    print('V2 archives:', version, mode, flush=True)
    docker(*options, args.image, 'vendor/bin/phpunit', '--fail-on-skipped', '--filter', pattern,
           'tests/Integration/JetStreamBackupIntegrationTest.php')


accounts = '\naccounts {A {jetstream: enabled, users: [{user: a, password: synthetic-n8-a}]}}\n'
with tempfile.TemporaryDirectory(prefix='nats-archive-') as directory:
    try:
        docker('network', 'create', network, stdout=subprocess.DEVNULL)
        if args.cluster:
            for index in range(1, 4):
                node = 'n' + str(index)
                routes = ','.join('"nats://n' + str(other) + ':6222"' for other in range(1, 4) if other != index)
                config = Path(directory) / (node + '.conf')
                config.write_text('server_name: ' + node + '\nport: 4222\nhttp_port: 8222\njetstream {domain: EU, store_dir: /tmp/js, max_memory_store: 128MB}\ncluster {name: N9, listen: "0.0.0.0:6222", routes: [' + routes + ']}\n' + accounts)
                name = network + '-' + node
                containers.append(name)
                docker('run', '-d', '--name', name, '--network', network, '--network-alias', node,
                       '-v', str(config) + ':/server.conf:ro', 'nats:2.15.2-alpine', '-c', '/server.conf', stdout=subprocess.DEVNULL)
            for name in containers:
                ready(name)
            test('2.15.2', cluster=True)
        else:
            config = Path(directory) / 'server.conf'
            config.write_text('port: 4222\nhttp_port: 8222\njetstream {domain: EU, store_dir: /tmp/js}\n' + accounts)
            for version in args.version or ['2.12.6', '2.14.0', '2.15.2']:
                name = network + '-server'
                containers.append(name)
                docker('run', '-d', '--name', name, '--network', network, '--network-alias', 'server',
                       '-v', str(config) + ':/server.conf:ro', 'nats:' + version + '-alpine', '-c', '/server.conf', stdout=subprocess.DEVNULL)
                ready(name)
                print(docker('exec', name, 'nats-server', '-v', capture_output=True, text=True).stdout.strip(), flush=True)
                test(version)
                docker('rm', '-f', name, stdout=subprocess.DEVNULL)
                containers.remove(name)
    finally:
        for name in reversed(containers):
            subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        subprocess.run(['docker', 'network', 'rm', network], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
