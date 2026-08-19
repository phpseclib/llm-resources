# `phpseclib4\File\CMS`

Full reference for Cryptographic Message Syntax (CMS, RFC 5652) in phpseclib 4.0.

CMS is a general-purpose envelope format for cryptographic operations on data — signing, encrypting, compressing, or digesting arbitrary content while carrying along metadata like signer certificates, recipient identifiers, and algorithm parameters. CMS is the successor to PKCS #7; the formats are wire-compatible in most respects, and CMS files are sometimes still called "PKCS#7" or use `.p7m` / `.p7s` extensions.

CMS is brand new in phpseclib 4.0 — there's no 3.0 equivalent for most of this surface. This reference covers the public API; it doesn't have a migration section because there's nothing to migrate from.

The CMS layer is large because the format models four distinct content types, each with its own class hierarchy. Most users only need one or two. Reading top-to-bottom is fine for orientation; for day-to-day use, skip to the section for your content type.

## Contents

- [The four content types](#the-four-content-types)
- [The `CMS` abstract class](#the-cms-abstract-class)
- [Loading](#loading)
- [SignedData](#signeddata)
  - [Reading signers and signed content](#reading-signers-and-signed-content)
  - [The `Signer` subobject](#the-signer-subobject)
  - [Creating a SignedData](#creating-a-signeddata)
  - [Signer attributes (signed and unsigned)](#signer-attributes-signed-and-unsigned)
  - [ESS, naked, and plain signers](#ess-naked-and-plain-signers)
  - [Detached signatures](#detached-signatures)
  - [Validation](#signeddata-validation)
- [EncryptedData and EnvelopedData](#encrypteddata-and-envelopeddata)
  - [Reading encrypted content](#reading-encrypted-content)
  - [Recipient types](#recipient-types)
  - [Creating an envelope](#creating-an-envelope)
  - [Adding recipients](#adding-recipients)
- [CompressedData](#compresseddata)
- [DigestedData](#digesteddata)
- [Embedded certificates and CRLs](#embedded-certificates-and-crls)
- [Output format](#output-format)
- [ArrayAccess](#arrayaccess)

---

## The four content types

A CMS file always wraps content of one of these types:

| Content type | OID | phpseclib class | What it does |
| --- | --- | --- | --- |
| **SignedData** | `id-signedData` | `CMS\SignedData` | Wraps content with one or more digital signatures; optionally embeds the signing certificates and CRLs. |
| **EnvelopedData / EncryptedData** | `id-envelopedData` / `id-encryptedData` | `CMS\EncryptedData` | Wraps content encrypted under a symmetric key; the key is delivered to recipients via one of several mechanisms. Both OIDs are handled by the same class. |
| **CompressedData** | `id-ct-compressedData` | `CMS\CompressedData` | Wraps content compressed with zlib. No cryptographic operation, just compression. |
| **DigestedData** | `id-digestedData` | `CMS\DigestedData` | Wraps content with a cryptographic hash. No signature — just a checksum. Rarely used in practice. |

Each class works independently — you don't typically mix them, though SignedData can wrap content that is itself another CMS object (signing an already-encrypted blob, for example).

PEM-encoded CMS files use the `-----BEGIN CMS-----` header. Older PKCS #7 PEM files may use `-----BEGIN PKCS7-----`; phpseclib accepts both on load. File extensions in the wild include `.p7m`, `.p7s`, `.p7b`, `.p7c`, and `.cms`.

---

## The `CMS` abstract class

```php
abstract class CMS
```

`phpseclib4\File\CMS` is the namespace root and an abstract dispatcher. You can't instantiate it directly; the entry point is the static `CMS::load()` method.

```php
public static function load(string $cms, int $mode = ASN1::FORMAT_AUTO_DETECT): CMS\SignedData|CMS\CompressedData|CMS\EncryptedData|CMS\DigestedData
```

`CMS::load()` parses just enough of the input to identify its content-type OID and then returns an instance of the appropriate concrete class. PEM or DER, auto-detected. Unsupported OIDs throw `phpseclib4\Exception\UnsupportedValueException`; malformed input throws `phpseclib4\Exception\UnexpectedValueException`.

```php
use phpseclib4\File\CMS;

$obj = CMS::load(file_get_contents('blob.p7m'));

if ($obj instanceof CMS\SignedData) {
    foreach ($obj->getSigners() as $signer) { /* ... */ }
} elseif ($obj instanceof CMS\EncryptedData) {
    $obj->deriveFromKey($privKey);
    // ...
}
```

If you know in advance what kind of CMS you're loading, you can also call the specific subclass's `load()` directly (`CMS\SignedData::load(...)`, etc.), skipping the dispatch. For untrusted input or generic CMS handling, prefer `CMS::load()`.

`CMS` also has two utility constants for recipient/signer identifier modes:

```php
CMS::ISSUER_AND_DN   // Refer to a cert by its issuer DN + serial number (the default)
CMS::KEY_ID          // Refer to a cert by its subject key identifier
```

These appear as the `$type` parameter on `addSigner()`, `createNewRecipientFromX509()`, etc.

And process-wide output toggles:

```php
CMS::enableBinaryOutput();
CMS::disableBinaryOutput();
```

Affects PEM vs. binary output for all `CMS\*` subclasses. See [Output format](#output-format).

---

## Loading

```php
$obj = CMS::load($bytes);   // returns a SignedData, EncryptedData, CompressedData, or DigestedData
```

Whether you need the dispatch through `CMS::load()` or can call a specific subclass's `load()` depends on the input:

- **PEM with a `-----BEGIN CMS-----` header**: either path works, but `CMS::load()` reads the content-type OID after extracting from PEM, so it correctly dispatches.
- **Raw DER from a known source**: `CMS\SignedData::load($der)` (etc.) is fine if you know the type.
- **`.p7m` files from email systems**: nearly always SignedData. Either path works.

There's no penalty for going through `CMS::load()` — it adds maybe one extra ASN.1 tag parse. Use it as the default.

One consequence of the polymorphic return: static analyzers see the declared `CMS` return type and flag `UndefinedMethod` on every subclass-specific call (`getSigners()`, `getKey()`, and so on). When the input is genuinely arbitrary, the `instanceof` guard shown above is a real check and resolves the error honestly. When the input is a fixture or a file whose type you already know, calling the subclass's own `load()` directly — `CMS\SignedData::load($der)` — gives the analyzer the narrow type for free and is the better shape for tests.

---

## SignedData

`SignedData` wraps content with one or more digital signatures. The signed content can be attached (carried inside the CMS) or detached (referenced externally). Signers identify themselves either by `issuerAndSerialNumber` (the default) or by `subjectKeyIdentifier`. Each signer carries its own signature, its own signed-attribute and unsigned-attribute collections, and optionally identifies its certificate. The CMS can also carry the signers' certificates as embedded data so the verifier doesn't need them separately.

### Reading signers and signed content

```php
public function getSigners(): array
public function findSigner(X509 $x509): ?Signer
public function getCertificates(): array
public function attach(mixed $data): void
public function detach()
```

Walk the signers and inspect:

```php
use phpseclib4\File\CMS;

$signed = CMS::load(file_get_contents('document.p7m'));

foreach ($signed->getSigners() as $signer) {
    if ($signer->validateSignature()) {
        $cert = $signer->getCertificate();
        if ($cert !== null) {
            echo "Signed by: ", $cert->getSubjectDN(), "\n";
        }
    }
}
```

`getSigners()` returns an array of `Signer` objects. `findSigner($x509)` returns the first signer whose cert matches the given X509, or `null`.

`getCertificates()` returns the embedded certificates (typically the signer certs, sometimes intermediates). `addCertificate($x509)` / `addCRL($crl)` add to those collections.

### The `Signer` subobject

Each `Signer` in a SignedData is its own object with its own API surface:

```php
public function getCertificate(): ?X509
public function matchesX509(X509 $x509): bool
public function validateSignature(bool $caonly = true): bool

public function listSignedAttrs(): array
public function hasSignedAttr(string $type): bool
public function getSignedAttr(string $type): ?Constructed
public function setSignedAttr(string $type, mixed $value): void

public function listUnsignedAttrs(): array
public function hasUnsignedAttr(string $type): bool
public function getUnsignedAttr(string $type): ?Constructed
public function setUnsignedAttr(string $type, mixed $value): void
```

`getCertificate()` returns the X509 cert that signed (looked up in the parent SignedData's `certificates` collection by matching the signer's `sid` to a cert), or `null` if the cert isn't present. The match is done by `matchesX509()` internally.

`validateSignature()` verifies the signer's signature. The `$caonly` parameter (default `true`) controls whether the signing cert must chain to a CA in `X509`'s CA store; pass `false` to accept any signature whose math checks out.

Signed vs. unsigned attributes is a CMS distinction: *signed* attributes are part of what gets signed (modifying them invalidates the signature), and *unsigned* attributes are bag-of-metadata that can be added or modified after signing. Common signed attributes include `id-aa-signingCertificateV2` (the ESS attribute identifying which cert signed), content type, and message digest. Common unsigned attributes include countersignatures and timestamps.

`Signer` also implements `Signable`, so you can sign with a `Signer` directly — that's part of the flow for adding signers (see below).

### Creating a SignedData

```php
public function __construct(mixed $data)
public function addSigner(X509 $x509, int $type = CMS::ISSUER_AND_DN): Signer
public function addNakedSigner(X509 $x509, int $type = CMS::ISSUER_AND_DN): Signer
public function addESSSigner(X509 $x509, int $type = CMS::ISSUER_AND_DN): Signer
public function addSignature(Signer $signer): void
```

Build a SignedData by constructing it with content, then adding signers:

```php
use phpseclib4\Crypt\EC;
use phpseclib4\File\{CMS, X509};

$signed = new CMS\SignedData('Hello, world.');

$signer = $signed->addESSSigner($signerCert);   // Signer object
$signerPriv->sign($signer);                      // sign with the corresponding private key
$signed->addSignature($signer);                   // attach the signer to the SignedData

echo $signed;
```

The constructor's `mixed $data` parameter accepts either a **string** (the content bytes, kept in memory) or a **PHP resource** (a file pointer from `fopen()` or similar). The resource form is the preferred path for large files — phpseclib streams from the file pointer instead of loading the entire content into memory, which matters a lot once you're signing multi-MB or GB inputs:

```php
$signed = new CMS\SignedData(fopen('/path/to/large-file.bin', 'r'));
// content is streamed during signing/serialization; never fully loaded into memory
```

The type hint is `mixed` because PHP still doesn't have a `resource` type hint (not even in 8.5). Passing anything other than `string` or `resource` throws `phpseclib4\Exception\UnexpectedValueException`.

The three `addSigner` variants differ in what signed attributes get pre-populated:

- **`addSigner()`** — populates a standard signed-attributes block (content type, message digest, signing time). This is the conventional choice for SignedData with attached content.
- **`addNakedSigner()`** — produces a signer with *no* signed-attributes block. Faster and smaller, but only valid when the encapsulated content is `id-data`. Per RFC 5652, signedAttrs is mandatory for any other content type.
- **`addESSSigner()`** — like `addSigner()` but also adds `id-aa-signingCertificateV2` (an ESS attribute that binds the specific certificate identity into the signed attributes — RFC 5035). This is the modern recommended form for new signatures; resists certain substitution attacks.

All three return a `Signer` object that you then sign with a private key:

```php
$privKey->sign($signer);
$signed->addSignature($signer);
```

`addSignature($signer)` does the bookkeeping — adds the signer to the SignedData's `signerInfos` collection and adds the signer's certificate to the embedded certificates collection if it wasn't already there.

You can add multiple signers to one SignedData. Each signs independently, with its own attributes and signature. This is the basis for documents requiring multiple-party signatures.

### Signer attributes (signed and unsigned)

To add or modify attributes on a signer *before* signing (because changing signed attrs after signing invalidates the signature):

```php
$signer = $signed->addESSSigner($signerCert);

$signer->setSignedAttr('id-aa-signingTime', new \DateTimeImmutable());
$signer->setSignedAttr('id-aa-contentHint', /* ... */);

$privKey->sign($signer);
$signed->addSignature($signer);
```

Unsigned attributes (typically used for timestamps and countersignatures) can be set after signing:

```php
$signer->setUnsignedAttr('id-aa-signatureTimeStampToken', $timestampToken);
```

The signature is unaffected by changes to unsigned attributes.

### ESS, naked, and plain signers

Of the three variants of `addSigner`, **`addESSSigner` is the right default for new code**. It produces signatures that conform to RFC 5035 (Enhanced Security Services) and includes the `id-aa-signingCertificateV2` attribute that pins the specific signing certificate's hash into the signed data, preventing certain attacks where a different cert with the same public key could substitute.

Use `addSigner` if you need a basic CMS signature without ESS — interoperability with old verifiers that don't recognize ESS attributes.

Use `addNakedSigner` only when:
- The content is `id-data` (any other content type makes a no-attributes signer invalid per spec),
- You specifically want the smaller signature size and faster signing/verifying,
- The verifier will accept signatures without signed attributes.

### Detached signatures

A "detached" SignedData refers to content that isn't embedded in the CMS — you ship the SignedData and the content separately. This is how `.p7s` files typically work (the `.p7s` is the signature; the email body is the signed content).

There are two ways to produce a detached SignedData:

**The conventional way**: construct with the content embedded, then strip it before serializing.

```php
$signed = new CMS\SignedData($content);
// ... add signer, sign ...

$signed->detach();
file_put_contents('document.p7s', (string) $signed);
file_put_contents('document.txt', $content);
```

**The streaming way**: construct with a resource. phpseclib hashes through the file pointer during signing but never embeds the content. The resulting CMS is naturally detached — no `detach()` call needed.

```php
$signed = new CMS\SignedData(fopen('document.txt', 'r'));
// ... add signer, sign ...

file_put_contents('document.p7s', (string) $signed);
// document.txt is already on disk; the .p7s references it implicitly
```

The streaming form is preferred for large files. It avoids loading the content into memory at any point in the sign/serialize cycle.

To verify a detached SignedData, re-attach the content first:

```php
$signed = CMS::load(file_get_contents('document.p7s'));

// Attach by string (small content):
$signed->attach(file_get_contents('document.txt'));

// Or attach by resource (large content — streamed during verification):
$signed->attach(fopen('document.txt', 'r'));

foreach ($signed->getSigners() as $signer) {
    var_dump($signer->validateSignature());
}
```

`attach()` accepts a `string` (the content bytes) or a `resource` (a file pointer). Anything else throws `UnexpectedValueException`. `detach()` removes any embedded content from the structure and clears any file pointer; signers' message-digest attributes still refer to the original content, so re-attaching the same content (by either means) makes the signatures valid again.

### SignedData validation

```php
public function validateSignature(bool $caonly = true): bool
```

Returns `true` if *all* signers validate; `false` if any signer fails. For per-signer validation, iterate `getSigners()` and call `validateSignature()` on each.

`$caonly` works the same way as on `Signer` — `true` requires each signer's cert to chain to a CA in `X509`'s CA store; `false` accepts mathematically valid signatures regardless of cert provenance.

If the SignedData has detached content, you must call `attach($content)` before validation or all signers will fail (they're hashing against missing content).

---

## EncryptedData and EnvelopedData

`CMS\EncryptedData` handles both the `id-encryptedData` and `id-envelopedData` OIDs. The difference at the CMS level is whether recipient information is included:

- **`id-encryptedData`**: just encrypted bytes with a content-encryption algorithm specified. The key is delivered out-of-band.
- **`id-envelopedData`**: encrypted bytes plus one or more `RecipientInfo` entries, each describing how a specific recipient can derive the content-encryption key.

phpseclib uses the same class for both. Whether the parsed object has recipients (`id-envelopedData`) or not (`id-encryptedData`) is reflected in `getRecipients()` returning a non-empty or empty array respectively.

### Reading encrypted content

```php
public function getAlgorithm(): string
public function getKey(): string
public function getKeyLength(): int
public function getKeyLengthInBytes(): int

public function withKey(string $key): self
public function deriveFromKey(string|EC\PrivateKey|RSA\PrivateKey $key): self
public function deriveFromPassword(string $password): self
public function decrypt(): string
```

Three paths to populate the content-encryption key, all of which return `$this` so they chain into `decrypt()`:

1. **You already have the CEK**: call `withKey($key)` directly. Used when the CEK was delivered out-of-band.
2. **You have a private key that matches one of the recipients**: call `deriveFromKey($privateKey)`. Walks the recipients, finds a match, derives the CEK via the appropriate KEK mechanism (RSA key transport, ECDH, etc.).
3. **You have a password for a PasswordRecipient**: call `deriveFromPassword($password)`. Walks the password-based recipients (PWRI-KEK), tries each.

`decrypt()` then uses the populated CEK to decrypt the content, returning the plaintext bytes:

```php
$cms = CMS::load(file_get_contents('encrypted.p7m'));

if ($cms instanceof CMS\EncryptedData) {
    // RSA recipient — the typical X509-based path
    $plaintext = $cms->deriveFromKey($rsaPrivateKey)->decrypt();

    // EC recipient — Diffie-Hellman path
    $plaintext = $cms->deriveFromKey($ecPrivateKey)->decrypt();

    // Password-based
    $plaintext = $cms->deriveFromPassword('secret')->decrypt();

    // Out-of-band CEK
    $plaintext = $cms->withKey($cek)->decrypt();
}
```

You can also work through specific recipients instead of letting `deriveFromKey()` pick one:

```php
$plaintext = $cms->findRecipient($recipientX509)?->withKey($rsaPrivateKey)->decrypt();
$plaintext = $cms->getRecipients()[0]?->withPassword('secret')->decrypt();
```

Recipients implement the same `decrypt()` contract (via the `KeyDerivation` trait shared with `EncryptedData`), so the call signatures are consistent. The recipient-side `decrypt()` reaches up through `$this->cms->cek` to find the populated CEK on its parent EncryptedData, then runs the same content decryption.

The two paths produce identical results. Use the EncryptedData-direct form when you don't care which recipient does the work; use the recipient form when you've identified the specific recipient (e.g., via `findRecipient()`) and want explicit control.

`getAlgorithm()` returns the content-encryption algorithm OID (e.g., `id-aes128-CBC-PAD`). `getKey()` returns the CEK once populated; calling it before `withKey()` / `deriveFrom*()` throws an uninitialized-property error since the `$cek` property is typed-non-nullable. `getKeyLength()` / `getKeyLengthInBytes()` describe the expected CEK size for the content-encryption algorithm in bits and bytes respectively.

`decrypt()` requires the CEK to be set — calling it before one of the populate-CEK methods throws `phpseclib4\Exception\InvalidStateException` with the message "Content encryption key not set."

### Recipient types

```php
public function getRecipients(): array
public function findRecipients(string|X509 $keyIdentifier): array
public function findRecipient(string|X509 $keyIdentifier): ?SearchableKey
```

Four recipient classes correspond to the four `RecipientInfo` types in RFC 5652:

| Class | RFC 5652 name | Key-derivation input |
| --- | --- | --- |
| `KeyTransRecipient` | `KeyTransRecipientInfo` (`ktri`) | RSA private key (the recipient cert's matching key) |
| `KeyAgreeRecipient` | `KeyAgreeRecipientInfo` (`kari`) | EC private key (used with Diffie-Hellman) |
| `KEKRecipient` | `KEKRecipientInfo` (`kekri`) | A pre-shared symmetric key |
| `PasswordRecipient` | `PasswordRecipientInfo` (`pwri`) | A password (used with PBES2 / PWRI-KEK) |

Each has a different `withKey()` / `withPassword()` signature appropriate to its mechanism:

```php
$ktri->withKey($rsaPrivateKey);            // KeyTransRecipient — RSA
$kari_inner->withKey($ecPrivateKey);        // EncryptedKey inside KeyAgreeRecipient — EC
$kekri->withKey($symmetricKey);             // KEKRecipient — string of right length
$pwri->withPassword($password);             // PasswordRecipient — string
```

`findRecipient` / `findRecipients` search by identifier. The `$keyIdentifier` can be:

- A string — matches against the recipient's `subjectKeyIdentifier` (for `KEY_ID`-type recipients) or its KEK identifier (for KEKRecipient).
- An X509 — matches against `issuerAndSerialNumber`-style recipients by checking the cert's matches.

`findRecipient` returns the first match (or `null`); `findRecipients` returns all matches. Returned objects implement the `SearchableKey` marker interface — typed but otherwise untouched.

### Creating an envelope

```php
public function __construct(
    string $data,
    string $encryptionAlgorithm = 'aes128-CBC-PAD',
    ?string $key = null
)
```

Construct an EncryptedData with the content to encrypt. The encryption algorithm defaults to AES-128-CBC with PKCS padding; pass another for a different cipher. The `$key` argument lets you supply a specific CEK; if omitted, phpseclib generates a random one.

```php
$cms = new CMS\EncryptedData('Hello, world.');
// CEK was generated automatically; available via $cms->getKey()

$cms = new CMS\EncryptedData('Hello, world.', 'aes256-CBC-PAD');
// AES-256 instead

$cms = new CMS\EncryptedData('Hello, world.', 'aes128-CBC-PAD', $existingCek);
// Use a specific CEK (must be the right length for the algorithm)
```

If `$key` is the wrong length for the chosen algorithm, the constructor throws `phpseclib4\Exception\LengthException`. The safest path — particularly if no recipients will be attached and the CEK has to be shared out-of-band — is to omit `$key`, let the constructor generate one, then read it back with `getKey()`. That avoids having to remember the per-algorithm key length and avoids the `LengthException` path entirely.

Construction encrypts immediately. The result is in the `id-encryptedData` form by default — no recipients yet. Add recipients to convert it to `id-envelopedData`.

### Adding recipients

```php
public function createNewRecipientFromPassword(string $password, string $encryptionAlgorithm = 'aes128-CBC-PAD'): PasswordRecipient
public function createNewRecipientFromX509(X509 $x509, int $type = CMS::ISSUER_AND_DN): KeyTransRecipient|KeyAgreeRecipient
public function createNewRecipientFromKeyWithIdentifier(...): KEKRecipient
```

```php
$cms = new CMS\EncryptedData('Hello, world.');

// Add a password recipient
$cms->createNewRecipientFromPassword('shared-secret');

// Add an X509 recipient (auto-selects KeyTrans for RSA certs, KeyAgree for EC)
$cms->createNewRecipientFromX509($recipientCert);

// Add a symmetric-key recipient with an identifier
$cms->createNewRecipientFromKeyWithIdentifier($kek, $kekId);

echo $cms;
```

**The password recipient's algorithm must not be weaker than the content's.** `$encryptionAlgorithm` here is the *key* encryption algorithm — the cipher that wraps the CEK — and it's independent of the algorithm the content was encrypted with. RFC 3211 key wrapping has no room to encode a CEK longer than the KEK, so a mismatch in that direction throws `phpseclib4\Exception\LengthException` ("The content encryption key should be the same length or shorter than the key encryption key"):

```php
$cms = new CMS\EncryptedData('hello, world!', 'aes256-CBC-PAD');
$cms->createNewRecipientFromPassword('correct horse battery staple', 'aes128-CBC-PAD');
// LengthException — 32-byte CEK can't be wrapped by a 16-byte KEK

$cms->createNewRecipientFromPassword('correct horse battery staple', 'aes256-CBC-PAD');
// fine — equal lengths
```

Note that the default here (`aes128-CBC-PAD`) matches the `EncryptedData` constructor's default, so the failure only shows up when the content algorithm was upgraded and the recipient's wasn't. If you pass a stronger algorithm to the constructor, pass at least as strong a one to every password recipient.

`createNewRecipientFromX509()` inspects the cert's public key type and produces the right `RecipientInfo`:

- RSA cert → `KeyTransRecipient` (the recipient's RSA private key will unwrap the CEK)
- EC cert → `KeyAgreeRecipient` (Diffie-Hellman with the recipient's EC private key)

Each `createNewRecipient*` call adds a recipient to the CMS. A single EncryptedData can have multiple recipients of mixed types — any one of them can decrypt the same content.

---

## CompressedData

```php
public function __construct(string $data)
public static function load(string|array|Constructed $encoded): self
public function getContent(): string
```

CompressedData wraps content with zlib compression. No cryptography — just compression.

```php
use phpseclib4\File\CMS;

$cms = new CMS\CompressedData(file_get_contents('large-file.txt'));
file_put_contents('large-file.p7c', (string) $cms);

// Later:
$cms = CMS::load(file_get_contents('large-file.p7c'));
if ($cms instanceof CMS\CompressedData) {
    $content = $cms->getContent();  // decompressed bytes
}
```

The constructor throws `phpseclib4\Exception\BadConfigurationException` if `zlib_encode()` isn't available; `getContent()` likewise requires `zlib_decode()`. Both are present in PHP's bundled zlib extension, which is enabled by default — only relevant on stripped-down builds.

CompressedData uses `id-alg-zlibCompress` exclusively — RFC 3274 doesn't define other algorithms in this slot.

---

## DigestedData

```php
public function __construct(string $data, string $hashAlgorithm = 'sha256')
public static function load(string|array|Constructed $encoded): self
public function validate(): bool
```

DigestedData wraps content with a cryptographic hash. Like CompressedData, no encryption — just a checksum.

```php
$cms = new CMS\DigestedData('content', 'sha256');
file_put_contents('content.p7m', (string) $cms);

$cms = CMS::load(file_get_contents('content.p7m'));
echo $cms->validate() ? 'hash matches' : 'corrupted';
```

DigestedData is rarely used in practice — it provides integrity but not authentication (anyone can rebuild the digest), and any use case that needs integrity usually needs authentication too (use SignedData). It exists in the spec for completeness; phpseclib supports it for parsing legacy structures that contain it.

The constructor accepts any hash algorithm `phpseclib4\Crypt\Hash` recognizes: `'sha1'`, `'sha224'`, `'sha256'`, `'sha384'`, `'sha512'`, `'md5'`, etc. SHA-256 is the default.

`validate()` re-computes the digest and compares it to the stored value with `hash_equals()` (constant-time comparison). Returns `true` if matching, `false` otherwise.

---

## Embedded certificates and CRLs

SignedData and EncryptedData both support carrying X.509 certificates and CRLs alongside the cryptographic payload:

```php
public function getCertificates(): array
public function addCertificate(X509 $cert): void
public function addCRL(CRL $crl): void
```

Same method names on both classes. For SignedData, these are the certs that signers' `getCertificate()` looks up; for EncryptedData, these are the recipient certs (or whatever the producer chose to include for the verifier's convenience).

`getCertificates()` returns a flat array of `X509` instances — including the chain certs the producer chose to embed, not just the leaf signing/recipient certs.

CompressedData and DigestedData don't carry embedded certs or CRLs — there's no cryptographic operation that would reference them.

---

## Output format

```php
public function __toString(): string
public function toString(array $options = []): string

CMS::enableBinaryOutput();
CMS::disableBinaryOutput();
```

Default is PEM with `-----BEGIN CMS-----` headers. Per-call binary: `toString(['binary' => true])`. Process-wide via `CMS::enableBinaryOutput()` / `CMS::disableBinaryOutput()`. The toggle applies to *all* CMS subclasses simultaneously — there's no per-subclass toggle.

The `id-signedData` content type with detached content is sometimes saved as `.p7s` (S/MIME signature); `id-envelopedData` payload as `.p7m` (S/MIME message); a CMS-wrapped certificate bundle as `.p7b`. phpseclib doesn't write specific extensions; the output is the same regardless of what extension you save it with.

---

## ArrayAccess

All four `CMS\*` classes implement `ArrayAccess`, `Countable`, and `Iterator`. The structure follows the ASN.1 schema:

```php
$cms['contentType']           // OID string, e.g., 'id-signedData'
$cms['content']                // the type-specific inner content
```

The structure under `content` varies by content type and is documented in RFC 5652. For most use cases the helper methods (`getSigners`, `getRecipients`, `getContent`, `validate`, etc.) are simpler than walking the structure directly.

Read [`references/asn1-constructed.md` → Autovivification on read](asn1-constructed.md#autovivification-on-read) before reaching into the CMS structure with bare ArrayAccess — `??` for optional fields, `isset()` for present-checks. The CMS structures are deep enough that you don't want to accidentally autovivify a path.
