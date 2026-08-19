# `phpseclib4\Crypt\RSA`, `EC`, `DSA`, `DH`, and `PublicKeyLoader`

The full asymmetric-keys reference for phpseclib 4.0. Load this when SKILL.md isn't enough — when you need to know exactly which exceptions `PublicKeyLoader::load()` can raise, the supported curve list, the JWT signature-format wiring, the DSA L/N constraints, the PKCS8 encryption parameters, or the difference between named and specified curves.

This file covers key creation, loading, format handling, signing, encryption, and key agreement. The signing model itself (private key signs `string|Signable`) is documented in SKILL.md and in `references/asn1-constructed.md`; this file documents the byte-signing path and the format/parameter side of things.

For migrating 3.0 key code to 4.0 — short answer, it mostly already works as written; long answer, see [`references/migration-3-to-4.md` § Things that did not change](migration-3-to-4.md#things-that-did-not-change).

## Contents

- [Class layout](#class-layout)
- [Loading: `PublicKeyLoader`](#loading-publickeyloader)
- [Loading: per-algorithm `::load()`](#loading-per-algorithm-load)
- [Loading: `loadFormat()` for diagnostics](#loading-loadformat-for-diagnostics)
- [`PublicKey` vs `PrivateKey` interfaces](#publickey-vs-privatekey-interfaces)
- [Immutability — `with*()` returns new keys](#immutability--with-returns-new-keys)
- [RSA](#rsa)
- [EC (ECDSA, EdDSA)](#ec-ecdsa-eddsa)
- [DSA](#dsa)
- [DH and ECDH](#dh-and-ecdh)
- [Output formats](#output-formats)
- [PKCS8 encryption parameters](#pkcs8-encryption-parameters)
- [Engine selection](#engine-selection)
- [Fingerprints and comments](#fingerprints-and-comments)
- [Custom key formats](#custom-key-formats)
- [Common mistakes](#common-mistakes)

---

## Class layout

```
phpseclib4\Crypt\Common\AsymmetricKey            (abstract base)
├── phpseclib4\Crypt\RSA                          (abstract; can't instantiate directly)
│   ├── RSA\PublicKey       (final, implements Common\PublicKey)
│   ├── RSA\PrivateKey      (final, implements Common\PrivateKey)
│   └── RSA\Parameters      — N/A (RSA has no separable parameters)
├── phpseclib4\Crypt\EC                           (abstract)
│   ├── EC\PublicKey        (final, implements Common\PublicKey)
│   ├── EC\PrivateKey       (final, implements Common\PrivateKey)
│   └── EC\Parameters       (final — just curve info)
├── phpseclib4\Crypt\DSA                          (abstract)
│   ├── DSA\PublicKey       (final, implements Common\PublicKey)
│   ├── DSA\PrivateKey      (final, implements Common\PrivateKey)
│   └── DSA\Parameters      (final — p, q, g)
└── phpseclib4\Crypt\DH                           (abstract)
    ├── DH\PublicKey        (final)
    ├── DH\PrivateKey       (final)
    └── DH\Parameters       (final — prime, base)

phpseclib4\Crypt\PublicKeyLoader                  (helper; not in the class tree)
```

Two things worth internalizing:

1. **`RSA`, `EC`, `DSA`, `DH` are abstract.** You never have an `RSA` instance; you have an `RSA\PrivateKey` or `RSA\PublicKey` instance, both of which extend `RSA`. The `RSA::createKey()` and `RSA::load()` factories return one of the concrete leaf types depending on context.
2. **`Common\PublicKey` and `Common\PrivateKey` are interfaces, not classes.** When SKILL.md or other docs say "the key signs the object," the receiver type is one of these interfaces. `DH` does not implement them — DH keys aren't signing keys.

---

## Loading: `PublicKeyLoader`

```php
namespace phpseclib4\Crypt;

abstract class PublicKeyLoader
{
    public static function load(string|array $key, ?string $password = null): AsymmetricKey;
    public static function loadPrivateKey(string|array $key, ?string $password = null): PrivateKey;
    public static function loadPublicKey(string|array $key): PublicKey;
    public static function loadParameters(string $key): AsymmetricKey;
}
```

The default entry point when you have a key and don't yet know if it's RSA / EC / DSA / DH, or public / private. `PublicKeyLoader::load()` tries each algorithm in order (EC, RSA, DSA, then attempts X.509 extraction) and returns the first that parses.

```php
use phpseclib4\Crypt\PublicKeyLoader;

$key = PublicKeyLoader::load(file_get_contents('something.pem'));
// $key is an instance of phpseclib4\Crypt\Common\PublicKey OR
// phpseclib4\Crypt\Common\PrivateKey OR an EC\Parameters / DSA\Parameters /
// DH\Parameters object (if the input was a -----BEGIN EC PARAMETERS----- etc.).
```

An X.509 certificate passed to `PublicKeyLoader::load()` returns the cert's public key — convenient but bypasses signature/expiry validation, so for anything other than convenience use `X509::load($pem)->getPublicKey()` instead.

### The two-exception model

This is the single most important detail in 4.0's key-loading API:

```php
use phpseclib4\Exception\{NoKeyLoadedException, PasswordNeededException};

try {
    $key = PublicKeyLoader::load($bytes, $password);
} catch (PasswordNeededException $e) {
    // The bytes are recognizably a key, but it's encrypted and we either
    // didn't supply a password or the wrong one. Prompt for a password.
} catch (NoKeyLoadedException $e) {
    // The bytes aren't recognizable as any supported key format. This is
    // a "garbage input" signal.
}
```

`PasswordNeededException` and `NoKeyLoadedException` are deliberately distinct so callers can tell "we need a password from the user" apart from "this isn't a key at all." A 3.0-era catch-all `catch (\Exception $e) { /* prompt for password */ }` will prompt for a password when the input was garbage — annoying for the user; in 4.0 you can route the two cases separately.

`PasswordNeededException` fires when phpseclib parses far enough to recognize the bytes as an encrypted key but no password was supplied. A *wrong* password is different — `PublicKeyLoader::load()` can't tell "wrong password" apart from "this format didn't parse" (decryption failure looks like parse failure), so it falls through every format and ends with `NoKeyLoadedException`. To get a `BadDecryptionException` for the wrong-password case, you have to commit to a specific format up front using `RSA::loadFormat()` / `EC::loadFormat()` / etc. — see [Loading: `loadFormat()` for diagnostics](#loading-loadformat-for-diagnostics) below.

### Narrowing by direction

When you know the direction (public or private) but not the algorithm:

```php
$private = PublicKeyLoader::loadPrivateKey($bytes, $password);  // throws if it's a public key
$public  = PublicKeyLoader::loadPublicKey($bytes);              // throws if it's a private key
```

Both throw `NoKeyLoadedException` if the loaded result is the wrong direction. The variants are useful when an API contract says "we expect a private key here" — catching the right type at the boundary is cleaner than checking `instanceof` later.

**These variants also help static analysis.** `PublicKeyLoader::load()` is declared as returning `AsymmetricKey`, which is all a static analyzer can know — so `$key->sign(...)` on the result draws an `UndefinedMethod` error, since a `PublicKey` has no `sign()`, and `$key->getCurve()` draws one too, since an `RSA` key has no curve. Using `loadPrivateKey()` narrows the declared return type to `PrivateKey` and makes `sign()` analyzable for free.

When you need an algorithm-specific method (`getCurve()`, RSA's `encrypt()` / `decrypt()`), the right guard depends on where the key came from:

```php
// Untrusted or user-supplied key: the check is real, keep it.
$key = PublicKeyLoader::loadPrivateKey($upload);
if (!$key instanceof EC\PrivateKey) {
    throw new \DomainException('an EC key is required here');
}
$curve = $key->getCurve();

// Hardcoded key (fixtures, tests, pinned keys): the type is already determined
// by the literal. An assert() here appeases the analyzer without adding a check
// that can ever fail — prefer a docblock, or suppress at the call site.
/** @var RSA\PrivateKey $key */
$key = PublicKeyLoader::loadPrivateKey(self::FIXTURE_PEM);
```

The same reasoning applies to `CMS::load()`, whose subclass depends on the parsed `contentType`, and to `ASN1::map()` — see `references/asn1-constructed.md`.

### Array inputs

`PublicKeyLoader::load()` accepts an array for "raw" components. The most common case is RSA-from-components:

```php
use phpseclib4\Math\BigInteger;

$pub = PublicKeyLoader::load([
    'e' => new BigInteger($hexExponent, 16),
    'n' => new BigInteger($hexModulus, 16),
]);
// $pub->getLoadedFormat() returns 'Raw'
```

The exact accepted shapes vary by algorithm. For RSA it's `['e' => BigInteger, 'n' => BigInteger]` for public keys and the full component set for private keys; for EC and DSA the documented public format is whichever serialized string the format plugins accept. In practice, if you have raw integers, building a PKCS8 string and loading that is more portable.

---

## Loading: per-algorithm `::load()`

When you know the algorithm at parse time, you can skip `PublicKeyLoader` and go directly to the algorithm class:

```php
use phpseclib4\Crypt\{RSA, EC, DSA, DH};

$rsa = RSA::load(file_get_contents('rsa.pem'), $password);
$ec  = EC::load(file_get_contents('ec.pem'));
$dsa = DSA::load(file_get_contents('dsa.pem'));
$dh  = DH::load(file_get_contents('dh.pem'));
```

Each returns either a `PublicKey`, `PrivateKey`, or `Parameters` subclass of the corresponding algorithm. Faster than `PublicKeyLoader::load()` because phpseclib doesn't try the other three algorithms first, and the failure mode is sharper — if you say "load this as RSA" and it doesn't parse, you know it definitively isn't a recognizable RSA key.

`RSA::load()`, `EC::load()`, etc. also throw the same `NoKeyLoadedException` / `PasswordNeededException` pair as `PublicKeyLoader::load()`.

`DH::load()` is a thin wrapper that tries EC first and then DH-specific formats — it's the right entry point when you want either DH or ECDH and don't want to pre-classify.

---

## Loading: `loadFormat()` for diagnostics

If a key isn't loading and you want to know *why*, call the format-specific loader:

```php
use phpseclib4\Crypt\RSA;

$key = RSA::loadFormat('PKCS1', file_get_contents('weird.pem'), 'password');
```

The general `load()` family swallows per-format parsing errors as it tries each format in turn — if every format fails, you get the generic `NoKeyLoadedException` with no detail about *which* format was the closest match. `loadFormat()` skips the try-each-format loop and feeds the input straight to a specific format plugin, so its exceptions carry the actual parse-failure message.

```php
public static function loadFormat(string $type, string $key, ?string $password = null): AsymmetricKey;
public static function loadPrivateKeyFormat(string $type, string $key, ?string $password = null): PrivateKey;
public static function loadPublicKeyFormat(string $type, string $key): PublicKey;
public static function loadParametersFormat(string $type, string|array $key): AsymmetricKey;
```

`$type` is a format name like `'PKCS1'`, `'PKCS8'`, `'PuTTY'`, `'OpenSSH'`, `'JWK'`, `'XML'`, `'MSBLOB'`, `'Raw'`. The list of supported format names per algorithm is what `RSA::getSupportedKeyFormats()` returns (an associative array of name → fully-qualified plugin class).

```php
print_r(RSA::getSupportedKeyFormats());
// ['pkcs1' => 'phpseclib4\Crypt\RSA\Formats\Keys\PKCS1',
//  'pkcs8' => 'phpseclib4\Crypt\RSA\Formats\Keys\PKCS8',
//  ...]
```

Format names match case-insensitively for `loadFormat()` calls.

---

## `PublicKey` vs `PrivateKey` interfaces

```php
namespace phpseclib4\Crypt\Common;

interface PrivateKey
{
    public function sign(string|Signable $source): string|array;
    public function getPublicKey(): PublicKey;
    public function toString(string $type, array $options = []): array|string;
    public function withPassword(?string $string = null): PrivateKey;
}

interface PublicKey
{
    public function verify(string $message, string|array $signature): bool;
    public function toString(string $type, array $options = []): array|string;
    public function getFingerprint(string $algorithm = 'sha256'): string;
}
```

RSA `PrivateKey` and `PublicKey` additionally implement `decrypt()` / `encrypt()`, but these aren't part of the interface — EC and DSA don't support encryption, so the interface stays general. To use RSA's encryption methods, narrow with `instanceof phpseclib4\Crypt\RSA\PublicKey` (or just call the method and let the type system fail-fast if you have the wrong type).

`SSH\Agent\Identity` also implements `PrivateKey`, so an SSH agent identity can sign anything a real private key can — see SKILL.md's signing section.

`sign()` takes `string|Signable`:

- **String mode**: returns the raw signature bytes.
- **Signable mode**: also installs the signature into the passed object as a side effect, and returns the bytes. For X.509 cert creation you usually want `$priv->sign($x509); echo $x509;` — see SKILL.md's signing idiom for the full picture.

**Why the return type is `string|array`.** Every signature format returns a string *except* `Raw`, which returns `['r' => BigInteger, 's' => BigInteger]`. `verify()` is symmetric: it takes `string|array` and the two sides have to agree.

```php
$priv = $priv->withSignatureFormat('Raw');
$sig  = $priv->sign($message);              // array, not string
$ok   = $priv->getPublicKey()->verify($message, $sig);
```

The pairing is enforced, not merely conventional — a mismatch throws `phpseclib4\Exception\UnexpectedValueException`:

| Format on the key | Signature passed to `verify()` | Result |
| --- | --- | --- |
| `Raw` | `string` | throws — Raw signatures must be arrays |
| `ASN1` / `IEEE` / `SSH2` | `array` | throws — only Raw accepts arrays |
| RSA (any format) | `array` | throws — RSA has no Raw signature format |

So a `Raw` signature must be verified by a key that is *also* in `Raw` mode. `withSignatureFormat()` is an immutable setter like every other `with*()`, and `getPublicKey()` carries the format over from the private key, so the round trip above works — but a public key loaded separately from a PEM defaults to `ASN1` and will throw on an array.

Practical consequence for any code that stores or transmits signatures: `Raw` output can't be `echo`'d, concatenated, or base64-encoded. Doing so raises PHP's "Array to string conversion" notice and yields the literal string `Array`. `Raw` is for when you need `r` and `s` as `BigInteger`s to feed into something else; for wire formats use `IEEE` (JWT / WebCrypto), `ASN1` (X.509), or `SSH2`.

For raw byte signing — the focus of this reference — string mode is what you want:

```php
$sig = $priv->sign($message);
$ok  = $priv->getPublicKey()->verify($message, $sig);
```

ECDSA and DSA signatures generated this way are not deterministic (each call produces a different `(r, s)`), because phpseclib doesn't currently use RFC 6979 deterministic-k generation — it relies on `random_bytes()` for fresh randomness on each signature. EdDSA (Ed25519, Ed448) signatures *are* deterministic by definition of the algorithm. RSA-PSS produces fresh signatures each time (randomized padding); RSA-PKCS1 produces identical signatures for identical inputs (deterministic padding).

---

## Immutability — `with*()` returns new keys

Every `AsymmetricKey` is immutable. Setters use the `withX()` convention and return a *new* key with the property changed:

```php
$rsa  = $rsa->withHash('sha512')->withPadding(RSA::SIGNATURE_PKCS1);
$ec   = $ec->withHash('sha256')->withSignatureFormat('IEEE');
$dsa  = $dsa->withSignatureFormat('SSH2');
$priv = $priv->withPassword('demo');         // encryption when serialized
$priv = $priv->withPassword();               // strip password
```

The originals are unchanged. The common mistake is `$key->withHash('sha512')` and assuming the existing variable was modified — it wasn't:

```php
$key->withHash('sha512');           // RETURN VALUE DISCARDED — $key is unchanged
$sig = $key->sign($message);        // still using whatever hash was set before

$key = $key->withHash('sha512');    // CORRECT — assign back
$sig = $key->sign($message);
```

The `with*` methods all have matching getters:

| Setter (returns new key) | Getter |
| --- | --- |
| `withHash(string)` | `getHash(): Hash` |
| `withMGFHash(string)` (RSA only) | `getMGFHash(): Hash` |
| `withSaltLength(?int)` (RSA only; affects signing only) | `getSaltLength(): int` |
| `withLabel(string)` (RSA only) | `getLabel(): string` |
| `withPadding(int)` (RSA only) | `getPadding(): int` |
| `withSignatureFormat(string)` (EC, DSA) | `getSignatureFormat(): string` |
| `withContext(?string)` (EC Ed25519/Ed448 only) | `getContext(): string` |
| `withPassword(?string)` | (no getter — passwords aren't exposed) |

Passwords aren't exposed in stack traces either. Parameters that take passwords or key material are marked with PHP's [`#[\SensitiveParameter]`](https://www.php.net/manual/en/class.sensitiveparameter.php) attribute, so on PHP 8.2+ an exception thrown out of `withPassword()`, `PublicKeyLoader::load()`, and friends renders the argument as `Object(SensitiveParameterValue)` instead of the literal password. On PHP 8.1 the attribute is inert (it parses as an ordinary attribute and does nothing), so the redaction is a bonus of running 8.2+, not something to rely on there. phpseclib 3.0 has no such marking at all — if your application logs exception traces, this is a concrete reason to prefer 4.0 over the compat shim for code paths that handle key passwords.

Getters for hash-like properties return `Hash` instances rather than strings; cast to string with `(string)` if you want the name:

```php
echo (string) $key->getHash();   // "sha256"
echo $key->getHash()->getLengthInBytes();  // 32
```

---

## RSA

```php
use phpseclib4\Crypt\RSA;

$priv = RSA::createKey();                // 2048 bits, default
$priv = RSA::createKey(4096);             // 4096-bit
$pub  = $priv->getPublicKey();
```

### Configuration before key creation

```php
RSA::setExponent(65537);          // default; 65537 is standard. Some legacy tools use 37 or 3.
RSA::setSmallestPrime(4096);      // default; lower this for multi-prime RSA
```

**Leave the exponent alone unless you have a specific interop reason.** RSA requires the totient of the two primes to be relatively prime to the exponent. Since the exponent is itself prime in practice, the only way that fails is if the totient is a multiple of it — and the smaller the exponent, the likelier that is. So a small exponent (PuTTY used 37 until Feb 2020; 3 shows up in old hardware) meaningfully increases how often key generation has to throw away its primes and start over. That regeneration path carried a state-reset bug for roughly six years; it's fixed in 4.0.0 and backported to 3.0, but anyone on an older point release who lowers the exponent can still hit it. There is no security or performance argument for 37 over 65537 either way.

Multi-prime RSA: `RSA::setSmallestPrime(256)` before `createKey(2048)` produces an 8-prime, 2048-bit key. Generation is much faster (eight 256-bit primes vs. two 1024-bit primes), but interoperability is lower — most tools only handle 2-prime RSA. Useful when generating keys is the bottleneck and you control both sides.

### Signing

Two padding modes:

```php
$priv = $priv->withPadding(RSA::SIGNATURE_PSS);    // default — secure, randomized
$priv = $priv->withPadding(RSA::SIGNATURE_PKCS1);  // legacy, deterministic, more widely supported

$sig = $priv->sign($message);
$ok  = $priv->getPublicKey()->verify($message, $sig);
```

PSS is the default. It's more secure but PKCS1 is far more commonly seen in the wild (JWT `RS256`, most CA-issued certs, most existing systems). PKCS1 generates the same signature bytes every time for the same `(key, message, hash)` triple; PSS does not, because PSS embeds a random salt.

**Configuring PSS:**

```php
$priv = $priv
    ->withPadding(RSA::SIGNATURE_PSS)
    ->withHash('sha256')               // hash for the signature; default sha256
    ->withMGFHash('sha256')            // hash for the mask generation function; default sha256
    ->withSaltLength(32);              // salt length in bytes, signing only; default = hash output length
```

The minimum key length for PSS depends on the parameters: `getLength()` must be ≥ `8 * (hashBytes + saltBytes + 2)`. For sha256 + 32-byte salt: 528 bits, which 2048 vastly exceeds.

**Salt length discovery — `withSaltLength()` does nothing on the verify side:**

`withSaltLength()` configures signing. Verification ignores it, because phpseclib recovers the salt length from the encoded message instead: PSS lays out the padding as a run of zero bytes terminated by a single `0x01`, so the verifier can find where the padding ends and treat everything after it as the salt, whatever its length. That's salt length discovery, and it is **on by default** as of 4.0.0 (backported to 3.0.57).

```php
$pub = $pub->withSaltLength(64);     // no effect on verify()
$pub->verify($message, $sig);        // true for a 32-byte salt, a 64-byte salt, or none at all
```

It applies to salt lengths that arrive with the key, too, not just hand-set ones: loading an RSASSA-PSS key whose parameters carry a `saltLength` sets it via `withSaltLength()` and `getSaltLength()` will report it, but verification still won't enforce it.

Toggle it process-wide — it's a static, like `enableBlinding()`, not a per-key `with*()`:

```php
RSA::disableSaltLengthDiscovery();   // verify() enforces the configured salt length again
RSA::enableSaltLengthDiscovery();    // default
```

All of this is PSS-only; PKCS1 signatures have no salt.

What `withSaltLength()` still governs, discovery or not:

- the salt actually generated when signing — `$sLen ?? $hLen` bytes from `random_bytes()`
- the minimum key length check at signing time (the formula above)
- the `saltLength` parameter written out when serializing a key in `PSS` format
- what `getSaltLength()` returns

Accepting any salt length on verify is the normal posture for a PSS implementation — OpenSSL's verify path auto-detects as well, and can't be told not to. That's worth knowing before disabling discovery: with it off, phpseclib can no longer hand PSS verification to the OpenSSL engine and falls back to pure PHP, and `RSA::forceEngine('OpenSSL')` in that state throws `BadConfigurationException`. Disable it only when a protocol pins the salt length and you want a mismatch to be a hard failure.

**Version note:** 3.0.56 and earlier have no discovery at all — there, verification uses the configured salt length and rejects a signature whose salt doesn't match it. On those releases `withSaltLength()` on the *verifying* key is load-bearing, and getting it wrong is a plausible cause of an otherwise inexplicable `verify()` failure. On 3.0.57+ / 4.0.0 with defaults, it never is.

**Configuring PKCS1:**

```php
$priv = $priv->withPadding(RSA::SIGNATURE_PKCS1)->withHash('sha256');
```

Minimum key length depends on the hash (the "DigestInfo" prefix is encoded in the signature):

| Hash | Minimum bits |
| --- | --- |
| md5 | 360 |
| sha1 | 368 |
| sha224 / sha512/224 | 464 |
| sha256 / sha512/256 | 496 |
| sha384 | 624 |
| sha512 | 752 |

### Encryption

```php
$priv = $priv->withPadding(RSA::ENCRYPTION_OAEP);   // default — secure
$priv = $priv->withPadding(RSA::ENCRYPTION_PKCS1);  // legacy
$priv = $priv->withPadding(RSA::ENCRYPTION_NONE);   // textbook RSA — NEVER for real use

$ct = $priv->getPublicKey()->encrypt($plaintext);
$pt = $priv->decrypt($ct);
```

All padded modes produce different ciphertext for the same plaintext (randomized padding). OAEP is the secure default; PKCS1 v1.5 encryption is vulnerable to padding-oracle attacks in some deployment patterns and shouldn't be the new-code default.

**OAEP plaintext size limit** (in bytes): `($key->getLength() - 2 * $key->getHash()->getLength() - 16) >> 3`. For a 2048-bit key + sha256: 190 bytes. Plaintexts longer than this need to be encrypted in chunks (phpseclib does this automatically — just call `encrypt($longerPlaintext)`).

**PKCS1 plaintext size limit**: `($key->getLength() - 88) >> 3` bytes (2048-bit key: 245 bytes).

### Padding is a bitmask

```php
$priv->getPadding();  // returns RSA::SIGNATURE_PSS | RSA::ENCRYPTION_OAEP by default
$priv = $priv->withPadding(RSA::SIGNATURE_PKCS1 | RSA::ENCRYPTION_OAEP);
```

Sign and encrypt padding modes coexist on the same key — one of each at a time. `withPadding()` accepts either alone or both OR'd together.

### `asPrivateKey()` — using a public key as a private key

```php
$priv = $pub->asPrivateKey();
```

Lets you sign or decrypt with a key that was loaded as a public key. Useful when you have raw `(n, e, d)` components and don't have the extra CRT data that a typical "private key" carries — the result will work for `sign()` / `decrypt()` but will be slower than a full private key (no CRT speedup). Most users will never need this.

### Blinding

```php
RSA::disableBlinding();
RSA::enableBlinding();   // default
```

Blinding is a timing-attack mitigation. Default-on. Disable only if you have a specific reason — exposing a private key to a timing oracle is a real attack class.

### RSA components

```php
$priv->toArray();   // ['n', 'e', 'd', 'p', 'q', 'dp', 'dq', 'qi']
$pub->toArray();    // ['n', 'e']
```

Returns associative arrays of `BigInteger` values. The component names match what JWK uses (`n`, `e`, `d`, `p`, `q`, `dp`, `dq`, `qi`), so this is also the path for converting to/from JWK shape by hand.

---

## EC (ECDSA, EdDSA)

```php
use phpseclib4\Crypt\EC;

$priv = EC::createKey('Ed25519');         // EdDSA, fast, modern default
$priv = EC::createKey('nistp256');        // ECDSA on P-256 (a.k.a. secp256r1, prime256v1)
$priv = EC::createKey('secp256k1');       // Bitcoin's curve
$pub  = $priv->getPublicKey();
```

`EC::createKey()` only takes named curves — pass the curve name as a string. Specified-curve creation isn't supported.

### Supported curves (named)

The full list is in `Crypt/EC/Curves/`; key categories:

- **EdDSA** — `Ed25519` (use this), `Ed448`
- **Montgomery (DH only)** — `Curve25519`, `Curve448`
- **NIST prime** — `nistp192`, `nistp224`, `nistp256` (= `secp256r1` = `prime256v1`), `nistp384`, `nistp521`
- **SECG** — `secp256k1` (Bitcoin)
- **Brainpool** — `brainpoolP160r1` through `brainpoolP512t1`
- **NIST binary** — `nistb163`, `nistk163`, etc. (rarely used, less optimized)

Curve aliases work: `'secp256r1'`, `'prime256v1'`, and `'nistp256'` all create the same curve.

### Curve family ↔ capability

Not every curve supports every operation:

| Curve family | Signing | Key agreement |
| --- | --- | --- |
| Weierstrass (nistp*, secp*, brainpool*) | ECDSA | ECDH |
| Twisted Edwards (Ed25519, Ed448) | EdDSA | (use the corresponding Montgomery curve for DH) |
| Montgomery (Curve25519, Curve448) | not supported (throws `BadMethodCallException`) | ECDH only |

Calling `sign()` on a Montgomery-curve key throws; calling `withHash()` on EdDSA / Montgomery keys does nothing useful since the hash is built into the algorithm.

### Signing

```php
$priv = $priv->withHash('sha256')->withSignatureFormat('ASN1');
$sig  = $priv->sign($message);
$ok   = $priv->getPublicKey()->verify($message, $sig);
```

Four signature formats:

- **ASN1** (default) — RFC 3279 SEQUENCE-of-INTEGER. What X.509 certs use. Variable-length.
- **SSH2** — RFC 4253 / draft-ietf-curdle-ssh-ed25519 wire format.
- **IEEE** — fixed-length concatenation of `r` and `s` (P1363 / IEEE 1363). What JWT (JWS) and the WebCrypto API use.
- **Raw** — returns an array `['r' => BigInteger, 's' => BigInteger]` instead of a string. The verifying key must be in `Raw` mode too, or `verify()` throws — see [`PublicKey` vs `PrivateKey` interfaces](#publickey-vs-privatekey-interfaces).

For JWT (JWS) you almost always want `IEEE`:

```php
use phpseclib4\Common\Functions\Strings;

$priv = $priv->withHash('sha256')->withSignatureFormat('IEEE');
$jws  = Strings::base64url_encode($priv->sign("$header.$payload"));
```

For EdDSA, the format toggle is mostly between raw bytes (default) and SSH2 wrapping. `withHash()` is a no-op since Ed25519 has SHA-512 baked in.

### Context strings (Ed25519ctx, Ed448)

```php
$priv = $priv->withContext('my-application');   // up to 255 bytes
$sig  = $priv->sign($message);
```

Domain separation for EdDSA. The same `(message, key)` with different contexts yields different signatures; signatures verify only when the context matches. Only meaningful for Ed25519 and Ed448; throws on other curves.

### `getCurve()` / `getLength()`

```php
$curve  = $priv->getCurve();   // "nistp256", "Ed25519", or for specified curves an array
$bits   = $priv->getLength();  // bits in the modulus
```

`getCurve()` returns the canonical name (e.g., `'nistp256'`, not `'prime256v1'`) for named curves. For loaded specified curves it returns an array describing the curve parameters.

### Specified vs named curves

EC keys can be saved with either an OID reference to a named curve ("named") or with the curve parameters spelled out inline ("specified"). Specified curves bloat keys and are rarely needed, but some interop scenarios require them.

```php
use phpseclib4\Crypt\EC\Formats\Keys\PKCS8;

PKCS8::useSpecifiedCurve();    // process-wide
echo $key->toString('PKCS8');   // emits curve parameters inline

PKCS8::useNamedCurve();        // back to OID references (default)
```

Or per-call:

```php
echo $key->toString('PKCS8', ['namedCurve' => false]);    // specified
echo $key->toString('PKCS8', ['namedCurve' => true]);     // named (default)
```

Loading auto-detects either form. `EC::createKey()` only produces named-curve keys.

---

## DSA

```php
use phpseclib4\Crypt\DSA;

$priv = DSA::createKey();              // L=2048, N=224 (default)
$priv = DSA::createKey(2048, 224);
$priv = DSA::createKey(DSA::createParameters(2048, 256));
$pub  = $priv->getPublicKey();
```

DSA is largely a legacy algorithm — for new code, use EdDSA (Ed25519). DSA support in phpseclib is complete but you should have a specific reason (interop with old systems, FIPS compliance with a DSA-only deployment) to choose it over EC.

### Valid (L, N) pairs

```
(2048, 224)   — FIPS 186-3, default
(2048, 256)   — FIPS 186-3
(3072, 256)   — FIPS 186-3
```

Plus `N = 160` (any L value) for SSH2 / PuTTY compatibility. SSH2 only supports N=160 DSA per RFC 4253; if you're generating DSA keys for SSH, you need `(L, 160)`. Anything else throws `InvalidArgumentException`.

### Parameters

DSA splits cleanly between *parameters* (p, q, g) and *key material* (x, y). Parameters can be precomputed and shared between many keys:

```php
$params = DSA::createParameters(2048, 224);   // expensive — generates p, q, g
$priv1  = DSA::createKey($params);             // cheap — just generates x
$priv2  = DSA::createKey($params);             // another key, same params

// or extract from an existing key
$params = $priv1->getParameters();    // Parameters instance
echo $params->toString('PKCS1');       // -----BEGIN DSA PARAMETERS-----
```

The `getParameters()` method is also on EC: `$ecKey->getParameters()` returns an `EC\Parameters` (just curve info).

### Signing

```php
$sig = $priv->sign($message);
$ok  = $priv->getPublicKey()->verify($message, $sig);
```

Same three formats as EC: `ASN1` (default), `SSH2`, `Raw` (returns `['r', 's']` array; the verifying key must be in `Raw` mode too, or `verify()` throws). No `IEEE` format for DSA (it's not used in JWT for DSA — JWT only does ECDSA via JWS).

### Components

```php
$priv->toArray();   // ['p', 'q', 'g', 'y', 'x']
$pub->toArray();    // ['p', 'q', 'g', 'y']
$priv->getLength(); // ['L' => 2048, 'N' => 224]
```

Returns associative arrays of `BigInteger` clones.

---

## DH and ECDH

```php
use phpseclib4\Crypt\DH;
use phpseclib4\Math\BigInteger;
```

DH (Diffie-Hellman) and ECDH (Elliptic Curve Diffie-Hellman) are key-agreement protocols — two parties derive a shared secret without transmitting it. Both go through the same `DH` class.

### DH parameters

```php
// Named groups (RFC 2409 / RFC 3526)
$params = DH::createParameters('diffie-hellman-group14-sha256');

// Specific prime and base
$prime = new BigInteger('...', 16);
$base  = new BigInteger(2);
$params = DH::createParameters($prime, $base);

// Random prime of a given bit length, base = 2
$params = DH::createParameters(2048);
```

Named groups:

| Name | Source |
| --- | --- |
| `diffie-hellman-group1-sha1` | RFC 2409 — 768-bit, **weak, do not use** |
| `diffie-hellman-group14-sha1` | RFC 3526 — 2048-bit |
| `diffie-hellman-group14-sha256` | RFC 3526 — 2048-bit |
| `diffie-hellman-group15-sha512` | RFC 3526 — 3072-bit |
| `diffie-hellman-group16-sha512` | RFC 3526 — 4096-bit |
| `diffie-hellman-group17-sha512` | RFC 3526 — 6144-bit |
| `diffie-hellman-group18-sha512` | RFC 3526 — 8192-bit |

The `-sha1` / `-sha256` / `-sha512` suffix doesn't affect the DH math (the group is the same); it indicates which hash an SSH2 KEX algorithm of the same name would use. For DH itself, `group14-sha1` and `group14-sha256` produce the same shared secrets.

### DH key generation

```php
$params = DH::createParameters('diffie-hellman-group14-sha256');
$priv   = DH::createKey($params);                // full-length private exponent
$priv   = DH::createKey($params, 320);           // truncated private exponent (160-bit security target)
$pub    = $priv->getPublicKey();
```

The optional `$length` argument follows [RFC 4419 § 6.2](https://tools.ietf.org/html/rfc4419#section-6.2) — a shorter private exponent speeds up the modular exponentiation at the cost of some security margin. RFC 4419 advises at least twice the desired symmetric strength (so 160 → 320 for a 160-bit security level).

### ECDH

ECDH uses EC keys directly — there's no separate ECDH key class. Create an EC key on a curve that supports ECDH (any prime/binary Weierstrass curve, or Curve25519/Curve448 for the Montgomery family):

```php
use phpseclib4\Crypt\EC;

$priv = EC::createKey('Curve25519');   // modern ECDH
$priv = EC::createKey('nistp256');     // ECDSA-style curve, also fine for ECDH
$pub  = $priv->getPublicKey();
```

### Computing a shared secret

```php
$alice = DH::createKey(DH::createParameters('diffie-hellman-group14-sha256'));
$bob   = DH::createKey($alice->getParameters());

$secretA = DH::computeSecret($alice, $bob->getPublicKey());
$secretB = DH::computeSecret($bob, $alice->getPublicKey());
// $secretA === $secretB
```

`computeSecret()`'s public-key argument is flexible:

- For DH: a `DH\PublicKey`, a `BigInteger` (the y value), or a string (encoded BigInteger).
- For ECDH: an `EC\PublicKey` or a string (encoded coordinates).

Returns a string (the raw shared secret bytes).

### "Don't use the shared secret directly"

The output of `computeSecret()` is the raw DH/ECDH output. Never use it as a symmetric key directly — pipe it through a KDF (HKDF, or even just a hash if you're feeling lazy and the threat model permits it):

```php
use phpseclib4\Crypt\Hash;

$shared = DH::computeSecret($priv, $peerPub);
$keyMaterial = (new Hash('sha256'))->hash($shared);   // 32-byte symmetric key
```

Phpseclib doesn't ship an HKDF helper directly, but for most uses a single hash of the shared secret is adequate.

---

## Output formats

`$key->toString($format, $options = [])` serializes a key. PHP's string casting (`echo $key`, `"$key"`, `(string) $key`) uses `'PKCS8'`:

```php
echo $key;                              // PKCS8 PEM
echo $key->toString('PKCS8');           // same thing explicitly
echo $key->toString('PKCS1');           // -----BEGIN RSA PRIVATE KEY-----, etc.
echo $key->toString('OpenSSH');
echo $key->toString('PuTTY');
echo $key->toString('JWK');             // JSON
echo $key->toString('XML');
echo $key->toString('MSBLOB');          // RSA only; base64 Microsoft blob format
```

### Format coverage by algorithm

| Format | RSA | EC | DSA | DH |
| --- | --- | --- | --- | --- |
| PKCS1 | yes | yes (incl. specified curves) | yes | yes (parameters only) |
| PKCS8 | yes | yes | yes | yes |
| PSS | yes (RSA-PSS variant) | — | — | — |
| PuTTY (v2 + v3) | yes | yes (limited curve set) | yes (N=160 only) | — |
| OpenSSH | yes | yes (limited curve set; Ed25519) | yes (N=160 only) | — |
| JWK | yes | yes (limited curve set; OKP for EdDSA) | — | — |
| XML | yes (full) | yes (public only) | yes (public only) | — |
| MSBLOB | yes | — | — | — |

PuTTY and OpenSSH limit EC keys to nistp256, nistp384, nistp521, and Ed25519. JWK adds secp256k1 and Ed448.

### PEM vs DER

PKCS1 / PKCS8 are PEM-encoded text by default. For raw DER bytes, look at format plugins individually (or strip the PEM headers yourself — for many uses the PEM form is what you want).

### Encrypting on serialize

```php
echo $key->withPassword('demo')->toString('PKCS8');   // ENCRYPTED PRIVATE KEY
echo $key->withPassword('demo')->toString('PKCS1');   // Proc-Type: 4,ENCRYPTED
echo $key->withPassword('demo')->toString('PuTTY');   // PuTTY with aes256-cbc
echo $key->withPassword('demo')->toString('OpenSSH'); // OpenSSH with aes256-ctr + bcrypt KDF
```

The same key in memory can be saved encrypted or unencrypted on demand. `withPassword('')` and `withPassword(null)` both strip encryption. `withPassword()` on a public key throws — public keys aren't encryptable in any standardized format.

Note: loading an encrypted key + saving it again does **not** produce byte-identical output. Encryption parameters (salt, iteration count) are randomized on each save. The content is equivalent; the bytes aren't.

### PuTTY and OpenSSH comments

Both formats embed a comment string. Default is `phpseclib-generated-key`.

```php
echo $key->toString('PuTTY', ['comment' => 'alice@laptop']);
echo $key->toString('OpenSSH', ['comment' => 'alice@laptop']);
```

Or process-wide:

```php
use phpseclib4\Crypt\Common\Formats\Keys\{OpenSSH, PuTTY};

PuTTY::setComment('alice@laptop');
OpenSSH::setComment('alice@laptop');
```

`$key->getComment()` reads the comment back. Returns `null` for formats that don't carry comments. Note that PEM-armor "comment lines" (the `Proc-Type:` etc. headers in PKCS1) aren't comments in this sense.

### PuTTY v2 vs v3

```php
echo $key->toString('PuTTY');                          // v2 (default)
echo $key->toString('PuTTY', ['version' => 3]);        // v3 — Argon2id KDF
```

v3 uses Argon2id for password-based key derivation; v2 uses SHA-1 in a custom construction. v3 is more secure but only readable by puttygen 0.75+. Use v2 unless you specifically need v3 — the PuTTY ecosystem still defaults to v2.

---

## PKCS8 encryption parameters

PKCS8's encryption story is the most configurable of any format. Defaults are reasonable for new keys; tune only when you need interop with a specific tool.

```php
use phpseclib4\Crypt\Common\Formats\Keys\PKCS8;

// Defaults (effectively):
PKCS8::setEncryptionAlgorithm('id-PBES2');
PKCS8::setEncryptionScheme('aes128-CBC-PAD');
PKCS8::setPRF('id-hmacWithSHA256');
PKCS8::setIterationCount(2048);

echo $key->withPassword('demo')->toString('PKCS8');
```

Or per-call:

```php
echo $key->withPassword('demo')->toString('PKCS8', [
    'encryptionAlgorithm' => 'id-PBES2',
    'encryptionScheme'    => 'aes256-CBC-PAD',
    'PRF'                 => 'id-hmacWithSHA512-256',
    'iterationCount'      => 100000,
]);
```

### Encryption algorithms

- **`id-PBES2`** — PBKDF2 + configurable cipher (PBES2 from PKCS #5 v2). Modern. Default. Use this.
- **`pbeWith*`** family — PBES1 schemes from PKCS #5 v1, listed for legacy interop only. Includes `pbeWithMD5AndDES-CBC`, `pbeWithSHAAnd3-KeyTripleDES-CBC`, and several RC2/RC4 variants. Some openssl versions still default to one of these (`pbeWithSHAAnd3-KeyTripleDES-CBC` is common).

### Encryption scheme (PBES2 only)

Choose one for `setEncryptionScheme()`:
- `aes128-CBC-PAD` (default), `aes192-CBC-PAD`, `aes256-CBC-PAD`
- `desCBC`, `des-EDE3-CBC`, `rc2CBC` (legacy)

### PRF (PBES2 only)

Choose one for `setPRF()`:
- `id-hmacWithSHA1`
- `id-hmacWithSHA224` / `id-hmacWithSHA256` (default) / `id-hmacWithSHA384` / `id-hmacWithSHA512`
- `id-hmacWithSHA512-224` / `id-hmacWithSHA512-256`

### Inspecting an encrypted PKCS8 key

```php
print_r(PKCS8::extractEncryptionAlgorithm($pemBytes));
```

Tells you which scheme an existing encrypted key uses — useful when an "incompatible format" loading error suggests a specific encryption combination is the problem.

### PKCS1 encryption

Much simpler:

```php
use phpseclib4\Crypt\Common\Formats\Keys\PKCS1;

PKCS1::setEncryptionAlgorithm('AES-256-CBC');   // default 'AES-128-CBC'
echo $key->withPassword('demo')->toString('PKCS1');
```

Supported: `AES-128-CBC`, `AES-192-CBC`, `AES-256-CBC`, `DES-EDE3-CBC`, `DES-CBC` (and ECB/CFB/OFB/CTR variants). The `Proc-Type: 4,ENCRYPTED` header makes this PEM-only.

PuTTY and OpenSSH each only support one encryption algorithm and don't expose tuning knobs.

---

## Engine selection

Some operations have multiple implementations: pure-PHP, OpenSSL extension, libsodium. phpseclib picks the best available by default. Override for testing or compliance:

```php
use phpseclib4\Crypt\RSA;

RSA::forceEngine('OpenSSL');   // 'OpenSSL', 'libsodium', 'PHP', or null to reset
RSA::forceEngine(null);
RSA::getForcedEngine();        // 'OpenSSL' or null
```

The engine setting is process-wide and per-algorithm (`RSA::forceEngine()` only affects RSA). Setting it to an engine that's unavailable (libsodium when the extension isn't installed, OpenSSL when it doesn't support the curve) makes future operations throw `BadConfigurationException`.

**Migration note:** This pair was called `useBestEngine()` / `useInternalEngine()` / `getEngine()` in phpseclib 3.0 (and 4.0 early development). The rename to `forceEngine()` / `getForcedEngine()` was backported to phpseclib 3.0.51, so 3.0 code on a current point release already uses the new names — there's no version skew between 3.0.51+ and 4.0 here.

---

## Fingerprints and comments

### Fingerprints

```php
echo $pub->getFingerprint('sha256');   // SHA256:xxx... (matches `ssh-keygen -lf`)
echo $pub->getFingerprint('md5');      // xx:xx:xx:... (matches `ssh-keygen -lE md5 -lf`)
```

Only `'sha256'` (default) and `'md5'` are supported. Output format matches OpenSSH's `ssh-keygen` exactly. Only meaningful for keys that have an SSH2 wire encoding — RSA, DSA (N=160), and the subset of EC curves SSH2 supports.

`getFingerprint()` is on `PublicKey` only — private keys have a public key (`->getPublicKey()->getFingerprint()`).

### Comments

Round-tripping comments through OpenSSH or PuTTY formats:

```php
$loaded = PublicKeyLoader::load($pem);
echo $loaded->getComment();           // 'alice@laptop' or null
echo $loaded->toString('OpenSSH', ['comment' => $loaded->getComment() . ' (rotated)']);
```

`getComment()` returns the comment from the format the key was loaded from. Returns `null` for formats that don't carry comments (PKCS1, PKCS8, MSBLOB) — those formats don't fail; they just have no comment to surface.

---

## Custom key formats

`RSA::addFileFormat($className)` registers a class as a new format plugin:

```php
class MyFormat
{
    public static function load(string $key, ?string $password = null): array
    {
        // parse $key, return an array of components
        return ['e' => new BigInteger(...), 'n' => new BigInteger(...), ...];
    }

    // Optionally:
    public static function savePublicKey(BigInteger $n, BigInteger $e, array $options = []): string { ... }
    public static function savePrivateKey(/* ... */): string { ... }
}

RSA::addFileFormat(MyFormat::class);

$key = PublicKeyLoader::load($bytesInMyFormat);
$key->getLoadedFormat();   // 'MyFormat'
```

The class's short name becomes the format identifier. Format plugins live alongside built-in plugins; loading tries each registered format in turn until one parses successfully.

Two cautions:

1. **Don't make `load()` overly permissive.** If your format's `load()` accepts arbitrary strings, `PublicKeyLoader::load()` will route real RSA / EC / DSA keys to your format and your downstream code will get nonsense components.
2. **Don't trust user input as a format spec.** `loadFormat($userInput, ...)` is a way for arbitrary registered classes to be invoked — register only formats you wrote or trust.

---

## Common mistakes

Patterns that look reasonable but produce bugs:

1. **`$key->withHash('sha256')` without reassignment.** The originals are immutable; the returned new key is the one you want. Always `$key = $key->withHash(...)`.
2. **Catching only `\Exception` after `PublicKeyLoader::load()`.** Misses the chance to distinguish "password needed" from "garbage input." Catch `PasswordNeededException` separately to drive the password prompt.
3. **Calling `$priv->sign($x509)` and expecting the return value to be PEM.** It's the raw signature bytes; the *signed cert* is installed into `$x509` as a side effect. Do `$priv->sign($x509); echo $x509;` (see SKILL.md).
4. **Using `PublicKeyLoader::load()` on an X.509 cert for validation.** It returns the cert's public key but bypasses validation entirely. Use `X509::load($pem)->getPublicKey()` instead, after `validateSignature()`.
5. **Using ECDSA with a Montgomery curve.** Curve25519 and Curve448 are for ECDH only. Use Ed25519 / Ed448 for signing.
6. **Treating `$priv->sign($message)` as deterministic for ECDSA / DSA.** Each call produces a different signature. If you need byte-identical signatures, use RSA-PKCS1 or EdDSA — both are deterministic by design.
7. **Storing the DH shared secret directly as a symmetric key.** Always run it through a KDF or at least a hash.
8. **Multi-prime RSA assuming interop.** Most tools only handle 2-prime. Don't `setSmallestPrime(256)` unless you control both sides.
9. **PuTTY v3 against an older puttygen.** v3 requires puttygen 0.75+ (Sept 2021). v2 is still the safer default.
10. **Treating a `Raw` signature as a string.** `withSignatureFormat('Raw')` makes `sign()` return `['r' => BigInteger, 's' => BigInteger]`. `echo`-ing, concatenating, or base64-encoding that gives you the literal `Array`. Verification is symmetric — a `Raw` signature needs a key that's also in `Raw` mode, or `verify()` throws `UnexpectedValueException`. Use `IEEE`, `ASN1`, or `SSH2` for anything that goes over a wire or into storage.
11. **`asPrivateKey()` for performance.** It works for sign/decrypt but lacks CRT data, so it's slower than a key loaded as a full private key. Useful for correctness; not a performance shortcut.
