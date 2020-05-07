# presentation-viewer
Template to show slides, videos and transcript of a presentation together

# Pre-processing workflow
To make use of this synchronized presentation player, the following pre-processing needs to have happened:
* the slides needs to be in a supported format
* the audio or video recordings needs to be available in a supported media player; the expectation is that the video recording contains *only* the speaker, not the slides
* WebVTT captions needs to be available

With all this available:
* an HTML transcript is generated out of the captions using [webvtt2html](https://github.com/dontcallmedom/webvtt2html); the raw 1-sentence-per-paragraph content can be rearranged and completed with additional markup (e.g. links, `<code>`, etc) at will
* the HTML transcript needs then to be marked up with `<div>` encompassing the paragraphs corresponding to the speech accompanying a given slide
* the decimal timecodes (in seconds) of the transitions between slides needs to be extracted and documented in `times.json`

# Supported media players
* [StreamFizz](https://www.streamfizz.com/)

# Supported slide formats
* [shower.js](https://github.com/shower/core)

See [ideas for more formats](https://github.com/w3c/presentation-viewer/issues/2)