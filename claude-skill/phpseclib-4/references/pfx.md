# `phpseclib4\File\PFX`

Full reference for PFX / PKCS #12 files in phpseclib 4.0.

A PFX (also called PKCS #12, file extension `.pfx` or `.p12`) is a password-protected bundle that can hold certificates, private keys, and metadata. It's the standard interchange format for "give someone everything they need to act as this identity" — Windows uses it for certificate import/export, Apple Keychain uses it, many internal CAs distribute issued certs as PFXs.

PFX is brand new in phpseclib 4.0; there's no 3.0 equivalent. This reference covers what the class does and how to use it; it doesn't have a migration section because there's nothing to migrate from.

## Contents

- [What a PFX is](#what-a-pfx-is)
- [Loading](#loading)
- [Reading](#reading)
- [Selectors](#selectors)
- [Creating](#creating)
- [Adding objects](#adding-objects)
- [Passwords and encryption](#passwords-and-encryption)
- [Encryption parameters](#encryption-parameters)
- [Signing with a PFX](#signing-with-a-pfx)
- [Output format](#output-format)
- [ArrayAccess](#arrayaccess)

---

## What a PFX is

The PFX format (PKCS #12, RFC 7292) is essentially a container of "bags" — each bag holds a certificate, a private key, or other PKCS-defined data. Bags carry optional attributes: a *friendly name* (a human-readable label) and a *local key ID* (an opaque byte string typically used to associate a key with its corresponding cert).

```
PFX
├── content (one or more SafeContents)
│   ├── bag (e.g., KeyBag containing a private key)
│   │   ├── friendlyName: "Production signing key"
│   │   └── localKeyID: <bytes>
│   ├── bag (e.g., CertBag containing an X509)
│   │   └── friendlyName: "Production signing key"
│   └── bag (CertBag for an intermediate cert)
└── HMAC over content (for integrity)
```

Typical contents:
- **A leaf certificate plus its private key.** The common case — someone exporting their identity from a browser, a CA distributing a fresh cert to a customer. Both bags share a `localKeyID` so consumers can pair them up.
- **A full chain plus the leaf's private key.** Same as above but with the issuing CA's cert (and possibly intermediates) included.
- **Just one or more certificates, no keys.** Less common but valid — for bundling a trust store, for example.
- **Just one or more private keys, no certs.** Possible but rare.

The password protects the file in two ways: it derives an HMAC key (which signs the contents to detect tampering), and it derives an encryption key (which encrypts the private keys, and optionally the certificates too). A PFX without a password has no integrity protection and no encryption — useful for development/testing but rare in production.

phpseclib's `PFX` class models this as a structured collection. You build a PFX by `add()`-ing X509 and PrivateKey objects with optional metadata; you read one by `load()`-ing the file with the password and then querying it via selectors and bulk getters.

---

## Loading

```php
public static function load(
    string|array|Constructed $pfx,
    ?string $password = null
): self
```

```php
use phpseclib4\File\PFX;

$pfx = PFX::load(file_get_contents('keystore.pfx'), 'my-password');
```

The second argument is the password. Pass `null` (or omit) for an unencrypted PFX. If the file is encrypted and no password is supplied, `phpseclib4\Exception\PasswordNeededException` is thrown.

The `PasswordNeededException` is distinct from `BadDecryptionException`, which is what you get for a wrong password. So:

```php
try {
    $pfx = PFX::load($bytes, $userInput);
} catch (PasswordNeededException $e) {
    // File is encrypted; ask user for password
} catch (BadDecryptionException $e) {
    // User supplied wrong password
} catch (UnexpectedValueException $e) {
    // File isn't a valid PFX
}
```

Loading an encrypted PFX also keeps the password set on the object — so when you later modify and re-export with `toString()`, the file gets re-encrypted with the same password automatically. To change the password, see [Passwords and encryption](#passwords-and-encryption).

---

## Reading

The basic question — "what's in this PFX?" — is answered by `getAll()`:

```php
public function getAll(): array
public function getCertificates(): array
public function getPrivateKeys(): array
public function getFriendlyNames(): array
public function getLocalKeyIDs(): array
```

```php
$pfx = PFX::load($bytes, $password);

foreach ($pfx->getAll() as $obj) {
    if ($obj instanceof X509) {
        echo "Certificate: ", $obj->getSubjectDN(), "\n";
    } elseif ($obj instanceof PrivateKey) {
        echo "Private key: ", $obj::class, "\n";
    }
}
```

`getCertificates()` and `getPrivateKeys()` are convenience filters — they return only objects of the matching type, in PFX order.

`getFriendlyNames()` and `getLocalKeyIDs()` return the metadata strings that appear anywhere in the PFX. Use these to discover what labels are available before reaching for the selector methods.

---

## Selectors

Most real-world PFXs have one or two bags, but PFXs can hold arbitrary collections — and you may want only a subset. The selector methods return either the matching objects or a new sub-PFX containing them.

```php
public function pluckByFriendlyName(string|BaseString $value): array
public function pluckByLocalKeyID(string|BaseString $value): array
public function pfxFromFriendlyName(string|BaseString $value): self
public function pfxFromLocalKeyID(string|BaseString $value): self
```

```php
// Get the X509 and PrivateKey tagged "production"
$objects = $pfx->pluckByFriendlyName('production');
// [X509, PrivateKey, ...] — could be any mix matching that name

// Get the same objects packaged into their own PFX
$prodPfx = $pfx->pfxFromFriendlyName('production');
echo $prodPfx;  // outputs a new PFX containing just those objects
```

`pluckBy*` returns a flat array of objects; `pfxFrom*` wraps them in a new `PFX` instance. Use the array form for inspection or single-use signing; use the PFX form when you need to re-export or pass the subset to something that takes a PFX.

Both forms accept `string` or any `BaseString` instance for the value. Friendly names are conventionally UTF-8 strings; local key IDs are conventionally raw bytes — use whichever your PFX was built with.

---

## Creating

```php
public function __construct()
```

The constructor takes no arguments. A new PFX is empty.

```php
use phpseclib4\Crypt\EC;
use phpseclib4\File\PFX;

$priv = EC::createKey('Ed25519');
$x509 = /* ... build a cert signed by something ... */;

$pfx = new PFX();
$pfx->setPassword('my-password');     // encrypt on export
$pfx->add($x509);
$pfx->add($priv);
echo $pfx;
```

The order of operations matters slightly:
- Call `setPassword()` *before* `add()` if you want the resulting PFX to be encrypted. `add()` checks the current password state and bags-types things accordingly (a private key goes into a `PKCS8ShroudedKeyBag` when there's a password, a plain `KeyBag` otherwise).
- You can call `setPassword()` after `add()` too — it'll re-bag everything into the appropriate types. But the operation is more work and easier to get wrong.

---

## Adding objects

```php
public function add(
    X509|PrivateKey $obj,
    string|BaseString|array|null $friendlyName = null,
    string|BaseString|array|null $localKeyID = null
): void
```

`add()` accepts either an `X509` certificate or a `PrivateKey`. It does *not* accept `CSR` or `CRL` — those aren't PFX-shaped. If you have a CSR or CRL to ship, use a different container (or send them as separate files).

```php
$pfx->add($x509);                                        // no metadata
$pfx->add($x509, 'production');                          // friendly name only
$pfx->add($x509, 'production', $keyId);                  // both
$pfx->add($x509, ['production', 'web-cert'], $keyId);    // multiple friendly names
```

Both `friendlyName` and `localKeyID` accept a single string, a single `BaseString` (for explicit ASN.1 string typing), or an array of either (to attach multiple labels). The labels show up in `getFriendlyNames()` / `getLocalKeyIDs()` and can be selected on via `pluckBy*` / `pfxFrom*`.

A common idiom: when bundling a cert with its matching private key, give both bags the same `localKeyID` so consumers can pair them up:

```php
$keyId = random_bytes(16);
$pfx->add($x509, 'production', $keyId);
$pfx->add($priv, 'production', $keyId);
```

This is what Windows and Apple Keychain use to figure out which key belongs to which cert. It's also what phpseclib's own `sign()` method relies on if a PFX contains multiple cert/key pairs — though for signing you'd typically use a PFX containing just one pair.

---

## Passwords and encryption

```php
public function setPassword(?string $password = null): void
public function removePassword(): void
```

`setPassword()` sets the password used for both encryption (of private keys; optionally of cert bags) and HMAC integrity. Subsequent `toString()` / `echo` calls produce an encrypted PFX.

`removePassword()` (or `setPassword(null)`) strips encryption entirely — private keys come out unencrypted, no HMAC. The next export will be a plain PFX. Use this sparingly; an unencrypted PFX is usually not what you want.

Changing the password on a loaded PFX:

```php
$pfx = PFX::load($bytes, 'old-password');
$pfx->setPassword('new-password');
file_put_contents('reencrypted.pfx', $pfx->toString(['binary' => true]));
```

`setPassword()` handles the re-encryption transparently. Internally, if the PFX was already encrypted, it walks the contents, re-wraps the private keys with the new password, and updates the HMAC. If the PFX wasn't encrypted, it has more work to do (move private keys from `KeyBag` to `PKCS8ShroudedKeyBag`, possibly move cert bags into encrypted containers) — but the API is the same call.

---

## Encryption parameters

```php
public static function setHashAlgorithm(string $algo): void
public static function setIterationCount(int $count): void
public static function setSaltLength(int $length): void
```

These are process-wide defaults that apply when phpseclib *encrypts* a PFX. They don't affect decryption — when reading a PFX, phpseclib uses whatever parameters the file was encrypted with.

- **`setHashAlgorithm()`** — hash used by PBKDF2 and HMAC. Default is `'sha256'`. Older PFXs you want to interoperate with may need `'sha1'`.
- **`setIterationCount()`** — PBKDF2 iteration count. Higher = more resistant to brute-force, slower to encrypt/decrypt. RFC 7292 § 6 recommends 1024 or more; modern guidance is 100,000+. Legacy PFXs often use 2,048.
- **`setSaltLength()`** — length in bytes of the random PBKDF2 salt. Default is 32 bytes. RFC 7292 recommends matching the hash output length.

Call these once at startup if you want non-default values; the PFX you produce next will use them.

For interoperability with older systems (older OpenSSL, older Windows), you may need to lower iteration count and/or use SHA-1. For modern security, prefer SHA-256+ with 100k+ iterations.

---

## Signing with a PFX

```php
public function sign(string|Signable $source): string
```

A PFX containing a private key (and optionally a matching X509) can sign things — this is the canonical "CA signs a certificate" path:

```php
// Sign a CSR using the CA's PFX
$caPfx = PFX::load(file_get_contents('ca.pfx'), 'ca-password');
$x509 = new X509($csr);   // copies subject, public key, extensions from CSR
$caPfx->sign($x509);       // installs signature; auto-fills issuer DN and AKI
echo $x509;                 // PEM of the signed cert
```

The PFX must contain either:

- **Exactly one private key, no cert.** Behaves like signing with a bare `PrivateKey` — no auto-fill of issuer info. Useful when you have a separate CA cert source.
- **Exactly one private key and exactly one matching X509.** The X509's public key must match the private key's public side. In this case `sign()` calls `copySigningX509Attributes()` on the target first, which copies the CA cert's subject DN as the target's issuer DN and the CA cert's `subjectKeyIdentifier` as the target's `authorityKeyIdentifier`.

A PFX with more than one cert/key pair, or with non-matching cert and key, throws `phpseclib4\Exception\InvalidArgumentException` when you try to sign with it. If you have a PFX containing multiple identities, use `pfxFromFriendlyName()` / `pfxFromLocalKeyID()` to extract the specific pair you want to sign with.

`sign()` returns the raw signature bytes (matching the `PrivateKey::sign()` contract); for `Signable` targets it also installs the signature as a side effect, so you can usually ignore the return value:

```php
$caPfx->sign($x509);     // installs into $x509
echo $x509;               // signed cert PEM
```

vs. signing raw bytes:

```php
$sig = $caPfx->sign($messageBytes);   // returns raw signature
```

See [`references/x509.md` → Signing](x509.md#signing) and [`references/asn1-constructed.md` → The Signable interface](asn1-constructed.md#the-signable-interface) for the full Signable contract.

---

## Output format

```php
public function __toString(): string
public function toString(array $options = []): string
public function getEncoded(): string
```

PFX is fundamentally a binary format — there's no widely-used PEM encoding. `toString()` always returns binary DER bytes; unlike X509, CSR, and CRL there's no PEM/binary toggle because there's nothing to toggle between.

```php
file_put_contents('out.pfx', $pfx->toString());
file_put_contents('out.pfx', (string) $pfx);          // same thing
```

The `$options` array on `toString()` is used for encryption parameter overrides — `hashAlgorithm`, `saltLength`, `iterationCount` — analogous to the process-wide `setHashAlgorithm()` / `setIterationCount()` / `setSaltLength()` defaults but scoped to this one call:

```php
$bytes = $pfx->toString([
    'hashAlgorithm' => 'sha256',
    'iterationCount' => 100000,
    'saltLength' => 16,
]);
```

This overrides whatever the process-wide defaults are without changing them.

---

## ArrayAccess

PFX implements `ArrayAccess`, `Countable`, and `Iterator`. The top-level keys follow the ASN.1 structure (`version`, `authSafe`, `macData`). For most use cases the helper methods (`getAll`, `getCertificates`, `pluckBy*`, etc.) are more convenient — they hide the bags-and-content nesting and give you the actual cert/key objects directly.

```php
$version = $pfx['version'];        // Integer
$safe    = $pfx['authSafe'];       // Constructed
$mac     = $pfx['macData'] ?? null;
```

Read [`references/asn1-constructed.md` → Autovivification on read](asn1-constructed.md#autovivification-on-read) before reaching into the structure with bare ArrayAccess — `??` for optional fields, `isset()` for present-checks. Most PFX code shouldn't need to go this deep; the helpers handle the typical paths.
