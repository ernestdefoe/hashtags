# Hashtags

Freeform hashtags for Flarum 2. Write `#anything` in a post and it becomes a link to a feed of every discussion using it — no admin setup, no pre-created tags.

## How it coexists with tag mentions

`flarum/mentions` already uses `#` for tag mentions, and this extension does not patch, disable or reorder it. Both matchers run, and which one wins is decided by position:

| You write | If a tag with that slug exists | If not |
|---|---|---|
| `#general` | tag mention, links to `/t/general` | freeform hashtag |
| `#foryourpage` | — | freeform hashtag, links to `/hashtag/foryourpage` |

Core's tag-mention pattern opens with `(?:[^“"]|^)`, so it consumes the character *before* the `#` and its tag starts one byte earlier in the text. s9e/TextFormatter sorts pending tags by position, so the tag mention is always offered the text first. When the slug is a real tag it wins and the parser cursor moves past it; when it isn't, its required `id`/`tagname` attributes are never set, `filterAttributes` invalidates it, the cursor doesn't move, and `HASHTAG` claims the same position.

Because precedence is positional it does not depend on extension load order or on `sortPriority` — which matters, since the Preg plugin hardcodes `-100` for every matcher it registers.

## What's safe

Verified against Litedown and Autolink:

- **Not** parsed inside code spans, fenced blocks or indented blocks
- `#fragment` in a bare or Markdown URL is protected — the URL tag starts earlier and runs longer
- `C#`, `F#` and `foo#bar` don't match: `\B#` requires the `#` not be preceded by a word character
- `#42` is left alone — the pattern requires at least one letter, so issue-number extensions keep it
- Markdown `# Heading` is untouched (it has a space)
- Unicode works: `#café`, `#日本語`, `#multi-word-thing`
- Hashtags are capped at 59 characters; a longer run of word characters simply isn't a hashtag

## Case

Matching is case-insensitive, display is not. `#GameDay` and `#gameday` are one hashtag with one feed; each post renders the casing its author typed, and the canonical URL uses the folded form.

## Composer autocomplete

If `flarum/mentions` is installed, typing `#` offers existing hashtags **and** tags in one dropdown — this extension registers onto core's `HashMentionFormat` rather than adding a competing trigger. Without mentions, everything still parses, links and browses; you just lose the suggestions.

The autocomplete is what makes freeform hashtags cohere. Without it you get `#gameday`, `#GameDay` and `#game-day` as three separate feeds inside a week.

## Pages

- `/hashtag/:name` — discussions using a hashtag. A stock `DiscussionList` driven by the `hashtag:` search filter, so visibility scoping, pagination and sorting are core's.
- `/hashtags` — browse everything in use, with search and popular / recent / A–Z.

`hashtag:gameday` and `-hashtag:gameday` also work in the search box.

## Installing on a forum that already has posts

Flarum stores posts as parsed XML, so a post written before this extension existed has `#gameday` sitting in a text node with no hashtag element — it will never render as a link or appear in a feed, however many times the page reloads. Re-parsing is the only fix, and that's what an edit does. To do it in bulk:

```bash
php flarum hashtags:reindex --dry-run   # report only
php flarum hashtags:reindex
```

Only posts that actually contain a hashtag are rewritten. Everything else is read and skipped: first a SQL filter for posts containing `#` at all, then a regex check on the unparsed source. The command is idempotent — a second run reports 0 updated.

## Requirements

- Flarum 2.0+
- PHP 8.2+
- `flarum/tags` and `flarum/mentions` are optional

## Licence

MIT
