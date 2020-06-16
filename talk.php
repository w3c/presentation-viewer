<?php

# talk.php is (a pared down version of) the server-side part of the
# video pages for the W3C's 2020 AC meeting. It is a single PHP script
# that generates different pages based on the PATH_INFO, i.e., based
# on the URL it is invoked with.
#
# The script looks up the PATH_INFO in the list of videos it knows
# about and uses the other information in that list to generate an
# appropriate page. E.g., if the request is for https://.../talk/i18n,
# the response is an HTML page that contain the slides and the video
# about "i18n" (i.e., internationalization).
#
# The generated page consists of CSS and HTML, but also contains
# JavaScript, which runs on the client-side (i.e., after the page is
# loaded into a browser) to synchronize the slides and the video.
#
# The fact that this document contains PHP code and writes out, among
# other things, JavaScript code, makes the source a bit hard to
# read. But roughly the PHP code is all at the start, in the middle of
# the file are "echo" statements that produce CSS and HTML, and at the
# end are "echo" statements that write the JavaScript code.
#
# Author: Bert Bos <bert@w3.org>
# Created: 25 April 2020

# talksdata is an array of records, each record describing one talk.
# If a talk has audio instead of video, add a key 'audio' that points
# to the sound file.
#
$talksdata = [
[
 'key'        => 'CEPC',
 'title'      => 'Code of Ethics and Professional Conduct (CEPC)',
 'presenter'  => 'Tzviya Siegman',
 'slides'     => '...URL omitted....something.html',
 'transcript' => '...URL omitted....something.html',
 'captions'   => [
   'en'      => '...URL omitted...something.en.vtt',
   'zh-hans' => '...URL omitted...something.zh.vtt',
   'ko'      => '...URL omitted...something.ko.vtt',
   'ja'      => '...URL omitted...something.ja.vtt',
   ],
 'player'     => '... URL omitted...',
 'poster'     => '... URL omitted...something.jpg',
 'timecodes'  => '...URL omitted...something.json',
 'duration'   => '4 min',
],
[
 'key'        => 'i18n',
 'title'      => 'Internationalization',
 'presenter'  => 'Richard Ishida',
 'slides'     => 'slides.html',
 'transcript' => 'i18n-audio.html',
 'captions'   => [
   'en'      => 'i18n-audio.vtt',
   'zh-hans' => 'captions-zh.vtt',
   'ko'      => 'captions-ko.vtt',
   'ja'      => 'captions-ja.vtt'
   ],
 'player'     => 'https://app.streamfizz.live/embed/ck9u24qw9vxkr0801xjts7lgt',
 'poster'     => 'https://cjx1uopmt0m4q0667xmnrqpk.blob.core.windows.net/ck9u24qw9vxkr0801xjts7lgt/thumbs/thumb-001.jpeg',
 'timecodes'  => 'times.json',
 'duration'   => '29 min',
],
[
 'key'        => 'strategy-funnel',
 'title'      => 'Strategy funnel',
 'presenter'  => 'Wendy Seltzer',
 'slides'     => '...URL omitted....something.html',
 'transcript' => '...URL omitted....something.html',
 'captions'   => [
   'en'      => '...URL omitted...something.en.vtt',
   'zh-hans' => '...URL omitted...something.zh.vtt',
   'ko'      => '...URL omitted...something.ko.vtt',
   'ja'      => '...URL omitted...something.ja.vtt',
],
 'player'     => '... URL omitted...',
 'poster'     => '... URL omitted...something.jpg',
 'timecodes'  => '...URL omitted...something.json',
 'duration'   => '15 min',
],
];

# The names of the languages. (Used in a dropdown menu.)
#
$langlabel = [
  'en'      => 'English',
  'zh-hans' => '简体中文',	# Simplified Chinese
  'ko'      => '한국어',		# Korean
  'ja'      => '日本語',	# Japanese
];


# errexit -- exit with status 500 and an error message
function errexit($msg)
{
  if (!headers_sent()) {
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: text/plain');
  }
  die("$msg\n");
}


# error_cb -- callback for when errors occur
function error_cb($errno, $errstr, $errfile, $errline, $errcontext)
{
  if (!(error_reporting() & $errno)) return false;
  # Don't distinguish warnings from errors
  errexit("Error: $errfile:$errline: $errstr ($errno)");
  # Not reached:
  return true;
}


# rel2abs -- combine a relative URL with a base
function rel2abs($rel_url, $base_url)
{
  if ($rel_url === '') return $base_url;
  if ($rel_url[0] === '#') return preg_replace('(#.*)','',$base_url) . $rel_url;
  if ($rel_url[0] === '?') return preg_replace('(?.*)','',$base_url) . $rel_url;

  $base = parse_url($base_url);

  if (strpos($rel_url, '//') === 0) return $base['scheme'] . $rel_url;

  $rel = parse_url($rel_url);

  if (isset($rel['scheme'])) return $rel_url; # Already absolute

  if ($rel['path'][0] === '/')
    $path = $rel['path'];
  else
    $path = preg_replace('([^/]*$)', '', $base['path']) . $rel['path'];

  do {				# Replace any "/./" by "/".
    $path = str_replace('/./', '/', $path, $n);
  } while ($n !== 0);

  do {				# Replace any "/foo/../" by "/".
    $path = preg_replace('(/([^./][^/]*|\.[^./][^/]*|\.\.[^/]+)/\.\./)', '/',
      $path, -1, $n);
  } while ($n !== 0);

  $abs_url = '';
  if (isset($base['scheme'])) $abs_url .= $base['scheme'];
  if (isset($base['host'])) $abs_url .= '//';
  if (isset($base['user'])) $abs_url .= $base['user'];
  if (isset($base['pass'])) $abs_url .= ':' . $base['passw'];
  if (isset($base['user'])) $abs_url .= '@';
  if (isset($base['host'])) $abs_url .= $base['host'];
  $abs_url .= $path;
  if (isset($base['query'])) $abs_url .= '?' . $base['query'];
  if (isset($base['fragment'])) $abs_url .= '#' . $base['fragment'];
  return $abs_url;
}


