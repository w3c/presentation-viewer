# presentation-viewer
Template to show slides, videos and transcript of a presentation together

---

*Read the [blog
post](https://www.w3.org/blog/2020/09/making-video-pages-for-the-w3c-ac-meeting/)
on the W3C site about the effort that led to this script and how it was used.*

---

This directory contains the template (*talk.php, page.css*) and one
example presentation. The example presentation is made up of a slide
set (*slides.html, slides.css* and directory *i18n*), English and
translated captions (*captions-ja.vtt, captions-ko.vtt,
captions-zh.vtt* and *i18n-audio.vtt*), a transcript
(*i18n-audio.html*) and a file with time codes for synchronizing the
slides and the video (*times.json*). The video is not in this
directory, but is hosted on StreamFizz. (The link is in the PHP file.)

# Running the code

Running the template requires a web server with PHP. Copy all files
and the i18n subdirectory to a directory on that server and then open

  http://your-server/directory/talk.php/i18n

in a browser. (Replace http://your-server/directory/ with the actual
URL of that directory.)

After the page opens in your browser, try playing the video and
experiment with the ‘Sync video…’  button. (This snapshot only
contains one sample presentation and thus it is deliberate that the
links to other talks do not work.)

# Pre-processing workflow
To make use of this synchronized presentation player for other presentations, the following pre-processing needs to have happened:
* the slides need to be in a supported format
* the audio or video recording need to be available in a supported media player; the expectation is that the video recording contains *only* the speaker, not the slides
* WebVTT captions need to be available

With all this available:
* an HTML transcript is generated out of the captions using [webvtt2html](https://github.com/dontcallmedom/webvtt2html); the raw 1-sentence-per-paragraph content can be rearranged and completed with additional markup (e.g. links, `<code>`, etc.) at will
* the HTML transcript needs then to be marked up with `<div>` encompassing the paragraphs corresponding to the speech accompanying a given slide
* the decimal timecodes (in seconds) of the transitions between slides need to be determined and written to `times.json`
* the URLs of the above resources need to be added at the top of the PHP file

# Supported media players
* [StreamFizz](https://www.streamfizz.com/)

# Supported slide formats
* [shower.js](https://github.com/shower/core)

See [ideas for more formats](https://github.com/w3c/presentation-viewer/issues/2)
