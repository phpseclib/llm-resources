# Migrating phpseclib 3.0 to 4.0

The full mapping table. SKILL.md covers the most common idioms in prose; this file is for lookup — when you see a 3.0 method or pattern and need to know what it maps to in 4.0.

> **A 3.0 → 4.0 migration is not a rename pass.** As with the 2.0 → 3.0 transition before it, there is no one-to-one translation for many APIs — particularly *object* signing (X.509, CSR, CRL), where the entire paradigm changed. (Raw byte signing — `$key->sign($string)` — is unchanged.) Migrating a phpseclib 3.0 codebase to 4.0 is closer to "switching from Symfony to Laravel" than to "updating to a point release." Plan accordingly: budget time for genuine refactoring, not just find-and-replace.

> Entries below have been verified against the phpseclib 3.0 and 4.0 source (as of the date this file was last updated). If you find a mapping that doesn't match current behavior, please [report it](https://github.com/phpseclib/llm-resources/issues) — the canonical reference is always the official [phpseclib 4.0 docs](https://phpseclib.com) and the source.

## Before you migrate: consider the compat shim

For many 3.0 codebases, the right answer is **don't migrate at all** — install [`phpseclib/phpseclib3_compat`](https://github.com/phpseclib/phpseclib3_compat) instead. The compat package emulates the entire `phpseclib3\` API on top of phpseclib 4.0. Existing 3.0 code continues to work unchanged, and the package "provides" `phpseclib/phpseclib:~3.0` in Composer terms, which means it satisfies any other dependency that requires phpseclib 3.0.

This is the same approach `phpseclib/phpseclib2_compat` takes for 2.0 → 3.0, and the migration story for both versions is shaped accordingly.

**The compat shim is the right answer when:**

