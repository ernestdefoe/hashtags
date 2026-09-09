# \#️⃣ Hashtags for Flarum 2 — freeform `#hashtags` that don't fight your tags

Hey folks 👋 — this one started as a question I couldn't answer cleanly: **how do you add hashtags to Flarum when `flarum/mentions` already owns `#`?**

The answer turned out to be "you don't have to fight it at all", and that's the whole extension.

[![Flarum 2](https://img.shields.io/badge/Flarum-2.x-6366f1?style=flat-square)](https://flarum.org)
[![Packagist Version](https://img.shields.io/packagist/v/ernestdefoe/hashtags?style=flat-square&color=6366f1&label=version)](https://packagist.org/packages/ernestdefoe/hashtags)
[![Downloads](https://img.shields.io/packagist/dt/ernestdefoe/hashtags?style=flat-square&color=64748b)](https://packagist.org/packages/ernestdefoe/hashtags)
[![License MIT](https://img.shields.io/badge/license-MIT-6366f1?style=flat-square)](https://github.com/ernestdefoe/hashtags/blob/main/LICENSE.md)

Write `#anything` in a post and it becomes a link to a feed of every discussion using it. No admin setup, no pre-created tags, nothing to configure. If somebody types it, it exists.

![A hashtag and a tag mention in the same sentence](https://raw.githubusercontent.com/ernestdefoe/hashtags/2.0.0/docs/images/inline-hashtags.png)

Look closely at that line, because it's the entire design. `#gameday` and `#upsetalert` are freeform hashtags nobody created in advance. `General` is a real Flarum tag, and it **still renders as a tag mention**. Same symbol, same sentence, neither one in the other's way.

---

## 1️⃣ It doesn't patch `flarum/mentions`

This is the part I want to be upfront about, because "hashtag extension for Flarum" usually implies a collision.

There isn't one. **Nothing in `flarum/mentions` is patched, disabled, reordered or wrapped.** Both matchers are registered and *position in the text* decides the winner:

| You write | A tag with that slug exists | No such tag |
| --- | --- | --- |
| `#general` | tag mention → `/t/general` | freeform hashtag |
| `#foryourpage` | — | freeform hashtag → `/hashtag/foryourpage` |

Core's tag-mention pattern opens with `(?:[^“"]|^)`, so it consumes the character *before* the `#`. Its tag therefore begins one byte earlier in the text, and s9e/TextFormatter sorts pending tags by position — so the tag mention is always offered the text first.

- Slug **is** a real tag → the mention applies, the parser cursor advances past it, and the hashtag tag is discarded. The tag wins, as it should.
- Slug is **not** a tag → `addTagId` never sets `id`/`tagname`, both are required attributes, `filterAttributes` invalidates it. An invalidated tag doesn't advance the cursor, so `HASHTAG` is offered the same position and claims it.

Because precedence is **positional**, it doesn't depend on extension load order — which matters, since the Preg plugin hardcodes `sortPriority` `-100` for every matcher, so priority isn't a lever available to anyone.

Net effect: **a tag slug is never shadowed by a hashtag, and a hashtag never has to be registered in advance.**

## 2️⃣ Autocomplete, shared with your existing tags

![One dropdown offering a tag and a hashtag together](https://raw.githubusercontent.com/ernestdefoe/hashtags/2.0.0/docs/images/composer-autocomplete.png)

Typing `#` offers existing hashtags **and** tags in one dropdown — that's `#speedr` matching the **Speedrun** tag and the **#speedrunfriday** hashtag simultaneously. It registers onto core's existing `HashMentionFormat` (which is `extendable`) rather than adding a competing trigger, so there's one symbol and one list.

This isn't polish, it's load-bearing. A freeform namespace only works if people can see what already exists while they type — otherwise you get `#gameday`, `#GameDay` and `#game-day` as three separate feeds inside a week.

## 3️⃣ A feed per hashtag

![The feed for a single hashtag](https://raw.githubusercontent.com/ernestdefoe/hashtags/2.0.0/docs/images/hashtag-feed.png)

`/hashtag/:name` is a stock `DiscussionList` driven by a `hashtag:` search filter — so visibility scoping, pagination and the sort dropdown are core's own rather than a reimplementation I'd have to keep in step. A hashtag used only inside discussions you can't see gives you an empty feed, not a leak.

`hashtag:gameday` and `-hashtag:gameday` also work directly in the search box.

## 4️⃣ A page to browse them

![Browsing every hashtag in use](https://raw.githubusercontent.com/ernestdefoe/hashtags/2.0.0/docs/images/browse-page.png)

`/hashtags` shows everything currently in use with search and popular / recently used / A–Z. Both pages are linked from the index navigation.

## 🧠 What matches, and what deliberately doesn't

**Matched:** `#gameday` · `#GameDay` · `#multi-word-thing` · `#two_words` · `#café` · `#日本語` · `#abc123`

| Input | Why it's left alone |
| --- | --- |
| `C#`, `F#`, `foo#bar` | `\B#` requires the `#` not be preceded by a word character |
| `#42` | at least one letter is required, so issue-reference extensions keep `#123` |
| `# Heading` | Markdown headings have a space |
| `` `#gameday` `` | code spans, fenced blocks and indented blocks are untouched |
| `example.com/page#gameday` | the URL tag starts earlier and runs longer, so it wins |
| a 60+ character word | no word boundary to close on, so it simply isn't a hashtag |

All verified against Litedown and Autolink with both enabled — not assumed.

**Case:** matching is case-insensitive, display is not. `#GameDay` and `#gameday` are one hashtag, one feed, one canonical URL — but each post renders the casing its author typed.

## 🚀 Quick start

```
composer require ernestdefoe/hashtags
php flarum cache:clear
```

Flarum 2.0+, PHP 8.2+. `flarum/tags` and `flarum/mentions` are both **optional** — it works without either and integrates with both when present. There are no settings, on purpose: hashtags exist because a post says so, and anything configurable would contradict the text that created them.

## 🗂️ Already have years of posts?

Flarum stores posts as **parsed XML**, so a `#gameday` written before you installed this is sitting in a plain text node with no hashtag element in it. It will never become a link on its own, however many times you reload. Re-parsing is the only thing that changes that — which is exactly what happens when someone edits a post.

```
php flarum hashtags:reindex --dry-run   # report only, writes nothing
php flarum hashtags:reindex
```

It filters twice before touching anything — a SQL filter for posts containing `#` at all, then a regex check on the unparsed source — so only posts that genuinely contain a hashtag are ever rewritten. Idempotent: a second run reports `0 updated`.

## 🏗️ Under the hood (for the curious)

Two tables: `hashtags` (one row per distinct hashtag, keyed on a case-folded `name_key`) and `post_hashtag` (with `discussion_id` denormalised so the feed is one indexed subquery rather than a join through posts).

Counters are **recomputed** from the pivot on every sync rather than incremented. An aggregate over a narrow index can't drift; a counter incremented on save and decremented on delete drifts the first time a queued job retries or a row vanishes through a foreign-key cascade — which fires no Flarum event at all.

The API resource is read-only deliberately. Hashtags come into being by being written and stop existing when the last post using one is edited or deleted, so an admin "editing" one would immediately disagree with the post text that produced it.

## 🔗 Links

- **GitHub:** https://github.com/ernestdefoe/hashtags
- **Packagist:** https://packagist.org/packages/ernestdefoe/hashtags
- **Bug reports:** https://ernestdefoe.online/t/hashtags
- **Licence:** [MIT](https://github.com/ernestdefoe/hashtags/blob/main/LICENSE.md)

## 💬 Feedback welcome

v2.0.0 just shipped. I'm especially interested in edge cases where the precedence feels wrong on your forum — a hashtag you expected that resolved to a tag instead, or the other way round — and in anything the pattern matches that it shouldn't.

Bug reports and feature ideas on [ernestdefoe.online](https://ernestdefoe.online/t/hashtags) or as a [GitHub issue](https://github.com/ernestdefoe/hashtags/issues), whichever you prefer.

Happy hashtagging! 🎉

— Ernest
