import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import { testConnection, uploadDump, startImport, stepImport, importStatus, resetImport, type ImportStatus, type TestResult, type SourceConfig } from '../../common/api';

declare const m: any;
const t = (k: string, p?: any): any => app.translator.trans('ernestdefoe-importer.admin.' + k, p);

interface Source {
  label: string;
  driver: string;
  prefix?: string;
  needsPrefix?: boolean;
  port?: string;
  noUsername?: boolean; // Redis auth uses the password only
  noUpload?: boolean; // dump-upload only supports mysqldump → hide for pgsql/redis
}

// Kept in step with Importers\Registry::catalog() on the PHP side.
const SOURCES: Record<string, Source> = {
  phpbb: { label: 'phpBB 3.x', driver: 'mysql', prefix: 'phpbb_', needsPrefix: true, port: '3306' },
  xenforo: { label: 'XenForo 1.x / 2.x', driver: 'mysql', port: '3306' },
  vbulletin: { label: 'vBulletin 3.x / 4.x / 5.x', driver: 'mysql', prefix: '', needsPrefix: true, port: '3306' },
  mybb: { label: 'MyBB 1.8.x', driver: 'mysql', prefix: 'mybb_', needsPrefix: true, port: '3306' },
  smf: { label: 'SMF 2.0 / 2.1', driver: 'mysql', prefix: 'smf_', needsPrefix: true, port: '3306' },
  vanilla: { label: 'Vanilla Forums', driver: 'mysql', prefix: 'GDN_', needsPrefix: true, port: '3306' },
  invision: { label: 'Invision Community (IP.Board)', driver: 'mysql', port: '3306' },
  convoro: { label: 'Convoro', driver: 'mysql', port: '3306' },
  discourse: { label: 'Discourse (PostgreSQL)', driver: 'pgsql', port: '5432', noUpload: true },
  nodebb: { label: 'NodeBB (Redis)', driver: 'redis', port: '6379', noUsername: true, noUpload: true },
};

/**
 * The import wizard. Two ways in: connect to a live source DB, or upload a
 * database dump (.sql / .sql.gz / .sqlite) for hosts that only hand you a file.
 * The import itself runs as a loop of small "step" requests — no timeout, no
 * queue worker required — and resumes after a reload.
 */
export default class ImporterPage extends Component {
  source = 'phpbb';
  mode: 'connect' | 'upload' = 'connect';
  config: SourceConfig = { host: '127.0.0.1', port: '3306', database: '', username: '', password: '', prefix: 'phpbb_' };
  file: File | null = null;
  handle: string | null = null;
  testing = false;
  uploading = false;
  testResult: TestResult | null = null;
  starting = false;
  status: ImportStatus = {};
  runId: number | null = null;
  stopped = false;

  oninit(vnode: any) {
    super.oninit(vnode);
    importStatus().then((s) => {
      if (s.runId && s.running) {
        this.status = s;
        this.runId = s.runId;
        this.loop();
        m.redraw();
      }
    });
  }

  onremove() {
    this.stopped = true;
  }

  cfg(): SourceConfig {
    return { ...this.config, driver: SOURCES[this.source].driver };
  }

  pickSource(key: string) {
    this.source = key;
    this.testResult = null;
    this.handle = null;
    const s = SOURCES[key];
    this.config.prefix = s.prefix || '';
    this.config.port = s.port || '3306';
    if (s.noUpload) this.mode = 'connect';
  }

  setMode(mode: 'connect' | 'upload') {
    this.mode = mode;
    this.testResult = null;
    this.handle = null;
  }

  test() {
    this.testing = true;
    this.testResult = null;
    testConnection(this.source, this.cfg())
      .then((r) => (this.testResult = r))
      .catch(() => (this.testResult = { ok: false, error: t('test_failed') }))
      .finally(() => {
        this.testing = false;
        m.redraw();
      });
  }

  upload() {
    if (!this.file) return;
    this.uploading = true;
    this.testResult = null;
    this.handle = null;
    uploadDump(this.source, this.config.prefix || '', this.file)
      .then((r) => {
        this.testResult = r;
        if (r.ok && r.handle) this.handle = r.handle;
      })
      .catch(() => (this.testResult = { ok: false, error: t('upload_failed') }))
      .finally(() => {
        this.uploading = false;
        m.redraw();
      });
  }

  start() {
    if (!confirm(t('confirm_start'))) return;
    const config: SourceConfig = this.mode === 'upload' ? { file: this.handle!, prefix: this.config.prefix } : this.cfg();
    this.starting = true;
    startImport(this.source, config)
      .then((s) => {
        this.runId = s.runId ?? null;
        this.status = s;
        this.loop();
      })
      .catch((e: any) => {
        const msg = e && e.response && e.response.error ? e.response.error : t('start_failed');
        this.status = { running: false, failed: true, percent: 0, status: msg };
      })
      .finally(() => {
        this.starting = false;
        m.redraw();
      });
  }

