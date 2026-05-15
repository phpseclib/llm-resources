---
name: phpseclib-4
description: Use when writing, debugging, or migrating PHP code that uses phpseclib (a pure-PHP cryptography library covering RSA/DSA/EC keys, SSH2, SFTP, X.509 certificates, CSRs, CRLs, PFX/PKCS12, SPKAC, CMS, ASN.1, and symmetric ciphers). Trigger on any mention of `phpseclib`, `phpseclib3`, `phpseclib4`, the namespaces `phpseclib3\` or `phpseclib4\`, classes like `SSH2`, `SFTP`, `X509`, `CSR`, `CRL`, `PFX`, `SPKAC`, `CMS`, `ASN1\Constructed`, on legacy class names like `Crypt_RSA`, `File_X509`, or `Net_SSH2`, on phpseclib-specific methods like `chmod`/`chown`/`chgrp`/`getSFTPErrors`, on the compat packages `phpseclib3_compat` or `phpseclib2_compat`, or when the user pastes phpseclib code and asks to fix, modernize, or migrate it from 3.0 to 4.0.
---

# phpseclib 4.0

This skill teaches Claude how to write idiomatic phpseclib 4.0 code and how to migrate phpseclib 3.0 code to 4.0. **Read this whole file first.** It is short by design — the long-form references in `references/` are loaded only when the task needs them.

## Namespace

phpseclib 4.0 uses the **`phpseclib4\`** root namespace. Every class, interface, and exception lives under it: `phpseclib4\Crypt\RSA`, `phpseclib4\File\X509`, `phpseclib4\File\ASN1\Constructed`, `phpseclib4\Math\BigInteger`, `phpseclib4\Exception\PasswordNeededException`, and so on. If you see `phpseclib3\` in code, it is 3.0 — see the migration section.

## Which version of 4 this skill targets

This skill is written against **phpseclib 4.0.0**. Public API surface — class names, method names, signatures, behaviors of `getSubjectDN()` / `validateSignature()` / `setCRLLookupCallback()` / etc. — is stable across all 4.0.x releases and will match what's documented here. phpseclib follows [Romantic Versioning (RomVer)](https://github.com/romversioning/romver) (PROJECT.MAJOR.MINOR); incompatible API changes are reserved for MAJOR or PROJECT bumps, not 4.0.x minor releases.

Three categories of things that *may* change between 4.0.x minor releases without contradicting BC:

1. **New features may land.** OCSP support, additional algorithms, additional helpers — adding methods or classes isn't a BC break. If a later 4.0.x release supports something this skill says isn't supported, the skill is just out of date.
2. **Method signatures inside the `Signable` interface may shift.** That the interface *exists* is stable (it's foundational to the signing model these docs describe). Which methods it requires of implementers may evolve — for example, `copySigningX509Attributes` might be generalized, or a `Signable` might gain a method to identify itself. If you implement `Signable` on your own type and a future 4.0.x reshapes the contract, your implementation may need updating.
3. **Constructed and ASN.1 internals may be refactored.** The behaviors documented in `references/asn1-constructed.md` (the rules mechanism, lazy decoding, the typed-object hierarchy as it appears to callers) are stable in 4.0.x. Lower-level implementation details — internal cache management, boilerplate-reduction refactors, whitelist mechanics — are not. If you're walking the structure with documented mechanisms (ArrayAccess, `decodeBER`/`map`, `$rules` callbacks, the typed-object classes), you're safe. If you're reaching into private state via reflection or relying on subtle implementation behavior, you own that.

The rule of thumb: anything covered in the references is the contract. If it's not in the references, treat it as an implementation detail.

If you find a 4.0.x release does something the skill doesn't describe, default to the library's behavior, not the skill's description.

## When this skill applies

Use it whenever the user is:

- **Writing new phpseclib code** (any version). Default to 4.0 idioms unless the user explicitly pins to 3.0.
- **Migrating from 3.0 to 4.0.** This is the highest-value case — see `references/migration-3-to-4.md`.
- **Debugging existing phpseclib code.** First identify which major version the code targets (see "Detecting the version" below), then apply the correct idioms.
- **Reading or constructing X.509 / CSR / CRL / PFX / SPKAC / ASN.1 structures, or doing SSH2 / SFTP work**, even when the user does not name phpseclib explicitly but the snippet they pasted uses it.

Do **not** use this skill for general PHP cryptography questions that are not about phpseclib (e.g., raw `openssl_*` calls, sodium, or other libraries) unless the user is comparing them to phpseclib.

## Detecting the version of pasted code

Before changing anything, figure out what version the user's code is. Tells:

| Signal | Likely version |
| --- | --- |
| `Crypt_RSA`, `File_X509`, `Net_SSH2` (no namespace, underscores) | 1.0 or 2.0 |
| `phpseclib\Crypt\RSA` (single-segment namespace) | 2.0 |
| `phpseclib3\Crypt\RSA`, `new X509(); $x509->loadX509(...)` | 3.0 |
| `phpseclib4\…`, `X509::load(...)`, `CSR::load(...)`, `PFX` class, `$private->sign($x509)`, `ArrayAccess` on file objects, `getDN()` throws on subject/issuer mismatch | 4.x |

Mixed signals — for example `phpseclib3\` imports alongside `X509::load()` calls — almost always mean partly-migrated code. Treat it as broken 4.x: the namespace needs to come up to `phpseclib4\` and any remaining 3.0 method shapes need to be rewritten.

## Before suggesting a migration: the compat shim

For 3.0 code, **before recommending any rewrite**, ask whether the user actually needs to migrate. There is a compatibility package, [`phpseclib/phpseclib3_compat`](https://github.com/phpseclib/phpseclib3_compat), that emulates the entire `phpseclib3\` API on top of phpseclib 4.0. Existing 3.0 code continues to work unchanged. The package "provides" `phpseclib/phpseclib:~3.0` in Composer's eyes, so it satisfies any other dependency that requires 3.0 (Google's PHP API client is the most common example — it currently pins to 3.0 and would otherwise conflict with `composer require phpseclib/phpseclib:~4.0`).

The compat shim is the right answer for many users. Suggest it explicitly when:

- The user has a large 3.0 codebase and the request is "upgrade to 4.0" without a specific reason for native 4.0 code.
- The user has a Composer conflict between phpseclib 4.0 and a third-party package that requires 3.0.
- The user wants the security and performance improvements of 4.0 but doesn't need the new features (PFX, CMS, modern types) in their own code.

The full migration is the right answer when the user explicitly wants to use new 4.0 features in their own code, when starting a new project, or when their library's public API exposes phpseclib types and they want callers to see real 4.0 types.

The two can also coexist — install the shim for legacy code paths, write new code against native 4.0 — because `phpseclib3\File\X509` (shimmed) and `phpseclib4\File\X509` (native) are different classes and don't conflict.

When the user has chosen to migrate, the rest of this skill applies. When they've chosen the shim, no migration work is needed — point them at the shim's installation instructions and stop there.

## Core idioms in 4.0

These are the patterns that show up everywhere. Internalize them before writing code.

### 1. Static `::load()` factories, not `loadX509()` / `loadCSR()` etc.

```php
use phpseclib4\File\X509;
use phpseclib4\File\CSR;
use phpseclib4\File\CRL;
use phpseclib4\File\PFX;
use phpseclib4\File\SPKAC;

