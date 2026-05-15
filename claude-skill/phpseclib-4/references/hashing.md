# Hashing: `phpseclib4\Crypt\Hash`

Reference for `phpseclib4\Crypt\Hash` — a wrapper over PHP's `hash()` / `hash_hmac()` that adds support for truncated hashes, SHA-3, Keccak, SHAKE, UMAC, AES-CMAC, and the PKCS#12 password-based key derivation function.

## When to reach for this at all

For plain hashing, **PHP's built-in `hash()` and `hash_hmac()` are almost always the right answer**. They're audited, they're fast, and they don't require pulling another class into the call path. Use phpseclib's `Hash` class when you need something `hash()` doesn't give you:

- **Truncated hashes** — `sha256-96`, `sha1-96`, etc., used by SSH and IPsec.
- **SHAKE128 / SHAKE256 at arbitrary output lengths** — phpseclib's `Hash` accepts `shake128-256`, `shake256-512`, etc.; PHP's `hash()` only does fixed lengths.
- **Keccak (not SHA-3)** — `keccak256` for Ethereum-flavor hashing, which is the pre-NIST-finalization Keccak with different padding from SHA-3.
- **UMAC** — RFC 4418 message authentication code.
- **AES-CMAC** — RFC 4493 MAC built from AES.
- **PKCS#12 KDF** — the password-based KDF from RFC 7292, used by PFX files.

If your hashing need is "SHA-256 this string" or "HMAC-SHA-256 this with this key," use `hash('sha256', $s)` or `hash_hmac('sha256', $s, $key)`. Don't reach for phpseclib for that.

## Contents

