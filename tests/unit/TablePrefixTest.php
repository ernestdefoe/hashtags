<?php

namespace Ernestdefoe\Hashtags\Tests\unit;

use Ernestdefoe\Hashtags\Hashtag\HashtagSyncer;
use Illuminate\Database\MySqlConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the bug that broke posting on every install with a
 * database table prefix (2.0.0 / 2.0.1).
 *
 * The query builder prefixes identifiers it wraps itself, but selectRaw() is
 * passed through verbatim — so the from/join/where were `fg_post_hashtag`
 * while the select list said `post_hashtag`, and MySQL rejected the whole
 * statement. Because the query runs from the post-save sync, the visible
 * symptom was a 500 on POST /api/discussions.
 *
 * No database is touched: toSql() renders the statement without executing it,
 * so the PDO here is never opened.
 */
class TablePrefixTest extends TestCase
{
    private function connection(string $prefix): MySqlConnection
    {
        return new MySqlConnection(fn () => null, 'testing', $prefix);
    }

    #[DataProvider('prefixes')]
    public function testEveryTableReferenceCarriesThePrefix(string $prefix): void
    {
        $sql = HashtagSyncer::recountQuery($this->connection($prefix), [1, 2, 3])->toSql();

        // A bare `post_hashtag.` or `posts.` — one not already carrying the
        // prefix and not part of a longer identifier — is the exact defect.
        if ($prefix !== '') {
            $this->assertDoesNotMatchRegularExpression(
                '/(?<![`\w.])(post_hashtag|posts)\./',
                $sql,
                "Unprefixed table reference in:\n$sql"
            );
        }

        foreach (['hashtag_id', 'discussion_id', 'created_at'] as $column) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($prefix, '/').'(post_hashtag|posts)\.'.$column.'/',
                $sql,
                "Expected a prefixed reference to $column in:\n$sql"
            );
        }
    }

    #[DataProvider('prefixes')]
    public function testSelectListAgreesWithTheFromClause(string $prefix): void
    {
        $sql = HashtagSyncer::recountQuery($this->connection($prefix), [1])->toSql();

        [$selectList] = explode(' from ', $sql, 2);

        $this->assertStringContainsString($prefix.'post_hashtag.hashtag_id', $selectList);
        $this->assertStringContainsString($prefix.'posts.created_at', $selectList);
        $this->assertStringContainsString('`'.$prefix.'post_hashtag`', $sql);
        $this->assertStringContainsString('`'.$prefix.'posts`', $sql);
    }

    public static function prefixes(): array
    {
        return [
            'no prefix' => [''],
            'fg_ prefix' => ['fg_'],
            'long prefix' => ['some_long_prefix_'],
        ];
    }
}