$x509  = X509::load(file_get_contents('cert.pem'));
$csr   = CSR::load(file_get_contents('req.csr'));
$crl   = CRL::load(file_get_contents('crl.bin'));
$pfx   = PFX::load(file_get_contents('store.pfx'), 'password'); // password optional
$spkac = SPKAC::load(file_get_contents('spkac.txt'));
```

Each `::load()` returns a hydrated object — there is no separate "load" step after `new` like the 3.0 `(new X509())->loadX509(...)` pattern.

To build a new structure from scratch (rather than parse one), `new X509()`, `new CSR()`, `new PFX()`, etc. with no constructor argument are still the right starting point — `::load()` is specifically for the parse case.

```php
$x509 = new X509();
$x509->setSubjectDN('CN=example.com');
// ... configure ...
$privKey->sign($x509);
echo $x509;
```

### 2. File objects are thin wrappers around `ASN1\Constructed`

`X509`, `CSR`, `CRL`, `PFX`, `SPKAC`, and the `CMS\*` classes all implement `ArrayAccess`, `Countable`, and `Iterator`. There are usually two ways to read any field — array access or a helper method:

```php
// Both of these return the same object (typically a typed PublicKey):
$key = $x509->getPublicKey();
$key = $x509['tbsCertificate']['subjectPublicKeyInfo'];

