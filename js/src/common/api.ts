import app from 'flarum/common/app';

export interface SourceConfig {
  driver?: string;
  host?: string;
  port?: string | number;
  database?: string;
  username?: string;
  password?: string;
  prefix?: string;
  file?: string; // handle from an uploaded dump
}

export interface TestResult {
  ok: boolean;
  error?: string;
  counts?: Record<string, number>;
}

export interface ImportStatus {
  runId?: number | null;
  running?: boolean;
  done?: boolean;
  failed?: boolean;
  skipped?: boolean;
  percent?: number;
  status?: string | null;
  summary?: Record<string, number>;
  lastStatus?: string | null;
}

const base = (): string => app.forum.attribute('apiUrl');

export function testConnection(source: string, config: SourceConfig): Promise<TestResult> {
  return app.request({ method: 'POST', url: `${base()}/importer/test`, body: { source, config } });
}

/** Upload a database dump (.sql/.sql.gz) or SQLite file; returns row counts + a handle for start(). */
export function uploadDump(source: string, prefix: string, file: File): Promise<TestResult & { handle?: string }> {
  const fd = new FormData();
  fd.append('source', source);
  fd.append('prefix', prefix);
  fd.append('file', file);
  return fetch(`${base()}/importer/upload`, {
    method: 'POST',
    headers: { 'X-CSRF-Token': (app.session as any).csrfToken },
    credentials: 'same-origin',
    body: fd,
  }).then((r) => r.json());
}

export function startImport(source: string, config: SourceConfig): Promise<ImportStatus> {
  return app.request({ method: 'POST', url: `${base()}/importer/start`, body: { source, config } });
}

/** Process one bounded batch of a running import. */
export function stepImport(runId: number): Promise<ImportStatus> {
  return app.request({ method: 'POST', url: `${base()}/importer/step`, body: { runId } });
}

export function importStatus(runId?: number): Promise<ImportStatus> {
  const q = runId ? `?runId=${runId}` : '';
  return app.request({ method: 'GET', url: `${base()}/importer/status${q}` });
}

export function resetImport(runId?: number): Promise<any> {
  return app.request({ method: 'POST', url: `${base()}/importer/reset`, body: runId ? { runId } : {} });
}

export interface RedirectRun {
  id: number;
  source: string;
  status: string;
  created_at: string | null;
  counts: { topic: number; tag: number; user: number };
}

export interface RedirectExample {
  kind: string;
  from: string;
  to: string | null;
}

export interface RedirectState {
  enabled: boolean;
  saved: { runId: number; source: string };
  runs: RedirectRun[];
  sources: { key: string; label: string }[];
  selected: { runId: number; source: string };
  known: boolean;
  examples: RedirectExample[];
}

/** What redirects would do, before anybody turns them on. */
export function redirectState(runId?: number, source?: string): Promise<RedirectState> {
  const q: string[] = [];
  if (runId) q.push(`runId=${runId}`);
  if (source) q.push(`source=${encodeURIComponent(source)}`);
  return app.request({ method: 'GET', url: `${base()}/importer/redirects${q.length ? '?' + q.join('&') : ''}` });
}

/**
 * Turn redirects on for one run, or off.
 *
 * Rejects with a 422 carrying `reason` when the combination would resolve
 * nothing — an empty map, an unknown platform, a run that no longer exists.
 */
export function saveRedirects(enabled: boolean, runId?: number, source?: string): Promise<any> {
  return app.request({
    method: 'POST',
    url: `${base()}/importer/redirects`,
    body: { enabled, runId, source },
    errorHandler: (e: any) => {
      throw e;
    },
  });
}
