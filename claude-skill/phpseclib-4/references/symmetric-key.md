# Symmetric-key ciphers

Reference for `phpseclib4\Crypt\AES`, `Rijndael`, `DES`, `TripleDES`, `Blowfish`, `Twofish`, `RC2`, `RC4`, `Salsa20`, and `ChaCha20`.

## When to reach for this at all

Symmetric-key ciphers aren't a focus of phpseclib. The library carries pure-PHP implementations of them mostly because it needs them internally — for PKCS#8 key encryption, for PFX/PKCS#12 bag encryption, for SSH transport, for CMS `EncryptedData`. They're exposed publicly because they're already there, but for the typical "encrypt this file" or "encrypt this database column" task, **`openssl_encrypt()` / `openssl_decrypt()` are almost always the right answer** — they're faster, they're audited, and they don't pull a 200KB pure-PHP cipher into the call path.

Reach for `phpseclib4\Crypt\*` when:

- You need an algorithm OpenSSL doesn't ship — **Rijndael with a non-128-bit block size** (mcrypt used to do this; OpenSSL doesn't), Salsa20, Twofish in modern OpenSSL builds, Blowfish in OpenSSL 3.0+ legacy-provider builds.
- You're already inside phpseclib code (CMS, PFX, SSH2) and want to stay there.
- You need a feature phpseclib exposes that OpenSSL's PHP binding doesn't — continuous-buffer chaining across `encrypt()` calls, PBKDF1, PKCS#12 KDF, BCrypt-PBKDF (the OpenSSH variant).
- You're targeting an environment without the OpenSSL extension.

For "I want CMS-style authenticated encryption with a real auditable construction," look at `phpseclib4\File\CMS` (`EncryptedData`) rather than rolling your own with these classes.

For best-practices algorithm choice when you *do* need to pick one, **ChaCha20-Poly1305** is the current recommendation, with **AES-GCM** as the second choice. Both are authenticated; everything else in this reference is unauthenticated unless you bolt on a MAC yourself.

## Contents

