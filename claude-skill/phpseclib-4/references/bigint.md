# `phpseclib4\Math\BigInteger`

Reference for `phpseclib4\Math\BigInteger` — arbitrary-precision integer arithmetic, used everywhere inside phpseclib for RSA / DSA / EC math.

## When to reach for this at all

`BigInteger` is mostly an *internal* class. phpseclib uses it for everything that doesn't fit in a PHP `int` — RSA moduli, EC coordinates, DSA group parameters, prime generation. You'll typically encounter one when a phpseclib API hands one back — for example, when you reach into the parsed structure of a key to inspect its raw components.

For *new* code that doesn't involve phpseclib types, PHP's built-in extensions are almost always the right answer:

- **`gmp_*` functions** if the `gmp` extension is available. Fastest, with the cleanest API for arbitrary-precision math.
- **`bcmath`** if `gmp` isn't around and you don't need bitwise operations.

Reach for `phpseclib4\Math\BigInteger` when:

- You're working with values phpseclib already handed you as `BigInteger` instances (most common case).
- You need a single interface that works regardless of which extension is installed — `BigInteger` auto-picks GMP, BCMath, or pure-PHP without your code needing to care.
- You need an operation that's awkward in raw GMP/BCMath — `randomRangePrime()`, `extendedGCD()` returning a labeled array, `createRecurringModuloFunction()`, the bit-precision-aware rotate operations.
- You're writing code that ships to environments without GMP and you don't want a hard dependency.

For straight `a + b` or `a mod n` where you already know GMP is installed, `gmp_add($a, $b)` is faster.

## Contents

