# `phpseclib4\Net\SSH2`, `SFTP`, `SCP`, and `SFTP\Stream`

The full SSH2 / SFTP API reference for phpseclib 4.0. Load this when SKILL.md isn't enough — when you need exact method signatures, the contract of the recursive-error model, the channel constants, the exception types thrown by each method, or guidance for the interactive-shell corner cases.

`SCP` and the `SFTP\Stream` wrapper are covered briefly at the end. Both are part of `phpseclib4\Net\`; `SCP` extends `SSH2` and `Stream` wraps an `SFTP`.

For the 3.0 → 4.0 mapping of SSH2 / SFTP methods (removed error-reporting methods, `chmod` argument order, exception model), see [`references/migration-3-to-4.md` → SSH2 and SFTP](migration-3-to-4.md#ssh2-and-sftp). This file documents the 4.0 surface; the migration doc documents the diff.

## Contents

- [Class layout](#class-layout)
- [Construction and connection](#construction-and-connection)
- [Authenticating](#authenticating)
- [Verifying the host key](#verifying-the-host-key)
- [Running commands with `exec()`](#running-commands-with-exec)
- [Interactive shells: `read()` / `write()`](#interactive-shells-read--write)
- [Subsystems](#subsystems)
- [Multiple channels](#multiple-channels)
- [Timeouts and keepalive](#timeouts-and-keepalive)
- [Algorithm selection](#algorithm-selection)
- [Connection diagnostics](#connection-diagnostics)
- [Logging](#logging)
- [SFTP construction and lifecycle](#sftp-construction-and-lifecycle)
- [SFTP filesystem operations](#sftp-filesystem-operations)
- [SFTP `put()` and `get()`](#sftp-put-and-get)
- [Recursive operations and `getErrors()`](#recursive-operations-and-geterrors)
- [SFTP version, extensions, and `copy()` / `posix_rename()` / `statvfs()`](#sftp-version-extensions-and-copy--posix_rename--statvfs)
- [Stat caching and path canonicalization](#stat-caching-and-path-canonicalization)
- [Listing](#listing)
- [SCP](#scp)
- [The SFTP stream wrapper](#the-sftp-stream-wrapper)

---

## Class layout

```
phpseclib4\Net\SSH2
├── extended by phpseclib4\Net\SCP    (adds put() / get() over SCP)
└── extended by phpseclib4\Net\SFTP   (adds the full SFTP filesystem API)

phpseclib4\Net\SFTP\Stream            (separate class — wraps an SFTP as a PHP stream wrapper)
```

Two practical consequences:

1. **`SFTP` and `SCP` are SSH2.** Every connection / authentication / algorithm-selection method documented in the SSH2 sections below works identically on `SFTP` and `SCP` instances. Use `new SFTP(...)` rather than `new SSH2(...)` from the start when you know you'll need SFTP — there's no need to construct one to upgrade to the other.
2. **A single instance carries one SSH session.** All the auxiliary state (logging configuration, keepalive interval, preferred algorithms, host) lives on the object. If you need two SSH connections to the same host with different credentials, instantiate twice.

---

## Construction and connection

```php
public function __construct(mixed $host, int $port = 22, int $timeout = 10)
```

```php
use phpseclib4\Net\SSH2;

$ssh = new SSH2('ssh.example.com');           // port 22, 10s connect timeout
$ssh = new SSH2('ssh.example.com', 2222);
$ssh = new SSH2('ssh.example.com', 22, 30);
```

The first argument is either a hostname/IP string **or** a stream resource. Passing a stream resource is how you tunnel SSH over an HTTP CONNECT proxy or a SOCKS5 proxy — open the socket yourself, complete the proxy handshake, and hand the resulting socket to the SSH2 constructor. When `$host` is a resource, `$port` is ignored but `$timeout` is still honored.

```php
// HTTP CONNECT proxy
$fsock = fsockopen('proxy.example.com', 8080, $errno, $errstr, 1);
fputs($fsock, "CONNECT ssh.example.com:22 HTTP/1.0\r\n\r\n");
while (($line = fgets($fsock, 1024)) !== "\r\n") { /* read response headers */ }
$ssh = new SSH2($fsock);
```

For IPv6, square-bracket the numeric address in any URL form: `tcp://[fe80::1]:22`. The bracket convention is PHP's, not phpseclib's.

To bind the client side to a specific local address, build the stream with `stream_socket_client` and pass it in:

```php
$ctx = stream_context_create(['socket' => ['bindto' => '127.255.255.255:0']]);
$socket = stream_socket_client('tcp://ssh.example.com:22', $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $ctx);
$ssh = new SSH2($socket);
```

### Connection is lazy

The constructor does not connect. The TCP connection and SSH handshake happen on the first call to one of:

- `login(...)`
- `getServerIdentification()`
- `getServerAlgorithms()`
- `getAlgorithmsNegotiated()`
- `getServerPublicHostKey()`

Calling `isConnected()` before any of these returns `false`. This is intentional — it lets you configure the instance (algorithm preferences, terminal type, `sendIdentificationStringFirst()` quirks) before the wire activity starts.

### Failure modes

- TCP connect failure → `phpseclib4\Exception\UnableToConnectException`
- Connection drops mid-handshake → `phpseclib4\Exception\ConnectionClosedException`
- No mutually supported KEX/host-key/cipher/MAC/compression → `phpseclib4\Exception\NoSupportedAlgorithmsException`
- Server identification string malformed → `phpseclib4\Exception\UnexpectedValueException`

All extend `\RuntimeException` and implement `phpseclib4\Exception\BaseException`, so `catch (\RuntimeException $e)` or `catch (\phpseclib4\Exception\BaseException $e)` covers everything.

### Quirks toggles

A handful of servers misbehave in protocol corners. phpseclib provides toggles:

```php
$ssh->sendIdentificationStringFirst();   // send our SSH-2.0-... before reading theirs
$ssh->sendIdentificationStringLast();    // wait for theirs first (default-ish; protocol allows either)
$ssh->sendKEXINITFirst();                // send SSH_MSG_KEXINIT before the server does
$ssh->sendKEXINITLast();                 // wait for the server to send it first
```

Reach for these only when a connection fails against a specific buggy server; don't apply them prophylactically.

---

## Authenticating

```php
public function login(string $username, #[SensitiveParameter] string|PrivateKey|array|Agent ...$args): bool
```

`login()` returns `bool` — true on success, false on failure. This is one of the few methods in 4.0 that still uses a bool return rather than throwing, because authentication failure is an *expected* outcome (bad password, wrong key), not an error. Connection errors during login still throw.

