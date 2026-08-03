import {liveButton, liveElement} from '../live/dom';
import {
  questionSpeechText,
  renderLiveAggregate,
  renderQuestionSolution,
} from '../live/qtype/registry';
import {createTtsControl, TtsPlayer} from '../live/tts';
import {ReportApi} from './api';
import {renderCompetencePanel} from './competence-panel';
import {
  normaliseRadar,
  renderMisconceptionPanel,
  type MisconceptionRadar,
} from './misconception-panel';
import {
  normaliseScheduleOverview,
  renderSchedulePanel,
  type ScheduleOverview,
} from './schedule-panel';
import type {
  ReportBootstrap,
  ReportConfig,
  ReportData,
  ReportModerationEntry,
  ReportOpenResponse,
  ReportParticipant,
  ReportParticipantSortKey,
  ReportQuestion,
  ReportQuestionOccurrence,
  ReportScope,
  ReportSelection,
  ReportSortDirection,
  ReportSource,
  ReportTimelineEntry,
} from './types';

type StringValues = Record<string, string | number>;

interface SortDefinition {
  key: ReportParticipantSortKey;
  label: string;
}

export class ReportApp {
  private readonly api: ReportApi;
  private readonly tts: TtsPlayer;
  private bootstrap: ReportBootstrap | null = null;
  private combinedDraft = new Set<string>();
  private content: HTMLDivElement | null = null;
  private controls: HTMLElement | null = null;
  private currentRequest: AbortController | null = null;
  private exportActions: HTMLDivElement | null = null;
  private readonly liveRegion: HTMLDivElement;
  private panel: HTMLDivElement | null = null;
  private report: ReportData | null = null;
  private schedule: ScheduleOverview | null = null;

  private radar: MisconceptionRadar | null = null;
  private selection: ReportSelection = {
    groupId: 0,
    scope: 'session',
    sourceKeys: [],
  };
  private sortDirection: ReportSortDirection = 'descending';
  private sortKey: ReportParticipantSortKey = 'points';
  private readonly stage: HTMLDivElement;
  private subtitleNode: HTMLParagraphElement | null = null;
  private tabs: HTMLDivElement | null = null;
  private titleNode: HTMLHeadingElement | null = null;

  public constructor(
    private readonly root: HTMLElement,
    private readonly config: ReportConfig,
  ) {
    this.api = new ReportApi(config);
    this.tts = new TtsPlayer(config);
    this.liveRegion = liveElement('div', 'quizgeist-live-visually-hidden', {
      'aria-atomic': 'true',
      'aria-live': 'polite',
      role: 'status',
    });
    this.stage = liveElement('div', 'quizgeist-report-stage');
  }

  public async init(): Promise<void> {
    this.root.classList.add('quizgeist-report-root');
    this.root.dataset.quizgeistRoot = 'report';
    this.root.dataset.quizgeistTheme = this.config.theme || 'hell';
    this.root.dataset.quizgeistSeason = this.config.season || 'herbst';
    this.root.dataset.reportViewer = this.config.viewerKind;
    this.root.replaceChildren(this.liveRegion, this.stage);
    await this.loadBootstrap();
  }

  private text(
    key: string,
    fallback: string,
    values: StringValues = {},
  ): string {
    let resolved = this.config.strings[key] || fallback;
    Object.entries(values).forEach(([name, value]) => {
      // Both notations: Moodle writes `{$a->name}` in the language file, the
      // TypeScript fallbacks write `{$name}`. Substituting only the second
      // one leaves a raw placeholder in the report (found while building C3).
      resolved = resolved.split(`{$a->${name}}`).join(String(value));
      resolved = resolved.split(`{$${name}}`).join(String(value));
    });
    if ('a' in values) {
      resolved = resolved.split('{$a}').join(String(values.a));
    }
    return resolved;
  }

  private announce(message: string): void {
    this.liveRegion.textContent = '';
    window.requestAnimationFrame(() => {
      this.liveRegion.textContent = message;
    });
  }

  private replaceStage(content: HTMLElement, focus = true): void {
    this.stage.replaceChildren(content);
    if (focus) {
      window.setTimeout(() => {
        content.querySelector<HTMLElement>('[data-report-heading]')?.focus();
      }, 0);
    }
  }

  private loadingState(message: string): HTMLElement {
    const state = liveElement('section', 'quizgeist-report-state', {
      'aria-live': 'polite',
      role: 'status',
    });
    state.append(
      liveElement('span', 'quizgeist-report-spinner', {'aria-hidden': 'true'}),
      liveElement('p', '', {text: message}),
    );
    return state;
  }

  private errorState(message: string, retry: () => void): HTMLElement {
    const state = liveElement(
      'section',
      'quizgeist-report-state quizgeist-report-state--error',
      {role: 'alert'},
    );
    const heading = liveElement('h3', '', {
      'data-report-heading': true,
      tabindex: -1,
      text: this.text(
        'report:error:title',
        'Der Bericht konnte nicht geladen werden',
      ),
    });
    const button = this.button(
      this.text('report:action:retry', 'Erneut versuchen'),
      'secondary',
    );
    button.addEventListener('click', retry);
    state.append(heading, liveElement('p', '', {text: message}), button);
    return state;
  }

  private button(
    label: string,
    variant: 'primary' | 'secondary' | 'quiet' = 'primary',
  ): HTMLButtonElement {
    return liveButton(
      label,
      `quizgeist-report-button quizgeist-report-button--${variant}`,
    );
  }

  private async loadBootstrap(): Promise<void> {
    this.currentRequest?.abort();
    const controller = new AbortController();
    this.currentRequest = controller;
    this.replaceStage(this.loadingState(this.text(
      'report:loading',
      'Berichtsquellen werden geladen …',
    )), false);
    try {
      const bootstrap = await this.api.bootstrap(controller.signal);
      if (controller.signal.aborted) {
        return;
      }
      this.bootstrap = bootstrap;
      this.root.dataset.reportViewer = bootstrap.viewer.kind;
      this.selection = this.initialSelection(bootstrap);
      this.combinedDraft = new Set(
        this.selection.scope === 'course'
          ? bootstrap.defaults.sourceKeys
          : this.selection.sourceKeys,
      );
      this.renderLayout();
      await this.loadReport();
    } catch (error) {
      if (this.isAbortError(error)) {
        return;
      }
      this.replaceStage(this.errorState(
        this.api.errorMessage(error),
        () => void this.loadBootstrap(),
      ));
    }
  }

  private initialSelection(bootstrap: ReportBootstrap): ReportSelection {
    const available = new Set(bootstrap.sources.map((source) => source.key));
    const sourceKeys = bootstrap.defaults.sourceKeys
      .filter((key) => available.has(key));
    const initialScope = bootstrap.defaults.scope;
    const groups = initialScope === 'course'
      ? bootstrap.courseGroups
      : bootstrap.groups;
    const defaultGroup = initialScope === 'course'
      ? bootstrap.courseDefaultGroupId
      : bootstrap.defaults.groupId;
    const groupIds = new Set(groups.map((group) => group.id));
    const groupId = defaultGroup === 0 || groupIds.has(defaultGroup)
      ? defaultGroup
      : groups[0]?.id || 0;
    if (initialScope === 'course') {
      return {groupId, scope: 'course', sourceKeys: []};
    }
    if (initialScope === 'combined') {
      const combinedKeys = [...sourceKeys];
      bootstrap.sources.forEach((source) => {
        if (combinedKeys.length < 2 && !combinedKeys.includes(source.key)) {
          combinedKeys.push(source.key);
        }
      });
      return {
        groupId,
        scope: 'combined',
        sourceKeys: combinedKeys,
      };
    }
    return {
      groupId,
      scope: 'session',
      sourceKeys: [
        sourceKeys[0] || bootstrap.sources[0]?.key || '',
      ].filter((key) => key !== ''),
    };
  }

