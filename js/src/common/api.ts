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
