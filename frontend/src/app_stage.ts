/**
 * Entry point of the stage-check bundle (F13), owned by quizgeistaddon_buehne.
 *
 * The bundle is built into `addon/buehne/amd/build/app_stage.js` and is loaded
 * ON DEMAND — only when a learner actually enters the stage sub-mode. Two
 * consequences, both intended:
 *
 *   - The base play bundle does not grow by a single byte for a feature most
 *     sessions never use, and the 38 MB of MediaPipe assets stay in the addon.
 *   - Without the addon code package the file simply is not there, so the
 *     sub-mode is absent instead of locked (P11_PLAN.md 2.6).
 *
 * The exported surface is deliberately tiny: create an engine, get numbers.
 * The bundle never talks to the network on its own.
 */

import {createStageEngine} from './stage/engine';
import {checkStage} from './stage/pose';

export {createStageEngine, checkStage};

/** Contract version so the page can refuse an unexpected bundle. */
export const contract = 1;

// The global is declared exactly ONCE, in frontend/src/live/stage-check.ts —
// that is the side which has to know the contract, because that is the side
// which loads the bundle. Declaring it a second time here would create two
// truths about one window property.