# split_CSS -- parse a style sheet into an array of rules
function split_CSS($text)
{
  # This returns an array of the top-level rules and @-rules of a
  # style sheet. It is used to find any style rules in the original
  # file with slides so that those rules can be transformed to apply
  # to the slides once they are merged into the video page. It only
  # finds top-level rules, so any rules inside @supports or @media
  # will not be transformed and may cause failures. Better not use
  # them in the slides!  (@media can be replaced with a media
  # attribute on a STYLE element, if needed.)

  # This isn't a real CSS parser...
  $text = preg_replace('(/\*.*?\*/)s', '', $text, PREG_SET_ORDER);
  preg_match_all('
  ( @[^{]* ( \{ ( (?>[^{}]*) | (?-2) )* \} | ; )
  | [^@{\s][^@{]* \{ ( (?>[^{}]*) | (?-2) )* \}
  )sx', $text, $matches);
  return $matches[0];
}


# hide_entities - a hack to hide HTML entities from the XML parser
function hide_entities($text)
{
  # The PHP standard library contains a parser for XML and HTML, which
  # we use to parse the files with slides and the transcript. That
  # parser does not know the standard HTML character entities, such as
  # "&eacute;" and "&rarr;", because in XML, such entities are not
  # predefined, but need to be explicitly declared. Rather than
  # declare them, we use a trick to hide them: We replace the "&" by a
  # character that is unlikely to occur in the document, in this case
  # character 127 (delete). Just before we write out the parsed
  # elements, we change it back to "&".
  return str_replace('&', "\177", $text);
}


# unhide_entities - a hack to hide HTML entities from the XML parser
function unhide_entities($text)
{
  return str_replace("\177", '&', $text);
}


# make_CSS_absolute -- make URLs in url() absolute
function make_CSS_absolute($style, $base_url)
{
  # Finds all occurrences of url(...), url('...') and url("...") and
  # combines any relative URLs contained in them with the given base
  # URL. The function returns the style sheet thus transformed.

  $h = '';
  while (preg_match('(^(.*?\burl\(\s*")([^"]*)(.*)$)s', $style, $m)) {
    $h .= $m[1] . rel2abs($m[2], $base_url);
    $style = $m[3];
  }
  $style = $h . $style;

  $h = '';
  while (preg_match('(^(.*?\burl\(\s*\')([^\']*)(.*)$)s', $style, $m)) {
    $h .= $m[1] . rel2abs($m[2], $base_url);
    $style = $m[3];
  }
  $style = $h . $style;

  $h = '';
  while (preg_match('(^(.*?\burl\(\s*)([^\'"\)][^\s\)]*)(.*)$)s', $style,$m)) {
    $h .= $m[1] . rel2abs($m[2], $base_url);
    $style = $m[3];
  }
  $style = $h . $style;

  return $style;
}


# change_tag -- replace an element by one with a different name but same content
function change_tag($elt, $newname)
{
  if ($elt->tagName == $newname) return; # Element already has the right name
  $newelt = $elt->ownerDocument->createElement($newname);
  foreach ($elt->childNodes as $c)
    $newelt->appendChild($c->cloneNode(true));
  foreach ($elt->attributes as $a)
    $newelt->setAttribute($a->nodeName, $a->nodeValue);
  $elt->parentNode->replaceChild($newelt, $elt);
}


# Maximum error & warning reporting. Set our own handler, so we can
# return 500 instead of "200 OK".
#
error_reporting(E_ALL | E_NOTICE | E_STRICT | E_DEPRECATED);
ini_set('display_errors', 'on');
set_error_handler("error_cb", E_ALL | E_NOTICE | E_STRICT | E_DEPRECATED);

# The PATH_INFO should be the value of a 'key' in one of the records
# of the $talksdata array, indicating which talk to return. E.g.,
# "/ceo-overview".
#
if (!isset($_SERVER['PATH_INFO'])) die('Missing index');
$key = substr($_SERVER['PATH_INFO'], 1); # Remove "/"
$talk = -1;
foreach ($talksdata as $i => $d) if ($d['key'] == $key) {$talk = $i; break;}
if ($talk == -1) die("Not found: $key");

$slides_url = $talksdata[$talk]['slides'];
$title = $talksdata[$talk]['title'];
$transcript_url = $talksdata[$talk]['transcript'];
$captions = $talksdata[$talk]['captions']; # An array of language => url pairs
$player_url = isset($talksdata[$talk]['player']) ?
  $talksdata[$talk]['player'] : null;
$audio_url =  isset($talksdata[$talk]['audio']) ?
  $talksdata[$talk]['audio'] : null;
$timecodes_url = isset($talksdata[$talk]['timecodes']) ?
  $talksdata[$talk]['timecodes'] : null;
$presenter = $talksdata[$talk]['presenter'];
$poster = isset($talksdata[$talk]['poster']) ? $talksdata[$talk]['poster'] : "https://www.w3.org/2020/Talks/ac-slides/template/AC-2020-slides-banner.png";
$duration = $talksdata[$talk]['duration'];
$prev_page = isset($talksdata[$talk-1]) ? $talksdata[$talk-1]['key'] : null;
$prev_title = isset($talksdata[$talk-1]) ? $talksdata[$talk-1]['title'] : null;
$next_page = isset($talksdata[$talk+1]) ? $talksdata[$talk+1]['key'] : null;
$next_title = isset($talksdata[$talk+1]) ? $talksdata[$talk+1]['title'] : null;

# The query parameters 'cuelang' and 'sync' set the defaults for the
# controls of those names.
#
$defaultlang = isset($_REQUEST['cuelang']) ? $_REQUEST['cuelang'] : null;
$defaultsync = isset($_REQUEST['sync']);

# If we have audio instead of video, some strings need to change.
#
$video_or_audio = $audio_url ? "sound player" : "video";
$video_title = "$video_or_audio of ‘{$title}’ by $presenter";

# Load the page with slides.
#
$slidesdoc = new DOMDocument();
$s = @file_get_contents($slides_url) or
  $s = '<div class=slide>(No slides yet)</div>';
$slidesdoc->loadHTML(hide_entities($s), LIBXML_NOERROR);

# Make links absolute.
# TODO: Also take any <base> into account.
#
$xpath = new DOMXPath($slidesdoc);
foreach ($xpath->query('//*[@href]', $slidesdoc) as $h)
  $h->setAttribute("href",
    rel2abs(rel2abs($h->getAttribute("href"), $slides_url), '../'));
foreach ($xpath->query('//*[@src]', $slidesdoc) as $h)
  $h->setAttribute("src",
    rel2abs(rel2abs($h->getAttribute("src"), $slides_url), '../'));

# If the slides are SECTIONs, make them DIVs instead.
# TODO: The XPath 'contains(@class,"slide")' also matches class=slider...
#
foreach ($xpath->query('//section[contains(@class,"slide")]', $slidesdoc) as $s)
  change_tag($s, "div");

# Extract a list of slides. Also give each slide ARIA attributes and an ID.
#
$slides = $xpath->query('//div[contains(@class,"slide")]', $slidesdoc);
$nslides = $slides->length;
for ($i = $nslides - 1; $i >= 0; $i--) {
  $slides->item($i)->setAttribute("role", "region");
  $slides->item($i)->setAttribute("aria-label",
    "Slide " . ($i + 1) . " of " . $nslides);
  if (!$slides->item($i)->hasAttribute("id"))
    $slides->item($i)->setAttribute("id", "slide-$i"); # TODO: check uniqueness
}

# Extract a list of style elements. Make URLs in the style sheets
# absolute, or at least relative to "../". Extract the style rules
# from each style element and prefix each rule with a selector
# "#slides". That way the style rules do not apply to elements in the
# page outside the slides.
#
$styles = $xpath->query('//style', $slidesdoc);
foreach ($styles as $s) {
  $rules = split_CSS(make_CSS_absolute($s->textContent,
    rel2abs($slides_url, '../')));
  foreach ($rules as $i => $r)
    if ($r[0] !== '@') {
      preg_match('(^([^{]+)(.*)$)s', $r, $parts);
      $parts[1] = str_replace(',', ', #slides ', $parts[1]);
      $rules[$i] = '#slides ' . $parts[1] . $parts[2];
    }
  $s->textContent = "\n" . join("\n", $rules) . "\n";
}

# Load the page with the transcript. Ignore errors when loading or
# parsing the file.
#
$transcriptdoc = new DOMDocument();
$s = @file_get_contents($transcript_url) or $s = '<html>';
$transcriptdoc->loadHTML(hide_entities($s), LIBXML_NOERROR);

# Make all links relative to the page we are
# generating. ($transcript_url is either absolute or relative to this
# PHP script, and this PHP script has URL "../talk.php" relative to
# the page being generated.)
#
$xpath = new DOMXPath($transcriptdoc);
foreach ($xpath->query('//*[@href]', $transcriptdoc) as $h)
  $h->setAttribute("href",
    rel2abs(rel2abs($h->getAttribute("href"),$transcript_url), '../'));
foreach ($xpath->query('//*[@src]', $transcriptdoc) as $h)
  $h->setAttribute("src",
    rel2abs(rel2abs($h->getAttribute("src"), $transcript_url), '../'));

# Extract the DIV elements. (Each DIV corresponds to a slide.)
#
$transcripts = $xpath->query('//body/div', $transcriptdoc);

# Load the file with the time codes for synchronizing the video and the slides.
#
$timecodes = @file_get_contents($timecodes_url);

# Add class "active" to the first slide (if there is one).
#
if ($slides->length) {
  $oldclass = $slides->item(0)->getAttribute("class");
  $slides->item(0)->setAttribute("class", $oldclass . " active");
}

# Create a document fragment with the slides and transcripts interleaved.
#
$doc = new DOMDocument();
$fragment = $doc->createDocumentFragment();
$ntranscripts = $transcripts->length;
$i = 0;
while ($i < $nslides || $i < $ntranscripts) {
  if ($i < $nslides) {
    $fragment->appendChild($doc->importNode($slides->item($i), true));
    $fragment->appendChild($doc->createTextNode("\n\n"));
  }
  if ($i < $ntranscripts) {
    $fragment->appendChild($doc->importNode($transcripts->item($i), true));
    $fragment->appendChild($doc->createTextNode("\n\n"));
  }
  $i++;
}

# Output an HTML file with the computed information inserted.
# TODO: What is a good value for max-age?
#
header('Cache-Control: private, max-age=60');
echo '<!DOCTYPE html>
<html lang=en-us>
  <head>
    <meta charset=utf-8>
    <meta name=viewport content="width=device-width">
    <title>W3C AC 2020 &ndash; ', $title, '</title>
    <meta name="twitter:site" content="@w3cdevs">
    <meta name="twitter:card" content="summary_large_image">
    <meta property="og:title" content="' . $title . ' - W3C AC Meeting May 2020">
    <meta property="og:description" content="' . $presenter .'’s presentation on “' . $title . '” to the W3C Advisory Committee meeting in May 2020.">
    <meta property="og:image" content="' . $poster .'">
    <meta property="twitter:image" content="' . $poster .'">
    <link rel=stylesheet media="screen, print" href="../slides.css">';
foreach ($styles as $s) echo "\n", $slidesdoc->saveHTML($s);
echo '
    <link rel=stylesheet media="screen, print"
      href="../page.css">
    <style>
      .button {-webkit-appearance: button;
        appearance: button; text-decoration: none; cursor: default;
        color: buttontext; letter-spacing: normal;
        word-spacing: normal; display: inline-block;
        text-align: center; background-color: buttonface;
        font: 400 medium system-ui, Segoe UI, sans-serif; font: -moz-button;
        padding: 1px 8px 2px; border-radius: 0.25em;
        /*border: 1px outset ThreeDLightShadow;*/
        /*border: 1px outset buttonface*/}
      [for=sync] {padding-left: 2em; margin-top: 1.2rem}
      .button.picto, button.picto {padding-right: 4px}
      #sync {position: relative; z-index: 1; width: 1em;
        margin: 0 -1.5em 0 0.5em}
      #player {margin-top: 1.2rem}
      .buttons button {font: inherit; font-weight: 700; cursor: pointer}
      .slide:target {outline: none} /* No big green outline */
      section:nth-of-type(2n+2) .button:focus,
      section:nth-of-type(2n+2) button:focus {outline-color: white}

      iframe {width: 100%; height: 100%}
      #video1 {overflow: hidden} /* Force border-radius on the video */

      output button {cursor: pointer}

      /* On small screens, the slides are scaled down. */
      .slide {font-size: 2.32vw}
      @media (min-aspect-ratio: 12/9) { .slide {font-size: 2.03vw} }
      @media (min-aspect-ratio: 18/9) { .slide {font-size: 3.3vh} }
      #video1, #audio, #slidenr {width: 88vw; margin: 0 0 1rem 0}
      #video1 {height: 49.5vw}
      #slidenr, #caption {box-sizing: border-box}

      #player, #slides {position: relative}
      #player::after {content: " "; display: block; height: 0; clear: both}
      #video1, #slidenr, #caption, #cue {border-radius: 0.5em}
      #video1, #audio {border: none}
      #audio {display: block; height: 54px}
      #slidenr {display: block; background: hsla(204,69%,15%,0.8); color: white;
        text-align: center}
      #caption, #prevnext {display: none}
      #caption {height: 3.2em}
      #caption, #cue {background: hsl(204,69%,16%)}
      #caption, #slidenr {text-align: center; padding: 0.3em;
        margin: 0 0 1rem 0}
      #cue {display: block; margin: -0.3em; padding: 0.3em}
      #cue[lang|=zh] {font-size: 110%; min-height: 1.6em}
      #cuelang {float: right}
      .comment, .progress {display: none}
      .slide {width: 40.889em;
        height: auto; min-height: 23em; /* slides.css uses rem */
        display: block; margin: 0 0 1rem 0; box-shadow: none}
      .slide li {padding-left: 0}
      .slide table {font-size: 100%} /* Undo 0.9em in tpac2.css */

      #talk details {margin-bottom: 2em}
      summary::before {font-size: 1em; margin-left: 0}
      
      /* Tables. Set the defaults explicitly, to override tpac2.css */
      #slides {hyphens: manual}
      #slides table {background: none; display: table; border-spacing: 2px;
        width: auto;
        border-collapse: separate; box-sizing: border-box; text-indent: 0}
      #slides td, #slides th {border: none; padding: 1px; height: auto;
        white-space: normal; width: auto;
        display: table-cell; vertical-align: inherit; text-align: unset}
      #slides th {text-align: center}

      /* In synchronized mode, show only the active slide. */
      #sync:checked ~ #player .slide {position: absolute; top: 0; left: 0;
        visibility: hidden}
      #sync:checked ~ #player .slide.active {position: relative;
        visibility: visible}
      #sync:checked ~ #prevnext {display: inline}
      #sync:checked ~ #player #slides > *:not(.slide) {display: none}
      #sync:checked ~ #player #caption {display: block}

      /* ... and only the active elements of incremental display. */
      #sync:checked ~ #player .next {visibility: hidden}
      #sync:checked ~ #player .slide.active .next.active {visibility: visible}

      /* Make the link to the current slide in #slidenr less visible. */
      #sync:checked ~ #player #slidenr a {text-decoration: none}

      /* Avoid transition effects when synchronization is off.*/
      #sync:not(:checked) ~ #player .slide {animation: none}

      /* No animation when moving backwards. */
      #sync:checked ~ #player .slide.active ~ .visited {animation: none}

      /* Turn off transitions on A inside slides in synchronized mode. */
      #sync:checked ~ #player .slide a {transition: none}

      /* When the section has 2em margins. */
      @media (min-width: 46em) and (max-aspect-ratio: 18/9) {
        #slides, #caption, #audio, #video1, #slidenr {width: 40.889em}
        #video1 {height: 23em}
        .slide {font-size: 1em; height: 23em; width: 40.889em; overflow: hidden}
      }

      /* If the window is wide enough for a slide at its normal size
      and a bit more, show the video on the right. At small window
      sizes, the video is 22rem wide and overlaps the slides a bit. As
      the window grows, the video overlaps less.

      At 69.5em, there is no overlap anymore and the video can start to grow.

      At 88em, the video is the same size as the slides and it can
      stop to grow. */

      @media (min-width: 62em) {
        #slides, #caption {width: 40.889em}
        .slide {font-size: 1em; height: 23em; width: 40.889em}

        /* When video and slides are not synchronized, the video is fixed. */
        #slidenr, #video1, #audio {margin: 0; position: fixed;
          z-index: 2; width: 22rem; width: calc(45.6rem - 23em);
          margin-left: calc(100% - 45.6rem + 23em)}
        #video1 {height: 10.125rem; height: calc((45.6rem - 23em) * 9/16);
          max-height: 100%}
        #slidenr {bottom: 1em}
        #video1, #audio {bottom: 3.5em}

        /* In synchronized mode, the video may partly overlap the slide. */
        #sync:checked ~ #player #slidenr,
        #sync:checked ~ #player #audio,
        #sync:checked ~ #player #video1 {position: absolute;
          bottom: auto; top: 8em}
        #sync:checked ~ #player #slidenr {
          margin-top: calc(1rem + (45.6rem - 23em) * 9/16)}
        #sync:checked ~ #player #audio + #slidenr {
          margin-top: calc(1rem + 54px)}

        #caption, #slidenr {height: 1.9em}

        /* If sticky positioning is supported, use that instead of fixed. */
        @supports (position: sticky) or (position: -webkit-sticky) {
          body {overflow: visible}
          #slidenr, #video1, #audio {position: -webkit-sticky; position: sticky;
            bottom: auto; z-index: 2}
          #video1 {top: 0.5em; margin-bottom: calc((23em - 45.6rem) * 9/16)}
          #audio {top: 0.5em; margin-bottom: -54px}
          #slidenr {margin-top: calc(1rem + (45.6rem - 23em) * 9/16);
            top: calc(1.5rem + (45.6rem - 23em) * 9/16);
            margin-bottom: calc(-1rem - 1.9em - (45.6rem - 23em) * 9/16)}
          #audio + #slidenr {margin-top: calc(1rem + 54px);
            top: calc(1rem + 0.5em + 54px);
            margin-bottom: calc(-1rem - 1.9em - 54px)}
        }
      }

      @media (min-width: 69.5em) {
        #slidenr, #video1, #audio {width: 16.9332rem;
          width: calc(100vw - 6rem - 40.889em);
          margin-left: calc(100% - 100vw + 6rem + 40.889em)}
        #video1 {height: 9.524925rem;
          height: calc((100vw - 6rem - 40.889em) * 9/16)}
        #sync:checked ~ #player #slidenr,
        #sync:checked ~ #player #audio,
        #sync:checked ~ #player #video1 {top: calc(38.05em - 43.24vw)}
        #sync:checked ~ #player #slidenr {
          margin-top: calc(1rem + (100vw - 6rem - 40.889em) * 9/16)}

        @supports (position: sticky) or (position: -webkit-sticky) {
          #video1 {margin-bottom: calc(0em - (100vw - 6rem - 40.889em) * 9/16)}
          #slidenr {margin-top: calc(1rem + (100vw - 6rem - 40.889em) * 9/16);
            top: calc(1.5rem + (100vw - 6rem - 40.889em) * 9/16);
            margin-bottom: calc(-1rem - 1.9em - (100vw - 6rem - 40.889em) * 9/16)}
        }
      }

      @media (min-width: 88em) {
        #slidenr, #audio, #video1 {width: 40.889em;
          /*margin-left: calc(100% - 40.889em);*/ margin-left: 42.889em}
        #video1 {height: 23em}
        #sync:checked ~ #player #slidenr,
        #sync:checked ~ #player #audio,
        #sync:checked ~ #player #video1 {width: 40.889em}
        #sync:checked ~ #player #video1 {height: 23em}
        #sync:checked ~ #player #audio,
        #sync:checked ~ #player #video1,
        #sync:checked ~ #player #slidenr {top: 0}
        #sync:checked ~ #player #slidenr {margin-top: calc(1rem + 23em)}

        @supports (position: sticky) or (position: -webkit-sticky) {
          #video1 {margin-bottom: -23em}
          #slidenr {margin-top: calc(1rem + 23em);
            top: calc(1.5rem + 23em);
            margin-bottom: calc(-1rem - 1.9em - 23em)}
        }
      }
    </style>
    <script src="../webvtt-parser.js"></script>
    <link rel=first href="../Overview.html">
    <link rel=index href="../ac-agenda.html">
