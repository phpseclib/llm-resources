# `phpseclib4\File\SPKAC`

Full reference for Signed Public Key And Challenge (SPKAC) in phpseclib 4.0.

SPKAC is a minimal alternative to CSR: a public key, an optional challenge string, and a signature proving control of the corresponding private key. There's no subject DN, no extensions, no attributes. It was historically used by browsers via the HTML `<keygen>` element to submit key generation requests to CAs, and you can still produce SPKACs from `openssl spkac -key private.key`.

`<keygen>` is deprecated and removed from modern browsers, so new use of SPKAC is rare — most certificate-request flows now use CSR. But you may encounter SPKAC files from old enrollment systems, internal CAs that still rely on the format, or `openssl spkac` output. The class is small because the format is small.

## Contents

- [What an SPKAC is](#what-an-spkac-is)
- [Loading](#loading)
- [Reading](#reading)
- [Creating](#creating)
- [Signing](#signing)
- [Validation](#validation)
- [Output format](#output-format)
- [ArrayAccess](#arrayaccess)

---

## What an SPKAC is

The ASN.1 structure:

```asn1
SignedPublicKeyAndChallenge ::= SEQUENCE {
    publicKeyAndChallenge  PublicKeyAndChallenge,
    signatureAlgorithm     AlgorithmIdentifier,
    signature              BIT STRING
}

PublicKeyAndChallenge ::= SEQUENCE {
    spki       SubjectPublicKeyInfo,
    challenge  IA5String
}
```

That's the entire format. Conceptually it's "I want this public key bound to this challenge string; here's a signature proving I have the corresponding private key." A receiving CA verifies the signature and, if it chooses to issue, uses the public key in the issued cert. The challenge is whatever the CA hands the requester at the start of the flow — it lets the CA confirm that the request is in response to that specific invitation.

Three consequences:

1. **No DN, no extensions, no attributes.** You can't request a subject in an SPKAC; the CA assigns it.
2. **Self-signed by definition.** Like CSR, `validateSignature()` checks the embedded key against the signature; there's no chain.
3. **No `<keygen>` in modern browsers.** SPKAC is legacy. New flows should use CSR.

The format predates ASN.1's general adoption of PEM and uses its own line-encoding when produced by `openssl spkac`:

```
SPKAC=MIIBO...
```

It's a single line, prefixed with `SPKAC=`, with the rest being base64-encoded DER. `SPKAC::load()` handles this format, plain base64, and raw DER.

---

## Loading

```php
public static function load(string|array|Constructed $spkac, int $mode = ASN1::FORMAT_AUTO_DETECT): SPKAC
```

```php
use phpseclib4\File\SPKAC;

$spkac = SPKAC::load(file_get_contents('request.spkac'));
```

Accepts the `SPKAC=...` line format produced by OpenSSL, plain base64, or DER bytes. Auto-detect handles all three. Bad input throws `phpseclib4\Exception\UnexpectedValueException`.

---

## Reading

```php
public function getPublicKey(): PublicKey
public function hasPublicKey(): bool
public function getChallenge(): string
```

```php
$key = $spkac->getPublicKey();          // PublicKey (RSA, EC, or DSA)
$challenge = $spkac->getChallenge();    // string (possibly empty)
```

`getPublicKey()` returns a `phpseclib4\Crypt\Common\PublicKey` — `RSA\PublicKey`, `EC\PublicKey`, or `DSA\PublicKey` — same semantics as on X509 and CSR. Throws `phpseclib4\Exception\UnexpectedValueException` if the SPKI didn't decode into a key phpseclib recognizes. Guard with `hasPublicKey()` for untrusted input.

When the loaded key was RSA in PKCS#8 format, `getPublicKey()` returns it with `SIGNATURE_PKCS1` padding pre-applied — matching the signing convention SPKAC uses. You typically don't have to think about this; it's already configured correctly for the validation path.

`getChallenge()` returns the challenge string. If no challenge was set (the default), this returns an empty string. The challenge is an IA5String — printable ASCII plus controls.

---

## Creating

```php
public function __construct(?PublicKey $public = null)
```

```php
use phpseclib4\Crypt\RSA;
use phpseclib4\File\SPKAC;

$priv = RSA::createKey(2048);

$spkac = new SPKAC($priv->getPublicKey());
$spkac->setChallenge('challenge-from-the-CA');

$priv->sign($spkac);
echo $spkac;
```

The constructor accepts an optional `PublicKey` to populate `spki` immediately, or `null` to start empty.

```php
public function setPublicKey(PublicKey $publicKey): void
public function removePublicKey(): void
public function setChallenge(string $challenge): void
```

`setPublicKey()` installs or replaces the SPKI slot. `removePublicKey()` clears it (rarely useful outside tests).

`setChallenge()` sets the challenge string. Per Mozilla's `<keygen>` documentation, empty challenges are valid — both Firefox (when `<keygen>` was supported) and `openssl spkac -key private.key` defaulted to empty. If a CA gives you a challenge, you'd echo that exact value back; if you're producing an SPKAC for testing purposes, empty is fine.

A subtle detail: `setChallenge()` ANDs the input with `0x7F` per byte, which clamps each byte into the 7-bit range. This is because the challenge field is an `IA5String` (7-bit ASCII), so high-bit characters can't legally appear. The clamping is silent; if you set a UTF-8 challenge with multi-byte characters, you'll get a clamped 7-bit version, not an error. For interoperability, pass plain ASCII.

---

## Signing

```php
public function getSignableSection(): string
public function setSignature(string $signature): void
public function identifySignatureAlgorithm(PublicKey $key): void
public function copySigningX509Attributes(X509 $x509): void
```

SPKAC implements `Signable`, so the standard 4.0 signing idiom applies — the private key whose public side is in the SPKAC signs the SPKAC:

```php
$priv->sign($spkac);
echo $spkac;
```

Implementation notes for the four `Signable` methods:

- `getSignableSection()` returns the encoded `publicKeyAndChallenge` bytes.
- `setSignature()` writes into the SPKAC's `signature` BIT STRING.
- `identifySignatureAlgorithm()` installs the algorithm OID — same `ASN1Signature` trait logic as X509 and CSR.
- `copySigningX509Attributes()` is a no-op on SPKAC (there's no issuer DN, no AKI — nothing to copy).

`$pfx->sign($spkac)` works syntactically but is unusual — a PFX is for a CA's signing identity, but the requester (not the CA) signs an SPKAC. You'd typically use a bare `PrivateKey`.

---

## Validation

```php
public function validateSignature(): bool
```

Verifies that the SPKAC's embedded public key signed the `publicKeyAndChallenge` correctly. There's no CA store, no chain, no date checking — same scope as `CSR::validateSignature()`. A `true` return means the SPKAC is internally consistent; whether you should *trust* the SPKAC enough to issue a cert is a policy question handled outside this method.

---

## Output format

```php
public function __toString(): string
public function toString(array $options = []): string

public static function enableBinaryOutput(): void
public static function disableBinaryOutput(): void
```

Default is the OpenSSL `SPKAC=` line format:

```
SPKAC=MIIBOzCCASUwgcwwDQYJKoZIhvcNAQEBBQADgboAMIG2AoGuAJW...
```

Toggle to binary DER per-call (`toString(['binary' => true])`) or process-wide (`SPKAC::enableBinaryOutput()` / `SPKAC::disableBinaryOutput()`).

There's no `-----BEGIN ... -----` PEM block for SPKAC — the format predates that convention.

---

## ArrayAccess

SPKAC implements `ArrayAccess`, `Countable`, and `Iterator`. The structure follows the ASN.1 schema:

```php
$pkac      = $spkac['publicKeyAndChallenge'];
$key       = $spkac['publicKeyAndChallenge']['spki'];        // PublicKey or Constructed
$challenge = $spkac['publicKeyAndChallenge']['challenge'];   // IA5String
$sig       = $spkac['signature'];                             // BitString
```

For most SPKAC code the helpers (`getPublicKey`, `getChallenge`, `validateSignature`) are simpler and more readable than the ArrayAccess path. Drop to ArrayAccess only when you need access the helpers don't expose — which on SPKAC is rare, since the format is so small.

See [`references/asn1-constructed.md`](asn1-constructed.md) for the underlying lazy-decoding mechanics and the autovivification considerations for optional fields.
