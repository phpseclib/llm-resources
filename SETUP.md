# Setup guide

Step-by-step installation for each supported AI tool. The [README](README.md) gives the overview and helps you pick which artifact you need; this file walks you through actually installing it.

## Which artifact do you need?

| If you're using... | You want... |
| --- | --- |
| Claude (web, desktop, or mobile app) | the **Claude skill** — see [§ Claude (consumer apps)](#claude-consumer-apps) |
| Claude Code (terminal) | the **Claude skill** — see [§ Claude Code](#claude-code) |
| Cursor | the **Cursor rule** — see [§ Cursor](#cursor) |
| ChatGPT, Gemini, Copilot, or anything else | the **portable guide** — see [§ ChatGPT, Gemini, and other LLMs](#chatgpt-gemini-and-other-llms) |
| Building your own tool with the Anthropic API | the **portable guide as a system prompt** — see [§ Custom integrations (API)](#custom-integrations-api) |

If you use multiple tools, install in each one — they don't conflict and don't share state.

---

## Claude (consumer apps)

Works on claude.ai, the desktop app (macOS/Windows/Linux), and the mobile app (iOS/Android). Skills installed in any one of these are available in all of them; the skill follows your account, not your device.

### Install

1. **Download the skill bundle.** Grab `phpseclib-4-skill.zip` from the [latest release](https://github.com/phpseclib/llm-resources/releases/latest). Don't unzip it — Claude wants the `.zip` directly.

2. **Open Claude's settings.** On the web: click your name (or initials) in the bottom-left, then **Settings**. On desktop: same path. On mobile: tap the menu icon, then **Settings**.

3. **Find the Skills section.** Look under **Capabilities** or **Features** — Anthropic occasionally renames or relocates this; if you don't see "Skills," check Anthropic's [official skills documentation](https://docs.claude.com) for the current path.

4. **Upload the bundle.** Click **Upload skill** (or **Add skill**, or similar) and select the `.zip` you downloaded.

5. **Confirm the skill is enabled.** It should appear in your skills list with a toggle switched on. If the toggle is off, switch it on.

### Verify it worked

Start a new conversation and paste this prompt:

> Are you familiar with phpseclib 4.0? What's the canonical way to sign an X.509 cert in 4.0?

If the skill is active, Claude should mention `$privKey->sign($x509)` (key signs object), explain that you should `echo $x509` afterwards rather than `echo $privKey->sign($x509)`, and reference concepts like the `Signable` interface or the `phpseclib4\` namespace. If Claude instead gives a generic answer about phpseclib without those specifics, the skill isn't loading — see [§ Troubleshooting](#troubleshooting).

### Updating

When a new version of the skill is released:

1. Download the new `.zip` from the [releases page](https://github.com/phpseclib/llm-resources/releases).
2. In Claude's Skills settings, **remove the old version first** (click the skill, then **Delete** or **Remove**).
3. Upload the new one.

Anthropic doesn't currently auto-detect skill updates, so manual update is required. If you skip the "remove old version first" step, you may end up with two versions of the skill installed and Claude will pick one inconsistently.

---

## Claude Code

Claude Code (the terminal tool) loads skills from a local directory, so installation is a `cp` away.

### Install

1. **Clone the repo or download the source archive:**

   ```bash
   git clone https://github.com/phpseclib/llm-resources.git
   ```

   Or download the source `.zip` from the [releases page](https://github.com/phpseclib/llm-resources/releases) and extract it.

2. **Copy the skill folder into Claude Code's skills directory:**

   ```bash
   mkdir -p ~/.claude/skills
   cp -r llm-resources/claude-skill/phpseclib-4 ~/.claude/skills/
   ```

   On Windows (PowerShell):

   ```powershell
   New-Item -ItemType Directory -Force -Path "$HOME\.claude\skills"
   Copy-Item -Recurse llm-resources\claude-skill\phpseclib-4 "$HOME\.claude\skills\"
   ```

3. **Restart Claude Code** (or start a new session) so it picks up the new skill.

### Verify it worked

In a Claude Code session, run:

```
/skills
```

You should see `phpseclib-4` in the list. Then ask:

> Are you familiar with phpseclib 4.0?

Same expected response as the consumer-app verification above.

### Updating

```bash
cd llm-resources
git pull
cp -r claude-skill/phpseclib-4 ~/.claude/skills/
```

The `cp -r` will overwrite the existing files. If you want a clean install instead, `rm -rf ~/.claude/skills/phpseclib-4` first.

### Symlink alternative (for development)

If you want changes to the repo to be reflected in Claude Code immediately without re-copying, symlink instead of copy:

```bash
ln -s "$PWD/llm-resources/claude-skill/phpseclib-4" ~/.claude/skills/phpseclib-4
```

This is mostly useful for contributors editing the skill itself; regular users should stick with `cp`.

---

## Cursor

Cursor uses `.mdc` rule files placed in a project's `.cursor/rules/` directory.

### Install (per-project)

1. **Download** [`cursor/phpseclib-4.mdc`](https://raw.githubusercontent.com/phpseclib/llm-resources/main/cursor/phpseclib-4.mdc) (right-click, save as).

2. **Place it in your project:**

   ```bash
   mkdir -p .cursor/rules
   mv ~/Downloads/phpseclib-4.mdc .cursor/rules/
   ```

3. **Restart Cursor** (or reload the window) so the rule is picked up.

### Install (globally for all projects)

Cursor also supports user-level rules that apply across every project. The exact path varies by OS — check Cursor's [rules documentation](https://docs.cursor.com/context/rules) for the current location, then place `phpseclib-4.mdc` there instead of in a project's `.cursor/rules/`.

### Verify it worked

Open a PHP file in your project that imports phpseclib (e.g., a file with `use phpseclib4\File\X509;` at the top). Ask Cursor:

> Generate a function that loads an X.509 cert from a PEM string and returns the subject DN.

If the rule is active, the generated code should use `X509::load($pem)`, call `getSubjectDN()` (not `getDN()`), and use the `phpseclib4\` namespace. If you get `(new X509())->loadX509($pem)` or `phpseclib3\` imports, the rule isn't firing — see [§ Troubleshooting](#troubleshooting).

### Updating

Re-download `phpseclib-4.mdc` and replace the existing file. Cursor will pick up the change on next file reload.

---

## ChatGPT, Gemini, and other LLMs

For tools that don't have a skill/rule system, use the portable guide as context.

### As a one-shot prompt prefix

Copy [`portable/phpseclib-4-for-llms.md`](https://raw.githubusercontent.com/phpseclib/llm-resources/main/portable/phpseclib-4-for-llms.md) into your message before your actual question:

```
[paste the full contents of phpseclib-4-for-llms.md here]

---

Now: <your actual question, e.g., "convert this 3.0 code to 4.0">
```

This works in any chat interface but uses ~3,000 tokens of context every time.

### As a custom GPT or Gem (recommended for repeat use)

**ChatGPT (custom GPT):**

1. Go to **Explore GPTs** → **+ Create**.
2. Under **Configure**, paste the contents of `phpseclib-4-for-llms.md` into the **Instructions** field.
3. Optionally, also attach the file under **Knowledge** so the GPT can reference it explicitly.
4. Name it something like "phpseclib 4.0 expert" and save.

**Gemini (Gem):**

1. Open Gemini and click **Gem manager** (or your version's equivalent).
2. Create a new Gem.
3. Paste `phpseclib-4-for-llms.md` into the **Instructions** field.
4. Save.

After creating the GPT or Gem, you can chat with it directly without re-pasting the guide every time.

### As a downloadable file attachment

Most chat interfaces (ChatGPT, Claude.ai, Gemini) accept file attachments. Download `phpseclib-4-for-llms.md` and attach it to a conversation, then ask your question. The model will read the file and use it as context for that conversation only.

### Verify it worked

Whichever method you used, ask:

> Without searching the web: what's the canonical way to sign an X.509 cert in phpseclib 4.0, and what does `$privKey->sign($x509)` return?

Expected answer: it should explain that `$privKey->sign($x509)` returns the raw signature bytes *and* installs the signature into `$x509` as a side effect, so `$privKey->sign($x509); echo $x509;` is the right pattern. If the model gives a generic answer or claims `sign()` returns the signed cert directly, the guide isn't being used — see [§ Troubleshooting](#troubleshooting).

---

## Custom integrations (API)

If you're building your own tool against the Anthropic API (or OpenAI, Google, etc.) and want phpseclib 4.0 expertise baked in:

### Anthropic API

```python
import anthropic

with open("phpseclib-4-for-llms.md") as f:
    phpseclib_guide = f.read()

client = anthropic.Anthropic()
response = client.messages.create(
    model="claude-sonnet-4-5",
    max_tokens=4096,
    system=f"You are a PHP expert. The following is the canonical guide for phpseclib 4.0; defer to it over any prior training:\n\n{phpseclib_guide}",
    messages=[{"role": "user", "content": "..."}],
)
```

The guide is ~3,000 tokens. Anthropic's prompt caching (`cache_control: {"type": "ephemeral"}`) makes the per-request cost negligible if you're making many calls.

### OpenAI / Gemini / others

Same shape — load the guide as a string and put it in the system prompt or developer message. Format slightly varies by API.

### A note on freshness

The guide is updated whenever the underlying skill is updated. For long-running deployments, fetch a fresh copy periodically (e.g., daily via CI) rather than vendoring an old version into your codebase.

---

## Troubleshooting

### The skill/rule appears installed but Claude/Cursor isn't using it

Skills and rules activate based on what the model thinks is relevant for the current conversation. If your prompt doesn't mention phpseclib by name and your code doesn't have phpseclib imports, the model may not pull in the skill at all — that's working as intended (skills are token-budget-aware).

To force the skill to load, mention phpseclib explicitly:

> Using the phpseclib 4.0 skill: <your question>

Or paste a code snippet that imports `phpseclib4\` so the trigger fires on the namespace match.

### The model is still generating phpseclib 3.0 code

Most likely causes, in order:

1. **The skill/rule isn't loaded.** Check the verification steps above for your tool.
2. **The skill is loaded but the model is hallucinating from training data.** Push back: "That's phpseclib 3.0 code. Per the phpseclib 4.0 skill, what's the 4.0 equivalent?" The model should self-correct.
3. **You're using an older model.** Skills work best with current Claude models (Sonnet 4 and later for the consumer apps; Sonnet 4.5 for Claude Code). Earlier models may have weaker skill-following behavior.

### "Unknown frontmatter field" or similar errors during install

Skills and Cursor rules use YAML frontmatter, and the spec evolves. If your version of Claude/Cursor rejects a frontmatter field that the artifact provides:

1. Check that you're on a current version of the tool — Anthropic and Cursor both ship updates regularly that affect what frontmatter is accepted.
2. Check the [issues page](https://github.com/phpseclib/llm-resources/issues) — if the tool's frontmatter schema changed, there may be a newer version of the artifact pending or already published.

### Code is still being generated with `chmod(0777, $path)` instead of `chmod($path, 0777)`

This is the single most common LLM mistake on phpseclib 4.0, because every model's training data overwhelmingly contains 3.0-style calls. The skill flags this explicitly, but if you're seeing the wrong order:

1. Check that the skill or rule is actually loaded (verification steps above).
2. Push back on the wrong code: "In phpseclib 4.0, `chmod` takes the path first, not the mode. Please fix."
3. If you keep seeing the same mistake from the same tool, [open an issue](https://github.com/phpseclib/llm-resources/issues) — that's a signal the skill's mistake-list isn't being weighted heavily enough by that particular model, and we can adjust the wording.

### Composer errors when installing phpseclib 4.0 alongside other packages

This isn't a skill problem — it's a Composer dependency conflict. Most likely you have a third-party package that requires `phpseclib/phpseclib:~3.0` (Google's PHP API client is the common one). Solutions:

- **Use the compat shim:** `composer require phpseclib/phpseclib3_compat`. This will satisfy the 3.0 requirement while running on 4.0 underneath. Your existing 3.0-style code keeps working unchanged.
- **Wait for the third-party package to update.** Some maintainers will bump their phpseclib requirement to allow 4.0 once it's stable.
- **Fork or patch the third-party package's `composer.json`** to allow 4.0 (only safe if you've checked that the package's actual usage works against 4.0).

The skill discusses this at length under "the compat shim" sections — if you ask Claude (or any tool with the skill loaded) about Composer conflicts, it should walk you through these options.

### Something else

[Open an issue](https://github.com/phpseclib/llm-resources/issues) with:

1. Which tool you're using and its version.
2. What you tried (which install method).
3. What you expected vs. what happened.
4. Any error messages, copy-pasted verbatim.

The more specific the report, the faster the fix.

---

## Uninstalling

### Claude (consumer apps)

Settings → Capabilities → Skills → click the phpseclib-4 skill → **Delete** (or **Remove**).

### Claude Code

```bash
rm -rf ~/.claude/skills/phpseclib-4
```

### Cursor

```bash
rm .cursor/rules/phpseclib-4.mdc
```

### Custom GPT / Gem

Delete the GPT or Gem from your tool's GPT/Gem manager.

### API integrations

Remove the guide from your system prompt and redeploy.
