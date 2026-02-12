# AGENTS.md — Rhymix Module Development Guide

This file provides instructions for human and AI contributors working on a **Rhymix module**.

It is based on Rhymix official manual pages and the Rhymix core repository structure:
- Manual: https://rhymix.org/manual
- Rhymix source: https://github.com/rhymix/rhymix

---

## 1) Scope and Principles

- Build features as a **module** when they need their own URL routes, pages, or API endpoints.
- Keep modules self-contained: avoid core modifications whenever possible.
- Prefer extension points (events, handlers, routing, permissions) over patching core behavior.
- Preserve compatibility with common Rhymix/XE extension patterns where practical.

---

## 2) Recommended Module Structure

Follow the patterns used in `modules/board`, `modules/page`, and other core modules.

Typical structure:

```text
modules/<module_name>/
  conf/
    info.xml
    module.xml
  lang/
    en.lang.php
    ko.lang.php
    ...
  queries/
    *.xml
  ruleset/
    *.xml
  skins/
    <skin_name>/...
  m.skins/
    <mobile_skin_name>/...

  <module_name>.class.php
  <module_name>.controller.php
  <module_name>.model.php
  <module_name>.view.php
  <module_name>.mobile.php          (if needed)
  <module_name>.api.php             (if needed)
  <module_name>.admin.controller.php
  <module_name>.admin.model.php     (if needed)
  <module_name>.admin.view.php
```

Notes:
- Some modules may not need all files. Keep only what is necessary.
- Keep naming consistent with Rhymix core conventions.

---

## 3) XML Config Responsibilities

### `conf/info.xml`
- Module metadata (title, description, version, category, author).
- Provide multilingual titles/descriptions where possible.

### `conf/module.xml`
- Declare permissions (`<grants>`), actions (`<actions>`), routes, menus, and event handlers.
- Explicitly map public/admin actions and required permissions.
- Prefer clear route patterns and predictable priorities.

---

## 4) Coding Standards (Required)

Follow Rhymix coding standards from the manual (`/manual/contrib/coding-standards`):

- UTF-8 without BOM, LF line endings.
- Use tabs for indentation unless file type convention requires spaces (e.g., Markdown/YAML).
- PHP-only files: omit closing `?>`.
- Braces for classes/functions/control structures; do not omit braces in one-line conditionals.
- Use PHPDoc (`/** ... */`) for all classes and functions.
- Prefer English comments (complete, clear sentences).
- For newly added class methods, declare visibility, parameter types, and return types where compatibility allows.
- Use strict comparisons (`===`) when appropriate.
- Follow PSR-1 and PSR-12 for anything not explicitly covered.

Compatibility caution:
- Rhymix supports PHP 7.4+, so avoid syntax/features unavailable in supported versions when targeting broad compatibility.

---

## 5) Security and Stability Rules

- Do not expose vulnerabilities publicly; treat security reports as sensitive.
- Validate all user input and admin form input (rulesets + server-side checks).
- Enforce permissions in both UI flow and action handlers.
- Avoid introducing notices/deprecations/warnings under Rhymix default error reporting.
- Keep frontend source editable by users (no mandatory obfuscation/build-only delivery for your own code).

---

## 6) Routing, Permissions, and Events

- Every actionable endpoint should have explicit action metadata and permission requirements.
- Keep read/write/admin permissions separated.
- Use event handlers to integrate with other modules safely.
- Avoid hard-coupling to internals of unrelated modules.

---

## 7) Database, Queries, and Data Handling

- Put module queries under `queries/*.xml` and keep query names stable.
- Keep schema and migration behavior predictable and reversible where possible.
- Use Rhymix DB helpers and framework facilities instead of raw ad-hoc SQL when core patterns exist.

---

## 8) Localization and UX

- Externalize UI strings to `lang/*.lang.php`.
- Provide at least Korean and English strings for user-facing/admin-facing text when possible.
- Keep admin menus and labels concise and consistent.

---

## 9) Contribution / PR Hygiene

- Keep each PR focused on one topic.
- Do not include unrelated formatting refactors in feature/fix PRs.
- Ensure tests/checks pass when available.
- Write commit messages in imperative present tense (e.g., `Fix null check in admin controller`).

---

## 10) What an AI Agent Should Do Before Editing

1. Read this file.
2. Inspect an existing core module with similar behavior (`modules/board`, `modules/page`, etc.).
3. Confirm required actions/routes/permissions in `conf/module.xml`.
4. Add/update language keys.
5. Run lint/tests available in the project.
6. Summarize all changed files and the rationale.

---

## 11) What an AI Agent Must Not Do

- Do not modify Rhymix core files unless explicitly requested.
- Do not bypass permission checks for convenience.
- Do not hardcode untranslated strings in templates/controllers.
- Do not mix unrelated cleanup with functional changes.

---

## 12) Quick Checklist

- [ ] Module metadata defined (`conf/info.xml`)
- [ ] Actions/routes/grants/events defined (`conf/module.xml`)
- [ ] Controller/model/view/admin classes aligned with naming conventions
- [ ] Queries/rulesets added and referenced correctly
- [ ] Language files updated
- [ ] Security and permission checks validated
- [ ] Coding standards satisfied
- [ ] Change scope is focused and documented
