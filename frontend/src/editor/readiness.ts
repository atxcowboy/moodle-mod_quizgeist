type ReadinessQuestion = {
  qtype: string;
  status: string;
};

/**
 * Apply the server's live-readiness rule to one question.
 */
export function isPlayableQuestion(
  question: ReadinessQuestion,
  liveSupportedTypes: readonly string[],
): boolean {
  return question.status === 'ready' && liveSupportedTypes.includes(question.qtype);
}

/**
 * Count questions the editor currently treats as playable.
 */
export function countPlayableQuestions(
  questions: readonly ReadinessQuestion[],
  liveSupportedTypes: readonly string[],
): number {
  return questions.filter((question) => isPlayableQuestion(question, liveSupportedTypes)).length;
}
