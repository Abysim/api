<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# Enums

## Purpose
PHP 8.1 backed enums defining status workflows for the two main content types.

## Key Files

| File | Description |
|------|-------------|
| `NewsStatus.php` | Integer-backed enum with 13 states: CREATED(0) -> REJECTED_BY_KEYWORD(1) / REJECTED_BY_CLASSIFICATION(2) / REJECTED_BY_DEEP_AI(8) / REJECTED_BY_DEEPEST_AI(11) / REJECTED_BY_DUP_TITLE(7) / REJECTED_AS_OFF_TOPIC(9) / REJECTED_MANUALLY(4) / BEING_PROCESSED(10) / PENDING_REVIEW(3) / APPROVED(5) / PUBLISHED(6) / FAILED(12) |
| `FlickrPhotoStatus.php` | Integer-backed enum with 8 states: CREATED(0) -> REJECTED_BY_TAG(1) / REJECTED_BY_CLASSIFICATION(2) / PENDING_REVIEW(3) / REJECTED_MANUALLY(4) / APPROVED(5) / PUBLISHED(6) / REMOVED_BY_AUTHOR(7) |

## For AI Agents

### Working In This Directory
- Both enums follow similar workflow patterns: Created -> Classification/Filter -> Review -> Approve/Reject -> Publish
- News has additional AI-depth tiers: classification, deep AI, deepest AI
- Status values are stored as integers in the database

<!-- MANUAL: -->
