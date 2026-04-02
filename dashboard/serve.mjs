#!/usr/bin/env node
/**
 * Локальный сервер дашборда без PHP (Node.js 18+).
 * Читает креды из config.php (regex) или AIRTABLE_PAT / AIRTABLE_BASE_ID.
 */
import http from "node:http";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const HOST = process.env.HOST || "127.0.0.1";
/** С LAUNCH.command обычно 9876; 8080 — запасной дефолт при ручном `node serve.mjs`. */
const PORT = Number(process.env.PORT || "9876", 10);

/** Точное имя таблицы в Airtable (если нет airtable_dz_table_id в config — ищем ID через Meta API). */
const DZ_TABLE_NAME = "🔸Debt (15,30,60,90)(Демидова) copy";
const FILTER =
  "OR({Статус оплаты}='Не оплачен',{Статус оплаты}='Оплачен частично')";
const AGING_KEYS = { "0–30": true, "31–60": true, "61–90": true, "90+": true };

const CS_ALL_TABLE_NAME = "CS ALL";
const CHURN_TABLE_NAME = "❤️ CHURN Prediction ☠️";
const CS_ALL_LABEL_KEYS = [
  "ЮЛ клиента",
  "ЮЛ",
  "Account",
  "Клиент",
  "Client",
  "Название",
  "Name",
  "Company",
  "Site ID",
  "CS",
  "Аккаунт",
  "Account name",
];

const SNAPSHOTS_DIR = path.join(__dirname, "snapshots");
const MANIFEST_PATH = path.join(SNAPSHOTS_DIR, "manifest.json");
const CACHE_DIR = path.join(__dirname, "cache");

/** @returns {[string, string]} prevWed, thisWed Y-m-d (Europe/Moscow), как в дашборде руководителя */
function moscowWeekRangeNow() {
  const now = new Date(
    new Date().toLocaleString("en-US", { timeZone: "Europe/Moscow" })
  );
  const dow = now.getDay();
  const daysBack = dow === 0 ? 4 : dow >= 3 ? dow - 3 : dow + 4;
  const thisWed = new Date(now);
  thisWed.setDate(now.getDate() - daysBack);
  const prevWed = new Date(thisWed);
  prevWed.setDate(thisWed.getDate() - 7);
  return [prevWed.toISOString().slice(0, 10), thisWed.toISOString().slice(0, 10)];
}

function moscowYearMonth() {
  return new Date()
    .toLocaleDateString("en-CA", { timeZone: "Europe/Moscow" })
    .slice(0, 7);
}

/** @param {string} slug */
function resolveMrrCacheNode(slug, fresh) {
  const safe = String(slug).replace(/[^a-zA-Z0-9_-]/g, "") || "default";
  const ym = moscowYearMonth();
  const p = path.join(CACHE_DIR, `mrr-${safe}.json`);
  try {
    if (fs.existsSync(p)) {
      const j = JSON.parse(fs.readFileSync(p, "utf8"));
      if (j && j.yearMonth === ym) {
        return {
          value: Number(j.value) || 0,
          yearMonth: j.yearMonth,
          updatedAt: j.updatedAt || "",
          note:
            "Значение MRR за " +
            ym +
            " (фиксируется на весь месяц, Node serve — тот же кэш, что и PHP).",
        };
      }
    }
  } catch {
    /* fresh write */
  }
  const out = {
    value: Math.round(fresh * 100) / 100,
    yearMonth: ym,
    updatedAt: new Date().toISOString(),
    note: "MRR обновлён для нового месяца (" + ym + ").",
  };
  try {
    if (!fs.existsSync(CACHE_DIR)) fs.mkdirSync(CACHE_DIR, { recursive: true });
    fs.writeFileSync(p, JSON.stringify(out, null, 2), "utf8");
  } catch {
    /* ignore */
  }
  return out;
}

/** @param {string} slug */
function recordWeeklyDebtNode(slug, totalDebt, overdueDebt) {
  const safe = String(slug).replace(/[^a-zA-Z0-9_-]/g, "") || "default";
  const [prevWed, thisWed] = moscowWeekRangeNow();
  const p = path.join(CACHE_DIR, `dz-weekly-${safe}.json`);
  let points = [];
  try {
    if (fs.existsSync(p)) {
      const j = JSON.parse(fs.readFileSync(p, "utf8"));
      if (j && Array.isArray(j.points)) points = j.points;
    }
  } catch {
    points = [];
  }
  const byEnd = {};
  for (const x of points) {
    if (x && x.weekEnd) byEnd[x.weekEnd] = x;
  }
  byEnd[thisWed] = {
    weekEnd: thisWed,
    weekStart: prevWed,
    totalDebt: Math.round(totalDebt * 100) / 100,
    overdueDebt: Math.round(overdueDebt * 100) / 100,
  };
  let merged = Object.values(byEnd).sort((a, b) =>
    String(a.weekEnd).localeCompare(String(b.weekEnd))
  );
  if (merged.length > 16) merged = merged.slice(-16);
  try {
    if (!fs.existsSync(CACHE_DIR)) fs.mkdirSync(CACHE_DIR, { recursive: true });
    fs.writeFileSync(
      p,
      JSON.stringify({ points: merged }, null, 2),
      "utf8"
    );
  } catch {
    /* ignore */
  }
  return merged;
}

function loadConfig() {
  let pat = (process.env.AIRTABLE_PAT || "").trim();
  let base = (process.env.AIRTABLE_BASE_ID || "").trim() || "appEAS1rPKpevoIel";
  let dzTableId = (process.env.AIRTABLE_DZ_TABLE_ID || "").trim();
  let dzViewId = (process.env.AIRTABLE_DZ_VIEW_ID || "").trim();
  let csTableId = (process.env.AIRTABLE_CS_TABLE_ID || "").trim();
  let churnTableId = (process.env.AIRTABLE_CHURN_TABLE_ID || "").trim();
  let extraSourceTableIds = (process.env.AIRTABLE_EXTRA_SOURCE_TABLE_IDS || "").trim();
  let csViewId = (process.env.AIRTABLE_CS_VIEW_ID || "").trim();
  let churnViewId = (process.env.AIRTABLE_CHURN_VIEW_ID || "").trim();
  let paidViewId = (process.env.AIRTABLE_PAID_VIEW_ID || "").trim();
  const configPath = path.join(__dirname, "config.php");
  if (fs.existsSync(configPath)) {
    const raw = fs.readFileSync(configPath, "utf8");
    const mPat = raw.match(/'airtable_pat'\s*=>\s*'([^']*)'/);
    const mBase = raw.match(/'airtable_base_id'\s*=>\s*'([^']*)'/);
    const mTbl = raw.match(/'airtable_dz_table_id'\s*=>\s*'([^']*)'/);
    const mDzView = raw.match(/'airtable_dz_view_id'\s*=>\s*'([^']*)'/);
    const mCs = raw.match(/'airtable_cs_table_id'\s*=>\s*'([^']*)'/);
    const mChurn = raw.match(/'airtable_churn_table_id'\s*=>\s*'([^']*)'/);
    const mCsView = raw.match(/'airtable_cs_view_id'\s*=>\s*'([^']*)'/);
    const mChurnView = raw.match(/'airtable_churn_view_id'\s*=>\s*'([^']*)'/);
    const mPaid = raw.match(/'airtable_paid_view_id'\s*=>\s*'([^']*)'/);
    const mExtra = raw.match(/'airtable_extra_source_table_ids'\s*=>\s*'([^']*)'/);
    if (!pat && mPat) pat = mPat[1].trim();
    if (!process.env.AIRTABLE_BASE_ID && mBase) base = mBase[1].trim();
    if (!process.env.AIRTABLE_DZ_TABLE_ID && mTbl) dzTableId = mTbl[1].trim();
    if (!process.env.AIRTABLE_DZ_VIEW_ID && mDzView) dzViewId = mDzView[1].trim();
    if (!process.env.AIRTABLE_CS_TABLE_ID && mCs) csTableId = mCs[1].trim();
    if (!process.env.AIRTABLE_CHURN_TABLE_ID && mChurn) churnTableId = mChurn[1].trim();
    if (!process.env.AIRTABLE_EXTRA_SOURCE_TABLE_IDS && mExtra) {
      extraSourceTableIds = mExtra[1].trim();
    }
    if (!process.env.AIRTABLE_CS_VIEW_ID && mCsView) csViewId = mCsView[1].trim();
    if (!process.env.AIRTABLE_CHURN_VIEW_ID && mChurnView) {
      churnViewId = mChurnView[1].trim();
    }
    if (!process.env.AIRTABLE_PAID_VIEW_ID && mPaid) {
      paidViewId = mPaid[1].trim();
    }
  }
  return {
    pat,
    base,
    dzTableId,
    dzViewId,
    csTableId,
    churnTableId,
    csViewId,
    churnViewId,
    extraSourceTableIds,
    paidViewId,
  };
}

