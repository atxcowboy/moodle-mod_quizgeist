/**
 * Round-based speaking trainer (F12).
 *
 * The loop is: read the task aloud (TTS) -> record -> upload -> poll ->
 * show the feedback and read it aloud. Every step is a discrete request; the
 * only thing that repeats is a cheap state read. There is no socket and no
 * server-sent event anywhere in this module, which is what lets a feature that
 * feels like a conversation live inside the polling rule of HOUSE_RULES.
 *
 * The trainer works WITHOUT a microphone. If `getUserMedia` is missing or the
 * learner declines, the same round runs on typed input and is scored by the
 * same coach. That is not a nicety: without it the feature would be unusable
 * for part of the class, which would make it worse than not shipping it.
 */

import {liveButton, liveElement} from '../live/dom';
import {liveString} from '../live/strings';
import {SpokenAnswerControl, type SpokenAnswerConfig} from '../live/spoken-answer';
import type {UploadedClip} from '../live/recorder';
import {TtsPlayer} from '../live/tts';

export interface SpeakingTurnResult {
  origin: string;
  score: number;
  passed: boolean;
  feedback: string;
  nextPrompt: string;
  matched: string[];
  missing: string[];
  valid: boolean;
  boundaryHonest: boolean;
  spoken: boolean;
  transcript: string;
  warnings: string[];
}

export interface SpeakingQuestion {
  task: string;
  accepted: string[];
}

export interface SpeakingCallbacks {
  submitTurn: (payload: {
    clipId?: number;
    text?: string;
    task: string;
    accepted: string[];
  }) => Promise<SpeakingTurnResult>;
  transcribe?: (clipId: number) => Promise<UploadedClip>;
  pollState?: (clipIds: number[]) => Promise<UploadedClip[]>;
}

export class SpeakingTrainer {
  readonly root: HTMLDivElement;

  private readonly promptBox: HTMLParagraphElement;

  private readonly feedbackBox: HTMLDivElement;

  private readonly textInput: HTMLTextAreaElement;

  private readonly submitButton: HTMLButtonElement;

  private readonly readButton: HTMLButtonElement;

  private control: SpokenAnswerControl | null = null;

  private question: SpeakingQuestion = {task: '', accepted: []};

  private busy = false;

  constructor(
    private readonly strings: Record<string, string>,
    clipConfig: SpokenAnswerConfig,
    private readonly callbacks: SpeakingCallbacks,
    private readonly tts: TtsPlayer | null,
  ) {
    this.root = liveElement('div', 'quizgeist-speaking');

    this.promptBox = liveElement('p', 'quizgeist-speaking__prompt', {
      'aria-live': 'polite',
    });
    this.readButton = liveButton(
      this.text('speaking:read', 'Aufgabe vorlesen'),
      'quizgeist-speaking__read quizgeist-touch-target',
    );
    this.readButton.addEventListener('click', () => {
      void this.speak(this.question.task);
    });

    this.feedbackBox = liveElement('div', 'quizgeist-speaking__feedback', {
      'aria-live': 'polite',
      role: 'status',
    });

    this.textInput = liveElement('textarea', 'quizgeist-speaking__text', {
      rows: 3,
      'aria-label': this.text('speaking:textlabel', 'Antwort eintippen'),
      placeholder: this.text('speaking:textplaceholder', 'Oder tippe deine Antwort.'),
    });
    this.submitButton = liveButton(
      this.text('speaking:submittext', 'Getippte Antwort abgeben'),
      'quizgeist-speaking__submit quizgeist-touch-target',
    );
    this.submitButton.addEventListener('click', () => {
      void this.submitTyped();
    });

    const controls = liveElement('div', 'quizgeist-speaking__controls');
    controls.append(this.readButton);
    this.root.append(this.promptBox, controls, this.feedbackBox);

    if (SpokenAnswerControl.available()) {
      this.control = new SpokenAnswerControl(strings, {...clipConfig, purpose: 'speaking'}, {
        onClip: (clip) => {
          void this.submitSpoken(clip);
        },
        transcribe: callbacks.transcribe,
        pollState: callbacks.pollState,
      });
      controls.append(this.control.root);
    } else {
      // No microphone: the typed path is not a consolation prize, it is the
      // same round with the same scoring.
      const notice = liveElement('p', 'quizgeist-speaking__notice', {
        text: this.text(
          'speaking:nomic',
          'Ohne Mikrofon geht es auch: tippe deine Antwort ein — sie wird genauso bewertet.',
        ),
      });
      this.root.append(notice);
    }
    const typed = liveElement('div', 'quizgeist-speaking__typed');
    typed.append(this.textInput, this.submitButton);
    this.root.append(typed);
  }

