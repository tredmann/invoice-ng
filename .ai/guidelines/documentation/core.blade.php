# Keeping the documentation true

This project's documentation lives in six places, and one change to the stack or to behaviour usually makes more than one of them wrong. **After any such change, work this list and correct what you falsified** — a stale doc is read as current and is worse than no doc.

- `README.md` — what the application does from a user's point of view, then the first-run sequence and the day-to-day command list. Update when a command, service, or setup step changes. **When a wave adds something a user can see, add it to "What it does today" and delete the matching line from "Not built yet".** That section is the only thing in this repository written for someone who wants to use the application rather than build it, so a feature missing from it is invisible to everyone who has not read the diff. Describe the behaviour and what it is for, not the classes that implement it.
- `CLAUDE.md` — the non-negotiable decisions and the container command block. Update when a decision is made or reversed; a new one is worth recording with the reason that forced it.
- `CONTEXT.md` — the domain's ubiquitous language: the German term for every concept, with the English identifier beside it. **Update it the moment a name is decided or changed**, in the same session, not afterwards — an entry there is what stops one concept from quietly acquiring a second name in the next wave, and what stops two concepts from sharing one. It is a glossary and nothing else: no implementation detail belongs in it.
- `docs/adr/` — decisions that are hard to reverse, surprising without context, **and** the result of a real trade-off. Numbered sequentially. If a decision fails any one of those three, do not write one: an easy decision will simply be reversed, an unsurprising one raises no questions, and one with no alternative records nothing.
- `docs/superpowers/specs/` — **the authority** on what the system does and what it is built from. When reality moves away from something a spec claims, correct the claim in place and say what changed, rather than leaving the old reasoning to mislead.
- `AGENTS.md` — **never edit this file directly.** It is generated, and `boost:install` silently replaces the whole block. Edit the matching file under `.ai/guidelines/` and regenerate:

```sh
docker compose run --rm app php artisan boost:install --guidelines --no-interaction
```

  Then diff `AGENTS.md` to confirm the change landed and nothing else moved. `docs/agents-md-maintenance.md` explains the trap in full.

All six live **in this repository**. There is no external wiki to keep in step: an Outline collection used to mirror the specs, and that obligation was dropped on 2026-09-26 because maintaining both cost more than it returned. Do not add a documentation location outside the repo without saying so here.

Dated plan and task documents record what was built on a given date. **Do not update them when the stack moves.** They are history, not current state.
