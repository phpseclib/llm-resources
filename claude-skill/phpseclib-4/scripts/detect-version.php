<?php
/**
 * detect-version.php — phpseclib codebase scanner
 *
 * Walks a PHP file or directory tree and reports every reference to phpseclib,
 * classified by version. Produces a checklist for migration planning, or a
 * recommendation to use the compat shim instead.
 *
 * Usage:
 *   php detect-version.php <path>              # human-readable report
 *   php detect-version.php <path> --json       # machine-readable JSON
 *   php detect-version.php <path> --summary    # one-line summary only
 *   php detect-version.php <path> --quiet      # exit code only, no output
 *
 * Exit codes:
 *   0   No phpseclib usage detected
 *   1   Phpseclib usage found, but all 4.0 — no migration needed
 *   2   Phpseclib usage found, mixed or pre-4.0 — migration or shim warranted
 *   3   Invalid arguments or unreadable path
 *
 * This script intentionally has no dependencies. It uses regex pattern matching
 * rather than a real PHP parser. That means it occasionally flags strings or
 * comments that look like phpseclib code, but the trade-off — zero install
 * friction — is worth it.
 *
 * Part of phpseclib/llm-resources. https://github.com/phpseclib/llm-resources
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// CLI parsing
// -----------------------------------------------------------------------------

$argv0 = $argv[0];
$path = null;
$mode = 'human'; // 'human' | 'json' | 'summary' | 'quiet'

for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg === '--json') {
        $mode = 'json';
    } elseif ($arg === '--summary') {
        $mode = 'summary';
    } elseif ($arg === '--quiet' || $arg === '-q') {
        $mode = 'quiet';
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1800));
        exit(0);
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown option: $arg\n");
        fwrite(STDERR, "Usage: php $argv0 <path> [--json|--summary|--quiet]\n");
        exit(3);
    } elseif ($path === null) {
        $path = $arg;
    } else {
        fwrite(STDERR, "Multiple paths not supported. Pass a single file or directory.\n");
        exit(3);
    }
}

if ($path === null) {
    fwrite(STDERR, "Usage: php $argv0 <path> [--json|--summary|--quiet]\n");
    fwrite(STDERR, "Pass a PHP file or a directory to scan.\n");
    exit(3);
}

if (!file_exists($path)) {
    fwrite(STDERR, "Path not found: $path\n");
    exit(3);
}

// -----------------------------------------------------------------------------
// Detection patterns
// -----------------------------------------------------------------------------

/**
 * Each pattern has:
 *   regex      — PCRE pattern to match in a single line
 *   version    — '1.0', '2.0', '3.0', '4.0', or 'ambiguous'
 *   category   — 'namespace', 'legacy-class', 'method', 'arg-order',
 *                'removed-api', 'return-check', 'extension', 'shim'
 *   severity   — 'info', 'warn', 'error'
 *   message    — short human-readable description
 *   suggestion — what to do about it (optional)
 *
 * Order matters for first-match: more specific patterns first.
 */