// print_r triggers __debugInfo() and produces a tree view of the parsed ASN.1.
print_r($x509);
```

Both access paths return the same friendly typed objects (`PublicKey`, `BigInteger`, `DateTimeInterface`, etc.) — the helper isn't a higher-level wrapper around a lower-level ArrayAccess view; they're peer paths. This works because `X509::load()` registers `$rules` with `ASN1::map()` at parse time that decode each field into its appropriate typed object. The helper methods (`getPublicKey()`, `getSubjectDN()`, etc.) are mostly thin checkers that throw a typed exception if the slot ended up not containing what they expected.

When a helper would throw (e.g., `getPublicKey()` on an unparseable key format throws `phpseclib4\Exception\UnexpectedValueException` because the slot ended up as a `Constructed` rather than a `PublicKey`), ArrayAccess lets you reach for the underlying `Constructed` and call `->getEncoded()` to get the raw bytes for further handling.

`print_r($x509)` triggers `__debugInfo()` and produces a tree view of the parsed ASN.1 structure. Tell users to do this when they ask "what's in this certificate."

### 3. Sign objects by passing them to a key (or PFX), not the other way around

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

The interface that makes all of this work:

```php
namespace phpseclib4\Crypt\Common;

interface PrivateKey
{
    public function sign(string|Signable $message): string;
    // ...
}
```

`sign()` always returns a `string` — that's the raw signature, regardless of whether you passed a string or a `Signable`. When you pass a `Signable`, the key additionally installs the signature back into the object as a side effect (which is why `echo $x509` afterwards prints the signed cert).

**String mode still works in 4.0.** Calling `$privKey->sign('arbitrary bytes')` produces a signature over those bytes, exactly as in 3.0. The `Signable` overload is *additive* — the existing string mode is unchanged. Practical migration consequence: 3.0 code that signs raw byte strings (`$sig = $privKey->sign($data)`) continues to work in 4.0 with no edits. Only the X.509 cert-creation pattern (3.0's `$x509->sign($issuer, $subject)`) needs rewriting, and that wasn't a `$privKey->sign(...)` call to begin with.

The `PrivateKey` interface is implemented by `phpseclib4\Crypt\EC\PrivateKey`, `RSA\PrivateKey`, `DSA\PrivateKey`, and — perhaps surprisingly — `phpseclib4\System\SSH\Agent\Identity`. So an SSH agent identity can sign an X.509 cert in 4.0, the same way an in-memory key can. The DNs and serial numbers won't be auto-filled the way they are when signing via PFX (an agent doesn't know about those), but the signature itself works.

The mechanism behind this: `X509`, `CSR`, `CRL`, `CMS\SignedData`, and `CMS\SignedData\Signer` all implement the `Signable` interface (`phpseclib4\File\Common\Signable`). When you pass any of them to `$privKey->sign(...)` or `$pfx->sign(...)`, the signer consults the interface to find exactly which bytes to sign and where the resulting signature belongs inside the structure. This matters most for the re-signing case — re-signing an already-signed cert isn't "compute a signature over the whole string and append it." The old signature has to be located, the right region of the cert hashed, the new signature computed, and the new signature placed back in the right slot. Without the interface, callers would need to write that `substr()`-and-splice logic themselves; with it, the key just does the right thing for whatever object you hand it.

**Critical ordering rule:** sign *after* you have set DNs, extensions, validity, etc. Modifications made after signing do **not** invalidate or recompute the signature — they leave you with a cert whose signature no longer matches its contents. The `$privKey->sign($x509)` call should be the last thing you do before serializing the cert, whether that's `echo`-ing it, writing it to a file, storing it in a database, or anything else that converts it to a string.

### 4. DN methods split into subject and issuer; bare versions throw on ambiguity

```php
$x509->getDN();           // OK only if subject DN == issuer DN (self-signed)
$x509->getSubjectDN();    // always safe
$x509->getIssuerDN();     // always safe
```

If subject ≠ issuer and the code calls `getDN()`, `setDN()`, `addDNProp()`, etc., phpseclib **throws an exception**. Always prefer the explicit `Subject` / `Issuer` variants in any code that handles non-self-signed certificates.

CSR objects only have a subject DN, so `getDN()` and `getSubjectDN()` are aliases. CRL objects only have an issuer DN, so `getDN()` and `getIssuerDN()` are aliases. The `setDN()` family follows the same rules.

DN return formats are controlled by an `ASN1::DN_*` constant: `DN_STRING` (default, OpenSSL 3.0 CLI-style string like `C = US, O = Acme, CN = example.com`), `DN_ARRAY` (phpseclib internal shape), `DN_OPENSSL` (mirrors `openssl_x509_parse()`), `DN_ASN1` (binary), `DN_CANON` (canonicalized binary), `DN_HASH` (hex of `SHA1(DN_CANON)`, matches OpenSSL's `subject_hash`).

**The `DN_STRING` format changed between 3.0 and 4.0.** 3.0 produced `C=US, O=Acme/CN=example.com` (using `/` as both a separator and a legal value character); 4.0 produces `C = US, O = Acme, CN = example.com` (the OpenSSL 3.0 CLI format, which uses `, ` as the separator and escapes embedded commas). Migrating code that *string-matches* `getDN()` output will silently produce wrong results — recommend `getSubjectDNProps('CN')` or `getSubjectDN(ASN1::DN_OPENSSL)` for stable structured access instead.

### 5. Extensions are arrays of `{extnId, extnValue, critical}`

```php
$ext = $x509->getExtension('id-ce-cRLDistributionPoints');
// $ext['extnId']    => string OID name, e.g. "id-ce-cRLDistributionPoints"
// $ext['extnValue'] => instance of phpseclib4\File\ASN1\Types\BaseType (or subclass)
// $ext['critical']  => bool
```

`getExtension()` returns `null` if the extension is missing. There is also `hasExtension()` for a clean boolean. Pass either the symbolic name (`id-ce-keyUsage`) or the dotted OID (`2.5.29.15`) — both work.

`setExtension($name, $value, $critical = null)` is the universal setter. If `$critical` is omitted, phpseclib picks the RFC 5280 default. A handful of extensions have dedicated helpers — prefer those when they exist:

| Extension | Helper |
| --- | --- |
| `id-ce-subjectAltName` (DNS / IP) | `addDomains(...)`, `addIPAddresses(...)` |
| `id-ce-subjectKeyIdentifier` | `setSubjectKeyIdentifier()`, `createSubjectKeyIdentifier($method = 1)` |
| `id-ce-authorityKeyIdentifier` | `setAuthorityKeyIdentifier()` |
| `id-ce-keyUsage` + `id-ce-basicConstraints` (CA cert) | `makeCA()` |

For CRLs, per-revoked-cert extensions use `setRevokedExtension()` / `getRevokedExtension()` / `hasRevokedExtension()`.

To define a brand-new custom extension, register its OID with `ASN1::loadOIDs([...])` and its shape with `X509::registerExtension($name, $definition)` before calling `setExtension()`.

### 6. PFX (PKCS12) is a first-class container

PFX is brand new in 4.0 — there was no equivalent class in 3.0. It now wraps everything you need to do with PKCS12 stores:

```php
$pfx = new PFX();
$pfx->add($privateKey);
$pfx->add($x509);
$pfx->add($x509, friendlyName: 'whatever');         // PHP 8 named args
$pfx->add($x509, localKeyID: 'some-id');
$pfx->setPassword('password');                       // or removePassword()
echo $pfx;                                           // binary DER

