/* ============================================================
   utils.js — Shared utilities for AQ Dashboard
   Exposed as window.AqUtils — loaded before page scripts.
   ============================================================ */
(function () {
  'use strict';

  window.AqUtils = {

    // ── Форматирование рублей ──────────────────────────────
    fmtR(v) {
      return Math.round(v ?? 0).toLocaleString('ru-RU') + '\u00a0₽';
    },

    // Компактный формат: 1 500 000 → 1.5М, 250 000 → 250К
    fmtK(v) {
      v = v ?? 0;
      if (v >= 1_000_000) return (v / 1_000_000).toFixed(1) + 'М';
      if (v >= 1_000)     return (v / 1_000).toFixed(0) + 'К';
      return Math.round(v).toString();
    },

    // Процент
    fmtPct(n) { return `${n}%`; },

    // Дата из ISO → DD.MM.YYYY
    fmtDate(s) {
      if (!s) return '—';
      const p = String(s).slice(0, 10).split('-');
      return p.length === 3 ? `${p[2]}.${p[1]}.${p[0]}` : s;
    },

    // Знак числа: +X или X (пусто если отрицательное — само несёт минус)
    sign(v) { return v >= 0 ? '+' : ''; },

    // HTML-escape
    esc(s) {
      return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    },

    // ── localStorage helpers (silent fail) ────────────────
    lsGet(key, fallback = null) {
      try {
        const raw = localStorage.getItem(key);
        return raw !== null ? JSON.parse(raw) : fallback;
      } catch { return fallback; }
    },

    lsSet(key, value) {
      try { localStorage.setItem(key, JSON.stringify(value)); } catch {}
    },

    lsDel(key) {
      try { localStorage.removeItem(key); } catch {}
    },

    // ── Clipboard helper ──────────────────────────────────
    async copyText(text, btn, labels = ['📋 Сводка', '✓ Скопировано', '✗ Ошибка']) {
      try {
        await navigator.clipboard.writeText(text);
        if (btn) { btn.textContent = labels[1]; setTimeout(() => btn.textContent = labels[0], 2000); }
        return true;
      } catch {
        if (btn) { btn.textContent = labels[2]; setTimeout(() => btn.textContent = labels[0], 2000); }
        return false;
      }
    },

    // ── CSV export helper ─────────────────────────────────
    // headers: string[], rows: string[][]
    downloadCsv(filename, headers, rows) {
      const BOM = '\uFEFF'; // UTF-8 BOM для Excel
      const escape = cell => {
        const s = String(cell ?? '');
        return s.includes(',') || s.includes('"') || s.includes('\n')
          ? '"' + s.replace(/"/g, '""') + '"'
          : s;
      };
      const lines = [headers.map(escape).join(',')];
      rows.forEach(r => lines.push(r.map(escape).join(',')));
      const blob = new Blob([BOM + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
      const url  = URL.createObjectURL(blob);
      const a    = document.createElement('a');
      a.href     = url;
      a.download = filename;
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 5000);
    },

  };
})();
