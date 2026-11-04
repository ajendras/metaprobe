# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-04

### Added

- Unified Extraction — Single API reads EXIF, IPTC and XMP from JPEG, PNG and TIFF in one call
- GPS Decoder — Converts DMS coordinates to decimal with Google Maps link generator
- Privacy Scanner — Flags risky fields and returns a numeric safety score
- Smart Sanitizer — Rewrites images stripping PII while preserving copyright and profiles
- Rich CLI — probe and sanitize commands with JSON output and verbose reporting
- Structured Reports — Metadata DTO with toArray, toJson and typed getters for easy automation
- Zero-config Core — Depends only on PHP extensions; optional Symfony Console for CLI
- Reader Interface — Pluggable readers so custom formats can be added in one file