- [Construction](#construction)
- [Output](#output)
- [Arithmetic](#arithmetic)
- [Division and the "common residue"](#division-and-the-common-residue)
- [Modular operations](#modular-operations)
- [Comparison](#comparison)
- [Bitwise operations](#bitwise-operations)
- [Precision and bit-width-aware operations](#precision-and-bit-width-aware-operations)
- [Length, inspection, primality](#length-inspection-primality)
- [Random number generation](#random-number-generation)
- [Static helpers: `min`, `max`, `minMaxBits`, `scan1divide`](#static-helpers-min-max-minmaxbits-scan1divide)
- [Serialization](#serialization)
- [Immutability](#immutability)
- [Engines](#engines)
- [Exceptions](#exceptions)

---

## Construction

```php
use phpseclib4\Math\BigInteger;

$a = new BigInteger(42);              // from int
$b = new BigInteger('42');            // from base-10 string
$c = new BigInteger('2a', 16);        // base-16
$d = new BigInteger('101010', 2);     // base-2
$e = new BigInteger("\x2A", 256);     // base-256 (raw bytes)
$f = new BigInteger();                // zero
```

The second argument is the base. Supported bases:

| Base | Meaning |
| --- | --- |
| `2` | Bit string (`'1010'`) |
| `10` | Decimal string or PHP int — the default |
| `16` | Hex string, no `0x` prefix (`'deadbeef'`) |
| `256` | Raw bytes — for parsing wire-format integers |

**Negative bases** mean "interpret as two's complement". `-256` parses bytes where the high bit indicates sign; `-16` does the same for hex. Use these when reading values from binary protocols that store signed integers in two's complement.

```php
$signed = new BigInteger("\x80", -256);     // -128
$signed = new BigInteger("\xFF", -256);     // -1
```

One quirk: `-10` is treated as `10`. Decimal strings carry their sign as a leading `-`, so the negative base is meaningless there.

## Output

```php
echo $n->toString();                 // base-10 string
echo (string) $n;                    // same — __toString returns toString
echo $n->toHex();                    // hex string, no prefix
echo $n->toBits();                   // bit string
echo $n->toBytes();                  // raw bytes (base 256)

print_r($n);                         // formatted debug view via __debugInfo
```

For negative numbers, the unsigned representations write them as positive (so `$neg->toBytes()` of -1 gives `"\x01"`, not `"\xFF"`). To get the two's-complement form, pass `true`:

```php
$n = new BigInteger(-1);
$n->toBytes();          // "\x01"      (just the magnitude)
$n->toBytes(true);      // "\xFF"      (two's complement)
$n->toHex(true);        // 'ff'
$n->toBits(true);       // '11111111'
```

The two's-complement form is what you want for wire protocols (ASN.1 INTEGER, for instance, uses it).

## Arithmetic

```php
$sum  = $a->add($b);
$diff = $a->subtract($b);
$prod = $a->multiply($b);
$abs  = $a->abs();
$neg  = $a->negate();
$pow  = $a->pow($b);                 // $a ** $b
$root = $a->root($n);                // nth root, defaults to 2 (square root)
```

All return new `BigInteger` instances; the originals are untouched. See [Immutability](#immutability).

## Division and the "common residue"

```php
[$quotient, $remainder] = $a->divide($b);
```

`divide()` returns a two-element array — the quotient and the **common residue**, not the bare remainder. The distinction matters for negative numbers:

- **Bare remainder** (what PHP's `%` operator gives): same sign as the dividend. `-7 % 3 == -1` in PHP.
- **Common residue** (what `divide()` returns): always non-negative — the first positive number congruent to the remainder. `(-7) divided by 3` gives quotient `-3` and common residue `2`, because `-3 * 3 + 2 == -7`.

If both operands are non-negative the two definitions coincide, so most code never notices the difference. The cases where it matters are typically modular arithmetic — where you want the canonical non-negative representative anyway — so the chosen behavior is usually what you want.

## Modular operations

```php
$result = $a->powMod($e, $n);              // ($a ** $e) mod $n
$result = $a->modPow($e, $n);              // alias for powMod
$inv    = $a->modInverse($n);              // returns null if no inverse exists
$gcd    = $a->gcd($n);                     // greatest common divisor
$ext    = $a->extendedGCD($n);             // ['gcd' => ..., 'x' => ..., 'y' => ...] s.t. a*x + n*y == gcd
```

`powMod` / `modPow` are the workhorse for RSA-style operations. The two names are aliases — pick whichever reads better in context.

`modInverse($n)` returns `null` (not `false`) if `$a` has no inverse modulo `$n` — i.e., if `gcd($a, $n) != 1`. Guard with a null check:

```php
$inv = $a->modInverse($n);
if ($inv === null) {
    // $a has no inverse mod $n
}
```

`extendedGCD` returns a labeled array `['gcd' => $g, 'x' => $x, 'y' => $y]` such that `$a * $x + $n * $y == $g`. Useful when you need not just the GCD but the Bézout coefficients (CRT setup, for example).

### Recurring modulo

If you're going to compute `$x mod $n` many times with the same `$n`, build a closure once:

```php
$reducer = $n->createRecurringModuloFunction();

$r1 = $reducer($x1);
$r2 = $reducer($x2);
$r3 = $reducer($x3);
```

Faster than constructing a fresh modulo context each time, particularly on the GMP engine where the closure captures the modulus by value.

## Comparison

```php
$cmp = $a->compare($b);              // negative, zero, or positive
$eq  = $a->equals($b);               // bool
$in  = $a->between($min, $max);      // bool, inclusive
```

**Common trap with `compare()`**: `!$a->compare($b)` means `$a == $b`, not `$a != $b`. The negation reads backwards because `compare` returns `0` for equality and PHP treats `0` as falsy:

```php
if (!$a->compare($b)) {              // means "equal"
    // ...
}

if ($a->equals($b)) {                 // clearer
    // ...
}
```

Use `equals()` for equality checks and reserve `compare()` for cases where you specifically need the three-way result (sorting, threshold logic).

## Bitwise operations

```php
$a->bitwise_and($b);
$a->bitwise_or($b);
$a->bitwise_xor($b);
$a->bitwise_not();
$a->bitwise_leftShift($n);            // $a * 2**$n
$a->bitwise_rightShift($n);           // $a / 2**$n
$a->bitwise_leftRotate($n);
$a->bitwise_rightRotate($n);

$a->bitwise_split($bits);             // array of $bits-wide chunks
```

`bitwise_split($n)` chops the integer into `$n`-bit chunks, returning them as an array of `BigInteger` instances **in most-significant-first order**. Useful for serializing large integers in fixed-width pieces.

```php
$chunks = $a->bitwise_split(64);     // 64-bit chunks, big-endian
```

A zero `BigInteger` splits to an empty array, not `[0]`. `$bits` must be at least 1, else `InvalidArgumentException` (despite the exception message, which says "Offset must be greater than 1" — the actual check is `>= 1`).

## Precision and bit-width-aware operations

`bitwise_not`, `bitwise_leftRotate`, `bitwise_rightRotate`, and (sometimes) `bitwise_leftShift` are ambiguous without a defined bit width — what does "rotate left by 1" mean for an arbitrary-precision integer? You have to tell `BigInteger` what width to assume.

```php
$a = new BigInteger(1);
$a->setPrecision(8);

$a->bitwise_leftRotate(1)->toBits();   // '00000010'  (1 rotated left within 8 bits)
$a->bitwise_not()->toBits();           // '11111110'  (NOT within 8 bits)
```

Without precision set, `bitwise_not` and the rotates have no fixed width to work against; they fall back to a value-dependent width that's rarely what you want.

```php
$a->setPrecision(256);                 // operate as if a 256-bit integer
$a->getPrecision();                    // returns the bit width, or -1 if unset
$a->setPrecision(0);                   // disable — getPrecision() returns -1
```

`setPrecision` also masks the current value to fit — if you set precision smaller than the current bit length, the high bits get dropped.

## Length, inspection, primality

```php
$a->getLength();                      // size in bits
$a->getLengthInBytes();               // size in bytes
$a->isOdd();                          // bool
$a->isNegative();                     // bool
$a->testBit($n);                      // is bit $n set?
$a->isPrime();                        // Miller–Rabin primality test
$a->isPrime(20);                      // explicit number of rounds
```

`isPrime()` runs Miller–Rabin. With default rounds, the error rate is `2^-80` — fine for cryptographic use. The `$t` parameter lets you tune the round count, mainly useful when you want to distribute primality testing across multiple requests (high-`$t` runs can be done piecemeal). Primes larger than 8196 bits throw `ResourceLimitException`.

## Random number generation

```php
$r = BigInteger::random(2048);                       // 2048-bit random
$p = BigInteger::randomPrime(2048);                  // 2048-bit random prime
$r = BigInteger::randomRange($min, $max);            // inclusive
$p = BigInteger::randomRangePrime($min, $max);       // returns null if no prime in range
```

All four are static. `$size` is in **bits**, not bytes.

```php
$nonce = BigInteger::random(128);     // 128-bit (16-byte) random integer
```

`randomRangePrime` returns `null` (not `false`) when there's no prime in `[$min, $max]`. Generation of primes larger than 8196 bits throws `ResourceLimitException`.

The underlying randomness comes from PHP's CSPRNG (`random_bytes()`), not from a phpseclib-internal source.

## Static helpers: `min`, `max`, `minMaxBits`, `scan1divide`

```php
BigInteger::min($a, $b, $c);                         // smallest
BigInteger::max($a, $b, $c);                         // largest
['min' => $lo, 'max' => $hi] = BigInteger::minMaxBits(256);   // 2^255 and 2^256 - 1

$shift = BigInteger::scan1divide($r);                // see below
```

`minMaxBits($n)` returns the smallest and largest `$n`-bit positive integer. Useful for setting up ranges for `randomRange`.

`scan1divide($r)` finds the lowest set bit in `$r`, right-shifts `$r` by that many bits **in place**, and returns the shift amount. It's the one method on `BigInteger` that mutates its argument — used internally by Miller–Rabin to factor out 2s. You probably won't call it directly.

## Serialization

```php
$json = json_encode($a);              // {"hex":"...","precision":256}
$ser  = serialize($a);                // PHP serialization
$back = unserialize($ser);            // round-trip
```

`BigInteger` implements `\JsonSerializable` and the `__serialize` / `__unserialize` magic methods. Both encode as `{hex: ..., precision: ...}` (precision only present if set). The hex is two's-complement, so signed values round-trip correctly.

You probably don't want to JSON-encode a `BigInteger` for an external API — receivers won't know what to do with `{hex: "..."}`. Convert to whatever shape the protocol wants (`toString()` for decimal, `toHex()` for hex, `toBytes()` for raw) yourself.

## Immutability

Every operation on `BigInteger` returns a new instance. There's no `addInPlace` or `incrementBy`. The only methods that touch internal state are `setPrecision` and the static `scan1divide`.

```php
$a = new BigInteger(5);
$b = $a->add(new BigInteger(3));      // $b is 8, $a is still 5
```

Cloning is supported via `__clone`. Most code doesn't need to clone explicitly — since operations return new instances, you only need an explicit clone when you intend to mutate (i.e., `setPrecision`) without affecting the original.

## Engines

`BigInteger` auto-selects the fastest available implementation on first use. You normally don't need to think about this. The selection order in current phpseclib 4.0:

1. **GMP** — if the `gmp` extension is loaded. Fastest.
2. The fastest of the pure-PHP engines that's available, paired with **OpenSSL** as a modexp accelerator when the `openssl` extension is loaded. The "fastest pure-PHP engine" depends on PHP version:
   - PHP 8.4+: **BCMath** ? **PHP64** ? **PHP32**
   - Earlier: **PHP64** ? **BCMath** ? **PHP32**
   (BCMath got significantly faster in 8.4, hence the swap.)
3. If OpenSSL isn't usable, the same pure-PHP engines paired with their built-in `DefaultEngine` for modexp.

The PHP64 engine requires 64-bit PHP (`PHP_INT_SIZE >= 8`); PHP32 works on 32-bit builds. Both refuse to load with JIT enabled on Windows.

Each "main engine" pairs with a "modexp engine" for modular exponentiation:

- **GMP** uses its built-in `DefaultEngine` (which calls `gmp_powm`).
- The pure-PHP engines (PHP64, PHP32, BCMath) prefer **OpenSSL** as a modexp accelerator when available — it does big-integer modular exponentiation much faster than pure PHP. The accelerator only kicks in for moduli between 31 and 16384 bits; outside that window, the engine falls back to its `DefaultEngine`.

To inspect or override the selection:

```php
[$main, $modexp] = BigInteger::getEngine();   // e.g. ['GMP', 'DefaultEngine']

BigInteger::setEngine('PHP64', ['DefaultEngine']);  // force pure-PHP, no OpenSSL accel
BigInteger::setEngine('GMP');                       // back to GMP
```

The second argument to `setEngine` is a list of modexp engines to try in order — the first that loads wins. Omit it to get just `['DefaultEngine']`.

`setEngine` throws `BadConfigurationException` if the requested main engine isn't installed or available, or if none of the requested modexp engines load.

**When you'd actually call `setEngine`:** unit tests that need to exercise a specific engine path, performance comparisons, or working around a specific bug in one engine. For production code, leave the auto-selection alone — it picks the right thing.

If you're on Windows with JIT enabled and have neither GMP nor BCMath, none of the engines load (the constructor throws `BadConfigurationException` with a message telling you to install GMP/BCMath or disable JIT). This is the one configuration where you have to do something.

## Exceptions

All under `phpseclib4\Exception\`:

- `BadConfigurationException` — no usable engine found, or `setEngine()` called with an invalid/unavailable engine.
- `ResourceLimitException` — primality testing or random-prime generation requested for a number larger than 8196 bits.
- `InvalidArgumentException` — `bitwise_split()` with offset less than 1.

All extend PHP's `\RuntimeException` and implement `phpseclib4\Exception\BaseException`.