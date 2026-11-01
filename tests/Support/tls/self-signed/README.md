# Synthetic TLS fixtures

Test material only. All certificates use SHA-256, SAN DNS:localhost and serverAuth
(or clientAuth for client.pem). RSA keys are 2048 bits except weak-key.pem (1024).
Valid certificates cover 2019-01-01 through 2045-01-01 UTC. expired.pem has the
same key, names, usage and signature algorithm as self.pem, but expired on
2020-01-01. self.pem and expired.pem are self-signed leaves. trusted.pem and
client.pem are issued by ca.pem; untrusted.pem is issued by untrusted-ca.pem.
The latter CA is deliberately absent from the client trust store. CA private keys
are not included. The client key password is synthetic-t6-client-passphrase.

The weak key is an additional security-level regression. These private keys and
password are public fixtures and must never be used outside tests.

Run the matrix on NATS 2.15.2 with `make integration-tls-self-signed`.
