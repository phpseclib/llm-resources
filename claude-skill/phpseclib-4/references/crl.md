# `phpseclib4\File\CRL`

Full reference for Certificate Revocation Lists in phpseclib 4.0.

A CRL (Certificate Revocation List, RFC 5280 § 5) is a signed list of certificates that a CA has revoked before their natural expiration. Verifiers consult CRLs during chain validation to refuse certs the CA has marked as compromised, superseded, or otherwise invalid.

CRLs share most of their mechanics with `X509` and `CSR` — `Signable`, lazy decoding via `Constructed`, the same DN family, PEM/DER output toggles — so this file emphasizes what's *CRL-specific*: the revocation list itself, per-entry extensions, and the integration with `X509`'s validation callback.

## Contents

- [What a CRL is](#what-a-crl-is)
- [Loading](#loading)
- [Reading the revocation list](#reading-the-revocation-list)
- [CRL-level vs. entry-level extensions](#crl-level-vs-entry-level-extensions)
- [Creating](#creating)
- [Revoking and unrevoking](#revoking-and-unrevoking)
- [Validity dates](#validity-dates)
- [Signing](#signing)
- [Output format](#output-format)
- [Validation](#validation)
- [Integrating with X509 validation](#integrating-with-x509-validation)
- [ArrayAccess](#arrayaccess)

---

## What a CRL is

The ASN.1 structure (RFC 5280 § 5.1):

```asn1
CertificateList ::= SEQUENCE {
    tbsCertList         TBSCertList,
    signatureAlgorithm  AlgorithmIdentifier,
    signature           BIT STRING
}

TBSCertList ::= SEQUENCE {
    version              Version OPTIONAL,
    signature            AlgorithmIdentifier,
    issuer               Name,
    thisUpdate           Time,
    nextUpdate           Time OPTIONAL,
    revokedCertificates  SEQUENCE OF SEQUENCE {
        userCertificate      CertificateSerialNumber,
        revocationDate       Time,
        crlEntryExtensions   Extensions OPTIONAL
    } OPTIONAL,
    crlExtensions        [0] EXPLICIT Extensions OPTIONAL
}
```

Conceptually a CRL is "the CA's list of revoked serial numbers, valid for a particular window, signed by the CA." Each revoked entry has a serial number, a revocation date, and optional per-entry extensions (most commonly the reason for revocation).

Three consequences:

1. **CRLs have an issuer DN but no subject DN.** `getDN()` and `getIssuerDN()` are aliases — there's nothing to disambiguate. Neither throws.
2. **CRLs are signed by their issuer's private key**, which is typically the same CA key that issued the certs being revoked. `validateSignature()` checks the embedded signature against the issuer's public key (which you supply via the `addCA()` family on `X509`, not on `CRL` — see [Validation](#validation)).
3. **CRLs have two kinds of extensions.** CRL-level extensions (`crlExtensions`) apply to the whole list — things like the CRL number, the issuing distribution point, the authority key identifier. Entry-level extensions (`crlEntryExtensions`) apply to a single revoked cert — most importantly the revocation reason code. phpseclib's API provides parallel method families for each. See [CRL-level vs. entry-level extensions](#crl-level-vs-entry-level-extensions).

---

## Loading

```php
public static function load(string|array|Constructed $crl, int $mode = ASN1::FORMAT_AUTO_DETECT): CRL
```

```php
use phpseclib4\File\CRL;

$crl = CRL::load(file_get_contents('combined.crl'));
```

Accepts PEM or DER and auto-detects. Same `$mode` semantics as `X509::load()` / `CSR::load()`. Bad input throws `phpseclib4\Exception\UnexpectedValueException`.

The PEM header for a CRL is `-----BEGIN X509 CRL-----`.

CRLs are commonly fetched from CRL distribution points (URLs embedded in certificates via the `id-ce-cRLDistributionPoints` extension). For real-world fetching, see [Integrating with X509 validation](#integrating-with-x509-validation).

---

## Reading the revocation list

### Basic queries

```php
public function numRevoked(): int
public function isRevoked(BigInteger|X509 $sn): bool
```

```php
echo $crl->numRevoked();              // 11521 (e.g.)
echo $crl->isRevoked($x509) ? 'yes' : 'no';
echo $crl->isRevoked($bigIntSerial) ? 'yes' : 'no';
```

Both `isRevoked()` and the other lookup methods accept either a `BigInteger` (the serial number directly) or an `X509` (from which the serial is extracted). Use whichever you have.

### Looking up specific revocations

```php
public function getRevokedInfo(BigInteger|X509 $sn): ?array
public function getRevokedIndex(BigInteger|X509 $sn): ?int
public function getRevokedByIndex(int $idx): ?array
```

`getRevokedInfo()` returns the full revocation entry as an array, or `null` if the cert isn't on the list. The shape includes `userCertificate` (BigInteger), `revocationDate` (DateTimeInterface), and optionally `crlEntryExtensions` (an array of extension dicts).

`getRevokedIndex()` returns the position in the revocation list, or `null`. `getRevokedByIndex()` is the inverse — pass a position, get the entry.

### Bulk extraction

```php
public function getRevokedAsArray(): array
```

Returns the entire revocation list keyed by serial-number-as-hex:

```php
[
    '0a1b2c3d...' => [
        'revocationDate' => '2024-03-15 12:00:00 UTC',
        'reason' => 'keyCompromise',
    ],
    '4e5f6789...' => [
        'revocationDate' => '2024-04-02 09:31:21 UTC',
    ],
    // ...
]
```

This is the right shape for caching — hex serials are stable strings (unlike `BigInteger` instances) and lookups are O(1).

If the same serial appears multiple times in the CRL (rare but valid), the value becomes a list of entries instead of a single entry. Code consuming this shape should check whether the value is a list-shaped array.

The `reason` field is only present if the entry has an `id-ce-cRLReasons` entry-level extension. Other entry-level extensions (`invalidityDate`, `certificateIssuer`, etc.) aren't surfaced in this shape — use `getRevokedExtension()` if you need them.

**Performance note.** Prefer `getRevokedAsArray()` over `$crl->toArray()` (or `$crl['tbsCertList']['revokedCertificates']->toArray()`) when you just need the revocation list. `toArray()` recursively decodes the entire structure and keeps every entry materialized in memory; for a 2 MB CRL with hundreds of thousands of revocations that's a lot of memory. `getRevokedAsArray()` walks the same list but reads only the few fields it surfaces (serial, revocation date, reason code), and after each entry it drops the entry's decoded state — letting phpseclib's `Constructed` reclaim memory before moving on. The result is roughly the same total work but a much lower memory ceiling. It also skips entry-level extensions other than `id-ce-cRLReasons` entirely, so you pay zero decode cost for the extensions you didn't ask for. See [`references/asn1-constructed.md`](asn1-constructed.md) for the lazy-decoding mechanics that make this possible.

---

## CRL-level vs. entry-level extensions

A CRL has extensions at two levels. The method families parallel each other, with the entry-level forms taking an extra `$cert` parameter to identify which revocation entry.

### CRL-level (applies to the whole CRL)

```php
public function listExtensions(): array
public function getExtension(string $name): ?array
public function hasExtension(string $name): bool
public function setExtension(string $name, mixed $value, ?bool $critical = null): void
public function removeExtension(string $name): void
```

Same semantics as `X509::listExtensions()` etc. Common CRL-level extensions:

- `id-ce-cRLNumber` — sequence number for this CRL. Verifiers use it to detect rollback attacks.
- `id-ce-authorityKeyIdentifier` — identifies the CA key that signed the CRL (matches the `subjectKeyIdentifier` of the CA's cert).
- `id-ce-issuingDistributionPoint` — for partitioned CRLs, the scope of this particular CRL within the issuer's overall revocation set.
- `id-ce-deltaCRLIndicator` — marks this as a delta CRL referencing a base CRL by its CRL number.

### Entry-level (applies to one revoked cert)

```php
public function listRevokedExtensions(BigInteger|X509 $cert): array
public function getRevokedExtension(BigInteger|X509 $cert, string $name): ?array
public function hasRevokedExtension(BigInteger|X509 $cert, string $name): bool
public function setRevokedExtension(BigInteger|X509 $cert, string $name, mixed $value, ?bool $critical = null): void
public function removeRevokedExtension(BigInteger|X509 $cert, string $name): void
```

Same shape as the CRL-level family but with an extra `$cert` parameter identifying which revocation entry. Common entry-level extensions:

- `id-ce-cRLReasons` — the reason code for this revocation (`keyCompromise`, `superseded`, etc.). Set automatically by `revoke(..., $reason)`.
- `id-ce-invalidityDate` — when the revocation took effect, which may be earlier than the `revocationDate` (the date the CRL itself records).
- `id-ce-certificateIssuer` — for indirect CRLs, the actual issuer of the revoked cert if it differs from the CRL's issuer.

The `setRevokedExtension()` / `removeRevokedExtension()` methods silently no-op if the cert isn't in the revocation list — they don't add new revocations. To revoke a cert, use `revoke()` (see below).

---

## Creating

```php
public function __construct()
```

The constructor takes no arguments. A new `CRL` starts with the issuer DN empty, the `thisUpdate` set to now, and the revocation list empty.

```php
use phpseclib4\File\CRL;

$crl = new CRL();
$crl->setIssuerDN('/O=My CA');
$crl->revoke($cert1, 'keyCompromise');
$crl->revoke($cert2);
$crl->setNextDate('+30 days');
$caPriv->sign($crl);
echo $crl;
```

### Setting the issuer DN

Full DN method family — `setDN()`, `setIssuerDN()`, `addDNProp()`, `addIssuerDNProp()`, `getDN()`, `getIssuerDN()`, etc. All exist; bare and `Issuer*` variants are aliases. See [`references/distinguished-names.md`](distinguished-names.md).

The issuer DN should match the subject DN of the CA cert whose private key signs the CRL. If you're signing with `$pfx->sign($crl)`, the issuer DN is auto-copied from the PFX's CA cert; with a bare `PrivateKey`, you need to set it yourself.

### Setting the authority key identifier

```php
public function setAuthorityKeyIdentifier(string|OctetString $value): void
```

Shortcut for setting the `id-ce-authorityKeyIdentifier` extension. Used to identify which of the CA's keys signed this CRL. As with the issuer DN, this gets auto-set if you sign with `$pfx->sign($crl)`.

There's no `setSubjectKeyIdentifier()` on CRL — CRLs have no subject.

### Custom extensions

```php
public static function registerExtension(string $id, array $mapping): void
public static function getRegisteredExtension(string $id): ?array
```

Inherited from the same mechanism used by `X509` — registration is process-wide and shared across X509, CSR, and CRL. See [`references/asn1-constructed.md` → Custom OIDs](asn1-constructed.md#custom-oids).

---

## Revoking and unrevoking

```php
public function revoke(BigInteger|X509 $cert, ?string $reason = null, \DateTimeInterface|string|null $date = null): void
public function unrevoke(BigInteger|X509 $cert): bool
public static function listValidRevocationReasons(): array
```

```php
$crl->revoke($x509);                                     // no reason, date defaults to now
$crl->revoke($x509, 'keyCompromise');                    // with reason
$crl->revoke($x509, 'superseded', '2024-03-15 12:00');  // with reason and date
$crl->revoke($serialBigInteger);                         // by serial instead of cert
```

`revoke()` appends an entry to the revocation list. The `$cert` can be an `X509` (from which the serial is extracted) or a `BigInteger` (the serial directly). The `$date` defaults to "now" if not specified.

If `$reason` is provided, it must be one of the valid reasons from `listValidRevocationReasons()`. The reason gets attached to the entry as an `id-ce-cRLReasons` extension. Per RFC 5280 § 5.3.1, the valid reasons are:

- `unspecified` — no specific reason given
- `keyCompromise` — the cert's private key was exposed
- `cACompromise` — the CA's key was exposed (used in CRLs about CAs)
- `affiliationChanged` — the subject's affiliation changed (e.g., they left the organization)
- `superseded` — replaced by a newer cert
- `cessationOfOperation` — the cert is no longer in use
- `certificateHold` — temporary revocation (can be lifted with `removeFromCRL`)
- `removeFromCRL` — used in delta CRLs to indicate "this cert is no longer on hold"
- `privilegeWithdrawn` — the requested privileges associated with the cert are revoked
- `aACompromise` — used in CRLs related to attribute authorities

The reason names are case-insensitive — `revoke($cert, 'keyCompromise')` and `revoke($cert, 'KEYCOMPROMISE')` both work. Pass anything not in the list and `revoke()` throws `phpseclib4\Exception\UnexpectedValueException`.

Call `listValidRevocationReasons()` for the authoritative current list:

```php
print_r(CRL::listValidRevocationReasons());
```

`unrevoke()` removes the cert from the list, returning `true` if it was present (and removed) or `false` if it wasn't on the list to begin with.

The same cert can be revoked multiple times. `revoke()` doesn't check for duplicates — calling it twice for the same serial produces two entries. This is technically valid per the spec (and sometimes useful for indicating revocation status changes over time), but it's unusual. Most code should call `isRevoked()` first if it cares about deduplication.

---

## Validity dates

```php
public function setThisDate(\DateTimeInterface|string $date): void
public function setNextDate(\DateTimeInterface|string $date): void
public function setLastDate(\DateTimeInterface|string $date): void    // alias for setThisDate
```

- **`thisUpdate`** (`setThisDate()`) — when this CRL was issued. Defaults to "now" if not set; usually you'd set it explicitly when generating a CRL.
- **`nextUpdate`** (`setNextDate()`) — when the next CRL is expected to be issued. Verifiers treat a CRL as stale (or rejected outright) past this date. Optional but strongly recommended in real-world CRLs.

`setLastDate()` is provided as an alias for `setThisDate()` for naming-convention reasons; it doesn't represent a different field.

Both accept any string `\DateTimeImmutable` can parse (`'2024-12-31'`, `'+30 days'`, `'now'`) as well as `\DateTimeInterface` instances directly.

---

## Signing

```php
public function getSignableSection(): string
public function setSignature(string $signature): void
public function identifySignatureAlgorithm(PublicKey $key): void
public function copySigningX509Attributes(X509 $x509): void
```

CRLs implement `Signable`. The standard 4.0 signing idiom applies:

```php
$caPriv->sign($crl);
echo $crl;
```

Or with a PFX:

```php
$pfx->sign($crl);
echo $crl;
```

When you sign with a `$pfx`, `copySigningX509Attributes()` does the following:

- Copies the CA cert's subject DN as the CRL's issuer DN.
- Copies the CA cert's `subjectKeyIdentifier` (if present) as the CRL's `authorityKeyIdentifier`.

With a bare `PrivateKey`, neither of those auto-copies happens; you have to set both yourself.

The "sign last" rule applies — modifying the CRL after signing leaves the signature stale. Add all revocations and extensions first, then sign.

---

## Output format

```php
public function __toString(): string
public function toString(array $options = []): string
public function getEncoded(): string

public static function enableBinaryOutput(): void
public static function disableBinaryOutput(): void
```

Default is PEM with `-----BEGIN X509 CRL-----` headers. Toggle to binary DER per-call (`toString(['binary' => true])`) or process-wide (`CRL::enableBinaryOutput()` / `CRL::disableBinaryOutput()`).

---

## Validation

```php
public function validateSignature(): bool
```

Verifies that the CRL's signature is valid against its issuer's public key. The issuer's public key has to be findable — this means a CA cert matching the CRL's issuer DN (and `authorityKeyIdentifier` if present) must be in `X509`'s CA store:

```php
X509::addCA(file_get_contents('ca.pem'));

$crl = CRL::load(file_get_contents('ca.crl'));
echo $crl->validateSignature() ? 'valid' : 'invalid';
```

No `$caonly` parameter or other tunables — the check is "does the signature verify against some known issuer." If the CA isn't in the store, validation fails.

`validateSignature()` does *not* check that the CRL is current (within its `thisUpdate` / `nextUpdate` window). For freshness checking, read `$crl['tbsCertList']['thisUpdate']` and `$crl['tbsCertList']['nextUpdate']` yourself and compare to the current time.

It also does not check whether the CRL is authorized to revoke the cert you're verifying — for indirect CRLs and CRL distribution point validation, that's higher-level policy logic the caller handles.

---

## Integrating with X509 validation

When validating an `X509`, `validateSignature()` will check whether the cert has been revoked — but only if you've configured a callback that tells it how to look up CRLs:

```php
use phpseclib4\File\{CRL, X509};
use phpseclib4\Math\BigInteger;

X509::addCA(file_get_contents('isrg-root.pem'));

// Cache of fetched CRLs, keyed by URL
$cache = [];

X509::setCRLLookupCallback(function (string $url, BigInteger $serial) use (&$cache): bool {
    if (!isset($cache[$url])) {
        // Real code would also check thisUpdate/nextUpdate, validate the CRL's signature,
        // store the cache to disk or DB so it survives across requests, etc.
        $crl = CRL::load(file_get_contents($url));
        $cache[$url] = $crl->getRevokedAsArray();
    }
    return isset($cache[$url][$serial->toHex()]);
});

$x509 = X509::load(file_get_contents('cert.pem'));
echo $x509->validateSignature() ? 'valid' : 'revoked or invalid';
```

phpseclib doesn't fetch CRLs itself — `setCRLLookupCallback` plugs in your code to do that work. The callback receives a CRL distribution point URL (extracted from the cert being validated) and the cert's serial number; it returns `true` if the serial is on the corresponding CRL, `false` otherwise. See [`references/x509.md` → CRL revocation](x509.md#crl-revocation) for the full callback story.

Real-world callbacks need to:

- Cache CRLs across requests (real CRLs are often hundreds of KB to multiple MB).
- Check `thisUpdate` and `nextUpdate` to decide whether to refresh the cache.
- Validate the CRL's own signature (using `addCA()` + `$crl->validateSignature()`).
- Handle fetch failures (network errors, malformed responses) — usually conservatively (return `true` to deny, or throw, depending on your security posture).

phpseclib 4.0 does not currently support OCSP. CRL is the only revocation-check mechanism, which increasingly matches where the ecosystem is going: in August 2023 the CA/Browser Forum voted to make OCSP optional and CRL mandatory, and in August 2025 Let's Encrypt — the largest CA in the world — shut down its OCSP responders entirely and now publishes revocation information exclusively via CRLs. The motivations are privacy (OCSP reveals to the CA which sites a visitor is accessing, in real time) and operational simplicity. So while phpseclib not supporting OCSP is a real limitation today, it's a less significant gap than it would have been a few years ago.

---

## ArrayAccess

CRL implements `ArrayAccess`, `Countable`, and `Iterator` with the same lazy-decoded structure access as X509 and CSR:

```php
$tbs        = $crl['tbsCertList'];
$issuer     = $crl['tbsCertList']['issuer'];
$thisUpdate = $crl['tbsCertList']['thisUpdate'];        // DateTimeInterface
$nextUpdate = $crl['tbsCertList']['nextUpdate'] ?? null;
$revoked    = $crl['tbsCertList']['revokedCertificates'];  // Constructed (iterable)
$sig        = $crl['signature'];                         // BitString
```

You can iterate the revocation list directly:

```php
foreach ($crl['tbsCertList']['revokedCertificates'] as $entry) {
    echo $entry['userCertificate']->toHex(), ' revoked at ', $entry['revocationDate'], "\n";
}
```

This is what `getRevokedAsArray()` does under the hood. For most code, the helper is shorter and produces a more convenient shape; for unusual queries (filter by extension, sort by date, etc.) walking the array directly may be cleaner.

See [`references/asn1-constructed.md`](asn1-constructed.md) for the lazy-decoding mechanics.
