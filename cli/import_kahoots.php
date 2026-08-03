<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Idempotent Kahoot JSON/media importer.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = [
    'course' => 0,
    'all' => false,
    'file' => '',
    'dryrun' => false,
    'targetcmid' => null,
    'source' => '',
    'config' => getenv('QUIZGEIST_MOODLE_CONFIG') ?: '',
];
$usage = <<<TEXT
Usage:
  php cli/import_kahoots.php --course=<id> (--alle | --datei=<uuid.json>)
      [--dry-run] [--ziel-cmid=<id>] [--quelle=/path/to/export/data]
      [--moodle-config=/path/to/config.php]

--alle              Importiert alle Kahoot-JSON-Dateien des lokalen Exports.
--datei              Importiert genau eine UUID-JSON aus data/kahoots/.
--dry-run            Prüft Mapping, Schema, MIME, Größen und Prüfsummen ohne Schreibzugriff.
--ziel-cmid          Hängt Fragen an eine bestehende Quizgeist-Aktivität im Kurs an.
--quelle             Absolutes Verzeichnis des lokalen Exports (data/).
--moodle-config      Absoluter Pfad zur Moodle-Konfiguration bei abweichendem Layout.
TEXT;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--alle') {
        $options['all'] = true;
    } else if ($argument === '--dry-run') {
        $options['dryrun'] = true;
    } else if ($argument === '--help' || $argument === '-h') {
        echo $usage . PHP_EOL;
        exit(0);
    } else if (str_starts_with($argument, '--course=')) {
        $options['course'] = (int)substr($argument, strlen('--course='));
    } else if (str_starts_with($argument, '--datei=')) {
        $options['file'] = substr($argument, strlen('--datei='));
    } else if (str_starts_with($argument, '--ziel-cmid=')) {
        $options['targetcmid'] = (int)substr($argument, strlen('--ziel-cmid='));
    } else if (str_starts_with($argument, '--quelle=')) {
        $options['source'] = substr($argument, strlen('--quelle='));
    } else if (str_starts_with($argument, '--moodle-config=')) {
        $options['config'] = substr($argument, strlen('--moodle-config='));
    } else {
        fwrite(STDERR, "Unbekannte Option: {$argument}\n\n{$usage}\n");
        exit(2);
    }
}
if ($options['course'] < 1
        || $options['all'] === ($options['file'] !== '')
        || ($options['targetcmid'] !== null && $options['targetcmid'] < 1)) {
    fwrite(STDERR, "Ungültige oder unvollständige Optionen.\n\n{$usage}\n");
    exit(2);
}

if ($options['config'] === '') {
    foreach ([
        // Moodle 5 public-directory layout:
        // <moodleroot>/public/mod/quizgeist/cli and <moodleroot>/config.php.
        dirname(__DIR__, 4) . '/config.php',
        // Installed as <moodleroot>/mod/quizgeist/cli.
        dirname(__DIR__, 3) . '/config.php',
    ] as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            $options['config'] = $candidate;
            break;
        }
    }
}
if (!is_file($options['config']) || !is_readable($options['config'])) {
    fwrite(STDERR, "Moodle-Konfiguration ist nicht lesbar: {$options['config']}\n");
    exit(2);
}

define('CLI_SCRIPT', true);
require_once($options['config']);

use mod_quizgeist\local\kahoot\import_service;
use mod_quizgeist\local\kahoot\source_bundle;