$patterns = [

    // ---- Namespace imports (strongest signal) ----
    [
        'regex' => '/\buse\s+phpseclib4\\\\[A-Za-z0-9_\\\\]+\s*(?:as\s+\w+)?\s*;/',
        'version' => '4.0',
        'category' => 'namespace',
        'severity' => 'info',
        'message' => 'phpseclib 4.0 import',
        'suggestion' => null,
    ],
    [
        'regex' => '/\buse\s+phpseclib3\\\\[A-Za-z0-9_\\\\]+\s*(?:as\s+\w+)?\s*;/',
        'version' => '3.0',
        'category' => 'namespace',
        'severity' => 'warn',
        'message' => 'phpseclib 3.0 import',
        'suggestion' => 'Update namespace to phpseclib4\\, or install phpseclib/phpseclib3_compat to keep this code as-is.',
    ],
    [
        // single-segment namespace, e.g. `use phpseclib\Crypt\RSA;` (2.0)
        // exclude phpseclib3\ and phpseclib4\ via negative lookahead
        'regex' => '/\buse\s+phpseclib\\\\(?!File\\\\|Crypt\\\\|Math\\\\|Net\\\\|System\\\\)[A-Za-z0-9_\\\\]+\s*;|\buse\s+phpseclib\\\\(?:File|Crypt|Math|Net|System)\\\\[A-Za-z0-9_\\\\]+\s*(?:as\s+\w+)?\s*;/',
        'version' => '2.0',
        'category' => 'namespace',
        'severity' => 'warn',
        'message' => 'phpseclib 2.0 import (single-segment namespace)',
        'suggestion' => 'Migrate to 3.0 first (or install phpseclib/phpseclib2_compat for the shim path), then consider 4.0.',
    ],

    // ---- Legacy class names (1.0 / 2.0) ----
    [
        'regex' => '/\b(?:Crypt_|Net_|File_|Math_|System_SSH_)[A-Z][A-Za-z_0-9]*\b/',
        'version' => '1.0',
        'category' => 'legacy-class',
        'severity' => 'warn',
        'message' => 'phpseclib 1.0/2.0 legacy class name',
        'suggestion' => 'Get to a current 3.0 release first (phpseclib/phpseclib2_compat may help), then evaluate the 4.0 path.',
    ],

    // ---- Compat shim references (informational) ----
    [
        'regex' => '/phpseclib\/phpseclib3_compat|phpseclib\/phpseclib2_compat/',
        'version' => 'ambiguous',
        'category' => 'shim',
        'severity' => 'info',
        'message' => 'Compat shim package referenced',
        'suggestion' => null,
    ],

    // ---- Removed APIs (3.0 only, gone in 4.0) ----
    [
        'regex' => '/->\s*loadX509\s*\(/',
        'version' => '3.0',
        'category' => 'removed-api',
        'severity' => 'error',
        'message' => '->loadX509() — removed in 4.0',
        'suggestion' => 'Replace with `X509::load($pem)` (static factory).',
    ],
    [
        'regex' => '/->\s*loadCSR\s*\(/',
        'version' => '3.0',
        'category' => 'removed-api',
        'severity' => 'error',
        'message' => '->loadCSR() — removed in 4.0',
        'suggestion' => 'Replace with `CSR::load($pem)` (new top-level class).',
    ],
    [
        'regex' => '/->\s*loadCRL\s*\(/',
        'version' => '3.0',
        'category' => 'removed-api',
        'severity' => 'error',
        'message' => '->loadCRL() — removed in 4.0',
        'suggestion' => 'Replace with `CRL::load($pem)` (new top-level class).',
    ],
    [
        'regex' => '/->\s*loadSPKAC\s*\(/',
        'version' => '3.0',
        'category' => 'removed-api',
        'severity' => 'error',
        'message' => '->loadSPKAC() — removed in 4.0',
        'suggestion' => 'Replace with `SPKAC::load($data)` (new top-level class).',
    ],
    [
        'regex' => '/->\s*loadCA\s*\(/',
        'version' => '3.0',
        'category' => 'removed-api',
        'severity' => 'error',
        'message' => '->loadCA() — renamed and made static in 4.0',
        'suggestion' => 'Replace with `X509::addCA($pem)` (now a static method, also renamed from loadCA).',
    ],
    [
        'regex' => '/->\s*saveX509\s*\(/',
        'version' => '3.0',
        'category' => 'removed-api',
        'severity' => 'error',
        'message' => '->saveX509() — removed in 4.0',
        'suggestion' => 'Replace with `echo $x509;` or `$x509->getEncoded()`. For DER, use `$x509->toString([\'binary\' => true])`.',
    ],
    [
        'regex' => '/->\s*saveCSR\s*\(/',
        'version' => '3.0',
        'category' => 'removed-api',
        'severity' => 'error',
        'message' => '->saveCSR() — removed in 4.0',
        'suggestion' => 'Replace with `echo $csr;` or `$csr->getEncoded()`.',
    ],
    [
        'regex' => '/->\s*saveCRL\s*\(/',
        'version' => '3.0',
        'category' => 'removed-api',
        'severity' => 'error',
        'message' => '->saveCRL() — removed in 4.0',
        'suggestion' => 'Replace with `echo $crl;` or `$crl->getEncoded()`.',
    ],
    [
        'regex' => '/->\s*getSFTPErrors\s*\(|->\s*getLastSFTPError\s*\(/',
        'version' => '3.0',
        'category' => 'removed-api',
        'severity' => 'error',
        'message' => 'SFTP error-polling method — removed in 4.0',
        'suggestion' => 'Rewrite around try/catch. The 4.0 `SFTP::getErrors()` exists but only collects per-step errors during recursive operations, not general operation failures.',
    ],
    [
        // SSH2::getErrors / getLastError — only flag when clearly on an SSH2/SFTP variable
        // to avoid colliding with the user's own getErrors method.
        'regex' => '/\$(?:ssh2?|sftp)\b[^;]*->\s*(?:getErrors|getLastError)\s*\(/i',
        'version' => '3.0',
        'category' => 'removed-api',
        'severity' => 'error',
        'message' => 'SSH2 error-polling method — removed in 4.0',
        'suggestion' => 'Rewrite around try/catch. All SSH2 errors are now thrown.',
    ],
    [
        // The Random::string() call. Excluded: `use phpseclib3\Crypt\Random;` lines
        // (those are already caught by the namespace import pattern).
        'regex' => '/\bRandom::\s*string\s*\(/',
        'version' => '3.0',
        'category' => 'removed-api',
        'severity' => 'error',
        'message' => 'Random::string() — Crypt\\Random class removed in 4.0',
        'suggestion' => 'Replace with PHP\'s built-in `random_bytes($n)`.',
    ],
    [
        'regex' => '/->\s*useBestEngine\s*\(|->\s*useInternalEngine\s*\(/',
        'version' => '3.0',
        'category' => 'removed-api',
        'severity' => 'warn',
        'message' => 'Engine selection — old method name (rename was backported to 3.0.51)',
        'suggestion' => 'Rename to `forceEngine(\'PHP\')` / `forceEngine(\'OpenSSL\')` / `forceEngine(\'libsodium\')` or `forceEngine(null)` to clear.',
    ],

    // ---- API shape changes (same name, different behavior) ----
    [
        // SFTP chmod arg-order trap. Match $var->chmod(<octal-or-int-literal>, ...
        // The intent: a numeric first argument to chmod is the 3.0 form.
        'regex' => '/->\s*chmod\s*\(\s*0[0-7]+|->\s*chmod\s*\(\s*[1-9]\d{2,3}\s*,/',
        'version' => '3.0',
        'category' => 'arg-order',
        'severity' => 'error',
        'message' => '$sftp->chmod() with mode-first argument order (3.0 style)',
        'suggestion' => 'Swap to path-first: `$sftp->chmod($path, 0777)`. The 3.0 order throws TypeError in 4.0.',
    ],
    [
        // 3.0 signing pattern: $x509->sign($issuer, $subject) — three X509 args
        // (the calling X509, plus two args). Very specific to the old API.
        'regex' => '/->\s*sign\s*\(\s*\$\w+\s*,\s*\$\w+\s*\)/',
        'version' => 'ambiguous',
        'category' => 'method',
        'severity' => 'warn',
        'message' => 'Possible 3.0 X.509 sign() pattern (two-arg form)',
        'suggestion' => 'In 4.0, signing is `$privKey->sign($x509);` (key signs the cert, returns raw signature, installs into $x509 as side effect). Verify the variables — if both args are X509 instances, this is 3.0 code that needs rewriting.',
    ],
    [
        // 3.0 signCSR / signCRL methods, gone in 4.0
        'regex' => '/->\s*signCSR\s*\(|->\s*signCRL\s*\(/',
        'version' => '3.0',
        'category' => 'removed-api',
        'severity' => 'error',
        'message' => 'signCSR()/signCRL() — removed in 4.0',
        'suggestion' => 'Replace with `$privKey->sign($csr)` / `$privKey->sign($crl)`. Key signs the signable object.',
    ],
    [
        // 3.0 setPrivateKey on the issuer cert before sign() — pattern for sign-as-CA setup
        'regex' => '/->\s*setPrivateKey\s*\(/',
        'version' => '3.0',
        'category' => 'method',
        'severity' => 'warn',
        'message' => '$x509->setPrivateKey() — 3.0 pattern (out-of-band key attachment)',
        'suggestion' => 'In 4.0 the key signs the cert directly: `$privKey->sign($x509)`. The setPrivateKey() pattern was needed in 3.0 because the X509 class held the sign() method.',
    ],

    // ---- DN methods (the bare versions throw in 4.0 on non-self-signed) ----
    [
        'regex' => '/->\s*(?:getDN|setDN|addDNProp|removeDNProp|getDNProp)\s*\(/',
        'version' => 'ambiguous',
        'category' => 'method',
        'severity' => 'warn',
        'message' => 'Bare DN method (getDN/setDN/etc.) — throws in 4.0 if subject ≠ issuer',
        'suggestion' => 'For code that handles non-self-signed certs, use the explicit `getSubjectDN()` / `getIssuerDN()` / `setSubjectDN()` / `setIssuerDN()` etc. variants.',
    ],

    // ---- String-matching getDN() output (silent BC break) ----
    [
        // strpos/strstr/strrpos/preg_match/preg_replace etc., with a (...)->getDN(...)
        // anywhere in the call arguments. Covers both the literal call inside the
        // string-fn and the assigned-then-checked case (when getDN appears nearby).
        'regex' => '/\b(?:strpos|strstr|stripos|strrpos|str_contains|str_starts_with|str_ends_with|preg_match|preg_match_all|preg_replace|substr_count)\s*\([^;]*->\s*get(?:Subject|Issuer)?DN\s*\(/',
        'version' => 'ambiguous',
        'category' => 'return-check',
        'severity' => 'warn',
        'message' => 'String-matching getDN() output — DN_STRING format changed between 3.0 and 4.0',
        'suggestion' => 'Don\'t string-match `getDN()` output. The 3.0 format was `C=US, O=Acme/CN=…`; the 4.0 format is `C = US, O = Acme, CN = …`. Use `getSubjectDNProps(\'CN\')` (returns array of values) or `getSubjectDN(ASN1::DN_OPENSSL)` (returns an associative array) for stable structured access.',
    ],

    // ---- DN format constants moved to ASN1 ----
    [
        'regex' => '/\bX509::\s*DN_(?:STRING|ARRAY|OPENSSL|ASN1|CANON)\b/',
        'version' => '3.0',
        'category' => 'method',
        'severity' => 'error',
        'message' => 'DN format constant on X509 — moved to ASN1 in 4.0',
        'suggestion' => 'Replace `X509::DN_*` with `ASN1::DN_*`.',
    ],

    // ---- ASN.1 low-level changes ----
    [
        'regex' => '/\bASN1::\s*asn1map\s*\(/',
        'version' => '3.0',
        'category' => 'removed-api',
        'severity' => 'error',
        'message' => 'ASN1::asn1map() — renamed in 4.0',
        'suggestion' => 'Rename to `ASN1::map()`. Also: input no longer needs `[0]` index because decodeBER() returns the single top-level structure directly.',
    ],
    [
        // Pattern: ASN1::decodeBER followed by [0] indexing — strong 3.0 signal
        'regex' => '/ASN1::\s*decodeBER\s*\([^)]*\)\s*\[\s*0\s*\]|\$\w+\s*=\s*ASN1::\s*decodeBER[^;]*;\s*[^;]*\$\w+\[\s*0\s*\]/',
        'version' => '3.0',
        'category' => 'method',
        'severity' => 'warn',
        'message' => 'decodeBER()[0] indexing — 4.0 returns the top-level structure directly',
        'suggestion' => 'Drop the `[0]` index. `ASN1::decodeBER()` in 4.0 returns the structure itself, not wrapped in a one-element array.',
    ],
    [
        // 3.0 signatureSubject idiom: slicing the signed region out of the raw DER
        // by offset/length from decodeBER() output. In 4.0 the Constructed retains
        // its own bytes, so the sub-structure hands them to you directly. The
        // decodeBER()[0] pattern above misses this because the substr() call is
        // usually on a different line from the decodeBER() call.
        'regex' => '/\bsubstr\s*\([^;]*\[\s*[\'"]start[\'"]\s*\][^;]*\[\s*[\'"]length[\'"]\s*\]/',
        'version' => '3.0',
        'category' => 'method',
        'severity' => 'warn',
        'message' => 'substr() by [\'start\']/[\'length\'] over decodeBER output — 3.0 signatureSubject idiom',
        'suggestion' => 'In 4.0 the Constructed retains its original encoding, so ask for it directly: `$cert[\'tbsCertificate\']->getEncoded()` returns the exact signed region. No offset-slicing of the source DER needed.',
    ],

    // ---- Return-value checks that imply 3.0 idioms ----
    [
        // Check for === false against typical phpseclib return values
        'regex' => '/(?:\$\w+|->getPublicKey\(\)|->getPrivateKey\(\)|->login\([^)]*\)|->getDN\(\)|->getExtension\([^)]*\))\s*===\s*false\b/',
        'version' => 'ambiguous',
        'category' => 'return-check',
        'severity' => 'warn',
        'message' => 'Possible === false check against phpseclib return value',
        'suggestion' => 'In 4.0 most phpseclib methods throw on failure rather than returning false. Replace with try/catch around the call.',
    ],

    // ---- Exception-class checks that imply 3.0 idioms ----
    [
        // catch (NoKeyLoadedException ...) — in 3.0 this was the *only* exception
        // PublicKeyLoader::load() (and RSA::load() / EC::load() / etc.) threw, including
        // for encrypted-but-no-password input. In 4.0 the encrypted-no-password case
        // throws PasswordNeededException instead, so this catch silently stops firing
        // for that case. Match both qualified and unqualified forms.
        'regex' => '/\bcatch\s*\(\s*(?:\\\\?phpseclib[34]\\\\Exception\\\\)?NoKeyLoadedException\b/',
        'version' => 'ambiguous',
        'category' => 'catch-shape',
        'severity' => 'warn',
        'message' => 'catch (NoKeyLoadedException) — encrypted-no-password case now throws PasswordNeededException in 4.0',
        'suggestion' => 'If this catch wraps `PublicKeyLoader::load()` / `RSA::load()` / etc. and the intent is to prompt the user for a password, add a `catch (PasswordNeededException ...)` clause too — otherwise the encrypted-key case will fall through as an uncaught exception. Code that catches both, or catches `\\Exception` / `\\Throwable`, is fine as-is.',
    ],

    // ---- Specialized extension helpers (3.0 names) ----
    [
        'regex' => '/->\s*setDomain\s*\(|->\s*setIPAddress\s*\(/',
        'version' => '3.0',
        'category' => 'method',
        'severity' => 'warn',
        'message' => 'setDomain()/setIPAddress() — renamed and now variadic in 4.0',
        'suggestion' => 'Rename to `addDomains(...)` / `addIPAddresses(...)`. Both accept variadic arguments now.',
    ],
    [
        'regex' => '/->\s*setKeyIdentifier\s*\(/',
        'version' => '3.0',
        'category' => 'method',
        'severity' => 'warn',
        'message' => 'setKeyIdentifier() — split in 4.0',
        'suggestion' => 'Use `setSubjectKeyIdentifier($keyId)` or `setAuthorityKeyIdentifier($keyId)` depending on which one you mean. Or `createSubjectKeyIdentifier($method)` to compute one from the public key.',
    ],
    [
        'regex' => '/->\s*getRevokedCertificateExtension\s*\(|->\s*setRevokedCertificateExtension\s*\(/',
        'version' => '3.0',
        'category' => 'method',
        'severity' => 'warn',
        'message' => 'Revoked-cert extension method on X509 — moved to CRL in 4.0',
        'suggestion' => 'Call `getRevokedExtension($serial, $name)` or `setRevokedExtension(...)` on the CRL object directly, not on X509.',
    ],

    // ---- validateDate (removed) ----
    [
        'regex' => '/->\s*validateDate\s*\(/',
        'version' => '3.0',
        'category' => 'removed-api',
        'severity' => 'error',
        'message' => 'validateDate() — removed in 4.0',
        'suggestion' => 'The date check is now part of `validateSignature()`. Use `X509::setTargetValidationDate($date)` first to check against a custom date.',
    ],

    // ---- URL-fetch toggles (removed; AIA fetching is now opt-in) ----
    [
        'regex' => '/->\s*(?:disableURLFetch|enableURLFetch)\s*\(|\bX509::\s*(?:disableURLFetch|enableURLFetch)\s*\(/',
        'version' => '3.0',
        'category' => 'removed-api',
        'severity' => 'error',
        'message' => 'disableURLFetch()/enableURLFetch() — removed in 4.0 (AIA intermediate fetching is now off by default)',
        'suggestion' => 'Both methods are gone. If this was disableURLFetch(), just delete the call — fetching is already off by default in 4.0. If this was enableURLFetch(), note that automatic AIA fetching no longer happens at all: register `X509::setURLFetchCallback(fn(string $host, string $ip, int $port, string $scheme): bool => ...)` to opt back in and gate which destinations phpseclib may connect to. Judge the resolved $ip; do not re-resolve $host.',
    ],

    // ---- getExtension return-shape change ----
    [
        // Pattern: ->getExtension(...) used directly without ['extnValue'] subscript
        // This is tricky because the assignment pattern $foo = $x509->getExtension(...) is
        // legitimate in both versions. Flag uses that look like 3.0-style value-extraction.
        'regex' => '/->\s*getExtension\s*\([^)]+\)\s*(?:==|!=|===|!==)\s*false/',
        'version' => '3.0',
        'category' => 'return-check',
        'severity' => 'warn',
        'message' => 'getExtension() compared to false — 4.0 returns null if missing, or an array {extnId, extnValue, critical} if present',
        'suggestion' => 'Use `if ($x509->hasExtension($name))` or check `$ext = $x509->getExtension($name); if ($ext !== null) { $value = $ext[\'extnValue\']; }`.',
    ],
];

