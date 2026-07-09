# phpseclib 4.0 for LLMs

A single-file reference for AI coding assistants (and humans) that need to write phpseclib 4.0 code or migrate phpseclib 3.0 code to 4.0. Maintained by the phpseclib project at <https://github.com/phpseclib/llm-resources>. Paste into a system prompt, attach to a custom GPT, or use as `llms.txt`-style context for any LLM-augmented tool.

If your assistant produces phpseclib code that contradicts this document, the document wins.

This document targets **phpseclib 4.0.0**. The public API (class names, method names, signatures, behaviors) is stable across all 4.0.x minor releases; phpseclib uses [Romantic Versioning](https://github.com/romversioning/romver), and breaking changes are reserved for MAJOR or PROJECT bumps. New features may arrive in 4.0.x (OCSP support, additional algorithms — adding methods isn't a BC break). The `Signable` interface exists across all 4.0.x but its specific method list may shift between minor releases. Behaviors of `Constructed` and the ASN.1 layer that are documented in this file are stable; lower-level implementation details aren't. If a later 4.0.x does something this document doesn't describe, defer to the library.

---

## At a glance

phpseclib 4.0 is a pure-PHP cryptography library. Major release breaking changes since 3.0:

- **Namespace** is now `phpseclib4\` (was `phpseclib3\`).
- **X.509 / CSR / CRL / PFX / SPKAC / CMS** all use static `::load()` factories and implement `ArrayAccess`.
- **Signing direction** changed: the key signs the object (`$private->sign($x509)`), not the other way around.
- **PFX** and **CMS** are top-level classes — both brand new in 4.0.
- **DN methods** split into `Subject*` / `Issuer*` variants; bare `getDN()` throws on CA-signed certs.
- **Type declarations everywhere.** Wrong types throw `TypeError` immediately.
- **Exceptions instead of `false`** returns across the library.
- **`SFTP::chmod` argument order** swapped to match every other SFTP method (path first, mode second).
- **`Crypt\Random` removed** — use `random_bytes()`.

If you only have time for one rule: **in 4.0, the private key (or PFX) signs the object — `$privateKey->sign($x509)`, not `$x509->sign(...)`. Sign last, after every other configuration is done. The call installs the signature into `$x509` and *returns* the raw signature bytes; for the full PEM cert use `$privateKey->sign($x509); echo $x509;`, not `echo $privateKey->sign($x509);`.** In 3.0 the signing logic lived on `X509::sign()` and took two X.509 instances; in 4.0 the key knows how to sign anything implementing the `Signable` interface (`X509`, `CSR`, `CRL`, `CMS\SignedData`, `CMS\SignedData\Signer`).

---

## Namespace

phpseclib 4.0 uses the `phpseclib4\` root namespace. Every class, interface, and exception is under it:

```
phpseclib4\Crypt\RSA
phpseclib4\Crypt\EC
phpseclib4\File\X509
phpseclib4\File\CSR
phpseclib4\File\CRL
phpseclib4\File\PFX
phpseclib4\File\SPKAC
phpseclib4\File\CMS
phpseclib4\File\ASN1\Constructed
phpseclib4\Math\BigInteger
phpseclib4\Net\SSH2
phpseclib4\Net\SFTP
phpseclib4\Exception\PasswordNeededException
```

If you see `phpseclib3\` in code, it's 3.0 and needs migration. `Crypt_RSA` / `File_X509` / `Net_SSH2` (no namespace, underscores) are 1.0 or 2.0 and need to be migrated to 3.0 first using the official 3.0 migration guide, then to 4.0 from there.

## Detecting the version of pasted code

| Signal | Version |
| --- | --- |
| `Crypt_RSA`, `File_X509`, `Net_SSH2` (underscores, no namespace) | 1.0 or 2.0 |
| `phpseclib\Crypt\RSA` (single-segment namespace) | 2.0 |
| `phpseclib3\Crypt\RSA`, `new X509(); $x509->loadX509(...)` | 3.0 |
| `phpseclib4\…`, `X509::load(...)`, `$private->sign($x509)`, ArrayAccess on file objects | 4.x |

Mixed signals — `phpseclib3\` imports next to `X509::load()` calls — almost always mean partly-migrated code. Treat it as broken 4.x: bring the namespace up to `phpseclib4\` and rewrite remaining 3.0 method shapes.

---

## Before suggesting a migration: the compat shim

For 3.0 codebases, **before recommending any rewrite**, surface this option: there is a compatibility package, [`phpseclib/phpseclib3_compat`](https://github.com/phpseclib/phpseclib3_compat), that emulates the entire `phpseclib3\` API on top of phpseclib 4.0. Existing 3.0 code keeps working unchanged. The package "provides" `phpseclib/phpseclib:~3.0` to Composer, satisfying any other dependency that requires 3.0 — Google's PHP API client being the most common case where this matters in practice.

The compat shim is the right answer when:

- The user has a large 3.0 codebase and just wants to "upgrade to 4.0" without a specific reason for native 4.0 code.
- A Composer conflict has arisen between phpseclib 4.0 and a third-party package pinned to 3.0.
- The user wants 4.0's security and performance improvements but doesn't need PFX, CMS, or modern types in their own code.

A full migration is the right answer when the user explicitly wants to use new 4.0 features in their own code, when starting fresh, or when their library exposes phpseclib types in its public API.

The two paths can coexist — install the shim for legacy code, write new code against native 4.0 — because `phpseclib3\File\X509` (shimmed) and `phpseclib4\File\X509` (native) are different classes.

When the user has chosen the shim, no migration is needed — point them at the shim's installation instructions and stop there. Otherwise, the rest of this document applies.

---

## Core idioms

### 1. Loading vs. constructing

```php
use phpseclib4\File\X509;
use phpseclib4\File\CSR;
use phpseclib4\File\CRL;
use phpseclib4\File\PFX;
use phpseclib4\File\SPKAC;
use phpseclib4\File\CMS;

// Parse existing data
$x509 = X509::load(file_get_contents('cert.pem'));
$csr  = CSR::load(file_get_contents('req.csr'));
$crl  = CRL::load(file_get_contents('crl.bin'));
$pfx  = PFX::load(file_get_contents('store.pfx'), 'password');
$cms  = CMS::load(file_get_contents('sample.p7m'));

// Build from scratch
$x509 = new X509();
$x509->setSubjectDN('CN=example.com');
// ... configure further ...
$privKey->sign($x509);
echo $x509;
```

`::load()` is for parsing existing data and returns a hydrated object — there is no separate "load" step after `new`. `new ClassName()` is for building from scratch. There is no `loadX509()` / `loadCSR()` / `loadCRL()` method in 4.0; those are 3.0 and earlier.

### 2. File objects are ArrayAccess wrappers around `ASN1\Constructed`

`X509`, `CSR`, `CRL`, `PFX`, `SPKAC`, and the `CMS\*` subclasses all implement `ArrayAccess`, `Countable`, and `Iterator`:

```php
// Both return the same object (typically a typed PublicKey):
$key = $x509->getPublicKey();
$key = $x509['tbsCertificate']['subjectPublicKeyInfo'];

print_r($x509);  // triggers __debugInfo() — tree view of parsed ASN.1
```

Both paths return the same friendly typed objects (`PublicKey`, `BigInteger`, `DateTimeInterface`, `BaseType` subclasses, etc.) — the helper isn't wrapping a lower-level ArrayAccess view; they're peer paths. `X509::load()` registers `$rules` with `ASN1::map()` at parse time that decode each field into its appropriate typed object, so by the time you read a field via either path, the typed object is already there. The helper methods are mostly thin checkers that throw a typed exception if the slot didn't decode as expected.

If a helper would throw — `getPublicKey()` throws `phpseclib4\Exception\UnexpectedValueException` on unparseable key formats because the slot ended up as a `Constructed` rather than a `PublicKey` — you can still ArrayAccess to that `Constructed` and call `->getEncoded()` to get the raw bytes.

### 3. Signing direction: key signs object

```php
use phpseclib4\Crypt\EC;
use phpseclib4\File\X509;

$privKey = EC::createKey('nistp256');

$x509 = new X509($privKey->getPublicKey());
$x509->setSubjectDN('/O=phpseclib demo subject');
$x509->setIssuerDN('/O=phpseclib demo issuer');
$privKey->sign($x509);   // installs the signature into $x509
echo $x509;              // PEM-encoded signed cert
```

Or via PFX, which additionally auto-sets the issuer DN and authorityKeyIdentifier from the PFX's CA cert:

```php
$x509 = new X509($pubKey);
$x509->setSubjectDN('/O=phpseclib demo subject');
$pfx->sign($x509);
echo $x509;
```

`$privKey->sign(...)` and `$pfx->sign(...)` do **two** things in one call: they install the signature into the passed object (so subsequent `echo $x509` produces the signed cert) and they return the raw signature bytes. The two outputs serve different purposes:

```php
$privKey->sign($x509);          // install + ignore the return value
echo $x509;                      // -> the full signed cert (most common case)

$rawSig = $privKey->sign($x509); // install + capture the signature bytes
                                  // useful when a protocol needs the signature
                                  // separately from the signed structure
```

Don't write `echo $privKey->sign($x509);` expecting to see a PEM cert — you'll get the raw signature bytes (typically gibberish on stdout). Use `$privKey->sign($x509); echo $x509;` instead.

The interface that makes this work:

```php
namespace phpseclib4\Crypt\Common;

interface PrivateKey
{
    public function sign(string|Signable $message): string;
    // ...
}
```

`sign()` always returns a `string` — the raw signature — regardless of whether you passed a string or a `Signable`. When `$message` is a `Signable`, the key additionally installs the signature back into the object as a side effect, which is why `echo $x509` afterwards prints the signed cert.

**String mode still works.** `$privKey->sign('arbitrary bytes')` returns a signature over those bytes, exactly as in 3.0. The `Signable` overload is *additive* — existing 3.0 raw-byte signing code continues to work in 4.0 with no edits. Only the X.509 cert-creation pattern needs rewriting, and that wasn't a `$privKey->sign(...)` call in 3.0.

`PrivateKey` is implemented by `phpseclib4\Crypt\{EC,RSA,DSA}\PrivateKey` and by `phpseclib4\System\SSH\Agent\Identity`. The SSH agent case means you can sign an X.509 cert with an agent-held key — useful for hardware-backed signing. (DNs and serials don't auto-populate the way they do via PFX, since the agent has no knowledge of those.)

The mechanism: `X509`, `CSR`, `CRL`, `CMS\SignedData`, and `CMS\SignedData\Signer` all implement the `Signable` interface (`phpseclib4\File\Common\Signable`). When you pass one of them to `$privKey->sign(...)` or `$pfx->sign(...)`, the signer consults the interface to find which bytes inside the structure to sign and where the resulting signature belongs. Without this, callers would have to write their own `substr()`-and-splice logic to extract the signed region and place the signature back in the right slot — which is hard to get right and especially hard for re-signing (the old signature has to be located and replaced, not just appended).

**Critical ordering rule.** Sign *after* every DN, extension, validity date, and other configuration is set. Modifications made after signing do **not** invalidate or recompute the signature — they leave you with an object whose signature no longer matches its contents. The signing call should be the last thing you do before serializing the object (echo, file write, DB write, etc.).

Never write `$x509->sign(...)` — the `sign` method lives on the signer, not the signee.

### 4. DN methods split into Subject and Issuer

```php
$x509->getSubjectDN();   // always safe
$x509->getIssuerDN();    // always safe
$x509->getDN();          // throws if subject != issuer
```

The bare `getDN()` / `setDN()` / `addDNProp()` / `removeDNProps()` family only works on self-signed certs (subject == issuer). On CA-signed certs they throw. Always use the explicit `Subject*` / `Issuer*` variants in code that handles arbitrary certificates.

CSR objects only have a subject DN — the bare and `Subject` methods are aliases. CRL objects only have an issuer DN — the bare and `Issuer` methods are aliases.

DN return formats are controlled by an `ASN1::DN_*` constant: `DN_STRING` (default, OpenSSL 3.0 CLI format — e.g. `C = US, O = Acme, CN = example.com`), `DN_ARRAY` (phpseclib internal shape), `DN_OPENSSL` (mirrors `openssl_x509_parse()`), `DN_ASN1` (binary), `DN_CANON` (canonicalized binary), `DN_HASH` (hex of `SHA1(DN_CANON)`, matches OpenSSL's `subject_hash`).

**`DN_STRING` format changed in 4.0.** 3.0 produced `C=US, O=Acme/CN=example.com` (`/` was both separator and a legal value character — ambiguous); 4.0 produces `C = US, O = Acme, CN = example.com`. 3.0 code that string-matches `getDN()` output (`strpos`, regexes, etc.) silently breaks. Use `getSubjectDNProps('CN')` for an array of values, or `getSubjectDN(ASN1::DN_OPENSSL)` for a structured associative array.

### 5. Extensions return `{extnId, extnValue, critical}` arrays

```php
$ext = $x509->getExtension('id-ce-cRLDistributionPoints');
// $ext === null if missing, otherwise:
// $ext['extnId']    => 'id-ce-cRLDistributionPoints'
// $ext['extnValue'] => phpseclib4\File\ASN1\Types\BaseType instance
// $ext['critical']  => bool
```

`hasExtension($name)` for boolean tests. `setExtension($name, $value, $critical = null)` to set; if `$critical` is omitted phpseclib picks the RFC 5280 default. Pass either symbolic name (`id-ce-keyUsage`) or dotted OID (`2.5.29.15`) — both work.

Specialized helpers exist for common extensions and should be preferred:

| Extension | Helper |
| --- | --- |
| `id-ce-subjectAltName` (DNS, IP) | `addDomains(...)`, `addIPAddresses(...)` |
| `id-ce-subjectKeyIdentifier` | `setSubjectKeyIdentifier()`, `createSubjectKeyIdentifier($method = 1)` |
| `id-ce-authorityKeyIdentifier` | `setAuthorityKeyIdentifier()` |
| `id-ce-keyUsage` + `id-ce-basicConstraints` (CA cert) | `makeCA()` |

For CRLs, per-revoked-cert extensions use `setRevokedExtension()` / `getRevokedExtension()` / `hasRevokedExtension()`.

To register a brand-new custom extension, register the OID with `ASN1::loadOIDs([...])` and the shape with `X509::registerExtension($name, $definition)` before calling `setExtension()`.

### 6. PFX (PKCS12) — new in 4.0

PFX is brand new as of 4.0 — there was no equivalent class in 3.0. PKCS12 work in 3.0 meant orchestrating OpenSSL calls or assembling structures by hand. In 4.0 the class wraps everything you need:

```php
$pfx = new PFX();
$pfx->add($privateKey);
$pfx->add($x509, friendlyName: 'whatever');
$pfx->add($x509, localKeyID: 'some-id');
$pfx->setPassword('password');
echo $pfx;   // binary DER

$pfx = PFX::load($bytes, 'password');
$pfx->getCertificates();   // X509[]
$pfx->getPrivateKeys();    // PrivateKey[]
$pfx->getAll();            // both, in order

$pfx->sign($x509);   // signs as a CA, copies issuer DN + AKI from PFX's cert
```

An encrypted PFX loaded without a password throws `phpseclib4\Exception\PasswordNeededException`. There is no migration path from 3.0 PFX code; treat it like CMS — purely a 4.0 topic.

`setPassword('')` (empty string) is phpseclib's default when no password is given. A truly passwordless PFX — from `removePassword()`, or created/loaded with no password — has no MAC (the integrity MAC's key is derived from the password, so no password means no MAC) and no encryption. Such files are not widely supported by other software: OpenSSL and LibreSSL refuse to load a MAC-less PFX unless you pass `-nomacver`, and .NET's certificate loader fails on Linux/macOS. Fine for dev/testing, rarely what you want otherwise. See [this Feb 2022 OpenSSL thread](https://mta.openssl.org/pipermail/openssl-users/2022-February/014901.html) and [this Aug 2016 .NET issue](https://github.com/dotnet/runtime/issues/18254).

### 7. CMS — new in 4.0

CMS (Cryptographic Message Syntax, also known as PKCS7) wraps content for signing, encrypting, digesting, or compressing. There was no CMS support in 3.0, so this is purely a 4.0 topic — never something to migrate.

`CMS::load()` is polymorphic — it returns one of four subclasses depending on the parsed `contentType`:

```php
use phpseclib4\File\CMS;

$cms = CMS::load(file_get_contents('sample.p7m'));
// $cms is one of:
//   phpseclib4\File\CMS\SignedData
//   phpseclib4\File\CMS\EncryptedData
//   phpseclib4\File\CMS\DigestedData
//   phpseclib4\File\CMS\CompressedData

if ($cms instanceof CMS\SignedData) {
    foreach ($cms->getSigners() as $signer) {
        // ...
    }
}
```

Always `instanceof`-check before calling subclass-specific methods. Signing follows the same idiom as X.509 — pass the CMS to a key or PFX:

```php
$cms = new CMS\SignedData('');   // empty placeholder content
$pfx->sign($cms);
echo $cms;
```

For detached signatures over large files, pass a resource to the constructor instead of a string — it streams rather than loading the file into memory:

```php
$fp = fopen('big-file.pdf', 'r');
$cms = new CMS\SignedData($fp);
$pfx->sign($cms);
```

Reading methods follow the rest of the library: `getSigners()`, `findSigner($x509)`, `getCertificates()`, `getCRLs()`, `addCertificate($x509)`. Embedded content is at `$cms['content']['encapContentInfo']['eContent']` and casts to a string.

**Gotcha:** `findSigner($x509)` and `Signer::getCertificate()` perform a `keyUsage` check — if neither `digitalSignature` nor `nonRepudiation` is set on the cert, they fail to match even when the DN and serial are correct. Disable with `X509::ignoreKeyUsage()` if needed.

### 8. Output format

`X509`, `CSR`, `CRL` cast to PEM by default. Switch to DER with the static `enableBinaryOutput()` / `disableBinaryOutput()` toggles, or per-call with `$x509->toString(['binary' => true])`. PFX is binary-only.

### 9. Validation entry points

```php
use phpseclib4\Math\BigInteger;

X509::addCA(file_get_contents('ca.pem'));   // call 1+ times to build the trust store
$x509 = X509::load($cert);
$x509->validateSignature();                  // also calls validateNonRevokedStatus()
$x509->validateURL('https://example.com/');

// Tunables (all static):
X509::setTargetValidationDate('2025-01-01');
X509::setRecurLimit(5);                      // intermediate-fetch recursion cap; -1 = unlimited
X509::ignoreKeyUsage();                      // skip keyUsage check on issuer
X509::ignoreBasicConstraints();              // skip basicConstraints check
X509::setCRLLookupCallback(function (string $url, BigInteger $serial): bool { /* ... */ });
// AIA intermediate fetching is OFF by default in 4.0 (was auto-on in 3.0;
// disableURLFetch()/enableURLFetch() are gone). Opt in with a destination gate:
// phpseclib resolves the host, connects to that resolved $ip, and asks the
// callback to allow/deny it. Judge $ip — never re-resolve $host.
X509::setURLFetchCallback(function (string $host, string $ip, int $port, string $scheme): bool { /* ... */ });
```

CSR and SPKAC are always self-signed, so their `validateSignature()` takes no setup.

---

## Library-wide changes

These affect every class in the library, not just `X509`.

### Type declarations everywhere

Every method has scalar parameter and return types; every property is typed. Passing the wrong type throws `TypeError` at the call site immediately, regardless of `declare(strict_types=1)`. This catches a large class of bugs that 3.0 silently coerced past.

In 3.0, many internal "uninitialized" values were set to `false`, so user code commonly checks `if ($foo->something === false)`. In 4.0 those are typed as `?T` and initialized to `null`. The check becomes `if (!isset($foo->something))` or `if ($foo->something === null)`.

### Exceptions instead of `false` returns

3.0 methods commonly returned `false` on failure and a string/`true` on success — effectively `bool|string`. 4.0 methods throw on failure and return either `null` or a real value on success. Practical consequences for migrating code:

- `if ($result === false)` checks need to become `try { ... } catch (\Throwable $e) { ... }`.
- "Forgot to check the return value" stops being a silent data-loss bug. The classic 3.0 footgun — calling `$sftp->login(...)` with bad credentials, ignoring the `false` return, then calling `$sftp->put(...)` in a loop and silently uploading nothing — now throws on the failed login and the loop never runs.
- The exception hierarchy is consistent. 3.0 threw plain SPL types (`\RuntimeException`, `\UnexpectedValueException`, etc.) for effectively identical conditions, with no way to tell phpseclib's throws apart from anyone else's. 4.0 uses dedicated `phpseclib4\Exception\*` types, and every one of them extends PHP's `\RuntimeException` *and* implements `phpseclib4\Exception\BaseException`. So you can catch the specific type, all phpseclib exceptions (`catch (\phpseclib4\Exception\BaseException $e)`), or all runtime issues (`catch (\RuntimeException $e)`).

**Naming gotcha worth flagging.** phpseclib 4.0 has classes named `phpseclib4\Exception\UnexpectedValueException`, `phpseclib4\Exception\RuntimeException`, etc. — same names as PHP's SPL exceptions but **different classes**. `catch (\UnexpectedValueException $e)` will *not* catch phpseclib's version. Migrated 3.0 catch blocks need either the namespace updated to `\phpseclib4\Exception\*`, or a switch to the broader `catch (\RuntimeException $e)` (which works for both).

**SFTP recursive operations are the deliberate exception.** Recursive SFTP calls (`delete()` on a tree, recursive `chmod`, etc.) keep going past individual failures and collect them. Use `SFTP::getErrors()` (4.0 only, different from 3.0's `getSFTPErrors()`) to inspect them after the operation completes.

### SFTP `chmod` argument order swapped

In 3.0, `chmod` took the value first and the path second, inconsistent with every other method in `SFTP`. 4.0 fixes this:

```php
// 3.0: value first
$sftp->chmod(0777, 'file.txt');
$sftp->chmod(0777, 'dir', true);   // recursive

// 4.0: path first, matching every other SFTP method
$sftp->chmod('file.txt', 0777);
$sftp->chmod('dir', 0777, true);
```

Only `chmod` swapped — `chown`, `chgrp`, `touch`, `truncate`, `put`, `get` were already path-first in 3.0. A 3.0-style call against 4.0 throws `TypeError` immediately on the `string $path` parameter — easy to spot but easy to miss in lightly-tested code paths. Grep migrated codebases for `chmod(`.

### Removed methods and classes

| Removed | Replacement |
| --- | --- |
| `SSH2::getErrors()`, `SSH2::getLastError()` | exceptions |
| `SFTP::getSFTPErrors()`, `SFTP::getLastSFTPError()` | exceptions; `SFTP::getErrors()` for the recursive-error case only |
| `phpseclib3\Crypt\Random`, `Random::string($n)` | `random_bytes($n)` |

`SFTP::getErrors()` exists in 4.0 but means something different than the 3.0 name suggested it might. It returns the per-operation errors collected during a recursive operation that kept going past partial failures, with operation and path included:

```
['REMOVE /home/test/A (SSH_FX_FAILURE): Failure',
 'REMOVE /home/test/A/B (SSH_FX_PERMISSION_DENIED): Permission denied',
 ...]
```

(3.0's `getSFTPErrors()` output was bare status strings with no operation or path context.)

---

## Migration patterns

```php
// === Loading ===
// 3.0
$x509 = new X509();
$x509->loadX509(file_get_contents('cert.pem'));
// 4.0
$x509 = X509::load(file_get_contents('cert.pem'));

// === Signing ===
// 3.0: needs three X509 instances. The private key is attached to $issuer
// via setPrivateKey() and never appears as a direct argument. sign() is
// called on a third X509 and returns the signed cert as an array; saveX509()
// renders it to PEM.
$subject = new X509();
$subject->setPublicKey($pubKey);
$subject->setDN('/O=phpseclib demo subject');
$issuer = new X509();
$issuer->setPrivateKey($privKey);
$issuer->setDN('/O=phpseclib demo issuer');
$x509 = new X509();
$result = $x509->sign($issuer, $subject);
echo $x509->saveX509($result);

// 4.0: one X509 with explicit subject and issuer DNs. The key installs the
// signature into the cert (and returns the raw signature bytes, which we
// ignore here since we want the full PEM).
$x509 = new X509($pubKey);
$x509->setSubjectDN('/O=phpseclib demo subject');
$x509->setIssuerDN('/O=phpseclib demo issuer');
$privKey->sign($x509);
echo $x509;

// === DN access on non-self-signed certs ===
// 3.0: returned subject DN regardless
$dn = $x509->getDN();
// 4.0: bare getDN() throws; use explicit variant
$dn = $x509->getSubjectDN();

// === Public key ===
// 3.0: silently fell back on unsupported formats
$key = $x509->getPublicKey();
// 4.0: throws phpseclib4\Exception\UnexpectedValueException; guard or catch
if ($x509->hasPublicKey()) {
    $key = $x509->getPublicKey();
}

// === Extension access ===
// 3.0: returned bare value
$value = $x509->getExtension('id-ce-keyUsage');
// 4.0: returns array, or null if missing
$ext = $x509->getExtension('id-ce-keyUsage');
$value = $ext['extnValue'] ?? null;

// === Random bytes ===
// 3.0
use phpseclib3\Crypt\Random;
$bytes = Random::string(32);
// 4.0
$bytes = random_bytes(32);

// === SFTP chmod ===
// 3.0
$sftp->chmod(0777, 'file.txt');
// 4.0
$sftp->chmod('file.txt', 0777);

// === SFTP error checking ===
// 3.0: poll after the fact
$sftp->login('u', 'p');
if ($sftp->getSFTPErrors()) { /* handle */ }
// 4.0: exceptions
try {
    $sftp->login('u', 'p');
    // ...
} catch (\Throwable $e) {
    // handle
}

// === CRL / SPKAC ===
// 3.0: handled inside X509
$x509->loadCRL($data);
// 4.0: dedicated class
use phpseclib4\File\CRL;
$crl = CRL::load($data);
```

**Raw byte signing is unchanged.** `$sig = $privKey->sign($bytes)` — calling `sign()` with a string for arbitrary-data signing (HMAC-style use cases, signing tokens, etc.) — works identically in 3.0 and 4.0. The interface signature gained a `string|Signable` union type, but the string branch behaves the same. Only the X.509 cert-creation pattern (which used `$x509->sign($issuer, $subject)` in 3.0, not `$privKey->sign(...)`) needs rewriting.

**PFX and CMS have no 3.0 equivalent.** Both classes are brand new in 4.0. If a 3.0 codebase does PKCS12 or CMS work, that work is happening outside phpseclib (typically via `openssl_*` functions or shell-out to the `openssl` CLI). Moving those operations into `PFX` / `CMS` is a refactor, not a mechanical migration.

**Low-level ASN.1 changed too** (only relevant if your 3.0 code calls `ASN1::decodeBER()` or `ASN1::asn1map()` directly):

- `ASN1::decodeBER()` now returns a *shallow* one-level array with nested `phpseclib4\File\ASN1\Constructed` objects holding lazy-decoded interior content, rather than the deeply-nested array 3.0 produced. This is the security improvement that prevents DoS attacks via maliciously-crafted ASN.1 with high-complexity interiors.
- `ASN1::asn1map($decoded[0], $map)` (3.0) → `ASN1::map($decoded, $map)` (4.0). Method renamed; input no longer needs the `[0]` index.
- The third argument changed from `$special` (flat array of decoders keyed by exact field name, applied during eager decode) to `$rules` (nested array of callbacks keyed by structural path with `*` wildcards, applied on demand as `Constructed` interior content is materialized). 4.0's bundled schemas use `$rules` pervasively where 3.0 used `$special` sparingly.
- Code that walked the deeply-nested 3.0 output (e.g., custom `asn1parse`-style tools) needs reworking against the lazy `Constructed` objects. To learn the patterns, read how `phpseclib4\File\X509`, `CMS`, `CRL`, and `CSR` use `ASN1::map()` and `$rules` — those classes are the canonical examples of every parsing pattern phpseclib supports.

---

## Common mistakes to flag in review

1. **`$sftp->chmod(0777, $path)` instead of `$sftp->chmod($path, 0777)`.** Wrong argument order; throws `TypeError`.
2. **`if ($result === false)` after a phpseclib call.** 4.0 throws instead of returning `false`. Use try/catch.
3. **`getSFTPErrors()` / `getLastSFTPError()` / `SSH2::getErrors()` / `SSH2::getLastError()`.** All removed. Rewrite around exceptions. The remaining `SFTP::getErrors()` is only for the recursive-error case.
4. **New namespace, old method names.** `phpseclib4\File\X509::load()` followed by `$x509->loadX509(...)` is a half-migration. The second call is dead.
5. **`getDN()` / `setDN()` on a CA-signed cert.** Throws. Use `Subject*` / `Issuer*` variants.
6. **Configuring after signing.** `$private->sign($x509); $x509->setExtension(...)` produces a stale signature. Configure first, sign last.
7. **Treating `getExtension()` return value as the value itself.** It's an array. Pull `$ext['extnValue']`.
8. **`getPublicKey()` on untrusted input without try/catch.** Throws `phpseclib4\Exception\UnexpectedValueException` on unsupported formats. Guard with `hasPublicKey()` or catch — and note that `catch (\UnexpectedValueException $e)` (the SPL class) will **not** catch this; you need either the phpseclib-namespaced type or `catch (\RuntimeException $e)`.
9. **`$x509->sign(...)` instead of `$key->sign($x509)` or `$pfx->sign($x509)`.** The `sign` method lives on the signer, not the signee.
10. **`echo $privKey->sign($x509);` expecting a PEM cert.** `sign()` returns the raw signature bytes, not the signed cert. The signature is also installed into `$x509` as a side effect, so the right pattern is `$privKey->sign($x509); echo $x509;`. Capture the return value only when you specifically need the bare signature.
11. **Building a PKCS12 by hand or via OpenSSL shellouts** when working in 4.0. Use the `PFX` class — it's new in 4.0 and replaces all the manual orchestration you may have inherited from 3.0 code.
12. **`phpseclib4\Crypt\Random` or `Random::string()`.** Does not exist in 4.0. Use `random_bytes()`.
13. **String-matching `getDN()` output.** The `DN_STRING` format changed between 3.0 (`C=US, O=Acme/CN=…`) and 4.0 (`C = US, O = Acme, CN = …`), so `strpos` / regex matches on the 3.0 format silently fail. Use `getSubjectDNProps('CN')` or `getSubjectDN(ASN1::DN_OPENSSL)` instead.

---

## Style preferences when generating code

- Use `use` statements at the top of every example. No inline fully-qualified class names.
- Prefer named arguments for non-obvious optional parameters: `$pfx->add($x509, friendlyName: '...')`.
- Default to `EC::createKey('nistp256')` for new keys unless RSA is specifically required — faster, smaller keys, avoids accidentally teaching bad RSA padding habits.
- Show `echo $x509;` at the end of creation examples and `print_r($x509);` at the end of inspection examples.
- For validation flows, show `X509::addCA(...)` first so users don't get a confusing "untrusted issuer" failure.

---

## Provenance

This document is the official phpseclib AI-assistant guide, maintained by the phpseclib project at <https://github.com/phpseclib/llm-resources>. The authoritative phpseclib documentation lives at <https://phpseclib.com>. When this document and the official docs disagree, the official docs are correct — please file an issue against the LLM-resources repo so it can be fixed.

If your AI assistant generates phpseclib code that contradicts this document, point it back here and ask it to revise.