  private renderLayout(): void {
    const shell = liveElement('section', 'quizgeist-report-view', {
      'aria-labelledby': 'quizgeist-report-title',
      'data-report-view': true,
    });
    const header = liveElement('header', 'quizgeist-report-header');
    const headingGroup = liveElement('div', 'quizgeist-report-header__copy');
    this.titleNode = liveElement('h3', 'quizgeist-report-title', {
      'data-report-heading': true,
      id: 'quizgeist-report-title',
      tabindex: -1,
      text: this.text('report:title', 'Berichte'),
    });
    this.subtitleNode = liveElement('p', 'quizgeist-report-subtitle', {
      text: this.text(
        'report:subtitle',
        'Ergebnisse nach Session, Zuweisung oder Kurs auswerten.',
      ),
    });
    headingGroup.append(this.titleNode, this.subtitleNode);
    this.exportActions = liveElement('div', 'quizgeist-report-export-actions', {
      'aria-label': this.text('report:export:label', 'Bericht exportieren'),
    });
    header.append(headingGroup, this.exportActions);

    this.tabs = liveElement('div', 'quizgeist-report-tabs', {
      'aria-label': this.text('report:scope:label', 'Berichtsumfang'),
      role: 'tablist',
    });
    this.panel = liveElement('div', 'quizgeist-report-panel', {
      id: 'quizgeist-report-panel',
      role: 'tabpanel',
    });
    this.controls = liveElement('section', 'quizgeist-report-filters', {
      'aria-label': this.text('report:filters:label', 'Bericht filtern'),
    });
    this.content = liveElement('div', 'quizgeist-report-content');
    this.panel.append(this.controls, this.content);
    shell.append(
      header,
      this.tabs,
      this.panel,
    );
    this.replaceStage(shell);
    this.renderTabs();
    this.renderControls();
    this.renderExportActions();
  }

