<?php

/*
 * phpBB post_text → HTML, and the Markdown text fixes. No Flarum, no PHPUnit:
 *
 *     php tests/post_text.php
 *
 * The fixtures are post_text exactly as phpBB 3.0 stores it: the member's text
 * HTML-escaped, BBCode tags carrying the post's bbcode_uid, and real markup
 * phpBB inserted itself. The integration test runs the same rows through
 * Flarum's formatter.
 */

declare(strict_types=1);

require __DIR__.'/../src/Importers/Bbcode.php';
require __DIR__.'/../src/Markup/Html.php';
require __DIR__.'/../src/Importers/PostText.php';

use ErnestDefoe\Importer\Importers\Bbcode;
use ErnestDefoe\Importer\Importers\PostText;

$fail = 0;
$check = function (string $name, bool $ok, string $got = '') use (&$fail) {
    echo ($ok ? '  ok  ' : 'FAIL  ').$name.($ok ? '' : "\n      got: ".$got)."\n";
    $fail += $ok ? 0 : 1;
};
$has = fn (string $name, string $got, string $needle) => $check($name, str_contains($got, $needle), $got);
$lacks = fn (string $name, string $got, string $needle) => $check($name, ! str_contains($got, $needle), $got);

$uid = '2ffwfp6b';

echo "phpBB 3.0 post_text\n";
$magic = '<!-- m --><a class="postlink" href="https://www.ea.com/games/battlefield/battlefield-6?a=1&amp;b=2">https://www.ea.com/games/battlefield/battlefield-6?a=1&amp;b=2</a><!-- m -->.';
$h = Bbcode::phpbb('See '.$magic, $uid);
$has('magic URL becomes one link', $h, '<a href="https://www.ea.com/games/battlefield/battlefield-6?a=1&amp;b=2"');
$has('the full stop stays outside it', $h, '</a>.');
$lacks('no comment marker left', $h, '!--');
$lacks('no escaped markup left', $h, '&lt;a');
$lacks('& decoded once, not twice', $h, '&amp;amp;');

$h = Bbcode::phpbb('<!-- m --><a class="postlink" href="http://www.example.com/a/very/long/path/that/phpbb/shortens/index.php?x=1">http://www.example.com/a/very/long/path ... index.php?x=1</a><!-- m -->', $uid);
$has('a shortened label shows the whole address', $h, '>http://www.example.com/a/very/long/path/that/phpbb/shortens/index.php?x=1</a>');

$h = Bbcode::phpbb('<!-- w --><a class="postlink" href="http://www.phpbb.com">www.phpbb.com</a><!-- w --> and <!-- l --><a class="postlink-local" href="http://board.example/viewtopic.php?f=2&amp;t=3">viewtopic.php?f=2&amp;t=3</a><!-- l -->', $uid);
$has('www link keeps its label', $h, '<a href="http://www.phpbb.com">www.phpbb.com</a>');
$has('local link keeps its label', $h, '>viewtopic.php?f=2&amp;t=3</a>');

$h = Bbcode::phpbb('Mail <!-- e --><a href="mailto:ada@example.com">ada@example.com</a><!-- e -->', $uid);
$check('e-mail becomes the address', $h === '<p>Mail ada@example.com</p>', $h);

$h = Bbcode::phpbb('Hi <!-- s:) --><img src="{SMILIES_PATH}/icon_e_smile.gif" alt=":)" title="Smile" /><!-- s:) --> there <!-- s:lol: --><img src="{SMILIES_PATH}/icon_lol.gif" alt=":lol:" title="Laughing" /><!-- s:lol: -->', $uid);
$check('smilies become their codes', $h === '<p>Hi :) there :lol:</p>', $h);

$h = Bbcode::phpbb("[b:$uid]bold[/b:$uid] [i:$uid]it[/i:$uid] [size=150:$uid]big[/size:$uid] [color=#FF0000:$uid]red[/color:$uid]", $uid);
$check('uid stripped from inline tags', $h === '<p><strong>bold</strong> <em>it</em> <span data-size="21">big</span> <span data-color="#ff0000">red</span></p>', $h);

$h = Bbcode::phpbb("[url=http&#58;//www&#46;example&#46;com/?q=a&amp;r=b:$uid]Example[/url:$uid]", $uid);
$has('[url=…:uid] with phpBB\'s encoded colon', $h, '<a href="http://www.example.com/?q=a&amp;r=b">Example</a>');