try {
    global $CFG, $DB;

    $course = get_course($options['course']);
    if (!$DB->record_exists('modules', ['name' => 'quizgeist'])) {
        throw new RuntimeException('mod_quizgeist ist in Moodle nicht installiert.');
    }
    $admin = get_admin();
    if (!$admin) {
        throw new RuntimeException('Kein Administratorkonto für die Importprovenienz gefunden.');
    }
    \core\session\manager::set_user($admin);

    $sourceroot = $options['source'];
    if ($sourceroot === '') {
        // Support both a classic Moodle webroot and Moodle 5's nested
        // <moodleroot>/public document root. The first existing local export
        // wins; neither candidate is ever treated as a URL.
        $classiccandidate = dirname($CFG->dirroot) . '/kahoot-export/data';
        $publicdircandidate = dirname($CFG->dirroot, 2) . '/kahoot-export/data';
        $sourcecandidates = basename($CFG->dirroot) === 'public'
            ? [$publicdircandidate, $classiccandidate]
            : [$classiccandidate, $publicdircandidate];
        foreach ($sourcecandidates as $candidate) {
            if (is_dir($candidate) && is_readable($candidate) && !is_link($candidate)) {
                $sourceroot = $candidate;
                break;
            }
        }
        if ($sourceroot === '') {
            $sourceroot = dirname($CFG->dirroot) . '/kahoot-export/data';
        }
    }
    $realroot = realpath($sourceroot);
    if ($realroot === false || !is_dir($realroot) || is_link($sourceroot)) {
        throw new RuntimeException("Kahoot-Exportverzeichnis ist nicht lesbar: {$sourceroot}");
    }
    if ($options['all']) {
        $sourcepath = $realroot;
    } else {
        $filename = $options['file'];
        if (basename($filename) !== $filename
                || !preg_match('/^[a-f0-9-]{36}\.json$/D', $filename)) {
            throw new RuntimeException('--datei erwartet einen UUID-Dateinamen ohne Pfadanteile.');
        }
        $sourcepath = $realroot . '/kahoots/' . $filename;
        $realfile = realpath($sourcepath);
        $kahootroot = realpath($realroot . '/kahoots');
        if ($realfile === false
                || $kahootroot === false
                || !is_file($realfile)
                || is_link($sourcepath)
                || !str_starts_with($realfile, $kahootroot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Kahoot-Datei ist nicht sicher lesbar: {$filename}");
        }
        $sourcepath = $realfile;
    }

    $bundle = source_bundle::open($sourcepath);
    $batch = import_service::import_bundle(
        $bundle,
        $course,
        $options['targetcmid'],
        (int)$admin->id,
        $options['dryrun']
    );
    foreach ($batch['reports'] as $report) {
        if (($report['status'] ?? '') === 'failed') {
            $failure = is_array($report['failure'] ?? null)
                ? $report['failure']
                : [];
            fprintf(
                STDERR,
                "%s: FEHLER [%s] %s\n",
                (string)($report['source']['title'] ?? 'Kahoot'),
                (string)($failure['code'] ?? 'unexpected_error'),
                (string)($failure['message']
                    ?? get_string('error:importfailed', 'mod_quizgeist'))
            );
            continue;
        }
        $totals = $report['totals'];
        $marker = !empty($report['idempotent']) ? ' (bereits vorhanden)' : '';
        printf(
            "%s: %d unverändert übernommen, %d angepasst, %d ausgelassen, %d Medien%s\n",
            $report['source']['title'],
            $totals['imported'],
            $totals['adjusted'],
            $totals['skipped'],
            $totals['mediaImported'],
            $marker
        );
        if (!empty($report['publicationPending'])) {
            fwrite(
                STDERR,
                (string)($report['source']['title'] ?? 'Kahoot')
                    . ': WARNUNG '
                    . get_string(
                        'kahoot:report:publicationpending',
                        'mod_quizgeist'
                    )
                    . PHP_EOL
            );
        }
    }
    echo 'QUIZGEIST_KAHOOT_IMPORT ' . json_encode(
        $batch,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    exit((int)($batch['totals']['failed'] ?? 0) > 0 ? 1 : 0);
} catch (Throwable $exception) {
    $message = get_string('error:importfailed', 'mod_quizgeist');
    if ($exception instanceof moodle_exception) {
        try {
            $message = get_string(
                $exception->errorcode,
                $exception->module,
                $exception->a
            );
        } catch (Throwable) {
            // Keep the bounded generic message.
        }
    } else {
        $candidate = trim($exception->getMessage());
        if (preg_match('/^[\pL\pN _.,:;()\\/-]{1,240}$/uD', $candidate)) {
            $message = $candidate;
        }
    }
    fwrite(
        STDERR,
        'import_kahoots: ' . clean_param($message, PARAM_TEXT) . PHP_EOL
    );
    exit(1);
}
