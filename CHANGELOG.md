# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.5.3] — 2026-11-02

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

[0.5.3]: https://github.com/SIDEZRU/access-matrix/releases/tag/v0.5.3