$h = Bbcode::phpbb("[list:$uid][*:$uid]one[/*:m:$uid][*:$uid]two[/*:m:$uid][/list:u:$uid][list=1:$uid][*:$uid]first[/*:m:$uid][/list:o:$uid]", $uid);
$check('lists with :m :u :o suffixes', $h === '<ul><li><p>one</p></li><li><p>two</p></li></ul><ol><li><p>first</p></li></ol>', $h);

$h = Bbcode::phpbb("[quote=&quot;Ada&quot;:$uid][quote=&quot;Bob&quot;:$uid]inner[/quote:$uid]outer[/quote:$uid]", $uid);
$check('nested quotes with uid', $h === '<blockquote data-cite="Ada"><blockquote data-cite="Bob"><p>inner</p></blockquote><p>outer</p></blockquote>', $h);

$h = Bbcode::phpbb("[code:$uid]&lt;?php echo &quot;a&amp;b&quot;;&nbsp;&nbsp;// &#91;b&#93; ?&gt;[/code:$uid]", $uid);
$check('code: entities decoded once, brackets kept', $h === '<pre>&lt;?php echo &quot;a&amp;b&quot;;  // [b] ?&gt;</pre>', $h);

$h = Bbcode::phpbb('[code=php:'.$uid.']<span class="syntaxdefault">&lt;?php&nbsp;</span><span class="syntaxkeyword">echo</span>[/code:'.$uid.']', $uid);
$check('code=php highlighting spans dropped', $h === '<pre data-lang="php">&lt;?php echo</pre>', $h);

$h = Bbcode::phpbb('&lt;script&gt;alert(1)&lt;/script&gt; and &amp;lt;b&amp;gt; and <script>alert(2)</script>', $uid);
$has('escaped <script> stays text', $h, '&lt;script&gt;alert(1)&lt;/script&gt;');
$has('a literal &lt; stays a literal &lt;', $h, '&amp;lt;b&amp;gt;');
$lacks('raw markup in a row never survives', $h, '<script');

$h = Bbcode::phpbb('<!-- m --><a class="postlink" href="javascript:alert(1)">x</a><!-- m --> <a href="http://x.example/" onclick="evil()">y</a>', $uid);
$lacks('javascript: link dropped', $h, 'javascript');
$lacks('event handlers dropped', $h, 'onclick');

$h = Bbcode::phpbb("Ünïcødé ✓ 日本語 &#128512; line<br />two\nthree", $uid);
$check('unicode, <br /> and newlines', $h === '<p>Ünïcødé ✓ 日本語 😀 line<br>two<br>three</p>', $h);

