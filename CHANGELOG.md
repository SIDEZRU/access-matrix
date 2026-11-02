# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The release headings deliberately omit the `[...]` brackets that Keep a Changelog
normally uses: the app store parses the changelog of a release with the regular
expression `^## (\d+\.\d+\.\d+)`, so the version has to follow `## ` directly, and
the version in the heading has to match the version in `appinfo/info.xml`.

## 0.5.7 — 2026-11-02

### Added

- Store metadata in `appinfo/info.xml`: website, repository, documentation links, author contact
  and screenshots, so the app page in the app store is complete.

### Fixed

- `<bugs>` points to the public issue tracker instead of the internal one.
- `CHANGELOG.md` is shipped inside the release archive — the app store reads the changelog of a
  release from that file.
- The application details panel shows the author and the repository instead of `Array`: Nextcloud
  parses `info.xml` into arrays, and the previous string cast of an array produced `Array`
  (and `href="Array"` for the repository link).
- The website and repository links in the details panel show the address itself.

## 0.5.3 — 2026-11-02

### Added

- Section **Matrix** in *Settings → Administration* with the application × group table.
- Master switch per application: the *All* checkbox toggles every group of the row at once.
- Bulk operations on columns: click to select a column, <kbd>Shift</kbd> for a range from the
  anchor column, <kbd>Ctrl</kbd> for a selective pick.
- Deferred apply with a per-application change counter and a confirmation when the change would
  remove the administrator's own access.
- Grouping of applications by category from `info.xml`, with collapsible sections.
- Column counters showing how many applications in the current selection have access to a group.
- Ability to hide group columns and to reorder them by dragging the headers (stored per user).
- Application details panel with description, properties and current access.
- Warning marker for applications that treat a group restriction as a complete shutdown.

### Changed

- The section name in *Settings → Administration* matches the application name.
- Application names use the standard theme text colour.
