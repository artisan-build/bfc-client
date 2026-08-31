# Workflow — bfc-client

Project profile for the `multi-agent-build` skill and any agent working in this repo. The coordinator
reads this FIRST. Greenfield package; keep truthful as it evolves.

## What this is

**bfc-client** is the Built for Cloud **client-side** package — a lean library carried by every BfC client
application. When Scalpels (or any provider) asks, it reports a **stable client identity** and **proof of
life** so the provider can attribute a token to a specific client and report honest status, **without the
control plane reading the customer's environment variables via the Forge/Cloud API**. It is the client half
of the Track-B mechanics; the server half (per-token client-identity storage + a `NoCredential` observation)
lives in `artisan-build/built-for-cloud` (Solo proj 21). Design context:
`~/Herd/brain/projects/scalpels_app/durable-records-plan.md` (v2) + the Track-B PRD in the coordinator brief.

## Phase & mode
- phase: **greenfield** (only a LICENSE exists; PR1 scaffolds the package).
- default mode: **A-autonomous** — merge each PR when CI is green; no human PR review unless asked.
- merge method: `gh pr merge --squash --delete-branch`; confirm the merge landed before the next dependent PR.

## Hard gate (verified on the committed SHA, clean tree)
- once scaffolded: `composer stan` (phpstan/larastan, memory 512M) AND `composer lint:test` (pint --test)
  AND `composer test` (pest). No composite `composer ready` in a fresh package unless PR1 adds one.
- **PR1 establishes the scaffold AND the gate**: composer.json (Laravel package, model it on
  `~/Herd/built-for-cloud/composer.json` — PHP ^8.3, illuminate ^13 contracts/support/routing as needed,
  larastan ^3.9, pint ^1.27, orchestra/testbench ^11, pestphp/pest ^4), PSR-4 `ArtisanBuild\BfcClient\`,
  a ServiceProvider, `.github/workflows/tests.yml` (model it on built-for-cloud's: PHP 8.4, stan → pest;
  a lint workflow), and `.solo/` gitignored. Verify CI actually runs+greens on PR1 before Mode-A gating on it.

## CI (the merge gate for Mode A)
- status: **to be established by PR1** (greenfield). Minimum bar: testing (pest) + static analysis (phpstan),
  matching the fleet. Do NOT Mode-A-merge later PRs until PR1's CI is verified green on a real run.

## Harness map (role → runtime)

**Resolve every role through `~/Herd/brain/playbooks/resolve-agent-role.md` against
`~/Herd/brain/agents.json`.** That file is the fleet-wide authority; this profile does not restate it.

The copied runtime bindings, hardcoded Solo `agent_tool_id`s, and obsolete claims that OpenCode and
Codex could not run under Solo's PTY were removed on 2026-08-31. Fleet bindings change, ids drift when
tools are re-added, and both runtimes have since run successfully under Solo.

- Spawn every harness in the intended worktree: **opencode** takes the worktree path as its last
  positional argument, **codex** takes `-C <path>`, and **claude** takes `--add-dir <path>`.
- When invoking `codex exec` in a Bash call, redirect stdin with `</dev/null`. Without it, a
  backgrounded process appends stdin to the prompt and hangs on a pipe that never closes.
- Spawn the acceptance judge fresh per PR and keep it blind to the reviewer.
- The judge and reviewer must NOT run concurrently in the same worktree. The judge's conformance pass
  can run rewriting commands such as Rector or Pint, causing the reviewer to inspect a moving tree.
- ⚠️ Coordinators on this machine PARK after subagent phases (Solo self-timer glitch). POLL subagents
  directly every ~2-3 min; do NOT rely on self-timers. The brain orchestrator also externally monitors.

## Toolchain conformance — ride-along rule (STANDING, all projects)
Run the project's conformance command (`composer lint`, or equivalent) when finalizing every PR and let its
changes ride along as their own commit titled `composer lint`. Scope-discipline language never suspends it.

## Ship details
- branch naming: `feat/<slug>`.
- PR target: `artisan-build/bfc-client` (branch `main`).
- release: not yet — no tag until the API stabilizes and Ed OKs a version (packagist later).
