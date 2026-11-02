#!/usr/bin/env python3
"""Pinned N2 matrix and a real replica transition held by paused target peers."""
import argparse
import json
from pathlib import Path
import socket
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
network = 'nats-n2-' + uuid.uuid4().hex[:10]
containers = []


def docker(*arguments, **kwargs):
    return subprocess.run(['docker', *arguments], check=True, **kwargs)


def wait_ready(name):
    deadline = time.monotonic() + 30
    while subprocess.run(['docker', 'exec', name, 'wget', '-qO-', 'http://127.0.0.1:8222/healthz'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode:
        if time.monotonic() >= deadline:
            docker('logs', name)
            raise RuntimeError('Server not ready: ' + name)
        time.sleep(.1)


def run_tests(host, version, label, v2=False, domain='', desired=False):
    options = ['run', '--rm', '--user', 'root', '--network', network,
               '-e', 'NATS_HOST=' + host, '-e', 'NATS_PORT=4222',
               '-e', 'NATS_EXPECTED_VERSION=' + version,
               '-e', 'NATS_METADATA=' + ('0' if desired else '1'),
               '-e', 'NATS_METADATA_DESIRED=' + ('1' if desired else '0'),
               '-e', 'NATS_ACK_V2=' + ('1' if v2 else '0'), '-e', 'NATS_METADATA_DOMAIN=' + domain]
    if args.output:
        directory = args.output.resolve() / label
        directory.mkdir(parents=True, exist_ok=True)
        options += ['-v', str(directory) + ':/n2-traces', '-e', 'NATS_METADATA_TRACE_DIR=/n2-traces']
    print('N2:', version, label, flush=True)
    docker(*options, args.image, 'vendor/bin/phpunit', '--fail-on-skipped',
           '--filter', 'testRealDesired' if desired else 'testDeliveryNak',
           'tests/Integration/JetStreamMetadataIntegrationTest.php')


class Wire:
    """Test-environment control only; the actual mapping tests use the public PHP Client/Admin."""
    def __init__(self, port, system=False):
        self.socket = socket.create_connection(('127.0.0.1', port), timeout=5)
        self.file = self.socket.makefile('rb')
        if not self.file.readline().startswith(b'INFO '):
            raise RuntimeError('Missing INFO')
        connect = {'verbose': False, 'pedantic': False, 'headers': True}
        if system:
            connect.update(user='sys', password='synthetic-n2-system-password')
            connect['pass'] = connect.pop('password')
        self.socket.sendall(b'CONNECT ' + json.dumps(connect).encode() + b'\r\n')
        self.sequence = 0

    def request(self, subject, payload):
        self.sequence += 1
        inbox = '_INBOX.N2.' + str(self.sequence)
        data = json.dumps(payload, separators=(',', ':')).encode()
        self.socket.sendall(('SUB ' + inbox + ' ' + str(self.sequence) + '\r\nUNSUB ' + str(self.sequence) + ' 1\r\nPUB ' + subject + ' ' + inbox + ' ' + str(len(data)) + '\r\n').encode() + data + b'\r\n')
        while True:
            line = self.file.readline()
            if not line:
                raise RuntimeError('Connection closed')
            if line == b'PING\r\n':
                self.socket.sendall(b'PONG\r\n')
            elif line.startswith(b'MSG '):
                size = int(line.split()[-1])
                raw = self.file.read(size)
                if self.file.read(2) != b'\r\n':
                    raise RuntimeError('Invalid response boundary')
                value = json.loads(raw)
                if 'error' in value:
                    raise RuntimeError('NATS API error: ' + repr(value['error']))
                return value, raw
            elif line.startswith(b'-ERR'):
                raise RuntimeError(line.decode())

    def close(self):
        self.file.close()
        self.socket.close()


with tempfile.TemporaryDirectory(prefix='nats-n2-') as directory:
    try:
        docker('network', 'create', network, stdout=subprocess.DEVNULL)
        if not args.cluster:
            for version in args.version or ['2.12.6', '2.14.0', '2.15.2']:
                # 2.12.6 has no ACK v2 feature flag (verified against that fixed source).
                modes = [(False, '')] if version == '2.12.6' else [(False, ''), (True, 'EU'), (True, '')]
                for index, (v2, domain) in enumerate(modes):
                    label = version + ('-v2-' + (domain or 'empty-domain') if v2 else '-classic')
                    name = network + '-' + label.lower()
                    config = Path(directory) / (label + '.conf')
                    config.write_text('port: 4222\nhttp_port: 8222\njetstream {store_dir: /tmp/jetstream' + (', domain: ' + domain if domain else '') + '}\n' + ('feature_flags { js_ack_fc_v2: true }\n' if v2 else ''))
                    containers.append(name)
                    docker('run', '-d', '--name', name, '--network', network, '--network-alias', 'server',
                           '-v', str(config) + ':/etc/nats.conf:ro', 'nats:' + version + '-alpine', '-c', '/etc/nats.conf', stdout=subprocess.DEVNULL)
                    wait_ready(name)
                    run_tests('server', version, label, v2, domain)
                    docker('rm', '-f', name, stdout=subprocess.DEVNULL)
                    containers.remove(name)
        else:
            for index in range(1, 8):
                node = 'n' + str(index)
                name = network + '-' + node
                config = Path(directory) / (node + '.conf')
                routes = ','.join('"nats://n' + str(other) + ':6222"' for other in range(1, 8) if other != index)
                config.write_text('server_name: ' + node + '\nserver_tags: ["' + ('n2-target' if index <= 5 else 'n2-extra') + '"]\nport: 4222\nhttp_port: 8222\njetstream {store_dir: /tmp/js, max_file_store: 1GB, max_memory_store: 128MB}\ncluster {name: N2, listen: "0.0.0.0:6222", routes: [' + routes + ']}\nno_auth_user: local\nsystem_account: SYS\naccounts {APP {jetstream: enabled, users: [{user: local}]}, SYS {users: [{user: sys, password: synthetic-n2-system-password}]}}\n')
                containers.append(name)
                docker('run', '-d', '--name', name, '--network', network, '--network-alias', node,
                       '-p', '127.0.0.1::4222', '-v', str(config) + ':/etc/nats.conf:ro',
                       'nats:2.15.2-alpine', '-c', '/etc/nats.conf', stdout=subprocess.DEVNULL)
            for name in containers:
                wait_ready(name)

            def port_for(node):
                return int(docker('inspect', '--format', '{{(index (index .NetworkSettings.Ports "4222/tcp") 0).HostPort}}', network + '-' + node, capture_output=True, text=True).stdout)

            monitor = 'n1'

            def meta():
                result = docker('exec', network + '-' + monitor, 'wget', '-qO-', 'http://127.0.0.1:8222/jsz', capture_output=True)
                return json.loads(result.stdout).get('meta_cluster', {})

            wire = Wire(port_for('n1'))
            held = []
            try:
                deadline = time.monotonic() + 30
                while True:
                    try:
                        info, _ = wire.request('$JS.API.STREAM.CREATE.N2_DESIRED', {'name': 'N2_DESIRED', 'subjects': ['n2.desired'], 'num_replicas': 3, 'storage': 'file', 'placement': {'tags': ['n2-target']}})
                        break
                    except RuntimeError:
                        if time.monotonic() >= deadline:
                            raise
                        time.sleep(.1)
                wire.request('n2.desired', {'marker': 'synthetic-n2-held-data'})
                deadline = time.monotonic() + 30
                while True:
                    info, _ = wire.request('$JS.API.STREAM.INFO.N2_DESIRED', {})
                    replicas = info['cluster'].get('replicas', [])
                    metadata = meta()
                    monitor = metadata.get('leader') or monitor
                    if (info['state']['messages'] == 1 and len(replicas) == 2 and all(peer['current'] and peer.get('lag', 0) == 0 for peer in replicas)
                            and len(metadata.get('replicas', [])) == 6
                            and all(peer['current'] for peer in metadata['replicas'])):
                        break
                    if time.monotonic() >= deadline:
                        print(json.dumps({'stream': info, 'meta': metadata}), flush=True)
                        raise RuntimeError('Initial R3 and seven metadata peers not current')
                    time.sleep(.1)
                active = info['cluster']['leader']
                current = {active, *(peer['name'] for peer in replicas)}
                targets = ['n' + str(index) for index in range(1, 6) if 'n' + str(index) not in current]
                # Keep metadata leadership on the unpaused nodes.
                if metadata['leader'] not in current:
                    system = Wire(port_for(metadata['leader']), system=True)
                    try:
                        system.request('$JS.API.META.LEADER.STEPDOWN', {'placement': {'preferred': active}})
                    finally:
                        system.close()
                    deadline = time.monotonic() + 15
                    while meta().get('leader') != active:
                        if time.monotonic() >= deadline:
                            raise RuntimeError('Metadata leadership barrier not reached')
                        time.sleep(.05)
                wire.close()
                wire = Wire(port_for(active))
                monitor = active
                # Seven metadata voters allow pausing both new stream peers plus one old follower.
                # R3 still has its two voters; R5 cannot reach three until this explicit gate opens.
                follower = next(peer['name'] for peer in replicas if peer['name'] != meta()['leader'])
                targets.append(follower)
                for node in targets:
                    name = network + '-' + node
                    docker('pause', name, stdout=subprocess.DEVNULL)
                    held.append(name)
                paused = [docker('inspect', '--format', '{{.State.Paused}}', name, capture_output=True, text=True).stdout.strip() for name in held]
                if paused != ['true', 'true', 'true']:
                    raise RuntimeError('Peer pause not acknowledged')
                print('Peer pause barrier:', targets, 'metadata leader:', meta()['leader'], flush=True)
                configuration = info['config']
                configuration['num_replicas'] = 5
                _, raw_update = wire.request('$JS.API.STREAM.UPDATE.N2_DESIRED', configuration)
                deadline = time.monotonic() + 10
                while True:
                    pending, raw = wire.request('$JS.API.STREAM.INFO.N2_DESIRED', {})
                    desired = pending['cluster'].get('desired')
                    if desired and desired.get('status') and len(desired.get('replicas', [])) == 5:
                        break
                    if time.monotonic() >= deadline:
                        raise RuntimeError('Held desired state not observed')
                    time.sleep(.1)
                if args.output:
                    args.output.mkdir(parents=True, exist_ok=True)
                    (args.output / 'desired-control.json').write_bytes(raw + b'\n')
                    (args.output / 'desired-update.json').write_bytes(raw_update + b'\n')
                    (args.output / 'barrier.json').write_text(json.dumps({'held': targets, 'paused': paused, 'initial': sorted(current), 'metadata_leader': meta()['leader']}, indent=2) + '\n')
                try:
                    run_tests(active, '2.15.2', 'cluster-held', desired=True)
                finally:
                    _, after_php = wire.request('$JS.API.STREAM.INFO.N2_DESIRED', {})
                    if args.output:
                        (args.output / 'desired-after-php.json').write_bytes(after_php + b'\n')
                for name in held:
                    docker('unpause', name, stdout=subprocess.DEVNULL)
                held.clear()
                deadline = time.monotonic() + 30
                while True:
                    complete, raw = wire.request('$JS.API.STREAM.INFO.N2_DESIRED', {})
                    peers = complete['cluster'].get('replicas', [])
                    if not complete['cluster'].get('desired') and len(peers) == 4 and all(peer['current'] for peer in peers):
                        break
                    if time.monotonic() >= deadline:
                        raise RuntimeError('R5 did not converge after barrier release')
                    time.sleep(.1)
                if args.output:
                    (args.output / 'desired-completed.json').write_bytes(raw + b'\n')
                print('Barrier released: R5 current; desired absent; next INFO succeeded on same control connection.', flush=True)
            finally:
                for name in held:
                    subprocess.run(['docker', 'unpause', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                wire.close()
    finally:
        for name in reversed(containers):
            subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        subprocess.run(['docker', 'network', 'rm', network], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