// Reading:
$pfx = PFX::load($bytes, 'password');
$pfx->getCertificates();   // X509[]
$pfx->getPrivateKeys();    // PrivateKey[]
$pfx->getAll();            // both, in order

// Signing as a CA:
$pfx->sign($x509);   // copies issuer DN + authorityKeyIdentifier from PFX's CA cert
```

If you `PFX::load()` an encrypted PFX without a password, expect `phpseclib4\Exception\PasswordNeededException`.

### 7. CMS (Cryptographic Message Syntax) — new in 4.0

CMS is a wrapper format for signing, encrypting, digesting, or compressing files. There was no CMS support in 3.0, so this is purely a 4.0 topic — never something to migrate.

`CMS::load()` is a polymorphic factory that returns one of four subclasses depending on the `contentType` of the parsed input:

```php
use phpseclib4\File\CMS;

$cms = CMS::load(file_get_contents('sample.p7m'));
// $cms is one of:
//   phpseclib4\File\CMS\SignedData
//   phpseclib4\File\CMS\EncryptedData
//   phpseclib4\File\CMS\DigestedData
//   phpseclib4\File\CMS\CompressedData
```

Always `instanceof`-check before calling subclass-specific methods if the input could be any CMS type. For code that only handles one variant, narrow with an `instanceof` guard at the top.

The signing pattern is the same as for X.509 — pass the CMS to a key or PFX:

```php
$cms = new CMS\SignedData('');           // empty placeholder content
$pfx->sign($cms);                         // adds an ESS signer using the PFX's cert + key
echo $cms;
```

For detached signatures over large files, pass a resource (not a string) to the `CMS\SignedData` constructor — it streams rather than loading the full file into memory:

```php
$fp = fopen('big-file.pdf', 'r');
$cms = new CMS\SignedData($fp);
$pfx->sign($cms);
```

Reading is symmetric with the rest of the library: `$cms->getSigners()`, `$cms->findSigner($x509)`, `$cms->getCertificates()`, `$cms->addCertificate($x509)`, `$cms->addCRL($crl)`. Embedded file content is at `$cms['content']['encapContentInfo']['eContent']` and casts to a string. The full API lives in `references/cms.md`.

One gotcha worth surfacing: `findSigner($x509)` and `Signer::getCertificate()` both perform a `keyUsage` check — if neither `digitalSignature` nor `nonRepudiation` is set on the cert, they fail to match even when the DN and serial are correct. Disable with `X509::ignoreKeyUsage()` if you need to.

### 8. Output format

`X509`, `CSR`, `CRL` cast to PEM by default. Switch to DER with the static `enableBinaryOutput()` / `disableBinaryOutput()` toggles, or per-call with `$x509->toString(['binary' => true])`. PFX is binary-only.

### 9. Validation entry points

```php
X509::addCA(file_get_contents('ca.pem'));   // call 1+ times to build the trust store
$x509 = X509::load($cert);
$x509->validateSignature();                  // also calls validateNonRevokedStatus()
$x509->validateURL('https://example.com/');

