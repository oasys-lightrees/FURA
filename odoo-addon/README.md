# MESSI — Odoo addon

The follow-up engine as an Odoo module, so it lives beside the timesheets, projects and
users Lightrees already keeps in Odoo.

## Status

| Part | State |
|---|---|
| Engine rules | Moved to `core/messi_core/`, shared with the standalone app. 29 tests |
| `messi/models/` — Odoo models, cron, constraints | Written, syntax-checked, **not yet run against Odoo** |
| `messi/views/`, `security/`, `data/`, `demo/` | Written, XML validated, **not yet loaded** |

**Nothing here has been run inside Odoo yet.** Odoo could not be installed in the
development container: `nightly.odoo.com` and `github.com` are refused by the
organisation's egress policy (403 at the proxy). The pure-Python core was tested instead,
and the Odoo layer needs one pass on a real instance before it can be trusted.

## Running the tested part

```bash
pytest -q      # 29 passed, from the repo root
```

The rules live in `core/messi_core/` and have no Odoo imports on purpose: when a cycle
opens, which period it belongs to, when a promise counts as broken — readable and testable
on their own. The models here call into them rather than restating them, which means
`messi_core` must be importable by the Odoo server (pip-install it, or vendor it into the
addon at packaging time).

**The standalone app in `app/` is the live implementation** (see ../RUNNING.md). This addon
is the same engine for the day Odoo becomes the target again.

## Installing (on a real Odoo)

Requires Odoo **17.0** (see `__manifest__.py`; 18/19 need view-tag changes — `<tree>`
became `<list>` in 18).

```bash
cp -r odoo-addon/messi /path/to/odoo/addons/
odoo -d <db> -i messi --without-demo=False    # demo data carries the four launch modules
```

Then: **MESSI → Configuration → Modules**. The demo data installs PRISTA, MESSI, LESTARI
and NADI, written from the real Telegram reports of 25/09/2026.

## Layout

```
messi/
├── models/         Odoo models; call into lib/ rather than reimplementing rules
├── security/       groups (player, follow-up maker) and record rules
├── data/           ir.cron: generate, spawn-from-promise, reap
├── demo/           the four launch modules as data
└── tests/          pytest suite for lib/
```

## Three rules the code enforces, not just documents

- **A module with a commitment-driven cadence and no fallback cannot be saved.** Without
  it, a subject with no live promise is silently forgotten (ADR-0008).
- **A published version that has been answered cannot be edited.** Answers stay comparable
  across time, so the analysis in `docs/17` can group by version.
- **A `self` module takes no subject; every other kind requires one.** Checked in the
  model, not in the form.

## Known gaps before this is usable

1. Run `-i messi` on a real Odoo 17 and fix what the loader complains about.
2. Odoo integration tests (`TransactionCase`) for generation, submit and reap — the lib
   tests cover the rules, not the wiring.
3. Subject binding for PRISTA needs the `project` module in `depends`; LESTARI needs a
   decision between `res.partner` and `crm.lead`.
4. Question rendering: answers are edited as key/value rows today. The generated form per
   question type is the next real piece of work.
5. `messi/static/description/icon.png` referenced by the menu is not committed yet.