- Your project uses a third-party package that pins to phpseclib 3.0 (Google's PHP API client, for example, currently does). Without the compat shim, `composer require phpseclib/phpseclib:~4.0` will conflict with that dependency. With the compat shim, both can coexist.
- Your codebase makes heavy use of the X.509 API and the cost of rewriting it is large. The X.509 redesign is the most extensive change in 4.0; a project with hundreds of `loadX509()` / `getDN()` / `signCSR()` call sites may genuinely take longer to rewrite than the rest of the migration combined.
- You want to upgrade the underlying phpseclib (for security fixes, new ciphers, performance, OpenSSL acceleration, the new PFX and CMS classes) without touching your existing code.

**A full migration to 4.0 is the right answer when:**

- You're starting a new project (no 3.0 code to preserve).
- You want to use the new 4.0 features (PFX, CMS, the `Signable` interface, modern type hints) directly in your own code. You can mix compat-shimmed 3.0 code with native 4.0 code in the same codebase — the shim doesn't preclude this — but if 4.0 is going to be the bulk of your code, the shim adds an indirection layer that buys you nothing.
- Your project is itself a library that exposes phpseclib types in its public API, in which case you want your callers to see real 4.0 types.

**You can also do both.** Install the compat shim for legacy code paths, and write new code against the 4.0 API directly. The two namespaces don't conflict — `phpseclib3\File\X509` (compat-shimmed) and `phpseclib4\File\X509` (native) are different classes and can be used in the same file.

**When using the shim, this migration guide doesn't apply** — the whole point of the shim is that you don't need to migrate. Read the rest of this document only if you've decided to rewrite against the native 4.0 API.

## Some context worth knowing

A few pieces of context that make the migration story make more sense:

**There is no formal phpseclib 2.0 → 3.0 migration guide either.** The 2.0 → 3.0 transition was complicated for many of the same reasons — public key loading in particular got a thorough redesign — and the existence of `phpseclib/phpseclib2_compat` made a written guide unnecessary for most users. This file is the first formal migration guide phpseclib has ever shipped, and it exists primarily because the 4.0 release is large enough to warrant one *and* because LLMs need a structured reference to write good migration code. For human users with substantial 3.0 codebases, the compat shim remains the recommended path.

**The namespace change is what makes the shim possible.** A common reaction to seeing `phpseclib4\` is "why didn't they just keep the namespace stable?" The answer is that *the namespace change is the feature*. If 4.0 lived at `phpseclib3\` (or some other shared namespace), there would be no way to install both versions side by side, no way to write a compat shim, and no way for a user with 4.0 in their app to coexist with a dependency that requires 3.0. The same was true for the 2.0 → 3.0 transition. The namespace numbering looks like a versioning quirk; it's actually deliberate ABI-isolation infrastructure, and the shim packages exist *because* of it.

**4.0 embraces PHP 8.1, not just permits it.** The minimum PHP version went from 5.6 (in 3.0) to 8.1 (in 4.0). That's not just a `composer.json` bump — the 4.0 source uses first-class callable syntax, `match` expressions, typed properties, named arguments, and `string|Signable` union types throughout. None of these are *necessary* to do what phpseclib does (everything could be expressed in PHP 5.6 syntax with more boilerplate), but if 8.1 is the minimum it would be wasteful not to use what 8.1 offers. This matters for migration philosophy: if you choose to rewrite rather than shim, you're not just getting "the same code in a new namespace." You're getting code that's idiomatic for modern PHP — and the rewrite is partly an opportunity to bring the code that *uses* phpseclib up to the same standard. People who choose the rewrite path are usually doing so for this reason as much as for the new features.

**This is why the "Symfony to Laravel" framing is right.** A version bump that just renumbered things would not warrant the work of a full rewrite. The work is warranted because 4.0 represents a deliberate generational shift — in PHP version, in idioms, in API design — and the codebase you end up with after a rewrite looks meaningfully different from the codebase you started with. The shim option exists for users who want the underlying improvements without the rewrite cost; the rewrite option exists for users who specifically want to modernize.

## How to use this file

If you're migrating a codebase, do this in order:

1. **Read the SKILL.md first.** It covers the patterns that account for ~90% of typical 3.0 code.
2. **Run `scripts/detect-version.php`** against the codebase to get a per-file inventory of 3.0-isms. The script catches the patterns that the SKILL.md flags.
3. **Use this file as a lookup.** For any 3.0 method or class the script reports that isn't covered in the SKILL.md, search this file by 3.0 name to find the 4.0 equivalent.
4. **Verify against the official docs** before committing the migration. This file is a fast-path; the docs are authoritative.

## Table of contents

- [Before you migrate: consider the compat shim](#before-you-migrate-consider-the-compat-shim)
- [Some context worth knowing](#some-context-worth-knowing)
- [Namespace and autoloading](#namespace-and-autoloading)
- [Loading and saving — X509, CSR, CRL, SPKAC](#loading-and-saving)
- [Constructing from scratch](#constructing-from-scratch)
- [Distinguished Names (DN)](#distinguished-names-dn)
- [Signing certificates](#signing-certificates)
- [Public key access](#public-key-access)
- [Extensions](#extensions)
- [Validation](#validation)
- [Output format (PEM vs DER)](#output-format-pem-vs-der)
- [Keys (RSA, EC, DSA)](#keys-rsa-ec-dsa)
- [Random bytes](#random-bytes)
- [Engine selection](#engine-selection)
- [ASN.1 (low-level)](#asn1-low-level)
- [SSH2 and SFTP](#ssh2-and-sftp)
- [Exceptions and error handling](#exceptions-and-error-handling)
- [PFX and CMS — no migration](#pfx-and-cms--no-migration)
- [Things that did not change](#things-that-did-not-change)

---

## Namespace and autoloading

Every class moved from `phpseclib3\` to `phpseclib4\`. The 4.0 release does not coexist with 3.0 in the same `composer require` — 4.0 is its own package version, not a parallel namespace, so any single project is on one or the other.

The namespace mapping is mechanical, but **the API at the new namespace is often substantially different**. In particular, `phpseclib4\File\X509` is much smaller than `phpseclib3\File\X509` was — CSR, CRL, and SPKAC functionality moved out into their own classes (see [Loading and saving](#loading-and-saving)). Don't assume that a class existing at the same path means its methods carried over unchanged.

| 3.0 | 4.0 | Notes |
| --- | --- | --- |
| `phpseclib3\Crypt\RSA` | `phpseclib4\Crypt\RSA` | High-level API stable |
| `phpseclib3\Crypt\EC` | `phpseclib4\Crypt\EC` | High-level API stable |
| `phpseclib3\Crypt\DSA` | `phpseclib4\Crypt\DSA` | High-level API stable |
| `phpseclib3\Crypt\Common\PrivateKey` | `phpseclib4\Crypt\Common\PrivateKey` | `sign()` accepts `string\|Signable` (was `string`-only) |
| `phpseclib3\Crypt\Common\PublicKey` | `phpseclib4\Crypt\Common\PublicKey` | High-level API stable |
| `phpseclib3\Crypt\Random` | **removed** | Use `random_bytes()` |
| `phpseclib3\File\X509` | `phpseclib4\File\X509` | **Substantially smaller** — CSR/CRL/SPKAC moved out |
| (no equivalent) | `phpseclib4\File\CSR` | New top-level class |
| (no equivalent) | `phpseclib4\File\CRL` | New top-level class |
| (no equivalent) | `phpseclib4\File\SPKAC` | New top-level class |
| (no equivalent) | `phpseclib4\File\PFX` | Brand new in 4.0 |
| (no equivalent) | `phpseclib4\File\CMS` | Brand new in 4.0 |
| `phpseclib3\File\ASN1` | `phpseclib4\File\ASN1` | Includes the `DN_*` constants now |
| `phpseclib3\Math\BigInteger` | `phpseclib4\Math\BigInteger` | Stable |
| `phpseclib3\Net\SSH2` | `phpseclib4\Net\SSH2` | Error-reporting methods removed; throws now |
| `phpseclib3\Net\SFTP` | `phpseclib4\Net\SFTP` | `chmod` arg order changed; error methods reworked |
| `phpseclib3\System\SSH\Agent` | `phpseclib4\System\SSH\Agent` | `Identity` now implements `PrivateKey` |

For pre-3.0 code (1.0 or 2.0 — `Crypt_RSA`, `File_X509`, `Net_SSH2`, or single-segment `phpseclib\Crypt\RSA`), there is no formal migration guide for those transitions either; the same paradigm-shift caveat applies. Get the code onto a current 3.0 release first using whatever combination of the [3.0 docs](https://phpseclib.com) and source diffs is necessary, then apply this guide to move from there to 4.0.

A bulk find-and-replace of `phpseclib3\` → `phpseclib4\` will get the namespace right but does **not** finish the migration — most of the API changes below need real edits, not text substitution.

---

## Loading and saving

The single biggest restructuring in 4.0. CSR, CRL, and SPKAC all moved out of `X509` into their own classes. PFX and CMS are also their own classes but are net-new in 4.0 — see [PFX and CMS — no migration](#pfx-and-cms--no-migration).

### Loading

| 3.0 | 4.0 |
| --- | --- |
| `(new X509())->loadX509($pem)` | `X509::load($pem)` |
| `(new X509())->loadCSR($pem)` | `CSR::load($pem)` |
| `(new X509())->loadCRL($pem)` | `CRL::load($pem)` |
| `(new X509())->loadSPKAC($pem)` | `SPKAC::load($pem)` |
| `$x509->loadCA($pem)` | `X509::addCA($pem)` (now static, and renamed) |

Each `::load()` factory accepts PEM or DER bytes — it auto-detects the format. There is no separate `loadDER()`-vs-`loadPEM()` choice in 4.0.

### Saving / serialization

| 3.0 | 4.0 |
| --- | --- |
| `$x509->saveX509($cert)` | `echo $x509;` (or `(string)$x509`, or `$x509->getEncoded()`) |
| `$x509->saveCSR($csr)` | `echo $csr;` |
| `$x509->saveCRL($crl)` | `echo $crl;` |
| `$x509->saveSPKAC($spkac)` | `echo $spkac;` |

The `save*` family is gone in 4.0 — no aliases survive. Every file class implements `__toString()` and casts to PEM by default; `getEncoded()` is available as an explicit method when you need a non-magic-method call. The 3.0 `saveX509()` took an array (the parsed cert structure) as its first argument; 4.0 has nothing equivalent because the object *is* the parsed structure.

For binary (DER) output: see [Output format](#output-format-pem-vs-der).

---

## Constructing from scratch

Where 3.0 relied on a sequence of setters after a no-arg constructor, 4.0 lets you pass key material directly to the constructor and uses explicit subject/issuer DN setters from the start.

```php
// 3.0 — typical pattern for self-signed cert
$x509 = new X509();
$x509->setPublicKey($pubKey);
$x509->setDN('/O=demo');
$x509->setStartDate('-1 day');
$x509->setEndDate('+1 year');

// 4.0
$x509 = new X509($pubKey);
$x509->setSubjectDN('/O=demo');
$x509->setIssuerDN('/O=demo');
$x509->setStartDate('-1 day');
$x509->setEndDate('+1 year');
```

The constructor accepting a public key is new — `new X509()` with no args still works for the case where you'll set the key later.

---

## Distinguished Names (DN)

The biggest gotcha during a careful migration. 3.0 had a single-DN model for most operations; 4.0 splits subject and issuer everywhere.

| 3.0 | 4.0 (always-safe) | 4.0 (bare — throws if subject ≠ issuer) |
| --- | --- | --- |
| `$x509->getDN()` | `$x509->getSubjectDN()` / `getIssuerDN()` | `$x509->getDN()` |
| `$x509->setDN($dn)` | `$x509->setSubjectDN($dn)` / `setIssuerDN($dn)` | `$x509->setDN($dn)` |
| `$x509->addDNProp($prop, $val)` | `$x509->addSubjectDNProp(...)` / `addIssuerDNProp(...)` | `$x509->addDNProp(...)` |
| `$x509->removeDNProp($prop)` | `$x509->removeSubjectDNProp(...)` / `removeIssuerDNProp(...)` | `$x509->removeDNProp(...)` |
| `$x509->getDNProp($prop)` | `$x509->getSubjectDNProp(...)` / `getIssuerDNProp(...)` | `$x509->getDNProp(...)` |

**Migration rule:** for any code that handles non-self-signed certs, mechanically replace bare `getDN`/`setDN`/`addDNProp`/`removeDNProp`/`getDNProp` with the explicit `Subject*` or `Issuer*` variant. The bare versions still exist but throw on CA-signed certs in 4.0.

### DN format constants

The constants moved from `X509` to `ASN1`:

| 3.0 | 4.0 |
| --- | --- |
| `X509::DN_STRING` | `ASN1::DN_STRING` |
| `X509::DN_ARRAY` | `ASN1::DN_ARRAY` |
| `X509::DN_OPENSSL` | `ASN1::DN_OPENSSL` |
| `X509::DN_ASN1` | `ASN1::DN_ASN1` |
| `X509::DN_CANON` | `ASN1::DN_CANON` |

### DN_STRING output format changed

3.0's `DN_STRING` produced the phpseclib-native format: `C=À, O=B/serialNumber=C`. 4.0 produces the OpenSSL 3.0 CLI format: `C = \C3\80, O = B, serialNumber = C`.

The 4.0 format eliminates ambiguities the 3.0 format had — `/` was both a separator and a legal value character in 3.0, so `O=Acme/Inc.` couldn't be distinguished from two attributes. The 4.0 format uses `, ` as the separator with escaping for embedded commas.

| Source | Output for the same DN |
| --- | --- |
| phpseclib 4.0 (`DN_STRING`) | `C = \C3\80, O = B, serialNumber = C` |
| OpenSSL 3.0 CLI | `C = \C3\80, O = B, serialNumber = C` |
| PHP `openssl_x509_parse()` on OpenSSL 3.0 | `/C=\xC3\x80/O=B/serialNumber=C` |
| phpseclib 1.0 – 3.0 (`DN_STRING`) | `C=À, O=B/serialNumber=C` |

phpseclib 4.0's `setDN()` can parse the OpenSSL 3.0 CLI format and PHP's `openssl_x509_parse()` format; only the OpenSSL 3.0 CLI form comes out of `getDN()`.

**The migration trap:** code that string-matches `getDN()` output silently produces wrong results.

```php
// 3.0: getDN() returned "C=US, O=Acme/CN=example.com"
if (strpos($x509->getDN(), '/CN=example.com') !== false) { /* ... */ }

// 4.0: getDN() returns "C = US, O = Acme, CN = example.com"
// The '/CN=' substring never appears now. The check silently always returns false.
```

Don't string-match `getDN()` output. Use `getSubjectDNProps('CN')` (which returns an array of CN values) or `getSubjectDN(ASN1::DN_OPENSSL)` (which returns an associative array) instead. These are stable across the format change and across phpseclib versions.

### CSR and CRL DN methods

CSR has only a subject DN. CRL has only an issuer DN. In those classes, the bare `getDN()` / `setDN()` and the explicit variant are aliases — neither throws.

| 3.0 (on X509, doing CSR work) | 4.0 (on CSR) |
| --- | --- |
| `(new X509())->loadCSR($csr)->getDN()` | `CSR::load($csr)->getSubjectDN()` (or `->getDN()`) |
| `(new X509())->loadCRL($crl)->getDN()` | `CRL::load($crl)->getIssuerDN()` (or `->getDN()`) |

---

## Signing certificates

Already covered in detail in the SKILL.md. The condensed table:

| 3.0 | 4.0 |
| --- | --- |
| `$issuer->setPrivateKey($priv); (new X509())->sign($issuer, $subject)` | `$priv->sign($x509)` |
| `$x509->setPrivateKey($priv); $x509->setPublicKey($pub); $x509->signCSR()` | `$priv->sign($csr)` |
| `$issuer->setPrivateKey($priv); $issuer->signCRL($issuer, $crl)` | `$priv->sign($crl)` |
| Sign with PFX → not supported | `$pfx->sign($x509)` |

3.0 had three differently-shaped signing methods on `X509`: `sign(X509 $issuer, X509 $subject)` for certs, `signCSR()` for CSRs (no issuer arg, since CSRs are self-signed — the public and private key both go on the same `X509`), and `signCRL(X509 $issuer, X509 $crl)` for CRLs. 4.0 collapses all three into a single uniform pattern: `$priv->sign($signableObject)` works for any object implementing the `phpseclib4\File\Common\Signable` interface — currently `X509`, `CSR`, `CRL`, `CMS\SignedData`, and `CMS\SignedData\Signer`.

This is one of the places where the "Symfony to Laravel" framing matters most. The 4.0 form is not a renaming of 3.0's `signCSR` / `signCRL` / `sign` — it's a different paradigm. There is no method-by-method rewrite; you reorganize the signing logic around a key (or PFX) that does the work.

PFX delegates to the private key it contains. An SSH agent identity (`phpseclib4\System\SSH\Agent\Identity` implements `PrivateKey`) can also sign — useful for hardware-backed signing, though DNs and authority key identifiers won't auto-populate the way they do via PFX.

`PrivateKey::sign(string|Signable $message): string` always returns the raw signature bytes. When `$message` is a `Signable`, the signature is *also* installed back into the object as a side effect. The full PEM cert is `$priv->sign($x509); echo $x509;` — not `echo $priv->sign($x509);` (which prints raw signature bytes).

Raw byte signing — `$priv->sign($bytes)` — is unchanged from 3.0 and continues to work in 4.0. The `string|Signable` union is purely additive.

### Signature algorithm and hash selection

| 3.0 | 4.0 |
| --- | --- |
| `$x509->setHash('sha256')` | call `withHash('sha256')` on the **key**, before signing: `$priv = $priv->withHash('sha256')` |
| `$x509->setSignatureAlgorithm(...)` | algorithm is implied by the key type; for RSA, use `withPadding()` on the key |

Most key configuration moved to the key object in 4.0 — that's the "wither" pattern returning a new key with the option set.

---

## Public key access

| 3.0 | 4.0 |
| --- | --- |
| `$x509->setPublicKey($key)` | `$x509->setPublicKey($key)` (unchanged), or `new X509($key)` |
| `$x509->getPublicKey()` | `$x509->getPublicKey()` — but **throws `phpseclib4\Exception\UnexpectedValueException`** on key formats it can't parse |
| `$x509->getPublicKey() === false` | `try { $x509->getPublicKey(); } catch (\phpseclib4\Exception\UnexpectedValueException $e) { ... }` or guard with `if ($x509->hasPublicKey())` |

The 3.0 silent-fallback behavior on unsupported key formats is gone. For untrusted input, always guard or catch.

For raw access to the SPKI bytes when the helper can't parse them:

```php
$spki = $x509['tbsCertificate']['subjectPublicKeyInfo'];
```

---

## Extensions

The biggest return-shape change in 4.0. `getExtension()` returned the bare value in 3.0; in 4.0 it returns an array with metadata.

| 3.0 | 4.0 |
| --- | --- |
| `$x509->getExtension($name)` | `$x509->getExtension($name)['extnValue']` |
| `$ext === false` (extension missing) | `$ext === null` |
| `$x509->setExtension($name, $value)` | `$x509->setExtension($name, $value)` (unchanged signature) |
| `$x509->setExtension($name, $value, $critical)` | `$x509->setExtension($name, $value, $critical)` (unchanged) |
| `$x509->removeExtension($name)` | `$x509->removeExtension($name)` (unchanged) |
| `$x509->getExtensions()` (list all) | `$x509->listExtensions()` returns names; pass each to `getExtension()` to fetch the value |
| (no equivalent) | `$x509->hasExtension($name)` — boolean check |

`getExtension()` returns the *first* instance of an extension if there are multiple, or `null` if absent. The full signature: `public function getExtension(string $name): ?array`.

The 4.0 `getExtension()` return shape is `['extnId' => string, 'extnValue' => BaseType, 'critical' => bool]`. The `extnValue` is a typed `phpseclib4\File\ASN1\Types\BaseType` instance, not a primitive — call `->getValue()` or cast appropriately depending on the extension.

### Per-revoked-cert extensions on CRLs

| 3.0 | 4.0 |
| --- | --- |
| `$x509->getRevokedCertificateExtension($serial, $id, $crl = null)` | `$crl->getRevokedExtension($serial, $name)` |
| `$x509->setRevokedCertificateExtension($serial, $id, $value, $critical = false, $replace = true)` | `$crl->setRevokedExtension(...)` |
| (no equivalent) | `$crl->hasRevokedExtension($serial, $name)` |

The 3.0 methods lived on `X509` and took an optional `$crl` parameter (defaulting to the currently-loaded CRL); 4.0 puts them on the `CRL` class itself, which is naturally tied to a specific CRL instance.

### Specialized extension helpers

| 3.0 | 4.0 |
| --- | --- |
| `$x509->setDomain('example.com')` | `$x509->addDomains('example.com')` (variadic) |
| `$x509->setIPAddress('1.2.3.4')` | `$x509->addIPAddresses('1.2.3.4')` (variadic) |
| `$x509->makeCA()` | `$x509->makeCA()` (unchanged) |
| `$x509->setKeyIdentifier($value)` | `$x509->setSubjectKeyIdentifier($keyId)` / `createSubjectKeyIdentifier($method = 1)` |
| (set on issuer; copied during sign — see below) | `$x509->setAuthorityKeyIdentifier($keyId)` |

3.0 had a single `setKeyIdentifier()` method that set "the" key identifier and let the signing flow figure out where it landed (subject side on the issuer cert, authority side on the signed cert). 4.0 names them explicitly: `setSubjectKeyIdentifier()` and `setAuthorityKeyIdentifier()`.

**Authority key identifier — semantic shift.** In 3.0, the issuer's subject key identifier was automatically copied to the signed cert's authority key identifier extension when `sign($issuer, $subject)` ran. In 4.0, that auto-copy happens specifically inside `$pfx->sign($x509)` — the PFX knows the CA cert and copies its subject key identifier into the signed cert's authority key identifier. A `$privKey->sign($x509)` call does *not* do this auto-copy, because the key has no notion of an issuer cert (and an SSH agent identity certainly doesn't). If you want the AKI extension and you're signing with a bare `PrivateKey`, set it explicitly with `setAuthorityKeyIdentifier()` before signing, or do the `setExtension()` dance / array-access manipulation yourself.

### Custom extension registration

| 3.0 | 4.0 |
| --- | --- |
| `ASN1::loadOIDs([...])` | `ASN1::loadOIDs([...])` (unchanged) |
| `X509::registerExtension($name, $def)` | `X509::registerExtension($name, $def)` (unchanged) |

---

## Validation

| 3.0 | 4.0 |
| --- | --- |
| `$x509->loadCA($pem)` | `X509::addCA($pem)` (now static, and renamed) |
| `$x509->validateSignature()` | `$x509->validateSignature()` (now also runs revocation and date checks) |
| `$x509->validateURL($url)` | `$x509->validateURL($url)` (unchanged) |
| `$x509->validateDate($date)` | (removed) — see below |
| `X509::disableURLFetch()` | `X509::disableURLFetch()` (unchanged; was already static in 3.0) |
| `X509::enableURLFetch()` | `X509::enableURLFetch()` (unchanged) |
| `X509::setRecurLimit($n)` | `X509::setRecurLimit($n)` (unchanged; was already static in 3.0) |

New in 4.0:

- `X509::setTargetValidationDate($date)` — supplies a custom date for the `validateSignature()` date check; defaults to "today" if unset
- `X509::ignoreKeyUsage()` — skip the keyUsage check on the issuer
- `X509::ignoreBasicConstraints()` — skip the basicConstraints check
- `X509::setCRLLookupCallback(callable $fn)` — supply a callback that takes a CDP URL and a serial number, returns whether that serial is listed as revoked in the corresponding CRL. Used by `validateSignature()` for revocation checking. (CRL only; phpseclib 4.0 does not currently do OCSP.)
- CSR and SPKAC have their own `validateSignature()` (always self-signed, no `addCA()` setup needed)

### `validateDate()` removal

In 3.0, `validateDate($date)` was an explicit method — you called it with a date and it told you whether the cert was valid at that moment. In 4.0, that check is folded into `validateSignature()` automatically; if you want to check against a date other than "now," call `X509::setTargetValidationDate($date)` first and then `validateSignature()`. There is no longer a way to do a date-only check independently of the signature check, but in practice nobody wanted that — it was always the signature *and* date as a pair.

---

## Output format (PEM vs DER)

| 3.0 | 4.0 |
| --- | --- |
| `$x509->saveX509($cert)` | `echo $x509;` (PEM, default) |
| `$x509->saveX509($cert, X509::FORMAT_PEM)` | `echo $x509;` (PEM, default) |
| `$x509->saveX509($cert, X509::FORMAT_DER)` | `$x509->toString(['binary' => true])` or `X509::enableBinaryOutput()` (process-wide) |

The 3.0 signature was `saveX509(array $cert, $format = self::FORMAT_PEM)` — you passed it the parsed array form of a cert plus a format flag. 4.0 has nothing equivalent because the object *is* the parsed structure: `__toString()` (or `getEncoded()`) renders it.

`X509::enableBinaryOutput()` and `X509::disableBinaryOutput()` are static toggles; they affect every subsequent `__toString()` call. For per-call control, use `$x509->toString(['binary' => true])`.

PFX is binary-only — there is no PEM form.

---

## Keys (RSA, EC, DSA)

The key APIs are largely unchanged in shape — the same `createKey()`, `load()`, `withHash()`, `withPadding()`, `sign()`, `verify()` patterns from 3.0 carry over. The main differences:

| 3.0 | 4.0 |
| --- | --- |
| `RSA::createKey()` | `RSA::createKey()` (unchanged) |
| `EC::createKey('nistp256')` | `EC::createKey('nistp256')` (unchanged) |
| `RSA::load($pem)` | `RSA::load($pem)` (unchanged) |
| `$key->withHash('sha256')` | `$key->withHash('sha256')` (unchanged) |
| `$key->sign($data)` (string) | `$key->sign($data)` (string) — unchanged |
| (3.0 only) | `$key->sign($x509)` — also accepts `Signable` objects |
| `Random::string($n)` | `random_bytes($n)` |

**PHP 8.1+ is now the minimum** (was 5.6 in 3.0). All key operations now have proper type declarations. Bad-type calls throw `TypeError` immediately rather than failing deeper in the call stack.

Key serialization formats, password handling, and OpenSSH key parsing are unchanged — anything that worked in 3.0 for raw key loading and saving works identically in 4.0 with just the namespace updated.

---

## Random bytes

| 3.0 | 4.0 |
| --- | --- |
| `phpseclib3\Crypt\Random::string($n)` | `random_bytes($n)` (PHP built-in) |

The entire `Crypt\Random` class is gone in 4.0. PHP's built-in `random_bytes()` (available since PHP 7.0) is used directly throughout the library, and your code should too.

---

## Engine selection

The methods used to pin a specific cryptographic backend (libsodium / OpenSSL / pure-PHP) were renamed:

| phpseclib 3.0 (pre-3.0.51) | phpseclib 3.0.51+ and 4.0 |
| --- | --- |
| `RSA::useBestEngine()` | `RSA::forceEngine(null)` (null = don't force, pick best) |
| `RSA::useInternalEngine()` | `RSA::forceEngine('PHP')` |
| `RSA::getEngine()` | `RSA::getForcedEngine()` |

The 4.0 signature: `public static function forceEngine(?string $engine = null): void`. Available values are the strings `'PHP'`, `'libsodium'`, and `'OpenSSL'` — case-sensitive. Passing `null` clears any previous forcing and goes back to "pick the best available." Passing an engine that isn't available for the key type (e.g., libsodium for RSA) throws an exception.

This rename was backported to 3.0.51 — if you're on a current 3.0 release, the new names already work and there's no migration to do.

**Why the rename happened.** The pre-3.0.51 API only let you choose between "best" and "internal," which works fine when there are exactly two engines, but breaks down once libsodium enters the picture for things like Ed25519 signatures. Suddenly "best" is ambiguous (libsodium *or* OpenSSL?) and there's no way to test the non-best engine if you specifically want to. The `forceEngine($name)` shape is unambiguous: you say which engine you want, by name, or you pass `null` to let the library pick.

These methods are almost exclusively used by phpseclib's own unit tests. Production code rarely needs them.

---

## ASN.1 (low-level)

If you only use phpseclib's high-level APIs — `X509::load()`, `RSA::load()`, `EC::createKey()`, etc. — you can skip this section. Almost nothing changed at the surface. The changes here are for the ~5% of users who use `ASN1::decodeBER()` / `ASN1::asn1map()` directly to parse custom ASN.1 structures (e.g., a custom protocol's binary format, or a PFX-like container that phpseclib didn't natively support in 3.0).

### `decodeBER()` return shape changed

In 3.0, `ASN1::decodeBER()` did a *full eager* parse and returned a deeply-nested array reflecting the entire structure of the input. In 4.0, it does a *shallow lazy* parse and returns a one-level array where any nested constructed types are represented as `phpseclib4\File\ASN1\Constructed` objects, decoded only on demand.

```php
// 3.0: full eager parse, deeply nested arrays
$decoded = ASN1::decodeBER($der);
// $decoded[0]['content'][0]['content'][0]['content'] => ... goes all the way down

// 4.0: shallow lazy parse, Constructed objects for nested content
$decoded = ASN1::decodeBER($der);
// $decoded => [
//     'start' => 0, 'length' => 801, 'headerlength' => 4, 'type' => 16,
//     'content' => phpseclib4\File\ASN1\Constructed Object { ... }
// ]
// Decoded interior is materialized only when accessed.
```

This isn't a regression — it's the security improvement that prevents DoS attacks via maliciously-crafted ASN.1 with high-complexity interior elements (large OIDs, deeply nested structures) that were never going to map to a valid schema anyway. In 3.0 the parser ate the entire blob before checking the schema; in 4.0 the schema check happens against the shallow structure first, and the interior is only decoded if the structure matches.

If you have 3.0 code that walks the deeply-nested `decodeBER()` output, the migration paths are:

1. **Use `ASN1::map()` instead** (see below). If your goal was to map the decoded structure to a known schema, the high-level path is still there — and it's how the rest of phpseclib uses ASN.1 internally.
2. **If you specifically want the deep tree** (e.g., you're writing an `asn1parse`-style tool that displays unknown structures recursively), you can roughly simulate the 3.0 behavior by treating each `Constructed` object as a SEQUENCE of `ASN1::TYPE_ANY` and recursively decoding. This is enough work that it's typically only worth it for genuine ASN.1 inspection tools — not for application code.

The old [phpseclib.sourceforge.net/x509/asn1parse.php](https://phpseclib.sourceforge.net/x509/asn1parse.php) demo relied on the 3.0 deeply-nested output and would need this kind of rework to run on 4.0.

### `ASN1::asn1map()` → `ASN1::map()` (and input-shape change)

```php
// 3.0
$decoded = ASN1::decodeBER($der);
$mapped = ASN1::asn1map($decoded[0], $map);   // note the [0] index

// 4.0
$decoded = ASN1::decodeBER($der);
$mapped = ASN1::map($decoded, $map);          // no index
```

Two changes in one line: the method was renamed (`asn1map` → `map`), and the input no longer needs the `[0]` index because `decodeBER()` now returns the single top-level structure directly rather than wrapping it in a one-element array.

### `$special` callbacks → `$rules`

The third argument to the schema-mapping call is the most substantive 4.0 ASN.1 change after the `decodeBER()` shape and the `asn1map` → `map` rename. It's worth understanding even if you don't migrate hand-rolled ASN.1 code immediately, because every meaningful 4.0 schema map uses it.

In 3.0, the third parameter to `ASN1::asn1map()` was `$special` — an array of decoders keyed by exact map-key name, applied during the eager full decode:

```php
// 3.0
$decoder = $id == 'id-ce-nameConstraints'
    ? [static::class, 'decodeNameConstraintIP']
    : [static::class, 'decodeIP'];
$decoded = ASN1::decodeBER($value);
$mapped = ASN1::asn1map($decoded[0], $map, ['iPAddress' => $decoder]);
```

In 4.0, the third parameter to `ASN1::map()` is `$rules` — a structurally-keyed nested array of callbacks (or values, or sub-rule arrays) that are applied **on demand** as `Constructed` interior content is materialized:

```php
// 4.0
$rules['permittedSubtrees']['*']['base']
  = $rules['excludedSubtrees']['*']['base']
  = function (Choice $el): void {
        if (isset($el['iPAddress'])) {
            $ip = (string) $el['iPAddress'];
            $size = strlen($ip) >> 1;
            $mask = substr($ip, $size);
            $ip = substr($ip, 0, $size);
            $el['iPAddress'] = [inet_ntop($ip), inet_ntop($mask)];
        }
    };
return ASN1::map($decoded, $map, $rules);
```

Key differences:

- **Path-shaped keys, not flat keys.** 3.0's `$special` matched a single key name anywhere in the structure; 4.0's `$rules` walks the nested shape of the decoded data, with `*` as a wildcard for "any element" (typical for SET OF / SEQUENCE OF). The example above attaches the IP-address rewriter specifically to `permittedSubtrees[*][base]` and `excludedSubtrees[*][base]`, not to every `iPAddress` field globally.
- **Applied on demand, not eagerly.** A rule callback fires when the corresponding `Constructed` interior is actually accessed. If your code only ever reads the subject DN from a cert, the rules attached to extensions or signatures never run.
- **Used pervasively in 4.0, sparingly in 3.0.** In 3.0 `$special` was an escape hatch for the few fields that needed custom decoding. In 4.0 `$rules` is the *normal* way 4.0's bundled schemas (Certificate, CRL, CMS, etc.) handle anything beyond pure type-driven decoding — including loading public keys, normalizing DNs, and parsing extensions.

If you want 3.0-style "everything decoded up front" behavior in 4.0, you can eagerly walk the structure after `ASN1::map()` and force the `Constructed` objects to materialize. That's a valid migration path for code that depended on the up-front decode and isn't ready to be reorganized around lazy access.

### Other ASN.1 changes

There are additional ASN.1 changes in 4.0 beyond the three above. The best way to learn them is to read how phpseclib's own bundled classes — `X509.php`, `CMS.php`, `CRL.php`, `CSR.php` — use `ASN1::map()` and `$rules`. Those files are the canonical examples of every parsing pattern phpseclib supports, including all the rule shapes (callbacks, sub-arrays, value substitutions) and how they compose. If your migration involves substantial hand-rolled ASN.1 work, reading those classes will teach you more than any prose summary could.

---

## SSH2 and SFTP

### SFTP `chmod` argument order

The most dangerous trap in the SFTP migration. `chmod` was the only SFTP method in 3.0 that took the value before the path; 4.0 fixes this to be consistent with every other SFTP method.

| 3.0 | 4.0 |
| --- | --- |
| `$sftp->chmod(0777, 'file.txt')` | `$sftp->chmod('file.txt', 0777)` |
| `$sftp->chmod(0777, 'dir', true)` (recursive) | `$sftp->chmod('dir', 0777, true)` |
| `$sftp->chown('file.txt', $uid)` | `$sftp->chown('file.txt', $uid)` (unchanged) |
| `$sftp->chgrp('file.txt', $gid)` | `$sftp->chgrp('file.txt', $gid)` (unchanged) |
| `$sftp->touch('file.txt')` | `$sftp->touch('file.txt')` (unchanged) |
| `$sftp->truncate('file.txt', $size)` | `$sftp->truncate('file.txt', $size)` (unchanged) |

Only `chmod` changed. A 3.0-style call against 4.0 throws `TypeError` immediately on the `string $path` parameter (regardless of `declare(strict_types=1)`).

### Removed error-reporting methods

| 3.0 | 4.0 |
| --- | --- |
| `SSH2::getErrors()` | removed — exceptions cover this |
| `SSH2::getLastError()` | removed — exceptions cover this |
| `SFTP::getSFTPErrors()` | removed — exceptions cover individual failures |
| `SFTP::getLastSFTPError()` | removed — exceptions cover individual failures |
| (no equivalent) | `SFTP::getErrors()` — collects per-step errors during recursive operations only |

The 4.0 `SFTP::getErrors()` exists but is **not** the same thing as the 3.0 `getSFTPErrors()`. It's only meaningful after a recursive SFTP operation that kept going past partial failures (e.g., recursive `delete()` on a tree). Its output includes operation and path:

```
['REMOVE /home/test/A (SSH_FX_FAILURE): Failure',
 'REMOVE /home/test/A/B (SSH_FX_PERMISSION_DENIED): Permission denied',
 ...]
```

Migration pattern for typical 3.0 error-checking code:

```php
// 3.0
$sftp->login('user', 'pass');
if ($sftp->getSFTPErrors()) {
    foreach ($sftp->getSFTPErrors() as $err) { error_log($err); }
    exit;
}
$sftp->put('remote', 'local', SFTP::SOURCE_LOCAL_FILE);
if ($sftp->getSFTPErrors()) { /* ... */ }

// 4.0
try {
    $sftp->login('user', 'pass');
    $sftp->put('remote', 'local', SFTP::SOURCE_LOCAL_FILE);
} catch (\Throwable $e) {
    error_log($e->getMessage());
    exit;
}
```

### Other SSH2/SFTP changes

The high-level connect/login/exec/put/get pattern is unchanged. Connection lifecycle, authentication methods, channel management, port forwarding, host key verification, and key exchange algorithm selection all carried over from 3.0 without API changes — only the surrounding error-handling style changed (exceptions instead of `false` returns).

---

## Exceptions and error handling

In 3.0, methods commonly returned `false` on failure and a value on success — effectively `bool|T`. In 4.0, methods throw on failure and return `?T` or `T` on success.

| 3.0 pattern | 4.0 pattern |
| --- | --- |
| `if ($result === false) { /* error */ }` | `try { ... } catch (\Throwable $e) { /* error */ }` |
| `if (!$result) { ... }` | same — but `!$result` is now ambiguous (could be empty string). Prefer try/catch. |
| `if ($foo->something === false)` (uninitialized property) | `if (!isset($foo->something))` or `=== null` |

3.0 exception types were inconsistent — phpseclib threw plain SPL types like `\RuntimeException`, `\UnexpectedValueException`, etc. for effectively identical conditions, with no way to distinguish "this came from phpseclib" from "this came from somewhere else." 4.0 uses dedicated `phpseclib4\Exception\*` types organized into a documented hierarchy.

### How the 4.0 exception hierarchy is structured

Every phpseclib 4.0 exception:

- **extends `\RuntimeException`** (the PHP built-in)
- **implements `phpseclib4\Exception\BaseException`** (a phpseclib-defined interface)

This gives you three workable catch strategies:

```php
// Specific phpseclib type — most precise
try { $x509->getPublicKey(); }
catch (\phpseclib4\Exception\UnexpectedValueException $e) { /* ... */ }

// All phpseclib exceptions — sweep up anything from the library
try { /* phpseclib calls */ }
catch (\phpseclib4\Exception\BaseException $e) { /* ... */ }

// All RuntimeExceptions — sweep up phpseclib AND other PHP-runtime issues
try { /* phpseclib calls */ }
catch (\RuntimeException $e) { /* ... */ }
```

### A sharp edge: don't catch the SPL classes by name

phpseclib 4.0 has classes named `phpseclib4\Exception\UnexpectedValueException`, `phpseclib4\Exception\RuntimeException`, etc. — these **share names** with the PHP SPL exception classes (`\UnexpectedValueException`, `\RuntimeException`) but are different types. The SPL `\UnexpectedValueException` extends `\RuntimeException` and is in the global namespace; the phpseclib version is in `phpseclib4\Exception\` and extends `\RuntimeException` directly (not the SPL `\UnexpectedValueException`).

This means:

```php
// THIS WILL NOT CATCH phpseclib's UnexpectedValueException:
try { $x509->getPublicKey(); }
catch (\UnexpectedValueException $e) { /* never runs */ }

// THIS WILL:
try { $x509->getPublicKey(); }
catch (\phpseclib4\Exception\UnexpectedValueException $e) { /* runs */ }

// SO WILL THIS (broader catch):
try { $x509->getPublicKey(); }
catch (\RuntimeException $e) { /* runs */ }
```

For migrating 3.0 catch blocks: any `catch (\UnexpectedValueException $e)` or `catch (\RuntimeException $e)` that was written for 3.0 phpseclib needs the namespace updated, OR — if you want a single broad catch for phpseclib errors — change to `catch (\RuntimeException $e)` (which keeps working) or `catch (\phpseclib4\Exception\BaseException $e)` (which is more precise and only catches phpseclib's own throws).

### Notable typed exceptions

- `phpseclib4\Exception\UnexpectedValueException` — input doesn't match the shape the method expects (e.g., `getPublicKey()` on an unparseable key format)
- `phpseclib4\Exception\InvalidArgumentException` — caller passed a value that's the right shape but semantically wrong (e.g., an unknown algorithm name)
- `phpseclib4\Exception\UnsupportedAlgorithmException` — input names an algorithm phpseclib doesn't implement
- `phpseclib4\Exception\PasswordNeededException` — encrypted PFX or encrypted key loaded without a password. Notably also thrown by `PublicKeyLoader::load()` when it gets far enough into parsing to know the data is a real key but encrypted; the existence of this distinct exception is what lets `PublicKeyLoader` report "this is encrypted, I need a password" without conflating it with "this isn't a recognizable key at all" (which throws a different exception).
- `phpseclib4\Exception\BadDecryptionException` — wrong password or corrupted ciphertext

The full exception hierarchy is documented at the official phpseclib docs — refer to those for the canonical list.

### The deliberate exception: SFTP recursive operations

Recursive SFTP operations (`delete()` on a tree, recursive `chmod`, etc.) deliberately keep going past per-step failures and collect them into `SFTP::getErrors()`. They do **not** throw on individual failures — only on operation-wide failures like a lost connection. This is a design choice: aborting halfway through a recursive delete leaves the tree half-deleted, which is usually worse than continuing and reporting at the end.

---

## PFX and CMS — no migration

`phpseclib4\File\PFX` and `phpseclib4\File\CMS` are **brand new** in 4.0. No 3.0 code uses them, because no 3.0 equivalent existed.

If a 3.0 codebase appears to do PKCS12 or CMS work, that work is happening **outside phpseclib** — typically via PHP's built-in `openssl_pkcs12_*` functions, `openssl_cms_*` functions (PHP 8.0+), or a shell-out to the `openssl` CLI. Moving those operations into `PFX` / `CMS` is a refactor (often a desirable one — cleaner code, no shell-out, no dependency on a working OpenSSL install), but it's not a mechanical migration.

When asked to "migrate the PKCS12 code to phpseclib 4," look for:

- `openssl_pkcs12_export()` / `openssl_pkcs12_export_to_file()` — replace with `PFX::add()` and `echo $pfx`
- `openssl_pkcs12_read()` — replace with `PFX::load()` and `getCertificates()` / `getPrivateKeys()`
- `proc_open('openssl pkcs12 ...')` — replace with the `PFX` class

For CMS, the analogous PHP functions are `openssl_cms_sign()`, `openssl_cms_verify()`, `openssl_cms_encrypt()`, `openssl_cms_decrypt()`. These map onto `CMS\SignedData` and `CMS\EncryptedData` respectively.

---

## Things that did not change

A short list of common 3.0 patterns that work identically in 4.0, to save reviewer time:

- **Raw byte signing.** `$priv->sign($bytes)` and `$pub->verify($bytes, $sig)` are unchanged.
- **Symmetric ciphers.** AES, DES, 3DES, Twofish, Blowfish, etc. — same API.
- **Hash and HMAC.** `phpseclib4\Crypt\Hash` is the same shape as `phpseclib3\Crypt\Hash`.
- **`BigInteger`.** API stable; the namespace is the only change.
- **SSH2 connect/login/exec.** Basic flow is unchanged. Error handling around it changed (exceptions, see above), but the success path looks the same.
- **SFTP put/get.** Path-first, source/destination unchanged. Only `chmod` swapped.
- **Key creation, loading, password handling, format export.** Same `createKey` / `load` / `withPassword` / `toString` shape, including all the low-level format details (PEM, DER, OpenSSH, PuTTY, XML, etc.).

If you're seeing a 3.0 method not in this file and not in the SKILL.md, it likely falls in the "did not change" bucket — check the official 4.0 docs to confirm the signature, but the migration is probably "update the namespace and you're done."

---

## Reporting gaps

If you find a 3.0 method that this file doesn't cover, or a mapping that turns out to be wrong, please [file an issue](https://github.com/phpseclib/llm-resources/issues) with:

1. The exact 3.0 method or pattern.
2. What the user was trying to do.
3. The 4.0 equivalent (if you've figured it out) or a note that you couldn't find one.

Mappings that need to be updated for new 4.0 releases are also welcome — the API is stabilizing but not frozen, and corrections from people working with current versions are the highest-signal contributions.
