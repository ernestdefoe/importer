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
 */
final class Smilies
{
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
        return self::MAP[trim($code)] ?? $code;
    }

    /** The colon-wrapped codes inside plain text, as whole words. */
    public static function inText(string $text): string
    {
        if (! str_contains($text, ':')) {
            return $text;
        }

        return preg_replace_callback('/(?<![\w:])(:[a-z?!]{1,10}:)(?![\w:])/i', function ($m) {
            $emoji = self::MAP[strtolower($m[1])] ?? null;

            return $emoji ?? $m[1];
        }, $text) ?? $text;
    }
}