// -----------------------------------------------------------------------------
// File walker
// -----------------------------------------------------------------------------

function collectPhpFiles(string $path): array {
    if (is_file($path)) {
        if (preg_match('/\.php$/i', $path)) {
            return [$path];
        }
        return [];
    }

    $files = [];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        $pathname = $file->getPathname();
        // Skip vendor — phpseclib itself doesn't need scanning.
        if (str_contains($pathname, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
            continue;
        }
        if (str_contains($pathname, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)) {
            continue;
        }
        if (preg_match('/\.php$/i', $pathname)) {
            $files[] = $pathname;
        }
    }
    sort($files);
    return $files;
}

// -----------------------------------------------------------------------------
// Scanner
// -----------------------------------------------------------------------------

function scanFile(string $file, array $patterns): array {
    $findings = [];
    $contents = @file_get_contents($file);
    if ($contents === false) {
        return [];
    }
    $lines = explode("\n", $contents);

    foreach ($lines as $lineNo => $line) {
        // Skip lines that are entirely comments — cheap heuristic, not bulletproof.
        $trimmed = ltrim($line);
        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '*')) {
            continue;
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern['regex'], $line, $matches)) {
                $findings[] = [
                    'file' => $file,
                    'line' => $lineNo + 1,
                    'column' => strpos($line, $matches[0]) + 1,
                    'snippet' => trim($line),
                    'matched' => $matches[0],
                    'version' => $pattern['version'],
                    'category' => $pattern['category'],
                    'severity' => $pattern['severity'],
                    'message' => $pattern['message'],
                    'suggestion' => $pattern['suggestion'],
                ];
            }
        }
    }
    return $findings;
}

