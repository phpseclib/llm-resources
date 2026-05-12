# Distinguished Names (DNs)

Deep reference for the DN handling in phpseclib 4.0 — every accepted input format, every output format, the complete list of recognized property aliases, multi-valued RDNs, the postal-address composite type, and how custom (unknown) OIDs work.

This file covers what `X509`, `CSR`, and `CRL` do with DNs. The method signatures for the DN family are documented in `references/x509.md` (the same methods exist on CSR and CRL, with the simplification that CSR has only a subject DN and CRL has only an issuer DN).

## Contents

- [What a DN is](#what-a-dn-is)
- [Where DNs live](#where-dns-live)
- [The bare-vs-explicit method family](#the-bare-vs-explicit-method-family)
- [Recognized property names](#recognized-property-names)
- [Input formats accepted by `setDN()`](#input-formats-accepted-by-setdn)
- [Adding, querying, removing individual properties](#adding-querying-removing-individual-properties)
- [Explicit ASN.1 string types](#explicit-asn1-string-types)
- [Output formats](#output-formats)
- [`DN_STRING` format change between 3.0 and 4.0](#dn_string-format-change-between-30-and-40)
- [Multi-valued RDNs](#multi-valued-rdns)
- [The `id-at-postalAddress` composite type](#the-id-at-postaladdress-composite-type)
- [Custom and unknown OIDs](#custom-and-unknown-oids)
- [Comparison: strict vs. loose](#comparison-strict-vs-loose)

---

## What a DN is

An X.509 Distinguished Name is an ordered sequence of *relative distinguished names* (RDNs). Each RDN is a set (not a sequence — set semantics, unordered) of one or more *attribute-type-and-value* pairs. The ASN.1 schema (from RFC 5280):

```asn1
Name ::= CHOICE {
    rdnSequence  RDNSequence
}

RDNSequence ::= SEQUENCE OF RelativeDistinguishedName

RelativeDistinguishedName ::= SET SIZE (1..MAX) OF AttributeTypeAndValue

AttributeTypeAndValue ::= SEQUENCE {
    type   AttributeType,        -- an OID
    value  AttributeValue         -- ANY, the type is determined by the OID
}
```

Almost all real DNs are flat — every RDN holds exactly one attribute, so the structure is effectively a list of `(OID, value)` pairs in order. The `RelativeDistinguishedName` being a SET rather than a single element is what enables [multi-valued RDNs](#multi-valued-rdns), which do exist in the wild but are rare.

The order of RDNs in the sequence is part of the DN's identity. `C=US, O=Acme` and `O=Acme, C=US` are different DNs.

phpseclib's parsed representation mirrors this structure exactly:

```php
[
    'rdnSequence' => [
        // each entry is an RDN (a SET)
        [
            // each entry inside an RDN is an attribute-type-and-value pair
            ['type' => 'id-at-countryName', 'value' => /* typed string */],
        ],
        [
            ['type' => 'id-at-organizationName', 'value' => /* typed string */],
        ],
        // ...
    ],
]
```

You can access this raw shape via `getDN(ASN1::DN_ARRAY)` or by reaching into the `ArrayAccess` view of the cert.

---

## Where DNs live

| Class | Has subject DN? | Has issuer DN? |
| --- | --- | --- |
| `X509` | yes | yes |
| `CSR` | yes | no |
| `CRL` | no | yes |
| `PFX` | no (uses contained X509s) | no |
| `SPKAC` | no | no |
| `CMS\SignedData` and friends | not directly — see note | not directly — see note |

For `CSR`, both `getDN()` and `getSubjectDN()` exist and are aliases — `CSR` has no issuer concept, so there's nothing to disambiguate. For `CRL`, both `getDN()` and `getIssuerDN()` are aliases for the same reason on the other side. For `X509`, where both DNs exist, the bare and explicit variants behave differently — see next section.

**Note on CMS classes.** The CMS classes (`SignedData`, `Signer`, `EncryptedData`, recipients) do contain DNs structurally — `Signer` references its signing cert via `$signer['sid']['issuerAndSerialNumber']['issuer']`, recipients via `$recipient['rid']['issuerAndSerialNumber']['issuer']` — but none of these classes expose `getDN()`-style helper methods, and they're not the intended way to work with the data. For SignedData, iterate `$signedData->getSigners()` and call `$signer->getCertificate()->getSubjectDN()` (or `->getIssuerDN()`) on each — the certificate is embedded in the SignedData's certificates collection, and the helper does the matching. For EncryptedData there's no equivalent `getCertificate()` on recipients because the goal there is decryption, not identification-after-the-fact. The DN-trait registration on `Signer` (which makes its DNs print readably via `print_r`) is plumbing, not API surface; it's not currently registered on EncryptedData or its recipients, so DN attributes there — including `postalAddress` — won't fully decode in their `print_r` output.

---

## The bare-vs-explicit method family

For X509, the full DN method surface is triplicated. Each operation has a bare form (works only on self-signed certs where subject = issuer), a `Subject*` form (always works), and an `Issuer*` form (always works).

| Bare | Subject variant | Issuer variant |
| --- | --- | --- |
| `getDN()` | `getSubjectDN()` | `getIssuerDN()` |
| `setDN($props)` | `setSubjectDN($props)` | `setIssuerDN($props)` |
| `resetDN()` | `resetSubjectDN()` | `resetIssuerDN()` |
| `addDNProp($name, $value)` | `addSubjectDNProp(...)` | `addIssuerDNProp(...)` |
| `addDNProps($name, array $values)` | `addSubjectDNProps(...)` | `addIssuerDNProps(...)` |
| `removeDNProps($name)` | `removeSubjectDNProps(...)` | `removeIssuerDNProps(...)` |
| `getDNProps($name)` | `getSubjectDNProps(...)` | `getIssuerDNProps(...)` |
| `hasDNProp($name)` | `hasSubjectDNProp(...)` | `hasIssuerDNProp(...)` |

The bare versions throw `phpseclib4\Exception\UnexpectedValueException` if subject ≠ issuer. For any X509 code that isn't provably handling only self-signed certs, **always use the explicit variants**. The bare versions exist mainly for compatibility with code shared between X509 and CSR/CRL, where the bare-and-explicit-are-aliases situation makes them interchangeable.

---

## Recognized property names

phpseclib accepts these property names everywhere a property is expected (`addDNProp`, `setDN` array keys, `getDNProps`, etc.). Names are **case-insensitive**; aliases are grouped together. Pass any name in a group and phpseclib normalizes it to the canonical form.

| Canonical | Aliases | Notes |
| --- | --- | --- |
| `id-at-countryName` | `countryName`, `C` | |
| `id-at-stateOrProvinceName` | `stateOrProvinceName`, `state`, `province`, `provincename`, `ST` | |
| `id-at-localityName` | `localityName`, `L` | |
| `id-at-organizationName` | `organizationName`, `O` | |
| `id-at-organizationalUnitName` | `organizationalUnitName`, `OU` | |
| `id-at-organizationIdentifier` | `organizationIdentifier` | |
| `id-at-commonName` | `commonName`, `CN` | |
| `id-at-businessCategory` | `businessCategory` | |
| `id-at-serialNumber` | `serialNumber` | |
| `id-at-postalCode` | `postalCode` | |
| `id-at-streetAddress` | `streetAddress` | |
| `id-at-postalAddress` | `postalAddress` | Composite — see [below](#the-id-at-postaladdress-composite-type) |
| `id-at-name` | `name` | |
| `id-at-givenName` | `givenName`, `GN` | |
| `id-at-surname` | `surname`, `SN` | |
| `id-at-initials` | `initials` | |
| `id-at-generationQualifier` | `generationQualifier` | |
| `id-at-dnQualifier` | `dnQualifier` | |
| `id-at-pseudonym` | `pseudonym` | |
| `id-at-title` | `title` | |
| `id-at-description` | `description` | |
| `id-at-role` | `role` | |
| `id-at-uniqueIdentifier` | `uniqueIdentifier`, `x500UniqueIdentifier` | |
| `pkcs-9-at-emailAddress` | `id-at-emailAddress`, `id-emailAddress`, `emailAddress` | Lives under the PKCS #9 OID branch, not `id-at-*` |
| `id-domainComponent` | `domainComponent`, `DC`, `id-domainComponent` | Used for AD-style DNs like `DC=example, DC=com` |
| `jurisdictionOfIncorporationCountryName` | `jurisdictionCountryName`, `jurisdictionC` | EV-cert extension |
| `jurisdictionOfIncorporationStateOrProvinceName` | `jurisdictionStateOrProvinceName`, `jurisdictionST` | EV-cert extension |
| `jurisdictionLocalityName` | `jurisdictionL` | EV-cert extension |

Any name not in this table throws `phpseclib4\Exception\InvalidArgumentException`, **unless** the name is a raw dotted OID (matches `/^\d+(?:\.\d+)+$/`). Raw OIDs are passed through as-is and treated as user-defined attribute types. See [Custom and unknown OIDs](#custom-and-unknown-oids).

---

## Input formats accepted by `setDN()`

The signature is:

```php
public function setDN(array|string|Element $props): void
```

Four input shapes are accepted. (Five if you count the empty cases.)

### 1. String — OpenSSL CLI format

The format `openssl x509 -noout -text` produces for the issuer/subject lines:

```php
$x509->setDN('C = US, O = Acme, CN = example.com');
```

Comma-separated `KEY = VALUE` pairs. Spaces around the `=` are optional (`C=US,O=Acme,CN=example.com` works too). Property names are normalized per the alias table above.

Rules for values in this format:

- **Non-printable ASCII characters** must be escaped as `\XX` hex sequences. The input is rejected with `UnexpectedValueException('Non-printable ASCII characters should not be present')` if any non-ASCII bytes appear directly.
- **Commas** within values must be either escaped (`\,` → `,`) or the whole value double-quoted: `O = "Acme, Inc."`.
- **Leading or trailing spaces** in a value require double-quoting: `CN = " spaced "`.
- **Backslashes and double quotes** inside a value are escaped as `\\` and `\"`.
- **`#`-prefixed hex** at the start of a value (`#3013...`) is treated as a raw DER-encoded value for that attribute. Used for unknown OIDs where the value's ASN.1 type isn't known to phpseclib. Example: `setDN('2.9999999 = #30050C03787878')`.

### 2. String — OpenSSL legacy format

The slash-separated format from older `openssl_x509_parse()` output and from `openssl x509 -subject -nameopt compat`:

```php
$x509->setDN('/C=US/O=Acme/CN=example.com');
```

The first character must be `/`. Rules:

- Slashes within values are escaped as `\/`.
- Non-printable bytes are encoded as `\xHH` hex sequences (lowercase `x`).
- This format does not support multi-valued RDNs.

Use the OpenSSL CLI format above for new code; the legacy format is here for compatibility with input from old tooling.

### 3. Array — `DN_OPENSSL` shape

A flat associative array, keyed by property name (with aliases accepted):

```php
$x509->setDN([
    'C'  => 'US',
    'O'  => 'Acme',
    'CN' => 'example.com',
]);
```

Values that are themselves arrays produce multi-valued RDNs:

```php
$x509->setDN([
    'C'  => 'US',
    'CN' => ['primary.example.com', 'alt.example.com'],  // two CN attributes
]);
```

Note: the multi-value case here actually produces *two separate RDNs* in the rdnSequence (one per CN value), not a single RDN with two values. To get a true multi-valued RDN, use the array form below.

This shape is what `getDN(ASN1::DN_OPENSSL)` produces, so it round-trips cleanly.

### 4. Array — `DN_ARRAY` shape

The most explicit form, mirroring the ASN.1 structure directly:

```php
$x509->setDN([
    'rdnSequence' => [
        [
            ['type' => 'id-at-countryName', 'value' => 'US'],
        ],
        [
            ['type' => 'id-at-organizationName', 'value' => 'Acme'],
        ],
        [
            // Multi-valued RDN: one SET containing two attributes
            ['type' => 'id-at-commonName', 'value' => 'primary.example.com'],
            ['type' => 'id-at-commonName', 'value' => 'alt.example.com'],
        ],
    ],
]);
```

Output of `getDN(ASN1::DN_ARRAY)` is in this shape, so it round-trips.

### 5. Element — raw ASN.1 bytes

For research / malformed-cert / niche cases where you have the DER encoding of a Name and want to install it directly:

```php
use phpseclib4\File\ASN1\Element;

$x509->setDN(new Element($rawNameDER));
```

phpseclib doesn't validate the bytes. They're written through unchanged. This is the right path when you specifically want a cert with a non-conforming or experimental DN structure.

---

## Adding, querying, removing individual properties

`setDN()` replaces the entire DN. For incremental changes, use the per-property family:

```php
public function addDNProp(string $name, string|BaseString|array|Element|Constructed $value): void
public function addSubjectDNProp(string $name, ...$value): void
public function addIssuerDNProp(string $name, ...$value): void

public function addDNProps(string $name, array $values): void
// Subject/Issuer variants exist

public function getDNProps(string $name): array
// Subject/Issuer variants exist; always returns an array (possibly empty)

public function hasDNProp(string $name): bool
// Subject/Issuer variants exist

public function removeDNProps(string $name): void
// Subject/Issuer variants exist; removes ALL matching RDNs
```

Single-value vs. multi-value appenders:

```php
$x509->addSubjectDNProp('O', 'phpseclib');           // appends one RDN
$x509->addSubjectDNProps('O', ['Org A', 'Org B']);  // appends two RDNs, both 'O'
```

`addDNProps` (with the trailing `s`) is shorthand for calling `addDNProp` repeatedly — it produces N separate single-valued RDNs, *not* one multi-valued RDN. Multi-valued RDNs require the `DN_ARRAY` shape passed to `setDN()`. See [Multi-valued RDNs](#multi-valued-rdns) below.

For the common case of "give me the CN," index into the returned array:

```php
$cn = $x509->getSubjectDNProps('CN')[0] ?? null;  // string or null
```

`removeDNProps` removes *every* RDN matching the property name; there's no "remove just the first" variant. If you need to keep some occurrences, get the full list, remove all, and re-add the keepers.

The `$value` accepted by `addDNProp` is `string|BaseString|array|Element|Constructed` — strings get UTF-8-encoded; typed `BaseString` instances preserve their ASN.1 type; arrays are used for composite values like `id-at-postalAddress`; `Element` and `Constructed` are for raw-bytes injection.

---

## Explicit ASN.1 string types

By default, every string value passed to `setDN()` becomes a `UTF8String`. To force a different ASN.1 string type, wrap the value in a typed string class:

```php
use phpseclib4\File\ASN1\Types\PrintableString;
use phpseclib4\File\ASN1\Types\IA5String;
use phpseclib4\File\ASN1\Types\BMPString;

$x509->setDN([
    'C'  => new PrintableString('US'),       // PrintableString
    'O'  => new IA5String('Acme'),            // IA5String
    'CN' => new BMPString("\x00E\x00x\x00."), // BMPString (UTF-16-ish)
]);
```

The available types are listed in `references/asn1-constructed.md` under "The type hierarchy." The most commonly needed ones for DN values:

- **`UTF8String`** — the default; appropriate for almost everything in modern certs.
- **`PrintableString`** — required for `id-at-countryName` per RFC 5280. Limited to a-z, A-Z, 0-9, space, and a handful of punctuation: `' = ( ) + , - . / : ?`. Notably **excludes `@` and `_`**.
- **`IA5String`** — required for email addresses and some other attributes that hold ASCII strings.
- **`BMPString`** — UTF-16 (effectively). Sometimes seen in older certs.
- **`TeletexString`** — legacy; mostly seen in old certs and rarely emitted by new ones.

The reason this matters: chain validation in strict mode compares DNs by ASN.1 type as well as value, so a `UTF8String "Acme"` and a `PrintableString "Acme"` won't match. If you're issuing certs whose subject DN must match an existing CA's issuer DN, the string types have to match too. See [Comparison: strict vs. loose](#comparison-strict-vs-loose).

---

## Output formats

`getDN($format)` accepts one of six format constants, all on `ASN1` (not `X509` — they moved in 4.0):

```php
$x509->getDN(ASN1::DN_STRING);   // default — OpenSSL 3.0 CLI string
$x509->getDN(ASN1::DN_OPENSSL);  // flat associative array
$x509->getDN(ASN1::DN_ARRAY);    // raw nested-array structure
$x509->getDN(ASN1::DN_ASN1);     // raw binary DER
$x509->getDN(ASN1::DN_CANON);    // canonicalized binary (for matching)
$x509->getDN(ASN1::DN_HASH);     // 32-bit hex hash (OpenSSL-compatible)
```

### `DN_STRING`

The default. Returns the OpenSSL 3.0 CLI format:

```
C = US, O = Internet Security Research Group, CN = ISRG Root X1
```

Property name aliases on output use the short forms when available: `C`, `ST`, `O`, `OU`, `CN`, `L`, `SN`, `GN`, `DC`, `x500UniqueIdentifier`, `jurisdictionL`, `jurisdictionST`, `jurisdictionC`. For property names without a registered short form (custom OIDs), the last segment after the final `-` is used (e.g., `id-at-businessCategory` → `businessCategory`).

Value-encoding rules:

- Non-printable bytes are written as `\XX` (uppercase hex) sequences.
- Backslashes are escaped as `\\`, double quotes as `\"`.
- Values containing commas or with leading/trailing spaces are double-quoted.
- Values that start with `#` followed by other characters get double-quoted to disambiguate from the `#`-hex syntax.
- Constructed or Element-typed values (raw bytes from unknown OIDs) are emitted as `#` followed by uppercase hex of the encoded bytes.

### `DN_OPENSSL`

A flat associative array mostly compatible with `openssl_x509_parse('...')['issuer']`:

```php
[
    'C'  => 'US',
    'O'  => 'Internet Security Research Group',
    'CN' => 'ISRG Root X1',
]
```

If the same attribute appears multiple times in the DN, the value is an array:

```php
[
    'C'  => 'US',
    'CN' => ['ISRG Root X1', 'phpseclib'],
]
```

Note that this *loses information* compared to `DN_ARRAY` — you can't tell from this shape whether the two CNs were two separate RDNs or one multi-valued RDN. Use `DN_ARRAY` if that distinction matters.

`DN_OPENSSL` doesn't represent constructed values well; for DNs that include `id-at-postalAddress` or unknown-OID constructed attributes, use `DN_ARRAY`.

### `DN_ARRAY`

The internal nested-array representation. Mirrors the ASN.1 structure exactly:

```php
[
    'rdnSequence' => [
        [
            ['type' => 'id-at-countryName', 'value' => /* PrintableString or UTF8String */ ],
        ],
        [
            ['type' => 'id-at-organizationName', 'value' => /* ... */ ],
        ],
        // ...
    ],
]
```

This is the right form to use when you need to inspect or modify the structure programmatically — multi-valued RDNs, attribute ordering, exact ASN.1 string types are all visible.

### `DN_ASN1`

The raw binary DER encoding of the Name. Equivalent to `$x509['tbsCertificate']['subject']->getEncoded()`. Useful when:

- You need to embed the DN bytes elsewhere (e.g., in a CMS structure).
- You're comparing DNs at the byte level.

Cannot be fed back into `setDN()` as a plain string (it would be parsed as the OpenSSL string format and fail). To round-trip, wrap in an `Element`: `setDN(new Element($der))`.

### `DN_CANON`

A canonicalized binary encoding designed for matching DNs that differ only in encoding-incidental ways:

- The outer SEQUENCE wrapper is stripped.
- Each string value is converted to UTF-8 where possible.
- String values are lowercased.
- Whitespace runs are collapsed to single spaces.
- Constructed RDN values are left mostly alone (except `id-at-postalAddress`, which gets recursively normalized to UTF-8).

Two DNs that are "the same" by RFC 5280 matching rules will produce the same `DN_CANON` bytes. Use this for issuer-DN-matches-subject-DN comparisons during chain building (which is what phpseclib's loose-comparison mode does — see [below](#comparison-strict-vs-loose)).

### `DN_HASH`

A hexadecimal string of the first 4 bytes of `SHA1(DN_CANON)` interpreted as a little-endian 32-bit integer, then re-packed and written as lowercase hex:

```
$ openssl x509 -noout -subject_hash -in cert.pem
1cd7d51b
```

```php
echo $x509->getDN(ASN1::DN_HASH);
// 1cd7d51b
```

Matches OpenSSL's `subject_hash` / `issuer_hash` output. The hash isn't cryptographically secure — it's a directory-lookup hash. Real-world CA certificate stores use these as filenames (`1cd7d51b.0`) so applications can find a CA by its DN hash.

---

## `DN_STRING` format change between 3.0 and 4.0

Worth highlighting separately because the change silently breaks `strpos`-style code.

The same DN renders differently across phpseclib versions and tools:

| Source | Output |
| --- | --- |
| phpseclib 4.0 `getDN(DN_STRING)` | `C = \C3\80, O = B, serialNumber = C` |
| OpenSSL 3.0 CLI | `C = \C3\80, O = B, serialNumber = C` |
| `openssl_x509_parse()` on OpenSSL 3.0 | `/C=\xC3\x80/O=B/serialNumber=C` |
| OpenSSL 1.0 CLI | `C=\xC3\x80, O=B/serialNumber=C` |
| phpseclib 1.0 – 3.0 `getDN(DN_STRING)` | `C=À, O=B/serialNumber=C` |

phpseclib 4.0 emits the OpenSSL 3.0 CLI format. The reason: the older formats used `/` as both an RDN separator and a legal character within values, leaving `O=Acme/Inc.` ambiguous (one attribute with a slash in its value, or two attributes?). The 4.0 format uses `, ` as the separator and escapes embedded commas, eliminating the ambiguity.

phpseclib 4.0's `setDN()` accepts both the new format and the OpenSSL 3.0+ `openssl_x509_parse()` slash-prefixed format, so input-side compatibility is preserved. Only `getDN()` output changed.

**Don't string-match `getDN()` output.** Use `getSubjectDNProps('CN')` (returns an array of values) or `getSubjectDN(ASN1::DN_OPENSSL)` (returns an associative array) for stable structured access. The migration guide and the `detect-version.php` scanner both flag string-matching against `getDN()` output as a likely silent-break point.

---

## Multi-valued RDNs

An RDN is technically a SET of attribute-type-and-value pairs, not a single attribute. The vast majority of real-world DNs use single-attribute RDNs, but the schema allows multiple. A multi-valued RDN looks like this in `DN_ARRAY` form:

```php
'rdnSequence' => [
    [
        // one RDN with TWO attributes
        ['type' => 'id-at-commonName', 'value' => 'primary'],
        ['type' => 'id-at-commonName', 'value' => 'alternate'],
    ],
],
```

In `DN_STRING` form they're represented with `+` between the attributes — though phpseclib's current `setDN()` string parser doesn't support `+` syntax, so the only way to construct them is via the `DN_ARRAY` form.

`getDN(DN_OPENSSL)` collapses multi-valued RDNs and multiple single-attribute RDNs into the same shape (a value array on a single key), losing the structural distinction.

Whether you need multi-valued RDNs is largely determined by whatever you're interoperating with. Most CAs issue certs with flat (single-attribute) RDNs and most validators handle them. If you're not specifically interoperating with something that uses multi-valued RDNs, the safer choice is to keep things flat.

---

## The `id-at-postalAddress` composite type

`id-at-postalAddress` is the only composite-valued DN attribute that phpseclib has built-in support for — every other DN attribute in the [recognized property names](#recognized-property-names) table holds a simple string. Other composite-valued DN attributes may exist in private OID arcs or in RFCs phpseclib doesn't yet model; if you encounter one, it'll come through `getDN(DN_ARRAY)` as a `Constructed` and you'll need to handle the structure manually.

The value of `id-at-postalAddress` is a SEQUENCE of `DirectoryString`s (each line of the address). phpseclib special-cases this attribute throughout the DN code.

Setting it:

```php
$x509->addDNProp('id-at-postalAddress', [
    'John Doe',
    '111 Anywhere St',
    'Anytown, TX, USA',
]);
```

Each line can be a plain string (becomes a `UTF8String`), or a typed string instance for explicit type control:

```php
use phpseclib4\File\ASN1\Types\PrintableString;

$x509->addDNProp('id-at-postalAddress', [
    new PrintableString('John Doe'),
    new PrintableString('111 Anywhere St'),
    new PrintableString('Anytown, TX, USA'),
]);
```

Or each line can be a `Choice` wrapping a `(string-type-name => value)` pair, which is the most explicit form (and what `getDN(DN_ARRAY)` produces on output):

```php
[
    ['utf8String' => new UTF8String('John Doe')],
    ['utf8String' => new UTF8String('111 Anywhere St')],
    ['utf8String' => new UTF8String('Anytown, TX, USA')],
]
```

Getting it: in `DN_OPENSSL` output it shows up as a multi-line representation; in `DN_ARRAY` output it's a properly structured nested array.

**Compatibility note.** `openssl_x509_parse()` does *not* surface `id-at-postalAddress` (or any other constructed-value DN attribute) — it filters them out entirely. If you're producing certs that need to be interoperable with PHP-OpenSSL parsing, avoid using `postalAddress` in the DN.

---

## Custom and unknown OIDs

phpseclib accepts any dotted OID as a property name. The behavior depends on whether phpseclib recognizes the OID:

```php
$x509->addDNProp('2.9999999', 'some value');
```

If the OID hasn't been registered (via `ASN1::loadOIDs()`), phpseclib doesn't know what ASN.1 type the value should have. The value is stored as a `UTF8String` and serialized as one. On output:

- `getDN(DN_STRING)`: emitted as `2.9999999 = "some value"` (with appropriate escaping).
- `getDN(DN_OPENSSL)`: the OID is the key, the value is the string.

To register a custom OID with a symbolic name:

```php
ASN1::loadOIDs([
    'myAttribute' => '2.9999999',
]);
```

After this, `'myAttribute'` and `'2.9999999'` are interchangeable everywhere phpseclib accepts an attribute name. See `references/asn1-constructed.md` under "Custom OIDs" for details.

### Hex-encoded raw values

For attributes where you need to write the raw DER-encoded bytes directly (e.g., the value is a custom constructed type that phpseclib doesn't know how to serialize), use the `#`-prefixed hex syntax in string form:

```php
$x509->setDN('2.9999999 = #30050C03787878');
```

or wrap an `Element` in array form:

```php
use phpseclib4\File\ASN1\Element;

$x509->addDNProp('2.9999999', new Element(hex2bin('30050C03787878')));
```

Both produce the same result: the value's DER bytes are `30 05 0C 03 78 78 78`. phpseclib will emit it back as `#30050C03787878` in `DN_STRING` form.

### The ambiguity problem

When phpseclib doesn't recognize an OID, it doesn't know whether the value should be a primitive (string-like) or constructed (composite). Output formats handle this differently:

- **`DN_STRING` (4.0)**: a hex-encoded raw value gets emitted as `#HEX` (no quotes); a string value as `"..."` (with quotes). This makes the two cases visually distinguishable.
- **`DN_OPENSSL`**: both cases collapse to "whatever the value is now" — the constructed/primitive distinction is lost.
- **`openssl_x509_parse()` on PHP**: doesn't disambiguate either — constructed values are dropped or rendered as opaque escaped strings.

If you're working with unknown-OID attributes round-trip, use the `Element` wrapper on input and check `instanceof Element` / `instanceof Constructed` on output rather than trusting string round-tripping.

---

## Comparison: strict vs. loose

When validating a certificate chain, phpseclib needs to decide whether one cert's issuer DN matches another cert's subject DN. Two modes:

```php
X509::strictDNComparison();           // default
X509::looseDNComparison();
X509::isStrictDNComparisonEnabled();
```

### Strict comparison (the default)

Two DNs match only if they're byte-identical at the ASN.1 level: same attribute order, same attribute types (OIDs), same string types (`UTF8String` vs. `PrintableString` matters), same string values byte-for-byte. This is what RFC 5280 § 7.1 specifies as the default matching rule.

Strict comparison catches subtle issues (a CA cert with a `PrintableString` "Acme" subject won't be matched by an end-entity cert with a `UTF8String` "Acme" issuer, because they're encoding different things even though they print the same).

### Loose comparison

Two DNs match if they're equal in `DN_CANON` form — that is, if their UTF-8-normalized, lowercased, whitespace-collapsed encodings are byte-identical. String type differences are ignored; case differences are ignored; whitespace differences are ignored.

Loose comparison is what `openssl verify` and most browsers do in practice, and it's what you generally want when validating real-world certificate chains. Some real chains were issued with mixed string types in different cert generations and only validate under loose comparison.

```php
X509::looseDNComparison();
```

The choice is global and process-wide. Set it at startup based on your policy. If you're validating chains from arbitrary external sources, loose is usually the right default; if you're validating your own internal PKI where you control the cert issuance, strict catches more bugs.
