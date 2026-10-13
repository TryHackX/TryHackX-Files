# Third-party notices

The PolyForm Noncommercial License in the repository root applies only to original TryHackX Files code.
Third-party components retain their own copyright notices and license terms.

## Font Awesome Free 6.5.2

TryHackX Files redistributes Font Awesome Free CSS and webfonts under
`public/assets/fontawesome/`. Copyright 2024 Fonticons, Inc.

- icons: Creative Commons Attribution 4.0 International;
- fonts: SIL Open Font License 1.1;
- code: MIT License.

The upstream license and attribution information is available at
<https://fontawesome.com/license/free>. The attribution comment embedded in
`public/assets/fontawesome/css/all.min.css` must not be removed.

## FFmpeg (via imageio-ffmpeg)

The `imageio-ffmpeg` Python package (BSD-2-Clause) installs a prebuilt static FFmpeg executable
inside the virtual environment. The upload service runs it only to extract a poster frame from
uploaded video. TryHackX Files does not contain or modify FFmpeg; the executable is downloaded by
`pip` as part of the wheel and is not covered by this project's license. FFmpeg is distributed
under the LGPL or GPL depending on the build options of the particular binary, with the
corresponding source available from <https://ffmpeg.org/download.html>; see
<https://ffmpeg.org/legal.html>. Whoever redistributes a virtual environment or a container image
built from `requirements-lock.txt` also redistributes that executable and takes over the
obligations that come with it.

## Installed dependencies

Composer and Python packages installed from `composer.lock`, `requirements-lock.txt` and
`requirements-dev-lock.txt` are third-party works. They are not relicensed under TryHackX Files'
PolyForm license. Each package's distribution and metadata contain its applicable license and
copyright notices, which must be retained when redistributing a build that contains the package.