- [Construction](#construction)
- [Supported algorithms](#supported-algorithms)
- [Hashing](#hashing)
- [HMAC](#hmac)
- [UMAC](#umac)
- [AES-CMAC](#aes-cmac)
- [PKCS#12 KDF (`setPassword`)](#pkcs12-kdf-setpassword)
- [Length and block-length accessors](#length-and-block-length-accessors)
- [Exceptions](#exceptions)

---

## Construction

```php
use phpseclib4\Crypt\Hash;

$hash = new Hash();           // defaults to sha256
$hash = new Hash('sha512');
$hash = new Hash('sha3-256');
$hash = new Hash('umac-128');
```

The constructor takes an algorithm name. **Default is `'sha256'`**, not the older SHA-1 default you may have seen elsewhere in phpseclib (e.g., `setPassword()`'s `$hash` parameter still defaults to SHA-1, because the formats it's parsing default to SHA-1 — that's interoperability, not advice).

You can change algorithms after construction:

```php
$hash->setHash('sha384');
$hash->getHash();         // 'sha384'
echo $hash;               // 'sha384' — __toString returns getHash()
```

Passing an unrecognized name throws `UnsupportedAlgorithmException`.

## Supported algorithms

Anything in PHP's `hash_algos()` is supported — phpseclib delegates the actual computation. On top of that, `Hash` adds:

| Family | Names | Notes |
| --- | --- | --- |
| **Truncated SHA / MD** | `md2-96`, `md5-96`, `sha1-96`, `sha224-96`, `sha256-96`, `sha384-96`, `sha512-96`, `sha512-224-96`, `sha512-256-96` | 12-byte (96-bit) truncations used by SSH and IPsec |
| **SHA-3** | `sha3-224`, `sha3-256`, `sha3-384`, `sha3-512` | NIST FIPS 202; available in PHP 7.1+ via `hash()` but exposed here for uniformity |
| **Keccak** | `keccak256` | Pre-finalization Keccak with the original padding. *Not* the same as `sha3-256`. Used by Ethereum and other crypto-adjacent ecosystems. |
| **SHAKE** | `shake128-N`, `shake256-N` where N is the desired output length in bits (any positive value) | Variable-length output. `shake128-256` gives 256-bit output, `shake256-512` gives 512-bit output, etc. |
| **UMAC** | `umac-32`, `umac-64`, `umac-96`, `umac-128` | RFC 4418. Requires a 16-byte key and a 1–16-byte nonce. |
| **AES-CMAC** | `aes_cmac` | RFC 4493. Requires a 16-byte key. |

Names are normalized: case is lowered, and `/` is replaced with `-` (so `'SHA512/256'` becomes `'sha512-256'`).

## Hashing

```php
$hash = new Hash('sha256');
$digest = $hash->hash('hello world');     // raw 32-byte string
echo bin2hex($digest);                     // 'b94d27b9934d3e08a52e52d7da7dabfac484efe37a5380ee9088f7ace2efcde9'
```

`hash()` accepts either a string or a stream resource. With a resource, it reads to EOF.

```php
$fp = fopen('large-file.bin', 'rb');
$digest = $hash->hash($fp);
fclose($fp);
```

Returns raw bytes, not hex. Wrap in `bin2hex()` or `base64_encode()` if you need a printable form.

`hash()` can be called repeatedly on the same `Hash` object — there's no internal "consumed" state for plain hashes. (UMAC and AES-CMAC do have state — see their sections below.)

A few algorithms only accept strings: passing a resource to `aes_cmac` or `umac-*` throws `UnexpectedValueException` (`'aes_cmac only works with strings'` / `'umac only works with strings'`).

## HMAC

To compute an HMAC rather than a plain hash, call `setKey()` before `hash()`:

```php
$hash = new Hash('sha256');
$hash->setKey('shared-secret');
$mac = $hash->hash('the message');         // HMAC-SHA-256
```

Keys can be any length. The standard HMAC pre-hashing rules apply (RFC 2104) — if the key is longer than the block size, it's hashed down first; if shorter, it's null-padded internally.

Calling `setKey(null)` (or `setKey()` with no argument) removes the key — `hash()` reverts to plain hashing.

```php
$hash->setKey('secret');
$mac = $hash->hash($message);              // HMAC

$hash->setKey(null);
$digest = $hash->hash($message);           // plain hash
```

For most HMAC use cases, **`hash_hmac('sha256', $message, $key, true)` is simpler and faster** than instantiating a `Hash` object. Reach for `Hash::setKey()` when you're using a truncated algorithm (`sha256-96`), SHAKE, or Keccak — algorithms `hash_hmac()` doesn't directly accept.

## UMAC

UMAC (RFC 4418) is a fast MAC used by SSH for some cipher suites. It needs both a key and a nonce:

```php
$hash = new Hash('umac-128');
$hash->setKey($sixteenByteKey);              // 16 bytes, exactly
$hash->setNonce($nonce);                     // 1–16 bytes
$tag = $hash->hash($message);                // 16 bytes (umac-128)
```

- **Key**: must be exactly 16 bytes. Other lengths throw `LengthException` (`'Key must be 16 bytes long'`).
- **Nonce**: 1 to 16 bytes. Outside that range throws `LengthException`.
- **Output**: 4, 8, 12, or 16 bytes for `umac-32`, `umac-64`, `umac-96`, `umac-128` respectively.

Missing key or nonce throws `InvalidStateException` from `hash()` (`'No key has been set'` / `'No nonce has been set'`).

UMAC computation internally uses an AES round, which is why the class keeps an internal AES state. If you change the algorithm, key, or nonce, the next `hash()` call rebuilds it; otherwise it's cached for speed across calls.

## AES-CMAC

AES-CMAC (RFC 4493) is a deterministic MAC built from AES. It needs a key but no nonce:

```php
$hash = new Hash('aes_cmac');
$hash->setKey($sixteenByteKey);             // 16 bytes, exactly
$tag = $hash->hash($message);                // 16 bytes
```

- **Key**: must be exactly 16 bytes. Other lengths throw `LengthException`.
- Missing key throws `InvalidStateException` (`'No key has been set'`).
- **Output**: always 16 bytes.

Unlike HMAC, AES-CMAC is deterministic without a nonce — the same `(key, message)` always produces the same tag. That's expected for CMAC.

## PKCS#12 KDF (`setPassword`)

The `Hash` class also exposes the PKCS#12 password-based key derivation function from [RFC 7292 § B.2](https://tools.ietf.org/html/rfc7292#appendix-B.2). Unlike most `Hash` methods, `setPassword()` here **installs a key**, not a hash result:

```php
public function setPassword(string $password, string $salt, int $iterationCount): void
```

```php
$hash = new Hash('sha256');
$hash->setPassword('user-password', $salt, 2048);
// Internally calls setKey($derivedBytes); subsequent hash() calls produce HMACs with this key.

$mac = $hash->hash($message);
```

The derived key is the same length as the hash output (`getLengthInBytes()`). The KDF runs `$iterationCount` rounds of the hash function over the password-salt material. This is what PFX/PKCS#12 files use for HMAC integrity protection.

`setPassword()` on a `Hash` is distinct from `setPassword()` on a `SymmetricKey` — the latter has four KDF methods and derives both a key and an IV; this one is specifically PKCS#12 and derives only a key.

Calling `setPassword()` on a `Hash` configured with an algorithm that doesn't define a block size (none currently, but the check exists) throws `BadMethodCallException`.

## Length and block-length accessors

```php
$hash->getLength();              // hash output in bits
$hash->getLengthInBytes();       // hash output in bytes
$hash->getBlockLength();         // internal block length in bits
$hash->getBlockLengthInBytes();  // internal block length in bytes
```

`getLength` returns the hash output size — what `hash()` returns. For `sha256` that's 256/32; for `sha256-96` that's 96/12; for `shake128-512` that's 512/64.

`getBlockLength` returns the hash function's *input* block size — relevant for HMAC, which uses this internally. For SHA-2 it's 512 bits (1024 for SHA-384/SHA-512); for SHA-3 it varies with the output size (1088 bits for SHA-3-256, 576 for SHA-3-512, etc., per the sponge construction).

```php
$hash = new Hash('sha256');
$hash->getLength();              // 256
$hash->getLengthInBytes();       // 32
$hash->getBlockLength();         // 512
$hash->getBlockLengthInBytes();  // 64
```

## Exceptions

All exceptions live under `phpseclib4\Exception\`:

- `UnsupportedAlgorithmException` — unrecognized hash name passed to constructor or `setHash()`.
- `LengthException` — wrong-length key for UMAC / AES-CMAC, or nonce outside 1–16 bytes for UMAC.
- `InvalidStateException` — `hash()` called for UMAC/AES-CMAC without a key (and, for UMAC, without a nonce).
- `UnexpectedValueException` — `hash()` called on UMAC/AES-CMAC with a resource instead of a string, or `hash()` called with neither a string nor a resource.
- `BadMethodCallException` — `setPassword()` called on a `Hash` configured with an algorithm that doesn't define a block size.

All extend PHP's `\RuntimeException` and implement `phpseclib4\Exception\BaseException`.