';
if ($prev_page !== null) echo "    <link rel=prev href=\"$prev_page\">\n";
if ($next_page !== null) echo "    <link rel=next href=\"$next_page\">\n";

echo '    <link rel=author href="mailto:w3t-comm@w3.org">
    <link rel=license href="../../../../Consortium/Legal/copyright-documents">
    <!-- pragma check.pl = nosponsors -->
  </head>
  <body>
    <header id=header>
      <!--<p class=skip><a title="Skip to content" aria-label="Skip to content"
      href="#intro"><span>Skip</span> ⬇︎</a></p>-->

      <h1>Video page</h1>

    </header>

    <main>
      <section id=intro>
        <!--<p class=skip><a title="Next section" aria-label="Next section"
        href="#talk"><span>Skip</span> ⬇︎</a></p>-->

        <h1>', $title, '</h1>

        <p>Presenter: <strong>', $presenter, '</strong><br>
        Duration: <strong>', $duration, '</strong></p>

        <p class=buttons>';
if ($prev_page !== null)
  echo '
          <button type=submit form=form formaction="', $prev_page, '#intro"
          class="picto im-arrow-left">Previous: ', $prev_title, '</button>
';
echo '
          <a href="../ac-agenda.html#sessions" class="picto im-data"
          >All talks</a>';