- [Class layout: BlockCipher vs StreamCipher](#class-layout-blockcipher-vs-streamcipher)
- [Block cipher modes](#block-cipher-modes)
- [The minimum encrypt / decrypt cycle](#the-minimum-encrypt--decrypt-cycle)
- [`setKey()` vs `setPassword()`](#setkey-vs-setpassword)
- [`setIV()` vs `setNonce()`](#setiv-vs-setnonce)
- [Per-cipher key and nonce constraints](#per-cipher-key-and-nonce-constraints)
- [Padding](#padding)
- [Continuous buffer](#continuous-buffer)
- [Authenticated encryption: GCM and Poly1305](#authenticated-encryption-gcm-and-poly1305)
- [TripleDES and 3CBC](#tripledes-and-3cbc)
- [Cipher attributes](#cipher-attributes)
- [Engine selection](#engine-selection)
- [Exceptions](#exceptions)

---

## Class layout: BlockCipher vs StreamCipher

Every cipher class extends `phpseclib4\Crypt\Common\SymmetricKey`. One layer down, ciphers extend either `BlockCipher` or `StreamCipher`:

```
SymmetricKey (abstract)
├── BlockCipher (abstract)
│   ├── Rijndael
│   │   └── AES                  // Rijndael with fixed 128-bit block
│   ├── DES
│   │   └── TripleDES
│   ├── Blowfish
│   ├── Twofish
│   └── RC2
└── StreamCipher (abstract)
    ├── RC4
    ├── Salsa20
    │   └── ChaCha20
    └── (others built on Salsa20)
```

The practical difference: **block ciphers take a mode as a constructor argument; stream ciphers don't**. The mode determines how blocks are chained, padded, and combined with the IV.

```php
use phpseclib4\Crypt\AES;
use phpseclib4\Crypt\ChaCha20;

$block  = new AES('ctr');     // mode required
$stream = new ChaCha20();      // no mode — there's only one
```

Stream ciphers also never use an IV (`usesIV()` returns `false`); they use a *nonce* instead. See [`setIV()` vs `setNonce()`](#setiv-vs-setnonce).

## Block cipher modes

The mode string passed to the constructor:

| Mode | Constant | Notes |
| --- | --- | --- |
| `ctr` | `MODE_CTR` | Counter mode. Parallelizable. Needs IV. No padding required. |
| `ecb` | `MODE_ECB` | Electronic Code Book. **Don't use.** Identical plaintext blocks produce identical ciphertext blocks. |
| `cbc` | `MODE_CBC` | Cipher Block Chaining. Needs IV. Padding required. |
| `cfb` | `MODE_CFB` | Cipher Feedback. Needs IV. |
| `cfb8` | `MODE_CFB8` | 8-bit CFB. Needs IV. |
| `ofb` | `MODE_OFB` | Output Feedback. Needs IV. |
| `ofb8` | `MODE_OFB8` | 8-bit OFB. Needs IV. |
| `gcm` | `MODE_GCM` | Galois/Counter Mode. **Authenticated.** Needs nonce, not IV. 128-bit block ciphers only (so AES, but not DES/Blowfish). See [Authenticated encryption](#authenticated-encryption-gcm-and-poly1305). |

The mode argument is case-insensitive. Passing an unrecognized mode throws `InvalidArgumentException`. `InvalidModeException` is reserved for GCM-specific issues — notably, asking for GCM on a non-128-bit-block cipher (`new TripleDES('gcm')` throws `InvalidModeException: 'GCM is only valid for block ciphers with a block size of 128 bits'`).

`MODE_STREAM` (`'stream'`) is the internal constant the `StreamCipher` base passes up to `SymmetricKey::__construct()`. You won't pass it yourself — block ciphers reject it (`InvalidArgumentException: Block ciphers cannot be ran in stream mode`), stream ciphers set it automatically.

## The minimum encrypt / decrypt cycle

```php
use phpseclib4\Crypt\AES;

$cipher = new AES('ctr');
$cipher->setKey(random_bytes(16));      // 128-bit key
$cipher->setIV(random_bytes(16));       // 128-bit IV (block size)

$ciphertext = $cipher->encrypt('hello');
echo $cipher->decrypt($ciphertext);     // "hello"
```

Three required steps for block ciphers: pick a mode, set the key, set the IV (unless the mode is ECB). For stream ciphers it's two: set the key, set the nonce.

```php
use phpseclib4\Crypt\ChaCha20;

$cipher = new ChaCha20();
$cipher->setKey(random_bytes(32));      // 256-bit key
$cipher->setNonce(random_bytes(12));    // 96-bit nonce (RFC 7539)

$ciphertext = $cipher->encrypt('hello');
```

**phpseclib does not silently substitute zeros for a missing key or IV.** phpseclib 2.0 did; 4.0 throws `InvalidStateException` (`'No key has been defined - call setKey() first'`). This is by design — silent null-padding is how cryptography quietly becomes useless.

`random_bytes()` is PHP's built-in CSPRNG. **Do not use `phpseclib4\Crypt\Random` — that class does not exist in 4.0.** The old 3.0 `Random::string()` helper is gone; `random_bytes()` is the replacement.

## `setKey()` vs `setPassword()`

These do different things, and using one when you wanted the other is a common bug.

**`setKey(string $key)`** installs `$key` as the literal cipher key. The string must already be exactly the right length (or, for the variable-length ciphers, a supported length). Pass a 9-byte string to `AES::setKey()` and you get a `LengthException`, not silent padding.

**`setPassword(string $password, string $method = 'pbkdf2', ...)`** derives a key from a (possibly weak) password using one of four KDFs and then calls `setKey()` for you (and, for PBKDF1 / PKCS#12, also calls `setIV()`).

Supported KDFs:

```php
$cipher->setPassword('hunter2');                                          // pbkdf2 (default)
$cipher->setPassword('hunter2', 'pbkdf2', 'sha256', $salt, 100000, 32);   // pbkdf2 with explicit params
$cipher->setPassword('hunter2', 'pbkdf1', 'sha256', $salt, 1000, 32);     // pbkdf1
$cipher->setPassword('hunter2', 'pkcs12', 'sha256', $salt, 2048);         // pkcs12 (sets IV too)
$cipher->setPassword('hunter2', 'bcrypt', $salt, 16, 32);                 // bcrypt-pbkdf (OpenSSH variant)
```

Trailing arguments by KDF:

| `$method` | After `$password`, `$method`: |
| --- | --- |
| `'pbkdf2'` (default) | `$hash = 'sha1'`, `$salt = 'phpseclib/salt'`, `$count = 1000`, `$dkLen = key length` |
| `'pbkdf1'` | `$hash`, `$salt`, `$count`, `$dkLen` — also sets IV from the second half of the derived material |
| `'pkcs12'` | `$hash`, `$salt`, `$count`, `$dkLen` — also sets IV |
| `'bcrypt'` | `$salt`, `$rounds = 16`, `$keylen = key length` — also sets IV |

A few gotchas:

- The PBKDF2 defaults (SHA-1, 1000 iterations, the literal salt `"phpseclib/salt"`) are **not modern-secure**. They exist because some on-disk formats (older PKCS#5 PEM blobs, WPA-PSK) use exactly these parameters. For new code, pass explicit values: a random salt, ≥100,000 iterations, SHA-256 or better.
- Argon2 is not supported. phpseclib intentionally doesn't ship it (too slow in pure PHP; the formats phpseclib parses don't use it). If you want Argon2, derive the key with PHP's `sodium_crypto_pwhash()` and pass the result to `setKey()`.
- `bcrypt` here is the **OpenSSH bcrypt-pbkdf** variant, not standard bcrypt. It's specifically for parsing OpenSSH-encrypted private keys.

If `$method` is anything other than the four above, `UnsupportedAlgorithmException` is thrown.

## `setIV()` vs `setNonce()`

An IV and a nonce serve the same purpose — making `encrypt(plaintext)` produce different ciphertext on each call — but phpseclib distinguishes them by which API takes which:

- **`setIV()`** — for block-cipher modes (CBC, CTR, CFB, OFB, etc.). Length must equal the block size: 16 bytes for AES, 8 for DES/Blowfish, etc. `usesIV()` returns `true`.
- **`setNonce()`** — for GCM (on block ciphers) and for stream ciphers (Salsa20, ChaCha20). Length depends on the algorithm. `usesNonce()` returns `true`.

```php
$cipher = new AES('gcm');
$cipher->usesIV();        // false
$cipher->usesNonce();     // true
$cipher->setNonce(random_bytes(12));
```

```php
$cipher = new AES('cbc');
$cipher->usesIV();        // true
$cipher->usesNonce();     // false
$cipher->setIV(random_bytes(16));
```

Stream ciphers' `usesIV()` always returns `false` — even though they pass an IV-shaped value into OpenSSL under the hood, the public contract is "set a nonce."

## Per-cipher key and nonce constraints

| Cipher | Key sizes (bytes) | Block / nonce | Notes |
| --- | --- | --- | --- |
| `AES` | 16, 24, 32 | 16-byte block | GCM nonce typically 12 bytes |
| `Rijndael` | 16, 20, 24, 28, 32 | 16, 20, 24, 28, or 32-byte block (via `setBlockLength()`) | AES is Rijndael with fixed 128-bit block |
| `DES` | 8 | 8-byte block | Insecure. Single-DES. |
| `TripleDES` | 16 or 24 | 8-byte block | 16 → keying option 2 (auto-extended to 24 internally) |
| `Blowfish` | 4–56 (32–448 bits) | 8-byte block | Variable key length |
| `Twofish` | 16, 24, 32 | 16-byte block | |
| `RC2` | variable | 8-byte block | Used by older PKCS#12 |
| `RC4` | variable | n/a (stream) | Insecure. Used by older protocols. |
| `Salsa20` | 16 or 32 | 8-byte nonce | 64-bit nonce only |
| `ChaCha20` | 16 or 32 | 8 or 12-byte nonce | 12-byte nonce per RFC 7539 |

Passing a wrong-length key throws `LengthException` from `setKey()`. The error message names the acceptable sizes — e.g., AES says `'Key of size 5 not supported by this algorithm. Only keys of sizes 16, 24 or 32 supported'`.

If you call `setKeyLength()` before `setKey()`, subsequent `setKey()` calls must match that length exactly — passing a key of any other length, even an otherwise-valid one, throws.

For ChaCha20 the 8-byte nonce path uses original-Salsa20-style construction; the 12-byte path uses the RFC 7539 IETF construction. If you're interoperating with something specific, match its nonce size.

## Padding

PKCS#5 / PKCS#7 padding is **on by default** for block ciphers. The plaintext is padded out to a multiple of the block size on `encrypt()` and stripped on `decrypt()`.

```php
$cipher->disablePadding();    // raw mode — plaintext length must be a multiple of block size
$cipher->enablePadding();     // default
```

Disable padding when you're handling padding externally (CMS does this, for example, because PKCS#7 padding is part of the CMS spec layer above the cipher).

Stream ciphers don't pad — there's no block boundary to pad to. Calling `enablePadding()` / `disablePadding()` on a stream cipher is harmless but has no effect.

Modes that don't need padding (CTR, OFB, CFB, GCM, CFB8, OFB8) ignore the padding setting on encrypt — the ciphertext is always the same length as the plaintext. CBC and ECB do pad.

## Continuous buffer

By default, each `encrypt()` call is a fresh encryption from the configured IV — calling `encrypt('foo')` twice produces the same ciphertext both times. Enable continuous-buffer mode and successive calls chain instead:

```php
$cipher->enableContinuousBuffer();
$a = $cipher->encrypt('hello');
$b = $cipher->encrypt('world');
// $a . $b == (fresh-cipher)->encrypt('helloworld')
```

This is the same idea as PHP's [`hash_init()`](https://www.php.net/manual/en/function.hash-init.php) / `hash_update()` for hashes. SSH transport, for example, encrypts each packet as a continuation of the previous one — that's what `enableContinuousBuffer()` is for.

Re-disable with `disableContinuousBuffer()`. Re-`setIV()` or `setKey()` also resets the buffer.

## Authenticated encryption: GCM and Poly1305

Unauthenticated cipher modes (CTR, CBC, …) protect *confidentiality* but not *integrity* — an attacker who flips ciphertext bits can flip plaintext bits, and you have no way to detect it. **Authenticated encryption** modes produce a tag alongside the ciphertext; `decrypt()` verifies the tag and refuses to return plaintext if it doesn't match.

phpseclib offers two:

**AES-GCM** — `new AES('gcm')`. 128-bit-block ciphers only. Standard, widely supported.

**ChaCha20-Poly1305 (and Salsa20-Poly1305)** — `(new ChaCha20())->enablePoly1305()`. Faster on devices without AES hardware acceleration.

### GCM

```php
$cipher = new AES('gcm');
$cipher->setKey(random_bytes(32));
$cipher->setNonce(random_bytes(12));    // 12 bytes is the GCM standard
$cipher->setAAD('header-bytes');         // optional, authenticated but not encrypted

$ciphertext = $cipher->encrypt('secret payload');
$tag = $cipher->getTag();                // 16 bytes by default; getTag(12) for shorter

// transmit $ciphertext, $tag, $nonce, $aad together

// later, on the receiver:
$cipher = new AES('gcm');
$cipher->setKey($sameKey);
$cipher->setNonce($nonce);
$cipher->setAAD($aad);
$cipher->setTag($tag);                    // MUST set before decrypt()
$plaintext = $cipher->decrypt($ciphertext);  // throws BadDecryptionException on tag mismatch
```

The tag is 16 bytes (128 bits) by default. `getTag($n)` returns the first `$n` bytes, where `$n` is between 4 and 16. Truncating to 12 is common and acceptable; truncating to 4 is the minimum and gives only weak authenticity guarantees.

Reusing a nonce with the same key in GCM is **catastrophic** — it breaks both authenticity and confidentiality. Always generate a fresh nonce per message. `random_bytes(12)` is fine.

### Poly1305 (ChaCha20 / Salsa20)

```php
$cipher = new ChaCha20();
$cipher->setKey(random_bytes(32));
$cipher->setNonce(random_bytes(12));
$cipher->enablePoly1305();              // turn on AEAD
$cipher->setAAD('header');

$ciphertext = $cipher->encrypt('secret payload');
$tag = $cipher->getTag();
```

By default phpseclib generates the Poly1305 key from the cipher key/nonce using the construction from [RFC 8439 § 2.6.1](https://tools.ietf.org/html/rfc8439#section-2.6.1). If you need a different construction (SSH does), set it manually:

```php
$cipher->setPoly1305Key(random_bytes(32));   // 256-bit
```

The Poly1305 tag-verification path on `decrypt()` is the same as GCM: call `setTag($tag)` first, and `decrypt()` throws `BadDecryptionException` on mismatch.

### `setAAD()`

For both GCM and Poly1305, `setAAD()` sets "additional authenticated data" — bytes that get authenticated by the tag but not encrypted. Use it for headers, metadata, or any value whose integrity you need to guarantee but whose secrecy you don't. Default is the empty string.

## TripleDES and 3CBC

`TripleDES` has all the normal modes (cbc, ctr, …) plus one peculiar one: **3CBC** (inner chaining), used by SSH-1. Outer chaining (`cbc`, also called `cbc3`) is what SSH-2 and everything else uses.

```php
$des = new TripleDES('3cbc');   // inner chaining (SSH-1 era)
$des = new TripleDES('cbc3');   // outer chaining (alias for 'cbc')
$des = new TripleDES('cbc');    // outer chaining
```

For new code, ignore 3CBC unless you're specifically targeting SSH-1. And ignore TripleDES entirely if you can — AES is faster and has a 128-bit block, which removes whole classes of attacks (sweet32) that affect DES's 64-bit block.

## Cipher attributes

```php
$cipher->getKeyLength();         // in bits
$cipher->getKeyLengthInBytes();  // in bytes
$cipher->getBlockLength();       // in bits (0 for stream ciphers)
$cipher->getBlockLengthInBytes();// in bytes (0 for stream ciphers)
$cipher->getMode();              // 'ctr', 'gcm', 'stream', etc.
$cipher->usesIV();               // bool
$cipher->usesNonce();            // bool
$cipher->continuousBufferEnabled(); // bool
```

The "in bits" methods return bit counts; the "InBytes" methods return byte counts. Choose by what you're doing — `random_bytes($cipher->getKeyLengthInBytes())` is shorter than `random_bytes($cipher->getKeyLength() >> 3)`.

## Engine selection

Each cipher picks the fastest available implementation at construction time. The candidates are:

| Constant | Name | Notes |
| --- | --- | --- |
| `ENGINE_OPENSSL` | `'OpenSSL'` | Used when PHP's `openssl_*` functions support the cipher+mode |
| `ENGINE_OPENSSL_AEAD` | `'OpenSSL (AEAD)'` | OpenSSL's GCM bindings |
| `ENGINE_LIBSODIUM` | `'libsodium'` | Used for ChaCha20-Poly1305 when sodium is available |
| `ENGINE_EVAL` | `'Eval'` | Pure-PHP, eval()-compiled inner loop. Faster than `ENGINE_INTERNAL`. |
| `ENGINE_INTERNAL` | `'PHP'` | Pure-PHP, last resort |

To override:

```php
$cipher->setPreferredEngine('OpenSSL');     // request OpenSSL
$cipher->setPreferredEngine('PHP');         // force pure-PHP
echo $cipher->getEngine();                   // see what's actually being used
$cipher->isValidEngine('OpenSSL');          // bool — would this work?
```

`setPreferredEngine()` is a hint, not a command — if you request OpenSSL and OpenSSL doesn't support the algorithm (e.g., Blowfish on OpenSSL 3.0.1+, which moved it to the legacy provider), phpseclib falls back to a working engine. Use `getEngine()` to see what actually got picked.

This matters mostly for performance debugging. The functional result is the same regardless of engine — the bytes encrypt and decrypt to the same values.

## Exceptions

All exceptions live under `phpseclib4\Exception\`. The ones you'll see most:

- `LengthException` — wrong-length key, IV, nonce, or tag.
- `InvalidArgumentException` — unrecognized mode string passed to constructor; missing required `setPassword()` param; running a block cipher in stream mode.
- `InvalidModeException` — GCM requested on a non-128-bit-block cipher.
- `InvalidStateException` — `encrypt()` / `decrypt()` called before `setKey()` / `setIV()` / `setNonce()`; `decrypt()` called on an AEAD cipher before `setTag()`.
- `BadMethodCallException` — `getTag()` / `setTag()` on a non-AEAD cipher; `setBlockLength()` on AES; `setIV()` on an ECB cipher; `enablePoly1305()` / `setPoly1305Key()` on a GCM cipher.
- `BadDecryptionException` — GCM or Poly1305 tag verification failed.
- `UnsupportedAlgorithmException` — `setPassword(..., $method)` with an unsupported `$method`.

All extend PHP's `\RuntimeException` and implement `phpseclib4\Exception\BaseException`. See the SKILL.md's exception-handling section for catch strategies.
