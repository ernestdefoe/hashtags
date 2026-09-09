# Hashtags

[![Flarum 2.0](https://img.shields.io/badge/Flarum-%5E2.0-orange)](https://flarum.org/)
[![Packagist](https://img.shields.io/packagist/v/ernestdefoe/hashtags)](https://packagist.org/packages/ernestdefoe/hashtags)
[![License MIT](https://img.shields.io/badge/license-MIT-blue)](https://github.com/ernestdefoe/hashtags/blob/main/LICENSE.md)

**Freeform hashtags for Flarum 2.** Write `#anything` in a post and it becomes a link to a feed of every discussion using it. No admin setup, no pre-created tags, nothing to configure — if somebody types it, it exists.

![Hashtags and a tag mention in the same sentence](https://raw.githubusercontent.com/ernestdefoe/hashtags/2.0.0/docs/images/inline-hashtags.png)

That one line is the whole design. `#gameday` and `#upsetalert` are freeform hashtags nobody created in advance. `General` is a real Flarum tag, and it still renders as a tag mention. Both use `#`, in the same sentence, and neither gets in the other's way.

## It does not fight flarum/mentions

`flarum/mentions` already uses `#` for tag mentions, so the obvious assumption is that a hashtag extension has to patch it, disable it, or move one of the two onto a different symbol.

It doesn't. **This extension does not touch flarum/mentions at all.** Both matchers are registered and position in the text decides:

| You write | A tag with that slug exists | No such tag |
| --- | --- | --- |
| `#general` | tag mention, links to `/t/general` | freeform hashtag |
| `#foryourpage` | — | freeform hashtag, links to `/hashtag/foryourpage` |

Core's tag-mention pattern consumes the character *before* the `#`, so its tag begins one byte earlier in the text, and s9e/TextFormatter offers it the text first. If the slug is a real tag it wins and the cursor moves past it. If it isn't, the mention invalidates itself without moving the cursor, and the hashtag claims the same position.

Precedence is therefore **positional**, which means it doesn't depend on extension load order. **A tag slug is never shadowed by a hashtag, and a hashtag never has to be registered in advance.**

## Autocomplete, shared with your tags

![One dropdown offering a tag and a hashtag together](https://raw.githubusercontent.com/ernestdefoe/hashtags/2.0.0/docs/images/composer-autocomplete.png)

Typing `#` offers existing hashtags **and** tags in a single dropdown — that's `#speedr` matching the **Speedrun** tag and the **#speedrunfriday** hashtag at once. It registers onto core's existing `#` format rather than adding a competing trigger, so there is one symbol and one list.

This isn't decoration. It's what makes a freeform namespace work: without suggestions you end up with `#gameday`, `#GameDay` and `#game-day` as three separate feeds inside a week, because nobody can see what already exists while they type.

## A feed per hashtag, and a page to browse them

![The feed for a single hashtag](https://raw.githubusercontent.com/ernestdefoe/hashtags/2.0.0/docs/images/hashtag-feed.png)

`/hashtag/:name` is a stock discussion list driven by a `hashtag:` search filter, so visibility scoping, pagination and the sort dropdown are Flarum's own rather than a reimplementation. A hashtag used only inside discussions you can't see gives you an empty feed.

![Browsing every hashtag in use](https://raw.githubusercontent.com/ernestdefoe/hashtags/2.0.0/docs/images/browse-page.png)

`/hashtags` browses everything currently in use, with search and popular / recently used / A–Z. Both pages are linked from the index navigation, and `hashtag:gameday` / `-hashtag:gameday` work in the search box.

## Details that matter

- **Case-insensitive matching, case-preserving display.** `#GameDay` and `#gameday` are one hashtag with one feed and one canonical URL, but each post renders the casing its author typed.
- **Safe by default.** Nothing is matched inside code spans, fenced blocks or indented blocks; a `#fragment` in a URL is left alone; `C#`, `F#` and `foo#bar` never match; `#42` is left for issue-reference extensions.
- **Unicode.** `#café`, `#日本語` and `#multi-word-thing` all work.
- **Hidden posts contribute nothing**, and deleting a post corrects the counts.
- **No settings**, on purpose — hashtags exist because a post says so, and anything configurable would contradict the text that created it.

## Installing

```
composer require ernestdefoe/hashtags
php flarum cache:clear
```

Flarum 2.0+ and PHP 8.2+. `flarum/tags` and `flarum/mentions` are both optional — it works without either and integrates with both.

**Already have posts?** Flarum stores posts as parsed XML, so a `#gameday` written before you installed this is sitting in a plain text node and will never become a link on its own. One command fixes that:

```
php flarum hashtags:reindex --dry-run
php flarum hashtags:reindex
```

Only posts that actually contain a hashtag are rewritten — everything else is read and skipped — and it's idempotent, so a second run reports 0 updated.

## Links

- **GitHub:** https://github.com/ernestdefoe/hashtags
- **Packagist:** https://packagist.org/packages/ernestdefoe/hashtags
- **Bug reports:** https://ernestdefoe.online/t/hashtags
- **Licence:** MIT

Bug reports and feature requests are welcome in the support tag above.