  async loop() {
    const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));
    while (this.runId && this.status && this.status.running && !this.stopped) {
      try {
        const s = await stepImport(this.runId);
        this.status = s;
        m.redraw();
        await sleep(s.skipped ? 1200 : 250);
      } catch (e: any) {
        const msg = e && e.response && e.response.error ? e.response.error : t('start_failed');
        this.status = { ...this.status, running: false, failed: true, status: msg };
        m.redraw();
        break;
      }
    }
  }

  reset() {
    resetImport(this.runId ?? undefined).then(() => {
      this.runId = null;
      this.status = {};
      this.testResult = null;
      this.handle = null;
      this.file = null;
      m.redraw();
    });
  }

  view() {
    const running = !!this.status.running;
    const done = !!this.status.done;
    const failed = !!this.status.failed;
    const src = SOURCES[this.source];
    const canStart = !!this.testResult && this.testResult.ok && (this.mode === 'connect' || !!this.handle);

    return m('.ImporterPage', [
      m('.ImporterPage-intro', [m('h2', t('title')), m('p.helpText', t('intro'))]),

      !running &&
        !done &&
        !failed &&
        m('.ImporterPage-wizard', [
          m('.Form-group', [
            m('label', t('source')),
            m(
              'select.FormControl',
              { value: this.source, onchange: (e: any) => this.pickSource(e.target.value) },
              Object.keys(SOURCES).map((k) => m('option', { value: k }, SOURCES[k].label))
            ),
          ]),

          // mode tabs (upload is mysqldump-only, so hidden for Redis/PostgreSQL sources)
          !src.noUpload &&
            m('.ImporterPage-modes', [
              m('button', { type: 'button', className: 'Button Button--text' + (this.mode === 'connect' ? ' is-active' : ''), onclick: () => this.setMode('connect') }, t('mode_connect')),
              m('button', { type: 'button', className: 'Button Button--text' + (this.mode === 'upload' ? ' is-active' : ''), onclick: () => this.setMode('upload') }, t('mode_upload')),
            ]),

          this.mode === 'connect'
            ? m('.ImporterPage-conn', [
                this.field('host', t('host'), 'text'),
                this.field('port', t('port'), 'text', '90px'),
                this.field('database', src.driver === 'redis' ? t('database_redis') : t('database'), 'text'),
                !src.noUsername && this.field('username', t('username'), 'text'),
                this.field('password', t('password'), 'password'),
                src.needsPrefix && this.field('prefix', t('prefix'), 'text', '160px'),
              ])
            : m('.ImporterPage-upload', [
                m('.Form-group', [
                  m('label', t('dump_file')),
                  m('input.FormControl', { type: 'file', accept: '.sql,.sql.gz,.gz,.sqlite,.sqlite3,.db', onchange: (e: any) => (this.file = e.target.files[0] || null) }),
                  m('p.helpText', t('dump_help')),
                ]),
                src.needsPrefix && this.field('prefix', t('prefix'), 'text', '160px'),
              ]),

          m('.ImporterPage-actions', [
            this.mode === 'connect'
              ? m(Button, { className: 'Button', loading: this.testing, onclick: () => this.test() }, t('test'))
              : m(Button, { className: 'Button', loading: this.uploading, disabled: !this.file, onclick: () => this.upload() }, t('upload')),
            m(Button, { className: 'Button Button--primary', loading: this.starting, disabled: !canStart, onclick: () => this.start() }, t('start')),
          ]),

          this.testResult && this.renderTestResult(this.testResult),
          m('p.helpText.ImporterPage-note', t('shared_note')),
        ]),

      (running || done || failed) && this.renderProgress(running, done, failed),
    ]);
  }

  field(key: keyof SourceConfig, label: string, type: string, width?: string) {
    return m('.Form-group.ImporterPage-field', { style: width ? `max-width:${width}` : '' }, [
      m('label', label),
      m('input.FormControl', { type, value: (this.config as any)[key] ?? '', oninput: (e: any) => ((this.config as any)[key] = e.target.value) }),
    ]);
  }

  renderTestResult(r: TestResult) {
    if (!r.ok) return m('.Alert.Alert--error.ImporterPage-result', r.error || t('test_failed'));
    const counts = r.counts || {};
    return m('.Alert.Alert--success.ImporterPage-result', [
      m('strong', t('test_ok')),
      m('.ImporterPage-counts', Object.keys(counts).map((k) => m('span.ImporterPage-count', [m('b', counts[k].toLocaleString()), ' ', k]))),
    ]);
  }

  renderProgress(running: boolean, done: boolean, failed: boolean) {
    const pct = Math.max(0, Math.min(100, this.status.percent || 0));
    const summary = this.status.summary || {};
    return m('.ImporterPage-progress', [
      m('.ImporterPage-progressHead', [
        m('strong', running ? t('running') : failed ? t('failed') : t('complete')),
        m('span.ImporterPage-pct', pct + '%'),
      ]),
      m('.ImporterPage-bar', m('.ImporterPage-barFill', { className: failed ? 'is-failed' : '', style: `width:${pct}%` })),
      m('p.ImporterPage-status', this.status.status || ''),
      Object.keys(summary).length > 0 &&
        m('.ImporterPage-counts', Object.keys(summary).map((k) => m('span.ImporterPage-count', [m('b', (summary[k] || 0).toLocaleString()), ' ', k]))),
      this.status.lastStatus && m('p.helpText', this.status.lastStatus),
      (done || failed) && m('.ImporterPage-actions', [m(Button, { className: 'Button', onclick: () => this.reset() }, t('import_another'))]),
    ]);
  }
}
