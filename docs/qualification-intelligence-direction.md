# Zambia Qualification Intelligence: direction and how it maps onto this codebase

## The one rule that shapes the schema

Four facts about a qualification are stored separately, each with its own source and verification date:

| Fact | Question it answers | Where it lives |
|---|---|---|
| Existence | Is there such a qualification, who awards it? | `qualifications` + `organizations` |
| Recognition | Is it accredited or recognised in Zambia, by whom? | `recognitions` (one row per authority claim) |
| Availability | Where can it be studied, how, how long? | `programmes` (provider + qualification) |
| Demand | Do employers ask for it? | `job_qualifications` (already built, Phase 7) |

An advertisement only ever proves *demand*. It never writes to the first three. Extraction may create a `candidate` qualification (unverified) that a person reviews; it never sets recognition.

## What already exists (Phase 6 and Phase 7 steps 1 and 2)

- Taxonomy tables: `academic_awards`, `fields_of_study` (hierarchical), `professional_qualifications`, `professional_registrations`, `occupations` (ISCO-08), `industries` (ISIC), `skills`, `seniority_levels`, each with `aliases` and `origin` (seed or person).
- `job_qualifications` with requirement level, either-or groups, confidence, evidence, review and removal flags, pointing at the taxonomy.
- A legacy flat `qualifications` table (name, level, aliases) that nothing new should depend on.
- Collection, source register, standardization, duplicates, the Vacancies and Qualifications pages. This is the **Market Intelligence** pillar and the **Data collection** layer, largely done.

## What is missing (the Qualification Directory pillar)

Proposed tables, in build order:

1. `organizations`: type (`awarding_body`, `professional_body`, `university`, `college`, `regulator`, `exam_board`), country, website, aliases. Replaces the free-text `body` columns.
2. `qualification_levels`: the Zambian Qualifications Framework levels (ZAQA) plus an `international` marker for foreign awards, mapped to the existing `education_level` values.
3. `directory_qualifications` (new; the legacy flat `qualifications` table is left untouched, because `job_qualifications.qualification_id` still points at it): `kind` (academic, professional, vocational/trade, short_course, registration), name, level_id, field_id, awarding_organization_id, typical_duration_months and entry_requirements (both nullable, only set when verified), `status` (`unverified`, `verified`, `retired`), aliases.
4. `qualification_relationships`: from, to, type (`progression`, `equivalent`, `prerequisite`, `exemption`).
5. `recognitions`: qualification, authority organization, status (`accredited`, `registered`, `recognised`, `not_recognised`, `unknown`), scope, reference number, valid from and to.
6. `programmes`: provider organization, qualification, mode, duration, campus or location, accreditation status of the programme itself.
7. `evidence`: polymorphic claim table. Every verified field above points at one or more rows: source URL or document, retrieved date, short note, who verified, `verified_at`. "Last verification date" on any page is read from here.

The three existing qualification taxonomy tables are folded into `directory_qualifications` by `kind` (their rows are copied with `origin` kept, nothing dropped), and `job_qualifications` gains `directory_qualification_id` while the old foreign keys stay until a later cleanup.

## Sources

The existing `sources` register is for job ads. Directory sources (university sites, ZAQA, HEA, TEVETA, ECZ, EIZ, ZICA, HPCZ, LAZ, ACCA and CIMA listings) get a `purpose` of `directory` or `recognition`, and the same approved and active rules apply: only approved sources are read, and a person reviews before anything is marked `verified`.

## Suggested build order

1. Directory schema (items 1 to 7) and the folding of existing taxonomy rows. No UI.
2. Seed core records by hand or CSV import (the examples in the brief: BSc Computer Science, Diploma in Registered Nursing, ACCA, CIMA, CCNA, trade certificates), with evidence rows.
3. Review screens: candidates found by extraction, recognition entry, evidence entry.
4. Public-style pages: qualification profile (existence, recognition, where to study, demand, trend), search, comparison.
5. Finish Phase 7 extraction against the new table; trend reporting by month.
6. Education pathways and reports.

## Open decisions

- Fold the three taxonomy tables into one `qualifications` table (recommended) or keep them and add the directory beside them.
- Whether the platform stays private and single-user (today's README) or gets a public side, which changes authentication, caching and the "not indexed by search engines" rule.
- How recognition is verified: manual entry with a document or link only (recommended to start), or collected from regulator registers.

## Status

Built: items 1 to 7, the fold (`DirectoryFold`, also run by `php artisan taxonomy:install`), and `sources.purpose`. Folded rows are `unverified`. Tested on SQLite only; run `php artisan migrate` on MariaDB to confirm there.

## Domain D (employment-market intelligence) and Domain E (classification and relationships)

**Domain D: ads as evidence of demand.** The storage is built (Phase 7 step 2): `job_qualifications`, `job_skills` and `job_experiences` carry requirement level (required, preferred, advantage, mentioned), either-or groups, confidence, the exact evidence wording, and review flags; `jobs` carries occupation, seniority, employer, location and industry. Not built: the extractor that fills them from the ad body (today the reader sets one qualification level and one experience figure per job).

**Domain E: matching and relationships.**
- Matching exists as `TaxonomyIndex` (whole-word, longest-phrase, alias-based). It reads the three old taxonomy tables, not the directory, and it only recognises named awards (BEng, ACCA). It does not yet recognise "Degree in Accounting and Finance" or "First Degree in Accounting" as level plus field.
- Exact wording is already preserved: `evidence` holds the ad's words and `candidate_text` holds wording the taxonomy does not know, never forced into the nearest entry.
- Relationships built: qualification to field, level, awarding body (`directory_qualifications`), qualification to provider (`programmes`), qualification to qualification (`qualification_relationships`).
- Relationships missing: qualification to occupations, qualification to skills, qualification to industry demand. Occupation and skill links should be derived from observed ads (counted, with a minimum sample) and kept apart from any curated link, so "employers asking for this also ask for that" is never presented as "this qualification leads to that job".

## Proposed next steps

1. Extractor (Phase 7 step 3): read qualifications, skills and experience from the ad body into the Phase 7 tables, matching against the directory, recording level and field separately when no named award is given.
2. Observed links: built as one table, `qualification_link_stats` (link_type occupation, skill or industry), rebuilt daily by `stats:rebuild`. It stores the sample size beside each count; `LinkStatsBuilder::MIN_SAMPLE` (5) is the floor for showing a share. Industry is empty until jobs or employers are given an industry.
3. Point `TaxonomyIndex` at the directory.
