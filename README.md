# MESSI

**M**essaging, **E**valuation/**S**creening, **S**ystems & **I**ssues — Lightrees' internal
operations and AI workflow platform.

MESSI replaces manual follow-up. A leader defines once what needs asking; the system asks
on schedule, records the answer, notices who did not answer, and turns what the answer
produced into a tracked object.

```
Follow-up module → Cycle (the ask) → Answer → Commitment (action · when · who)
                                            └→ Outcome (approval · signature · meeting · issue · project)
```

The engine is generic — the product is the machine that builds follow-up questions, not any
one module. The launch modules are **MESSI** (messenger screening), **LESTARI** (leads status
reporting), **PRISTA** (project status) and **SHERINA** (schedule request and action); a
sugar mill asking about daily output is the same machinery with different questions.

An AI layer sits over the top, applied only where it produces measurable operational value —
most importantly on the *questions*, before anyone is ever asked.

This repository holds the **system design** for the platform, and a working build of its
first module.

## Running it

```bash
python3 web/tests/run_all.py   # 245 browser checks against the live page
pytest -q                      # 29 engine-rule tests, no browser or database
sh php/tests/run_all.sh        # 644 checks on the PHP + MySQL build
./run.sh                       # the FastAPI + Postgres build on :8000
```

The page people use is [`web/messi.html`](web/README.md) — mobile-first, one question per
screen. It opens on **FURA** (Follow Up Report Automation), a catalogue of modules; MESSI
is the first one that runs, and explains itself on a first visit. What each module asks —
its channels, thresholds, hours and the wording of every question — is a settings document
an admin edits from a page, not a constant in the code, because the same module is meant to
run for teams that count different things. [`php/`](php/README.md) is the same page with a PHP + MySQL back end, meant to be
uploaded to cPanel: it adds accounts, a database, the hourly job that opens each day and
chases what was promised, and a Google Chat webhook that posts the morning link. See
[RUNNING.md](RUNNING.md) for what works today and what does not.

## Design documents

| # | Document | What it answers |
|---|----------|-----------------|
| 01 | [Overview & Scope](docs/01-overview.md) | What we are building, for whom, what is explicitly out of scope |
| 02 | [Architecture](docs/02-architecture.md) | Components, boundaries, request/data flows, service topology |
| 03 | [Domain Model](docs/03-domain-model.md) | Entities, relationships, lifecycles, the screening→project→issue chain |
| 04 | [Data Model](docs/04-data-model.md) | PostgreSQL schema, indexes, tenancy columns, migrations |
| 05 | [API Design](docs/05-api-design.md) | REST surface, conventions, errors, pagination, idempotency |
| 06 | [AI Layer](docs/06-ai-layer.md) | AI task contracts, grounding, cost control, quality evaluation |
| 07 | [Events & Automation](docs/07-events-and-automation.md) | Outbox, job queue, rules engine, notifications |
| 08 | [Security & Tenancy](docs/08-security-and-tenancy.md) | AuthN/AuthZ, RBAC, isolation, data governance |
| 09 | [Deployment & Operations](docs/09-deployment-and-operations.md) | Docker, AWS topology, CI/CD, observability, runbooks |
| 10 | [Delivery Plan & KPIs](docs/10-delivery-plan.md) | Phase map, KPI scorecard, measurement mechanics, risk register |
| 11 | [Phase 1 — Internal MVP](docs/11-phase-1-mvp.md) | Engine, console, MESSI + LESTARI — the no-AI baseline phase |
| 12 | [Phase 2 — Automation & Intelligence](docs/12-phase-2-automation.md) | AI rollout discipline, messenger, SHERINA, scoring |
| 13 | [Phase 3 — Productization](docs/13-phase-3-productization.md) | Multi-tenancy, billing, operating-model changes |
| 14 | [The Follow-up Engine](docs/14-followup-engine.md) | Modules, enrolments, cycles, commitments, scoring — the core |
| 15 | [Modules & Outcomes](docs/15-modules-and-outcomes.md) | MESSI, LESTARI, PRISTA; approvals, signatures, SHERINA |
| 16 | [Question Design & Authoring](docs/16-question-design.md) | How to write questions that make people think; the linter and console |
| 17 | [Answer Analysis](docs/17-answer-analysis.md) | Progress, bottleneck attribution, themes, performance recap |

Architecture decisions with lasting consequences are recorded in [docs/adr](docs/adr).

## Design principles

1. **Deterministic software does deterministic work.** AI summarizes, classifies and
   extracts. It never owns state transitions, permissions or arithmetic.
2. **Structure is captured once.** A fact entered during screening is never re-typed to
   become a project, an issue or a report.
3. **Organization-aware from day one.** Every row carries an owning organization even
   though Phase 1 has exactly one. Multi-tenancy in Phase 3 is then a configuration
   change, not a migration.
4. **Measure the outcome, not the AI usage.** Every KPI on the proposal's scorecard has a
   named table, event or metric behind it before the feature ships.
5. **The system should ask less over time, not more.** Every follow-up mechanism has a
   path to a lower cadence — commitment-driven scheduling, auto-decay, archiving. A system
   that only ever adds questions gets ignored wholesale.
6. **Boring until proven otherwise.** One database, one deployable backend, one queue
   mechanism. Extraction into services happens when a measurement demands it.
