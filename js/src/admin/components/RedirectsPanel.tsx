import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import extractText from 'flarum/common/utils/extractText';
import { redirectState, saveRedirects, type RedirectState } from '../../common/api';

declare const m: any;
const t = (k: string, p?: any): any => app.translator.trans('ernestdefoe-importer.admin.redirects.' + k, p);

/**
 * Keep the old forum's addresses working.
 *
 * 🚨 **The preview is the point of this screen.** Redirects are a promise about
 * every link anybody ever made, and it is a promise nobody can check afterwards
 * — one that resolves nothing looks exactly like one that works, right up until
 * the traffic that used to arrive stops arriving. So the switch sits UNDER a
 * list of real addresses from this site's own import, each resolved for real.
 * Somebody should be able to recognise one of their own URLs before they commit.
 */
export default class RedirectsPanel extends Component<ComponentAttrs, RedirectState | null> {
  state: RedirectState | null = null;
  loading = true;
  saving = false;
  error: string | null = null;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.load();
  }

  load(runId?: number, source?: string) {
    this.loading = true;
    m.redraw();

    redirectState(runId, source)
      .then((s) => {
        this.state = s;
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        // No runs, or the endpoint is unreachable: show nothing rather than an
        // error on a page whose main job is the import itself.
        this.state = null;
        this.loading = false;
        m.redraw();
      });
  }

  save(enabled: boolean) {
    const s = this.state;
    if (!s) return;

    this.saving = true;
    this.error = null;
    m.redraw();

    saveRedirects(enabled, s.selected.runId, s.selected.source)
      .then(() => {
        this.saving = false;
        this.load(s.selected.runId, s.selected.source);
      })
      .catch((e: any) => {
        this.saving = false;
        // The controller refuses combinations that would resolve nothing, and
        // says which — so the message can name the actual problem.
        const reason = e?.response?.reason || 'failed';
        this.error = reason;
        m.redraw();
      });
  }

  view() {
    const s = this.state;

    /*
     * An element while the first request is in flight, never `null`. The panel
     * decides whether to exist by asking the server, so the alternative is a
     * settings page that silently grows a section a moment after it opens.
     */
    if (!s) return m('.ImporterPage.RedirectsPanel', m('p.helpText', t('loading')));

    // Only runs that actually mapped something can drive a redirect.
    const usable = s.runs.filter((r) => r.counts.topic + r.counts.tag + r.counts.user > 0);

    return m('.ImporterPage.RedirectsPanel', [
      m('.ImporterPage-intro', [m('h2', t('title')), m('p.helpText', t('intro'))]),

      usable.length === 0
        ? m('p.helpText.RedirectsPanel-empty', t('no_runs'))
        : m('.ImporterPage-wizard', [
            m('.Form-group', [
              m('label', t('run')),
              m(
                'select.FormControl',
                {
                  value: String(s.selected.runId),
                  onchange: (e: any) => this.load(Number(e.target.value)),
                },
                /*
                 * 🚨 `extractText`, because `trans()` hands back vnodes rather
                 * than a string. An <option> cannot render those, so the whole
                 * dropdown came out BLANK — every run present, none of them
                 * with a name, and nothing in the console to say why.
                 */
                usable.map((r) =>
                  m(
                    'option',
                    { value: String(r.id) },
                    extractText(t('run_option', { id: r.id, source: r.source, topics: r.counts.topic.toLocaleString() }))
                  )
                )
              ),
            ]),

            m('.Form-group', [
              m('label', t('source')),
              m(
                'select.FormControl',
                {
                  value: s.selected.source,
                  onchange: (e: any) => this.load(s.selected.runId, e.target.value),
                },
                s.sources.map((o) => m('option', { value: o.key }, o.label))
              ),
              // 🚨 One importer covers vBulletin 3, 4 and 5, and those serve
              // three completely different URL shapes. Only the person who ran
              // the old site knows which links are out there.
              m('p.helpText', t('source_help')),
            ]),

            !s.known && m('.Alert.Alert--error.RedirectsPanel-alert', t('unknown_source')),

            s.known && this.renderExamples(),

            this.error && m('.Alert.Alert--error.RedirectsPanel-alert', t('error_' + this.error)),

            m('.ImporterPage-actions', [
              m(
                Switch,
                {
                  state: s.enabled,
                  disabled: this.saving || !s.known,
                  onchange: (v: boolean) => this.save(v),
                },
                t(s.enabled ? 'on' : 'off')
              ),
            ]),

            m('p.helpText.ImporterPage-note', t('note')),
          ]),
    ]);
  }

  /**
   * Real addresses from this site's map, resolved.
   *
   * 🚨 Built from rows that exist here, never from invented ids. A preview of
   * made-up URLs proves a regular expression compiles and nothing more; one
   * made of real ids answers the only question being asked, which is whether
   * this will work on THIS forum.
   */
  renderExamples() {
    const ex = this.state?.examples || [];

    if (ex.length === 0) return m('p.helpText', t('no_examples'));

    return m('.RedirectsPanel-examples', [
      m('p.helpText', t('examples_intro')),
      m(
        'table.RedirectsPanel-table',
        m(
          'tbody',
          ex.map((e) =>
            m('tr', { className: e.to ? '' : 'is-unresolved' }, [
              m('td.RedirectsPanel-from', m('code', e.from)),
              m('td.RedirectsPanel-arrow', '→'),
              m('td.RedirectsPanel-to', e.to ? m('code', e.to) : m('span.helpText', t('unresolved'))),
            ])
          )
        )
      ),
    ]);
  }
}