  /**
   * Start a new round.
   */
  setQuestion(question: SpeakingQuestion, autoRead = true): void {
    this.question = {
      task: question.task,
      accepted: Array.isArray(question.accepted) ? question.accepted : [],
    };
    this.promptBox.textContent = this.question.task;
    this.feedbackBox.replaceChildren();
    this.textInput.value = '';
    if (autoRead) {
      void this.speak(this.question.task);
    }
  }

  dispose(): void {
    this.control?.dispose();
  }

  private text(key: string, fallback: string): string {
    return liveString(this.strings, key, {}, fallback);
  }

  private async speak(text: string): Promise<void> {
    if (this.tts === null || text.trim() === '') {
      return;
    }
    try {
      await this.tts.play(text);
    } catch {
      // A voice that does not come is a missing convenience, never a missing
      // round: the task is on screen either way.
    }
  }

  private async submitSpoken(clip: UploadedClip): Promise<void> {
    await this.submit({clipId: clip.id});
  }

  private async submitTyped(): Promise<void> {
    const text = this.textInput.value.trim();
    if (text === '') {
      this.showMessage(this.text('speaking:empty', 'Bitte sag oder schreib etwas.'));
      return;
    }
    await this.submit({text});
  }

  private async submit(payload: {clipId?: number; text?: string}): Promise<void> {
    if (this.busy) {
      return;
    }
    this.busy = true;
    this.submitButton.disabled = true;
    this.showMessage(this.text('speaking:working', 'Rückmeldung wird erstellt …'));
    try {
      const result = await this.callbacks.submitTurn({
        ...payload,
        task: this.question.task,
        accepted: this.question.accepted,
      });
      this.renderResult(result);
      await this.speak(result.feedback);
    } catch {
      this.showMessage(this.text(
        'speaking:failed',
        'Die Rückmeldung konnte nicht erstellt werden. Versuch es noch einmal.',
      ));
    } finally {
      this.busy = false;
      this.submitButton.disabled = false;
    }
  }

  private showMessage(message: string): void {
    this.feedbackBox.replaceChildren(
      liveElement('p', 'quizgeist-speaking__message', {text: message}),
    );
  }

  private renderResult(result: SpeakingTurnResult): void {
    const score = liveElement('p', 'quizgeist-speaking__score', {
      // Never only a colour: the pass/fail state is also a word.
      text: liveString(
        this.strings,
        result.passed ? 'speaking:score:passed' : 'speaking:score:open',
        {score: result.score},
        result.passed
          ? `Erreicht: ${result.score} von 100 — das reicht.`
          : `Erreicht: ${result.score} von 100 — da geht noch was.`,
      ),
    });
    const feedback = liveElement('p', 'quizgeist-speaking__text-feedback', {
      text: result.feedback,
    });
    const children: HTMLElement[] = [score, feedback];

    if (result.transcript !== '') {
      children.push(liveElement('p', 'quizgeist-speaking__transcript', {
        text: liveString(
          this.strings,
          'speaking:transcript',
          {text: result.transcript},
          `Verstanden: ${result.transcript}`,
        ),
      }));
    }
    if (result.origin === 'fallback' && result.warnings.includes('gateway_unavailable')) {
      // Say that the machine was busy rather than pretend the rule-based
      // answer came from somewhere it did not.
      children.push(liveElement('p', 'quizgeist-speaking__origin', {
        text: this.text(
          'speaking:origin:fallback',
          'Diese Rückmeldung kommt aus dem Regelabgleich, nicht aus der KI.',
        ),
      }));
    }
    if (result.nextPrompt !== '') {
      children.push(liveElement('p', 'quizgeist-speaking__next', {
        text: result.nextPrompt,
      }));
    }
    this.feedbackBox.replaceChildren(...children);
  }
}
