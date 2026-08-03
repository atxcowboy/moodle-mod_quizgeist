import type {ReportCompetence} from './competence-panel';
import type {
  LiveAggregate,
  LiveConfig,
  LiveDistributionEntry,
  LiveQuestion,
  LiveQuestionType,
} from '../live/types';

export const REPORT_SCOPES = ['session', 'combined', 'course'] as const;

export type ReportScope = typeof REPORT_SCOPES[number];
export type ReportViewerKind = 'teacher' | 'student';
export type ReportSourceKind = 'session' | 'assignment';

export interface ReportConfig extends LiveConfig {
  bootstrapAction: string;
  dataAction: string;
  exportUrl: string;
  locale: string;
  reportsAddonInstalled: boolean;
  selfstudyAddonInstalled: boolean;
  viewerKind: ReportViewerKind;
}

export interface ReportViewer {
  canExport: boolean;
  canViewOthers: boolean;
  canViewProfiles: boolean;
  kind: ReportViewerKind;
}

export interface ReportSource {
  cmid: number;
  endedAtMs: number;
  id: number;
  instanceName: string;
  key: string;
  kind: ReportSourceKind;
  label: string;
  quizgeistId: number;
  startedAtMs: number;
  status: string;
}

export interface ReportGroup {
  id: number;
  name: string;
}

export interface ReportSelection {
  groupId: number;
  scope: ReportScope;
  sourceKeys: string[];
}

export interface ReportBootstrap {
  courseCanSelectAllGroups: boolean;
  courseDefaultGroupId: number;
  courseGroups: ReportGroup[];
  defaults: ReportSelection;
  groups: ReportGroup[];
  sources: ReportSource[];
  viewer: ReportViewer;
}

export interface ReportHardestQuestion {
  correctRate: number | null;
  rootId: number;
  rootKey: string;
  title: string;
}

export interface ReportSummary {
  averageCorrectRate: number | null;
  eligible: number;
  hardestQuestion: ReportHardestQuestion | null;
  participating: number;
  participationRate: number | null;
  pointsPercent: number | null;
}

export interface ReportTimelineEntry {
  averageCorrectRate: number | null;
  instanceName: string;
  kind: ReportSourceKind;
  quizgeistId: number;
  participantCount: number;
  pointsPercent: number | null;
  sourceKey: string;
  sourceLabel: string;
  timestampMs: number;
}

export interface ReportQuestionVersion {
  questionId: number;
  questionText: string;
  qtype: LiveQuestionType;
  version: number;
}

export interface ReportQuestionOccurrence {
  aggregate: LiveAggregate | LiveDistributionEntry[] | null;
  authoritative: boolean;
  occurrenceCount: number;
  question: LiveQuestion;
  questionId: number;
  projectionKey: string;
  sourceKey: string;
  sourceLabel: string;
  stage: string;
  version: number;
}

export interface ReportQuestion {
  averageResponseTimeMs: number | null;
  correctCount: number;
  correctRate: number | null;
  difficult: boolean;
  gradedCount: number;
  maxPoints: number;
  missingCount: number;
  quizgeistId: number;
  occurrences: ReportQuestionOccurrence[];
  points: number;
  qtype: LiveQuestionType;
  responseCount: number;
  rootId: number;
  rootKey: string;
  title: string;
  versions: ReportQuestionVersion[];
}

export interface ReportParticipant {
  attemptCount: number;
  averageResponseTimeMs: number | null;
  correctCount: number;
  correctRate: number | null;
  displayName: string;
  gradedCount: number;
  maxPoints: number;
  points: number;
  profileUrl?: string;
  responseCount: number;
  sourceCount: number;
  userId: number;
  userIdentifier: string;
}

export interface ReportOpenResponse {
  authoritative: boolean;
  displayName: string;
  id: string;
  kind: string;
  metricEligible: boolean;
  questionId: number;
  questionTitle: string;
  rootId: number;
  rootKey: string;
  sourceKey: string;
  sourceLabel: string;
  status: string;
  text: string;
  timeCreatedMs: number;
  userId: number;
  userIdentifier: string;
  version: number;
}

export interface ReportModerationEntry {
  action: string;
  actorName: string;
  id: string;
  questionId: number;
  rootId: number;
  rootKey: string;
  sourceKey: string;
  sourceLabel: string;
  targetKey: string;
  targetType: string;
  timeCreatedMs: number;
  version: number;
}

export interface ReportData {
  competences: ReportCompetence[];
  moderationTrailTruncated: boolean;
  moderationTrail: ReportModerationEntry[];
  omittedSources: Array<{
    cmid: number;
    instanceName: string;
    reason: string;
  }>;
  openResponsesTruncated: boolean;
  openResponses: ReportOpenResponse[];
  participants: ReportParticipant[];
  questions: ReportQuestion[];
  selection: ReportSelection;
  subtitle: string;
  summary: ReportSummary;
  timeline: ReportTimelineEntry[];
  title: string;
}

export type {ReportCompetence};

export type ReportParticipantSortKey =
  | 'averageResponseTimeMs'
  | 'correctRate'
  | 'displayName'
  | 'points'
  | 'sourceCount'
  | 'userIdentifier';

export type ReportSortDirection = 'ascending' | 'descending';