echo "phpBB 3.2+ s9e XML\n";
$h = Bbcode::phpbb('<r>Hello <B><s>[b]</s>world<e>[/b]</e></B> <URL url="https://example.com/?a=1&amp;b=2">https://example.com/?a=1&amp;b=2</URL> <E>:)</E> &lt;script&gt; &amp;lt;<br/>
<QUOTE author="Ada"><s>[quote="Ada"]</s>hi<e>[/quote]</e></QUOTE></r>');
$has('bold', $h, '<strong>world</strong>');
$has('magic link left for the autolinker', $h, 'https://example.com/?a=1&amp;b=2');
$has('escaped text stays text', $h, '&lt;script&gt; &amp;lt;');
$has('quote', $h, '<blockquote data-cite="Ada"><p>hi</p></blockquote>');
$lacks('no XML left', $h, '&lt;B&gt;');
$check('plain <t> post', Bbcode::phpbb('<t>Just text &amp; more</t>') === '<p>Just text &amp; more</p>', Bbcode::phpbb('<t>Just text &amp; more</t>'));

echo "Markdown entities\n";
$md = PostText::fixEntities("Tom &amp; Jerry &lt;b&gt; &amp;lt; a&#124;b\n\n```\n&lt;?php\n```\n\n`a&lt;b`");
$check('decoded once, code untouched', $md === "Tom & Jerry \\<b\\> &lt; a\\|b\n\n```\n&lt;?php\n```\n\n`a&lt;b`", $md);

$md = PostText::fixEntities('See https://example.com/a\\_b?x=1&amp;y=2. and [t](https://example.com/c_d) \\_ok\\_');
$check('bare URL loses the escaped underscore, text keeps it', $md === 'See https://example.com/a_b?x=1&y=2. and [t](https://example.com/c_d) \\_ok\\_', $md);

echo "Repairing an already-imported post\n";
$bad = 'See &lt;!-- m --&gt;&lt;a class="postlink" href="https://www.ea.com/games/battle\_field?a=1&amp;b=2"&gt;https://www.ea.com/games/battle\_field?a=1&amp;b=2&lt;/a&gt;&lt;!-- m --&gt;. Nice &lt;!-- s:) --&gt;&lt;img src="{SMILIES\_PATH}/icon\_e\_smile.gif" alt=":)" title="Smile" /&gt;&lt;!-- s:) --&gt;'."\n".'\[\*\]one\[/\*:m\]\[\*\]two\[/\*:m\]\[/list:u\]';
$check('detected', PostText::hasPhpbbLegacy($bad));
$fixed = PostText::fixEntities(PostText::repairPhpbb($bad));
$check('rebuilt', $fixed === "See [https://www.ea.com/games/battle\\_field?a=1&b=2](https://www.ea.com/games/battle_field?a=1&b=2). Nice :)\n- one\n- two\n", $fixed);
$check('idempotent', PostText::fixEntities(PostText::repairPhpbb($fixed)) === PostText::fixEntities($fixed) && ! PostText::hasPhpbbLegacy($fixed));
$check('clean post not detected', ! PostText::hasPhpbbLegacy('Just a post with [a link](https://x.example) &amp; text'));

echo "Quotes keep their author\n";
foreach ([
    'Vanilla' => ['[quote="Kryptos;c-5"]hi[/quote]', 'Kryptos'],
    'vBulletin' => ['[quote=Ana Maria;123]hi[/quote]', 'Ana Maria'],
    'phpBB 3.2' => ['[quote="Kryptos" post_id=4 time=1600000000 user_id=2]hi[/quote]', 'Kryptos'],
    'MyBB' => ["[quote='Kryptos' pid='4' dateline='1600000000']hi[/quote]", 'Kryptos'],
    'SMF' => ['[quote author=Kryptos link=msg=4 date=1600000000]hi[/quote]', 'Kryptos'],
    'escaped name' => ['[quote="<b>x</b>"]hi[/quote]', '&lt;b&gt;x&lt;/b&gt;'],
] as $board => [$src, $cite]) {
    $has($board, Bbcode::toHtml($src), '<blockquote data-cite="'.$cite.'"');
    $has($board.': body', Bbcode::toHtml($src), '><p>hi</p></blockquote>');
}
$has('no author, plain quote', Bbcode::toHtml('[quote]hi[/quote]'), '<blockquote><p>hi</p></blockquote>');

echo "\nphpBB 3.2+ XML imported as text before 0.3.7 (repair-text)\n";
// What 0.3.6 stored for real phpBB 3.3 rows, before (entities) and after
// (backslashes) 0.4.1's repair-text ran over it. Produced by the old code.
$xmlCases = [
    'quote+smiley (as imported)' => '&lt;r&gt;&lt;QUOTE author="Xeno"&gt;&lt;s&gt;

> &lt;/s&gt;I am liking the stuff I watched but it is early days. Please don\'t fuck this up.&lt;e&gt;

&lt;/e&gt;&lt;/QUOTE&gt;Has a battlefield game ever had a good launch &lt;E&gt;:slol:&lt;/E&gt;&lt;/r&gt;',
    'quote+smiley (after 0.4.1 repair)' => '\\<r\\>\\<QUOTE author="Xeno"\\>\\<s\\>

> \\</s\\>I am liking the stuff I watched but it is early days. Please don\'t fuck this up.\\<e\\>

\\</e\\>\\</QUOTE\\>Has a battlefield game ever had a good launch \\<E\\>:slol:\\</E\\>\\</r\\>',
    'link (as imported)' => '&lt;r&gt;Gameplay leaked, yes that\'s BF -&lt;br/&gt;
&lt;br/&gt;
&lt;URL url="https://www.reddit.com/r/Battlefield/comments/1j5sugo/5\\_minutes\\_gameplay\\_thoughts/?utm\\_source=share&amp;utm\\_medium=web3x"&gt;&lt;LINK\\_TEXT text="https://www.reddit.com/r/Battlefield/co ... are\\_button"&gt;https://www.reddit.com/r/Battlefield/comments/1j5sugo/5\\_minutes\\_gameplay\\_thoughts/?utm\\_source=share&amp;utm\\_medium=web3x&lt;/LINK\\_TEXT&gt;&lt;/URL&gt;&lt;/r&gt;',
    'link (after 0.4.1 repair)' => '\\<r\\>Gameplay leaked, yes that\'s BF -\\<br/\\>
\\<br/\\>
\\<URL url="https://www.reddit.com/r/Battlefield/comments/1j5sugo/5_minutes_gameplay_thoughts/?utm_source=share&utm_medium=web3x"\\>\\<LINK\\_TEXT text="https://www.reddit.com/r/Battlefield/co ... are\\_button"\\>https://www.reddit.com/r/Battlefield/comments/1j5sugo/5_minutes_gameplay_thoughts/?utm_source=share&utm_medium=web3x\\</LINK\\_TEXT\\>\\</URL\\>\\</r\\>',
    'bold+plain (as imported)' => '&lt;r&gt;&lt;B&gt;&lt;s&gt;**&lt;/s&gt;Day one patch&lt;e&gt;**&lt;/e&gt;&lt;/B&gt; is 40GB &amp; it\'s &lt;I&gt;&lt;s&gt;*&lt;/s&gt;still&lt;e&gt;*&lt;/e&gt;&lt;/I&gt; broken &lt;sigh&gt;&lt;/r&gt;',
    'bold+plain (after 0.4.1 repair)' => '\\<r\\>\\<B\\>\\<s\\>**\\</s\\>Day one patch\\<e\\>**\\</e\\>\\</B\\> is 40GB & it\'s \\<I\\>\\<s\\>*\\</s\\>still\\<e\\>*\\</e\\>\\</I\\> broken \\<sigh\\>\\</r\\>',
    'plain t (as imported)' => '&lt;t&gt;Just plain text with a 5 &lt; 6 &amp; done&lt;/t&gt;',
    'plain t (after 0.4.1 repair)' => '\\<t\\>Just plain text with a 5 \\< 6 & done\\</t\\>',
];
foreach ($xmlCases as $name => $md) {
    $check("$name: recognised", PostText::hasPhpbbXml($md));
    $fixed = PostText::fixEntities(PostText::repairPhpbbXml($md));
    $check("$name: no phpBB tag left", ! preg_match('#(&lt;|\\<)/?(r|t|s|e|E|B|I|QUOTE|URL|LINK\\?_TEXT|br/?)(\s|&gt;|\\>|/)#', $fixed), $fixed);
    $check("$name: running twice changes nothing", PostText::fixEntities(PostText::repairPhpbbXml($fixed)) === $fixed, $fixed);
}
$q = PostText::fixEntities(PostText::repairPhpbbXml($xmlCases['quote+smiley (after 0.4.1 repair)']));
$has('a quote keeps who it quotes, inside the quote', $q, "> **Xeno wrote:**\n>\n> I am liking");
$has('the reply after the quote is outside it', $q, "\n\nHas a battlefield game ever had a good launch :slol:");
$l = PostText::fixEntities(PostText::repairPhpbbXml($xmlCases['link (as imported)']));
$check('a shortened link becomes its full address, once', substr_count($l, 'https://www.reddit.com') === 1 && str_contains($l, '5_minutes_gameplay_thoughts/?utm_source=share&utm_medium=web3x'), $l);
$lacks('the shortened label is gone', $l, ' ... ');
$b = PostText::fixEntities(PostText::repairPhpbbXml($xmlCases['bold+plain (as imported)']));
$has('bold and italic come back as Markdown', $b, '**Day one patch** is 40GB & it\'s *still* broken');
$has('angle brackets the member typed stay, as text', $b, '\<sigh\>');
$check('a translated "wrote:" is used when given', str_contains(PostText::repairPhpbbXml($xmlCases['quote+smiley (as imported)'], fn ($n) => "$n a écrit :"), '**Xeno a écrit :**'));
foreach (['Ordinary post with <r> in it', '\<r\>no closing root', 'Text about &lt;r&gt; tags &lt;/r&gt; mid-sentence', '<t>raw XML, never escaped</t>'] as $plain) {
    $check('not phpBB XML: '.$plain, ! PostText::hasPhpbbXml($plain));
}

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