async function resolveTableId(base, pat, configured, exactName, configKeyHint) {
  const c = (configured || "").trim();
  if (c) return c;
  const url = `https://api.airtable.com/v0/meta/bases/${encodeURIComponent(base)}/tables`;
  const r = await fetch(url, {
    headers: { Authorization: `Bearer ${pat}` },
  });
  const j = await r.json().catch(() => ({}));
  if (!r.ok) {
    const msg = j.error?.message || r.statusText;
    throw new Error(
      `Meta API: ${msg}. Задайте ${configKeyHint} или переменную окружения с tbl…`
    );
  }
  for (const t of j.tables || []) {
    if (t.name === exactName) return t.id;
  }
  throw new Error(
    `Таблица «${exactName}» не найдена. Укажите ${configKeyHint} в config.php.`
  );
}

async function resolveDzTableId(base, pat, configured) {
  return resolveTableId(
    base,
    pat,
    configured,
    DZ_TABLE_NAME,
    "airtable_dz_table_id / AIRTABLE_DZ_TABLE_ID"
  );
}

function selectName(v) {
  if (v && typeof v === "object" && v.name != null) return String(v.name);
  if (typeof v === "string" && v !== "") return v;
  return null;
}

function money(v) {
  if (v == null || v === "") return null;
  if (typeof v === "number" && !Number.isNaN(v)) return v;
  if (typeof v === "string") {
    const clean = v.replace(/\s/g, "").replace(/\u00a0/g, "");
    const n = clean.replace(/[^\d.,\-]/g, "").replace(",", ".");
    if (n !== "" && !Number.isNaN(Number(n))) return Number(n);
  }
  return null;
}

/** @param {Record<string, unknown>} f */
function resolveDebtAmount(f, status) {
  const formula = money(f["Фактическая задолженность"]);
  const rest = money(f["Сумма остатка"]);
  const invoice = money(f["Сумма счета"]);
  if (status === "Оплачен частично") {
    if (rest != null && rest > 0) return rest;
    if (formula != null && formula > 0) return formula;
    if (rest != null) return rest;
    if (formula != null) return formula;
    return invoice ?? 0;
  }
  if (formula != null && formula > 0) return formula;
  if (rest != null && rest > 0) return rest;
  if (formula != null) return formula;
  if (rest != null) return rest;
  return invoice ?? 0;
}

function num(v) {
  if (typeof v === "number" && !Number.isNaN(v)) return v;
  if (typeof v === "string" && v !== "") {
    const n = String(v).replace(/[^\d.,\-]/g, "").replace(",", ".");
    if (n !== "" && !Number.isNaN(Number(n))) return Number(n);
  }
  return null;
}

function dateStr(v) {
  if (typeof v === "string" && /^\d{4}-\d{2}-\d{2}/.test(v)) return v.slice(0, 10);
  return null;
}