// -----------------------------------------------------------------------------
// Aggregation
// -----------------------------------------------------------------------------

function aggregate(array $findings): array {
    $versions = ['1.0' => 0, '2.0' => 0, '3.0' => 0, '4.0' => 0, 'ambiguous' => 0];
    $categories = [];
    $severities = ['info' => 0, 'warn' => 0, 'error' => 0];
    $filesAffected = [];
    $shimReferenced = false;

    foreach ($findings as $f) {
        $versions[$f['version']]++;
        $categories[$f['category']] = ($categories[$f['category']] ?? 0) + 1;
        $severities[$f['severity']]++;
        $filesAffected[$f['file']] = true;
        if ($f['category'] === 'shim') {
            $shimReferenced = true;
        }
    }

    return [
        'total_findings' => count($findings),
        'files_affected' => count($filesAffected),
        'versions' => $versions,
        'categories' => $categories,
        'severities' => $severities,
        'shim_already_referenced' => $shimReferenced,
    ];
}

function recommendation(array $agg): string {
    $v = $agg['versions'];

    if ($agg['total_findings'] === 0) {
        return 'No phpseclib usage detected in scanned files.';
    }

    if ($v['1.0'] > 0 || $v['2.0'] > 0) {
        return 'Pre-3.0 code detected. Get the codebase to a current 3.0 release first '
             . '(phpseclib/phpseclib2_compat may help), then evaluate the 4.0 path.';
    }

    if ($v['3.0'] === 0 && $v['4.0'] > 0) {
        return 'All detected code is phpseclib 4.0. No migration needed.';
    }

    if ($v['3.0'] === 0 && $v['ambiguous'] > 0 && $v['4.0'] === 0) {
        return 'Only ambiguous patterns detected. Manual review needed to confirm '
             . 'which version this code targets — likely 3.0 based on style cues.';
    }

    $threeOhWork = $v['3.0'] + $v['ambiguous'];
    $shimRecommendThreshold = 20;

    if ($threeOhWork >= $shimRecommendThreshold) {
        return "Substantial 3.0 surface detected ({$threeOhWork} issues across "
             . "{$agg['files_affected']} files). Strongly consider phpseclib/phpseclib3_compat — "
             . "the shim package provides phpseclib/phpseclib:~3.0 on top of 4.0, so this code "
             . "can keep running unchanged. Rewriting against native 4.0 is a refactor that's "
             . "rarely worth the effort unless you specifically want to use new 4.0 features "
             . "(PFX, CMS, Signable interface) in your own code.";
    }

    return "Modest 3.0 surface detected ({$threeOhWork} issues across {$agg['files_affected']} files). "
         . "Either path is reasonable: rewrite against native 4.0 using the migration guide, "
         . "or install phpseclib/phpseclib3_compat to keep this code as-is while upgrading the "
         . "underlying library to 4.0.";
}