if ($next_page !== null)
  echo '

          <button type=submit form=form formaction="', $next_page, '#intro">Next:
          ', $next_title, '<span
          class="picto im-arrow-right"></span></button>';
echo '
        </p>
      </section>

      <section id=talk>
        <!--<p class=skip><a title="Next section" aria-label="Next section"
        href="#extrabuttons"><span>Skip</span> ⬇︎</a></p>-->

        <h2>Slides &amp; video</h2>
';
if (!$audio_url)
  echo '
        <details>
          <summary>Keyboard shortcuts in the video player</summary>
          <ul>
            <li>Play/pause: <kbd>space</kbd>
            <li>Increase volume: <kbd>up arrow</kbd>
            <li>Decrease volume: <kbd>down arrow</kbd>
            <li>Seek forward: <kbd>right arrow</kbd>
            <li>Seek backward: <kbd>left arrow</kbd>
            <li>Captions on/off: <kbd>C</kbd>
            <li>Fullscreen on/off: <kbd>F</kbd>
            <li>Mute/unmute: <kbd>M</kbd>
            <li>Seek percent: <kbd>0-9</kbd>
          </ul>
        </details>
';
echo '
        <form id=form>
        <input type=checkbox name=sync id=sync',
	($defaultsync ? ' checked' : ''), '
        ><label for=sync class=button>Sync video and hide transcript
        <a href="../talk-help.html" title="Help" class="picto im-question"
        ></a></label> 

        <a class="picto im-angle-up button" id=align href="#align"
        title="Align to top of window"></a> 