/** Период счёта YYYY-MM: из номера (даты, квартал, рус. месяц) или из даты счёта. */
function billingPeriodFromInvoice(invoiceNo, invoiceDateIso) {
  const no = String(invoiceNo || "").trim();
  if (no) {
    let m = no.match(/\b(0?[1-9]|1[0-2])[.\/](20[0-9]{2})\b/);
    if (m) return `${m[2]}-${String(m[1]).padStart(2, "0")}`;
    m = no.match(/\b(20[0-9]{2})[.\/](0?[1-9]|1[0-2])\b/);
    if (m) return `${m[1]}-${String(m[2]).padStart(2, "0")}`;
    m = no.match(/\b(20[0-9]{2})(0[1-9]|1[0-2])\b/);
    if (m) return `${m[1]}-${m[2]}`;
    m = no.match(/\b[QqКк]\s*([1-4])[\s.\-/]*(20[0-9]{2})\b/);
    if (m) {
      const y = m[2];
      const q = parseInt(m[1], 10);
      const month = (q - 1) * 3 + 1;
      return `${y}-${String(month).padStart(2, "0")}`;
    }
    m = no.match(/\b(20[0-9]{2})[\s.\-/]*[QqКк]\s*([1-4])\b/);
    if (m) {
      const y = m[1];
      const q = parseInt(m[2], 10);
      const month = (q - 1) * 3 + 1;
      return `${y}-${String(month).padStart(2, "0")}`;
    }
    const lower = no.toLocaleLowerCase("ru-RU");
    const ruMonths = [
      ["декабря", 12],
      ["ноября", 11],
      ["октября", 10],
      ["сентября", 9],
      ["августа", 8],
      ["июля", 7],
      ["июня", 6],
      ["мая", 5],
      ["апреля", 4],
      ["марта", 3],
      ["февраля", 2],
      ["января", 1],
      ["декабрь", 12],
      ["ноябрь", 11],
      ["октябрь", 10],
      ["сентябрь", 9],
      ["август", 8],
      ["июль", 7],
      ["июнь", 6],
      ["май", 5],
      ["апрель", 4],
      ["март", 3],
      ["февраль", 2],
      ["январь", 1],
    ];
    const alt = ruMonths.map(([w]) => w.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")).join("|");
    const reLeft = new RegExp(
      `(?<![0-9а-яёa-z])(${alt})\\s+(20[0-9]{2})(?![0-9а-яёa-z])`,
      "iu"
    );
    m = lower.match(reLeft);
    if (m) {
      const mo = ruMonths.find(([w]) => w.toLocaleLowerCase("ru-RU") === m[1].toLocaleLowerCase("ru-RU"));
      if (mo) return `${m[2]}-${String(mo[1]).padStart(2, "0")}`;
    }
    const reRight = new RegExp(
      `(?<![0-9а-яёa-z])(20[0-9]{2})\\s+(${alt})(?![0-9а-яёa-z])`,
      "iu"
    );
    m = lower.match(reRight);
    if (m) {
      const mo = ruMonths.find(
        ([w]) => w.toLocaleLowerCase("ru-RU") === m[2].toLocaleLowerCase("ru-RU")
      );
      if (mo) return `${m[1]}-${String(mo[1]).padStart(2, "0")}`;
    }
  }
  if (invoiceDateIso && /^20\d{2}-\d{2}/.test(invoiceDateIso)) {
    return invoiceDateIso.slice(0, 7);
  }
  return "—";
}

function managerList(v) {
  if (!Array.isArray(v)) return [];
  const out = [];
  for (const item of v) {
    if (typeof item === "string" && item.trim()) out.push(item.trim());
    else if (item && typeof item === "object" && item.name != null)
      out.push(String(item.name).trim());
  }
  return [...new Set(out)];
}

function richText(v) {
  return typeof v === "string" ? v : "";
}

function escHtml(s) {
  return String(s)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function rub(n) {
  return (
    Number(n).toLocaleString("ru-RU", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }) + " ₽"
  );
}

async function airtableFetchAll(baseId, token, tableId, viewId = "") {
  const records = [];
  let offset = null;
  const v = String(viewId || "").trim();
  const useView = /^viw[a-zA-Z0-9]{3,}$/.test(v);
  do {
    const u = new URL(
      `https://api.airtable.com/v0/${encodeURIComponent(baseId)}/${encodeURIComponent(tableId)}`
    );
    u.searchParams.set("filterByFormula", FILTER);
    u.searchParams.set("pageSize", "100");
    if (useView) u.searchParams.set("view", v);
    if (offset) u.searchParams.set("offset", offset);
    const r = await fetch(u, {
      headers: { Authorization: `Bearer ${token}` },
    });
    const j = await r.json();
    if (!r.ok) {
      throw new Error(j.error?.message || `Airtable HTTP ${r.status}`);
    }
    for (const rec of j.records || []) records.push(rec);
    offset = j.offset || null;
  } while (offset);
  return records;
}

async function airtableFetchAllNoFilter(baseId, token, tableId, viewId = "") {
  const records = [];
  let offset = null;
  const v = String(viewId || "").trim();
  const useView = /^viw[a-zA-Z0-9]{3,}$/.test(v);
  do {
    const u = new URL(
      `https://api.airtable.com/v0/${encodeURIComponent(baseId)}/${encodeURIComponent(tableId)}`
    );
    u.searchParams.set("pageSize", "100");
    if (useView) u.searchParams.set("view", v);
    if (offset) u.searchParams.set("offset", offset);
    const r = await fetch(u, {
      headers: { Authorization: `Bearer ${token}` },
    });
    const j = await r.json();
    if (!r.ok) {
      throw new Error(j.error?.message || `Airtable HTTP ${r.status}`);
    }
    for (const rec of j.records || []) records.push(rec);
    offset = j.offset || null;
  } while (offset);
  return records;
}

/** @param {unknown} v */
function csAllScalarToString(v) {
  if (v == null || v === "") return null;
  if (typeof v === "boolean") return v ? "да" : "нет";
  if (typeof v === "number" && !Number.isNaN(v)) return String(v);
  if (typeof v === "string") {
    const t = v.trim();
    return t === "" ? null : t;
  }
  if (typeof v === "object" && v !== null && !Array.isArray(v)) {
    if ("name" in v && v.name != null) {
      const t = String(v.name).trim();
      return t === "" ? null : t;
    }
    return null;
  }
  if (Array.isArray(v)) {
    if (v.length === 0) return null;
    const keys = Object.keys(v);
    const isList = keys.length > 0 && keys.every((k, i) => String(i) === k);
    if (isList) {
      const first = v[0];
      if (typeof first === "string")
        return v.length === 1 ? first : `${first}… +${v.length - 1}`;
      return `записей: ${v.length}`;
    }
  }
  return null;
}

/** @param {Record<string, unknown>} f */
function csAllPrimaryLabel(f) {
  for (const k of CS_ALL_LABEL_KEYS) {
    if (!Object.prototype.hasOwnProperty.call(f, k)) continue;
    const s = csAllScalarToString(f[k]);
    if (s != null && s !== "") return { label: s, key: k };
  }
  return { label: "—", key: null };
}

/** @param {Record<string, unknown>} f */
function csAllDetailsLine(f, skipKey) {
  const keys = Object.keys(f).sort();
  const parts = [];
  for (const k of keys) {
    if (skipKey != null && k === skipKey) continue;
    const val = csAllScalarToString(f[k]);
    if (val == null || val === "") continue;
    parts.push(`${k}: ${val}`);
    if (parts.length >= 8) break;
  }
  return parts.join(" · ");
}

/** @param {{ base: string, pat: string, churnTableId: string, churnViewId?: string }} config */
async function tryFetchChurnByLabel(config) {
  const v = String(config.churnViewId || "").trim();
  const viewOk = /^viw[a-zA-Z0-9]{3,}$/.test(v);
  const emptyMeta = (tid, err) => ({
    tableName: CHURN_TABLE_NAME,
    tableId: tid,
    viewId: viewOk ? v : null,
    count: 0,
    error: err || null,
  });
  let churnTid;
  try {
    churnTid = await resolveTableId(
      config.base,
      config.pat,
      config.churnTableId,
      CHURN_TABLE_NAME,
      "airtable_churn_table_id / AIRTABLE_CHURN_TABLE_ID"
    );
  } catch (e) {
    return {
      byLabel: {},
      meta: emptyMeta(null, String(e.message || e)),
    };
  }
  let raw;
  try {
    raw = await airtableFetchAllNoFilter(
      config.base,
      config.pat,
      churnTid,
      config.churnViewId || ""
    );
  } catch (e) {
    return {
      byLabel: {},
      meta: emptyMeta(churnTid, String(e.message || e)),
    };
  }
  /** @type {Record<string, string>} */
  const byLabel = {};
  for (const rec of raw) {
    const f = rec.fields || {};
    const { label, key } = csAllPrimaryLabel(f);
    if (label === "—") continue;
    const k = label.trim().toLowerCase();
    const line = csAllDetailsLine(f, key);
    if (byLabel[k]) byLabel[k] += " · ‖ " + line;
    else byLabel[k] = line;
  }
  return {
    byLabel,
    meta: {
      tableName: CHURN_TABLE_NAME,
      tableId: churnTid,
      viewId: viewOk ? v : null,
      count: raw.length,
      error: null,
    },
  };
}

/**
 * @param {Record<string, number>} debtByLegal
 * @param {Record<string, string>} [churnByLabel]
 */
async function tryFetchCsAll(config, debtByLegal, churnByLabel = {}) {
  const v = String(config.csViewId || "").trim();
  const viewOk = /^viw[a-zA-Z0-9]{3,}$/.test(v);
  const emptyMeta = (tid, err) => ({
    tableName: CS_ALL_TABLE_NAME,
    tableId: tid,
    viewId: viewOk ? v : null,
    count: 0,
    error: err || null,
  });
  let csTid;
  try {
    csTid = await resolveTableId(
      config.base,
      config.pat,
      config.csTableId,
      CS_ALL_TABLE_NAME,
      "airtable_cs_table_id / AIRTABLE_CS_TABLE_ID"
    );
  } catch (e) {
    return {
      clients: [],
      meta: emptyMeta(null, String(e.message || e)),
      mrrSum: 0,
    };
  }
  let raw;
  try {
    raw = await airtableFetchAllNoFilter(
      config.base,
      config.pat,
      csTid,
      config.csViewId || ""
    );
  } catch (e) {
    return {
      clients: [],
      meta: emptyMeta(csTid, String(e.message || e)),
      mrrSum: 0,
    };
  }
  const clients = [];
  let mrrSum = 0;
  for (const rec of raw) {
    const f = rec.fields || {};
    const { label, key } = csAllPrimaryLabel(f);
    let dz = debtByLegal[label] || 0;
    if (dz <= 0 && label !== "—") {
      const needle = label.trim().toLowerCase();
      for (const [leg, sum] of Object.entries(debtByLegal)) {
        if (String(leg).trim().toLowerCase() === needle) {
          dz = sum;
          break;
        }
      }
    }
    const ck = label.trim().toLowerCase();
    const churnLine = label !== "—" ? churnByLabel[ck] || "" : "";
    const mv = f["MRR sum"];
    if (typeof mv === "number" && !Number.isNaN(mv)) mrrSum += mv;
    else if (typeof mv === "string" && mv.trim()) {
      const n = parseFloat(
        mv.replace(/[^\d.,-]/g, "").replace(/\s/g, "").replace(",", ".")
      );
      if (!Number.isNaN(n)) mrrSum += n;
    }
    clients.push({
      id: String(rec.id || ""),
      label,
      dzTotal: Math.round(dz * 100) / 100,
      details: csAllDetailsLine(f, key),
      churnDetails: churnLine,
    });
  }
  clients.sort((a, b) => a.label.localeCompare(b.label, "ru"));
  return {
    clients,
    meta: {
      tableName: CS_ALL_TABLE_NAME,
      tableId: csTid,
      viewId: viewOk ? v : null,
      count: clients.length,
      error: null,
    },
    mrrSum: Math.round(mrrSum * 100) / 100,
  };
}

async function enrichPayloadWithCsAll(payload, config, debtTableId = null) {
  const debtByLegal = {};
  for (const r of payload.rows || []) {
    const k = r.legal || "—";
    debtByLegal[k] = (debtByLegal[k] || 0) + (r.amount || 0);
  }
  const churnBundle = await tryFetchChurnByLabel(config);
  const bundle = await tryFetchCsAll(config, debtByLegal, churnBundle.byLabel);
  payload.csAllClients = bundle.clients;
  payload.meta = {
    ...payload.meta,
    csAll: bundle.meta,
    churn: churnBundle.meta,
  };

  const slugMrr = `${config.base}_${bundle.meta?.tableId || "cs"}`.replace(
    /[^a-zA-Z0-9_-]/g,
    ""
  );
  payload.mrr = resolveMrrCacheNode(slugMrr, bundle.mrrSum ?? 0);
  const mrrV = payload.mrr.value || 0;
  const td = payload.kpi?.totalDebt ?? 0;
  payload.debtToMrrPct =
    mrrV > 0 ? Math.round((td / mrrV) * 1000) / 10 : null;

  if (debtTableId) {
    const wslug = `${config.base}_${debtTableId}`.replace(
      /[^a-zA-Z0-9_-]/g,
      ""
    );
    payload.weeklyDebtTrend = recordWeeklyDebtNode(
      wslug,
      td,
      payload.kpi?.overdueDebt ?? 0
    );
  } else {
    payload.weeklyDebtTrend = [];
  }
  payload.weeklyPayments = {
    bars: [],
    currentWeekTotal: 0,
    weekStart: "",
    weekEnd: "",
    error: null,
  };
}

function genericFieldToString(v) {
  if (v === null || v === undefined) return "";
  if (typeof v === "boolean") return v ? "true" : "false";
  if (typeof v === "number") return String(v);
  if (typeof v === "string") return v;
  if (Array.isArray(v)) {
    const parts = v.map((x) => selectName(x)).filter(Boolean);
    if (parts.length) return parts.join(", ");
    try {
      return JSON.stringify(v);
    } catch {
      return "—";
    }
  }
  if (typeof v === "object" && v != null && v.name != null) return String(v.name);
  try {
    return JSON.stringify(v);
  } catch {
    return "—";
  }
}

function buildGenericPayload(raw, genericTableId, tableName, defaultDebtTableId) {
  const allKeys = new Set();
  for (const rec of raw) {
    const f = rec.fields || {};
    for (const k of Object.keys(f)) allKeys.add(k);
  }
  const keysSorted = [...allKeys].sort();
  const rows = [];
  for (const rec of raw) {
    const f = rec.fields || {};
    const flat = {};
    for (const [k, val] of Object.entries(f)) {
      flat[k] = genericFieldToString(val);
    }
    const g = {};
    for (const k of keysSorted) g[k] = flat[k] ?? "";
    rows.push({ id: String(rec.id || ""), g });
  }
  rows.sort((a, b) => a.id.localeCompare(b.id));
  const n = rows.length;
  return {
    generatedAt: new Date().toISOString(),
    fetchMs: 0,
    recordCount: n,
    kpi: {
      totalDebt: 0,
      overdueDebt: 0,
      invoiceCount: n,
      legalEntityCount: n,
    },
    aging: { "0–30": 0, "31–60": 0, "61–90": 0, "90+": 0 },
    byStatus: {},
    byManager: [],
    byCompany: [],
    byDirection: [],
    topLegal: [],
    rows,
    csAllClients: [],
    weeklyDebtTrend: [],
    weeklyPayments: {
      bars: [],
      currentWeekTotal: 0,
      weekStart: "",
      weekEnd: "",
      error: null,
    },
    mrr: { value: 0, yearMonth: "", updatedAt: "", note: "" },
    debtToMrrPct: null,
    meta: {
      dataMode: "generic",
      defaultDebtTableId,
      table: genericTableId,
      tableName: tableName || "—",
      genericKeys: keysSorted,
      filter: null,
      csAll: {
        tableName: CS_ALL_TABLE_NAME,
        tableId: null,
        count: 0,
        error: null,
      },
      churn: {
        tableName: CHURN_TABLE_NAME,
        tableId: null,
        count: 0,
        error: null,
      },
    },
  };
}

/** CS ALL + CHURN по API (как для дебиторки); суммы ДЗ по клиентам не считаем — пустой debtByLegal. */
async function enrichGenericPayloadWithCsAll(payload, config) {
  const churnBundle = await tryFetchChurnByLabel(config);
  const bundle = await tryFetchCsAll(config, {}, churnBundle.byLabel);
  payload.csAllClients = bundle.clients;
  payload.meta = {
    ...payload.meta,
    csAll: bundle.meta,
    churn: churnBundle.meta,
  };
  const slugMrr = `${config.base}_${bundle.meta?.tableId || "cs"}`.replace(
    /[^a-zA-Z0-9_-]/g,
    ""
  );
  payload.mrr = resolveMrrCacheNode(slugMrr, bundle.mrrSum ?? 0);
  payload.debtToMrrPct = null;
}

async function listTablesFlat(base, pat) {
  const url = `https://api.airtable.com/v0/meta/bases/${encodeURIComponent(base)}/tables`;
  const r = await fetch(url, {
    headers: { Authorization: `Bearer ${pat}` },
  });
  const j = await r.json();
  if (!r.ok) {
    throw new Error(j.error?.message || r.statusText);
  }
  return (j.tables || []).map((t) => ({ id: t.id, name: t.name }));
}

const TBL_ID_RE = /^tbl[a-zA-Z0-9]{3,}$/;

/** @param {{ extraSourceTableIds?: string }} config */
function mergeExtraTableIdsIntoList(config, fromMeta) {
  const byId = new Map();
  for (const t of fromMeta || []) {
    if (t && t.id) byId.set(String(t.id).trim(), { id: String(t.id).trim(), name: t.name || t.id });
  }
  const raw = (config.extraSourceTableIds || "").trim();
  if (raw) {
    for (const part of raw.split(/[\s,;]+/)) {
      const tid = part.trim();
      if (!TBL_ID_RE.test(tid)) continue;
      if (!byId.has(tid)) byId.set(tid, { id: tid, name: `Таблица ${tid}` });
    }
  }
  return [...byId.values()].sort((a, b) => a.name.localeCompare(b.name, "ru"));
}

/** @param {ReturnType<typeof loadConfig>} config */
async function fallbackTablesListFromConfig(config) {
  const out = [];
  const seen = new Set();
  function add(id, name) {
    const t = (id || "").trim();
    if (!t || seen.has(t)) return;
    seen.add(t);
    out.push({ id: t, name });
  }
  const dzCfg = (config.dzTableId || "").trim();
  if (TBL_ID_RE.test(dzCfg)) {
    add(dzCfg, DZ_TABLE_NAME);
  } else {
    try {
      const debtTid = await resolveDzTableId(config.base, config.pat, config.dzTableId);
      add(debtTid, DZ_TABLE_NAME);
    } catch {
      /* нет tbl дебиторки в config и Meta недоступен */
    }
  }
  add(config.csTableId, CS_ALL_TABLE_NAME);
  add(config.churnTableId, CHURN_TABLE_NAME);
  const raw = (config.extraSourceTableIds || "").trim();
  if (raw) {
    for (const part of raw.split(/[\s,;]+/)) {
      const tid = part.trim();
      if (TBL_ID_RE.test(tid)) add(tid, `Таблица ${tid}`);
    }
  }
  return out.sort((a, b) => a.name.localeCompare(b.name, "ru"));
}

/** @param {ReturnType<typeof loadConfig>} config */
async function listTablesForSourcePicker(config) {
  let fromMeta = [];
  let metaError = null;
  try {
    fromMeta = await listTablesFlat(config.base, config.pat);
  } catch (e) {
    metaError = String(e.message || e);
  }
  if (Array.isArray(fromMeta) && fromMeta.length > 0) {
    return {
      tables: mergeExtraTableIdsIntoList(config, fromMeta),
      metaUnavailable: false,
      metaError: null,
    };
  }
  return {
    tables: await fallbackTablesListFromConfig(config),
    metaUnavailable: true,
    metaError,
  };
}

async function lookupTableNameById(base, pat, tableId) {
  try {
    const list = await listTablesFlat(base, pat);
    const t = list.find((x) => x.id === tableId);
    return t?.name || null;
  } catch {
    return null;
  }
}

async function buildDashboardPayload(config, requestedTableId) {
  const debtTid = await resolveDzTableId(
    config.base,
    config.pat,
    config.dzTableId
  );
  const want = (requestedTableId || "").trim();
  const t0 = Date.now();
  if (want && want !== debtTid) {
    if (!/^tbl[a-zA-Z0-9]{3,}$/.test(want)) {
      throw new Error("Некорректный tableId (ожидается tbl… из Airtable).");
    }
    const raw = await airtableFetchAllNoFilter(config.base, config.pat, want);
    const name = (await lookupTableNameById(config.base, config.pat, want)) || "—";
    const payload = buildGenericPayload(raw, want, name, debtTid);
    await enrichGenericPayloadWithCsAll(payload, config);
    payload.fetchMs = Date.now() - t0;
    return payload;
  }
  const raw = await airtableFetchAll(
    config.base,
    config.pat,
    debtTid,
    config.dzViewId || ""
  );
  const payload = attachTableMeta(buildPayload(raw), debtTid);
  await enrichPayloadWithCsAll(payload, config, debtTid);
  payload.fetchMs = Date.now() - t0;
  payload.meta.dataMode = "debt";
  payload.meta.defaultDebtTableId = debtTid;
  const dv = String(config.dzViewId || "").trim();
  payload.meta.dzViewId = /^viw[a-zA-Z0-9]{3,}$/.test(dv) ? dv : null;
  return payload;
}

function buildPayload(raw) {
  const todayDt = new Date();
  todayDt.setHours(0, 0, 0, 0);

  const rows = [];
  let totalDebt = 0;
  let overdueTotal = 0;
  const aging = { "0–30": 0, "31–60": 0, "61–90": 0, "90+": 0 };
  const byCompany = {};
  const byDirection = {};
  const byStatus = {};
  const byManager = {};
  const byLegal = {};

  for (const rec of raw) {
    const f = rec.fields || {};
    const id = String(rec.id || "");

    let status = selectName(f["Статус оплаты"]) || "—";
    byStatus[status] = (byStatus[status] || 0) + 1;

    const amt = resolveDebtAmount(f, status);

    const dueStr = dateStr(f["Срок оплаты"]);
    const due = dueStr ? new Date(dueStr + "T00:00:00") : null;
    let daysOver = num(f["Кол-во дней просрочки"]);
    const bucket = String(f["Группа просрочки"] || "").trim();

    let isOverdue = false;
    if (daysOver != null && daysOver > 0) isOverdue = true;
    else if (due != null && due < todayDt && status !== "Оплачен")
      isOverdue = true;

    totalDebt += amt;
    if (isOverdue) overdueTotal += amt;

    if (bucket && AGING_KEYS[bucket]) aging[bucket] += amt;
    else if (isOverdue && due != null) {
      const secs = Math.floor((todayDt - due) / 1000);
      const d = Math.max(0, Math.floor(secs / 86400));
      if (d <= 30) aging["0–30"] += amt;
      else if (d <= 60) aging["31–60"] += amt;
      else if (d <= 90) aging["61–90"] += amt;
      else aging["90+"] += amt;
    }

    const our = selectName(f["Наша компания"]) || "—";
    const dir = selectName(f["Направление"]) || "—";
    byCompany[our] = (byCompany[our] || 0) + amt;
    byDirection[dir] = (byDirection[dir] || 0) + amt;

    let legal = String(f["ЮЛ клиента"] || "").trim();
    if (!legal) legal = "—";
    byLegal[legal] = (byLegal[legal] || 0) + amt;

    let managers = managerList(f["Аккаунт менеджер"]);
    if (!managers.length) managers = ["Не указан"];
    const share = amt / managers.length;
    for (const m of managers) byManager[m] = (byManager[m] || 0) + share;

    let invoiceId = "";
    if (f["ИД счета"] != null) {
      const v = f["ИД счета"];
      invoiceId =
        typeof v === "string" || typeof v === "number"
          ? String(v)
          : JSON.stringify(v);
    }

    const invNo = String(f["Номер счета"] || "").trim();
    const invDate = dateStr(f["Дата счета"]);

    rows.push({
      id,
      legal,
      invoiceNo: invNo,
      billingPeriod: billingPeriodFromInvoice(invNo, invDate),
      invoiceId,
      amount: Math.round(amt * 100) / 100,
      status,
      dueDate: dueStr,
      daysOverdue: daysOver,
      agingBucket: bucket || null,
      ourCompany: our,
      direction: dir,
      nextStep: richText(f["Следующий шаг_ Статус_"]),
      stepDue: dateStr(f["Срок по шагу"]),
      comment: String(f["Комментарий по ДЗ"] || "").trim(),
      managers,
      overdue: isOverdue,
      invoiceDate: invDate,
      shipmentStatus: selectName(f["Статус отгрузки"]),
      sendStatus: selectName(f["Статус отправки"]),
      litigation: selectName(f["Иск"]),
    });
  }

  rows.sort((a, b) => {
    if (a.overdue !== b.overdue) return a.overdue ? -1 : 1;
    const da = a.daysOverdue;
    const db = b.daysOverdue;
    if (da != null && db != null && da !== db) return db - da;
    return b.amount - a.amount;
  });

  const toSortedRows = (obj) =>
    Object.entries(obj)
      .map(([name, amount]) => ({ name, amount: Math.round(amount * 100) / 100 }))
      .sort((x, y) => y.amount - x.amount);

  const legalCounts = {};
  for (const r of rows) legalCounts[r.legal] = (legalCounts[r.legal] || 0) + 1;
  const legalRows = Object.entries(byLegal)
    .map(([name, amount]) => ({
      name,
      amount: Math.round(amount * 100) / 100,
      count: legalCounts[name] || 0,
    }))
    .sort((x, y) => y.amount - x.amount);
  const legalTop = legalRows.slice(0, 25);

  const unpaidN =
    (byStatus["Не оплачен"] || 0) + (byStatus["Оплачен частично"] || 0);
  const clientsN = new Set(rows.map((r) => r.legal)).size;

  return {
    generatedAt: new Date().toISOString(),
    fetchMs: 0,
    recordCount: rows.length,
    kpi: {
      totalDebt: Math.round(totalDebt * 100) / 100,
      overdueDebt: Math.round(overdueTotal * 100) / 100,
      invoiceCount: unpaidN,
      legalEntityCount: clientsN,
    },
    aging,
    byStatus,
    byManager: toSortedRows(byManager),
    byCompany: toSortedRows(byCompany),
    byDirection: toSortedRows(byDirection),
    topLegal: legalTop,
    rows,
    weeklyDebtTrend: [],
    weeklyPayments: {
      bars: [],
      currentWeekTotal: 0,
      weekStart: "",
      weekEnd: "",
      error: null,
    },
    mrr: { value: 0, yearMonth: "", updatedAt: "", note: "" },
    debtToMrrPct: null,
    meta: { filter: FILTER, table: "", tableName: DZ_TABLE_NAME },
  };
}

function attachTableMeta(payload, tableId) {
  payload.meta = { ...payload.meta, table: tableId };
  return payload;
}

function ensureSnapshotsDir() {
  if (!fs.existsSync(SNAPSHOTS_DIR)) {
    fs.mkdirSync(SNAPSHOTS_DIR, { recursive: true });
  }
}

function listSnapshots() {
  ensureSnapshotsDir();
  if (fs.existsSync(MANIFEST_PATH)) {
    try {
      const decoded = JSON.parse(fs.readFileSync(MANIFEST_PATH, "utf8"));
      if (Array.isArray(decoded)) return decoded.filter((x) => x && typeof x === "object");
    } catch {
      /* fall through */
    }
  }
  const items = [];
  for (const f of fs.readdirSync(SNAPSHOTS_DIR)) {
    if (!f.startsWith("dz-report-") || !f.endsWith(".html")) continue;
    const abs = path.join(SNAPSHOTS_DIR, f);
    const st = fs.statSync(abs);
    items.push({
      id: f.replace(/[^\d-]/g, ""),
      file: f,
      url: "snapshots/" + encodeURIComponent(f),
      createdAt: st.mtime.toISOString(),
      recordCount: null,
      totalDebt: null,
      sizeBytes: st.size,
    });
  }
  items.sort((a, b) => String(b.createdAt).localeCompare(String(a.createdAt)));
  return items;
}

function renderReportHtmlGeneric(payload) {
  const rows = Array.isArray(payload.rows) ? payload.rows : [];
  const keys = Array.isArray(payload.meta?.genericKeys) ? payload.meta.genericKeys : [];
  const generatedAt = escHtml(String(payload.generatedAt || ""));
  const title = escHtml(String(payload.meta?.tableName || "Таблица"));
  const kpi = payload.kpi || {};
  let th = `<th>${escHtml("id")}</th>`;
  for (const k of keys) {
    th += `<th>${escHtml(String(k))}</th>`;
  }
  let body = "";
  for (const row of rows) {
    if (!row || typeof row !== "object") continue;
    const g = row.g && typeof row.g === "object" ? row.g : {};
    body += `<tr><td>${escHtml(String(row.id ?? ""))}</td>`;
    for (const k of keys) {
      body += `<td>${escHtml(String(g[k] ?? ""))}</td>`;
    }
    body += "</tr>";
  }
  return `<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${title}</title>
  <style>
    body{font-family:Segoe UI,Arial,sans-serif;background:#f6f8fb;color:#1a2233;margin:0}
    .wrap{max-width:1320px;margin:0 auto;padding:20px}
    .muted{color:#5f6f87;font-size:13px}
    .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:14px}
    .kpi{background:#fff;border:1px solid #dbe2ef;border-radius:10px;padding:10px}
    .kpi .v{font-weight:700;font-size:20px;color:#1b5ce6}
    .kpi .l{font-size:12px;color:#5f6f87}
    .block{background:#fff;border:1px solid #dbe2ef;border-radius:10px;padding:12px;margin-bottom:14px}
    table{width:100%;border-collapse:collapse;font-size:12px}
    th,td{border-bottom:1px solid #edf1f7;padding:6px;text-align:left;vertical-align:top}
    th{background:#f8fafe}
    .scroll{overflow:auto;max-height:75vh}
  </style>
</head>
<body>
  <div class="wrap">
    <h1>Произвольная таблица: ${title}</h1>
    <div class="muted">Сформировано: ${generatedAt}</div>
    <div class="kpis">
      <div class="kpi"><div class="v">${escHtml(String(kpi.invoiceCount ?? rows.length))}</div><div class="l">${escHtml("Записей")}</div></div>
      <div class="kpi"><div class="v">${escHtml(String(keys.length))}</div><div class="l">${escHtml("Полей")}</div></div>
    </div>
    <section class="block">
      <div class="scroll"><table><thead><tr>${th}</tr></thead><tbody>${body}</tbody></table></div>
    </section>
  </div>
</body>
</html>`;
}

function renderReportHtml(payload) {
  if (payload.meta?.dataMode === "generic") {
    return renderReportHtmlGeneric(payload);
  }
  const rows = Array.isArray(payload.rows) ? payload.rows : [];
  const kpi = payload.kpi || {};
  const aging = payload.aging || {};
  const generatedAt = escHtml(String(payload.generatedAt || ""));

  const kpiHtml =
    `<div class="kpi"><div class="v">${rub(kpi.totalDebt || 0)}</div><div class="l">${escHtml("Непогашенная ДЗ")}</div></div>` +
    `<div class="kpi"><div class="v">${rub(kpi.overdueDebt || 0)}</div><div class="l">${escHtml("Просроченная ДЗ")}</div></div>` +
    `<div class="kpi"><div class="v">${escHtml(String(kpi.invoiceCount ?? 0))}</div><div class="l">${escHtml("Счетов")}</div></div>` +
    `<div class="kpi"><div class="v">${escHtml(String(kpi.legalEntityCount ?? 0))}</div><div class="l">${escHtml("ЮЛ")}</div></div>`;

  let agingRows = "";
  for (const bucket of ["0–30", "31–60", "61–90", "90+"]) {
    agingRows += `<tr><td>${escHtml(bucket)}</td><td class="num">${rub(aging[bucket] || 0)}</td></tr>`;
  }

  let csRows = "";
  const csList = Array.isArray(payload.csAllClients) ? payload.csAllClients : [];
  for (const c of csList) {
    if (!c || typeof c !== "object") continue;
    csRows +=
      `<tr><td>${escHtml(String(c.label ?? "—"))}</td>` +
      `<td class="num">${rub(c.dzTotal || 0)}</td>` +
      `<td>${escHtml(String(c.details ?? ""))}</td>` +
      `<td>${escHtml(String(c.churnDetails ?? ""))}</td></tr>`;
  }

  let tableRows = "";
  for (const row of rows) {
    if (!row || typeof row !== "object") continue;
    const oc = row.overdue ? ' class="overdue"' : "";
    tableRows +=
      `<tr${oc}>` +
      `<td>${escHtml(String(row.legal ?? "—"))}</td>` +
      `<td>${escHtml(String(row.invoiceNo ?? "—"))}</td>` +
      `<td class="num">${rub(row.amount || 0)}</td>` +
      `<td>${escHtml(String(row.status ?? "—"))}</td>` +
      `<td>${escHtml(String(row.dueDate ?? "—"))}</td>` +
      `<td class="num">${escHtml(String(row.daysOverdue ?? "—"))}</td>` +
      `<td>${escHtml(String(row.agingBucket ?? "—"))}</td>` +
      `<td>${escHtml(String(row.ourCompany ?? "—"))}</td>` +
      `<td>${escHtml(String(row.direction ?? "—"))}</td>` +
      `<td>${escHtml((row.managers || []).join(", "))}</td>` +
      `<td>${escHtml(String(row.nextStep ?? ""))}</td>` +
      `<td>${escHtml(String(row.comment ?? ""))}</td>` +
      `</tr>`;
  }

  return `<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Отчет ДЗ</title>
  <style>
    body{font-family:Segoe UI,Arial,sans-serif;background:#f6f8fb;color:#1a2233;margin:0}
    .wrap{max-width:1320px;margin:0 auto;padding:20px}
    .head{margin-bottom:16px}
    .head h1{margin:0 0 6px}
    .muted{color:#5f6f87;font-size:13px}
    .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:14px}
    .kpi{background:#fff;border:1px solid #dbe2ef;border-radius:10px;padding:10px}
    .kpi .v{font-weight:700;font-size:20px;color:#1b5ce6}
    .kpi .l{font-size:12px;color:#5f6f87}
    .block{background:#fff;border:1px solid #dbe2ef;border-radius:10px;padding:12px;margin-bottom:14px}
    .block h2{margin:0 0 10px;font-size:14px;color:#5f6f87;text-transform:uppercase}
    table{width:100%;border-collapse:collapse;font-size:13px}
    th,td{border-bottom:1px solid #edf1f7;padding:7px;text-align:left;vertical-align:top}
    th{background:#f8fafe;position:sticky;top:0}
    .num{text-align:right;white-space:nowrap}
    tr.overdue td:first-child{box-shadow:inset 3px 0 0 #d93025}
    .scroll{overflow:auto;max-height:70vh}
    @media print{body{background:#fff}.block,.kpi{break-inside:avoid}}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="head">
      <h1>Отчет по дебиторской задолженности</h1>
      <div class="muted">Сформировано: ${generatedAt}</div>
    </div>
    <div class="kpis">${kpiHtml}</div>
    <section class="block">
      <h2>Возрастная структура</h2>
      <div class="scroll">
        <table><thead><tr><th>Группа</th><th class="num">Сумма</th></tr></thead><tbody>${agingRows}</tbody></table>
      </div>
    </section>
    <section class="block">
      <h2>Клиенты (CS ALL)</h2>
      <div class="scroll">
        <table><thead><tr><th>Клиент</th><th class="num">ДЗ в срезе</th><th>Детали</th><th>CHURN</th></tr></thead><tbody>${csRows}</tbody></table>
      </div>
    </section>
    <section class="block">
      <h2>Детализация счетов</h2>
      <div class="scroll">
        <table>
          <thead>
            <tr>
              <th>ЮЛ</th><th>Счет</th><th class="num">Сумма</th><th>Статус</th><th>Срок оплаты</th><th class="num">Дней</th>
              <th>Группа</th><th>Наша компания</th><th>Направление</th><th>Менеджеры</th><th>След. шаг</th><th>Комментарий</th>
            </tr>
          </thead>
          <tbody>${tableRows}</tbody>
        </table>
      </div>
    </section>
  </div>
</body>
</html>`;
}

function saveHtmlSnapshot(payload, html) {
  ensureSnapshotsDir();
  const now = new Date();
  const slug = [
    now.getFullYear(),
    String(now.getMonth() + 1).padStart(2, "0"),
    String(now.getDate()).padStart(2, "0"),
    "-",
    String(now.getHours()).padStart(2, "0"),
    String(now.getMinutes()).padStart(2, "0"),
    String(now.getSeconds()).padStart(2, "0"),
  ].join("");
  const fileName = `dz-report-${slug}.html`;
  const abs = path.join(SNAPSHOTS_DIR, fileName);
  fs.writeFileSync(abs, html, "utf8");
  const st = fs.statSync(abs);
  const item = {
    id: slug,
    file: fileName,
    url: "snapshots/" + encodeURIComponent(fileName),
    createdAt: now.toISOString(),
    recordCount: Number(payload.recordCount || 0),
    totalDebt: Number(payload.kpi?.totalDebt ?? 0),
    sizeBytes: st.size,
  };
  let manifest = listSnapshots();
  manifest = [item, ...manifest].slice(0, 300);
  fs.writeFileSync(MANIFEST_PATH, JSON.stringify(manifest, null, 2), "utf8");
  return item;
}

function safeJoin(root, urlPath) {
  const decoded = decodeURIComponent(urlPath.split("?")[0]);
  const resolved = path.normalize(path.join(root, decoded));
  if (!resolved.startsWith(root)) return null;
  return resolved;
}

const MIME = {
  ".css": "text/css; charset=utf-8",
  ".js": "application/javascript; charset=utf-8",
  ".html": "text/html; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".ico": "image/x-icon",
};

async function handleApi(searchParams, config) {
  const action = searchParams.get("action") || "refresh";

  if (action === "snapshots") {
    return {
      status: 200,
      body: JSON.stringify({ ok: true, items: listSnapshots() }),
      json: true,
    };
  }

  if (!config.pat) {
    return {
      status: 503,
      body: JSON.stringify({
        ok: false,
        error: "Не задан AIRTABLE_PAT (config.php или переменная окружения).",
      }),
      json: true,
    };
  }

  if (action === "tables") {
    try {
      const picked = await listTablesForSourcePicker(config);
      const debtTid = await resolveDzTableId(
        config.base,
        config.pat,
        config.dzTableId
      );
      return {
        status: 200,
        body: JSON.stringify({
          ok: true,
          schemaVersion: 1,
          tables: picked.tables,
          defaultDebtTableId: debtTid,
          tablesSource: picked.metaUnavailable ? "config" : "meta",
          tablesNote: picked.metaError,
        }),
        json: true,
      };
    } catch (e) {
      return {
        status: 500,
        body: JSON.stringify({ ok: false, error: String(e.message || e) }),
        json: true,
      };
    }
  }

  try {
    const wantTable = (searchParams.get("tableId") || "").trim();
    const payload = await buildDashboardPayload(config, wantTable);

    if (action === "snapshot") {
      const html = renderReportHtml(payload);
      const snap = saveHtmlSnapshot(payload, html);
      return {
        status: 200,
        body: JSON.stringify({
          ok: true,
          data: payload,
          snapshot: snap,
          items: listSnapshots(),
        }),
        json: true,
      };
    }

    return {
      status: 200,
      body: JSON.stringify({
        ok: true,
        data: payload,
        items: listSnapshots(),
      }),
      json: true,
    };
  } catch (e) {
    const msg = String(e.message || e);
    const rateLimited =
      /rate limit|429|лимит|LIMIT/i.test(msg) ||
      (e && typeof e === "object" && "status" in e && e.status === 429);
    return {
      status: rateLimited ? 429 : 500,
      body: JSON.stringify({
        ok: false,
        error: msg,
        rateLimited,
        rateLimitHint: rateLimited
          ? "Лимит запросов Airtable. Обычно сброс около 20:00 по Москве (Europe/Moscow); уточните в аккаунте Airtable."
          : null,
      }),
      json: true,
    };
  }
}

function readUtf8File(p) {
  try {
    if (!fs.existsSync(p) || !fs.statSync(p).isFile()) return null;
    const s = fs.readFileSync(p, "utf8").trim();
    return s || null;
  } catch {
    return null;
  }
}

/** Валидный JSON из cache/ для вставки в <script type="application/json"> */
function embedJsonFromCacheFile(fileName) {
  const raw = readUtf8File(path.join(CACHE_DIR, fileName));
  if (!raw) return "";
  try {
    JSON.parse(raw);
    return raw.replace(/</g, "\\u003c");
  } catch {
    return "";
  }
}

function nodeModeNoticeP() {
  return `<p>${escHtml(
    "Сервер Node.js: главный дашборд и API — /index.php и /api.php. Полный дашборд руководителя и еженедельный отчёт — только при запуске с PHP; ниже ссылка на главную."
  )}</p>`;
}

function handlePhpOnlyApiJson(hint) {
  return {
    status: 503,
    body: JSON.stringify({
      ok: false,
      error:
        hint ||
        "Этот метод доступен только при запуске через PHP. Используйте LAUNCH.command с PHP или обновите кэш из среды с PHP.",
    }),
    json: true,
  };
}

function handleManagerPlaceholderPage() {
  const body = `<!DOCTYPE html>
<html lang="ru" id="html-root">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Дашборд руководителя (Node)</title>
  <link rel="stylesheet" href="assets/dashboard.css?v=16">
  <script src="assets/aq-theme-boot.js?v=1"></script>
</head>
<body>
  <div class="setup">
    <h1>Дашборд руководителя</h1>
    ${nodeModeNoticeP()}
    <p><a href="index.php">Открыть главный дашборд ДЗ</a></p>
  </div>
</body>
</html>`;
  return { status: 200, type: "text/html; charset=utf-8", body };
}

function handleWeeklyPlaceholderPage() {
  const body = `<!DOCTYPE html>
<html lang="ru" id="html-root">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Еженедельный отчёт (Node)</title>
  <link rel="stylesheet" href="assets/dashboard.css?v=16">
  <script src="assets/aq-theme-boot.js?v=1"></script>
</head>
<body>
  <div class="setup">
    <h1>Еженедельный отчёт по ДЗ</h1>
    ${nodeModeNoticeP()}
    <p><a href="index.php">Открыть главный дашборд ДЗ</a></p>
  </div>
</body>
</html>`;
  return { status: 200, type: "text/html; charset=utf-8", body };
}

function handleChurnPage(config) {
  if (!config.pat) {
    return {
      status: 200,
      type: "text/html; charset=utf-8",
      body: `<!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><title>Угроза Churn</title>
<link rel="stylesheet" href="assets/dashboard.css?v=16"></head>
<body><div class="setup"><h1>Угроза Churn</h1>
<p>Укажите токен в <code>config.php</code> или переменную окружения <code>AIRTABLE_PAT</code>.</p>
</div></body></html>`,
    };
  }
  const boot = embedJsonFromCacheFile("churn-report.json");
  const inner = boot
    ? `<script type="application/json" id="churn-bootstrap">${boot}</script>
  <div id="app">
    <div class="sk-page" id="app-skeleton">
      <div class="sk-topbar"></div>
      <div class="sk-wrap">
        <div class="sk-block sk-tall"></div>
        <div class="sk-block"></div>
        <div class="sk-block sk-tall"></div>
      </div>
    </div>
  </div>
  <script src="assets/utils.js?v=1" defer></script>
  <script src="assets/churn.js?v=6" defer></script>
  <script src="assets/shared-nav.js?v=2" defer></script>`
    : `<div class="setup">
    <h1>Угроза Churn</h1>
    <p>Нет файла кэша <code>cache/churn-report.json</code>. Сгенерируйте его из среды с PHP или откройте <a href="index.php">главный дашборд</a>.</p>
  </div>`;

  const body = `<!DOCTYPE html>
<html lang="ru" id="html-root">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="color-scheme" content="dark light">
  <meta name="csrf-token" content="">
  <title>Угроза Churn — AnyQuery</title>
  <link rel="stylesheet" href="assets/dashboard.css?v=16">
  <link rel="stylesheet" href="assets/churn.css?v=10">
  <script src="assets/aq-theme-boot.js?v=1"></script>
</head>
<body>
${inner}
</body>
</html>`;
  return { status: 200, type: "text/html; charset=utf-8", body };
}

function handleChurnFactPage(config) {
  if (!config.pat) {
    return {
      status: 200,
      type: "text/html; charset=utf-8",
      body: `<!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><title>Потери выручки</title>
<link rel="stylesheet" href="assets/dashboard.css?v=16"></head>
<body><div class="setup"><h1>Потери выручки</h1>
<p>Укажите токен в <code>config.php</code> или <code>AIRTABLE_PAT</code>.</p>
</div></body></html>`,
    };
  }
  const boot = embedJsonFromCacheFile("churn-fact-report.json");
  const inner = boot
    ? `<script type="application/json" id="fact-bootstrap">${boot}</script>
  <div id="app">
    <div class="sk-page" id="app-skeleton">
      <div class="sk-topbar"></div>
      <div class="sk-wrap">
        <div class="sk-kpi-row">
          <div class="sk-block sk-kpi"></div>
          <div class="sk-block sk-kpi"></div>
          <div class="sk-block sk-kpi"></div>
          <div class="sk-block sk-kpi"></div>
        </div>
        <div class="sk-block sk-tall"></div>
        <div class="sk-block"></div>
      </div>
    </div>
  </div>
  <script src="assets/utils.js?v=1" defer></script>
  <script src="assets/churn_fact.js?v=15" defer></script>
  <script src="assets/shared-nav.js?v=2" defer></script>`
    : `<div class="setup">
    <h1>Потери выручки</h1>
    <p>Нет кэша <code>cache/churn-fact-report.json</code>. Нужен предварительный запуск с PHP или откройте <a href="index.php">главный дашборд</a>.</p>
  </div>`;

  const body = `<!DOCTYPE html>
<html lang="ru" id="html-root">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="color-scheme" content="dark light">
  <meta name="csrf-token" content="">
  <title>Потери выручки — AnyQuery</title>
  <link rel="stylesheet" href="assets/dashboard.css?v=16">
  <link rel="stylesheet" href="assets/churn_fact.css?v=11">
  <script src="assets/aq-theme-boot.js?v=1"></script>
</head>
<body>
${inner}
</body>
</html>`;
  return { status: 200, type: "text/html; charset=utf-8", body };
}

async function handleIndex(config, searchParams) {
  if (!config.pat) {
    return {
      status: 200,
      type: "text/html; charset=utf-8",
      body: `<!DOCTYPE html>
<html lang="ru">
<head><meta charset="utf-8"><title>ДЗ — дашборд</title></head>
<body style="font-family:system-ui;padding:2rem">
<h1>Дашборд дебиторки</h1>
<p>Укажите токен в <code>dashboard/config.php</code> (поле <code>airtable_pat</code>).</p>
</body></html>`,
    };
  }
  try {
    const wantTable = (searchParams?.get("tableId") || "").trim();
    const payload = await buildDashboardPayload(config, wantTable);
    payload.snapshots = listSnapshots();
    const bootstrapJson = JSON.stringify(payload).replace(/</g, "\\u003c");
    const html = `<!DOCTYPE html>
<html lang="ru" id="html-root">
<head>
  <meta charset="utf-8">
  <script src="assets/aq-theme-boot.js?v=1"></script>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>ДЗ — дашборд</title>
  <link rel="stylesheet" href="assets/dashboard.css?v=16">
</head>
<body>
  <script type="application/json" id="dz-bootstrap">${bootstrapJson}</script>
  <div id="app"></div>
  <script src="assets/dashboard.js?v=29" defer></script>
</body>
</html>`;
    return { status: 200, type: "text/html; charset=utf-8", body: html };
  } catch (e) {
    const err = escHtml(String(e.message || e));
    return {
      status: 200,
      type: "text/html; charset=utf-8",
      body: `<!DOCTYPE html>
<html lang="ru">
<head><meta charset="utf-8"><title>Ошибка</title></head>
<body style="font-family:system-ui;padding:2rem">
<h1>Ошибка загрузки</h1>
<p>${err}</p>
<p><a href="api.php">api.php</a></p>
</body></html>`,
    };
  }
}

async function handle(req, res) {
  const config = loadConfig();
  let url;
  try {
    url = new URL(req.url || "/", `http://${HOST}:${PORT}`);
  } catch {
    res.writeHead(400);
    res.end();
    return;
  }

  const pathname = url.pathname.replace(/\/$/, "") || "/";

  if (pathname === "/api.php") {
    const r = await handleApi(url.searchParams, config);
    res.writeHead(r.status, {
      "Content-Type": "application/json; charset=utf-8",
      "X-Content-Type-Options": "nosniff",
    });
    res.end(r.body);
    return;
  }

  if (
    pathname === "/manager_api.php" ||
    pathname === "/churn_api.php" ||
    pathname === "/churn_fact_api.php"
  ) {
    const r = handlePhpOnlyApiJson();
    res.writeHead(r.status, {
      "Content-Type": "application/json; charset=utf-8",
      "X-Content-Type-Options": "nosniff",
    });
    res.end(r.body);
    return;
  }

  if (pathname === "/manager.php") {
    const r = handleManagerPlaceholderPage();
    res.writeHead(r.status, { "Content-Type": r.type });
    res.end(r.body);
    return;
  }

  if (pathname === "/weekly.php") {
    const r = handleWeeklyPlaceholderPage();
    res.writeHead(r.status, { "Content-Type": r.type });
    res.end(r.body);
    return;
  }

  if (pathname === "/churn.php") {
    const r = handleChurnPage(config);
    res.writeHead(r.status, { "Content-Type": r.type });
    res.end(r.body);
    return;
  }

  if (pathname === "/churn_fact.php") {
    const r = handleChurnFactPage(config);
    res.writeHead(r.status, { "Content-Type": r.type });
    res.end(r.body);
    return;
  }

  if (pathname === "/" || pathname === "/index.php") {
    const r = await handleIndex(config, url.searchParams);
    res.writeHead(r.status, { "Content-Type": r.type });
    res.end(r.body);
    return;
  }

  if (pathname.startsWith("/assets/") || pathname.startsWith("/snapshots/")) {
    const filePath = safeJoin(__dirname, pathname.slice(1));
    if (!filePath || !fs.existsSync(filePath) || !fs.statSync(filePath).isFile()) {
      res.writeHead(404);
      res.end("Not found");
      return;
    }
    const ext = path.extname(filePath).toLowerCase();
    const type = MIME[ext] || "application/octet-stream";
    res.writeHead(200, { "Content-Type": type });
    fs.createReadStream(filePath).pipe(res);
    return;
  }

  res.writeHead(404);
  res.end("Not found");
}

const server = http.createServer((req, res) => {
  void handle(req, res).catch((err) => {
    console.error(err);
    res.writeHead(500);
    res.end("Server error");
  });
});

server.listen(PORT, HOST, () => {
  console.error(`Дашборд (Node): http://${HOST}:${PORT}/index.php`);
  console.error(`  Churn (кэш): http://${HOST}:${PORT}/churn.php`);
  console.error(`  Факт churn:   http://${HOST}:${PORT}/churn_fact.php`);
});
