# phpseclib AI assistant resources

AI assistant resources for [phpseclib](https://github.com/phpseclib/phpseclib) 4.0 — a Claude skill, paste-ready prompts, and reference docs that teach LLMs how to write idiomatic phpseclib 4.0 code and migrate phpseclib 3.0 code to 4.0.

If you're working on a phpseclib 3.0 codebase that needs to move to 4.0, or starting a new project against 4.0 and want your AI coding assistant to actually know the API, this repo is for you.

## Why this exists

phpseclib 4.0 is a significant release. The X.509 subsystem was redesigned around static `::load()` factories, ArrayAccess, and a new signing direction. PFX, CMS, and SPKAC became first-class top-level classes. SFTP gained typed exceptions and one critical argument-order fix. Most LLMs were trained on phpseclib 3.0 (or worse, 2.0) code and confidently produce output that won't work against 4.0 — wrong namespaces, wrong method names, wrong argument orders.

The resources in this repo close that gap. They give your AI assistant the same context a phpseclib maintainer would give a new contributor: what changed, what the new idioms are, and what mistakes to watch for.

## Quick install

| Tool | Resource | How to install |
| --- | --- | --- |
| **Claude** (claude.ai, desktop, mobile) | [`claude-skill/phpseclib-4/`](claude-skill/phpseclib-4/) | Download the folder as a `.zip` and upload it under Settings → Capabilities → Skills |
| **Claude Code** | [`claude-skill/phpseclib-4/`](claude-skill/phpseclib-4/) | Copy the folder into `~/.claude/skills/` |
| **ChatGPT, Gemini, anything else** | [`portable/phpseclib-4-for-llms.md`](portable/phpseclib-4-for-llms.md) | Paste into the system prompt, attach as a file, or include in a custom GPT's instructions |
| **Cursor** | [`cursor/phpseclib-4.mdc`](cursor/) | _Coming soon_ — drop into `.cursor/rules/` once published |

After installing, just write naturally — "help me convert this phpseclib 3 code to 4," "show me how to sign a CSR with phpseclib 4," "why is `$sftp->chmod(0777, 'file')` throwing a TypeError?" — and the assistant will pull in the right context automatically.

## What's in the box

```
.
├── claude-skill/                    # Claude-format skill (SKILL.md + references + scripts)
│   └── phpseclib-4/
├── portable/                        # Single-file guide for any LLM
│   └── phpseclib-4-for-llms.md
├── source/                          # Canonical content; the above are built from this
└── README.md
```

The `source/` folder is the single source of truth. Everything else is either generated from it or a thin wrapper around it. If you want to fix a typo or add a missing pattern, edit `source/` and the build will propagate it.

## What this is _not_

A few things worth being explicit about:

**This is not a magic migration tool.** It does not auto-rewrite your codebase. It makes your AI assistant much better at the rewriting, but a human still needs to drive, review, and test the output. Migrations of non-trivial codebases will still take real work; the tooling just removes the "the LLM keeps generating phpseclib 2.0 patterns" friction.

**This is not a substitute for the phpseclib docs.** The official documentation at [phpseclib.com](https://phpseclib.com) is the authoritative reference. The resources here are condensed, LLM-focused excerpts. When the assistant gets something subtle wrong, the docs are where to look.

**This is not a security audit.** LLMs writing cryptography code can produce output that looks right and is subtly wrong. Treat AI-generated phpseclib code the way you would AI-generated SQL: read it carefully, run it under tests, and don't deploy it to production without review. The skill includes guardrails that help (it tells the model to prefer EC over RSA when keys aren't specified, to call `validateSignature()` after `addCA()`, etc.) but it cannot prevent every mistake.

## Compatibility

| | Status |
| --- | --- |
| phpseclib 4.0 | Supported |
| phpseclib 3.0 | Recognized; used as the migration source |
| phpseclib 2.0 and 1.0 | Recognized for version detection only — get the code onto a current 3.0 release first, then to 4.0 from there |

**You may not need to migrate.** [`phpseclib/phpseclib3_compat`](https://github.com/phpseclib/phpseclib3_compat) emulates the entire `phpseclib3\` API on top of phpseclib 4.0, so existing 3.0 code can keep working while the underlying library is upgraded. It also "provides" `phpseclib/phpseclib:~3.0` to Composer, which means it satisfies dependencies that pin to 3.0 (Google's PHP API client, for example). For many projects — especially ones with substantial X.509 code — installing the compat shim is faster, safer, and just as functional as a full rewrite. The skill, the Cursor rule, and the portable guide all surface this option before recommending a migration.

## Reporting issues

If your AI assistant generates phpseclib code that doesn't work, the most useful thing you can do is open an issue with:

1. The exact prompt you used.
2. The output the assistant produced.
3. What you expected instead, or the error you got.
4. Which tool (Claude, ChatGPT, Cursor, etc.) and which model version, if known.

The skill improves through this kind of feedback. "It generated `Crypt_RSA` again" is more actionable than "it's broken."

## Contributing

PRs welcome, especially:

- New 3.0 → 4.0 migration patterns you ran into that aren't covered.
- Trigger phrases that should fire the skill but don't.
- Cases where the assistant generated wrong code despite the skill being active — these are the most valuable bug reports because they tell us where the guidance needs to be sharper.
- Format adapters for tools we don't ship for yet (Aider, Continue, Zed, Windsurf, etc.).

Edit content in `source/` rather than in the per-tool folders directly, so the changes propagate to every output.

## License

Same as phpseclib itself — [MIT](LICENSE).

## See also

- [phpseclib](https://github.com/phpseclib/phpseclib) — the library this is for.
- [phpseclib documentation](https://phpseclib.com) — official docs.
- [Anthropic's skill format spec](https://docs.claude.com/en/docs/claude-code/skills) — if you want to understand or build on the Claude skill format.