// -----------------------------------------------------------------------------
// Output formatters
// -----------------------------------------------------------------------------

function formatHuman(array $findings, array $agg, string $recommendation, string $scannedPath): string {
    if ($agg['total_findings'] === 0) {
        return "Scanned: $scannedPath\n\nNo phpseclib usage detected.\n";
    }

    $out = "phpseclib version scan\n";
    $out .= "======================\n\n";
    $out .= "Scanned: $scannedPath\n";
    $out .= "Files affected: {$agg['files_affected']}\n";
    $out .= "Total findings: {$agg['total_findings']}\n";
    $out .= "  errors: {$agg['severities']['error']}    "
          . "warnings: {$agg['severities']['warn']}    "
          . "info: {$agg['severities']['info']}\n";
    $out .= "\nBy version:\n";
    foreach ($agg['versions'] as $v => $c) {
        if ($c > 0) {
            $out .= sprintf("  %-10s %d\n", $v . ':', $c);
        }
    }
    $out .= "\nFindings:\n---------\n\n";

    // Group findings by file.
    $byFile = [];
    foreach ($findings as $f) {
        $byFile[$f['file']][] = $f;
    }

    foreach ($byFile as $file => $fileFindings) {
        $out .= "$file\n";
        foreach ($fileFindings as $f) {
            $sev = strtoupper($f['severity']);
            $out .= sprintf("  %-5s line %d: [%s/%s] %s\n",
                $sev, $f['line'], $f['version'], $f['category'], $f['message']);
            $out .= "        snippet: " . substr($f['snippet'], 0, 100)
                  . (strlen($f['snippet']) > 100 ? '...' : '') . "\n";
            if ($f['suggestion'] !== null) {
                $out .= "        → " . $f['suggestion'] . "\n";
            }
        }
        $out .= "\n";
    }

    $out .= "Recommendation\n--------------\n\n";
    $out .= wordwrap($recommendation, 78) . "\n";

    return $out;
}

