<?php

namespace ErnestDefoe\Importer\Tests\integration;

use ErnestDefoe\Importer\Importers\Bbcode;
use ErnestDefoe\Importer\Importers\InvisionImporter;
use ErnestDefoe\Importer\Importers\Src;
use ErnestDefoe\Importer\Markup\VanillaRich;

/**
 * One post per source using every construct that source's editor can store,
 * in the exact form the source keeps it in its database. Modelled on real
 * posts from each platform (structure, class names, attribute order, escaping
 * and line endings as stored), with the words replaced by markers so a test
 * can find each piece again after import.
 *
 * Each marker word belongs to one construct: BOLDWORD to bold, QUOTEDTEXT to
 * a quote, and so on. Every one must survive whatever the editor.
 */
final class FormattingCorpus
{
    /** construct => the word that marks it */
    public const WORDS = [
        'bold' => 'BOLDWORD', 'italic' => 'ITALICWORD', 'underline' => 'UNDERWORD', 'strike' => 'STRIKEWORD',
        'color' => 'REDWORD', 'size' => 'BIGWORD', 'font' => 'FONTWORD', 'heading' => 'HEADINGWORD',
        'quote' => 'QUOTEDTEXT', 'list' => 'LISTITEM', 'olist' => 'NUMITEM', 'code' => 'CODELINE',
        'icode' => 'ICODEWORD', 'link' => 'LINKLABEL', 'image' => 'image.png', 'spoiler' => 'SPOILERTEXT',
        'ispoiler' => 'INLINESPOILER', 'table' => 'TABLECELL', 'center' => 'CENTERWORD', 'media' => 'dQw4w9WgXcQ',
        'mention' => 'Ada', 'attachment' => 'photo.jpg', 'email' => 'ada@example.com', 'hr' => 'AFTERRULE',
        'sup' => 'SUPWORD', 'highlight' => 'HIGHWORD', 'literal' => 'LITERALS', 'bareurl' => 'https://example.com/bare',
    ];