  private renderTabs(): void {
    if (!this.tabs) {
      return;
    }
    const scopes: Array<{scope: ReportScope; label: string}> = [
      {
        scope: 'session',
        label: this.text('report:tab:session', 'Session'),
      },
    ];
    if (this.config.reportsAddonInstalled) {
      scopes.push({
        scope: 'combined',
        label: this.text('report:tab:combined', 'Kombiniert'),
      }, {
        scope: 'course',
        label: this.text('report:tab:course', 'Kurs'),
      });
    }
    const buttons = scopes.map(({scope, label}) => {
      const selected = this.selection.scope === scope;
      const button = this.button(label, 'secondary');
      button.classList.add('quizgeist-report-tab');
      button.dataset.reportScope = scope;
      button.id = `quizgeist-report-tab-${scope}`;
      button.setAttribute('aria-controls', 'quizgeist-report-panel');
      button.setAttribute('aria-selected', selected ? 'true' : 'false');
      button.setAttribute('role', 'tab');
      button.tabIndex = selected ? 0 : -1;
      button.addEventListener('click', () => {
        void this.changeScope(scope);
      });
      button.addEventListener('keydown', (event) => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
          return;
        }
        event.preventDefault();
        const currentIndex = scopes.findIndex((entry) => entry.scope === scope);
        const targetIndex = event.key === 'Home'
          ? 0
          : event.key === 'End'
            ? scopes.length - 1
            : (currentIndex + (event.key === 'ArrowRight' ? 1 : -1)
              + scopes.length) % scopes.length;
        const targetScope = scopes[targetIndex]?.scope || 'session';
        void this.changeScope(targetScope, true);
      });
      return button;
    });
    this.tabs.replaceChildren(...buttons);
    this.panel?.setAttribute(
      'aria-labelledby',
      `quizgeist-report-tab-${this.selection.scope}`,
    );
  }

  private async changeScope(
    nextScope: ReportScope,
    focusTab = false,
  ): Promise<void> {
    if (!this.bootstrap) {
      return;
    }
    const nextKeys = this.sourceKeysForScope(nextScope);
    const availableGroups = nextScope === 'course'
      ? this.bootstrap.courseGroups
      : this.bootstrap.groups;
    const availableGroupIds = new Set(availableGroups.map(({id}) => id));
    const nextDefault = nextScope === 'course'
      ? this.bootstrap.courseDefaultGroupId
      : this.bootstrap.defaults.groupId;
    const canKeepAll = nextScope !== 'course'
      || this.bootstrap.courseCanSelectAllGroups;
    const nextGroupId = (
      this.selection.groupId === 0 && canKeepAll
    ) || availableGroupIds.has(this.selection.groupId)
      ? this.selection.groupId
      : nextDefault;
    this.selection = {
      ...this.selection,
      groupId: nextGroupId,
      scope: nextScope,
      sourceKeys: nextKeys,
    };
    if (nextScope === 'combined') {
      this.combinedDraft = new Set(nextKeys);
    }
    this.renderTabs();
    this.renderControls();
    this.renderExportActions();
    if (focusTab) {
      this.tabs?.querySelector<HTMLElement>(
        `[data-report-scope="${nextScope}"]`,
      )?.focus();
    }
    await this.loadReport();
  }

  private sourceKeysForScope(nextScope: ReportScope): string[] {
    const sources = this.bootstrap?.sources || [];
    if (nextScope === 'course') {
      return [];
    }
    if (nextScope === 'session') {
      const current = this.selection.sourceKeys.find((key) => (
        sources.some((source) => source.key === key)
      ));
      return [current || sources[0]?.key || ''].filter((key) => key !== '');
    }
    const selected = [...this.combinedDraft].filter((key) => (
      sources.some((source) => source.key === key)
    ));
    if (selected.length >= 2) {
      return selected;
    }
    const fallback = [...new Set([
      ...selected,
      ...this.selection.sourceKeys.filter((key) => (
      sources.some((source) => source.key === key)
      )),
    ])];
    sources.forEach((source) => {
      if (fallback.length < 2 && !fallback.includes(source.key)) {
        fallback.push(source.key);
      }
    });
    return fallback;
  }

  private renderControls(): void {
    if (!this.controls || !this.bootstrap) {
      return;
    }
    const wrapper = liveElement('div', 'quizgeist-report-filter-grid');
    if (this.selection.scope === 'course') {
      wrapper.append(liveElement('p', 'quizgeist-report-filter-copy', {
        text: this.text(
          'report:scope:course:description',
          'Der Kursbericht fasst alle für Sie sichtbaren Quizgeist-Aktivitäten zusammen.',
        ),
      }));
    } else {
      wrapper.append(this.renderSourceControl());
    }
    const groupControl = this.renderGroupControl();
    if (groupControl) {
      wrapper.append(groupControl);
    }
    this.controls.replaceChildren(wrapper);
  }

  private renderSourceControl(): HTMLElement {
    const combined = this.selection.scope === 'combined';
    const fieldset = liveElement('fieldset', 'quizgeist-report-source-fieldset');
    fieldset.append(liveElement('legend', 'quizgeist-report-filter-label', {
      text: this.config.selfstudyAddonInstalled
        ? this.text(
          'report:filter:sources',
          combined
            ? 'Sessions und Zuweisungen kombinieren'
            : 'Session oder Zuweisung',
        )
        : this.text('report:filter:sessions', 'Sessions'),
    }));
    const list = liveElement('div', 'quizgeist-report-source-list');
    (this.bootstrap?.sources || []).forEach((source) => {
      list.append(this.renderSourceOption(source, combined));
    });
    if (list.childElementCount === 0) {
      list.append(liveElement('p', 'quizgeist-report-filter-copy', {
        text: this.text(
          'report:sources:empty',
          'Es stehen noch keine Berichtsquellen zur Verfügung.',
        ),
      }));
    }
    fieldset.append(list);
    if (combined) {
      const actions = liveElement('div', 'quizgeist-report-filter-actions');
      const apply = this.button(
        this.text('report:action:apply', 'Auswahl anwenden'),
      );
      apply.dataset.reportApply = '';
      apply.disabled = this.combinedDraft.size < 2;
      apply.addEventListener('click', () => {
        if (this.combinedDraft.size < 2) {
          return;
        }
        this.selection = {
          ...this.selection,
          sourceKeys: [...this.combinedDraft],
        };
        this.renderExportActions();
        void this.loadReport();
      });
      actions.append(
        liveElement('p', 'quizgeist-report-filter-hint', {
          'aria-live': 'polite',
          'data-report-source-count': true,
          text: this.text(
            'report:sources:selected',
            '{$count} Quellen ausgewählt',
            {count: this.combinedDraft.size},
          ),
        }),
        apply,
      );
      fieldset.append(actions);
    }
    return fieldset;
  }

  private renderSourceOption(
    source: ReportSource,
    combined: boolean,
  ): HTMLElement {
    const label = liveElement('label', 'quizgeist-report-source-option');
    const selected = combined
      ? this.combinedDraft.has(source.key)
      : this.selection.sourceKeys[0] === source.key;
    const input = liveElement('input', '', {
      checked: selected,
      name: combined ? `report-source-${source.key}` : 'report-source',
      type: combined ? 'checkbox' : 'radio',
      value: source.key,
    });
    input.dataset.reportSourceKey = source.key;
    input.dataset.reportSource = source.key;
    input.dataset.reportSourceKind = source.kind;
    input.dataset.reportSourceId = String(source.id);
    input.addEventListener('change', () => {
      if (combined) {
        if (input.checked) {
          this.combinedDraft.add(source.key);
        } else {
          this.combinedDraft.delete(source.key);
        }
        const apply = this.controls?.querySelector<HTMLButtonElement>(
          '[data-report-apply]',
        );
        if (apply) {
          apply.disabled = this.combinedDraft.size < 2;
        }
        const count = this.controls?.querySelector<HTMLElement>(
          '[data-report-source-count]',
        );
        if (count) {
          count.textContent = this.text(
            'report:sources:selected',
            '{$count} Quellen ausgewählt',
            {count: this.combinedDraft.size},
          );
        }
        this.renderExportActions();
        return;
      }
      if (input.checked) {
        this.selection = {
          ...this.selection,
          sourceKeys: [source.key],
        };
        this.renderExportActions();
        void this.loadReport();
      }
    });
    const copy = liveElement('span', 'quizgeist-report-source-option__copy');
    copy.append(
      liveElement('strong', '', {text: source.label}),
      liveElement('small', '', {
        text: this.sourceMeta(source),
      }),
    );
    label.append(input, copy);
    return label;
  }

  private sourceMeta(source: ReportSource): string {
    const parts = [
      source.instanceName,
      source.kind === 'assignment'
        ? this.text('report:source:assignment', 'Zuweisung')
        : this.text('report:source:session', 'Live-Session'),
      this.formatDate(source.startedAtMs),
    ].filter((part) => part !== '');
    return parts.join(' · ');
  }

  private renderGroupControl(): HTMLElement | null {
    const course = this.selection.scope === 'course';
    const groups = course
      ? this.bootstrap?.courseGroups || []
      : this.bootstrap?.groups || [];
    if (this.bootstrap?.viewer.kind === 'student'
        || !this.bootstrap?.viewer.canViewOthers
        || groups.length === 0) {
      return null;
    }
    const maySelectAll = course
      ? Boolean(this.bootstrap?.courseCanSelectAllGroups)
      : this.bootstrap?.defaults.groupId === 0
        || this.selection.groupId === 0
        || groups.some((group) => group.id === 0);
    const uniqueGroups = groups.filter((group, index, all) => (
      all.findIndex((candidate) => candidate.id === group.id) === index
    ));
    if (uniqueGroups.length === 0 && !maySelectAll) {
      return null;
    }
    const control = liveElement('div', 'quizgeist-report-group-filter');
    const id = 'quizgeist-report-group';
    const label = liveElement('label', 'quizgeist-report-filter-label', {
      for: id,
      text: this.text('report:filter:group', 'Gruppe'),
    });
    const select = liveElement('select', 'quizgeist-report-select', {
      id,
      'data-report-group': true,
    });
    if (maySelectAll && !uniqueGroups.some((group) => group.id === 0)) {
      select.append(liveElement('option', '', {
        selected: this.selection.groupId === 0,
        text: this.text(
          'report:group:all',
          'Alle sichtbaren Gruppen',
        ),
        value: 0,
      }));
    }
    uniqueGroups.forEach((group) => {
      select.append(liveElement('option', '', {
        selected: this.selection.groupId === group.id,
        text: group.name,
        value: group.id,
      }));
    });
    select.addEventListener('change', () => {
      const groupId = Number(select.value);
      if (!Number.isInteger(groupId) || groupId < 0) {
        return;
      }
      this.selection = {...this.selection, groupId};
      this.renderExportActions();
      void this.loadReport();
    });
    control.append(label, select);
    return control;
  }

  private renderExportActions(): void {
    if (!this.exportActions || !this.bootstrap) {
      return;
    }
    const canExport = this.bootstrap.viewer.canExport
      && this.config.exportUrl !== ''
      && this.validSelection()
      && (
        this.selection.scope !== 'combined'
        || this.combinedDraft.size >= 2
      );
    if (!canExport) {
      this.exportActions.replaceChildren();
      this.exportActions.hidden = true;
      return;
    }
    this.exportActions.hidden = false;
    const csv = this.exportLink('csv');
    this.exportActions.replaceChildren(
      ...(this.config.reportsAddonInstalled
        ? [this.exportLink('xlsx'), csv]
        : [csv]),
    );
  }

  private exportLink(format: 'csv' | 'xlsx'): HTMLAnchorElement {
    const label = format === 'csv'
      ? this.text('report:action:csv', 'CSV herunterladen')
      : this.text('report:action:xlsx', 'XLSX herunterladen');
    const link = liveElement('a', 'quizgeist-report-button quizgeist-report-button--secondary', {
      'data-report-export': format,
      download: true,
      href: this.exportHref(format),
      text: label,
      ...(format === 'csv' ? {
        title: this.text(
          'report:action:csv:hint',
          'CSV verwendet Semikolon und deutsches Dezimalkomma; XLSX bleibt für Tabellenkalkulationen empfohlen.',
        ),
      } : {}),
    });
    return link;
  }

  private exportHref(format: 'csv' | 'xlsx'): string {
    const url = new URL(this.config.exportUrl, window.location.href);
    if (!url.searchParams.has('id')) {
      url.searchParams.set('id', String(this.config.cmid));
    }
    url.searchParams.set('format', format);
    url.searchParams.set('scope', this.selection.scope);
    url.searchParams.set('groupid', String(this.selection.groupId));
    url.searchParams.set('sesskey', this.config.sesskey);
    url.searchParams.delete('sourcekeys');
    url.searchParams.delete('sourcekeys[]');
    this.selection.sourceKeys.forEach((sourceKey) => {
      url.searchParams.append('sourcekeys[]', sourceKey);
    });
    return url.toString();
  }

  private async loadReport(): Promise<void> {
    if (!this.content) {
      return;
    }
    this.tts.stop();
    if (!this.validSelection()) {
      this.currentRequest?.abort();
      this.report = null;
      this.renderExportActions();
      this.content.replaceChildren(this.emptyState(this.text(
        'report:empty:selection',
        this.selection.scope === 'combined'
          ? 'Wählen Sie mindestens zwei Berichtsquellen aus.'
          : 'Wählen Sie eine Berichtsquelle aus.',
      )));
      this.announce(this.text(
        'report:empty:description',
        'Wählen Sie genügend Berichtsquellen aus.',
      ));
      return;
    }
    this.currentRequest?.abort();
    const controller = new AbortController();
    this.currentRequest = controller;
    this.content.replaceChildren(this.loadingState(this.text(
      'report:loading',
      'Bericht wird berechnet …',
    )));
    this.announce(this.text('report:loading', 'Bericht wird berechnet …'));
    try {
      const report = await this.api.data(this.selection, controller.signal);
      if (controller.signal.aborted) {
        return;
      }
      this.report = report;
      this.selection = report.selection;
      if (this.selection.scope === 'combined') {
        this.combinedDraft = new Set(this.selection.sourceKeys);
      }
      this.renderTabs();
      this.renderControls();
      this.renderExportActions();
      await this.loadSchedule(controller.signal);
      await this.loadRadar(controller.signal);
      this.renderReport(report);
      this.announce(this.text(
        'report:loaded',
        'Bericht „{$title}“ wurde geladen.',
        {title: report.title || this.text('report:title', 'Berichte')},
      ));
    } catch (error) {
      if (this.isAbortError(error)) {
        return;
      }
      this.report = null;
      this.content.replaceChildren(this.errorState(
        this.api.errorMessage(error),
        () => void this.loadReport(),
      ));
      this.announce(this.api.errorMessage(error));
    }
  }

  /**
   * Fetch the teacher's due-topics overview, tolerating its absence.
   *
   * The action belongs to the self-study addon and additionally requires
   * mod/quizgeist:viewschedule. A missing action or a missing permission is
   * not an error the reader has to read about — the panel simply does not
   * appear, exactly like every other capability-bound surface.
   */
  /**
   * Fetch the F5 misconception radar, tolerating its absence.
   *
   * Same rule as the due-topics panel: the action belongs to the reports
   * addon and to mod/quizgeist:viewreports. A missing action is not an error
   * a reader has to read about — the panel simply does not appear.
   */
  private async loadRadar(signal?: AbortSignal): Promise<void> {
    this.radar = null;
    if (!this.config.reportsAddonInstalled
        || this.config.viewerKind !== 'teacher') {
      return;
    }
    try {
      this.radar = normaliseRadar(await this.api.misconceptions(signal));
    } catch (_error) {
      this.radar = null;
    }
  }

  private async loadSchedule(signal?: AbortSignal): Promise<void> {
    this.schedule = null;
    if (!this.config.selfstudyAddonInstalled
        || this.config.viewerKind !== 'teacher') {
      return;
    }
    try {
      this.schedule = normaliseScheduleOverview(
        await this.api.schedule(signal),
      );
    } catch (_error) {
      this.schedule = null;
    }
  }

  private renderReport(report: ReportData): void {
    if (!this.content) {
      return;
    }
    if (this.titleNode) {
      this.titleNode.textContent = report.title
        || this.text('report:title', 'Berichte');
    }
    if (this.subtitleNode) {
      this.subtitleNode.textContent = report.subtitle || this.selectionSummary();
    }
    if (this.isEmpty(report)) {
      this.content.replaceChildren(this.emptyState());
      return;
    }
    const fragment = document.createDocumentFragment();
    fragment.append(this.renderKpis(report));
    fragment.append(liveElement('p', 'quizgeist-report-filter-hint', {
      text: this.text(
        'report:kpis:gradingnote',
        'Diese Nutzungskennzahlen sind unabhängig von der Moodle-Notenberechnung.',
      ),
    }));
    if (report.omittedSources.length > 0) {
      fragment.append(liveElement('p', 'quizgeist-report-filter-hint', {
        role: 'status',
        text: this.text(
          'report:sources:omitted',
          '{$count} Aktivitäten wurden ausgelassen, weil die gewählte Gruppe dort nicht verfügbar ist.',
          {count: report.omittedSources.length},
        ),
      }));
    }
    if (this.selection.scope === 'course') {
      fragment.append(this.renderTimeline(report.timeline));
    }
    // F6: die Kompetenzachse erscheint nur, wenn der Server sie geliefert hat.
    // Ohne reports-Addon gibt es sie gar nicht — kein gesperrter Koeder.
    const competences = renderCompetencePanel(
      report.competences,
      (key, fallback, values) => this.text(key, fallback, values),
    );
    if (competences !== null) {
      fragment.append(competences);
    }
    // F3: das Faelligkeits-Dashboard haengt am selfstudy-Addon und wird
    // nachgeladen; bis dahin fehlt es, statt leer zu behaupten.
    if (this.schedule !== null) {
      fragment.append(renderSchedulePanel(
        this.schedule,
        (key, fallback, values) => this.text(key, fallback, values),
      ));
    }
    // F5: der Radar erscheint nur, wenn die Lehrkraft ueberhaupt Etiketten
    // vergeben hat — eine leere Ueberschrift waere eine verschlossene Tuer
    // mit Schild.
    if (this.radar !== null) {
      const radar = renderMisconceptionPanel(
        this.radar,
        (key, fallback, values) => this.text(key, fallback, values),
      );
      if (radar !== null) {
        fragment.append(radar);
      }
    }
    fragment.append(
      this.renderQuestions(report.questions),
      this.renderParticipants(report),
      this.renderOpenReview(report),
    );
    this.content.replaceChildren(fragment);
  }

  private selectionSummary(): string {
    const selected = this.selection.sourceKeys.length;
    if (this.selection.scope === 'course') {
      return this.text(
        'report:scope:course:summary',
        'Alle sichtbaren Quizgeist-Aktivitäten des Kurses',
      );
    }
    if (this.selection.scope === 'combined') {
      return this.text(
        'report:scope:combined:summary',
        '{$count} Quellen kombiniert',
        {count: selected},
      );
    }
    const source = this.bootstrap?.sources.find(
      (entry) => entry.key === this.selection.sourceKeys[0],
    );
    return source?.label || this.text('report:tab:session', 'Session');
  }

  private isEmpty(report: ReportData): boolean {
    return report.questions.length === 0
      && report.participants.length === 0
      && report.openResponses.length === 0
      && report.moderationTrail.length === 0
      && report.timeline.length === 0;
  }

  private emptyState(description = ''): HTMLElement {
    const state = liveElement('section', 'quizgeist-report-empty', {
      'data-report-empty': true,
    });
    const heading = liveElement('h4', '', {
      'data-report-heading': true,
      tabindex: -1,
      text: this.text('report:empty:title', 'Noch keine Berichtsdaten'),
    });
    state.append(
      liveElement('span', 'quizgeist-report-empty__illustration', {
        'aria-hidden': 'true',
      }),
      heading,
      liveElement('p', '', {
        text: description || this.text(
          'report:empty:description',
          'Nach der ersten Session oder Zuweisung erscheinen die Ergebnisse hier.',
        ),
      }),
    );
    return state;
  }

  private renderKpis(report: ReportData): HTMLElement {
    const summary = report.summary;
    const list = liveElement('dl', 'quizgeist-report-kpis', {
      'aria-label': this.text('report:kpis:label', 'Kennzahlen'),
    });
    const pointsPercent = this.formatPercent(summary.pointsPercent);
    list.append(this.kpi(
      'points-percent',
      this.text('report:kpi:averagepoints', 'Punktequote'),
      pointsPercent,
      summary.pointsPercent,
    ));

    const averageRate = this.formatPercent(summary.averageCorrectRate);
    list.append(this.kpi(
      'correct-rate',
      this.text('report:kpi:correctrate', 'Ø Richtigquote'),
      averageRate,
      summary.averageCorrectRate,
    ));

    const participation = summary.eligible > 0
      ? `${this.formatInteger(summary.participating)} / ${this.formatInteger(summary.eligible)}`
      : this.formatInteger(summary.participating);
    list.append(this.kpi(
      'participation',
      this.text('report:kpi:participation', 'Teilnahme'),
      participation,
      summary.participating,
    ));

    if (this.config.reportsAddonInstalled) {
      const hardest = summary.hardestQuestion;
      const hardestCard = this.kpi(
        'hardest-question',
        this.text('report:kpi:hardest', 'Schwierigste Frage'),
        hardest?.title || '–',
        hardest?.rootId || null,
        hardest?.correctRate === null || hardest?.correctRate === undefined
          ? ''
          : this.text(
            'report:kpi:hardest:rate',
            '{$rate} richtig',
            {rate: this.formatPercent(hardest.correctRate)},
          ),
      );
      if (hardest) {
        const questionIndex = report.questions.findIndex((question) => (
          question.rootKey === hardest.rootKey
          || question.rootId === hardest.rootId
        ));
        const value = hardestCard.querySelector<HTMLElement>(
          '.quizgeist-report-kpi__value',
        );
        if (value && questionIndex >= 0) {
          const link = liveElement('a', 'quizgeist-report-kpi__link', {
            href: `#quizgeist-report-question-${questionIndex}`,
            text: hardest.title,
          });
          value.replaceChildren(link);
        }
      }
      list.append(hardestCard);
    }
    return list;
  }

  private renderTimeline(entries: ReportTimelineEntry[]): HTMLElement {
    const section = liveElement('section', 'quizgeist-report-section', {
      'aria-labelledby': 'quizgeist-report-timeline-title',
      'data-report-timeline': true,
    });
    section.append(liveElement('h4', 'quizgeist-report-section__title', {
      id: 'quizgeist-report-timeline-title',
      text: this.text(
        'report:timeline:title',
        'Entwicklung im Kurs',
      ),
    }));
    if (entries.length === 0) {
      section.append(liveElement('p', 'quizgeist-report-inline-empty', {
        text: this.text(
          'report:timeline:empty',
          'Für diesen Kurs liegen noch keine Verlaufsdaten vor.',
        ),
      }));
      return section;
    }
    const region = liveElement('div', 'quizgeist-report-table-region', {
      'aria-label': this.text(
        'report:timeline:tablelabel',
        'Entwicklung über Quizgeist-Aktivitäten',
      ),
      role: 'region',
      tabindex: 0,
    });
    const table = liveElement(
      'table',
      'quizgeist-report-table quizgeist-report-table--timeline',
      {'data-report-timeline-table': true},
    );
    table.append(liveElement('caption', 'quizgeist-live-visually-hidden', {
      text: this.text(
        'report:timeline:caption',
        'Durchschnittswerte je Quizgeist-Quelle in zeitlicher Reihenfolge',
      ),
    }));
    const head = liveElement('thead');
    const headerRow = liveElement('tr');
    [
      this.text('report:timeline:source', 'Quelle'),
      this.text('report:timeline:date', 'Datum'),
      this.text('report:timeline:averagepoints', 'Punktequote'),
      this.text('report:timeline:correctrate', 'Ø Richtigquote'),
      this.text('report:timeline:participants', 'Teilnehmende'),
    ].forEach((label) => {
      headerRow.append(liveElement('th', '', {
        scope: 'col',
        text: label,
      }));
    });
    head.append(headerRow);
    const body = liveElement('tbody');
    entries.forEach((entry) => {
      body.append(this.timelineRow(entry));
    });
    table.append(head, body);
    region.append(table);
    section.append(region);
    return section;
  }

  private timelineRow(entry: ReportTimelineEntry): HTMLTableRowElement {
    const row = liveElement('tr', '', {
      'data-report-instance-name': entry.instanceName,
      'data-report-quizgeist-id': entry.quizgeistId > 0 ? entry.quizgeistId : '',
      'data-report-source-key': entry.sourceKey,
      'data-report-source-kind': entry.kind,
      'data-report-timeline-row': true,
    });
    const source = liveElement('th', 'quizgeist-report-timeline-source', {
      'data-report-timeline-source': true,
      scope: 'row',
    });
    source.append(liveElement('strong', '', {text: entry.sourceLabel}));
    if (entry.instanceName !== '' && entry.instanceName !== entry.sourceLabel) {
      source.append(liveElement('small', '', {text: entry.instanceName}));
    }
    row.append(
      source,
      liveElement('td', 'quizgeist-report-timeline-date', {
        'data-report-timeline-date': true,
        text: this.formatDate(entry.timestampMs) || '–',
      }),
      liveElement('td', 'quizgeist-report-table__number', {
        'data-report-timeline-points': true,
        text: this.formatPercent(entry.pointsPercent),
      }),
      liveElement('td', 'quizgeist-report-table__number', {
        'data-report-timeline-correct': true,
        text: this.formatPercent(entry.averageCorrectRate),
      }),
      liveElement('td', 'quizgeist-report-table__number', {
        'data-report-timeline-participants': true,
        text: this.formatInteger(entry.participantCount),
      }),
    );
    return row;
  }

  private kpi(
    key: string,
    label: string,
    value: string,
    rawValue: number | null,
    detail = '',
  ): HTMLElement {
    const card = liveElement('div', 'quizgeist-report-kpi', {
      'data-report-kpi': key,
      'data-report-value': rawValue === null ? '' : rawValue,
    });
    card.append(
      liveElement('dt', 'quizgeist-report-kpi__label', {text: label}),
      liveElement('dd', 'quizgeist-report-kpi__value', {text: value}),
    );
    if (detail !== '') {
      card.append(liveElement('span', 'quizgeist-report-kpi__detail', {
        text: detail,
      }));
    }
    return card;
  }

  private renderQuestions(questions: ReportQuestion[]): HTMLElement {
    const section = liveElement('section', 'quizgeist-report-section', {
      'aria-labelledby': 'quizgeist-report-questions-title',
    });
    section.append(liveElement('h4', 'quizgeist-report-section__title', {
      id: 'quizgeist-report-questions-title',
      text: this.text('report:section:questions', 'Fragen im Detail'),
    }));
    section.append(liveElement('p', 'quizgeist-report-filter-hint', {
      text: this.text(
        'report:questions:missingnote',
        'Fehlend zählt nur Personen, die anhand von Beitritts- und Aktivitätszeiten bei der Frage anwesend waren; fehlende wertbare Antworten zählen als falsch.',
      ),
    }));
    section.append(liveElement('p', 'quizgeist-report-filter-hint', {
      text: this.text(
        'report:questions:medianote',
        'Bilder und andere Medien sind in Berichtsdatensätzen bewusst nicht enthalten. Öffnen Sie die Frage im Editor, um den vollständigen Medienkontext zu sehen.',
      ),
    }));
    if (questions.length === 0) {
      section.append(liveElement('p', 'quizgeist-report-inline-empty', {
        text: this.text(
          'report:questions:empty',
          'Für diese Auswahl liegen keine Fragen vor.',
        ),
      }));
      return section;
    }
    const list = liveElement('div', 'quizgeist-report-question-list');
    questions.forEach((question, index) => {
      list.append(this.renderQuestion(question, index));
    });
    section.append(list);
    return section;
  }

  private renderQuestion(
    question: ReportQuestion,
    index: number,
  ): HTMLDetailsElement {
    const details = liveElement('details', 'quizgeist-report-question', {
      'data-report-root-id': question.rootId,
      'data-report-root-key': question.rootKey,
      id: `quizgeist-report-question-${index}`,
      open: index === 0,
    });
    const summary = liveElement('summary', 'quizgeist-report-question__summary');
    const heading = liveElement('span', 'quizgeist-report-question__heading');
    heading.append(
      liveElement('strong', '', {text: question.title}),
      liveElement('small', '', {
        text: this.text(
          'report:question:root',
          'Fragenstamm {$root}',
          {root: question.rootId},
        ),
      }),
    );
    const badges = liveElement('span', 'quizgeist-report-question__badges');
    if (this.config.reportsAddonInstalled && question.difficult) {
      badges.append(liveElement('span', 'quizgeist-report-difficult', {
        text: this.text('report:question:difficult', 'Schwierige Frage'),
      }));
    }
    badges.append(liveElement('span', 'quizgeist-report-question__rate', {
      text: this.formatPercent(question.correctRate),
    }));
    summary.append(heading, badges);

    const body = liveElement('div', 'quizgeist-report-question__body');
    body.append(
      this.renderQuestionMetrics(question),
      this.renderVersionTrail(question),
    );
    const occurrences = liveElement('div', 'quizgeist-report-occurrences');
    if (question.occurrences.length === 0) {
      occurrences.append(liveElement('p', 'quizgeist-report-inline-empty', {
        text: this.text(
          'report:occurrences:empty',
          'Für diese Frage liegt keine Antwortverteilung vor.',
        ),
      }));
    } else {
      question.occurrences.forEach((occurrence) => {
        occurrences.append(this.renderOccurrence(occurrence));
      });
    }
    body.append(occurrences);
    details.append(summary, body);
    return details;
  }

  private renderQuestionMetrics(question: ReportQuestion): HTMLElement {
    const metrics = liveElement('dl', 'quizgeist-report-question-metrics');
    const entries = [
      {
        label: this.text('report:question:points', 'Punkte'),
        value: question.maxPoints > 0
          ? `${this.formatNumber(question.points)} / ${this.formatNumber(question.maxPoints)}`
          : this.formatNumber(question.points),
      },
      {
        label: this.text('report:question:correct', 'Richtig'),
        value: `${this.formatInteger(question.correctCount)} / ${this.formatInteger(question.gradedCount)}`,
      },
      {
        label: this.text('report:question:answers', 'Antworten'),
        value: this.formatInteger(question.responseCount),
      },
      {
        label: this.text('report:question:missing', 'Fehlend'),
        value: this.formatInteger(question.missingCount),
      },
      {
        label: this.text('report:question:time', 'Ø Antwortzeit'),
        value: this.formatDuration(question.averageResponseTimeMs),
      },
    ];
    entries.forEach((entry) => {
      const item = liveElement('div', '');
      item.append(
        liveElement('dt', '', {text: entry.label}),
        liveElement('dd', '', {text: entry.value}),
      );
      metrics.append(item);
    });
    return metrics;
  }

  private renderVersionTrail(question: ReportQuestion): HTMLElement {
    const section = liveElement('section', 'quizgeist-report-versions', {
      'aria-label': this.text(
        'report:versions:label',
        'Gespielte Frageversionen',
      ),
    });
    section.append(liveElement('h5', '', {
      text: this.text('report:question:versions', 'Gespielte Versionen'),
    }));
    const list = liveElement('ul', 'quizgeist-report-version-list');
    question.versions.forEach((version) => {
      const item = liveElement('li', 'quizgeist-report-version', {
        'data-report-question-id': version.questionId,
        'data-report-version': version.version,
      });
      item.append(
        liveElement('strong', '', {
          text: this.text(
            'report:version:label',
            'Version {$version}',
            {version: version.version},
          ),
        }),
        document.createTextNode(` · ${this.qtypeLabel(version.qtype)}`),
        liveElement('small', '', {
          text: this.text(
            'report:version:id',
            'Frage-ID {$id}',
            {id: version.questionId},
          ),
        }),
      );
      list.append(item);
    });
    if (list.childElementCount === 0) {
      list.append(liveElement('li', 'quizgeist-report-inline-empty', {
        text: this.text(
          'report:versions:empty',
          'Keine Versionsangaben vorhanden.',
        ),
      }));
    }
    section.append(list);
    return section;
  }

  private renderOccurrence(
    occurrence: ReportQuestionOccurrence,
  ): HTMLElement {
    const source = this.bootstrap?.sources.find(
      (entry) => entry.key === occurrence.sourceKey,
    );
    const sourceLabel = occurrence.sourceLabel
      || source?.label
      || occurrence.sourceKey;
    const article = liveElement('article', 'quizgeist-report-occurrence', {
      'data-report-occurrence': true,
      'data-report-question-id': occurrence.questionId,
      'data-report-projection-key': occurrence.projectionKey,
      'data-report-source-key': occurrence.sourceKey,
      'data-report-version': occurrence.version,
      'data-report-authoritative': occurrence.authoritative,
      'data-report-occurrence-count': occurrence.occurrenceCount,
      'data-report-stage': occurrence.stage,
    });
    const heading = liveElement('header', 'quizgeist-report-occurrence__header');
    heading.append(
      liveElement('h5', '', {text: sourceLabel}),
      liveElement('p', '', {
        text: [
          this.text(
            'report:version:label',
            'Version {$version}',
            {version: occurrence.version},
          ),
          occurrence.authoritative
            ? this.text(
              'report:visit:authoritative',
              'wertungsrelevant',
            )
            : this.text(
              'report:visit:notauthoritative',
              'neutralisiert – ohne Kennzahlwirkung',
            ),
          this.text(
            'report:occurrences:count',
            '{$count} Vorkommen',
            {count: occurrence.occurrenceCount},
          ),
        ].join(' · '),
      }),
      liveElement('p', 'quizgeist-report-occurrence__question', {
        text: occurrence.question.questionText,
      }),
      createTtsControl(
        this.tts,
        questionSpeechText(occurrence.question),
        this.config,
      ),
    );
    const aggregate = liveElement('div', 'quizgeist-report-occurrence__aggregate');
    try {
      aggregate.append(renderLiveAggregate(
        occurrence.question,
        occurrence.aggregate,
        {
          aggregate: occurrence.aggregate,
          answer: null,
          audience: 'player',
          interactive: false,
          nowMs: Date.now(),
          text: (key, fallback, values = {}) => this.text(key, fallback, values),
        },
      ));
    } catch (_error) {
      aggregate.append(liveElement('p', 'quizgeist-report-inline-empty', {
        text: this.text(
          'report:aggregate:error',
          'Die Antwortverteilung konnte nicht dargestellt werden.',
        ),
      }));
    }
    article.append(heading, aggregate);
    if (occurrence.authoritative) {
      article.append(renderQuestionSolution(occurrence.question, {
        text: (key, fallback, values = {}) => this.text(key, fallback, values),
      }));
    }
    return article;
  }

  private renderParticipants(report: ReportData): HTMLElement {
    const section = liveElement('section', 'quizgeist-report-section', {
      'aria-labelledby': 'quizgeist-report-participants-title',
      'data-report-participants': true,
    });
    const ownOnly = this.bootstrap?.viewer.kind === 'student'
      || !this.bootstrap?.viewer.canViewOthers;
    section.append(liveElement('h4', 'quizgeist-report-section__title', {
      id: 'quizgeist-report-participants-title',
      text: ownOnly
        ? this.text('report:participants:own', 'Meine Ergebnisse')
        : this.text('report:section:participants', 'Teilnehmende'),
    }));
    if (report.participants.length === 0) {
      section.append(liveElement('p', 'quizgeist-report-inline-empty', {
        text: this.text(
          'report:participants:empty',
          'Für diese Auswahl liegen keine Teilnehmendendaten vor.',
        ),
      }));
      return section;
    }
    const region = liveElement('div', 'quizgeist-report-table-region', {
      'aria-label': this.text(
        'report:participants:tablelabel',
        'Ergebnisse der Teilnehmenden',
      ),
      role: 'region',
      tabindex: 0,
    });
    const table = liveElement('table', 'quizgeist-report-table');
    table.append(liveElement('caption', 'quizgeist-live-visually-hidden', {
      text: this.text(
        'report:participants:caption',
        'Name, Nutzerkennung, Punkte, Richtigquote und Antwortzeiten',
      ),
    }));
    const head = liveElement('thead');
    const headerRow = liveElement('tr');
    const definitions: SortDefinition[] = [
      {
        key: 'displayName',
        label: ownOnly
          ? this.text('report:participant:self', 'Ergebnis')
          : this.text('report:table:name', 'Name'),
      },
      {
        key: 'userIdentifier',
        label: this.text('report:table:useridentifier', 'Nutzerkennung'),
      },
      {
        key: 'points',
        label: this.text('report:table:points', 'Punkte'),
      },
      {
        key: 'correctRate',
        label: this.text('report:table:correctrate', 'Richtigquote'),
      },
      {
        key: 'averageResponseTimeMs',
        label: this.text('report:table:averagetime', 'Ø Antwortzeit'),
      },
      {
        key: 'sourceCount',
        label: this.text('report:table:sources', 'Quellen'),
      },
    ];
    definitions.forEach((definition) => {
      headerRow.append(this.sortHeader(definition));
    });
    head.append(headerRow);
    const body = liveElement('tbody');
    this.sortedParticipants(report.participants).forEach((participant) => {
      body.append(this.participantRow(participant));
    });
    table.append(head, body);
    region.append(table);
    section.append(region);
    return section;
  }

  private sortHeader(definition: SortDefinition): HTMLTableCellElement {
    const active = this.sortKey === definition.key;
    const header = liveElement('th', '', {
      'aria-sort': active ? this.sortDirection : 'none',
      scope: 'col',
    });
    const button = liveButton(
      definition.label,
      'quizgeist-report-sort',
      {'data-report-sort': definition.key},
    );
    if (active) {
      button.append(liveElement('span', 'quizgeist-report-sort__direction', {
        'aria-hidden': 'true',
        text: this.sortDirection === 'ascending' ? '↑' : '↓',
      }));
    }
    button.addEventListener('click', () => {
      this.changeSort(definition);
    });
    header.append(button);
    return header;
  }

  private changeSort(definition: SortDefinition): void {
    if (this.sortKey === definition.key) {
      this.sortDirection = this.sortDirection === 'ascending'
        ? 'descending'
        : 'ascending';
    } else {
      this.sortKey = definition.key;
      this.sortDirection = ['displayName', 'userIdentifier'].includes(
        definition.key,
      )
        ? 'ascending'
        : 'descending';
    }
    if (!this.report || !this.content) {
      return;
    }
    const previous = this.content.querySelector<HTMLElement>(
      '[data-report-participants]',
    );
    if (previous) {
      previous.replaceWith(this.renderParticipants(this.report));
      this.content.querySelector<HTMLElement>(
        `[data-report-sort="${definition.key}"]`,
      )?.focus();
    }
    this.announce(this.text(
      'report:participants:sorted',
      'Tabelle nach {$column} {$direction} sortiert.',
      {
        column: definition.label,
        direction: this.sortDirection === 'ascending'
          ? this.text('report:sort:ascending', 'aufsteigend')
          : this.text('report:sort:descending', 'absteigend'),
      },
    ));
  }

  private sortedParticipants(
    participants: ReportParticipant[],
  ): ReportParticipant[] {
    const direction = this.sortDirection === 'ascending' ? 1 : -1;
    return [...participants].sort((left, right) => {
      if (this.sortKey === 'displayName'
          || this.sortKey === 'userIdentifier') {
        const result = left[this.sortKey].localeCompare(
          right[this.sortKey],
          this.config.locale,
          {sensitivity: 'base'},
        );
        return result === 0
          ? left.userId - right.userId
          : direction * result;
      }
      const leftValue = left[this.sortKey];
      const rightValue = right[this.sortKey];
      const leftNumber = typeof leftValue === 'number' ? leftValue : -1;
      const rightNumber = typeof rightValue === 'number' ? rightValue : -1;
      if (leftNumber === rightNumber) {
        return left.displayName.localeCompare(
          right.displayName,
          this.config.locale,
          {sensitivity: 'base'},
        ) || left.userIdentifier.localeCompare(
          right.userIdentifier,
          this.config.locale,
          {sensitivity: 'base'},
        ) || left.userId - right.userId;
      }
      return direction * (leftNumber - rightNumber);
    });
  }

  private participantRow(
    participant: ReportParticipant,
  ): HTMLTableRowElement {
    const row = liveElement('tr', '', {
      'data-report-participant': true,
      'data-report-user-id': participant.userId > 0
        ? participant.userId
        : '',
      'data-report-user-identifier': participant.userIdentifier,
    });
    const name = liveElement('th', 'quizgeist-report-table__name', {
      scope: 'row',
    });
    const profileUrl = this.profileUrl(participant.profileUrl);
    if (profileUrl) {
      name.append(liveElement('a', '', {
        href: profileUrl,
        text: participant.displayName,
      }));
    } else {
      name.textContent = participant.displayName;
    }
    const pointValue = participant.maxPoints > 0
      ? `${this.formatNumber(participant.points)} / ${this.formatNumber(participant.maxPoints)}`
      : this.formatNumber(participant.points);
    row.append(
      name,
      liveElement('td', 'quizgeist-report-table__identifier', {
        'data-report-user-identifier-cell': true,
        text: participant.userIdentifier,
      }),
      liveElement('td', 'quizgeist-report-table__number', {text: pointValue}),
      liveElement('td', 'quizgeist-report-table__number', {
        text: this.formatPercent(participant.correctRate),
      }),
      liveElement('td', 'quizgeist-report-table__number', {
        text: this.formatDuration(participant.averageResponseTimeMs),
      }),
      liveElement('td', 'quizgeist-report-table__number', {
        text: this.formatInteger(participant.sourceCount),
      }),
    );
    return row;
  }

  private profileUrl(value: string | undefined): string {
    if (!value
        || this.bootstrap?.viewer.kind !== 'teacher'
        || !this.bootstrap.viewer.canViewOthers
        || !this.bootstrap.viewer.canViewProfiles) {
      return '';
    }
    try {
      const url = new URL(value, window.location.href);
      return url.origin === window.location.origin
        && ['http:', 'https:'].includes(url.protocol)
        ? url.toString()
        : '';
    } catch (_error) {
      return '';
    }
  }

  private renderOpenReview(report: ReportData): HTMLElement {
    const section = liveElement('section', 'quizgeist-report-section', {
      'aria-labelledby': 'quizgeist-report-review-title',
      'data-report-review': true,
    });
    section.append(liveElement('h4', 'quizgeist-report-section__title', {
      id: 'quizgeist-report-review-title',
      text: this.text(
        this.config.reportsAddonInstalled
          ? 'report:open:title'
          : 'report:open:title:base',
        this.config.reportsAddonInstalled
          ? 'Offene Antworten und Moderation'
          : 'Offene Antworten',
      ),
    }));
    if (report.openResponses.length === 0) {
      section.append(liveElement('p', 'quizgeist-report-inline-empty', {
        text: this.text(
          'report:open:empty',
          'Für diese Auswahl liegen keine offenen Antworten vor.',
        ),
      }));
    } else {
      const list = liveElement('div', 'quizgeist-report-review-list');
      report.openResponses.forEach((response) => {
        list.append(this.openResponseRow(response));
      });
      section.append(list);
      if (report.openResponsesTruncated) {
        section.append(liveElement('p', 'quizgeist-report-filter-hint', {
          text: this.text(
            'report:review:truncated',
            'Die Ansicht ist begrenzt. Der Export enthält alle offenen Antworten.',
          ),
        }));
      }
    }
    if (!this.config.reportsAddonInstalled) {
      return section;
    }
    const moderation = liveElement('section', 'quizgeist-report-moderation', {
      'aria-labelledby': 'quizgeist-report-moderation-title',
    });
    moderation.append(liveElement('h5', '', {
      id: 'quizgeist-report-moderation-title',
      text: this.text('report:moderation:title', 'Moderationsspur'),
    }));
    if (report.moderationTrail.length === 0) {
      moderation.append(liveElement('p', 'quizgeist-report-inline-empty', {
        text: this.text(
          'report:moderation:empty',
          'Keine Moderationsschritte vorhanden.',
        ),
      }));
    } else {
      const list = liveElement('ol', 'quizgeist-report-moderation-list');
      report.moderationTrail.forEach((entry) => {
        list.append(this.moderationRow(entry));
      });
      moderation.append(list);
      if (report.moderationTrailTruncated) {
        moderation.append(liveElement('p', 'quizgeist-report-filter-hint', {
          text: this.text(
            'report:moderation:truncated',
            'Die Ansicht ist begrenzt. Der Export enthält die vollständige Moderationsspur.',
          ),
        }));
      }
    }
    section.append(moderation);
    return section;
  }

  private openResponseRow(response: ReportOpenResponse): HTMLElement {
    const row = liveElement('article', 'quizgeist-report-review-row', {
      'data-report-question-id': response.questionId,
      'data-report-open': true,
      'data-report-review-row': true,
      'data-report-root-id': response.rootId,
      'data-report-source-key': response.sourceKey,
      'data-report-user-id': response.userId > 0 ? response.userId : '',
      'data-report-user-identifier': response.userIdentifier,
      'data-report-version': response.version,
    });
    const header = liveElement('header', 'quizgeist-report-review-row__header');
    const title = response.questionTitle
      || this.text(
        'report:review:question',
        'Frage {$id}',
        {id: response.questionId},
      );
    header.append(
      liveElement('strong', '', {text: title}),
      liveElement('span', 'quizgeist-report-status', {
        text: this.statusLabel(response.status),
      }),
    );
    const meta = [
      response.sourceLabel || response.sourceKey,
      this.qtypeLabel(response.kind),
      `${this.text(
        'report:version:label',
        'Version {$version}',
        {version: response.version},
      )}`,
      response.displayName,
      response.userIdentifier,
      this.formatDate(response.timeCreatedMs),
    ].filter((entry) => entry !== '');
    row.append(
      header,
      liveElement('p', 'quizgeist-report-review-row__meta', {
        text: meta.join(' · '),
      }),
      liveElement('blockquote', 'quizgeist-report-review-row__text', {
        text: response.text,
      }),
    );
    if (!response.metricEligible) {
      row.append(liveElement('p', 'quizgeist-report-review-row__note', {
        text: this.text(
          'report:review:notmetric',
          'Moderationszeile – nicht in Schülerkennzahlen enthalten.',
        ),
      }));
    }
    return row;
  }

  private moderationRow(entry: ReportModerationEntry): HTMLLIElement {
    const row = liveElement('li', 'quizgeist-report-moderation-row', {
      'data-report-moderation-row': true,
      'data-report-moderation': true,
      'data-report-question-id': entry.questionId,
      'data-report-root-id': entry.rootId,
      'data-report-source-key': entry.sourceKey,
      'data-report-version': entry.version,
    });
    const action = this.moderationLabel(entry.action);
    const actor = entry.actorName || this.text(
      'report:moderation:system',
      'Moderation',
    );
    const target = [entry.targetType, entry.targetKey]
      .filter((value) => value !== '')
      .join(' · ');
    row.append(
      liveElement('strong', '', {text: action}),
      liveElement('p', '', {
        text: [
          entry.sourceLabel || entry.sourceKey,
          actor,
          target,
          this.formatDate(entry.timeCreatedMs),
        ].filter((value) => value !== '').join(' · '),
      }),
    );
    return row;
  }

  private statusLabel(status: string): string {
    const fallbacks: Record<string, string> = {
      approved: 'Freigegeben',
      deleted: 'Gelöscht',
      hidden: 'Ausgeblendet',
      pending: 'Ausstehend',
      recorded: 'Erfasst',
      rejected: 'Abgelehnt',
    };
    const normalized = status.toLowerCase();
    return this.text(
      `report:status:${normalized}`,
      fallbacks[normalized] || 'Erfasst',
    );
  }

  private moderationLabel(action: string): string {
    const fallbacks: Record<string, string> = {
      approved: 'Freigegeben',
      deleted: 'Gelöscht',
      grouped: 'Gruppiert',
      hidden: 'Ausgeblendet',
      moderated: 'Moderiert',
      rejected: 'Abgelehnt',
      restored: 'Wiederhergestellt',
    };
    const normalized = action.toLowerCase();
    return this.text(
      `report:moderation:${normalized}`,
      fallbacks[normalized] || 'Moderation',
    );
  }

  private qtypeLabel(type: string): string {
    const fallbacks: Record<string, string> = {
      brainstorm: 'Brainstorming',
      open: 'Offene Frage',
      pin: 'Bildmarkierung',
      poll: 'Umfrage',
      puzzle: 'Sortierung',
      quiz: 'Quiz',
      reveal: 'Aufdecken',
      scale: 'Skala',
      shortanswer: 'Kurzantwort',
      slide: 'Folie',
      slider: 'Schieberegler',
      truefalse: 'Wahr/Falsch',
      wordcloud: 'Wortwolke',
    };
    return this.text(
      `live:qtype:${type}`,
      fallbacks[type] || 'Frage',
    );
  }

  private formatNumber(value: number): string {
    return new Intl.NumberFormat(this.config.locale, {
      maximumFractionDigits: 2,
    }).format(value);
  }

  private formatInteger(value: number): string {
    return new Intl.NumberFormat(this.config.locale, {
      maximumFractionDigits: 0,
    }).format(value);
  }

  private formatPercent(value: number | null): string {
    return value === null
      ? '–'
      : `${new Intl.NumberFormat(this.config.locale, {
        maximumFractionDigits: 1,
      }).format(value)} %`;
  }

  private formatDuration(value: number | null): string {
    if (value === null) {
      return '–';
    }
    return this.text(
      'report:time:seconds',
      '{$seconds} s',
      {
        seconds: new Intl.NumberFormat(this.config.locale, {
          maximumFractionDigits: 1,
        }).format(value / 1000),
      },
    );
  }

  private formatDate(value: number): string {
    if (!Number.isFinite(value) || value <= 0) {
      return '';
    }
    return new Intl.DateTimeFormat(this.config.locale, {
      dateStyle: 'medium',
      timeStyle: 'short',
    }).format(new Date(value));
  }

  private isAbortError(error: unknown): boolean {
    return error instanceof DOMException && error.name === 'AbortError';
  }

  private validSelection(): boolean {
    if (this.selection.scope === 'course') {
      return true;
    }
    if (this.selection.scope === 'combined') {
      return this.selection.sourceKeys.length >= 2;
    }
    return this.selection.sourceKeys.length === 1;
  }
}