';
if ($timecodes_url && ($player_url || $audio_url))
  echo '
        <span id=prevnext aria-label="Slide navigation controls"
        role=navigation >
          <a id=firstslide href="#firstslide" title="First slide" class=button
          role=button >1st</a>

          <a id=prevslide href="#prevslide" class="picto im-arrow-left button"
          title="Previous slide" role=button ></a>

          <a id=nextslide href="#nextslide" class="picto im-arrow-right button"
          title="Next slide" role=button ></a>
        </span>
';
echo '
        <div id=player>';

echo '
      <script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "VideoObject",
  "name": "' . $title .'",
  "description": "' . $presenter .'’s presentation on “' . $title . '” to the W3C Advisory Committee meeting in May 2020.",
  "thumbnailUrl": "' . $poster .'",
  "uploadDate": "2020-05-08T13:00:00+02:00",
  "duration": "PT' .(explode(' ', $duration)[0]). 'M0S",
  "embedUrl": "' .$player_url .'"}
</script>
';

if ($audio_url) {
  echo '
          <audio id=audio controls
          title="', $video_title, '"
          src="', rel2abs($audio_url, '../'), '"
          ></audio>';
} elseif ($player_url) {
  echo '
          <div id=video1><iframe id=video width=640 height=360
          title="', $video_title, '"
          src="', rel2abs($player_url, '../'), '"
          frameborder=0 allow="accelerometer; autoplay; encrypted-media;
          picture-in-picture" allowFullScreen ></iframe></div>
';
}
if ($slides->length && ($player_url || $audio_url))
  echo '
          <output id=slidenr aria-live=polite><a href="#',
          $slides->item(0)->getAttribute("id"), '">',
          $slides->item(0)->getAttribute("aria-label"), '</a></output>';