    /** @return array<string, array{read:\Closure, raw:string, has:list<string>, base?:string}> */
    public static function sources(): array
    {
        $bb = fn (array $opts = []) => fn (string $raw) => Bbcode::toHtml($raw, $opts);
        $private = fn (string $class, string $method) => function (string $raw, ...$args) use ($class, $method) {
            $m = new \ReflectionMethod($class, $method);

            return $m->invoke(null, $raw, ...$args);
        };

        return [
            'phpBB 3.0' => [
                'read' => fn (string $raw) => Bbcode::phpbb($raw, '3fg7hj2k'),
                'raw' => '[b:3fg7hj2k]BOLDWORD[/b:3fg7hj2k] [i:3fg7hj2k]ITALICWORD[/i:3fg7hj2k] [u:3fg7hj2k]UNDERWORD[/u:3fg7hj2k] [color=#FF0000:3fg7hj2k]REDWORD[/color:3fg7hj2k] [size=150:3fg7hj2k]BIGWORD[/size:3fg7hj2k]'."\n"
                    .'[quote=&quot;Ada&quot;:3fg7hj2k]QUOTEDTEXT[/quote:3fg7hj2k]'."\n"
                    .'[list:3fg7hj2k][*:3fg7hj2k]LISTITEM1[/*:m:3fg7hj2k][*:3fg7hj2k]LISTITEM2[/*:m:3fg7hj2k][/list:u:3fg7hj2k][list=1:3fg7hj2k][*:3fg7hj2k]NUMITEM[/*:m:3fg7hj2k][/list:o:3fg7hj2k]'."\n"
                    .'[code=php:3fg7hj2k]<span class="syntaxdefault">&lt;?php&nbsp;echo&nbsp;&quot;CODELINE&quot;;&nbsp;</span>[/code:3fg7hj2k]'."\n"
                    .'[url=https&#58;//example&#46;com/link:3fg7hj2k]LINKLABEL[/url:3fg7hj2k] <!-- m --><a class="postlink" href="https://example.com/magic?a=1&amp;b=2">https://example.com/magic?a=1&amp;b=2</a><!-- m --> [img:3fg7hj2k]https&#58;//example&#46;com/image&#46;png[/img:3fg7hj2k]'."\n"
                    .'Mail <!-- e --><a href="mailto:ada@example.com">ada@example.com</a><!-- e --> <!-- s:) --><img src="{SMILIES_PATH}/icon_e_smile.gif" alt=":)" title="Smile" /><!-- s:) --> LITERALS 2*3 &lt;b&gt;not bold&lt;/b&gt; [sic]',
                'has' => ['codelang', 'bold', 'italic', 'underline', 'color', 'size', 'quote', 'list', 'olist', 'code', 'link', 'image', 'email', 'literal'],
            ],
            'phpBB 3.2+' => [
                'read' => fn (string $raw) => Bbcode::phpbb($raw),
                'raw' => '<r><B><s>[b]</s>BOLDWORD<e>[/b]</e></B> <I><s>[i]</s>ITALICWORD<e>[/i]</e></I> <U><s>[u]</s>UNDERWORD<e>[/u]</e></U> <COLOR color="#FF0000"><s>[color=#FF0000]</s>REDWORD<e>[/color]</e></COLOR> <SIZE size="150"><s>[size=150]</s>BIGWORD<e>[/size]</e></SIZE><br/>'."\n"
                    .'<QUOTE author="Ada" post_id="12" time="1600000000" user_id="3"><s>[quote="Ada" post_id=12 time=1600000000 user_id=3]</s>QUOTEDTEXT<e>[/quote]</e></QUOTE>'."\n"
                    .'<LIST><s>[list]</s><LI><s>[*]</s>LISTITEM1</LI><LI><s>[*]</s>LISTITEM2</LI><e>[/list]</e></LIST><LIST type="decimal"><s>[list=1]</s><LI><s>[*]</s>NUMITEM</LI><e>[/list]</e></LIST>'."\n"
                    .'<CODE lang="php"><s>[code=php]</s>&lt;?php echo "CODELINE"; ?&gt;<e>[/code]</e></CODE>'."\n"
                    .'<URL url="https://example.com/link"><s>[url=https://example.com/link]</s>LINKLABEL<e>[/url]</e></URL> <IMG src="https://example.com/image.png"><s>[img]</s><URL url="https://example.com/image.png">https://example.com/image.png</URL><e>[/img]</e></IMG><br/>'."\n"
                    .'<SPOILER><s>[spoiler]</s>SPOILERTEXT<e>[/spoiler]</e></SPOILER><br/>'."\n"
                    .'Mail <EMAIL email="ada@example.com">ada@example.com</EMAIL> <E>:)</E> LITERALS 2*3 &lt;b&gt;not bold&lt;/b&gt; [sic]</r>',
                'has' => ['codelang', 'bold', 'italic', 'underline', 'color', 'size', 'quote', 'list', 'olist', 'code', 'link', 'image', 'spoiler', 'email', 'literal'],
            ],
            'XenForo 2' => [
                'read' => $bb(),
                'raw' => "[B]BOLDWORD[/B] [I]ITALICWORD[/I] [U]UNDERWORD[/U] [S]STRIKEWORD[/S] [COLOR=rgb(226, 80, 65)]REDWORD[/COLOR] [SIZE=6]BIGWORD[/SIZE] [FONT=Courier New]FONTWORD[/FONT]\n\n"
                    ."[HEADING=2]HEADINGWORD[/HEADING]\n"
                    ."[QUOTE=\"Ada, post: 12, member: 3\"]QUOTEDTEXT[/QUOTE]\n"
                    ."[LIST]\n[*]LISTITEM1\n[*]LISTITEM2\n[/LIST]\n[LIST=1]\n[*]NUMITEM\n[/LIST]\n"
                    ."[CODE=php]<?php echo \"CODELINE\"; ?>[/CODE]\n"
                    ."Inline [ICODE]ICODEWORD[/ICODE] here.\n\n"
                    ."[URL='https://example.com/link']LINKLABEL[/URL] [URL unfurl=\"true\"]https://example.com/unfurled[/URL] [EMAIL]ada@example.com[/EMAIL]\n"
                    ."[IMG width=\"320px\" alt=\"pic\"]https://example.com/image.png[/IMG]\n\n"
                    ."[SPOILER=\"Plot\"]SPOILERTEXT[/SPOILER]\n[ISPOILER]INLINESPOILER[/ISPOILER]\n\n"
                    ."[TABLE]\n[TR]\n[TH]HEADCELL[/TH]\n[/TR]\n[TR]\n[TD]TABLECELL[/TD]\n[/TR]\n[/TABLE]\n\n"
                    ."[CENTER]CENTERWORD[/CENTER]\n[MEDIA=youtube]dQw4w9WgXcQ[/MEDIA]\n\n"
                    ."[USER=3]@Ada[/USER] [ATTACH type=\"full\" alt=\"photo.jpg\"]45[/ATTACH]\n"
                    ."[HR][/HR]\nAFTERRULE :) LITERALS 2*3 <b>not bold</b> [sic]",
                'has' => ['codelang', 'bold', 'italic', 'underline', 'strike', 'color', 'size', 'font', 'heading', 'quote', 'list', 'olist', 'code', 'icode', 'link', 'image', 'email', 'spoiler', 'ispoiler', 'table', 'center', 'media', 'mention', 'attachment', 'hr', 'literal'],
            ],
            'vBulletin 3/4' => [
                'read' => $bb(),
                'raw' => "[B]BOLDWORD[/B] [I]ITALICWORD[/I] [U]UNDERWORD[/U] [COLOR=\"Red\"]REDWORD[/COLOR] [SIZE=4]BIGWORD[/SIZE] [FONT=Verdana]FONTWORD[/FONT] [HIGHLIGHT]HIGHWORD[/HIGHLIGHT]\r\n"
                    ."[QUOTE=Ada;12]QUOTEDTEXT[/QUOTE]\r\n"
                    ."[LIST]\r\n[*]LISTITEM1\r\n[*]LISTITEM2\r\n[/LIST]\r\n[LIST=1]\r\n[*]NUMITEM\r\n[/LIST]\r\n"
                    ."[PHP]<?php echo \"CODELINE\"; ?>[/PHP]\r\n"
                    ."[URL=\"https://example.com/link\"]LINKLABEL[/URL] [EMAIL]ada@example.com[/EMAIL]\r\n"
                    ."[IMG]https://example.com/image.png[/IMG]\r\n"
                    ."[CENTER]CENTERWORD[/CENTER]\r\n"
                    ."[video=youtube;dQw4w9WgXcQ]https://www.youtube.com/watch?v=dQw4w9WgXcQ[/video]\r\n"
                    ."[TABLE=\"width: 500\"][TR][TD]TABLECELL[/TD][/TR][/TABLE]\r\n"
                    ."[NOPARSE][b]LITERALS[/b][/NOPARSE] 2*3 <b>not bold</b> [sic]",
                'has' => ['codelang', 'bold', 'italic', 'underline', 'color', 'size', 'font', 'highlight', 'quote', 'list', 'olist', 'code', 'link', 'email', 'image', 'center', 'media', 'table', 'literal'],
            ],
            'vBulletin 5' => [
                'read' => fn (string $raw) => $private(\ErnestDefoe\Importer\Importers\Vbulletin5Importer::class, 'body')($raw, 'off', null),
                'raw' => "[B]BOLDWORD[/B] [I]ITALICWORD[/I] [U]UNDERWORD[/U] [STRIKE]STRIKEWORD[/STRIKE] [COLOR=#FF0000]REDWORD[/COLOR] [SIZE=5]BIGWORD[/SIZE]\r\n"
                    ."[h=2]HEADINGWORD[/h]\r\n"
                    ."[QUOTE=Ada;n12]QUOTEDTEXT[/QUOTE]\r\n"
                    ."[LIST][*]LISTITEM1[*]LISTITEM2[/LIST][LIST=1][*]NUMITEM[/LIST]\r\n"
                    ."[CODE]CODELINE[/CODE]\r\n"
                    ."[URL=https://example.com/link]LINKLABEL[/URL] [IMG]https://example.com/image.png[/IMG]\r\n"
                    ."[ATTACH=JSON]{\"data-align\":\"none\",\"data-size\":\"full\",\"data-attachmentid\":\"77\",\"data-filename\":\"photo.jpg\"}[/ATTACH]\r\n"
                    ."[USER=\"3\"]Ada[/USER] [HR][/HR]\r\nAFTERRULE LITERALS 2*3 [sic]",
                'has' => ['bold', 'italic', 'underline', 'strike', 'color', 'size', 'heading', 'quote', 'list', 'olist', 'code', 'link', 'image', 'attachment', 'mention', 'hr', 'literal'],
            ],
            'vBulletin 5 (HTML allowed)' => [
                'read' => fn (string $raw) => $private(\ErnestDefoe\Importer\Importers\Vbulletin5Importer::class, 'body')($raw, 'on_nl2br', null),
                'raw' => "<b>BOLDWORD</b> <i>ITALICWORD</i> [U]UNDERWORD[/U]\n[QUOTE=Ada;12]QUOTEDTEXT[/QUOTE]\n<ul><li>LISTITEM1</li><li>LISTITEM2</li></ul>\n<a href=\"https://example.com/link\" onclick=\"steal()\">LINKLABEL</a> <script>alert(1)</script>LITERALS",
                'has' => ['bold', 'italic', 'underline', 'quote', 'list', 'link', 'literal'],
            ],
            'MyBB' => [
                'read' => fn (string $raw) => Bbcode::toHtml($raw, ['size' => 'pt']),
                'raw' => "[b]BOLDWORD[/b] [i]ITALICWORD[/i] [u]UNDERWORD[/u] [s]STRIKEWORD[/s] [color=red]REDWORD[/color] [size=x-large]BIGWORD[/size] [font=Arial]FONTWORD[/font]\r\n"
                    ."[quote='Ada' pid='12' dateline='1600000000']QUOTEDTEXT[/quote]\r\n"
                    ."[list]\r\n[*]LISTITEM1\r\n[*]LISTITEM2\r\n[/list]\r\n[list=1]\r\n[*]NUMITEM\r\n[/list]\r\n"
                    ."[php]<?php echo \"CODELINE\"; ?>[/php]\r\n"
                    ."[url=https://example.com/link]LINKLABEL[/url] [email=ada@example.com]ada@example.com[/email] [img=320x200]https://example.com/image.png[/img]\r\n"
                    ."[align=center]CENTERWORD[/align]\r\n[video=youtube]https://www.youtube.com/watch?v=dQw4w9WgXcQ[/video]\r\n"
                    ."[spoiler]SPOILERTEXT[/spoiler]\r\n[hr]\r\nAFTERRULE :) LITERALS 2*3 <b>not bold</b> [sic]",
                'has' => ['codelang', 'bold', 'italic', 'underline', 'strike', 'color', 'size', 'font', 'quote', 'list', 'olist', 'code', 'link', 'email', 'image', 'center', 'media', 'spoiler', 'hr', 'literal'],
            ],
            'SMF' => [
                'read' => fn (string $raw) => $private(\ErnestDefoe\Importer\Importers\SmfImporter::class, 'body')($raw),
                'raw' => '[b]BOLDWORD[/b] [i]ITALICWORD[/i] [u]UNDERWORD[/u] [s]STRIKEWORD[/s] [color=red]REDWORD[/color] [size=14pt]BIGWORD[/size] [font=Arial]FONTWORD[/font] [sup]SUPWORD[/sup]<br />'
                    .'[quote author=Ada link=msg=12 date=1600000000]QUOTEDTEXT[/quote]<br />'
                    .'[list][li]LISTITEM1[/li][li]LISTITEM2[/li][/list][list type=decimal][li]NUMITEM[/li][/list]<br />'
                    .'[code=php]&lt;?php echo &quot;CODELINE&quot;; ?&gt;[/code]<br />'
                    .'[url=https://example.com/link]LINKLABEL[/url] [email]ada@example.com[/email] [img width=100 height=50]https://example.com/image.png[/img]<br />'
                    .'[center]CENTERWORD[/center][table][tr][td]TABLECELL[/td][/tr][/table][youtube]dQw4w9WgXcQ[/youtube]<br />'
                    .'[hr]AFTERRULE &nbsp;LITERALS 2*3 &lt;b&gt;not bold&lt;/b&gt; [sic]',
                'has' => ['codelang', 'bold', 'italic', 'underline', 'strike', 'color', 'size', 'font', 'sup', 'quote', 'list', 'olist', 'code', 'link', 'email', 'image', 'center', 'table', 'media', 'hr', 'literal'],
            ],
            'Invision' => [
                'read' => fn (string $raw) => InvisionImporter::ipsHtml($raw, 'https://old.example.com'),
                'raw' => '<p><strong>BOLDWORD</strong> <em>ITALICWORD</em> <u>UNDERWORD</u> <s>STRIKEWORD</s> <span style="color:#e74c3c;">REDWORD</span> <span style="font-size:24px;">BIGWORD</span> <span style="font-family:\'Courier New\', Courier, monospace;">FONTWORD</span></p>'."\n\n"
                    .'<h2>HEADINGWORD</h2>'."\n\n"
                    .'<blockquote class="ipsQuote" data-ipsquote="" data-ipsquote-contentapp="forums" data-ipsquote-contentclass="forums_Topic" data-ipsquote-contentcommentid="12" data-ipsquote-contentid="4" data-ipsquote-contenttype="forums" data-ipsquote-timestamp="1600000000" data-ipsquote-userid="3" data-ipsquote-username="Ada"><div class="ipsQuote_citation">Ada said:</div><div class="ipsQuote_contents"><p>QUOTEDTEXT</p></div></blockquote>'."\n\n"
                    .'<ul><li>LISTITEM1</li><li>LISTITEM2</li></ul><ol><li>NUMITEM</li></ol>'."\n\n"
                    .'<pre class="ipsCode prettyprint lang-php prettyprinted">&lt;?php echo "CODELINE"; ?&gt;</pre>'."\n\n"
                    .'<p><a href="https://example.com/link" rel="external nofollow">LINKLABEL</a> <img alt="image.png" class="ipsImage" data-ratio="50" height="200" src="<fileStore.core_Attachment>/monthly_2020_01/image.png" width="320"></p>'."\n\n"
                    .'<div class="ipsSpoiler" data-ipsspoiler=""><div class="ipsSpoiler_header"><span></span></div><div class="ipsSpoiler_contents ipsClearfix" data-gramm="false"><p>SPOILERTEXT</p></div></div>'."\n\n"
                    .'<table><tbody><tr><td>TABLECELL</td></tr></tbody></table>'."\n\n"
                    .'<p style="text-align:center;">CENTERWORD</p>'."\n\n"
                    .'<div class="ipsEmbeddedVideo" contenteditable="false"><div><iframe allowfullscreen="" frameborder="0" height="270" src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?feature=oembed" width="480"></iframe></div></div>'."\n\n"
                    .'<p><a contenteditable="false" data-ipshover="" data-ipshover-target="<___base_url___>/profile/3-ada/?do=hovercard" data-mentionid="3" href="<___base_url___>/profile/3-ada/" rel="">@Ada</a> <img alt=":)" data-emoticon="" height="20" src="<fileStore.core_Emoticons>/emoticons/smile.png" srcset="<fileStore.core_Emoticons>/emoticons/smile@2x.png 2x" title=":)" width="20"> LITERALS 2*3 &lt;b&gt;not bold&lt;/b&gt; [sic]</p>',
                'has' => ['codelang', 'bold', 'italic', 'underline', 'strike', 'color', 'size', 'font', 'heading', 'quote', 'list', 'olist', 'code', 'link', 'image', 'spoiler', 'table', 'center', 'media', 'mention', 'literal'],
            ],
            'Discourse' => [
                'read' => fn (string $raw) => Src::sanitizeHtml($raw),
                'base' => 'https://old.example.com',
                'raw' => '<p><strong>BOLDWORD</strong> <em>ITALICWORD</em> <ins>UNDERWORD</ins> <s>STRIKEWORD</s> <code>ICODEWORD</code></p>'."\n"
                    .'<h2><a name="headingword-1" class="anchor" href="#headingword-1"></a>HEADINGWORD</h2>'."\n"
                    .'<aside class="quote no-group" data-username="ada" data-post="2" data-topic="4">'."\n".'<div class="title">'."\n".'<div class="quote-controls"></div>'."\n".'<img loading="lazy" alt="" width="24" height="24" src="/user_avatar/old.example.com/ada/48/3_2.png" class="avatar"> ada:</div>'."\n".'<blockquote>'."\n".'<p>QUOTEDTEXT</p>'."\n".'</blockquote>'."\n".'</aside>'."\n"
                    .'<ul>'."\n".'<li>LISTITEM1</li>'."\n".'<li>LISTITEM2</li>'."\n".'</ul>'."\n".'<ol>'."\n".'<li>NUMITEM</li>'."\n".'</ol>'."\n"
                    .'<pre><code class="lang-php">&lt;?php echo "CODELINE"; ?&gt;'."\n".'</code></pre>'."\n"
                    .'<p><a href="https://example.com/link">LINKLABEL</a></p>'."\n"
                    .'<p><div class="lightbox-wrapper"><a class="lightbox" href="/uploads/default/original/1X/image.png" data-download-href="/uploads/default/abc" title="image.png"><img src="/uploads/default/optimized/1X/image_2_690x388.png" alt="image.png" data-base62-sha1="abc" width="690" height="388"><div class="meta"><svg class="fa d-icon d-icon-far-image svg-icon" aria-hidden="true"><use href="#far-image"></use></svg><span class="filename">image.png</span><span class="informations">1920×1080 120 KB</span><svg class="fa d-icon d-icon-discourse-expand svg-icon" aria-hidden="true"><use href="#discourse-expand"></use></svg></div></a></div></p>'."\n"
                    .'<details>'."\n".'<summary>'."\n".'Plot</summary>'."\n".'<p>SPOILERTEXT</p>'."\n".'</details>'."\n"
                    .'<p><span class="spoiler">INLINESPOILER</span></p>'."\n"
                    .'<div class="md-table">'."\n".'<table>'."\n".'<thead>'."\n".'<tr>'."\n".'<th>HEADCELL</th>'."\n".'</tr>'."\n".'</thead>'."\n".'<tbody>'."\n".'<tr>'."\n".'<td>TABLECELL</td>'."\n".'</tr>'."\n".'</tbody>'."\n".'</table>'."\n".'</div>'."\n"
                    .'<div class="youtube-onebox lazy-video-container" data-video-id="dQw4w9WgXcQ" data-video-title="A video" data-video-start-time="" data-provider-name="youtube"><a href="https://www.youtube.com/watch?v=dQw4w9WgXcQ" target="_blank" rel="noopener"><img class="youtube-thumbnail" src="https://img.youtube.com/vi/dQw4w9WgXcQ/maxresdefault.jpg" title="A video" width="690" height="388"></a></div>'."\n"
                    .'<hr>'."\n".'<p>AFTERRULE <a class="mention" href="/u/ada">@Ada</a> <img src="/images/emoji/twitter/slight_smile.png?v=12" title=":slight_smile:" class="emoji" alt=":slight_smile:" loading="lazy" width="20" height="20"> LITERALS 2*3 &lt;b&gt;not bold&lt;/b&gt; [sic]</p>',
                'has' => ['codelang', 'bold', 'italic', 'underline', 'strike', 'icode', 'heading', 'quote', 'list', 'olist', 'code', 'link', 'image', 'spoiler', 'ispoiler', 'table', 'media', 'hr', 'mention', 'literal'],
            ],
            'Vanilla HTML' => [
                'read' => fn (string $raw) => $private(\ErnestDefoe\Importer\Importers\VanillaImporter::class, 'body')($raw, 'Html'),
                'raw' => '<b>BOLDWORD</b> <i>ITALICWORD</i> <u>UNDERWORD</u> <strike>STRIKEWORD</strike> <font color="red">REDWORD</font><br><blockquote class="Quote UserQuote" rel="Ada"><div class="QuoteAuthor"><a href="/profile/Ada">Ada</a> said:</div><div class="QuoteText">QUOTEDTEXT</div></blockquote><ul><li>LISTITEM1</li><li>LISTITEM2</li></ul><ol><li>NUMITEM</li></ol><pre class="CodeBlock"><code>CODELINE</code></pre><a href="https://example.com/link" rel="nofollow">LINKLABEL</a> <img src="https://example.com/image.png" alt="image"><div class="Spoiler">SPOILERTEXT</div><center>CENTERWORD</center>@Ada LITERALS 2*3 [sic]',
                'has' => ['bold', 'italic', 'underline', 'strike', 'color', 'quote', 'list', 'olist', 'code', 'link', 'image', 'spoiler', 'center', 'literal'],
            ],
            'Vanilla Markdown' => [
                'read' => fn (string $raw) => $private(\ErnestDefoe\Importer\Importers\VanillaImporter::class, 'body')($raw, 'Markdown'),
                'raw' => "**BOLDWORD** *ITALICWORD* ~~STRIKEWORD~~ `ICODEWORD`\n\n## HEADINGWORD\n\n> @Ada said:\n> QUOTEDTEXT\n\n- LISTITEM1\n- LISTITEM2\n\n1. NUMITEM\n\n```php\n<?php echo \"CODELINE\"; ?>\n```\n\n[LINKLABEL](https://example.com/link) ![image](https://example.com/image.png)\n\n| HEADCELL |\n|---|\n| TABLECELL |\n\nSee https://example.com/bare\n\nNext paragraph\n\n---\n\nAFTERRULE LITERALS 2\\*3 [sic]",
                'has' => ['codelang', 'bold', 'italic', 'strike', 'icode', 'heading', 'quote', 'list', 'olist', 'code', 'link', 'image', 'table', 'hr', 'literal', 'bareurl'],
            ],
            'Vanilla Rich' => [
                'read' => fn (string $raw) => VanillaRich::toHtml($raw) ?? '',
                'raw' => json_encode([
                    ['insert' => 'BOLDWORD', 'attributes' => ['bold' => true]], ['insert' => ' '], ['insert' => 'ITALICWORD', 'attributes' => ['italic' => true]], ['insert' => ' '],
                    ['insert' => 'STRIKEWORD', 'attributes' => ['strike' => true]], ['insert' => ' '], ['insert' => 'ICODEWORD', 'attributes' => ['code-inline' => true]], ['insert' => ' '],
                    ['insert' => 'LINKLABEL', 'attributes' => ['link' => 'https://example.com/link']], ['insert' => "\nHEADINGWORD"], ['insert' => "\n", 'attributes' => ['header' => ['level' => 2, 'ref' => '']]],
                    ['insert' => 'QUOTEDTEXT'], ['insert' => "\n", 'attributes' => ['blockquote-line' => true]],
                    ['insert' => 'LISTITEM1'], ['insert' => "\n", 'attributes' => ['list' => ['depth' => 0, 'type' => 'bullet']]],
                    ['insert' => 'LISTITEM2'], ['insert' => "\n", 'attributes' => ['list' => ['depth' => 0, 'type' => 'bullet']]],
                    ['insert' => 'NUMITEM'], ['insert' => "\n", 'attributes' => ['list' => ['depth' => 0, 'type' => 'ordered']]],
                    ['insert' => '<?php echo "CODELINE"; ?>'], ['insert' => "\n", 'attributes' => ['code-block' => true]],
                    ['insert' => 'SPOILERTEXT'], ['insert' => "\n", 'attributes' => ['spoiler-line' => true]],
                    ['insert' => ['embed-external' => ['data' => ['url' => 'https://example.com/image.png', 'type' => 'image', 'name' => 'image.png'], 'loaderData' => ['type' => 'image']]]],
                    ['insert' => ['embed-external' => ['data' => ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'type' => 'youtube', 'name' => 'A video']]]],
                    ['insert' => ['mention' => ['name' => 'Ada', 'userID' => 3]]], ['insert' => ' LITERALS 2*3 <b>not bold</b> [sic]'], ['insert' => ['emoji' => ['emojiChar' => '🙂']]], ['insert' => "\n"],
                ]),
                'has' => ['bold', 'italic', 'strike', 'icode', 'link', 'heading', 'quote', 'list', 'olist', 'code', 'spoiler', 'image', 'media', 'mention', 'literal'],
            ],
            'Vanilla Rich2' => [
                'read' => fn (string $raw) => VanillaRich::toHtml($raw) ?? '',
                'raw' => json_encode([
                    ['type' => 'p', 'children' => [['text' => 'BOLDWORD', 'bold' => true], ['text' => ' '], ['text' => 'ITALICWORD', 'italic' => true], ['text' => ' '], ['text' => 'UNDERWORD', 'underline' => true], ['text' => ' '], ['text' => 'STRIKEWORD', 'strikethrough' => true], ['text' => ' '], ['type' => 'link', 'url' => 'https://example.com/link', 'children' => [['text' => 'LINKLABEL']]]]],
                    ['type' => 'h2', 'children' => [['text' => 'HEADINGWORD']]],
                    ['type' => 'blockquote', 'children' => [['type' => 'blockquote-line', 'children' => [['text' => 'QUOTEDTEXT']]]]],
                    ['type' => 'ul', 'children' => [['type' => 'li', 'children' => [['type' => 'lic', 'children' => [['text' => 'LISTITEM1']]]]], ['type' => 'li', 'children' => [['type' => 'lic', 'children' => [['text' => 'LISTITEM2']]]]]]],
                    ['type' => 'ol', 'children' => [['type' => 'li', 'children' => [['type' => 'lic', 'children' => [['text' => 'NUMITEM']]]]]]],
                    ['type' => 'code_block', 'children' => [['type' => 'code_line', 'children' => [['text' => 'CODELINE']]]]],
                    ['type' => 'spoiler', 'children' => [['type' => 'spoiler-content', 'children' => [['type' => 'spoiler-line', 'children' => [['text' => 'SPOILERTEXT']]]]]]],
                    ['type' => 'table', 'children' => [['type' => 'tbody', 'children' => [['type' => 'tr', 'children' => [['type' => 'td', 'children' => [['type' => 'p', 'children' => [['text' => 'TABLECELL']]]]]]]]]]],
                    ['type' => 'rich_embed_card', 'dataSourceType' => 'url', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'embedData' => ['embedType' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'], 'children' => [['text' => '']]],
                    ['type' => 'p', 'children' => [['type' => '@', 'name' => 'Ada', 'userID' => 3, 'children' => [['text' => '']]], ['text' => ' LITERALS 2*3 <b>not bold</b> [sic]']]],
                ]),
                'has' => ['bold', 'italic', 'underline', 'strike', 'link', 'heading', 'quote', 'list', 'olist', 'code', 'spoiler', 'table', 'media', 'mention', 'literal'],
            ],
            'NodeBB' => [
                'read' => fn (string $raw) => Src::markdown($raw, ['mentions' => true]),
                'base' => 'https://old.example.com',
                'raw' => "**BOLDWORD** *ITALICWORD* ~~STRIKEWORD~~ `ICODEWORD`\n\n## HEADINGWORD\n\n> QUOTEDTEXT\n\n* LISTITEM1\n* LISTITEM2\n\n1. NUMITEM\n\n```php\n<?php echo \"CODELINE\"; ?>\n```\n\n[LINKLABEL](https://example.com/link) ![image.png](/assets/uploads/files/image.png)\n\n| HEADCELL |\n| --- |\n| TABLECELL |\n\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\n\n***\n\nAFTERRULE @Ada :slight_smile: LITERALS 2\\*3 <b>not bold</b> [sic]",
                'has' => ['codelang', 'bold', 'italic', 'strike', 'icode', 'heading', 'quote', 'list', 'olist', 'code', 'link', 'image', 'table', 'media', 'hr', 'mention', 'literal'],
            ],
            'Web Wiz' => [
                'read' => fn (string $raw) => $private(\ErnestDefoe\Importer\Importers\WebWizImporter::class, 'body')($raw),
                'raw' => '<strong>BOLDWORD</strong> <em>ITALICWORD</em> <u>UNDERWORD</u> <font color="#FF0000">REDWORD</font> <font size="5">BIGWORD</font><br /><br />[QUOTE=Ada]QUOTEDTEXT[/QUOTE]<ul><li>LISTITEM1</li><li>LISTITEM2</li></ul>[CODE]CODELINE[/CODE]<br /><a href="https://example.com/link" target="_blank">LINKLABEL</a> <img src="https://example.com/image.png" border="0" /><div align="center">CENTERWORD</div>[TUBE]dQw4w9WgXcQ[/TUBE]<br />LITERALS 2*3 [sic]',
                'has' => ['bold', 'italic', 'underline', 'color', 'size', 'quote', 'list', 'code', 'link', 'image', 'center', 'media', 'literal'],
            ],
            'Convoro' => [
                'read' => fn (string $raw) => $raw,
                'raw' => '<p><strong>BOLDWORD</strong> <em>ITALICWORD</em> <u>UNDERWORD</u> <s>STRIKEWORD</s> <code>ICODEWORD</code></p><h2>HEADINGWORD</h2><blockquote data-author="Ada"><p>QUOTEDTEXT</p></blockquote><ul><li><p>LISTITEM1</p></li><li><p>LISTITEM2</p></li></ul><ol><li><p>NUMITEM</p></li></ol><pre><code class="language-php">&lt;?php echo "CODELINE"; ?&gt;</code></pre><p><a href="https://example.com/link">LINKLABEL</a> <img src="https://example.com/image.png" alt="image"></p><details><summary>Plot</summary><p>SPOILERTEXT</p></details><table><tbody><tr><th>HEADCELL</th></tr><tr><td>TABLECELL</td></tr></tbody></table><p style="text-align: center">CENTERWORD</p><hr><p>See https://example.com/bare</p><p>AFTERRULE LITERALS 2*3 &lt;b&gt;not bold&lt;/b&gt; [sic]</p>',
                'has' => ['codelang', 'bold', 'italic', 'underline', 'strike', 'icode', 'heading', 'quote', 'list', 'olist', 'code', 'link', 'image', 'spoiler', 'table', 'center', 'hr', 'literal', 'bareurl'],
            ],
        ];
    }
}
