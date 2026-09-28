"""Seed data: one squad, and the four modules written from the real Telegram reports
of 25/09/2026 (PRISTA, MESSI, LESTARI, NADI).

NADI is the interesting one. Of its ~30 original questions, everything the system can
compute was dropped — "sudah lapor PRISTA?", "total project berapa?", "pagi hanging
berapa?" are a dashboard, not questions. What is left is the judgement, which is what
was being answered with "belum selesai" because it sat under twenty lines of bookkeeping.
"""

from __future__ import annotations

import json

from auth import hash_password

WEEK = ["mon", "tue", "wed", "thu", "fri"]

PRISTA_QUESTIONS = [
    {"key": "latest_update", "type": "long_text", "required": True,
     "label": "Latest Update — apa yang berubah sejak update terakhir?"},
    {"key": "next_action", "type": "long_text", "required": True,
     "label": "Next Action — langkah berikutnya apa?"},
    {"key": "next_due", "type": "date", "required": True,
     "label": "Due Date — kapan?", "help": "Tanggal ini jadi janji. Sistem akan menanyakannya pada hari itu."},
    {"key": "hanging_reason", "type": "select", "required": False,
     "label": "Kalau belum bergerak, kenapa?",
     "options": ["menunggu_approval", "menunggu_pihak_luar", "menunggu_dependency",
                 "kurang_waktu", "kurang_info", "teknis", "lainnya"]},
]

MESSI_QUESTIONS = [
    {"key": "total_open", "type": "number", "required": True,
     "label": "Total messenger aktif/open (WAG + TGG + GCG)"},
    {"key": "hanging_gt3", "type": "number", "required": True,
     "label": "Gantung >3 hari"},
    {"key": "hanging_lt3", "type": "number", "required": True,
     "label": "Gantung <3 hari"},
    {"key": "replied", "type": "number", "required": True,
     "label": "Sudah dibalas hari ini"},
    {"key": "unresolved_detail", "type": "long_text", "required": False,
     "label": "Yang masih gantung — mana saja, dan mau dijadikan apa?",
     "help": "Approval, signature, jadwal meeting, task, atau project."},
    {"key": "escalation", "type": "long_text", "required": False,
     "label": "Kendala/insight yang perlu dieskalasi"},
    {"key": "next_action", "type": "long_text", "required": False,
     "label": "Next action untuk yang masih gantung"},
    {"key": "next_due", "type": "date", "required": False, "label": "Kapan?"},
]

LESTARI_QUESTIONS = [
    {"key": "latest_update", "type": "long_text", "required": True,
     "label": "Terakhir gimana?"},
    {"key": "stage", "type": "select", "required": True, "label": "Sekarang di tahap mana?",
     "options": ["contacted", "qualified", "proposal", "negotiation", "won", "lost"]},
    {"key": "next_action", "type": "long_text", "required": True,
     "label": "Next action apa?"},
    {"key": "next_due", "type": "date", "required": True, "label": "Kapan?"},
    {"key": "stall_reason", "type": "select", "required": False,
     "label": "Kalau belum bergerak, kenapa?",
     "options": ["menunggu_approval_internal", "customer_diam", "harga",
                 "butuh_spek", "budget_klien", "lainnya"]},
]

NADI_QUESTIONS = [
    {"key": "biggest_worry", "type": "long_text", "required": True,
     "label": "Dari yang masih gantung, mana yang paling mengkhawatirkan dan kenapa?",
     "help": "Angka hari ini sudah ada di dashboard — tidak perlu diketik ulang."},
    {"key": "root_fix", "type": "long_text", "required": True,
     "label": "Root fix — apa yang harus berubah supaya ini tidak terulang?",
     "help": "Fruit fix-nya sudah tercatat sebagai janji di PRISTA. Di sini yang sistemik."},
    {"key": "next_action", "type": "long_text", "required": True,
     "label": "Besok fokus ke apa?"},
    {"key": "next_due", "type": "date", "required": False, "label": "Target selesai kapan?"},
]

MAPPING = {"action": "next_action", "due": "next_due"}