function formatSummary(array $agg, string $recommendation): string {
    if ($agg['total_findings'] === 0) {
        return "No phpseclib usage detected.\n";
    }
    $errors = $agg['severities']['error'];
    $warns = $agg['severities']['warn'];
    return "{$agg['total_findings']} findings in {$agg['files_affected']} files "
         . "({$errors} errors, {$warns} warnings). " . $recommendation . "\n";
}

function formatJson(array $findings, array $agg, string $recommendation, string $scannedPath): string {
    return json_encode([
        'scanned_path' => $scannedPath,
        'aggregate' => $agg,
        'recommendation' => $recommendation,
        'findings' => $findings,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

// -----------------------------------------------------------------------------
// Main
// -----------------------------------------------------------------------------

$files = collectPhpFiles($path);
$allFindings = [];
foreach ($files as $file) {
    $allFindings = array_merge($allFindings, scanFile($file, $patterns));
}

$agg = aggregate($allFindings);
$rec = recommendation($agg);

switch ($mode) {
    case 'json':
        echo formatJson($allFindings, $agg, $rec, $path);
        break;
    case 'summary':
        echo formatSummary($agg, $rec);
        break;
    case 'quiet':
        // No output; exit code only.
        break;
    case 'human':
    default:
        echo formatHuman($allFindings, $agg, $rec, $path);
        break;
}

// Exit code reflects what was found.
if ($agg['total_findings'] === 0) {
    exit(0);
}
$preFour = $agg['versions']['1.0'] + $agg['versions']['2.0']
         + $agg['versions']['3.0'] + $agg['versions']['ambiguous'];
if ($preFour === 0) {
    exit(1);
}
exit(2);
