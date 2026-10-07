// Fake `claude` runtime. The store is kept in localStorage so it survives a reload and
// is shared between the two identities — the same way the real shared database behaves.
(function () {
  const KEY = "__fake_db__";
  const load = () => { try { return JSON.parse(localStorage.getItem(KEY)); } catch { return null; } };
  const save = s => { try { localStorage.setItem(KEY, JSON.stringify(s)); } catch {} };
  const empty = () => ({ cycles:{}, commitments:{}, roster:{} });
  const now = () => load() || window.__SEED__ || empty();
  // Reset only on the first load of a tab, so a reload genuinely tests persistence.
  const fresh = window.__RESET__ && !sessionStorage.getItem("__seeded__");
  if (fresh) { save(window.__SEED__ || empty());
               try { sessionStorage.setItem("__seeded__","1"); } catch {} }

  /* Simpanannya dibaca ulang tiap kali dipakai, bukan disalin sekali ke memori saat
     skrip ini jalan.

     Dua tab berbagi simpanan yang sama. Tab yang baru dibuka kadang belum melihat
     tulisan tab sebelah pada detik pertamanya — dan kalau isinya sudah terlanjur
     disalin ke memori saat itu, tulisan tab sebelah akan hilang tertimpa begitu tab ini
     menyimpan miliknya sendiri. Satu dari belasan kali, dan yang terlihat cuma "laporan
     rekannya tidak muncul". Database sungguhan tidak pernah kehilangan tulisan klien
     lain, jadi tiruannya pun tidak boleh. */
  Object.defineProperty(window, "__STORE__", { get: now, configurable: true });

  /* Dan tab yang baru dibuka menunggu simpanannya terlihat.
     Satu dari belasan kali, tulisan tab sebelah belum sampai ke tab ini pada detik
     pertamanya — bukan karena belum ditulis, tapi karena belum sempat terbit. Yang
     membedakan "belum ada isinya" dari "belum kelihatan" cuma waktu, jadi di sini
     ditunggu sebentar. Tab yang memang memulai dari nol sudah menulis simpanannya
     sendiri di atas, jadi dia tidak pernah ikut menunggu. */
  const terlihat = async () => {
    for (let i = 0; i < 60 && !load(); i++) {
      await new Promise(r => setTimeout(r, 50));
    }
    return now();
  };

  const PROFILES = { u_nicho:{name:"Nicho"}, u_lead:{name:"Lia"}, u_rio:{name:"Rio"} };
  window.claude = { use: async (n) => {
    if (n === "db") return { collection: (col) => ({
      get: async () => ({ docs: Object.entries((await terlihat())[col] || {})
        .map(([id, data]) => ({ id, data: () => data })) }),
      // Dibaca ulang tepat sebelum ditulis, supaya menulis satu dokumen tidak pernah
      // berarti menimpa seluruh isinya dengan salinan yang sudah basi.
      doc: (id) => ({ set: async (v) => {
        const s = now();
        (s[col] = s[col] || {})[id] = JSON.parse(JSON.stringify(v));
        save(s);
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