echo '
          <div id=slides class=fade-in role=region aria-live=off
          aria-label="Slide container">

', unhide_entities($doc->saveHTML($fragment)),
              '          </div><!-- id=slides -->

          <p id=caption>
            <select title="Language for subtitles" id=cuelang name=cuelang>';

foreach ($captions as $lang => $url) {
  echo "\n              <option value=$lang";
  if ($lang === $defaultlang) echo ' selected';
  echo '>', (isset($langlabel[$lang]) ? $langlabel[$lang] : $lang);
}

echo '
              <option value=x-none',
              ($defaultlang === 'x-none' ? ' selected' : ''), '>no captions
            </select>
            <output id=cue aria-live=off>
              <noscript>(Synchronization requires JavaScript)</noscript>
            </output>
          </p>
        </div><!-- id=player -->
        </form>
      </section>

      <section id=extrabuttons>
        <!--<p class=skip><a title="Next section" aria-label="Next section"
        href="#footer"><span>Skip</span> ⬇︎</a></p>-->

        <p class=buttons>';
if ($prev_page !== null)
  echo '
          <button type=submit form=form formaction="', $prev_page, '#intro"
          class="picto im-arrow-left">Previous: ', $prev_title, '</button>';
echo '

          <a href="../ac-agenda.html#sessions" class="picto im-data"
          >All talks</a>';