```php
if (!$ssh->login('alice', 'hunter2')) {
    throw new \Exception('Login failed');
}
```

### Password

```php
$ssh->login('alice', 'hunter2');
```

If password auth fails, phpseclib will automatically try keyboard-interactive with the same password before returning `false`. Many servers prompt for passwords via keyboard-interactive rather than the standalone password mechanism, so this fallback is usually what you want.

### Public key

```php
use phpseclib4\Crypt\PublicKeyLoader;

$key = PublicKeyLoader::load(file_get_contents('id_ed25519'));
$ssh->login('alice', $key);
```

Password-protected key:

```php
$key = PublicKeyLoader::load(file_get_contents('id_ed25519'), 'key-password');
$ssh->login('alice', $key);
```

`PublicKeyLoader::load()` auto-detects format (OpenSSH, PuTTY, PKCS#8, raw PEM, etc.) and key type (RSA, DSA, EC including Ed25519). Passing the bytes is enough; no need to call format-specific loaders.

### SSH agent

```php
use phpseclib4\System\SSH\Agent;

$agent = new Agent;                       // reads $_SERVER['SSH_AUTH_SOCK'] / $_ENV['SSH_AUTH_SOCK']
$agent = new Agent('/run/user/1000/keyring/ssh');  // explicit socket path
$ssh->login('alice', $agent);
```

For agent forwarding (so commands run over this SSH session can reach a downstream agent for further SSH connections):

```php
$agent->startSSHForwarding($ssh);
echo $ssh->exec('ssh user@inner-host "hostname"');
```

`Agent` implements `phpseclib4\Crypt\Common\PrivateKey`, so it can also sign things directly — useful when signing X.509 certs without the private key ever leaving the agent. See SKILL.md's "Sign objects by passing them to a key (or PFX)" idiom.

### Multi-factor

Pass extra positional arguments after the username:

```php
$ssh->login('alice', 'password', 'totp-code');
$ssh->login('alice', $key, 'totp-code');
```

phpseclib feeds the arguments to whatever auth methods the server demands next, in order.

### Keyboard-interactive with named prompts

When the server uses keyboard-interactive with multiple prompts you need to disambiguate, pass an array of `prompt-substring => answer` pairs:

```php
$ssh->login('alice', [
    ['Password' => 'pass1'],
    ['Verification code' => 'code1'],
]);
```

The outer array is the list of prompts you expect; each inner array maps a substring of the prompt text to the answer. The first key whose substring appears in the actual prompt wins.

### "No authentication" servers

Some SSH servers accept any username with no authentication and then prompt for credentials from inside the shell:

```php
$ssh->login('alice');                 // no auth method — server proceeds
$ssh->read('User Name:');
$ssh->write("alice\n");
$ssh->read('Password:');
$ssh->write("hunter2\n");
```

This is rare. If you're sure your credentials are correct and `login('alice', 'hunter2')` is failing, suspect this case — the password prompt is coming from the shell, not from SSH auth.

### Which methods can still be attempted

After a failed `login()`, `getAuthMethodsToContinue()` returns the auth methods the server says it would accept on a retry, as an array (or `null` if the session is past the auth stage):

```php
if (!$ssh->login('alice', $key)) {
    print_r($ssh->getAuthMethodsToContinue());   // e.g. ['publickey', 'keyboard-interactive']
}
```

### Smart MFA

`enableSmartMFA()` / `disableSmartMFA()` toggle phpseclib's heuristic for matching multiple positional auth arguments to the server's required methods. Smart MFA is on by default in modern versions; turn it off if it's picking the wrong method for your server.

---

## Verifying the host key

By default, phpseclib does not verify the server's identity. Server impersonation defeats the security of SSH, so any real client needs to verify the host key against an expected value before authenticating.

```php
$expected = file_get_contents('expected-hostkey.pub');   // out-of-band-trusted

$ssh = new SSH2('ssh.example.com');
if ($ssh->getServerPublicHostKey() !== $expected) {
    throw new \Exception('Host key mismatch — possible MITM');
}
$ssh->login('alice', $key);
```

`getServerPublicHostKey()` returns the host key in SSH wire format (the same format `~/.ssh/known_hosts` uses for the key portion), prefixed with the signature algorithm name and a space. It returns `null` if called before the handshake somehow completes, but in normal usage it triggers the handshake itself.

Strategies:

- **Trust on first use (TOFU).** Save the result of `getServerPublicHostKey()` on first connection, compare on subsequent connections. This is what OpenSSH does.
- **Pinned key.** Bake the expected key into your config; reject any mismatch.
- **Cert-style.** Out of scope here — SSH certificates are not as widely deployed as X.509 certs and phpseclib doesn't handle them specially.

The comparison should be exact-bytes; don't try to normalize.

---

## Running commands with `exec()`

```php
public function exec(string $command, ?\Closure $callback = null): ?string
```

```php
echo $ssh->exec('uname -a');
echo $ssh->exec('ls -la /var/log');
```

`exec()` opens a fresh channel, runs the command, and returns its combined stdout+stderr as a string. Returns `null` when a PTY is enabled (because output streams continuously and must be read with `read()` instead — see [`enablePTY()`](#exec-with-a-pty) below).

Throws `phpseclib4\Exception\InvalidStateException` if called before `login()`.

### Each `exec()` is a fresh channel

A subtle but important property: state changes made by one `exec()` do **not** carry over to the next.

```php
echo $ssh->exec('pwd');       // /home/alice
$ssh->exec('cd /tmp');
echo $ssh->exec('pwd');       // /home/alice — not /tmp
```

The second `cd` runs in its own channel; when the channel closes, the cwd is gone. Combine commands explicitly when you need state:

```php
echo $ssh->exec('cd /tmp && pwd');
echo $ssh->exec('cd /tmp; pwd');
```

If you need persistent shell state, use `openShell()` + `read()`/`write()` instead of `exec()`.

### Suppressing stderr

By default `exec()`'s return value includes both stdout and stderr. Toggle:

```php
$ssh->enableQuietMode();      // omit stderr from exec()'s return value
$ssh->exec('command-that-writes-to-stderr');
$ssh->getStdError();          // retrieve stderr separately
$ssh->disableQuietMode();
$ssh->isQuietModeEnabled();   // bool
```

Quiet mode interacts with PTY — see below.

### Exit status

```php
$ssh->exec('false');
$ssh->getExitStatus();        // 1 (or null if the server didn't send an exit status)
```

`getExitStatus()` reads the exit status from the *most recently completed* `exec()` channel. Call it before starting the next `exec()`.

### Streaming output (callback mode)

For long-running commands, pass a `Closure` to receive output as it arrives:

```php
$ssh->exec('ping 127.0.0.1', function (string $chunk): bool|null {
    echo $chunk;
    if (str_contains($chunk, 'icmp_seq=5')) {
        return true;     // returning true closes the channel and ends exec() early
    }
    return null;
});
```

The callback is invoked once per chunk of output from the server. Returning `true` causes `exec()` to close the channel and return `null`. Returning anything else (including `null`) continues. The callback receives stdout+stderr interleaved unless quiet mode is enabled, in which case it gets only stdout.

When using a callback, `exec()` returns `null` rather than the accumulated output (the callback already saw it).

### `exec()` with a PTY

```php
$ssh->enablePTY();
$ssh->exec('top');             // returns null immediately — output streams in
$ssh->setTimeout(5);
echo $ssh->read();             // pulls accumulated output
$ssh->disablePTY();
$ssh->isPTYEnabled();          // bool
```

A PTY is required for commands that need an interactive terminal: `top`, `vim`, `passwd`, anything that uses ANSI escape codes or hides keystrokes. With a PTY, `exec()` does not block waiting for the command to finish — it returns `null` immediately, and you read output via `read()` on `CHANNEL_EXEC` (the default when only an exec is open).

`getStdError()` does **not** work with a PTY-enabled exec, because a PTY conflates stdout and stderr into a single stream at the kernel level. This is a property of how PTYs work, not a phpseclib limitation.

---

## Interactive shells: `read()` / `write()`

```php
public function read(string $expect = '', int $mode = self::READ_SIMPLE, ?int $channel = null): string|bool
public function write(string $cmd, ?int $channel = null): void
```

A persistent interactive shell — same semantics as if a user were typing at a terminal:

```php
echo $ssh->read('alice@host:~$ ');
$ssh->write("ls -la\n");                 // note the trailing newline
echo $ssh->read('alice@host:~$ ');
```

If you haven't called `openShell()` explicitly, the first `read()` or `write()` opens one automatically.

### Read modes

```php
$ssh->read();                                          // read until timeout
$ssh->read('prompt$ ');                                // read until 'prompt$ ' appears (default mode)
$ssh->read('prompt$ ', SSH2::READ_SIMPLE);             // same as above
$ssh->read('#pattern#', SSH2::READ_REGEX);             // read until regex matches
$ssh->read('', SSH2::READ_NEXT);                       // read next packet only, don't loop
```

`READ_SIMPLE` (the default) returns everything up to and including the first occurrence of `$expect`. `READ_REGEX` does the same but with a regex match. `READ_NEXT` returns whatever's in the next channel packet, without any matching — useful when you don't know what's coming.

In all modes, `read()` consumes data from the buffer. The next `read()` starts from where the last one left off.

### Always read the prompt before writing

```php
// WRONG — initial prompt is still in the buffer
$ssh->write("ls\n");
echo $ssh->read('alice@host:~$ ');   // catches the *first* prompt, not the post-ls one

// RIGHT
$ssh->read('alice@host:~$ ');         // drain the initial prompt
$ssh->write("ls\n");
echo $ssh->read('alice@host:~$ ');    // catches the post-ls prompt
```

This is the most common interactive-shell bug. The buffer always has the initial prompt waiting; if you don't consume it before the first `write()`, every subsequent `read()` is one step behind.

### `read()` after PTY-exec

When `enablePTY()` is on and `exec()` was used to start the command, `read()` reads from `CHANNEL_EXEC`. When `openShell()` is what started the channel, `read()` reads from `CHANNEL_SHELL`. The default `$channel = null` picks the right one automatically based on what's open (preferring subsystem → exec → shell).

### Closing and resetting

```php
$ssh->reset();                  // close and re-open the current interactive channel
$ssh->reset(SSH2::CHANNEL_EXEC); // close and re-open a specific channel
$ssh->sendEOF();                // send EOF on the current channel
```

`reset()` is the right answer when an exec/shell channel hangs or times out and you want to start fresh without disconnecting.

### Sending special characters

Terminal control sequences are just byte sequences over the channel:

```php
$ssh->write("\x03");            // Ctrl-C
$ssh->write("\x1BOP");          // F1
$ssh->write("\x1B[A");          // Arrow up
```

A table of common sequences is in `references/special-chars.md` (or [the docusaurus page](https://phpseclib.com/) for the broader list).

### Parsing ANSI escapes

phpseclib's terminal type is `vt100` by default. Commands like `top` produce ANSI escape codes that don't make sense as raw bytes. `phpseclib4\File\ANSI` is a minimal terminal emulator that renders them:

```php
use phpseclib4\File\ANSI;

$ansi = new ANSI;
$ansi->appendString($ssh->read('alice@host:~$ '));
$ssh->write("top\n");
$ssh->setTimeout(5);
$ansi->appendString($ssh->read());
echo $ansi->getScreen();        // HTML
echo $ansi->getHistory();       // HTML, including scrolled-out content
```

Default screen size is 80×24 and history is 200 lines. Override:

```php
$ssh->setWindowSize(120, 40);   // before opening the shell
$ansi->setHistory(500);
```

`getScreen()` and `getHistory()` both return HTML; strip with `htmlspecialchars_decode(strip_tags($ansi->getScreen()))` for plain text. Change the terminal type with `$ssh->setTerminal('xterm')` if your application needs xterm-specific escapes.

---

## Subsystems

Some SSH servers expose named subsystems (the SFTP subsystem is the canonical example; some NETCONF and Git over SSH setups are others).

```php
$ssh->startSubsystem('netconf');   // returns bool: true if the subsystem opened
$ssh->write("<rpc>...</rpc>\n", SSH2::CHANNEL_SUBSYSTEM);
echo $ssh->read('', SSH2::READ_SIMPLE, SSH2::CHANNEL_SUBSYSTEM);
$ssh->stopSubsystem();
```

Only one subsystem at a time is supported per SSH2 instance. SFTP uses this mechanism internally (with its own dedicated `SFTP::CHANNEL` constant), which is why `SFTP` is a subclass of `SSH2` rather than a separate composition.

---

## Multiple channels

A single SSH2 connection can run an exec, an interactive shell, and a subsystem simultaneously. The `$channel` parameter on `read()`, `write()`, and `reset()` selects which channel the call targets:

```php
SSH2::CHANNEL_EXEC          // 1 — used by exec()
SSH2::CHANNEL_SHELL         // 2 — used by openShell()
SSH2::CHANNEL_SUBSYSTEM     // 3 — used by startSubsystem()
SSH2::CHANNEL_AGENT_FORWARD // 4
SSH2::CHANNEL_KEEP_ALIVE    // 5
```

Pattern: one PTY-exec running a long command, plus an interactive shell on the side:

```php
$ssh->enablePTY();
$ssh->exec('sudo tail -f /var/log/syslog');         // CHANNEL_EXEC
$ssh->write("sudo ls\n", SSH2::CHANNEL_SHELL);     // CHANNEL_SHELL — opens it lazily
$ssh->read('Password:', SSH2::READ_SIMPLE, SSH2::CHANNEL_SHELL);
```

Status checkers:

```php
$ssh->isShellOpen();                  // CHANNEL_SHELL has data status
$ssh->isPTYOpen();                    // CHANNEL_EXEC has data status (only meaningful with enablePTY)
$ssh->isInteractiveChannelOpen(SSH2::CHANNEL_SUBSYSTEM);
$ssh->getInteractiveChannelId();      // id of the most-recently-opened interactive channel
$ssh->getOpenChannelCount();          // total open channels including auxiliaries
```

### Server quirks

OpenSSH 5.8–6.9 on Ubuntu has a bug where multiple concurrent channels deadlock. phpseclib refuses to open a second channel against such servers by default, throwing `phpseclib4\Exception\UnsupportedValueException`. If you've verified that a particular server is fine despite reporting an affected version, `forceMultipleChannels()` overrides the check.

When phpseclib auto-selects a channel (the `$channel = null` default), it picks subsystem → exec → shell in that priority order. This is documented but considered legacy behavior — pass an explicit channel when running multi-channel code.

---

## Timeouts and keepalive

```php
public function setTimeout(int $timeout): void
public function getTimeout(): int
public function isTimeout(): bool
public function setKeepAlive(int $interval): void
```

`setTimeout()` controls how long `read()` and `exec()` will wait for output before giving up. The default is 10 seconds. Setting it to 0 means "wait forever."

After each `read()` or `exec()`, the timeout resets to whatever was last set, so:

```php
$ssh->setTimeout(2);
$ssh->read();             // waits up to 2s
$ssh->read();             // also waits up to 2s
```

To check whether a `read()` returned because of a timeout vs. because the expected text appeared:

```php
$result = $ssh->read('prompt$ ');
if ($ssh->isTimeout()) {
    // the prompt never showed up
}
```

`setTimeout()` also bounds `exec()`. If an `exec()` times out and you want to issue another, call `reset()` first — the previous channel is still half-open.

### Keepalive

For long-running commands against servers configured with low `ClientAliveInterval`/`ClientAliveCountMax`, set a keepalive:

```php
$ssh->setKeepAlive(30);   // send SSH_MSG_IGNORE every 30s
```

Keepalive packets only go out while phpseclib has control. If your PHP script spends 60 seconds in `sleep()` between SSH operations, no keepalives are sent during that gap — phpseclib is just PHP code, it can't run a background thread. The keepalive helps for commands that take a long time *server-side*, not for client-side gaps between calls.

---

## Algorithm selection

```php
public function setPreferredAlgorithms(array $methods): void
public function getServerAlgorithms(): array
public function getAlgorithmsNegotiated(): array
public static function getSupportedKEXAlgorithms(): array
public static function getSupportedHostKeyAlgorithms(): array
public static function getSupportedEncryptionAlgorithms(): array
public static function getSupportedMACAlgorithms(): array
public static function getSupportedCompressionAlgorithms(): array
```

The defaults are tuned for the best speed/security tradeoff given which extensions are loaded (OpenSSL, libsodium). Override only when you have a specific reason — interoperability with a constrained server, compliance requirements, or debugging.

The shape:

```php
$ssh->setPreferredAlgorithms([
    'kex'     => ['curve25519-sha256', 'ecdh-sha2-nistp256'],
    'hostkey' => ['ssh-ed25519', 'rsa-sha2-512'],
    'client_to_server' => [
        'crypt' => ['aes256-gcm@openssh.com', 'chacha20-poly1305@openssh.com'],
        'mac'   => ['hmac-sha2-512-etm@openssh.com'],
        'comp'  => ['none'],
    ],
    'server_to_client' => [ /* same shape */ ],
]);
```

Each list-valued key also accepts a comma-separated string for compatibility with PHP's `ssh2_connect`-style API:

```php
$ssh->setPreferredAlgorithms([
    'kex' => 'curve25519-sha256,ecdh-sha2-nistp256',
]);
```

If you ask for an algorithm phpseclib doesn't know about, `setPreferredAlgorithms()` throws `phpseclib4\Exception\UnsupportedAlgorithmException` immediately — it won't wait for KEX to fail.

### Diagnosing a "No supported algorithms" error

After a `NoSupportedAlgorithmsException` from KEX:

```php
print_r($ssh->getServerAlgorithms());
// ['kex' => [...], 'hostkey' => [...], 'client_to_server' => [...], 'server_to_client' => [...]]
```

Then compare against the static `getSupported*` lists to figure out what overlaps. The intersection is what's available; if it's empty, you'll need to either upgrade the server or relax phpseclib's defaults via `setPreferredAlgorithms()` (which may mean accepting weaker crypto).

### Server identification

```php
$ssh->getServerIdentification();   // "SSH-2.0-OpenSSH_9.6p1 Ubuntu-3ubuntu13.5"
```

Useful for logging and for server-specific workarounds.

### Banner

```php
$ssh->getBannerMessage();   // pre-auth banner if the server sent one
```

This is the text some servers display before login (typically a legal warning). Per RFC 4252 § 5.4, it has no functional role; it's just informational.

### Compression

zlib compression algorithms (`zlib@openssh.com`, `zlib`) are supported but require the zlib extension be loaded in PHP. Default preference is `none` because compression rarely helps SSH and complicates the threat model.

---

## Connection diagnostics

```php
public function isConnected(int $level = 0): bool
public function isAuthenticated(): bool
```

`isAuthenticated()` returns true after a successful `login()`. False before, or after disconnection.

`isConnected()` takes a `$level` controlling how aggressively it checks:

- `isConnected(0)` (default): passive — `feof($socket)`. Cheap. Detects half-closed connections only if the OS already noticed.
- `isConnected(1)`: active — sends `SSH_MSG_IGNORE`, no response expected. Slightly more reliable, server doesn't echo back.
- `isConnected(2)`: most thorough — opens and immediately closes a channel. Some servers (notably Cisco IOS routers) limit total channels per session, so this can give false negatives on those.

For most cases, level 0 is fine. Level 1 is the right choice for "I haven't talked to this server in a while, is the connection still good?" — it's what `setKeepAlive()` uses internally.

### Disconnecting

```php
$ssh->disconnect();
```

Sends `SSH_MSG_DISCONNECT` and closes the socket. The destructor calls `disconnect()` automatically, so you rarely need to call it explicitly — though doing so makes the lifecycle obvious in code.

---

## Logging

Logging is controlled by constants you `define()` before instantiating SSH2:

```php
define('NET_SSH2_LOGGING', SSH2::LOG_COMPLEX);
```

The four levels:

- `SSH2::LOG_SIMPLE` — short array of message types; retrieve via `getLog()` which returns an array. Useful for "what happened" overview.
- `SSH2::LOG_COMPLEX` — full hex dump of every packet sent/received; `getLog()` returns a multi-MiB string. Capped at 1 MiB; wraps. This is the most useful level for debugging.
- `SSH2::LOG_REALTIME` — same as COMPLEX but streamed to stdout/stderr as packets flow rather than buffered. `getLog()` returns nothing because the log is already gone.
- `SSH2::LOG_REALTIME_FILE` — REALTIME but written to a file. Useful when the script crashes before you can read the buffer.

```php
define('NET_SSH2_LOG_REALTIME_FILENAME', '/tmp/ssh-debug.log');
define('NET_SSH2_LOGGING', SSH2::LOG_REALTIME_FILE);
```

Passwords used in `login()` are redacted in all log types — they appear as the literal string `password` rather than your actual password. Key bytes are not redacted, but they're typically not in human-readable form anyway.

SFTP-layer logging is independent:

```php
define('NET_SFTP_LOGGING', SFTP::LOG_COMPLEX);
$sftp->getSFTPLog();
```

The four `SFTP::LOG_*` constants mirror SSH2's, except there is no `LOG_REALTIME_FILE` for SFTP.

---

## SFTP construction and lifecycle

```php
use phpseclib4\Net\SFTP;

$sftp = new SFTP('ssh.example.com', 22, 30);
if (!$sftp->login('alice', $key)) {
    throw new \Exception('Login failed');
}
echo $sftp->pwd();   // "/home/alice"
$sftp->put('remote.txt', 'local.txt', SFTP::SOURCE_LOCAL_FILE);
```

`SFTP::__construct()` has the same signature as `SSH2::__construct()`. The SFTP subsystem is *not* opened during construction or during `login()` — it's opened on the first SFTP filesystem method call (`pwd()`, `nlist()`, `get()`, etc.), via a `precheck()` step that initializes the subsystem if needed.

Practical consequence: an `SFTP` instance can be used for SSH `exec()` too, since it's still an SSH2. The SFTP channel uses `SFTP::CHANNEL` (0x100), which doesn't conflict with the SSH2 channel constants. So:

```php
$sftp = new SFTP('host');
$sftp->login('alice', $key);
echo $sftp->exec('uname -a');                    // works — runs over SSH
$sftp->put('remote.txt', 'data');                 // works — opens SFTP subsystem
```

If a method that requires the SFTP subsystem is called before `login()`, `phpseclib4\Exception\InvalidStateException` is thrown ("Function should not be called before you've logged in").

### Setting SFTP version

```php
$sftp->setPreferredVersion(3);
$sftp->getNegotiatedVersion();      // 3 (after first SFTP operation negotiates)
$sftp->getSupportedVersions();      // ['version' => 3, 'extensions' => [...]]
$sftp->getSupportedExtensions();    // associative array of extension => version
```

SFTPv3 is the universal lowest common denominator. Most servers support it; OpenSSH defaults to v3. Higher versions (v4, v5, v6) add features like atomic rename, fuller attribute models, and the `OWNERGROUP` string-based ownership scheme — but server support is uneven.

`setPreferredVersion()` is a hint. If the server doesn't support the requested version, phpseclib falls back to the highest version they both support. To pin to exactly one version with no fallback, you'd need to verify with `getNegotiatedVersion()` after the first operation.

---

## SFTP filesystem operations

The naming follows PHP's filesystem functions (`stat`, `lstat`, `chmod`, `chown`, `chgrp`, `touch`, `truncate`, `mkdir`, `rmdir`, `rename`, `delete`, `file_exists`, `is_dir`, `is_file`, `is_link`, `is_readable`, `is_writable`, `is_writeable`, `fileatime`, `filemtime`, `fileperms`, `fileowner`, `filegroup`, `filesize`, `filetype`, `readlink`, `symlink`). Behavior matches PHP's where reasonable; the differences are noted below.

### Path argument

All these methods take a path as the first argument. Relative paths are resolved against the current SFTP working directory (which starts as the user's home directory and is changed by `chdir()`); absolute paths are used as-is. `pwd()` returns the current working directory.

### `stat()` and `lstat()`

```php
$info = $sftp->stat('file.txt');
// [
//     'size'  => 12345,
//     'mode'  => 33188,                // octal, matches PHP's stat()
//     'atime' => 1715000000,
//     'mtime' => 1715000000,
//     'type'  => FileType::REGULAR,    // enum-style int constant
//     ... more fields, server- and version-dependent
// ]
```

`lstat()` follows the same shape but does not follow symlinks — it returns info about the link itself rather than its target.

Return type is `array`. If the file doesn't exist or can't be stat'd, both methods throw `phpseclib4\Exception\FileSystemException`. To check existence without an exception, use `file_exists()` (which internally try/catches `stat()`).

### `chmod()` / `chown()` / `chgrp()`

```php
public function chmod(string $filename, int $mode, bool $recursive = false): void
public function chown(string $filename, int|string $uid, bool $recursive = false): void
public function chgrp(string $filename, int|string $gid, bool $recursive = false): void
```

All three take path-first, then value. **In 3.0, `chmod` was value-first** — `chmod($mode, $path)`. This is the most likely source of `TypeError`s when migrating, because PHP doesn't coerce `int` → `string`.

`$mode` for `chmod` is masked to `0o7777` (i.e. only the standard permission bits). The setuid/setgid/sticky bits are honored.

For SFTPv4+, `chown`/`chgrp` accept *strings* of the form `user@dns_domain` rather than numeric uid/gid. phpseclib passes through whatever value you provide; it does not translate uid ↔ name. Check `$sftp->getSupportedVersions()['version']` if you need to branch by version.

`$recursive = true` walks a directory tree applying the change to every entry. See [Recursive operations and `getErrors()`](#recursive-operations-and-geterrors) below for how errors during the walk are reported.

### `touch()`

```php
public function touch(string $filename, ?int $time = null, ?int $atime = null): void
```

Updates atime and mtime; creates the file if it doesn't exist. Without arguments, sets both to "now":

```php
$sftp->touch('file.txt');
$sftp->touch('file.txt', 1715000000);                 // mtime = atime = 1715000000
$sftp->touch('file.txt', 1715000000, 1700000000);     // mtime, atime separate
```

### `truncate()`

```php
public function truncate(string $filename, int $new_size): void
```

Truncates or extends the file to exactly `$new_size` bytes. Extension fills with NULs.

### `rename()`

```php
public function rename(string $oldname, string $newname): void
```

If `$newname` already exists, `rename()` throws (`FileSystemException`). To overwrite atomically, use `posix_rename()` — see below.

### `delete()`

```php
public function delete(string $path, bool $recursive = true): void
```

Note the default: **`$recursive` is `true`**. Calling `delete('some-dir')` without arguments deletes the entire directory tree. To delete only a single file (and refuse to recurse), pass `false`. If `$path` is a directory and `$recursive` is `false`, the call throws `FileSystemException`.

When recursing, partial failures are collected in `getErrors()` rather than aborting the operation — see [Recursive operations](#recursive-operations-and-geterrors).

### `mkdir()` / `rmdir()`

```php
public function mkdir(string $dir, int $mode = -1, bool $recursive = false): void
public function rmdir(string $dir): void
```

If `$mode = -1` (the default), `mkdir` doesn't set a mode — the server applies its default umask. Pass `0o755` or similar to set explicitly.

`$recursive = true` creates parent directories as needed (like `mkdir -p`). Intermediate failures (e.g., a parent already exists) don't abort — only a failure on the leaf directory raises.

`rmdir` does **not** recurse. To remove a non-empty directory, use `delete($path, true)`.

### `symlink()` / `readlink()`

```php
public function symlink(string $target, string $link): void
public function readlink(string $link): string
```

`symlink()` argument order is **target first**, matching POSIX `symlink(2)` and PHP's `symlink()`. `readlink()` returns the target path that the link points to (not the link itself, not a resolved absolute path).

### Existence and type checks

```php
$sftp->file_exists($path);     // bool
$sftp->is_dir($path);          // bool
$sftp->is_file($path);         // bool
$sftp->is_link($path);         // bool (uses lstat)
$sftp->is_readable($path);     // bool — attempts to OPEN for reading
$sftp->is_writable($path);     // bool — attempts to OPEN for writing
$sftp->is_writeable($path);    // alias of is_writable
```

These all catch `FileSystemException` internally and return `false` on failure. They never throw under normal circumstances. `is_readable` and `is_writable` work by actually trying to open the file (then closing it immediately) — they don't infer from permission bits, which can be unreliable across users and ACLs.

### Property accessors

```php
$sftp->fileatime($path);     // int
$sftp->filemtime($path);     // int
$sftp->fileperms($path);     // int (octal)
$sftp->fileowner($path);     // int (v3) or string (v4+)
$sftp->filegroup($path);     // int (v3) or string (v4+)
$sftp->filesize($path);                  // int
$sftp->filesize($path, recursive: true); // sum of all files in a directory tree
$sftp->filetype($path);                  // 'file', 'dir', 'link', 'block', 'char', 'fifo', 'socket'
```

All of these throw `FileSystemException` if the path doesn't exist. Guard with `file_exists()` or try/catch.

---

## SFTP `put()` and `get()`

```php
public function put(
    string $remote_file,
    #[SensitiveParameter] mixed $data,
    int $mode = self::SOURCE_STRING,
    int $start = -1,
    int $local_start = -1,
    ?\Closure $progressCallback = null
): void

public function get(
    string $remote_file,
    mixed $local_file = null,
    int $offset = 0,
    int $length = -1,
    ?\Closure $progressCallback = null
): ?string
```

### `put()` — uploading

`put()` has three source modes via the `$mode` flag bitmask:

```php
// 1. Upload a literal string (the default)
$sftp->put('remote.txt', 'hello, world');

// 2. Upload from a local file
$sftp->put('remote.txt', '/path/to/local.txt', SFTP::SOURCE_LOCAL_FILE);

// 3. Upload from a stream resource
$fp = fopen('php://stdin', 'r');
$sftp->put('remote.txt', $fp);    // mode detected from resource type

// 4. Upload from a generator (callback)
$sftp->put('remote.txt', function (int $chunkSize): ?string {
    static $i = 0;
    return $i++ < 10 ? "chunk $i\n" : null;   // null ends the upload
}, SFTP::SOURCE_CALLBACK);
```

The default `SOURCE_STRING` mode is a frequent source of confusion: if you pass a filename string without `SOURCE_LOCAL_FILE`, you'll upload the literal filename text (12 bytes for `'filename.ext'`), not the file's contents. Always include `SOURCE_LOCAL_FILE` when you mean the file.

### `put()` — resuming and partial uploads

```php
SFTP::RESUME            // resume by reading $remote_file's size first
SFTP::RESUME_START      // resume from a position in the local source
```

```php
$sftp->put('remote.txt', 'local.txt', SFTP::SOURCE_LOCAL_FILE | SFTP::RESUME);
```

`RESUME` stats the remote file and starts writing from its current end. `RESUME_START` is the local-side equivalent — it starts reading the local file at the existing remote size. If both are set, `RESUME_START` wins.

For finer-grained control, use the `$start` / `$local_start` integer arguments directly (they override the mode flags when ≥ 0):

```php
$sftp->put('remote.txt', 'local.txt', SFTP::SOURCE_LOCAL_FILE,
           start: 1024, local_start: 0);
```

This writes to position 1024 in the remote file, reading from byte 0 of the local file — useful for assembling a file from multiple parts in parallel uploads.

### `put()` — progress callback

```php
$sftp->put('remote.txt', 'local.txt', SFTP::SOURCE_LOCAL_FILE,
           progressCallback: function (int $bytesSent): void {
               echo "Sent $bytesSent bytes\n";
           });
```

The callback receives the cumulative byte count after each chunk. Return value is ignored.

### `get()` — downloading

```php
$contents = $sftp->get('remote.txt');                       // returns string
$sftp->get('remote.txt', '/path/to/local.txt');             // saves to file, returns null
$sftp->get('remote.txt', $resource);                        // writes to a stream resource

$sftp->get('remote.txt', '/local.txt', offset: 1024, length: 4096);
$sftp->get('remote.txt', $callback);                        // a Closure receives chunks
```

When `$local_file` is `null`, the entire file is returned as a string — fine for small files, ruinous for large ones. For anything that might be big, write directly to a file or pass a callback:

```php
$sftp->get('huge.bin', function (string $chunk): void {
    // process incrementally
});
```

`$offset` and `$length` work like `fseek()` + `fread()`: skip `$offset` bytes, then read up to `$length` bytes (or `-1` for "rest of file").

### Preserving timestamps on download

```php
$sftp->enableDatePreservation();
$sftp->get('remote.txt', 'local.txt');     // sets local mtime/atime to match remote
$sftp->disableDatePreservation();
```

Only meaningful when the second argument is a filename string. With resources or callbacks, there's no PHP-level file to `touch()`.

---

## Recursive operations and `getErrors()`

```php
public function getErrors(): array
```

Recursive SFTP operations (`delete($path, true)`, `chmod($path, $mode, true)`, `chown($path, $uid, true)`, `chgrp($path, $gid, true)`, `nlist($dir, true)`, `rawlist($dir, true)`) behave differently from the rest of the API: they keep going past individual failures rather than aborting on the first one.

The rationale: aborting halfway through a recursive delete leaves the tree half-deleted, which is usually worse than continuing and reporting at the end. Same for recursive chmod — a partial walk leaves the tree in a mixed state that's hard to recover.

Errors encountered during the walk are collected in an internal list. After the operation completes, retrieve them:

```php
$sftp->delete('/some/dir', recursive: true);
foreach ($sftp->getErrors() as $err) {
    error_log($err);
}
// Example output:
// REMOVE /some/dir/A (SSH_FX_FAILURE): Failure
// REMOVE /some/dir/B (SSH_FX_PERMISSION_DENIED): Permission denied
// RMDIR /some/dir/A (SSH_FX_FAILURE): Failure
```

Each line has the shape `OPERATION /path (SSH_FX_STATUS_CODE): server message`. The operation prefix tells you which SFTP packet type failed:

- `OPEN` — opening a file (for read or write)
- `OPENDIR` — opening a directory for listing
- `REMOVE` — deleting a file
- `RMDIR` — removing a directory
- `SETSTAT` — applying chmod/chown/chgrp/touch attributes

The status codes are SFTP protocol status codes — `SSH_FX_FAILURE`, `SSH_FX_PERMISSION_DENIED`, `SSH_FX_NO_SUCH_FILE`, `SSH_FX_OP_UNSUPPORTED`, etc. They're sent by the server, not generated by phpseclib.

`getErrors()` returns the collected errors **and clears the internal list**. Two calls back-to-back: the second returns an empty array. Save the result if you need to inspect it more than once.

### Operation-wide failures still throw

The error-collection only applies to per-entry failures inside the recursive walk. Connection loss, protocol errors, or failure to open the *initial* target still throw `FileSystemException` (or `ConnectionClosedException`, etc.) and abort the call:

```php
try {
    $sftp->delete('/nonexistent', recursive: true);
} catch (FileSystemException $e) {
    // The top-level path didn't exist
}
```

The semantics are essentially: "errors visible in the recursion walk → collected; errors that prevent the operation from making progress → thrown."

### Non-recursive operations

Non-recursive variants of these methods throw on failure as normal. `delete($path)` (with `$recursive = false` and `$path` a regular file) throws if the file doesn't exist; `chmod($path, $mode)` (no `$recursive`) throws if the path doesn't exist. The "collect, don't throw" behavior is specific to `$recursive = true`.

---

## SFTP version, extensions, and `copy()` / `posix_rename()` / `statvfs()`

Three SFTP methods rely on server-side extensions and fail with `ServiceUnavailableException` if the server doesn't advertise them.

### `copy()` — server-side copy

```php
public function copy(string $oldname, string $newname): void
```

Requires the `copy-data` extension. Performs the file copy entirely on the server, without round-tripping bytes to the client. Much faster than `get()` + `put()` for large files.

```php
if (isset($sftp->getSupportedExtensions()['copy-data'])) {
    $sftp->copy('huge.bin', 'huge-backup.bin');
} else {
    // fall back to client-side copy
    $sftp->put('huge-backup.bin', $sftp->get('huge.bin'));
}
```

### `posix_rename()` — atomic overwriting rename

```php
public function posix_rename(string $oldname, string $newname): void
```

Like `rename()`, but overwrites `$newname` if it exists, atomically. Uses SFTPv5's `SSH_FXP_RENAME_ATOMIC` flag if available, otherwise the `posix-rename@openssh.com` extension. If neither is supported, throws `ServiceUnavailableException`.

This is what you want for "swap the new version into place" patterns where leaving both files in an inconsistent state for any window is unacceptable.

### `statvfs()` — filesystem info

```php
public function statvfs(string $path): array
// returns ['bsize', 'frsize', 'blocks', 'bfree', 'bavail', 'files', 'ffree',
//          'favail', 'fsid', 'flag', 'namemax']
```

Requires the `statvfs@openssh.com` extension. Returns the fields documented in [`statvfs(3)`](https://man7.org/linux/man-pages/man3/statvfs.3.html) — total/free blocks, total/free inodes, etc. Useful for "do we have space before uploading" checks.

---

## Stat caching and path canonicalization

```php
public function disableStatCache(): void
public function enableStatCache(): void
public function clearStatCache(): void
public function enablePathCanonicalization(): void
public function disablePathCanonicalization(): void
```

By default, SFTP caches `stat()` results so that subsequent calls for the same path don't re-query the server. This is a substantial speedup for `is_dir`/`is_file`/`filesize`/etc. checks during a directory walk, but it means: **if a file changes on the server outside phpseclib's awareness, the cached stat may be stale**.

When to clear or disable:

- The remote filesystem is being modified by other processes between SFTP calls
- You've just `put()` a file and want a fresh `stat()` — though phpseclib invalidates the cache for paths you write to, so this is usually handled automatically
- Long-running scripts where memory pressure from the cache matters

```php
$sftp->disableStatCache();   // skip the cache entirely
$sftp->clearStatCache();     // keep cache enabled but drop current entries
```

Path canonicalization (turning `./foo` and `../bar` into absolute paths) is on by default and runs server-side. Disabling skips the round-trip but means relative paths are sent to the server as-is — only useful when you're already managing canonical paths in your code:

```php
$sftp->disablePathCanonicalization();
$sftp->chdir('/absolute/only/from/here');
```

---

## Listing

```php
public function nlist(string $dir = '.', bool $recursive = false): array
public function rawlist(string $dir = '.', bool $recursive = false, ?\Closure $onFile = null): array
public function setListOrder(string|int ...$args): void
```

`nlist()` returns an array of filename strings (matching PHP's `scandir`). Includes `.` and `..` unless recursive.

`rawlist()` returns an associative array `filename => attribute-array`, where each attribute array has the same shape as `stat()` output — `size`, `mode`, `atime`, `mtime`, `type`, etc. For directories with many entries this is one round trip rather than N stat calls; it's almost always preferable to `nlist` + per-entry `stat`.

```php
foreach ($sftp->rawlist('/var/log') as $name => $attrs) {
    if ($name === '.' || $name === '..') continue;
    echo "$name: {$attrs['size']} bytes\n";
}
```

The optional `$onFile` callback fires per entry as the listing streams in — useful for memory-bounded processing of huge directories:

```php
$sftp->rawlist('/huge-dir', false, function (string $dir, string $name, array $attrs): void {
    // process one entry, accumulator stays small
});
```

### Sort order

```php
$sftp->setListOrder('filename', SORT_ASC);
$sftp->setListOrder('size', SORT_DESC, 'filename', SORT_ASC);
$sftp->setListOrder(true);        // directories first, otherwise unsorted
$sftp->setListOrder();            // no sorting (server's natural order)
```

`setListOrder()` takes `(field, direction, field, direction, ...)` pairs. Valid fields are anything in the `rawlist()` attribute output. Filename comparisons are case-insensitive.

The setting is sticky on the SFTP instance until changed; subsequent `nlist`/`rawlist` calls all use it.

---

## SCP

```php
use phpseclib4\Net\SCP;

$scp = new SCP('ssh.example.com');
$scp->login('alice', $key);

$scp->put('remote.txt', 'hello, world');
$scp->put('remote.bin', 'local.bin', SCP::SOURCE_LOCAL_FILE);

$content = $scp->get('remote.txt');                    // returns string
$scp->get('remote.bin', '/path/to/local.bin');         // writes to file
```

`SCP` extends `SSH2`, so connection, authentication, host-key verification, and algorithm selection all work identically.

The API surface is just `put()` and `get()`:

```php
public function put(
    string $remote_file,
    #[SensitiveParameter] mixed $data,
    int $mode = self::SOURCE_STRING,
    ?\Closure $callback = null
): void

public function get(
    string $remote_file,
    mixed $local_file = null,
    ?\Closure $progressCallback = null
): ?string
```

The same `SOURCE_STRING` / `SOURCE_LOCAL_FILE` gotcha applies as with SFTP — without `SOURCE_LOCAL_FILE`, the second argument is treated as literal bytes. There is no `SOURCE_CALLBACK` for SCP because SCP requires the file size upfront in its protocol greeting, and a callback can't supply that.

### When to use SCP vs SFTP

SFTP is what you almost always want:

- Stat, list, chmod, delete, rename — SCP can do none of these
- Resume partial transfers — SCP can't
- Progress callbacks during the transfer — works in both but cleaner in SFTP
- Per-byte control over offsets — SFTP only

SCP is appropriate when:

- The server has SFTP disabled but SCP enabled (still occasionally seen on legacy boxes)
- You specifically need scp-compatible behavior (e.g., interop with shell scripts using `scp` on the other end)
- Minimal dependency surface — SCP code is ~200 lines, much smaller than SFTP

Both protocols have known security warts compared to SFTP. OpenSSH 8.0+ defaults `scp` itself to use SFTP under the hood for new installations; the SCP wire protocol is regarded as legacy. Use SFTP unless you have a specific reason not to.

---

## The SFTP stream wrapper

```php
use phpseclib4\Net\SFTP\Stream;

Stream::register();        // registers the 'sftp://' protocol
Stream::register('sftp2'); // or under a custom protocol name
```

After registering, PHP's filesystem functions work over SFTP:

```php
$contents = file_get_contents('sftp://alice:password@host//etc/hostname');
file_put_contents('sftp://alice:password@host//tmp/out.txt', $data);
copy('/local.txt', 'sftp://alice:password@host//remote.txt');

$dir = dir('sftp://alice:password@host//var/log');
while (($file = $dir->read()) !== false) {
    echo $file, "\n";
}
$dir->close();
```

Note the double slash before the absolute path: `sftp://user:pass@host//absolute/path`. The first slash terminates the host portion; the second is the leading slash of the absolute path. For paths relative to the user's home: `sftp://user:pass@host/relative/path` (single slash).

### Authentication via context

For anything beyond username + password, pass credentials via a stream context:

```php
use phpseclib4\Crypt\PublicKeyLoader;

$key = PublicKeyLoader::load(file_get_contents('id_ed25519'));

$ctx = stream_context_create([
    'sftp' => [
        'username' => 'alice',
        'privkey'  => $key,        // accepts a phpseclib4 PrivateKey
    ],
]);

$contents = file_get_contents('sftp://host//etc/hostname', false, $ctx);
```

Pass an existing connected `SFTP` instance via `'session'` or `'sftp'` to skip the connect/login step entirely (the wrapper reuses your connection):

```php
$ctx = stream_context_create(['sftp' => ['session' => $existingSftp]]);
file_put_contents('sftp://host//tmp/out.txt', $data, 0, $ctx);
```

### Connection reuse

The wrapper caches `SFTP` instances keyed by `(host, port, username, password/key)`. Repeated `file_get_contents()` calls with the same URL reuse one underlying connection — useful when iterating over many files. The cache is process-global (a static class property), so it persists across function boundaries.

### Stream notifications

PHP's `STREAM_NOTIFY_*` callbacks work if you provide a `notification` callable in the context, mirroring the FTP wrapper's behavior:

```php
$ctx = stream_context_create([
    'sftp' => ['username' => 'alice', 'password' => 'pass'],
    'notification' => function (int $code, int $severity, string $msg, ...): void {
        // STREAM_NOTIFY_CONNECT, STREAM_NOTIFY_AUTH_REQUIRED, STREAM_NOTIFY_AUTH_RESULT,
        // STREAM_NOTIFY_PROGRESS, STREAM_NOTIFY_FAILURE
    },
]);
```

### Limitations

The wrapper is convenient but loses access to phpseclib's richer API. You can't pass a progress callback to a `file_get_contents()`-over-SFTP call, you can't set `RESUME` flags, you can't access `getErrors()` after a recursive operation. For anything beyond "open this remote file like a local one," use the `SFTP` class directly.
