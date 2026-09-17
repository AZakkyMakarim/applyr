# Applyr

An automated assistant that finds job postings matching the user's search criteria, generates tailored application documents for them with AI, and tracks the user through reviewing and applying.

## Language

**SearchProfile**:
A named, independently-toggleable saved set of search parameters (keywords, free-text location + country code, min/max experience years, bucketed post-date range, work arrangement, job type) the user wants matched against job boards. A user may have several active at once; exactly one Application exists per Job regardless of how many SearchProfiles matched it. SearchProfile is canonical/generic — each Adapter best-effort-translates its fields into that platform's own native filters, approximating or ignoring what it can't express (e.g. a platform with no experience-year filter, or a country an Adapter doesn't support yet) rather than SearchProfile carrying platform-specific variants. Carries no run/poll state (last-run time, dedup cursor) — that belongs to the scheduling mechanism, not the profile itself.
_Avoid_: Search filter, query

**Adapter**:
A platform-specific component that fetches job postings from one job board (e.g. Glints, JobStreet) and normalizes them into Jobs. May use a platform's internal JSON API or fall back to parsing HTML.
_Avoid_: Scraper (too narrow — an Adapter may not scrape at all), Connector

**Job**:
A single job posting, normalized to one common shape regardless of which Adapter produced it. Uniquely identified by the composite key `platform` + `external_id` (the platform's own posting id), enforced as a unique index — this is the dedup key checked before a Job enters AI tailoring. Core fields (title, company, location, url, description, work arrangement, job type, salary, posted date, status, experience-year bounds) are harmonized the same way from every Adapter; anything platform-specific stays in a raw JSON blob rather than being promoted to a column until actually needed. Upserted in place on re-poll rather than treated as immutable — this is how `status` (open/closed/expired) becomes observable over time — though fields an Application/TailoredApplication already consumed are never retroactively overwritten by a later refresh. Where an Adapter's search serves only open Jobs, an open Job a poll's searches no longer return is fetched by id instead, and one the platform no longer has becomes closed; each poll fetches only a capped number of these, longest-unrefreshed first, and a posting the platform answers with an error or unexpected shape is skipped rather than failing the poll, unless every one of several such fetches in that poll fails. A new posting the platform no longer has by the time its description is fetched is skipped, not stored, so it never enters tailoring; storing it closed would let a later search that still returns it reopen a Job with no Application. May be matched by more than one SearchProfile (tracked via a Job-SearchProfile pivot, informational only) while still surfacing exactly one Application.
_Avoid_: Posting, Listing, Vacancy

**MasterProfile**:
The user's own ground-truth personal/contact info (name, email, phone, location, links, professional summary, an optional profile photo), work history, skills, education, and projects. The single source of truth that a TailoredApplication's facts (e.g. experience title/company/period) must match exactly. A single mutable record (not versioned) edited via a dashboard form; identifying/structural fields on each entry are immutable facts, only free-text description/achievement fields are AI-reframeable.
_Avoid_: Resume, Profile (too generic — collides with unrelated "profile" concepts)

**TailoredApplication**:
The AI-generated, fact-validated CV and cover letter content — plus its two rendered PDFs (`cv.pdf`, `cover_letter.pdf`; always rendered separately, never combined) — produced for one Job. Its stored JSON (`cv_data`, `cover_letter_data`) is a full self-contained snapshot: copied facts plus reframed description/achievements per entry, keyed by the MasterProfile entry's id, so rendering never needs to re-join live MasterProfile state and the snapshot itself is the audit trail. Reframes descriptions and achievements from the MasterProfile without altering its title/company/period facts; achievements may be curated down to a subset but never invented beyond MasterProfile's own list (curating down to none is allowed). An entry the AI leaves out keeps its MasterProfile text; one returned with a blank description keeps its MasterProfile description. A generation attempt whose response is unreadable or fails fact validation is regenerated and discarded, not kept as a TailoredApplication; only a fact-valid result becomes one.
_Avoid_: Generated CV, Draft

**Application**:
The tracked record of the user's intent to apply to a Job. Exactly one Application exists per Job, regardless of how many SearchProfiles matched it. Carries a single `status` (`pending_tailoring` | `tailoring_failed` | `needs_review` | `applied` | `rejected`) through the dashboard and references its current TailoredApplication, which is retained across a regenerate until replaced. No separate "ready" state — the dashboard applies directly from `needs_review`. `rejected` covers self-drops at any stage (no separate "withdrawn"); terminal statuses can be undone back to their prior status.
_Avoid_: Lamaran, Job Application
