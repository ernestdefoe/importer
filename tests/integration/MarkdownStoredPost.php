<?php

namespace ErnestDefoe\Importer\Tests\integration;

/** A XenForo post as an import into a Markdown forum stored it (EditorFormattingTest's corpus, captured). */
final class MarkdownStoredPost
{
    public const XML = <<<'XML'
<r><p><STRONG><s>**</s>BOLDWORD<e>**</e></STRONG> <EM><s>*</s>ITALICWORD<e>*</e></EM> UNDERWORD <DEL><s>~~</s>STRIKEWORD<e>~~</e></DEL> REDWORD BIGWORD FONTWORD</p>

<H2><s>## </s>HEADINGWORD</H2>

<QUOTE><i>&gt; </i><p><STRONG><s>**</s>Ada wrote:<e>**</e></STRONG></p>
<i>&gt;</i>
<i>&gt; </i><p>QUOTEDTEXT</p></QUOTE>

<LIST><LI><s>- </s>LISTITEM1</LI>
<LI><s>- </s>LISTITEM2</LI></LIST>


<LIST type="decimal"><LI><s>1. </s>NUMITEM</LI></LIST>

<CODE lang="php"><s>```php</s><i>
</i>&lt;?php echo "CODELINE"; ?&gt;<i>
</i><e>```</e></CODE>

<p>Inline <C><s>`</s>ICODEWORD<e>`</e></C> here.</p>

<p><URL url="https://example.com/link"><s>[</s>LINKLABEL<e>](https://example.com/link)</e></URL> <URL url="https://example.com/unfurled">https://example.com/unfurled</URL> <EMAIL email="ada@example.com">ada@example.com</EMAIL><br/>
<IMG alt="pic" src="https://example.com/image.png"><s>![</s>pic<e>](https://example.com/image.png)</e></IMG></p>

<SPOILER><i>&gt;! </i><p><STRONG><s>**</s>Plot<e>**</e></STRONG></p>
<i>&gt;!</i>
<i>&gt;! </i><p>SPOILERTEXT</p></SPOILER>

<p><ISPOILER><s>&gt;!</s>INLINESPOILER<e>!&lt;</e></ISPOILER></p>

<p><STRONG><s>**</s>HEADCELL<e>**</e></STRONG><br/>
TABLECELL</p>

<p>CENTERWORD</p>

<p><URL url="https://www.youtube.com/watch?v=dQw4w9WgXcQ">https://www.youtube.com/watch?v=dQw4w9WgXcQ</URL></p>

<p>@Ada <ESC><s>\</s>[</ESC>Attachment: photo.jpg<ESC><s>\</s>]</ESC></p>

<HR>---</HR>

<p>AFTERRULE :) LITERALS 2<ESC><s>\</s>*</ESC>3 <ESC><s>\</s>&lt;</ESC>b&gt;not bold<ESC><s>\</s>&lt;</ESC>/b&gt; <ESC><s>\</s>[</ESC>sic<ESC><s>\</s>]</ESC></p></r>
XML;
}
