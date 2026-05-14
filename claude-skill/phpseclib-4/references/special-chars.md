# Special characters for `SSH2::write()`

Lookup table for control sequences you'd write into an interactive shell via `$ssh->write(...)`. These are the byte sequences a real terminal sends when the user presses the corresponding key. The terminal type matters — phpseclib uses `vt100` by default (override with `$ssh->setTerminal('xterm')`); the sequences below are what `vt100`/`xterm` programs expect.

For background on when to use these and how they fit with the ANSI emulator, see [`references/ssh2-sftp.md` → Interactive shells](ssh2-sftp.md#interactive-shells-read--write).

## Quick reference

| Key | PHP string | Bytes (hex) |
|---|---|---|
| <kbd>Ctrl</kbd>+<kbd>C</kbd> | `"\x03"` | 03 |
| <kbd>Ctrl</kbd>+<kbd>D</kbd> | `"\x04"` | 04 |
| <kbd>Ctrl</kbd>+<kbd>Z</kbd> | `"\x1A"` | 1A |
| <kbd>Esc</kbd> | `"\x1B"` | 1B |
| <kbd>Tab</kbd> | `"\t"` | 09 |
| <kbd>Enter</kbd> | `"\n"` | 0A |
| <kbd>Backspace</kbd> | `"\x7F"` | 7F |
| <kbd>↑</kbd> | `"\x1B[A"` | 1B 5B 41 |
| <kbd>↓</kbd> | `"\x1B[B"` | 1B 5B 42 |
| <kbd>→</kbd> | `"\x1B[C"` | 1B 5B 43 |
| <kbd>←</kbd> | `"\x1B[D"` | 1B 5B 44 |
| <kbd>Home</kbd> | `"\x1B[H"` | 1B 5B 48 |
| <kbd>End</kbd> | `"\x1B[F"` | 1B 5B 46 |
| <kbd>Page Up</kbd> | `"\x1B[5~"` | 1B 5B 35 7E |
| <kbd>Page Down</kbd> | `"\x1B[6~"` | 1B 5B 36 7E |
| <kbd>Insert</kbd> | `"\x1B[2~"` | 1B 5B 32 7E |
| <kbd>Delete</kbd> | `"\x1B[3~"` | 1B 5B 33 7E |
| <kbd>F1</kbd> | `"\x1BOP"` | 1B 4F 50 |
| <kbd>F2</kbd> | `"\x1BOQ"` | 1B 4F 51 |
| <kbd>F3</kbd> | `"\x1BOR"` | 1B 4F 52 |
| <kbd>F4</kbd> | `"\x1BOS"` | 1B 4F 53 |
| <kbd>F5</kbd> | `"\x1B[15~"` | 1B 5B 31 35 7E |
| <kbd>F6</kbd> | `"\x1B[17~"` | 1B 5B 31 37 7E |
| <kbd>F7</kbd> | `"\x1B[18~"` | 1B 5B 31 38 7E |
| <kbd>F8</kbd> | `"\x1B[19~"` | 1B 5B 31 39 7E |
| <kbd>F9</kbd> | `"\x1B[20~"` | 1B 5B 32 30 7E |
| <kbd>F10</kbd> | `"\x1B[21~"` | 1B 5B 32 31 7E |
| <kbd>F11</kbd> | `"\x1B[23~"` | 1B 5B 32 33 7E |
| <kbd>F12</kbd> | `"\x1B[24~"` | 1B 5B 32 34 7E |

## Anatomy of these sequences

Most non-printable keys produce an *escape sequence*: the byte `0x1B` (ESC, written `\x1B` in PHP strings) followed by one or more characters that describe which key. Two introducer characters are common:

- **`\x1B[…`** — "CSI" (Control Sequence Introducer). The `[` here is the literal `[` character (byte `0x5B`), not metasyntax. Used by cursor keys, page navigation, and F5+.
- **`\x1BO…`** — "SS3" (Single Shift 3). The `O` is a literal uppercase O (byte `0x4F`). Used by F1–F4 in `vt100`/`xterm`.

The F-key split between `O` and `[` is a historical wart from the original VT100 keyboard, which had four function keys distinct from the rest. Modern terminals preserve the split for backward compatibility.

## PHP escape syntax — easy to get wrong

A PHP double-quoted string interprets `\xNN` as one byte and stops. Adjacent characters are *literal*, not part of the escape:

```php
"\x1B[A"   // 3 bytes: 1B 5B 41   — correct arrow-up
"\x1B5B41" // 6 bytes: 1B 35 42 34 31  — WRONG (literal chars 5,B,4,1)
```

If you see a phpseclib snippet anywhere with `"\x1B5B…"` for an arrow key, it's a typo. The two-digit hex form `\xNN` only consumes the two hex digits immediately after `\x`; you can't pack multiple bytes into one escape. Either use multiple `\x` escapes (`"\x1B\x5B\x41"`) or — much clearer for printable ASCII bytes — write them as literals (`"\x1B[A"`).

Single-quoted strings don't process escapes at all, so `'\x1B[A'` is the 5-character literal backslash-x-1-B-[-A — also wrong. Always use double quotes for control sequences.

## Sending these via `write()`

```php
$ssh->setTimeout(2);
$ssh->read();
$ssh->write("vim\n");
$ssh->read();
$ssh->write("\x1BOP");        // press F1 inside vim
$ssh->write("\x1B[A\x1B[A");  // up-arrow twice
```

For commands like `vim` and `top` that draw a full-screen UI, pair this with `phpseclib4\File\ANSI` to render the response — see the `ssh2-sftp.md` reference for the pattern.

## Terminal-type caveat

The sequences above are correct for `vt100` (phpseclib's default) and for `xterm`. Other terminal types produce different sequences for some keys — notably, `xterm`-mode F1–F4 can be either `\x1BOP…` or `\x1B[11~…` depending on the application keypad state. If your server-side program isn't responding to a sequence, check what terminal type you've set (`$ssh->setTerminal(...)`) and whether the program is querying it via `$TERM`.
