import {build} from 'esbuild';
import {mkdir} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

const frontendDirectory = path.dirname(fileURLToPath(import.meta.url));
const pluginDirectory = path.resolve(frontendDirectory, '..');
const buildDirectory = path.join(pluginDirectory, 'amd', 'build');
const loaderDirectory = path.join(pluginDirectory, 'loader');

const bundles = [
  {
    entryPoint: path.join(frontendDirectory, 'src', 'app_edit.ts'),
    globalName: 'QuizgeistEditApp',
    output: 'app_edit.js',
  },
  {
    entryPoint: path.join(frontendDirectory, 'src', 'app_host.ts'),
    globalName: 'QuizgeistHostApp',
    output: 'app_host.js',
  },
  {
    entryPoint: path.join(frontendDirectory, 'src', 'app_play.ts'),
    globalName: 'QuizgeistPlayApp',
    output: 'app_play.js',
  },
  {
    entryPoint: path.join(frontendDirectory, 'src', 'app_report.ts'),
    globalName: 'QuizgeistReportApp',
    output: 'app_report.js',
  },
  // P11/C6 (F13): Das Buehnen-Bundle gehoert dem ADDON und wird deshalb auch
  // dorthin gebaut. Ohne Addon-Codepaket liegt die Datei gar nicht auf der
  // Platte — der Untermodus fehlt dann vollstaendig, statt gesperrt zu
  // erscheinen (P11_PLAN.md 2.6). Es wird ausserdem nur BEI BEDARF geladen,
  // damit das Spieler-Bundle nicht fuer alle um MediaPipe waechst.
  // Ziel ist bewusst `bundles/`, NICHT `amd/build/`: Moodle sammelt jede
  // Datei unter amd/build ohne .min.js-Geschwister in das seitenweite
  // RequireJS-Paket ein — ein define-loses IIFE-Bundle liefe dann auf jeder
  // Seite der Installation mit.
  {
    entryPoint: path.join(frontendDirectory, 'src', 'app_stage.ts'),
    globalName: 'QuizgeistStageApp',
    output: 'app_stage.js',
    outputDirectory: path.join(pluginDirectory, 'addon', 'buehne', 'bundles'),
  },
];

const loaders = [
  ['app_edit-loader.js', 'app_edit.min.js'],
  ['app_host-loader.js', 'app_host.min.js'],
  ['app_play-loader.js', 'app_play.min.js'],
  ['app_report-loader.js', 'app_report.min.js'],
];

const target = process.argv[2] ?? 'all';
const validTargets = new Set(['all', 'bundles', 'loaders']);

if (!validTargets.has(target)) {
  throw new Error(`Unbekanntes Build-Ziel: ${target}`);
}

await mkdir(buildDirectory, {recursive: true});

await Promise.all(
  bundles
    .map((bundle) => bundle.outputDirectory)
    .filter((directory) => typeof directory === 'string')
    .map((directory) => mkdir(directory, {recursive: true})),
);

if (target === 'all' || target === 'bundles') {
  await Promise.all(
    bundles.map((bundle) => build({
      entryPoints: [bundle.entryPoint],
      outfile: path.join(
        bundle.outputDirectory ?? buildDirectory,
        bundle.output,
      ),
      bundle: true,
      charset: 'ascii',
      format: 'iife',
      globalName: bundle.globalName,
      legalComments: 'none',
      logLevel: 'info',
      minify: false,
      platform: 'browser',
      sourcemap: false,
      target: ['es2019'],
    })),
  );
}

if (target === 'all' || target === 'loaders') {
  await Promise.all(
    loaders.map(([source, output]) => build({
      entryPoints: [path.join(loaderDirectory, source)],
      outfile: path.join(buildDirectory, output),
      bundle: false,
      charset: 'ascii',
      legalComments: 'none',
      logLevel: 'info',
      minify: true,
      platform: 'browser',
      sourcemap: false,
      target: ['es2019'],
    })),
  );
}
