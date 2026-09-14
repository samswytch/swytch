/**
 * CSV export (BRIEF.md §10). Thirty lines of code and the insurance policy for
 * the whole project, so it stays dependency-free and obvious.
 */

/**
 * Spreadsheets treat a leading =, +, - or @ as the start of a formula. The log
 * contains text the assistant wrote, so a cell is prefixed with an apostrophe
 * when it would otherwise be read as one.
 */
function escapeCell(value: string | number | null | undefined): string {
  if (value === null || value === undefined) return '';
  let text = String(value);
  if (/^[=+\-@]/.test(text)) text = `'${text}`;
  return `"${text.replace(/"/g, '""')}"`;
}

export function toCsv(headers: string[], rows: (string | number | null | undefined)[][]): string {
  const lines = [headers.map(escapeCell).join(','), ...rows.map((row) => row.map(escapeCell).join(','))];
  // A byte order mark so Excel opens it as UTF-8 rather than mangling the
  // pound signs and curly quotes.
  return `﻿${lines.join('\r\n')}\r\n`;
}

export function csvFilename(stem: string): string {
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/London' }).format(new Date());
  return `${stem}-${today}.csv`;
}

export function csvResponse(body: string, filename: string): Response {
  return new Response(body, {
    headers: {
      'Content-Type': 'text/csv; charset=utf-8',
      'Content-Disposition': `attachment; filename="${filename}"`,
      'Cache-Control': 'no-store',
    },
  });
}