MODULES = [
    ("PRISTA", "Project Issue Status", "project", PRISTA_QUESTIONS,
     {"kind": "on_commitment",
      "fallback": {"kind": "weekly", "weekday": "mon", "at": "09:00", "due_after": "P1D"}},
     MAPPING,
     "Satu cycle per project per PIC. Ditanya pada tanggal yang dijanjikan; "
     "kalau tidak ada janji terbuka, seminggu sekali."),
    ("MESSI", "Messenger Screening", "self", MESSI_QUESTIONS,
     {"kind": "daily", "days": WEEK, "at": "09:00", "due_after": "PT9H"},
     MAPPING, "Sapuan harian channel. Yang gantung wajib jadi objek yang dilacak."),
    ("LESTARI", "Leads Status Reporting", "lead", LESTARI_QUESTIONS,
     {"kind": "on_commitment",
      "fallback": {"kind": "weekly", "weekday": "mon", "at": "09:00", "due_after": "P1D"}},
     MAPPING, "Cadence mengikuti janji: ditanya pada tanggal yang dia sebut sendiri."),
    ("NADI", "Night Debrief", "self", NADI_QUESTIONS,
     {"kind": "daily", "days": WEEK, "at": "16:30", "due_after": "PT5H"},
     MAPPING, "Versi ramping: pertanyaan yang jawabannya sudah ada di sistem dihapus."),
]

PROJECTS = [
    ("OA001", "Onboarding App"), ("OA002", "HaloAI"), ("OA003", "PIWA Website"),
    ("OA004", "Testing Website Onboarding"), ("OA005", "Bikin Checkout Page"),
    ("OA006", "Upload File Landing Page Fitand"), ("OA007", "Fitur Website Presensi"),
]

LEADS = [("L001", "PT Anu"), ("L002", "CV Sejahtera"), ("L003", "Ibu Ratna")]


def seed(conn) -> dict:
    org = conn.execute(
        """INSERT INTO organizations (slug, name) VALUES ('oasys','OASYS Squad')
           ON CONFLICT (slug) DO UPDATE SET name = EXCLUDED.name RETURNING id"""
    ).fetchone()["id"]

    users = {}
    for email, name, role in [
        ("nicho@lightrees.com", "Nicho", "player"),
        ("chief@lightrees.com", "Chief", "leader"),
    ]:
        users[email] = conn.execute(
            """INSERT INTO users (organization_id, email, display_name, password_hash, role)
               VALUES (%s,%s,%s,%s,%s)
               ON CONFLICT (email) DO UPDATE SET display_name = EXCLUDED.display_name
               RETURNING id""",
            (org, email, name, hash_password("messi"), role),
        ).fetchone()["id"]

    subjects = {}
    for kind, items in (("project", PROJECTS), ("lead", LEADS)):
        for ref, name in items:
            subjects[(kind, ref)] = conn.execute(
                """INSERT INTO subjects (organization_id, kind, ref, name)
                   VALUES (%s,%s,%s,%s)
                   ON CONFLICT (organization_id, kind, ref)
                   DO UPDATE SET name = EXCLUDED.name RETURNING id""",
                (org, kind, ref, name),
            ).fetchone()["id"]

    versions = {}
    for key, name, subject_kind, questions, cadence, mapping, desc in MODULES:
        module_id = conn.execute(
            """INSERT INTO modules (organization_id, key, name, description, subject_kind)
               VALUES (%s,%s,%s,%s,%s)
               ON CONFLICT (organization_id, key)
               DO UPDATE SET name = EXCLUDED.name RETURNING id""",
            (org, key, name, desc, subject_kind),
        ).fetchone()["id"]
        versions[key] = conn.execute(
            """INSERT INTO module_versions (module_id, version, questions, cadence,
                                            commitment_mapping, published_at)
               VALUES (%s, 1, %s, %s, %s, now())
               ON CONFLICT (module_id, version)
               DO UPDATE SET questions = EXCLUDED.questions, cadence = EXCLUDED.cadence
               RETURNING id, module_id""",
            (module_id, json.dumps(questions), json.dumps(cadence), json.dumps(mapping)),
        ).fetchone()

    def enrol(module_key, player, subject_id=None):
        v = versions[module_key]
        conn.execute(
            """INSERT INTO enrolments (organization_id, module_id, module_version_id,
                                       player_id, subject_id)
               VALUES (%s,%s,%s,%s,%s) ON CONFLICT DO NOTHING""",
            (org, v["module_id"], v["id"], player, subject_id),
        )

    nicho = users["nicho@lightrees.com"]
    enrol("MESSI", nicho)
    enrol("NADI", nicho)
    for ref, _ in PROJECTS:
        enrol("PRISTA", nicho, subjects[("project", ref)])
    for ref, _ in LEADS:
        enrol("LESTARI", nicho, subjects[("lead", ref)])

    conn.commit()
    counts = {
        "modules": len(MODULES),
        "subjects": len(subjects),
        "enrolments": conn.execute("SELECT count(*) AS n FROM enrolments").fetchone()["n"],
    }
    return counts
