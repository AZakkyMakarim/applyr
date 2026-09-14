# Issue tracker: Beads (bd)

Issues for this repo live in a local Beads database (Dolt), managed via the `bd` CLI — not GitHub Issues. Sync is local-first via `bd dolt push/pull`, separate from `git push`/`git pull` of code.

## Conventions

- Create issues with `bd create --title="..." --description="..." --type=task|bug|feature --priority=0-4` (0=critical, 4=backlog)
- Hierarchical work: `bd create ... --parent=<id>` for a subtask/child under an epic or tracked issue
- Find work: `bd ready` (unblocked, open), `bd list --status=open|in_progress`, `bd show <id>`
- Claim before starting: `bd update <id> --claim`
- Close when done: `bd close <id> --reason="..."` (multiple ids at once is fine)
- Dependencies: `bd dep add <issue> <depends-on>`; see blockers with `bd blocked`
- Do NOT use `bd edit` — it opens `$EDITOR` and blocks agents. Update fields inline with `bd update <id> --title/--description/--notes/--design`
- `.beads/issues.jsonl` is a passive export, NOT the source of truth — never hand-edit it or rely on it for sync

## When a skill says "publish to the issue tracker"

Run `bd create --title="..." --description="..." --type=... --priority=...`. Use `--parent=<id>` when the new issue is a child of an epic/spec already tracked in Beads.

## When a skill says "fetch the relevant ticket"

Run `bd show <id>`. The user will normally pass the bd issue id directly.

## PRs as a request surface

Off. GitHub PRs are not treated as an issue-tracker request surface in this repo — all work items go through `bd`.

## Sync

`bd dolt push` / `bd dolt pull` sync the Beads Dolt DB via `refs/dolt/data` on the `origin` remote — separate from normal `git push`/`git pull` on `refs/heads/*`.
