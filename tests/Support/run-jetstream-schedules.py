#!/usr/bin/env python3
"""Run N6 scheduled publication on pinned NATS releases."""
import argparse
from pathlib import Path
import subprocess
import tempfile
import time
import uuid

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--image', default='nats-client')
parser.add_argument('--version', action='append', choices=['2.12.6', '2.14.0', '2.15.2'])
parser.add_argument('--output', type=Path)
args = parser.parse_args()
monitoring = (Path(__file__).parent / 'atomic-monitoring.conf').read_text()
network = 'nats-schedules-' + uuid.uuid4().hex[:10]
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


def test(version, host):
    options = ['run', '--rm', '--user', 'root', '--network', network, '-e', 'NATS_HOST=' + host, '-e', 'NATS_PORT=4222', '-e', 'NATS_EXPECTED_VERSION=' + version, '-e', 'NATS_SCHEDULES=1']
    if args.output:
        directory = args.output.resolve() / version
        directory.mkdir(parents=True, exist_ok=True)
        options += ['-v', str(directory) + ':/schedule-traces', '-e', 'NATS_SCHEDULE_TRACE_DIR=/schedule-traces']
    print('SCHEDULES matrix:', version, flush=True)
    docker(*options, args.image, 'vendor/bin/phpunit', '--fail-on-skipped', 'tests/Integration/JetStreamScheduleIntegrationTest.php')


with tempfile.TemporaryDirectory(prefix='nats-schedules-') as directory:
    try:
        docker('network', 'create', network, stdout=subprocess.DEVNULL)
        config = Path(directory) / 'server.conf'
        config.write_text('port: 4222\nhttp_port: 8222\njetstream {domain: EU, store_dir: /tmp/jetstream}\n' + monitoring)
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
