# IFSC YouTube Video Collection

[![Update video database](https://github.com/sportclimbing/ifsc-videos/actions/workflows/update-database.yml/badge.svg)](https://github.com/sportclimbing/ifsc-videos/actions/workflows/update-database.yml)
[![Update video database](https://img.shields.io/packagist/dt/sportclimbing/ifsc-youtube-videos)](https://packagist.org/packages/sportclimbing/ifsc-youtube-videos)


This fetches stream URLs from IFSC's YouTube channel using the API. Stream URLs are usually available there
days before an event starts. Their online calendar is not always up-to-date, and URLs are often missing.

This is used by [sportclimbing/ifsc-calendar](https://github.com/sportclimbing/ifsc-calendar) which generates
the calendar files for [sportclimbing/web](https://github.com/sportclimbing/web).

- Video information, such as title, ID, length, and publish date, can be found in [data/videos.json](data/videos.json)
- Video covers are [automatically downloaded](data/covers/), and magically upscaled using [deepai.org](https://deepai.org)

This can be installed via composer:
```shell
$ composer require sportclimbing/ifsc-youtube-videos:dev-main
```
