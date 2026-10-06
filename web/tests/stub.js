// Fake `claude` runtime. The store is kept in localStorage so it survives a reload and
// is shared between the two identities — the same way the real shared database behaves.
(function () {
  const KEY = "__fake_db__";
  const load = () => { try { return JSON.parse(localStorage.getItem(KEY)); } catch { return null; } };
  const save = s => { try { localStorage.setItem(KEY, JSON.stringify(s)); } catch {} };
  const empty = { cycles:{}, commitments:{}, roster:{} };
  // Reset only on the first load of a tab, so a reload genuinely tests persistence.
  const fresh = window.__RESET__ && !sessionStorage.getItem("__seeded__");
  if (fresh) { window.__STORE__ = window.__SEED__ || empty; save(window.__STORE__);
               try { sessionStorage.setItem("__seeded__","1"); } catch {} }
  else { window.__STORE__ = load() || window.__SEED__ || empty; }

  const PROFILES = { u_nicho:{name:"Nicho"}, u_lead:{name:"Lia"}, u_rio:{name:"Rio"} };
  window.claude = { use: async (n) => {
    if (n === "db") return { collection: (col) => ({
      get: async () => ({ docs: Object.entries(window.__STORE__[col] || {})
        .map(([id, data]) => ({ id, data: () => data })) }),
      doc: (id) => ({ set: async (v) => {
        (window.__STORE__[col] = window.__STORE__[col] || {})[id] = JSON.parse(JSON.stringify(v));
        save(window.__STORE__);
      }}),
    })};
    if (n === "user") return {
      id: async () => window.__WHO__,
      me: async () => ({ name: (PROFILES[window.__WHO__]||{}).name || "" }),
      isOwner: () => window.__WHO__ === "u_lead",
      profiles: async (ids) => Object.fromEntries(ids.map(i => [i, PROFILES[i] || {name:""}])),
    };
    return null;
  }};
})();