// Tunables (all static):
X509::setTargetValidationDate('2025-01-01');
X509::setRecurLimit(5);                      // intermediate-fetch recursion cap; -1 = unlimited
X509::disableURLFetch();                     // do not auto-download intermediates
X509::ignoreKeyUsage();                      // skip keyUsage check on issuer
X509::ignoreBasicConstraints();              // skip basicConstraints check
X509::setCRLLookupCallback(function (string $url, BigInteger $serial): bool { ... });
```

CSR and SPKAC are always self-signed, so their `validateSignature()` takes no setup.

## Library-wide changes (not X.509-specific)

The biggest BC breaks in 4.0 are in `X509`, but several changes affect every class in the library. Watch for these no matter what subsystem the user is working in.

### Type declarations everywhere

Every method has scalar parameter and return types, every property is typed. Passing the wrong type produces an immediate `TypeError` at the call site instead of silently coercing or surfacing as a confusing error deep in the stack. When migrating 3.0 code, expect to find calls that were sloppy about types (e.g., passing an `int` where a `string` was expected, or relying on PHP's implicit `null → ''` coercion) and need explicit casts or fixes.

A related pattern: in 3.0, many internal "uninitialized" values were set to `false`, so user code commonly checks `if ($foo->something === false)`. In 4.0 those are typed as `?T` and initialized to `null`, so the check becomes `if (!isset($foo->something))` or `if ($foo->something === null)`. The `=== false` form will now incorrectly match the literal boolean `false` if a method ever returns one.

### Exceptions instead of `false` returns

In 3.0, methods commonly returned `false` on failure and a string/`true` on success — so the return type was effectively `bool|string`. In 4.0, those methods throw on failure and return either `null` or a real value on success. Practical consequences for migrating code:

- `if ($result === false)` checks need to become `try { ... } catch (\Throwable $e) { ... }`.
- "Forgot to check the return value" stops being a silent data-loss bug. The classic 3.0 footgun — calling `$sftp->login(...)` with bad credentials, ignoring the `false` return, then calling `$sftp->put(...)` in a loop and silently uploading nothing — now throws on the failed login and the loop never runs.
- The exception hierarchy is consistent across the library. 3.0 threw plain SPL types (`\RuntimeException`, `\UnexpectedValueException`, etc.) for effectively identical conditions, with no way to tell phpseclib's throws apart from anyone else's. 4.0 uses dedicated `phpseclib4\Exception\*` types. Every phpseclib 4.0 exception extends PHP's `\RuntimeException` *and* implements the `phpseclib4\Exception\BaseException` interface, so you have three viable catch strategies: catch the specific type (`catch (\phpseclib4\Exception\UnexpectedValueException $e)`), catch all phpseclib exceptions (`catch (\phpseclib4\Exception\BaseException $e)`), or catch all runtime issues (`catch (\RuntimeException $e)`).

**Sharp edge worth flagging in code review:** phpseclib 4.0 has classes named `phpseclib4\Exception\UnexpectedValueException`, `phpseclib4\Exception\RuntimeException`, etc. — same names as PHP's SPL exceptions but **different classes**. `catch (\UnexpectedValueException $e)` will *not* catch phpseclib's `phpseclib4\Exception\UnexpectedValueException`, because the latter doesn't extend the SPL one. Migrated 3.0 catch blocks need either the namespace updated, or a switch to the broader `catch (\RuntimeException $e)` (which works for both).

**SFTP is the deliberate exception.** Recursive SFTP operations (`delete()` on a directory tree, recursive `chmod`, etc.) keep going past individual failures and collect them rather than aborting halfway through. See `SFTP::getErrors()` below.

### SFTP argument order: subject first, then value

In 3.0, `chmod` took the value first and the path second, inconsistent with every other method in `SFTP`. 4.0 fixes this so the path comes first everywhere:

```php
// 3.0
$sftp->chmod(0777, 'filename.remote');
$sftp->chmod(0777, 'dirname.remote', true);   // recursive
$sftp->chown('filename.remote', $uid);        // path-first already
$sftp->chgrp('filename.remote', $gid);        // path-first already

