-- MESSI standalone schema. One file for now; split into ordered migrations before the
-- first deployment that holds data anyone cares about.

CREATE TABLE IF NOT EXISTS organizations (
    id              BIGSERIAL PRIMARY KEY,
    slug            TEXT NOT NULL UNIQUE,
    name            TEXT NOT NULL,
    timezone        TEXT NOT NULL DEFAULT 'Asia/Jakarta',
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS users (
    id              BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    email           TEXT NOT NULL UNIQUE,
    display_name    TEXT NOT NULL,
    password_hash   TEXT NOT NULL,
    role            TEXT NOT NULL DEFAULT 'player'
                    CHECK (role IN ('player','leader','admin')),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS sessions (
    token           TEXT PRIMARY KEY,
    user_id         BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expires_at      TIMESTAMPTZ NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Subjects a follow-up can be about. Generic on purpose: a lead, a project, a machine.
CREATE TABLE IF NOT EXISTS subjects (
    id              BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    kind            TEXT NOT NULL,
    ref             TEXT,
    name            TEXT NOT NULL,
    stage           TEXT,
    attributes      JSONB NOT NULL DEFAULT '{}',
    archived_at     TIMESTAMPTZ,
    UNIQUE (organization_id, kind, ref)
);

CREATE TABLE IF NOT EXISTS modules (
    id              BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    key             TEXT NOT NULL,
    name            TEXT NOT NULL,
    description     TEXT,
    subject_kind    TEXT NOT NULL,
    status          TEXT NOT NULL DEFAULT 'active'
                    CHECK (status IN ('draft','active','paused','archived')),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (organization_id, key)
);

CREATE TABLE IF NOT EXISTS module_versions (
    id              BIGSERIAL PRIMARY KEY,
    module_id       BIGINT NOT NULL REFERENCES modules(id) ON DELETE CASCADE,
    version         INTEGER NOT NULL,
    questions       JSONB NOT NULL,
    cadence         JSONB NOT NULL,
    commitment_mapping JSONB,
    outcomes        TEXT[] NOT NULL DEFAULT '{}',
    published_at    TIMESTAMPTZ,
    UNIQUE (module_id, version)
);

CREATE TABLE IF NOT EXISTS enrolments (
    id              BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    module_id       BIGINT NOT NULL REFERENCES modules(id) ON DELETE CASCADE,
    module_version_id BIGINT NOT NULL REFERENCES module_versions(id),
    player_id       BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    subject_id      BIGINT REFERENCES subjects(id) ON DELETE CASCADE,
    cadence         JSONB,
    tz              TEXT NOT NULL DEFAULT 'Asia/Jakarta',
    paused_until    DATE,
    no_change_streak SMALLINT NOT NULL DEFAULT 0,
    archived_at     TIMESTAMPTZ
);
-- COALESCE is load-bearing: without it a player could be enrolled in a 'self' module
-- any number of times, because NULL never equals NULL.
CREATE UNIQUE INDEX IF NOT EXISTS uq_enrolment
    ON enrolments (module_id, player_id, COALESCE(subject_id, 0))
    WHERE archived_at IS NULL;

CREATE TABLE IF NOT EXISTS cycles (
    id              BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    enrolment_id    BIGINT NOT NULL REFERENCES enrolments(id) ON DELETE CASCADE,
    module_version_id BIGINT NOT NULL REFERENCES module_versions(id),
    period_key      TEXT NOT NULL,
    opens_at        TIMESTAMPTZ NOT NULL,
    due_at          TIMESTAMPTZ NOT NULL,
    status          TEXT NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','submitted','late','missed','skipped')),
    submitted_at    TIMESTAMPTZ,
    no_change       BOOLEAN NOT NULL DEFAULT false,
    skip_reason     TEXT,
    triggered_by_commitment_id BIGINT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (enrolment_id, period_key)
);
CREATE INDEX IF NOT EXISTS ix_cycles_pending ON cycles (status, due_at)
    WHERE status = 'pending';

CREATE TABLE IF NOT EXISTS answers (
    id              BIGSERIAL PRIMARY KEY,
    cycle_id        BIGINT NOT NULL REFERENCES cycles(id) ON DELETE CASCADE,
    question_key    TEXT NOT NULL,
    value_text      TEXT,
    value_number    NUMERIC,
    origin          TEXT NOT NULL DEFAULT 'human'
                    CHECK (origin IN ('human','integration','ai_extracted')),
    UNIQUE (cycle_id, question_key)
);

CREATE TABLE IF NOT EXISTS commitments (
    id              BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    cycle_id        BIGINT NOT NULL REFERENCES cycles(id) ON DELETE CASCADE,
    enrolment_id    BIGINT NOT NULL REFERENCES enrolments(id) ON DELETE CASCADE,
    action_text     TEXT NOT NULL,
    due_at          TIMESTAMPTZ NOT NULL,
    owner_id        BIGINT NOT NULL REFERENCES users(id),
    status          TEXT NOT NULL DEFAULT 'open'
                    CHECK (status IN ('open','kept','broken','cancelled','superseded')),
    resolved_at     TIMESTAMPTZ,
    resolved_cycle_id BIGINT REFERENCES cycles(id) ON DELETE SET NULL,
    spawned_cycle_id  BIGINT REFERENCES cycles(id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ix_commitments_open ON commitments (status, due_at)
    WHERE status = 'open';

CREATE TABLE IF NOT EXISTS events (
    id              BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL,
    event_type      TEXT NOT NULL,
    entity_type     TEXT NOT NULL,
    entity_id       BIGINT NOT NULL,
    actor_id        BIGINT,
    payload         JSONB NOT NULL DEFAULT '{}',
    occurred_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ix_events_type ON events (organization_id, event_type, occurred_at DESC);
