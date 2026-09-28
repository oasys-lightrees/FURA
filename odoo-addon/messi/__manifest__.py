{
    "name": "MESSI — Follow-up Engine",
    "summary": "Define a follow-up once; the system asks, records, and chases.",
    "description": """
MESSI is a follow-up engine, not a report form. A leader defines a module once —
its questions, its subject, how often it asks, and what an answer can become — and
the system asks on schedule, records the answer, notices who did not answer, and
turns the promise inside the answer into something it will chase.

Launch modules: MESSI (messenger screening), PRISTA (project issue status),
LESTARI (leads status reporting), NADI (night debrief).

Design: see docs/14-followup-engine.md and docs/16-question-design.md in the repo.
""",
    "author": "Lightrees",
    "website": "https://lightrees.com",
    "category": "Productivity",
    "version": "17.0.0.1.0",
    "license": "LGPL-3",
    "depends": ["base", "mail"],
    "data": [
        "security/messi_groups.xml",
        "security/ir.model.access.csv",
        "views/messi_menus.xml",
        "views/messi_module_views.xml",
        "views/messi_cycle_views.xml",
        "views/messi_commitment_views.xml",
        "data/ir_cron.xml",
    ],
    "demo": ["demo/messi_demo_modules.xml"],
    "application": True,
    "installable": True,
}
