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
$has('www link keeps its label', $h, '<a href="http://www.phpbb.com" rel="nofollow noopener" target="_blank">www.phpbb.com</a>');
$has('local link keeps its label', $h, '>viewtopic.php?f=2&amp;t=3</a>');

$h = Bbcode::phpbb('Mail <!-- e --><a href="mailto:ada@example.com">ada@example.com</a><!-- e -->', $uid);
$check('e-mail becomes the address', $h === '<p>Mail ada@example.com</p>', $h);

$h = Bbcode::phpbb('Hi <!-- s:) --><img src="{SMILIES_PATH}/icon_e_smile.gif" alt=":)" title="Smile" /><!-- s:) --> there <!-- s:lol: --><img src="{SMILIES_PATH}/icon_lol.gif" alt=":lol:" title="Laughing" /><!-- s:lol: -->', $uid);
$check('smilies become their codes', $h === '<p>Hi :) there :lol:</p>', $h);

$h = Bbcode::phpbb("[b:$uid]bold[/b:$uid] [i:$uid]it[/i:$uid] [size=150:$uid]big[/size:$uid] [color=#FF0000:$uid]red[/color:$uid]", $uid);
$check('uid stripped from inline tags', $h === '<p><strong>bold</strong> <em>it</em> <span>big</span> <span style="color:#FF0000">red</span></p>', $h);

$h = Bbcode::phpbb("[url=http&#58;//www&#46;example&#46;com/?q=a&amp;r=b:$uid]Example[/url:$uid]", $uid);
$has('[url=…:uid] with phpBB\'s encoded colon', $h, '<a href="http://www.example.com/?q=a&amp;r=b" rel="nofollow noopener" target="_blank">Example</a>');

$h = Bbcode::phpbb("[list:$uid][*:$uid]one[/*:m:$uid][*:$uid]two[/*:m:$uid][/list:u:$uid][list=1:$uid][*:$uid]first[/*:m:$uid][/list:o:$uid]", $uid);
$check('lists with :m :u :o suffixes', $h === '<ul><li>one</li><li>two</li></ul><ol><li>first</li></ol>', $h);

$h = Bbcode::phpbb("[quote=&quot;Ada&quot;:$uid][quote=&quot;Bob&quot;:$uid]inner[/quote:$uid]outer[/quote:$uid]", $uid);
$check('nested quotes with uid', $h === '<blockquote data-cite="Ada"><blockquote data-cite="Bob">inner</blockquote>outer</blockquote>', $h);

$h = Bbcode::phpbb("[code:$uid]&lt;?php echo &quot;a&amp;b&quot;;&nbsp;&nbsp;// &#91;b&#93; ?&gt;[/code:$uid]", $uid);
$check('code: entities decoded once, brackets kept', $h === '<pre><code>&lt;?php echo &quot;a&amp;b&quot;;  // [b] ?&gt;</code></pre>', $h);

$h = Bbcode::phpbb('[code=php:'.$uid.']<span class="syntaxdefault">&lt;?php&nbsp;</span><span class="syntaxkeyword">echo</span>[/code:'.$uid.']', $uid);
$check('code=php highlighting spans dropped', $h === '<pre><code>&lt;?php echo</code></pre>', $h);

$h = Bbcode::phpbb('&lt;script&gt;alert(1)&lt;/script&gt; and &amp;lt;b&amp;gt; and <script>alert(2)</script>', $uid);
$has('escaped <script> stays text', $h, '&lt;script&gt;alert(1)&lt;/script&gt;');
$has('a literal &lt; stays a literal &lt;', $h, '&amp;lt;b&amp;gt;');
$lacks('raw markup in a row never survives', $h, '<script');

$h = Bbcode::phpbb('<!-- m --><a class="postlink" href="javascript:alert(1)">x</a><!-- m --> <a href="http://x.example/" onclick="evil()">y</a>', $uid);
$lacks('javascript: link dropped', $h, 'javascript');
$lacks('event handlers dropped', $h, 'onclick');

$h = Bbcode::phpbb("Ünïcødé ✓ 日本語 &#128512; line<br />two\nthree", $uid);
$check('unicode, <br /> and newlines', $h === "<p>Ünïcødé ✓ 日本語 😀 line<br>\ntwo<br>\nthree</p>", $h);

echo "phpBB 3.2+ s9e XML\n";
$h = Bbcode::phpbb('<r>Hello <B><s>[b]</s>world<e>[/b]</e></B> <URL url="https://example.com/?a=1&amp;b=2">https://example.com/?a=1&amp;b=2</URL> <E>:)</E> &lt;script&gt; &amp;lt;<br/>
<QUOTE author="Ada"><s>[quote="Ada"]</s>hi<e>[/quote]</e></QUOTE></r>');
$has('bold', $h, '<strong>world</strong>');
$has('magic link left for the autolinker', $h, 'https://example.com/?a=1&amp;b=2');
$has('escaped text stays text', $h, '&lt;script&gt; &amp;lt;');
$has('quote', $h, '<blockquote data-cite="Ada">hi</blockquote>');
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
    $has($board, Bbcode::toHtml($src), '<blockquote data-cite="'.$cite.'">hi</blockquote>');
}
$has('no author, plain quote', Bbcode::toHtml('[quote]hi[/quote]'), '<blockquote>hi</blockquote>');

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