// 4.0
$sftp->chmod('filename.remote', 0777);
$sftp->chmod('dirname.remote', 0777, true);   // recursive
$sftp->chown('filename.remote', $uid);        // unchanged
$sftp->chgrp('filename.remote', $gid);        // unchanged
```

Only `chmod` swapped — `chown`, `chgrp`, `touch`, `truncate`, `put`, `get`, etc. were already path-first in 3.0.

Code written against 3.0 will throw a `TypeError` on the first call against a 4.0 build. PHP does *not* implicitly coerce `int` → `string`, so passing `0777` as the first argument fails the `string $path` parameter type check immediately, regardless of `declare(strict_types=1)`. This is a loud failure rather than a silent one — easy to spot but easy to miss in code paths that aren't covered by tests, so still worth grepping for `chmod(` during a migration to fix proactively rather than wait for production to surface them.

### Error reporting on SSH2 / SFTP

Four methods are gone:

| 3.0 | 4.0 |
| --- | --- |
| `SSH2::getErrors()` | removed — exceptions cover this |
| `SSH2::getLastError()` | removed — exceptions cover this |
| `SFTP::getSFTPErrors()` | removed — exceptions cover individual failures |
| `SFTP::getLastSFTPError()` | removed — exceptions cover individual failures |

`SFTP::getErrors()` exists in 4.0 but means something different than the 3.0 name suggested it might. It returns the per-operation errors collected during a recursive operation that kept going past partial failures. The format also changed to include the operation and path:

```php
// 3.0 SFTP::getSFTPErrors() output (no context):
//   ['NET_SFTP_STATUS_FAILURE: Failure', 'NET_SFTP_STATUS_PERMISSION_DENIED: Permission denied', ...]

// 4.0 SFTP::getErrors() output (operation + path + status):
//   ['REMOVE /home/test/A (SSH_FX_FAILURE): Failure',
//    'REMOVE /home/test/A/B (SSH_FX_PERMISSION_DENIED): Permission denied',
//    ...]
```

When migrating, treat any 3.0 call to `getErrors`/`getLastError`/`getSFTPErrors`/`getLastSFTPError` as a flag that the surrounding logic is built around polling for errors. Rewrite it around `try`/`catch` for normal operations, and only use `SFTP::getErrors()` for recursive operations where partial failure is expected.

### Engine selection (test hooks only)

The `useBestEngine()` / `useInternalEngine()` / `getEngine()` methods were renamed to `forceEngine()` / `getForcedEngine()` to make their behavior clearer. This rename was backported to phpseclib 3.0.51, so anyone on a current point release of 3.0 already has the new names — it is not actually a 4.0-specific BC break. The methods are also almost exclusively used by phpseclib's own unit tests to exercise each backend (libsodium / OpenSSL / pure-PHP) side by side. Application code very rarely needs them. If a user is hitting this in migrated code, ask whether they actually need to pin a backend or whether the call can simply be removed.

### `Crypt\Random` is gone

`phpseclib3\Crypt\Random` and its `Random::string()` method existed because PHP 5.6 had no built-in CSPRNG. PHP 7.0+ has `random_bytes()`, which 4.0 uses directly. Replace any `Random::string($n)` call with `random_bytes($n)`. There is no `phpseclib4\Crypt\Random`.

## Common 3.0 → 4.0 migration patterns

These are the most frequent X.509 / CSR / CRL rewrites. Library-wide changes (SFTP arg order, exceptions, error reporting, etc.) are covered in the previous section. The full table is in `references/migration-3-to-4.md`.

### Loading

```php
// 3.0
$x509 = new X509();
$x509->loadX509(file_get_contents('cert.pem'));

// 4.0
$x509 = X509::load(file_get_contents('cert.pem'));
```

### Signing

```php
// 3.0: $issuer and $subject are both X509 instances. The private key is
// attached to $issuer via setPrivateKey(); it never appears as a direct
// argument. sign() is called on a third X509 (often a fresh `new X509()`)
// and returns the signed cert as an array, which saveX509() then renders
// to PEM.
$subject = new X509();
$subject->setPublicKey($pubKey);
$subject->setDN('/O=phpseclib demo subject');

$issuer = new X509();
$issuer->setPrivateKey($privKey);
$issuer->setDN('/O=phpseclib demo issuer');

$x509 = new X509();
$result = $x509->sign($issuer, $subject);
echo $x509->saveX509($result);

// 4.0: one X509 with explicit subject and issuer DNs. The private key
// installs the signature into the cert (and returns the raw signature
// bytes, which we ignore here since we want the full PEM).
$x509 = new X509($pubKey);
$x509->setSubjectDN('/O=phpseclib demo subject');
$x509->setIssuerDN('/O=phpseclib demo issuer');
$privKey->sign($x509);
echo $x509;
```

Only the X.509 cert-creation pattern needs migration. Raw byte signing — `$sig = $privKey->sign($bytes)` — is unchanged from 3.0 and works identically in 4.0; the `Signable` overload is additive.

The 3.0 form needed three X.509 instances (a subject, an issuer with the private key bolted on, and a third one to call `sign()` on) and attached the private key out-of-band via `setPrivateKey()`. 4.0 collapses this to one X.509 with explicit `Subject` / `Issuer` DNs and lets the key sign it directly. The 4.0 form works because `X509`, `CSR`, `CRL`, `CMS\SignedData`, and `CMS\SignedData\Signer` all implement a `Signable` interface that tells the signing key exactly what to sign and where to place the signature.

### Reading the public key

```php
// 3.0 and 4.0 both have:
$key = $x509->getPublicKey();

// But in 4.0, an unsupported key format throws phpseclib4\Exception\UnexpectedValueException.
// The 3.0 silent-fallback behavior is gone — use $x509->hasPublicKey() to guard,
// or fall back to $x509['tbsCertificate']['subjectPublicKeyInfo'] for raw access.
```

### Extension access

```php
// 3.0
$ext = $x509->getExtension('id-ce-cRLDistributionPoints');
// returned the bare value

// 4.0
$ext = $x509->getExtension('id-ce-cRLDistributionPoints');
// returns ['extnId' => string, 'extnValue' => BaseType, 'critical' => bool]
// or null if missing
$value = $ext['extnValue'];
```

### DN access on non-self-signed certs

```php
// 3.0
$dn = $x509->getDN();              // returned the subject DN regardless

// 4.0
$dn = $x509->getDN();              // throws if subject != issuer
$dn = $x509->getSubjectDN();       // always safe — never throws
$dn = $x509->getIssuerDN();        // always safe — never throws
```

In 4.0 the bare `getDN()` / `setDN()` / `addDNProp()` / `removeDNProps()` family only works on self-signed certs (where subject and issuer are the same) and throws otherwise. The `Subject*` and `Issuer*` variants always work. For non-trivial code that handles arbitrary certificates, just use the explicit variants everywhere.

### CRL / SPKAC

In 3.0 these were partially handled inside `X509` (e.g., `$x509->loadCRL()`, `$x509->loadSPKAC()`). In 4.0 each is its own class with its own `::load()` factory, its own ArrayAccess view, and its own helper methods. Update imports accordingly.

### PFX / CMS — no migration

PFX and CMS are **new in 4.0**; neither has any equivalent in 3.0. There is no migration path. If a user is migrating a 3.0 codebase that does PKCS12 work, that work is happening outside phpseclib (likely via `openssl_*` functions or shell-out to the `openssl` CLI), and the migration question is "should we move that into the new `PFX` class?" — usually yes, but it's a refactor, not a renaming.

## Common mistakes to avoid

When generating or reviewing 4.0 code, watch for these — they are the half-migrations LLMs love to produce:

1. **`$sftp->chmod(0777, $path)` instead of `$sftp->chmod($path, 0777)`.** The argument order changed in 4.0 to match every other SFTP method (path first). A 3.0-style call against 4.0 throws `TypeError` immediately on the `string $path` parameter — easy to spot in tested code paths but easy to miss in lightly-tested ones. Grep migrated codebases for `chmod(` and fix proactively.
2. **`if ($result === false)` after a phpseclib call.** In 4.0 most failures throw rather than returning `false`. The `=== false` check is now dead code at best and silently incorrect at worst. Replace with `try` / `catch`.
3. **Calling `getSFTPErrors()` / `getLastSFTPError()` / `SSH2::getErrors()` / `SSH2::getLastError()`.** All four are gone. The remaining `SFTP::getErrors()` exists but only collects per-operation errors during recursive operations — it is not a general "what went wrong" probe.
4. **New namespace, old method names.** `phpseclib4\File\X509::load()` is right, but `$x509->loadX509(...)` after that is a 3.0 holdover. If you see `::load()`, the next line should not also call `loadX509`.
5. **Calling `getDN()` / `setDN()` on a CA-signed cert.** Use the `Subject` / `Issuer` variant unless the cert is provably self-signed.
6. **Signing before configuring.** `$private->sign($x509); $x509->setExtension(...)` produces a cert with a stale signature. Always configure first, sign last.
7. **Treating `getExtension()` return value as the value itself.** It is an array — pull `$ext['extnValue']`.
8. **Forgetting that `getPublicKey()` throws on unsupported formats** in 4.0. Wrap in `hasPublicKey()` or try/catch when handling untrusted input.
9. **Using `$x509->sign(...)` instead of `$key->sign($x509)` or `$pfx->sign($x509)`.** The sign method lives on the signer, not the signee.
10. **`echo $privKey->sign($x509);` expecting a PEM cert.** `sign()` returns the raw signature bytes, not the signed cert. The signature is also installed into `$x509` as a side effect, so the right pattern is `$privKey->sign($x509); echo $x509;`. Capture the return value only when you specifically need the bare signature (e.g., for a protocol that transmits it separately).
11. **Building a PKCS12 by hand or via OpenSSL shellouts** when working in 4.0. Use the `PFX` class — it's new in 4.0 and replaces all the manual orchestration you may have inherited from 3.0 code.
12. **Using `\phpseclib4\Crypt\Random` or `Random::string()`.** That class does not exist in 4.0. Use `random_bytes()`.
13. **String-matching `getDN()` output.** The `DN_STRING` format changed between 3.0 (`C=US, O=Acme/CN=…`) and 4.0 (`C = US, O = Acme, CN = …`), so `strpos($x509->getDN(), '/CN=…')` and similar patterns silently break. Use `getSubjectDNProps('CN')` or `getSubjectDN(ASN1::DN_OPENSSL)` for structured access instead.

## When to load deeper references

This SKILL.md is enough for ~90% of phpseclib 4.0 tasks. Load a reference only when the current task actually needs it — do not pre-load.

- `references/migration-3-to-4.md` — full 3.0 → 4.0 method/class mapping table. Load when migrating non-trivial code.
- `references/x509.md`, `references/csr.md`, `references/crl.md`, `references/pfx.md`, `references/spkac.md` — full API reference for each file class. Load when working in depth with that specific format.
- `references/cms.md` — full CMS API: `SignedData`, `EncryptedData`, `DigestedData`, `CompressedData`, signers, ESS attributes, detached signatures, finding signers by certificate. Load when the user is doing CMS / PKCS7 work.
- `references/ssh2-sftp.md` — full SSH2 / SFTP API with the 4.0 exception model and recursive-error handling. Load when the user is doing SSH2 or SFTP work beyond a single command.
- `references/asn1-constructed.md` — deep dive on `ASN1\Constructed`, encoding/decoding, cache invalidation, custom maps. Load when the user is doing low-level ASN.1 work or extending phpseclib.
- `references/distinguished-names.md` — every DN format constant, multi-valued RDN handling, postal addresses. Load only when the DN work is non-trivial.

## Scripts

- `scripts/detect-version.php` — scans a PHP file or directory and reports which phpseclib version each file targets, plus a list of 3.0-isms that need migration: `phpseclib3\` namespace, legacy method names (`loadX509`, `getSFTPErrors`, `Random::string`), and `chmod(0xxx, ...)` SFTP arg-order calls. Run it as `php scripts/detect-version.php path/to/code` before starting a migration so you have a checklist.

## House style for generated code

When writing new phpseclib 4.0 code:

- Use `use` statements at the top of every example. Do not use fully-qualified class names inline.
- Prefer named arguments (`friendlyName: '...'`) for any optional argument whose meaning is not obvious from position. phpseclib 4.0 examples use them and they read better.
- Show `echo $x509;` at the end of creation examples so users can see the PEM. Show `print_r($x509);` at the end of inspection examples so they get the tree view.
- When demonstrating signature validation, show `X509::addCA(...)` first so users do not get a confusing "untrusted issuer" failure.
- Keys: prefer `EC::createKey('nistp256')` in examples unless the user specifically wants RSA. It is faster, the keys are smaller, and it avoids accidentally teaching bad RSA padding habits.
