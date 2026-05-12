# `phpseclib4\File\CSR`

Full reference for Certificate Signing Requests in phpseclib 4.0.

A CSR (Certificate Signing Request, PKCS #10, RFC 2986) is the structure you send to a CA when you want a certificate. It contains a public key, a desired subject DN, optional requested extensions, and a self-signature with the corresponding private key. The CA examines the CSR, decides what (if anything) to issue, and returns an X.509 certificate.

CSRs share a lot of mechanics with `X509` — both implement `Signable`, both use the same lazy-decoding `Constructed` infrastructure, both serialize as PEM by default — so this file emphasizes what's *different* about CSRs and cross-references `references/x509.md` for the parts that work identically.

## Contents

- [What a CSR is](#what-a-csr-is)
- [Loading](#loading)
- [Reading](#reading)
- [Attributes vs. extensions](#attributes-vs-extensions)
- [Creating](#creating)
- [The challenge password](#the-challenge-password)
- [Signing](#signing)
- [Output format](#output-format)
- [Validation](#validation)
- [From X509 to CSR](#from-x509-to-csr)
- [ArrayAccess](#arrayaccess)

---

## What a CSR is

The ASN.1 structure (RFC 2986):

```asn1
CertificationRequest ::= SEQUENCE {
    certificationRequestInfo  CertificationRequestInfo,
    signatureAlgorithm        AlgorithmIdentifier,
    signature                 BIT STRING
}

CertificationRequestInfo ::= SEQUENCE {
    version       INTEGER { v1(0) },
    subject       Name,
    subjectPKInfo SubjectPublicKeyInfo,
    attributes    [0] IMPLICIT Attributes
}
```

Conceptually a CSR is "a public key plus a request envelope, signed by the holder of the corresponding private key." The signature proves the requester actually controls the private key (since they had to use it to sign), but it doesn't bind the CSR to any external authority — there's no issuer, no CA, no chain. The CA does all the trust work after receiving the CSR.

Three consequences:

1. **CSRs have a subject DN but no issuer DN.** All the DN methods exist in both `setDN()` / `setSubjectDN()` flavors and they're aliases — there's nothing to disambiguate, so neither throws on a CSR.
2. **CSRs are self-signed by definition.** `validateSignature()` checks that the public key embedded in the CSR is the one that produced the signature; that's all it can check. There's no CA store, no chain validation, no `addCA()` family.
3. **CSR "extensions" live inside an attribute.** A CSR has top-level *attributes*, and certificate-style extensions are bundled into a specific attribute (`pkcs-9-at-extensionRequest`). The `getExtension()` / `setExtension()` methods on `CSR` automatically navigate through this wrapper — see [Attributes vs. extensions](#attributes-vs-extensions) below.

---

## Loading

```php
public static function load(string|array|Constructed $csr, int $mode = ASN1::FORMAT_AUTO_DETECT): CSR
```

```php
use phpseclib4\File\CSR;

$csr = CSR::load(file_get_contents('request.csr'));
```

Accepts PEM or DER bytes and auto-detects. The `$mode` parameter takes the same `ASN1::FORMAT_AUTO_DETECT` / `ASN1::FORMAT_PEM` / `ASN1::FORMAT_DER` constants as `X509::load()`; auto-detect is rarely worth overriding.

Bad input throws `phpseclib4\Exception\UnexpectedValueException`. For untrusted input, wrap in try/catch.

There is no `loadCSR()` instance method in 4.0; the static factory is the only path. If you see `$csr = new CSR(); $csr->loadCSR($pem);` in code, that's a 3.0 pattern that needs migrating.

---

## Reading

### Inspecting the structure

```php
print_r($csr);
```

Triggers `__debugInfo()`. Same lazy-decoding mechanics as `X509` — only the fields you access get materialized. See [`references/asn1-constructed.md`](asn1-constructed.md) for the underlying lazy-decoding story.

### `getPublicKey()`

```php
public function getPublicKey(): PublicKey
```

Returns a `phpseclib4\Crypt\Common\PublicKey` (`RSA\PublicKey`, `EC\PublicKey`, or `DSA\PublicKey`). Throws `phpseclib4\Exception\UnexpectedValueException` if the key format isn't one phpseclib parses. Same behavior as `X509::getPublicKey()` — guard with `hasPublicKey()` if the input is untrusted.

### `hasPublicKey()`

```php
public function hasPublicKey(): bool
```

`true` if the CSR's SPKI slot decoded into a real `PublicKey` object. See [`references/x509.md` → `hasPublicKey()`](x509.md#haspublickey) for the full story on what `false` actually means here — same logic applies.

### `getSubjectDN()` and family

```php
public function getDN(int $format = ASN1::DN_STRING): array|string
public function getSubjectDN(int $format = ASN1::DN_STRING): array|string
public function getDNProps(string $propName): array
public function getSubjectDNProps(string $propName): array
public function hasDNProp(string $propName): bool
public function hasSubjectDNProp(string $propName): bool
```

`getDN()` and `getSubjectDN()` are aliases — both return the subject DN. `getDNProps()` / `getSubjectDNProps()` likewise. Pick whichever reads better; there's no functional difference.

See [`references/distinguished-names.md`](distinguished-names.md) for the full DN format and property-name story.

---

## Attributes vs. extensions

This is the part most likely to trip people up. A CSR has *attributes* at its top level, and *extensions* live inside one of those attributes.

### The structure

```
CSR
├── certificationRequestInfo
│   ├── subject (DN)
│   ├── subjectPKInfo (public key)
│   └── attributes
│       ├── pkcs-9-at-challengePassword  ← an attribute
│       ├── pkcs-9-at-extensionRequest   ← an attribute
│       │   └── extensions               ← extensions live in here
│       │       ├── id-ce-keyUsage
│       │       ├── id-ce-subjectAltName
│       │       └── ...
│       └── ... (other attributes)
├── signatureAlgorithm
└── signature
```

When the CA receives the CSR and decides to issue a cert, it can choose to honor or ignore each requested extension. The CSR is asking; the CA decides.

### Working with attributes

```php
public function listAttributes(): array
public function getAttribute(string $name): BaseType|Constructed|null
public function hasAttribute(string $name): bool
public function setAttribute(string $type, mixed $value): void
public function removeAttribute(string $type): void
```

`listAttributes()` returns the names (or OIDs) of all top-level attributes:

```php
print_r($csr->listAttributes());
// Array
// (
//     [0] => pkcs-9-at-extensionRequest
//     [1] => pkcs-9-at-challengePassword
// )
```

`getAttribute()` returns the raw value of the first matching attribute, or `null`. The shape depends on the attribute — for most CA-relevant attributes it'll be a typed `BaseType` (often a string-like).

`setAttribute()` adds or replaces an attribute. The `$value` is whatever the attribute's ASN.1 schema expects. For common attributes there are dedicated methods (see below); for less-common ones you need to know the schema.

### Working with extensions

```php
public function listExtensions(): array
public function getExtension(string $name): ?array
public function hasExtension(string $name): bool
public function setExtension(string $name, mixed $value, ?bool $critical = null): void
public function removeExtension(string $name): void
```

These look like their `X509` counterparts but transparently navigate into the `pkcs-9-at-extensionRequest` attribute on your behalf. `setExtension()` creates the attribute wrapper if it doesn't exist; `removeExtension()` removes individual extensions without affecting the attribute. `getExtension()` returns the same `['extnId' => ..., 'extnValue' => ..., 'critical' => bool]` shape as on `X509`.

The specialized helpers from `X509` also work on CSR:

```php
public function addDomains(string ...$domains): void
public function addIPAddresses(string ...$ipAddresses): void
```

These add DNS names / IPs to `id-ce-subjectAltName` exactly as they do on X509.

Some `X509` helpers don't make sense on CSR and aren't available — `makeCA()`, `setAuthorityKeyIdentifier()`, `setSubjectKeyIdentifier()`, `createSubjectKeyIdentifier()`. Setting key identifiers on a CSR is unusual because the CA assigns identifiers when issuing; if you do need to request a specific subject key identifier, use `setExtension('id-ce-subjectKeyIdentifier', ...)` manually.

### Why the wrapper exists

The CSR format predates the X.509 v3 extension model and uses the older attribute system at its top level. RFC 2985 defined the `extensionRequest` attribute as a way to smuggle v3-style extensions into the older structure. phpseclib's API papers over this — you can mostly pretend extensions live at the top level, the way they do on `X509` — but the distinction shows up if you walk the structure via ArrayAccess or if you need to set attributes that aren't extensions (like challenge passwords).

---

## Creating

```php
public function __construct(PublicKey|X509|null $csr = null)
```

Three construction paths:

```php
use phpseclib4\Crypt\EC;
use phpseclib4\File\CSR;

// Empty CSR, set fields manually
$csr = new CSR();
$csr->setPublicKey($privKey->getPublicKey());

// Pre-populated from a public key
$csr = new CSR($privKey->getPublicKey());

// Pre-populated from an existing X.509 cert (subject DN + public key + extensions copied)
$csr = new CSR($existingX509);
```

The X509-to-CSR path is useful for renewal: take an existing cert, build a CSR from it, sign with the same key, send to the CA. The copy includes the subject DN, the public key, and all extensions (but not attributes — CSRs and X.509 certs don't share attribute structure). See [From X509 to CSR](#from-x509-to-csr) for caveats.

### Setting the public key

```php
public function setPublicKey(PublicKey $publicKey): void
public function removePublicKey(): void
```

`setPublicKey()` installs (or replaces) the SPKI slot. `removePublicKey()` clears it back to a placeholder. Rarely needed except in tests.

### Setting the DN

Full DN method family — `setDN()`, `setSubjectDN()`, `addDNProp()`, `addSubjectDNProp()`, `resetDN()`, `resetSubjectDN()`, etc. All exist; subject and bare variants are aliases. See [`references/distinguished-names.md`](distinguished-names.md) for input formats and property names.

### Setting extensions

```php
$csr->addDomains('example.com', 'www.example.com');
$csr->setExtension('id-ce-keyUsage', [...]);
$csr->setExtension('id-ce-extKeyUsage', [...]);
```

Everything `X509::setExtension()` accepts works here too. The wrapper-attribute mechanics are handled transparently.

### Setting attributes

```php
$csr->setAttribute('pkcs-9-at-challengePassword', [['utf8String' => 'secret']]);
```

Lower-level than `setExtension()`. You need to know the ASN.1 schema for the attribute's value. For the common case of challenge passwords, use [`setChallengePassword()`](#the-challenge-password) instead.

### Custom extensions

```php
public static function registerExtension(string $id, array $mapping): void
public static function getRegisteredExtension(string $id): ?array
```

These are static and process-wide — registering on `CSR` makes the extension available everywhere phpseclib parses extension-shaped data. Same mechanics as `X509::registerExtension()`.

Custom OID-to-name aliases are registered separately via `ASN1::loadOIDs()`. See [`references/asn1-constructed.md` → Custom OIDs](asn1-constructed.md#custom-oids).

---

## The challenge password

The challenge password is a CSR-specific feature defined in RFC 2985. The idea: when the requester submits the CSR, they include a password; the CA stores it; later, if the requester needs to authenticate to the CA's website (to revoke the cert, request reissuance, etc.), they can prove their identity by reciting the password. It's a layer of authentication on top of the cryptographic identity the cert itself represents.

In practice, public CAs largely don't use this anymore — modern protocols use ACME or account-based authentication instead. Internal/corporate PKIs sometimes still use it.

```php
public function setChallengePassword(string|UTF8String|PrintableString $password): void
public function getChallengePassword(): ?string
```

```php
$csr->setChallengePassword('my-challenge-secret');
echo $csr->getChallengePassword();  // "my-challenge-secret"
```

Internally this is `setAttribute('pkcs-9-at-challengePassword', ...)` with the right value wrapping. Strings and `UTF8String` instances are encoded as `UTF8String` in the CSR; `PrintableString` instances as `PrintableString`.

`getChallengePassword()` returns `null` if no challenge-password attribute is set.

---

## Signing

```php
use phpseclib4\Crypt\EC;
use phpseclib4\File\CSR;

$privKey = EC::createKey('nistp256');
$csr = new CSR($privKey->getPublicKey());
$csr->setSubjectDN('/CN=example.com/O=Example');
$csr->addDomains('example.com', 'www.example.com');

$privKey->sign($csr);
echo $csr;
```

CSRs implement `Signable`, so the standard 4.0 signing idiom applies: the key signs the CSR, the call installs the signature into the CSR object as a side effect *and* returns the raw signature bytes, and you `echo` (or call `toString()` on) the CSR to get the PEM.

```php
public function getSignableSection(): string
public function setSignature(string $signature): void
public function identifySignatureAlgorithm(PublicKey $key): void
public function copySigningX509Attributes(X509 $x509): void
```

The Signable methods on CSR:

- `getSignableSection()` returns the encoded `certificationRequestInfo` bytes.
- `setSignature()` writes into the CSR's `signature` BIT STRING.
- `identifySignatureAlgorithm()` chooses the appropriate `signatureAlgorithm` OID based on the key — same logic as X509 via the `ASN1Signature` trait.
- `copySigningX509Attributes()` is a no-op on CSR (there's no issuer to copy from).

**`$pfx->sign($csr)` works** but is unusual. A PFX represents a CA's signing material — using it to sign a CSR would mean the CA is creating the CSR itself, which conflates the requester and issuer roles. In practice you use a `PrivateKey` (specifically, the key whose public side is in the CSR) to sign.

The "sign last" rule applies: configure all DNs, extensions, attributes, and the challenge password *before* calling `sign()`. Modifying after signing leaves the signature stale.

---

## Output format

```php
public function __toString(): string
public function toString(array $options = []): string

public static function enableBinaryOutput(): void
public static function disableBinaryOutput(): void
```

Default is PEM. The header is `-----BEGIN CERTIFICATE REQUEST-----` (not `-----BEGIN CSR-----` — that's a different format).

```php
// Per-call DER
$der = $csr->toString(['binary' => true]);

// Process-wide DER
CSR::enableBinaryOutput();
echo $csr;
CSR::disableBinaryOutput();
```

`getEncoded()` also exists on CSR and returns whatever the current output mode dictates — same as on X509.

---

## Validation

```php
public function validateSignature(): bool
```

Verifies that the CSR's embedded public key signed the `certificationRequestInfo` bytes correctly. There's no `$caonly` parameter (no CA concept for CSRs), no `loadCA()`/`addCA()` family, no chain validation, no date checking, no revocation lookup.

A `true` return means the CSR is internally consistent (the key in it produced the signature). A `false` return means either the signature is wrong or the key/algorithm is broken in some way.

`validateSignature()` does *not* tell you whether you should trust the CSR — that's a policy question the receiving CA answers separately. A CSR with a valid self-signature can still be a request you'd refuse to fulfill (wrong DN, wrong key size, requested extensions outside your policy, etc.).

---

## From X509 to CSR

```php
$csr = new CSR($existingX509);
```

Copies from the X509:

- The subject DN
- The public key
- All extensions (via `listExtensions()` on the X509 and `setExtension()` on the new CSR)

Does *not* copy:

- The issuer DN (CSRs don't have one)
- The signature (you have to sign the CSR yourself with the corresponding private key)
- Attributes (X509 doesn't have any to copy)
- The serial number, validity dates, etc. (CSRs don't carry those)

The typical use case is renewal: you have a cert about to expire and want to request a new one with the same subject and the same key. Build the CSR from the existing cert, optionally tweak (extend SAN, drop deprecated extensions), sign with the private key, submit to the CA.

**Watch out:** if the existing cert has extensions that came from the CA's policy rather than the original request (a CA-assigned subject key identifier, an authority key identifier, CRL distribution points), those get copied too. You usually want to strip them before signing, or the CA will see a renewal request with values it didn't issue and may reject the request:

```php
$csr = new CSR($existingX509);
$csr->removeExtension('id-ce-authorityKeyIdentifier');
$csr->removeExtension('id-ce-cRLDistributionPoints');
$csr->removeExtension('id-pe-authorityInfoAccess');
// Keep id-ce-subjectAltName, id-ce-keyUsage, id-ce-extKeyUsage etc. — those represent what the cert is for
$privKey->sign($csr);
```

What to strip depends on CA policy. The safe default: strip anything the CA would normally assign (CRL DPs, AIA, AKI, basic constraints if you're not requesting a CA cert) and keep anything that describes what the cert is for (SAN, key usage, extended key usage).

---

## ArrayAccess

CSR implements `ArrayAccess`, `Countable`, and `Iterator` — the same lazy-decoded structure access available on X509. The interior names follow the ASN.1 schema:

```php
$ri    = $csr['certificationRequestInfo'];
$subj  = $csr['certificationRequestInfo']['subject'];
$key   = $csr['certificationRequestInfo']['subjectPKInfo'];   // PublicKey or Constructed
$attrs = $csr['certificationRequestInfo']['attributes'];
$sig   = $csr['signature'];                                    // BitString
```

The same rules-driven pre-decoding that X509 uses applies here — by the time you ArrayAccess into `subjectPKInfo` or `subject`, the typed objects are already there (no extra decode work). The helper methods are thin checkers over the same slots. See [`references/asn1-constructed.md`](asn1-constructed.md) for the underlying mechanics.

When the helper methods don't cover what you need — walking unusual attribute structures, inspecting attributes phpseclib doesn't model, reaching into raw ASN.1 — ArrayAccess is the way. For the typical "I want a CN" / "I want the public key" / "I want the SAN list" cases, the helpers are shorter and read better.
