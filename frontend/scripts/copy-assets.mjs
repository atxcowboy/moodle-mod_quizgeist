/**
 * Refresh the LOCAL MediaPipe assets of the stage-check addon (F13).
 *
 * Analogous to mod_redewerkstatt/frontend/scripts/copy-assets.mjs, with one
 * difference: the template keeps a second checked-in copy under
 * `frontend/assets/`, which costs 38 MB twice. Here the wasm runtime is copied
 * out of `node_modules/@mediapipe/tasks-vision/wasm` — byte-identical to the
 * files the template ships, verified by sha256 — so the repository carries
 * exactly ONE copy: the one that is shipped.
 *
 * The pose model has no npm source. It is checked in, and this script only
 * verifies it. `--model=<file>` replaces it deliberately.
 *
 * Nothing here downloads anything. No CDN, no network, no DSGVO question.
 */

import {createHash} from 'node:crypto';
import {copyFile, mkdir, readFile, readdir, stat} from 'node:fs/promises';
import {dirname, join} from 'node:path';
import {fileURLToPath} from 'node:url';

const scriptDirectory = dirname(fileURLToPath(import.meta.url));
const frontendDirectory = dirname(scriptDirectory);
const pluginDirectory = dirname(frontendDirectory);
const sourceWasm = join(
  frontendDirectory,
  'node_modules',
  '@mediapipe',
  'tasks-vision',
  'wasm',
);
const targetDirectory = join(
  pluginDirectory,
  'addon',
  'buehne',
  'thirdparty',
  'mediapipe',
);
const targetWasm = join(targetDirectory, 'wasm');
const modelName = 'pose_landmarker_lite.task';

/** The exact assets the stage check is allowed to ship, with their digests. */
const EXPECTED = {
  'pose_landmarker_lite.task':
    '59929e1d1ee95287735ddd833b19cf4ac46d29bc7afddbbf6753c459690d574a',
  'wasm/vision_wasm_internal.js':
    'e7fd9858e8e8f221d9b96eddc11f8e077f263e0b7bbd79d3cbe882b134274f8c',
  'wasm/vision_wasm_internal.wasm':
    '6a5c64584c2ab61c763b6e204afbdbc7ce1caf7f5216187322bca8df94f646bc',
  'wasm/vision_wasm_module_internal.js':
    '1f1d6215324a1fe62f6742d49a3db911170987ca18ad8c1b75f1a1c82acf2b44',
  'wasm/vision_wasm_module_internal.wasm':
    '617b8e0248dbd27e9d7ece4218004eae4cefb499196d1bb4fa0e3fef21708756',
  'wasm/vision_wasm_nosimd_internal.js':
    '438d1fe8ff7f4d946025bc211c291543c037d8a3785ed4eee60f1f521b236296',
  'wasm/vision_wasm_nosimd_internal.wasm':
    '8a3092d34c79d3f57e6ba8592105e8a90f6b07c27891ffecd14cca428bfd3e31',
};

async function digest(file) {
  return createHash('sha256').update(await readFile(file)).digest('hex');
}

function parseArguments(argv) {
  let model = null;
  let verifyOnly = false;
  for (const argument of argv) {
    if (argument === '--verify') {
      verifyOnly = true;
      continue;
    }
    if (argument.startsWith('--model=')) {
      model = argument.slice('--model='.length);
      continue;
    }
    throw new Error(`Unbekannte Option: ${argument}`);
  }
  return {model, verifyOnly};
}

const {model, verifyOnly} = parseArguments(process.argv.slice(2));

if (!verifyOnly) {
  await mkdir(targetWasm, {recursive: true});
  let sources;
  try {
    sources = (await readdir(sourceWasm)).sort();
  } catch (error) {
    throw new Error(
      `MediaPipe-Laufzeit fehlt: ${sourceWasm}. `
      + 'Zuerst `npm install` im frontend/-Verzeichnis ausführen.',
    );
  }
  for (const name of sources) {
    if (!Object.hasOwn(EXPECTED, `wasm/${name}`)) {
      // A future package version may add files. They are not shipped without
      // a decision, because every shipped byte is a DSGVO statement.
      process.stdout.write(`übersprungen (nicht freigegeben): wasm/${name}\n`);
      continue;
    }
    await copyFile(join(sourceWasm, name), join(targetWasm, name));
  }
  if (model !== null) {
    await copyFile(model, join(targetDirectory, modelName));
  }
}

const problems = [];
let total = 0;
for (const [relative, expected] of Object.entries(EXPECTED)) {
  const file = join(targetDirectory, relative);
  try {
    const info = await stat(file);
    total += info.size;
    const actual = await digest(file);
    if (actual !== expected) {
      problems.push(`${relative}: sha256 ${actual}, erwartet ${expected}`);
    }
  } catch (error) {
    problems.push(`${relative}: fehlt in ${targetDirectory}`);
  }
}

if (problems.length > 0) {
  process.stderr.write(`MediaPipe-Assets sind nicht in Ordnung:\n`);
  for (const problem of problems) {
    process.stderr.write(`  - ${problem}\n`);
  }
  if (Object.hasOwn(problems, 0) && problems[0].includes(modelName)) {
    process.stderr.write(
      `  Das Modell hat keine npm-Quelle. Mit --model=<Datei> setzen.\n`,
    );
  }
  process.exit(1);
}

process.stdout.write(
  `QUIZGEIST_STAGE_ASSETS_OK ${Object.keys(EXPECTED).length} Dateien, `
  + `${total} Bytes (${(total / 1048576).toFixed(2)} MiB) in ${targetDirectory}\n`,
);
