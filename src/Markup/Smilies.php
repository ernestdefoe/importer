<?php

namespace ErnestDefoe\Importer\Markup;

/**
 * phpBB's standard smilies as emoji.
 *
 * phpBB stores a smiley as an image whose alt is its code (`:lol:`), and the
 * importer used to keep the code on the assumption that flarum/emoji turns it
 * back into a picture. It only knows real emoji names, so phpBB's own codes
 * (:lol:, :mrgreen:, :shock:, :roll: …) were left showing as text (Winckle's
 * GRcade, 2026-10-26). They are written as the emoji they stand for instead.
 *
 * Only the colon-wrapped codes are converted inside plain text, and only as
 * whole words: ":)" and friends are left to flarum/emoji, and an unknown
 * code (a board's own custom smiley) is kept as it was.
 *
 * 🚨 A code the forum has its OWN emoji for is left alone too. Flamoji
 * (pianotell/flarum-ext-flamoji) keeps them in `custom_emojis.text_to_replace`
 * and draws its picture wherever the code appears; Winckle mapped phpBB's
 * smilies that way, and turning them into standard emoji would have replaced
 * his pictures (edonline d/75 #33).
 */
final class Smilies
{
    /** @var array<string, true>|null codes the forum draws itself */
    private static ?array $own = null;

    /** For tests: the codes the forum has its own emoji for. */
    public static function setOwn(array $codes): void
    {
        self::$own = [];
        foreach ($codes as $c) {
            $c = strtolower(trim((string) $c, " \t"));
            if ($c !== '') {
                self::$own[$c] = true;
                self::$own[':'.trim($c, ':').':'] = true;
            }
        }
    }

    private static function own(string $code): bool
    {
        if (self::$own === null) {
            $codes = [];
            try {
                if (function_exists('resolve')) {
                    $db = resolve(\Illuminate\Database\ConnectionInterface::class);
                    if ($db->getSchemaBuilder()->hasTable('custom_emojis')) {
                        $codes = $db->table('custom_emojis')->whereNotNull('text_to_replace')->pluck('text_to_replace')->all();
                    }
                }
            } catch (\Throwable) {
                // No container (the plain script tests) or no table: nothing of the forum's own.
            }
            self::setOwn($codes);
        }

        return isset(self::$own[strtolower(trim($code))]);
    }

    public const MAP = [
        ':D' => '😀', ':-D' => '😀', ':grin:' => '😁', ':mrgreen:' => '😁',
        ':)' => '🙂', ':-)' => '🙂', ':smile:' => '🙂',
        ';)' => '😉', ';-)' => '😉', ':wink:' => '😉',
        ':(' => '🙁', ':-(' => '🙁', ':sad:' => '🙁',
        ':o' => '😮', ':-o' => '😮', ':eek:' => '😮', ':shock:' => '😲',
        ':?' => '😕', ':-?' => '😕', ':???:' => '😕',
        '8-)' => '😎', ':cool:' => '😎',
        ':lol:' => '😆',
        ':x' => '😠', ':-x' => '😠', ':mad:' => '😠',
        ':P' => '😛', ':-P' => '😛', ':razz:' => '😛',
        ':oops:' => '😳', ':cry:' => '😢', ':evil:' => '👿', ':twisted:' => '😈', ':roll:' => '🙄',
        ':!:' => '❗', ':?:' => '❓', ':idea:' => '💡', ':arrow:' => '➡️',
        ':|' => '😐', ':-|' => '😐', ':neutral:' => '😐',
        ':geek:' => '🤓', ':ugeek:' => '🤓',
    ];

    /** One smiley code (an image's alt, an <E> element) as its emoji, or the code unchanged. */
    public static function code(string $code): string
    {
        return self::own($code) ? $code : (self::MAP[trim($code)] ?? $code);
    }

    /** The colon-wrapped codes inside plain text, as whole words. */
    public static function inText(string $text): string
    {
        if (! str_contains($text, ':')) {
            return $text;
        }

        return preg_replace_callback('/(?<![\w:])(:[a-z?!]{1,10}:)(?![\w:])/i', function ($m) {
            $emoji = self::own($m[1]) ? null : (self::MAP[strtolower($m[1])] ?? null);

            return $emoji ?? $m[1];
        }, $text) ?? $text;
    }
}
