# `phpseclib4\File\ASN1` and `phpseclib4\File\ASN1\Constructed`

Deep reference for the ASN.1 layer in phpseclib 4.0 — the parser, the type hierarchy, the `$rules` mechanism, the `Signable` interface, and the lazy-decoding model that the `X509` / `CSR` / `CRL` / `PFX` / `CMS` classes are built on top of.

You don't need any of this to write code that uses the high-level classes. Load this when:

- You're writing a custom ASN.1 parser on top of phpseclib (your own protocol's binary format, a non-standard certificate variant, an embedded structure phpseclib doesn't model).
- You're trying to understand *why* a certificate parses the way it does — what's lazy, what's eager, what `$rules` did at parse time.
- You want to implement `Signable` on a new type so a `PrivateKey` can sign it directly.
- You're debugging an `UnexpectedValueException` or `ExcessivelyDeepDataException` from deep inside `ASN1::decodeBER()` and need to know what the parser was trying to do.

## Contents

- [The two-phase pipeline](#the-two-phase-pipeline)
- [`ASN1::decodeBER()`](#asn1decodeber)
- [`ASN1::map()` and rules](#asn1map-and-rules)
- [`Constructed`: the lazy SEQUENCE/SET node](#constructed-the-lazy-sequenceset-node)
- [The type hierarchy](#the-type-hierarchy)
- [Cache invalidation and re-encoding](#cache-invalidation-and-re-encoding)
- [Security limits](#security-limits)
- [Toggling the parser](#toggling-the-parser)
- [The `Signable` interface](#the-signable-interface)
- [Custom OIDs](#custom-oids)
- [Worked example: parsing a custom SEQUENCE](#worked-example-parsing-a-custom-sequence)

---

## The two-phase pipeline

Decoding ASN.1 in 4.0 is two distinct steps. They have to be understood separately:

1. **`ASN1::decodeBER($bytes)`** does a *shallow* parse. It walks the outermost tag, reads its length, and returns a single-level array describing it. If the tag is a SEQUENCE / SET / constructed OCTET STRING / etc., the *interior* is wrapped in a `phpseclib4\File\ASN1\Constructed` object — not parsed yet. The Constructed object holds the raw bytes plus enough metadata (start, headerlength, tag, class) to parse them later.

2. **`ASN1::map($decoded, $mapping, $rules)`** attaches a *schema* (the `$mapping`) and a *rule set* (the `$rules`) to that Constructed object. The schema describes what fields should be inside; the rules describe what to do with each field once it's accessed. Neither the schema nor the rules trigger any further decoding by themselves — they're stored on the Constructed object via `linkMapping()`.

3. **Decoding the interior** happens on demand, the first time someone calls `$constructed['fieldname']` (or iterates, or asks for `count()`, etc.). The Constructed object's `decodeCurrent()` runs at that point, walks its bytes against the schema, applies any matching rules, and caches the result. Subsequent accesses are cheap.

This is the key 4.0 architectural change. In 3.0, `ASN1::decodeBER()` did the full deep decode upfront — the entire tree was materialized before you could touch any of it. In 4.0, the tree only materializes the branches you actually visit.

The practical consequences:

- **Parse time is roughly proportional to how much of the structure you access**, not to how big the structure is. A 50KB certificate where you only read the subject DN costs about the same as a 5KB certificate where you only read the subject DN.
- **Malicious deep nesting in unused branches doesn't pay.** An attacker who embeds a million levels of nested SEQUENCEs inside an unused extension can't force phpseclib to walk those levels unless your code reads that extension. (Compare 3.0, where every byte was walked eagerly.)
- **Errors surface at access time, not at load time.** `X509::load($pem)` succeeds on a cert with a malformed signature algorithm OID if you never read `signatureAlgorithm`. The first time you do, you get the exception.

---

## `ASN1::decodeBER()`

```php
public static function decodeBER(string $encoded, int $start = 0, int $encoded_pos = 0): array
```

Returns a shallow descriptor of the single ASN.1 element at `$encoded_pos` in `$encoded`. Shape:

```php
[
    'start'        => int,    // byte offset of the value (after the header)
    'headerlength' => int,    // header size in bytes (tag + length octets)
    'length'       => int,    // length of the value (may be omitted for indefinite-length)
    'type'         => int,    // ASN1::TYPE_* constant for the tag
    'content'      => mixed,  // either a raw bytes string, a Constructed, or a typed object
]
```

For context-specific / application / private classes, the descriptor has additional keys (`constant` for the tag number, `type` set to the class constant). UNIVERSAL types use the `type` field directly.

What goes in `content`:

- For primitive types where `decodeBER` has all it needs (NULL, BOOLEAN, simple INTEGER, etc.), the typed object directly (`ExplicitNull`, `Boolean`, `Integer`).
- For SEQUENCE / SET / CHOICE-like / constructed OCTET STRING / constructed BIT STRING / etc., a `Constructed` instance wrapping the raw interior bytes.
- For BIT STRING / OCTET STRING / UTCTime / GeneralizedTime / OID, the parsed leaf object (`BitString`, `OctetString`, `UTCTime`, `GeneralizedTime`, `OID`).

`decodeBER` does *not* take a schema. It parses the byte structure based purely on the ASN.1 tags. A schema-less `decodeBER` call on a SEQUENCE gives you a Constructed object whose children can be enumerated (via `toArray()`) but not addressed by name (`offsetGet` throws `InvalidStateException` until `linkMapping()` has been called).

---

## `ASN1::map()` and rules

```php
public static function map(array $decoded, array $mapping, array $rules = []): Element|BaseType
```

`map()` takes a `decodeBER` result and a schema (`$mapping`), and returns the typed value. For a SEQUENCE schema, it returns the same Constructed object that was in `$decoded['content']`, after attaching the mapping and rules to it via `linkMapping()`. For a primitive schema, it returns the typed object directly.

### What the mapping looks like

A mapping is a nested array describing the schema. For a SEQUENCE:

```php
[
    'type' => ASN1::TYPE_SEQUENCE,
    'children' => [
        'fieldName1' => ['type' => ASN1::TYPE_INTEGER],
        'fieldName2' => ['type' => ASN1::TYPE_UTF8_STRING, 'optional' => true],
        'fieldName3' => ['type' => ASN1::TYPE_SEQUENCE, 'children' => [/* ... */]],
    ],
]
```

The `'children'` map controls how positional ASN.1 elements get string-keyed names (so `$constructed['fieldName1']` works). For SET / SEQUENCE OF / SET OF schemas, use `'min'` and `'max'` instead of `'children'`, plus a single inner `'children'` describing the repeated element type.

Field options:

- `'optional' => true` — the field may be absent. If absent, the key won't appear in the parsed Constructed.
- `'default' => $value` — the field has a default value if absent.
- `'implicit' => true` / `'explicit' => true` — the field is tagged. Combined with `'constant' => N` to specify the tag number.
- `'mapping' => [...]` — for INTEGER and BIT STRING types, a value-to-name mapping (e.g., bit-name mapping for keyUsage).

For the canonical schemas, read `phpseclib4\File\ASN1\Maps\*` in the source — `Certificate.php`, `CertificationRequest.php`, `CertificateList.php`, the CMS variants, etc.

### Rules

The `$rules` parameter is the on-demand decoding mechanism that makes 4.0 work. Rules attach callbacks (or sub-rule arrays) to specific paths in the structure, and fire when the corresponding interior content is materialized.

```php
$rules = [];

// Sub-rules: when the 'extensions' field of 'tbsCertificate' is accessed, and each child
// element underneath is materialized, hand it to the extension-mapper callback.
$rules['tbsCertificate']['extensions']['*'] = self::mapInExtensions(...);

// Sub-rules with double wildcards: each RDN inside the subject's rdnSequence (which is
// SEQUENCE OF SET OF) gets normalized through the DN mapper.
$rules['tbsCertificate']['subject']['rdnSequence']['*']['*'] = self::mapInDNs(...);

// Inline callback: when subjectPublicKeyInfo is accessed, try to load it as a PublicKey
// and replace the Constructed in-place. If loading fails, leave the Constructed alone.
$rules['tbsCertificate']['subjectPublicKeyInfo'] = function (Constructed &$key) {
    try {
        $key = PublicKeyLoader::load($key->getEncoded());
    } catch (NoKeyLoadedException) {
        // slot stays as a Constructed; helper methods will throw if accessed
    }
};

return ASN1::map($decoded, Maps\Certificate::MAP, $rules);
```

Key properties:

- **Path keys are structural.** The `'*'` wildcard means "any element at this position" — used for SEQUENCE OF and SET OF. Specific names (`'extensions'`, `'subjectPublicKeyInfo'`) target named children.
- **Rules can be callbacks or nested arrays.** A callback fires when its slot is materialized and can mutate the slot's value in place (typically by replacing it with a higher-level typed object). A nested array gets attached to the corresponding child Constructed and applies in turn when that child is accessed.
- **Rules fire lazily.** A rule attached to `tbsCertificate.signature` doesn't fire unless someone reads that field. This is how 4.0 avoids decoding the entire structure upfront.
- **Rules pervade 4.0's bundled schemas.** Where 3.0's `$special` parameter was an escape hatch used for a few special cases, 4.0's `$rules` is the *normal* way phpseclib's own classes (X509, CSR, CRL, CMS) install typed-object decoding on top of the raw ASN.1.

### `map()` and static analysis

The declared return type is `Element|BaseType`, which is as precise as the signature can be: whether you get a `Constructed` or a leaf type depends entirely on the `$mapping` argument. Since `Constructed` has `toArray()`, `offsetGet()`, and the rest of the container API while `UTF8String` has none of it, psalm and phpstan report `UndefinedMethod` on essentially every non-trivial use:

```php
$cert = ASN1::map($decoded, Maps\Certificate::MAP, $rules);
$tbs  = $cert['tbsCertificate'];   // UndefinedMethod: BaseType has no offsetGet
```

In practice the second argument is almost always a hardcoded map constant, so the return type *is* determined — just not in a way an analyzer can see without evaluating the map. Three options, roughly in order of preference:

1. **Suppress `UndefinedMethod` project-wide.** This is what phpseclib itself does in its own `psalm.xml`, on the reasoning that a real unit-test suite catches genuinely undefined methods and the alternative is hundreds of asserts that can never fire. Reasonable for any codebase that uses `ASN1::map()` heavily.
2. **Annotate at the call site** with `/** @var Constructed */`. Precise, no runtime cost, but tedious to apply everywhere and easy to let drift out of sync with the map.
3. **`assert($x instanceof Constructed)`.** Only worth it when the map is genuinely dynamic — chosen at runtime, or supplied by a caller. With a fixed map it's dead weight.

Custom maps you write yourself follow the same rule: a mapping whose top-level `'type'` is `TYPE_SEQUENCE` or `TYPE_SET` yields a `Constructed`; a primitive top-level type yields the corresponding leaf object; `TYPE_CHOICE` yields a `Choice`.

### `mapChoice`

`map()` handles `ASN1::TYPE_CHOICE` differently because a CHOICE represents "one of these options." It returns a `phpseclib4\File\ASN1\Types\Choice` object — see [Choice](#choice) under the type hierarchy below.

---

## `Constructed`: the lazy SEQUENCE/SET node

```php
class Constructed implements \ArrayAccess, \Countable, \Iterator, BaseType
```

The main lazy-decoding node. Every SEQUENCE, SET, CHOICE (sort of — see Choice below), and constructed OCTET/BIT STRING in a parsed structure is a `Constructed` instance until something forces it to decode.

### Construction

You don't construct these directly. `decodeBER()` makes them. The constructor is private to the parser:

```php
public function __construct(
    private string $encoded,        // the raw interior bytes
    private int $class,             // ASN1::CLASS_*
    private int $tag,                // ASN1::TYPE_*
    private int $start,              // offset within the original input
    private int $encoded_pos,
    private int $headerlength,
    private string $rawheader        // the tag + length octets
)
```

After construction, the Constructed object is in a "decoded? no" state. The `decoded` property is null. The `mapping` is null. Until `linkMapping()` is called (by `ASN1::map()`), most array-access methods throw `InvalidStateException`. Iteration via `toArray()` works without a mapping — it just enumerates children without naming them.

### Access methods

```php
// ArrayAccess — triggers decodeCurrent() on first access
public function offsetExists(mixed $offset): bool
public function &offsetGet(mixed $offset): mixed
public function offsetSet(mixed $offset, mixed $value): void
public function offsetUnset(mixed $offset): void

// Countable
public function count(): int

// Iterator
public function rewind(): void
public function current(): mixed
public function key(): mixed
public function next(): void
public function valid(): bool

// Inspection
public function keys(): ?array              // names of children (after decoding)
public function firstKey(): mixed
public function lastKey(): mixed
public function toArray(bool $convertPrimitives = false): array
public function currentlyDecoded(): array|string   // see Cache invalidation below
public function hasMapping(): bool
```

`offsetGet` returns by reference — so `$constructed['child']['grandchild'] = $value` works the way you'd expect on a real array. The reference is to the slot in the decoded `$decoded` array.

### Autovivification on read

`offsetGet` on a missing key does *not* throw and does *not* return `null`. It creates an empty array at that slot and returns it — [autovivification](https://en.wikipedia.org/wiki/Autovivification), the same behavior PHP gives you with arrays-of-arrays when you assign into a deep path.

```php
$crl = new CRL();
var_dump($crl['zzz']);
// array(0) { }    ← not null, not an exception
```

This is deliberate: autovivification is what makes `$x509['tbsCertificate']['subject']['rdnSequence'][0][0] = [...]` work as a write path without callers having to manually pre-create every intermediate level. The same `offsetGet` that supports reads is what writes pass through, so it has to create the slot.

There's a subtlety with the encoded-bytes cache. The autovivified slot lives in the decoded array, but when the structure is compiled and re-encoded — which a wrapper class like `CRL` does on `echo` / `toString()` / `getEncoded()`, ultimately via `ASN1::encodeDER()` — empty slots get dropped:

```php
$crl = new CRL();
$tmp = $crl['zzz'];          // autovivifies 'zzz' = []
print_r($crl);                // 'zzz' visible in the live decoded view
echo $crl;                    // re-encodes; the empty slot is silently dropped
print_r($crl);                // 'zzz' is gone
```

You only really notice if you take a *reference* to an intermediate node before the compile-away happens — references bypass the compile path and show the raw decoded state, autovivified slots and all:

```php
$crl = new CRL();
$copy = &$crl['tbsCertList'];
$x = $crl['tbsCertList']['zzz'];  // autovivifies 'zzz' inside tbsCertList
print_r($copy);                    // shows 'zzz' = [] (bypasses compile)
```

#### Testing whether an optional field is present

The autovivification trap fires when you access a slot directly via `offsetGet`:

```php
$nextUpdate = $crl['tbsCertList']['nextUpdate'];   // ← if missing, autovivifies to []
```

But many PHP constructs that *look* like they're calling `offsetGet` actually go through `offsetExists` first, which doesn't autovivify (`Constructed::offsetExists` just checks `isset($this->decoded[$offset])` without creating anything). So the idiomatic PHP tests work cleanly:

```php
// All of these route through offsetExists for the missing case — no autovivification:
if (isset($crl['tbsCertList']['nextUpdate']))   { /* ... */ }
$nextUpdate = $crl['tbsCertList']['nextUpdate'] ?? null;
if (!empty($crl['tbsCertList']['nextUpdate']))   { /* ... */ }
```

`??` is the cleanest for "get the value or null." `isset()` is fine for boolean tests. `empty()` works for boolean tests too, but if the key *does* exist it'll also call `offsetGet` to test the value for falsiness, so it's only equivalent to `!isset()` in the missing-key case.

#### When you *do* need to avoid the trap

The trap fires for raw direct access without any wrapping:

```php
$nextUpdate = $crl['tbsCertList']['nextUpdate'];  // missing → autovivifies, returns []
if ($nextUpdate) { ... }                          // truthy test, but slot is now in the structure
```

If you need the value and want to handle "missing" cleanly without autovivifying, `??` is the right tool:

```php
$nextUpdate = $crl['tbsCertList']['nextUpdate'] ?? null;
```

Or for fields that have dedicated helpers (extensions, DN properties, etc.), prefer those — they know how to give you a clean present/absent answer.

### `decodeCurrent`

This is the heart of the class. Triggered by any access method. Walks `$this->encoded` against `$this->mapping`, produces a name-keyed array of children, caches the result on `$this->decoded`. Subsequent accesses skip straight to the cached array.

Important properties:

- **Each child is decoded only as far as `decodeBER` decoded it.** A child that's itself a SEQUENCE comes out as another Constructed — its interior isn't decoded yet. This is what makes the laziness recursive.
- **Rules fire here.** As each child is matched against the mapping, the corresponding rule (if any) is applied. For inline-callback rules, the callback runs on the child. For nested rules, they're attached to the child Constructed via `linkMapping()`.
- **Type mismatch throws.** If the actual tag doesn't match the mapping's expected type, `UnexpectedValueException` is thrown (unless blobs-on-bad-decodes is enabled — see [Toggling the parser](#toggling-the-parser)).
- **Indefinite-length encoding is supported.** `calculateIndefiniteLength()` walks the children until it hits the end-of-contents marker. Used for streamed encodings.

### `replaceTag` and `linkMapping`

```php
public function replaceTag(int $tag): void
public function linkMapping(array $mapping, array $rules = []): void
public function getTag(): int
```

`linkMapping` attaches the schema and rules; this is what `ASN1::map()` calls. `replaceTag` exists for implicit tagging — when a SEQUENCE arrives with a context-specific tag but should be decoded as if it had its underlying UNIVERSAL tag, the parser swaps in the universal tag here.

### `getEncoded` and the byte cache

```php
public function getEncoded(): string
public function getEncodedWithoutHeader(): string
public function getEncodedLength(): int
public function hasEncoded(): bool
public function setEncoded(string $header, string $encoded): void
public function setWrapping(string $wrapping): void
public function getEncodedWithWrapping(): string
public function hasWrapping(): bool
```

The Constructed remembers its original encoded bytes (`$this->encoded` plus `$this->rawheader`). `getEncoded()` returns header+content concatenated — byte-perfect to the original input. This is the cache that makes re-serializing a parsed cert produce identical output if nothing's been modified.

`setWrapping()` / `getEncodedWithWrapping()` handle the explicit-tagging case where the universal-tagged content needs to be wrapped in additional header bytes.

When you modify the Constructed (via `offsetSet` / `offsetUnset`), the cache is invalidated (see below). Note that `getEncoded()` does **not** re-encode — it only ever returns `$this->rawheader . $this->encoded`, so once those are zeroed by an edit it returns an empty string until something compiles the structure again. See [Cache invalidation and re-encoding](#cache-invalidation-and-re-encoding).

---

## The type hierarchy

All of the leaf types implement the `BaseType` interface (`phpseclib4\File\ASN1\Types\BaseType`). The interface is small:

```php
interface BaseType {
    public function enableForcedCache(): void;
    public function disableForcedCache(): void;
    public function isCacheForced(): bool;
    public function setWrapping(string $wrapping): void;
    public function hasWrapping(): bool;
    public function getEncodedWithWrapping(): string;
    public function hasEncoded(): bool;
    public function getEncoded(): string;
    public function getEncodedLength(): int;
    public function setEncoded(string $header, string $encoded): void;
    public function hasTypeID(): bool;
}
```

The cache methods tie into the [`useEncodedCache`](#toggling-the-parser) toggle. By default `ASN1::encodeDER()` reuses a node's stored byte-for-byte encoding; `ignoreEncodedCache()` turns that off globally; and `enableForcedCache()` pins an individual node so its cached bytes are reused even when the global toggle is off (`isCacheForced()` reports the pin). The reuse is also gated on `hasEncoded()` — an invalidated node reports `false`, which is what lets `encodeDER` re-encode exactly the modified path while reusing untouched subtrees.

Most leaf types use the `Common` trait, which implements all of this in terms of a `$metadata` array. The exceptions are `Constructed`, `Choice`, `BitString`, and `Integer`, which implement the interface directly (and store the metadata as named properties for performance or for compatibility with the underlying parent class).

### Leaf types

| Class | ASN.1 type | TYPE constant | Backed by |
| --- | --- | --- | --- |
| `Boolean` | BOOLEAN | 1 | `bool $value` |
| `Integer` | INTEGER | 2 | extends `BigInteger` (also has `string $mappedValue` for enum-like INTEGERs) |
| `BitString` | BIT STRING | 3 | `string $value` (raw bytes) + `array $mappedValue` for named-bit bit strings |
| `OctetString` | OCTET STRING | 4 | `string $value` |
| `ExplicitNull` | NULL | 5 | (no value — exists to be distinguishable from PHP `null`) |
| `OID` | OBJECT IDENTIFIER | 6 | `string|Element $value` (dotted form or raw, lazily decoded) |
| `UTF8String` | UTF8String | 12 | `string $value` (`BaseString` subclass) |
| `NumericString` | NumericString | 18 | `BaseString` |
| `PrintableString` | PrintableString | 19 | `BaseString` |
| `TeletexString` | TeletexString | 20 | `BaseString` |
| `VideotexString` | VideotexString | 21 | `BaseString` |
| `IA5String` | IA5String | 22 | `BaseString` |
| `UTCTime` | UTCTime | 23 | extends `\DateTime` |
| `GeneralizedTime` | GeneralizedTime | 24 | extends `\DateTime` |
| `GraphicString` | GraphicString | 25 | `BaseString` |
| `VisibleString` | VisibleString | 26 | `BaseString` |
| `GeneralString` | GeneralString | 27 | `BaseString` |
| `UniversalString` | UniversalString | 28 | `BaseString` |
| `BMPString` | BMPString | 30 | `BaseString` |

Both `UTCTime` and `GeneralizedTime` are `\DateTime` subclasses, so `instanceof \DateTimeInterface` works on either. They differ only in their TYPE constant and ASN.1 encoding.

### `BaseString`

All string types other than BitString and OctetString share the `BaseString` abstract base class, which adds cross-type conversion methods:

```php
public function toUTF8String(): self
public function toBMPString(): self
public function toUniversalString(): self
public function toPrintableString(): self
public function toTeletexString(): self
public function toIA5String(): self
public function toVisibleString(): self
public function isConvertable(): bool
```

Conversion is character-size-based and *lazy* (no encoding tables, just bit-width handling). Each `BaseString` subclass declares a `SIZE` constant indicating bytes per character: `UTF8String` is 0 (variable-width, special-cased), `IA5String` / `PrintableString` / `VisibleString` / `TeletexString` are 1, `BMPString` is 2, `UniversalString` is 4. `GraphicString` and `VideotexString` don't declare a SIZE — they're not convertible (calling a conversion method on them throws `BadMethodCallException`).

Conversion preserves the literal byte values; it doesn't do real Unicode normalization. For most ASN.1 string handling this is what you want (you're working at the byte level, not the human-readable text level).

### `BitString` is special

`BitString` is also `ArrayAccess` / `Countable` / `Iterator` because of named-bit BIT STRINGs like `keyUsage`. The bit positions get string names from the mapping (`'digitalSignature'`, `'keyCertSign'`, etc.), and you address them by name:

```php
if ($keyUsage->contains('keyCertSign')) { /* ... */ }
```

`$bitString->mappedValue` is the named-bit array; `$bitString->value` is the raw bytes. Trying to use the ArrayAccess interface before `$mappedValue` is populated throws `InvalidStateException` — bare BIT STRINGs without a mapping don't have named bits.

### `Choice`

```php
class Choice implements \ArrayAccess, \Countable, \Iterator, BaseType
```

A `Choice` represents "one of these alternatives." It holds a single `(index, value)` pair: `$choice->index` is the name of the chosen alternative, `$choice->value` is the value. ArrayAccess works through the index — `$choice['indexName']` returns the value if `'indexName'` matches `$choice->index`, throws `UnexpectedValueException` otherwise.

`Choice` propagates `$rules` down to its value when accessed, the same way `Constructed` does. The cache-invalidation chain via `$parent` also runs through Choices.

This is used heavily for CHOICE schemas like `GeneralName` (which can be a `dNSName`, an `iPAddress`, a `directoryName`, etc.) — each value of a `GeneralName` is a `Choice` whose `index` tells you which alternative was chosen.

### `Element`

```php
class Element {
    public function __construct(public string $value) {}
}
```

The `ASN.1 ANY` mapping returns an `Element` — opaque raw bytes. Use `$element->getEncoded()` or `(string) $element` to get the bytes back. `Element` instances bypass normal encoding when serializing (their bytes are written through unchanged).

Two sentinel subclasses:

- **`ExcessivelyDeepData`** — substituted in place of content that exceeded the recursion depth limit. Has the raw bytes that *would* have been decoded if depth weren't capped.
- **`MalformedData`** — substituted in place of content that failed to decode when "blobs-on-bad-decodes" mode is enabled. Has the raw bytes of the failed element.

Both subclasses exist purely as type markers — `instanceof ExcessivelyDeepData` / `instanceof MalformedData` lets your code distinguish these from normal `Element` results.

---

## Cache invalidation and re-encoding

When you modify a parsed structure (e.g., `$x509['tbsCertificate']['serialNumber'] = new BigInteger(42)`), the cached bytes are no longer accurate, so phpseclib invalidates the cache up the tree. `Constructed::invalidateCache()` zeroes the node's own `rawheader`, `encoded`, and `wrapping`, then calls `invalidateCache()` on its `$parent` (a `Constructed` or `Choice`), and the chain continues to the root.

**Invalidation is all `Constructed` does — it never recompiles.** `getEncoded()` is literally `return $this->rawheader . $this->encoded;`, with no re-encode path, so on a node whose cache was just invalidated it returns an empty string. Regeneration is the job of the wrapper classes (`X509`, `CSR`, `CRL`, `PFX`, …): their `offsetGet()` calls `compile()`, which round-trips the structure through `load("$this")` — ultimately `ASN1::encodeDER($structure, $map)` — to rebuild the bytes. So `echo $x509` yields a correctly re-encoded cert because the `X509` wrapper recompiled on the way to `__toString`, not because the underlying `Constructed` regenerated anything.

The split exists because `Constructed` doesn't *know how* to re-encode in the general case. It holds its mapping, but mappings are deliberately shallow about content they treat as opaque: in `Maps\Extension::MAP`, an extension's `extnValue` is just an `OCTET STRING`. The per-OID knowledge of how to serialize a parsed `basicConstraints` or `subjectAltName` back into those bytes lives in `X509`'s static extension registry (`registerExtension()` / `self::$extensions`), reachable only through `X509::mapOutExtensions()` on the compile path. Add or change an extension and the `Constructed` has the typed value sitting in its decoded array but no way to turn it back into the octet string — that machinery is in the wrapper, not in the mapping or in `Constructed`. So for that case `Constructed` couldn't recompile even if it tried; invalidate-and-defer is the only correct option.

**The reference trap this produces:** if you take a reference to an interior node and modify *through it*, `getEncoded()` on that reference returns `0`, because it's a bare `Constructed` that never recompiles. Re-fetch through the wrapper to trigger `offsetGet` → `compile()`, after which the reference points at regenerated bytes:

```php
$x509 = X509::load($pem);
$cert = &$x509['tbsCertificate'];
echo strlen($cert->getEncoded());                   // 654
$cert['serialNumber'] = new BigInteger('deadbeef', 16);
echo strlen($cert->getEncoded());                   // 0   — Constructed invalidated, didn't recompile
echo strlen($x509['tbsCertificate']->getEncoded()); // 643 — X509::offsetGet recompiled
$cert = &$x509['tbsCertificate'];
echo strlen($cert->getEncoded());                   // 643 — reference now points at the recompiled node
```

### Writes are not validated

`offsetSet` stores whatever you give it — it does no shape or type checking:

```php
$x509 = X509::load($pem);
$x509['tbsCertificate'] = 'hello, world';   // accepted silently
```

The assignment succeeds. The mistake doesn't surface until the structure is re-encoded — `echo`, `getEncoded()`, `toString()` — and when it does, it's a plain PHP `TypeError` thrown from inside the encoder (`X509::toString()` dereferences `$this->cert['tbsCertificate']['subjectPublicKeyInfo']` as its first step, which indexes the string with a string key), **not** a `phpseclib4\Exception\*`. So a `catch (\phpseclib4\Exception\BaseException $e)` around your phpseclib calls won't catch it, and the stack trace points at the encoder rather than at the line that made the bad write.

This is the write-side companion to "errors surface at access time, not at load time." A `TypeError` originating in `toString()` / `ASN1::encodeDER()` usually means an earlier ArrayAccess write put the wrong shape into a slot — look upstream from the throw site, not at it.

Where they exist, prefer the high-level classes' helper setters (`X509::setSubjectDN()`, `setExtension()`, `makeCA()`, …) over direct structural writes: they normalize varied input formats and save you assembling the boilerplate shape by hand. They normalize *format*, though, not *structure* for every field — handing a scalar to a field that expects an array can still slip through to the same deferred failure. Reserve direct `$node['...'] = ...` writes for cases where you know the exact expected shape.

### Disabling invalidation

```php
ASN1::disableCacheInvalidation();  // subsequent modifications won't invalidate
ASN1::enableCacheInvalidation();   // re-enable (the default)
```

Disabling is useful when phpseclib's own code is making "logical" modifications that shouldn't actually change the encoded bytes — for instance, when an extension's OCTET STRING value gets *re-parsed* into a structured typed object, that's an internal representation change, not a value change, and shouldn't dirty the cache.

### `currentlyDecoded()`

```php
public function currentlyDecoded(): array|string
```

Returns a representation of the Constructed showing only the parts that have actually been decoded so far. Decoded children appear with their values; un-touched Constructeds appear as `'...'`. Useful for debugging the laziness — you can see exactly how much of a structure your code has materialized.

```php
$x509 = X509::load($pem);
echo print_r($x509['tbsCertificate']->currentlyDecoded(), true);
// At this point, almost everything is '...' because nothing's been read.

$x509->getSubjectDN();
echo print_r($x509['tbsCertificate']->currentlyDecoded(), true);
// Now 'subject' is decoded but most other fields are still '...'.
```

---

## Security limits

### Recursion depth

```php
public static function setRecursionDepth(int $depth): void
public static function getRecursionDepth(): int
```

Default: 128. When parsing a constructed type (especially nested BIT STRINGs and OCTET STRINGs that allow recursive constructed encoding), the depth is tracked. Exceeding the limit throws `ExcessivelyDeepDataException`. In blobs-on-bad-decodes mode, the offending subtree is replaced with an `ExcessivelyDeepData` instance and parsing continues.

128 levels is far more than RFC-compliant ASN.1 should ever produce. The limit exists to bound parser CPU/memory on hostile input.

### OID size

OIDs are capped at **128 bytes** of encoded length. `ASN1::decodeOID()` throws `ResourceLimitException` if an encoded OID exceeds this. The limit matches Java's `ObjectIdentifier`, and it's the relevant defense against the 4096+-byte OID DoS that motivated this redesign — a giant OID can encode a huge BigInteger whose component arithmetic balloons CPU/memory during decode.

The 128-byte limit is generous: real OIDs are almost always under 30 bytes. If you have a legitimate use case for longer OIDs, there's currently no way to raise the limit without patching the source.

### Blobs on bad decodes

```php
public static function enableBlobsOnBadDecodes(): void
public static function disableBlobsOnBadDecodes(): void
public static function isBlobsOnBadDecodesEnabled(): bool
```

When enabled, decode errors (malformed bytes, excessive depth, schema mismatch) no longer throw — they replace the offending sub-element with a `MalformedData` or `ExcessivelyDeepData` Element and let parsing continue. This lets you load malformed certs to inspect them rather than failing outright.

Off by default (errors throw). Turn on at your own risk; mostly useful for forensic / debugging tools.

---

## Toggling the parser

A few static toggles control parser behavior:

```php
ASN1::useEncodedCache();         // reuse cached encoding when re-encoding (default)
ASN1::ignoreEncodedCache();      // re-encode from scratch every time

ASN1::enableCacheInvalidation();   // dirty parents on modification (default)
ASN1::disableCacheInvalidation();  // don't propagate (advanced)

ASN1::enableBlobsOnBadDecodes();   // replace failures with MalformedData/ExcessivelyDeepData
ASN1::disableBlobsOnBadDecodes();  // throw on failure (default)

ASN1::setRecursionDepth(128);      // change the recursion-depth limit
ASN1::setTimeFormat('Y-m-d');      // change the format for `__toString` on UTCTime/GeneralizedTime
```

All of these are process-wide static state. Set them at startup if needed; expect them to remain set for the duration of the request.

---

## The `Signable` interface

```php
interface Signable {
    public function getSignableSection(): string;
    public function setSignature(string $signature): void;
    public function identifySignatureAlgorithm(PublicKey $key): void;
    public function copySigningX509Attributes(X509 $x509): void;
}
```

This is the contract a class implements to be signable by `$privateKey->sign(...)` or `$pfx->sign(...)`. Five classes in 4.0 implement it: `X509`, `CSR`, `CRL`, `CMS\SignedData`, `CMS\SignedData\Signer`.

### What each method does

- **`getSignableSection(): string`** — returns the bytes that need to be hashed and signed. For X509 this is the encoded `tbsCertificate`; for CSR it's `certificationRequestInfo`; for CRL it's `tbsCertList`. The Signable knows its own "to be signed" structure.

- **`setSignature(string $signature): void`** — installs the resulting signature bytes back into the object's signature slot. For X509 this fills the `signature` BIT STRING. After this returns, `$x509->getEncoded()` produces the full signed PEM.

- **`identifySignatureAlgorithm(PublicKey $key): void`** — installs the algorithm OID and parameters on the Signable. Implementations typically use the `phpseclib4\File\Common\Traits\ASN1Signature` trait, which maps a PublicKey instance to the correct OID (e.g., `id-RSASSA-PSS`, `sha256WithRSAEncryption`, `id-Ed25519`, `ecdsa-with-SHA256`).

- **`copySigningX509Attributes(X509 $x509): void`** — used by the PFX-signs-X509 path. The CA cert in the PFX is passed in here, and the Signable copies the CA cert's subject DN as its own issuer DN, plus the CA cert's subject key identifier as its own authority key identifier (if applicable). A bare-key sign call skips this — the key has no CA cert to copy from.

### The full sign sequence

When you call `$privKey->sign($x509)`:

1. (PFX only:) `$x509->copySigningX509Attributes($caCert)` runs first, populating issuer DN and AKI from the CA cert.
2. `$x509->identifySignatureAlgorithm($privKey->getPublicKey())` installs the algorithm OID and parameters.
3. `$bytes = $x509->getSignableSection()` extracts the bytes to sign.
4. `$sig = $privKey->sign($bytes)` (the string-form sign, the same overload used for raw byte signing) produces the raw signature.
5. `$x509->setSignature($sig)` installs the signature back into the X509 object.
6. The whole expression returns `$sig` (the raw signature bytes).

This is why `sign()` returns the signature bytes *and* installs them as a side effect — both outputs are useful, depending on what you're doing. And it's why re-signing is just calling sign again: there's no special "replace existing signature" logic; `setSignature` overwrites whatever's there.

### Implementing Signable on a new type

If you have a custom ASN.1 structure that needs signing, implementing `Signable` is the way. You need:

1. A `tbs*` (to-be-signed) sub-structure that `getSignableSection()` can serialize.
2. A signature slot that `setSignature()` can write into.
3. An algorithm-identifier slot that `identifySignatureAlgorithm()` can write into. Pulling in the `ASN1Signature` trait gives you the OID-mapping logic for free.
4. A no-op (or appropriate-fields) implementation of `copySigningX509Attributes()` — if your structure has no concept of an issuer, this is just `public function copySigningX509Attributes(X509 $x509): void {}`.

With the interface implemented, `$privKey->sign($yourObject)` and `$pfx->sign($yourObject)` work the same way they do for X509.

---

## Custom OIDs

```php
public static function loadOIDs(array|string $oids): bool
public static function getOIDFromName(string $name): string
public static function getNameFromOID(string $oid): string
```

phpseclib ships with the full set of OIDs from RFC 5280 and related standards pre-registered. For custom OIDs:

```php
ASN1::loadOIDs([
    'myCustomExtension' => '1.2.3.4.5.6.7',
]);
```

After this, `'myCustomExtension'` and `'1.2.3.4.5.6.7'` are interchangeable everywhere phpseclib accepts an OID. `OID::__toString()` will return the symbolic name; matching against either form works.

Registration is process-wide static state. Load custom OIDs once at startup.

---

## Worked example: parsing a custom SEQUENCE

Suppose you have a custom binary format defined as:

```asn1
MyMessage ::= SEQUENCE {
    version    INTEGER,
    payload    OCTET STRING,
    timestamp  GeneralizedTime OPTIONAL
}
```

The mapping is:

```php
use phpseclib4\File\ASN1;

$mapping = [
    'type' => ASN1::TYPE_SEQUENCE,
    'children' => [
        'version'   => ['type' => ASN1::TYPE_INTEGER],
        'payload'   => ['type' => ASN1::TYPE_OCTET_STRING],
        'timestamp' => ['type' => ASN1::TYPE_GENERALIZED_TIME, 'optional' => true],
    ],
];
```

Decoding looks like:

```php
$decoded = ASN1::decodeBER($derBytes);
$msg = ASN1::map($decoded, $mapping);

echo $msg['version'];                    // Integer
echo bin2hex((string) $msg['payload']);  // OctetString bytes as hex
if (isset($msg['timestamp'])) {
    echo $msg['timestamp']->format('c'); // GeneralizedTime / DateTimeInterface
}
```

To re-encode after modification, call `ASN1::encodeDER()` with the mapping. `$msg` here is a bare `Constructed` (no wrapper class loaded it), so — per [Cache invalidation and re-encoding](#cache-invalidation-and-re-encoding) — `$msg->getEncoded()` would return an empty string after the edit, not the new bytes. `Constructed` doesn't recompile itself; you supply the mapping and re-encode explicitly:

```php
$msg['version'] = new \phpseclib4\Math\BigInteger(2);
$newBytes = ASN1::encodeDER($msg, $mapping);
```

To add a rule that decodes payload as a nested structure when accessed:

```php
use phpseclib4\File\ASN1\Constructed;

$payloadMapping = [
    'type' => ASN1::TYPE_SEQUENCE,
    'children' => [/* ... */],
];

$rules = [
    'payload' => function (\phpseclib4\File\ASN1\Types\OctetString &$payload) use ($payloadMapping) {
        $decoded = ASN1::decodeBER($payload->value);
        $payload = ASN1::map($decoded, $payloadMapping);
    },
];

$msg = ASN1::map($decoded, $mapping, $rules);
$inner = $msg['payload'];  // triggers the rule, replaces OctetString with the mapped inner SEQUENCE
```

To make `MyMessage` signable, implement the `Signable` interface on a wrapper class and use the `ASN1Signature` trait for the algorithm-identification helper. The signature slot can either be in the existing schema (add a `signatureAlgorithm` and `signature` field at the SEQUENCE level) or in an outer wrapper that contains both the to-be-signed `MyMessage` and the algorithm/signature alongside. Look at `X509::getSignableSection()` and `CSR::getSignableSection()` in the source for two slightly-different idiomatic shapes.