if ($next_page !== null)
  echo '

          <button id=nexttalk type=submit form=form formaction="', $next_page, '#intro">Next:
          ', $next_title, '<span
          class="picto im-arrow-right"></span></button>';
echo '
        </p>
      </section>
    </main>

    <footer id=footer>
      <p class=skip><a title="Back to top" aria-label="Back to top"
      href="#header"><span>Top</span> ⇱</a></p>
    </footer>

    <script>
    (function() {

      "use strict";

      // timecodes is specific to this presentation. It gives the times
      // (in seconds) in the video at which each slide should be shown.
      const timecodes = ', ($timecodes ? $timecodes : '[0.0]'), ';

      // caption_urls points to WebVTT files with subtitles for the
      // video in various languages.
      const caption_urls = {';

foreach ($captions as $lang => $url)
  echo "\n        \"$lang\": \"", rel2abs($url, '../'), "\",";

echo '
      };

      let captions = {};        // For each language one array of subtitles
      let cuelang_elt = document.getElementById("cuelang");
      let cuelang = cuelang_elt.value; // Chosen language for subtitles
      let cue_elt = document.getElementById("cue");
      let slidenr_elt = document.getElementById("slidenr");
      let firstslide_elt = document.getElementById("firstslide");
      let prevslide_elt = document.getElementById("prevslide");
      let nextslide_elt = document.getElementById("nextslide");
      // "syncelts" includes both slides and incremental display items.
      let syncelts = document.querySelectorAll(".slide, .next");
      let current = 0;          // Active element (index in syncelts)
      let curslide = 0;         // Currently visible slide (index in syncelts)
      let curcue = -1;          // Current caption (index in captions[curlang])
      let video = document.getElementById("video"); // Only one of video and
      let audio = document.getElementById("audio"); // audio should be defined
      let nexttalk_elt = document.getElementById("nexttalk");


      // Load the captions into the captions array in the background.
      for (let lang in caption_urls) {
        let req = new XMLHttpRequest();
        req.my_lang = lang;     // A way to pass the language to the listener
        req.addEventListener("load", function(ev) {
          let parser = new WebVTTParser();
          let tree = parser.parse(ev.target.responseText, "captions");
          if (tree.errors.length)
            cue_elt.textContent = "Error: line " + tree.errors[0].line +
              " of " + lang + ": " + tree.errors[0].message;
          for (let i = tree.cues.length - 1; i >= 0; i--)
            tree.cues[i].text = tree.cues[i].text.replace(/<\/?v[^>]*>/g,"");
          captions[ev.target.my_lang] = tree.cues;
        });
        req.open("GET", caption_urls[lang]);
        req.send();
      }

      // Round the timecodes to whole seconds, because the StreamFizz
      // player can only seek to whole seconds.
      // Also work around a bug in the player: seeking to 0 does not
      // work, but seeking to 0.01 does.
      if (video) {
        for (let i = 0; i < timecodes.length; i++)
          timecodes[i] = Math.round(timecodes[i]);
        if (timecodes[0] == 0) timecodes[0] = 0.01;
      }


      // Event handler for the SELECT to choose the language of the subtitles
      document.getElementById("cuelang").addEventListener("change",
        function(ev) {
          cuelang = ev.target.value;
          if (cuelang in captions && curcue in captions[cuelang]) {
            cue_elt.setAttribute("lang", cuelang);
            cue_elt.innerHTML = captions[cuelang][curcue].text;
          } else {
            cue_elt.removeAttribute("lang");
            cue_elt.innerHTML = "";
          }
        });


      // announce -- show the slide label in the output element
      function announce(n)
      {
        if (!slidenr) return;             // No output element

        let label = syncelts[n].getAttribute("aria-label");
        let target = syncelts[n].id;
        
        if (!target) {
          slidenr_elt.textContent = label;
        } else {
          while (slidenr_elt.firstChild)
            slidenr_elt.removeChild(slidenr_elt.firstChild);
          let e = document.createElement("A");
          e.textContent = label;
          e.href = "#" + target;
          slidenr_elt.appendChild(e);
        }
      }


      // activate -- deactivate the current element and activate the new one
      function activate(new_index)
      {
        if (new_index < current) {

          // Deactivate the old current element and activate the new.
          syncelts[current].classList.remove("active");
          current = new_index;
          syncelts[current].classList.add("active");

          // Find containing slide i.
          let i = current;
          while (i > 0 && !syncelts[i].classList.contains("slide")) i--;

          // If it is a new slide, deactivate the old one and activate the new.
          if (i != curslide) {

            // Deactivate the old slide and elements inside it.
            do syncelts[curslide++].classList.remove("active");
            while (curslide < syncelts.length &&
                     ! syncelts[curslide].classList.contains("slide"));

            // Activate the new slide.
            syncelts[i].classList.add("active");

            // Announce the new slide number.
            announce(i);
            curslide = i;
          }

        } else if (new_index > current) {

          current = new_index;

          // If this is a slide, deactivate the previous slide.
          if (syncelts[current].classList.contains("slide")) {

            syncelts[curslide].classList.remove("active");
            syncelts[curslide].classList.add("visited");
            curslide = current;

            // Announce the new slide number.
            announce(current);
          }

          // Activate the new current element (slide or other element).
          syncelts[current].classList.add("active");
        }
      }


      // seek_video -- set the video to the start time of the current slide
      function seek_video()
      {
        if (current in timecodes) {
          if (video)
            video.contentWindow.postMessage(["seek" ,timecodes[current]], "*");
          else if (audio)
            audio.currentTime = timecodes[current];
        }
      }

      // Event handler for the "first slide" button.
      if (firstslide_elt) firstslide_elt.addEventListener("click",
        function(ev) {
          ev.preventDefault();
          activate(0);          // Move the "active" class
          // console.log("first: current = "+current+" t -> "+timecodes[current]);
          seek_video();
        });


      // Event handler for the "previous slide" button.
      if (prevslide_elt) prevslide_elt.addEventListener("click",
        function(ev) {
          ev.preventDefault();
          if (current == 0) return; // Already at first element
          activate(current - 1); // Move the "active" class
          //console.log("prev: current = "+current+" t -> "+timecodes[current]);
          seek_video();
        });


      // Event handler for the "next slide" button.
      if (nextslide_elt) nextslide_elt.addEventListener("click",
        function(ev) {
          ev.preventDefault();
          if (current == syncelts.length - 1) return; // No next element
          activate(current + 1); // Move the "active" class
          //console.log("next: current = "+current+" t -> "+timecodes[current]);
          seek_video();
        });


      // search -- return index of x in array a, or -1 if not found
      function search(x, a, cmp)
      {
        let lo = 0, hi = a.length - 1;
        while (lo <= hi) {
          let m = Math.floor((lo + hi)/2), r = cmp(x, a[m]);
          if (r < 0) hi = m - 1;
          else if (r > 0) lo = m + 1;
          else return m;
        }
        return -1;
      }


      // As the video plays, announce the relevant slide and make it active.
      window.addEventListener("message",
        function(ev) {
          const type = ev.data[0];
          const t = ev.data[1];
          if (type !== "position") return;
          // console.log("message: t = " + t);

          // Find the caption corresponding to time t.
          if (cuelang in captions) {
            let i = search(t, captions[cuelang],
               function(a,b) {return a<b.startTime ? -1 : a>b.endTime ? 1 : 0});
            if (i == curcue) ;  // Output already has the right cue
            else if (i < 0) cue_elt.innerHTML = "";
            else cue_elt.innerHTML = captions[cuelang][i].text;
            curcue = i;
          }

          // Find index i corresponding to time t. Search forward then backward.
          let i = Math.min(current, timecodes.length - 1);
          while (i < timecodes.length - 1 && t > timecodes[i]) i++;
          while (i > 0 && t < timecodes[i]) i--;

          // If i is not the current element, move the "active" class.
          activate(i);
        });


      // As the audio plays, announce the relevant slide and make it active.
      if (audio)
        audio.addEventListener("timeupdate",
          function(ev) {
            let t = ev.target.currentTime;
            // console.log("timeupdate: t = " + t);

            // Find the caption corresponding to time t.
            if (cuelang in captions) {
              let i = search(t, captions[cuelang],
                function(a,b){return a<b.startTime ? -1 : a>b.endTime ? 1 : 0});
              if (i == curcue) ;  // Output already has the right cue
              else if (i < 0) cue_elt.innerHTML = "";
              else cue_elt.innerHTML = captions[cuelang][i].text;
              curcue = i;
            }

            // Find index corresponding to time t. Search forward then backward.
            let i = Math.min(current, timecodes.length - 1);
            while (i < timecodes.length - 1 && t > timecodes[i]) i++;
            while (i > 0 && t < timecodes[i]) i--;

            // If i is not the current element, move the "active" class.
            activate(i);
          });


      // When the audio ends, show a link to the next talk in the captions area
      if (audio) audio.addEventListener("ended", function(ev) {
	if (!nexttalk_elt) return;
        while (cue_elt.firstChild) cue_elt.removeChild(cue_elt.firstChild);
        cue_elt.appendChild(nexttalk_elt.cloneNode(true));
        cue_elt.removeAttribute("lang");
      });

    })();
    </script>
  </body>
</html>
';
