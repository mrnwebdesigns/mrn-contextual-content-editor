# AGENTS.md - MRN Contextual Content Editor

## Purpose
This directory contains the MRN Contextual Content Editor WordPress plugin in-repo standard plugin release unit.

## Rules
- Keep changes scoped to this plugin release unit.
- Preserve WordPress security controls for admin actions, REST/AJAX/admin-post handlers, nonces, capabilities, sanitization, and escaping.
- Treat `MRN_QA_CODE_ANALYSIS_SCOPE=all mrn-qa run --project-root /Users/khofmeyer/Development/MRN/plugins/mrn-contextual-content-editor` as the canonical plugin readiness signal.

## Safety
- Never auto-deploy from this plugin directory.
- Require explicit release references for promotion.
- Run separate runtime QA against a named site when validating front-end, admin, API, accessibility, or performance behavior.
