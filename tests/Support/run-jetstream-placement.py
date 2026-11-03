#!/usr/bin/env python3
"""Isolated N8 version admission, seven-node placement and held rollback tests."""
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
parser.add_argument('--cluster', action='store_true', help='Only the 2.15.2 cluster and held rollback')
parser.add_argument('--old-only', action='store_true')
parser.add_argument('--held-only', action='store_true')
parser.add_argument('--output', type=Path)
parser.add_argument('--numeric-peers', action='store_true', help='Use distinct numeric-looking string server names')
args = parser.parse_args()
network = 'nats-n8-' + uuid.uuid4().hex[:10]
containers = []
held = []


def server_name(index):
    return '0' * (index - 1) + '1' if args.numeric_peers else 'n' + str(index)


def alias(node):
    return 'n' + str(len(node)) if args.numeric_peers and not node.startswith('n') else node


def docker(*arguments, **kwargs):
    return subprocess.run(['docker', *arguments], check=True, **kwargs)


def ready(name):
    end = time.monotonic() + 30
    while subprocess.run(['docker', 'exec', name, 'wget', '-qO-', 'http://127.0.0.1:8222/healthz'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode:
        if time.monotonic() >= end:
            docker('logs', name)
            raise RuntimeError('Server not ready: ' + name)
        time.sleep(.1)


def test_options(host, version, mode, output, control=None):
    options = ['run', '--rm', '--user', 'root', '--network', network,
               '-e', 'NATS_HOST=' + alias(host), '-e', 'NATS_PORT=4222',
               '-e', 'NATS_EXPECTED_VERSION=' + version, '-e', 'NATS_PLACEMENT_MODE=' + mode]
    output.mkdir(parents=True, exist_ok=True)
    options += ['-v', str(output) + ':/placement-traces', '-e', 'NATS_PLACEMENT_TRACE_DIR=/placement-traces']
    if control:
        options += ['-v', str(control) + ':/placement-control']
    pattern = {'old': 'testOldApi', 'cluster': 'test(Evacuation|CancelWithout|ExplicitSystem)', 'held': 'testCancelHeld', 'snapshot': 'testRollbackSnapshot'}[mode]
    return ['docker', *options, args.image, 'vendor/bin/phpunit', '--fail-on-skipped', '--filter', pattern,
            'tests/Integration/JetStreamPlacementIntegrationTest.php']


class Wire:
    """Cluster setup/barriers only; operations and their observation use public PHP Admin."""
    def __init__(self, port, user='a'):
        self.socket = socket.create_connection(('127.0.0.1', port), timeout=10)
        self.file = self.socket.makefile('rb')
        assert self.file.readline().startswith(b'INFO ')
        self.socket.sendall(b'CONNECT ' + json.dumps({'verbose': False, 'headers': True, 'user': user, 'pass': 'synthetic-n8-' + user}).encode() + b'\r\n')
        self.sequence = 0

    def request(self, subject, payload):
        self.sequence += 1
        inbox = '_INBOX.N8.' + str(self.sequence)
        data = json.dumps(payload, separators=(',', ':')).encode()
        self.socket.sendall(('SUB ' + inbox + ' ' + str(self.sequence) + '\r\nUNSUB ' + str(self.sequence) + ' 1\r\nPUB ' + subject + ' ' + inbox + ' ' + str(len(data)) + '\r\n').encode() + data + b'\r\n')
        while True:
            line = self.file.readline()
            if line == b'PING\r\n':
                self.socket.sendall(b'PONG\r\n')
            elif line.startswith(b'MSG '):
                raw = self.file.read(int(line.split()[-1]))
                assert self.file.read(2) == b'\r\n'
                value = json.loads(raw)
                if 'error' in value:
                    raise RuntimeError('NATS error: ' + repr(value['error']))
                return value
            elif not line or line.startswith(b'-ERR'):
                raise RuntimeError('NATS control connection closed: ' + repr(line))

    def close(self):
        self.file.close()
        self.socket.close()


monitoring = ', '.join('{service: "$SYS.REQ.SERVER.*.' + kind + '"}' for kind in ['VARZ', 'ACCOUNTZ', 'ROUTEZ'])
imports = ', '.join('{service: {account: SYS, subject: "$SYS.REQ.SERVER.*.' + kind + '"}}' for kind in ['VARZ', 'ACCOUNTZ', 'ROUTEZ'])
mappings = 'mappings: { "ALT.API.INFO": "$JS.API.INFO", "ALT.API.STREAM.INFO.WANTED": "$JS.API.STREAM.INFO.WANTED", "ALT.API.STREAM.PEER.EVACUATE.WANTED": "$JS.API.STREAM.PEER.EVACUATE.OTHER", "$JS.API.STREAM.PEER.EVACUATE.WANTED": "$JS.API.STREAM.PEER.EVACUATE.OTHER", "$JS.API.STREAM.PEER.EVACUATE.N8_CANCEL": "$JS.API.STREAM.PEER.EVACUATE.OTHER" }'
accounts = '\naccounts { A {jetstream: enabled, ' + mappings + ', users: [{user: a, password: "synthetic-n8-a"}], imports: [' + imports + ']}, B {jetstream: enabled, users: [{user: b, password: "synthetic-n8-b"}], imports: [' + imports + ']}, SYS {users: [{user: sys, password: "synthetic-n8-sys"}], exports: [' + monitoring + ']}}\nsystem_account: SYS\n'

with tempfile.TemporaryDirectory(prefix='nats-n8-') as directory:
    directory = Path(directory)
    output = args.output.resolve() if args.output else directory / 'traces'
    try:
        docker('network', 'create', network, stdout=subprocess.DEVNULL)
        if not args.cluster and not args.held_only:
            config = directory / 'old.conf'
            config.write_text('port: 4222\nhttp_port: 8222\njetstream {domain: EU, store_dir: /tmp/js}\n' + accounts)
            for version in ['2.12.6', '2.14.0']:
                name = network + '-old'
                containers.append(name)
                docker('run', '-d', '--name', name, '--network', network, '--network-alias', 'server', '-v', str(config) + ':/server.conf:ro', 'nats:' + version + '-alpine', '-c', '/server.conf', stdout=subprocess.DEVNULL)
                ready(name)
                print('N8 admission:', version, flush=True)
                subprocess.run(test_options('server', version, 'old', output / version), check=True)
                docker('rm', '-f', name, stdout=subprocess.DEVNULL)
                containers.remove(name)
        if not args.old_only:
            for index in range(1, 8):
                node = 'n' + str(index)
                routes = ','.join('"nats://n' + str(other) + ':6222"' for other in range(1, 8) if other != index)
                config = directory / (node + '.conf')
                config.write_text('server_name: ' + json.dumps(server_name(index)) + '\nport: 4222\nhttp_port: 8222\nserver_tags: ["' + ('movable","01","1' if index <= 5 else 'spare') + '"]\njetstream {domain: EU, store_dir: /tmp/js, max_file_store: 1GB, max_memory_store: 128MB}\ncluster {name: N8, listen: "0.0.0.0:6222", routes: [' + routes + ']}\n' + accounts)
                name = network + '-' + alias(node)
                containers.append(name)
                docker('run', '-d', '--name', name, '--network', network, '--network-alias', node, '-p', '127.0.0.1::4222', '-v', str(config) + ':/server.conf:ro', 'nats:2.15.2-alpine', '-c', '/server.conf', stdout=subprocess.DEVNULL)
            for name in containers:
                ready(name)

            def port(node):
                return int(docker('port', network + '-' + alias(node), '4222', capture_output=True, text=True).stdout.strip().rsplit(':', 1)[1])

            last_meta = {}

            def meta():
                # Current/lag for other peers is authoritative on the elected Raft leader.
                first = json.loads(docker('exec', network + '-n1', 'wget', '-qO-', 'http://127.0.0.1:8222/jsz', capture_output=True, text=True).stdout).get('meta_cluster', {})
                leader = first.get('leader')
                actual = first
                if leader and alias(leader) != 'n1':
                    actual = json.loads(docker('exec', network + '-' + alias(leader), 'wget', '-qO-', 'http://127.0.0.1:8222/jsz', capture_output=True, text=True).stdout).get('meta_cluster', {})
                last_meta.update(observer='n1', observer_snapshot=first, authoritative_node=leader, authoritative_snapshot=actual)
                return actual

            end = time.monotonic() + 30
            while not meta().get('leader') or len(meta().get('replicas', [])) != 6 or not all(p['current'] for p in meta()['replicas']):
                if time.monotonic() >= end:
                    print(json.dumps(last_meta), flush=True)
                    raise RuntimeError('Seven metadata peers did not settle')
                time.sleep(.1)
            output.mkdir(parents=True, exist_ok=True)
            (output / 'cluster-startup.json').write_text(json.dumps(last_meta, indent=2))
            if not args.held_only:
                print('N8 placement: 2.15.2, seven peers, accounts A/B/SYS', flush=True)
                subprocess.run(test_options('n1', '2.15.2', 'cluster', output / 'cluster'), check=True)
            wire = Wire(port('n1'))
            try:
                wire.request('$JS.API.STREAM.CREATE.N8_CANCEL', {'name': 'N8_CANCEL', 'subjects': ['n8.held'], 'storage': 'file', 'retention': 'limits', 'num_replicas': 3, 'placement': {'tags': ['01']}})
                wire.request('n8.held', {'marker': 'synthetic-n8-held'})
                end = time.monotonic() + 30
                while True:
                    original = wire.request('$JS.API.STREAM.INFO.N8_CANCEL', {})
                    peers = original.get('cluster', {}).get('replicas', [])
                    if len(peers) == 2 and all(p['current'] and p.get('lag', 0) == 0 for p in peers):
                        break
                    if time.monotonic() >= end:
                        raise RuntimeError('Original R3 not settled')
                    time.sleep(.05)
                active = original['cluster']['leader']
                current = {active, *(p['name'] for p in peers)}
                if meta()['leader'] not in current:
                    system = Wire(port(meta()['leader']), 'sys')
                    try:
                        system.request('$JS.API.META.LEADER.STEPDOWN', {'placement': {'preferred': active}})
                    finally:
                        system.close()
                    end = time.monotonic() + 15
                    while meta().get('leader') != active:
                        if time.monotonic() >= end:
                            raise RuntimeError('Metadata leadership barrier failed')
                        time.sleep(.05)
                wire.close()
                wire = Wire(port(active))
                targets = [server_name(i) for i in range(1, 6) if server_name(i) not in current]
                targets.append(next(p['name'] for p in peers if p['name'] != meta()['leader']))
                for node in targets:
                    name = network + '-' + alias(node)
                    docker('pause', name, stdout=subprocess.DEVNULL)
                    held.append(name)
                config = original['config'].copy()
                config['num_replicas'] = 5
                update = wire.request('$JS.API.STREAM.UPDATE.N8_CANCEL', config)
                end = time.monotonic() + 10
                while True:
                    pending = wire.request('$JS.API.STREAM.INFO.N8_CANCEL', {})
                    desired = pending['cluster'].get('desired')
                    if desired and desired.get('status') and len(desired.get('replicas', [])) == 5:
                        break
                    if time.monotonic() >= end:
                        raise RuntimeError('Held R3 to R5 transition not observed')
                    time.sleep(.05)
                control = directory / 'control'
                control.mkdir()
                (control / 'original.json').write_text(json.dumps(original))
                traces = output / 'held'
                traces.mkdir(parents=True, exist_ok=True)
                (traces / 'setup.json').write_text(json.dumps({'held': targets, 'original': original, 'update': update, 'pending': pending}, indent=2))
                print('N8 rollback held by:', targets, 'original:', sorted(current), flush=True)
                process = subprocess.Popen(test_options(active, '2.15.2', 'held', traces, control))
                end = time.monotonic() + 25
                while not (control / 'cancelled').exists():
                    if process.poll() is not None:
                        raise RuntimeError('PHP rollback failed before acceptance: ' + str(process.returncode))
                    if time.monotonic() >= end:
                        process.terminate()
                        process.wait(timeout=10)
                        raise RuntimeError('Cancel acceptance barrier timed out')
                    time.sleep(.03)
                for name in held:
                    docker('unpause', name, stdout=subprocess.DEVNULL)
                held.clear()
                if process.wait(timeout=35):
                    raise RuntimeError('Public rollback observation failed')
                after = wire.request('$JS.API.STREAM.INFO.N8_CANCEL', {})
                (traces / 'control-after.json').write_text(json.dumps(after, indent=2))
                (control / 'pending.json').write_text(json.dumps(pending))
                def stable():
                    end = time.monotonic() + 15
                    while time.monotonic() < end:
                        # INFO can receive no reply during a stream leader election. Retry only
                        # this read on a fresh setup socket; never retry changing commands.
                        observer = Wire(port(active))
                        observer.socket.settimeout(1)
                        try:
                            info = observer.request('$JS.API.STREAM.INFO.N8_CANCEL', {})
                        except TimeoutError:
                            continue
                        finally:
                            observer.close()
                        cluster = info.get('cluster', {})
                        replicas = cluster.get('replicas', [])
                        if cluster.get('leader') and not cluster.get('desired') and len(replicas) == 2 and all(p['current'] and not p.get('lag') for p in replicas):
                            return info, {cluster['leader'], *(p['name'] for p in replicas)}
                        time.sleep(.03)
                    raise RuntimeError('Stable snapshot peer group not observed')

                def snapshot(case):
                    (control / 'case').write_text(case)
                    subprocess.run(test_options(active, '2.15.2', 'snapshot', output / 'snapshots', control), check=True)

                # A leader change within the same saved instance must remain acceptable.
                old_leader = original['cluster']['leader']
                if after['cluster']['leader'] == old_leader:
                    wire.request('$JS.API.STREAM.LEADER.STEPDOWN.N8_CANCEL', {})
                end = time.monotonic() + 15
                while True:
                    changed, group = stable()
                    if changed['cluster']['leader'] != old_leader:
                        break
                    if time.monotonic() >= end:
                        raise RuntimeError('Stream leader did not change')
                    time.sleep(.03)
                snapshot('leader')
                # Only the original peers retain tag "1": change placement without changing peers.
                for index in range(1, 6):
                    node = server_name(index)
                    if node not in current:
                        config_path = directory / (alias(node) + '.conf')
                        config_path.write_text(config_path.read_text().replace('["movable","01","1"]', '["movable","01"]'))
                        # Restart this non-baseline peer so the meta inventory also refreshes its tags.
                        docker('restart', network + '-' + alias(node), stdout=subprocess.DEVNULL)
                        ready(network + '-' + alias(node))
                        end = time.monotonic() + 10
                        while True:
                            varz = json.loads(docker('exec', network + '-' + alias(node), 'wget', '-qO-', 'http://127.0.0.1:8222/varz', capture_output=True, text=True).stdout)
                            if '1' not in varz.get('tags', varz.get('server_tags', [])):
                                break
                            if time.monotonic() >= end:
                                raise RuntimeError('Tag reload barrier failed')
                            time.sleep(.03)
                cfg = {key: original['config'][key] for key in ['name', 'subjects', 'storage', 'retention', 'num_replicas', 'placement']}
                cfg['placement'] = {'tags': ['1']}
                end = time.monotonic() + 20
                while len(meta().get('replicas', [])) != 6 or not all(peer['current'] for peer in meta()['replicas']):
                    if time.monotonic() >= end:
                        raise RuntimeError('Meta peers did not settle after tag changes')
                    time.sleep(.05)
                wire.request('$JS.API.STREAM.UPDATE.N8_CANCEL', cfg)
                different, group = stable()
                assert group == current and different['cluster']['raft_group'] == original['cluster']['raft_group']
                snapshot('placement')
                (output / 'different-placement.json').write_text(json.dumps(different, indent=2))
                # Make exactly the same three servers eligible for the replacement stream.
                for index in range(1, 6):
                    node = server_name(index)
                    if node not in current:
                        config_path = directory / (alias(node) + '.conf')
                        config_path.write_text(config_path.read_text().replace('["movable","01"]', '["spare"]'))
                        # Restart this non-baseline peer so the meta inventory also refreshes its tags.
                        docker('restart', network + '-' + alias(node), stdout=subprocess.DEVNULL)
                        ready(network + '-' + alias(node))
                        end = time.monotonic() + 10
                        while True:
                            varz = json.loads(docker('exec', network + '-' + alias(node), 'wget', '-qO-', 'http://127.0.0.1:8222/varz', capture_output=True, text=True).stdout)
                            if '01' not in varz.get('tags', varz.get('server_tags', [])):
                                break
                            if time.monotonic() >= end:
                                raise RuntimeError('Replacement eligibility reload failed')
                            time.sleep(.03)
                end = time.monotonic() + 20
                while len(meta().get('replicas', [])) != 6 or not all(peer['current'] for peer in meta()['replicas']):
                    if time.monotonic() >= end:
                        raise RuntimeError('Meta peers did not settle before replacement')
                    time.sleep(.05)
                wire.request('$JS.API.STREAM.DELETE.N8_CANCEL', {})
                cfg['placement'] = {'tags': ['01']}
                wire.request('$JS.API.STREAM.CREATE.N8_CANCEL', cfg)
                recreated, group = stable()
                assert group == current and recreated['created'] != original['created'] and recreated['state']['messages'] == 0
                snapshot('recreated')
                (output / 'recreated-stream.json').write_text(json.dumps(recreated, indent=2))
            finally:
                wire.close()
    finally:
        for name in held:
            subprocess.run(['docker', 'unpause', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for name in reversed(containers):
            subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        subprocess.run(['docker', 'network', 'rm', network], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
