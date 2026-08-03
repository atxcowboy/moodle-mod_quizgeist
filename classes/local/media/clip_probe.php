<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Server-side container inspection for uploaded short clips.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\media;

defined('MOODLE_INTERNAL') || die();

/**
 * Reads container type and duration out of the BYTES of an uploaded clip.
 *
 * Two things make this class necessary rather than convenient:
 *
 * 1. The client's Content-Type is an assertion, not evidence. Magic bytes are
 *    evidence, so the accepted type is derived here and the declared one is
 *    only ever compared against the result.
 * 2. The duration limit has to be enforced against something the client cannot
 *    choose. A `durationMs` form field would be exactly such a choice, so the
 *    duration is read out of the container instead.
 *
 * Deliberately dependency-free: the clip channel lives in the BASE plugin so
 * existing recordings stay playable without the AI addon, and the base plugin
 * must not start depending on ffprobe or on quizgeistaddon_ai\bounded_process.
 *
 * A container whose duration cannot be established is REJECTED, not accepted
 * with zero. An unenforceable limit is not a limit.
 */
final class clip_probe {

    /** Bytes read from the head for signature detection. */
    private const HEAD_BYTES = 4096;

    /** Bytes read from the tail when a format stores its length there. */
    private const TAIL_BYTES = 65536;

    /** Guard against a hostile element chain in a malformed container. */
    private const MAX_ELEMENTS = 200000;

    /** EBML element IDs, stored with their length markers as usual. */
    private const EBML_SEGMENT = 0x18538067;
    private const EBML_INFO = 0x1549A966;
    private const EBML_TIMECODESCALE = 0x2AD7B1;
    private const EBML_DURATION = 0x4489;
    private const EBML_CLUSTER = 0x1F43B675;
    private const EBML_TIMECODE = 0xE7;
    private const EBML_SIMPLEBLOCK = 0xA3;
    private const EBML_BLOCKGROUP = 0xA0;
    private const EBML_BLOCK = 0xA1;

    /**
     * @var int[] IDs that may legally appear directly inside a Cluster.
     *
     * Needed only for streaming containers, where MediaRecorder writes Clusters
     * of unknown size: the cluster ends at the first ID that is not one of
     * these, and without that list an unknown-size cluster would swallow the
     * rest of the file.
     */
    private const CLUSTER_CHILDREN = [
        self::EBML_TIMECODE, self::EBML_SIMPLEBLOCK, self::EBML_BLOCKGROUP,
        0xAB, 0xA7, 0x58D7, 0xAF,
    ];

    /**
     * Inspect one uploaded file.
     *
     * @param string $path Absolute path of an existing readable file.
     * @return array{mimetype:string,durationms:int,code:string}
     *         `code` is empty on success and a stable machine code otherwise;
     *         it is a diagnosis key, never a string shown to a user (F16).
     */
    public static function inspect(string $path): array {
        $failure = static fn(string $code): array => [
            'mimetype' => '',
            'durationms' => 0,
            'code' => $code,
        ];

        if (!is_file($path) || !is_readable($path)) {
            return $failure('clip_unreadable');
        }
        $size = (int) filesize($path);
        if ($size <= 0) {
            return $failure('clip_empty');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return $failure('clip_unreadable');
        }

        try {
            $head = (string) fread($handle, self::HEAD_BYTES);
            $family = self::family($head);
            if ($family === '') {
                return $failure('clip_signature_unknown');
            }

            $durationms = match ($family) {
                'webm' => self::webm_duration($handle, $size),
                'ogg' => self::ogg_duration($handle, $size),
                'mp4' => self::mp4_duration($handle, $size),
                'wav' => self::wav_duration($head, $size),
                default => -1,
            };
            if ($durationms < 0) {
                return $failure('clip_duration_unreadable');
            }
            return [
                'mimetype' => self::family_mimetype($family),
                'durationms' => $durationms,
                'code' => '',
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Detect the container family from its magic bytes.
     *
     * @param string $head First bytes of the file.
     * @return string webm, ogg, mp4, wav or the empty string.
     */
    public static function family(string $head): string {
        if (strncmp($head, "\x1a\x45\xdf\xa3", 4) === 0) {
            return 'webm';
        }
        if (strncmp($head, 'OggS', 4) === 0) {
            return 'ogg';
        }
        if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
            return 'mp4';
        }
        if (strncmp($head, 'RIFF', 4) === 0 && strlen($head) >= 12 && substr($head, 8, 4) === 'WAVE') {
            return 'wav';
        }
        return '';
    }

    /**
     * Canonical MIME type of one container family.
     *
     * @param string $family Family key.
     * @return string
     */
    public static function family_mimetype(string $family): string {
        return match ($family) {
            'webm' => 'audio/webm',
            'ogg' => 'audio/ogg',
            'mp4' => 'audio/mp4',
            'wav' => 'audio/wav',
            default => '',
        };
    }

    /**
     * MIME types a caller may declare for one detected family.
     *
     * A recorder legitimately says `video/webm` for an audio-only WebM, so the
     * comparison is family based rather than string equality.
     *
     * @param string $family Family key.
     * @return string[]
     */
    public static function family_mimetypes(string $family): array {
        return match ($family) {
            'webm' => ['audio/webm', 'video/webm'],
            'ogg' => ['audio/ogg'],
            'mp4' => ['audio/mp4', 'video/mp4', 'audio/x-m4a', 'audio/aac'],
            'wav' => ['audio/wav', 'audio/x-wav'],
            default => [],
        };
    }

    /**
     * Duration of a Matroska/WebM container in milliseconds.
     *
     * Two shapes occur in practice and both are handled: a muxed file that
     * states Info/Duration, and a MediaRecorder stream whose Segment has an
     * unknown size and no Duration at all. For the second the last Cluster
     * timecode plus the last block offset inside it is the duration.
     *
     * @param resource $handle Open file handle.
     * @param int $size File size.
     * @return int Milliseconds, or -1 when undecidable.
     */
    private static function webm_duration($handle, int $size): int {
        fseek($handle, 0);
        $scale = 1000000;
        $duration = 0.0;
        $lastcluster = -1;
        $elements = 0;

        // The EBML header is a normal, sized element; skip it and walk the
        // Segment children.
        $header = self::read_element_header($handle, $size);
        if ($header === null || $header['id'] !== 0x1A45DFA3) {
            return -1;
        }
        fseek($handle, $header['datastart'] + max(0, $header['datasize']));

        $segment = self::read_element_header($handle, $size);
        if ($segment === null || $segment['id'] !== self::EBML_SEGMENT) {
            return -1;
        }
        $segmentend = $segment['datasize'] < 0
            ? $size
            : min($size, $segment['datastart'] + $segment['datasize']);
        $position = $segment['datastart'];

        while ($position < $segmentend && $elements++ < self::MAX_ELEMENTS) {
            fseek($handle, $position);
            $element = self::read_element_header($handle, $segmentend);
            if ($element === null) {
                break;
            }
            $datasize = $element['datasize'];
            $known = $datasize >= 0;
            $end = $known ? min($segmentend, $element['datastart'] + $datasize) : $segmentend;

            if ($element['id'] === self::EBML_INFO && $known) {
                [$scale, $duration] = self::webm_info($handle, $element['datastart'], $end, $scale, $duration);
                $position = $end;
                continue;
            }
            if ($element['id'] === self::EBML_CLUSTER) {
                [$clustertime, $next] = self::webm_cluster(
                    $handle,
                    $element['datastart'],
                    $end,
                    $known
                );
                if ($clustertime >= 0) {
                    $lastcluster = max($lastcluster, $clustertime);
                }
                $position = $next > $position ? $next : $position + 1;
                continue;
            }
            if (!$known) {
                // An unknown-size element that is neither Segment nor Cluster
                // cannot be traversed safely; refuse rather than guess.
                return -1;
            }
            $position = $end;
        }

        $frominfo = $duration > 0.0 ? (int) round($duration * $scale / 1000000.0) : -1;
        $fromclusters = $lastcluster >= 0 ? (int) round($lastcluster * $scale / 1000000.0) : -1;
        $result = max($frominfo, $fromclusters);
        return $result >= 0 ? $result : -1;
    }

    /**
     * Read TimecodeScale and Duration out of one Info element.
     *
     * @param resource $handle Open file handle.
     * @param int $start First byte of the Info payload.
     * @param int $end First byte after the Info payload.
     * @param int $scale Current timecode scale.
     * @param float $duration Current duration in scale units.
     * @return array{0:int,1:float}
     */
    private static function webm_info($handle, int $start, int $end, int $scale, float $duration): array {
        $position = $start;
        $elements = 0;
        while ($position < $end && $elements++ < 512) {
            fseek($handle, $position);
            $element = self::read_element_header($handle, $end);
            if ($element === null || $element['datasize'] < 0) {
                break;
            }
            $payloadend = min($end, $element['datastart'] + $element['datasize']);
            if ($element['id'] === self::EBML_TIMECODESCALE) {
                $value = self::read_uint($handle, $element['datastart'], $element['datasize']);
                if ($value > 0) {
                    $scale = $value;
                }
            } else if ($element['id'] === self::EBML_DURATION) {
                $value = self::read_float($handle, $element['datastart'], $element['datasize']);
                if ($value !== null && $value > 0.0) {
                    $duration = $value;
                }
            }
            $position = $payloadend;
        }
        return [$scale, $duration];
    }

    /**
     * Read one Cluster and return its highest timecode plus the next position.
     *
     * @param resource $handle Open file handle.
     * @param int $start First byte of the Cluster payload.
     * @param int $limit Hard upper bound.
     * @param bool $known Whether the Cluster stated its size.
     * @return array{0:int,1:int} [timecode in scale units or -1, next position]
     */
    private static function webm_cluster($handle, int $start, int $limit, bool $known): array {
        $position = $start;
        $timecode = -1;
        $blockoffset = 0;
        $elements = 0;

        while ($position < $limit && $elements++ < self::MAX_ELEMENTS) {
            fseek($handle, $position);
            $element = self::read_element_header($handle, $limit);
            if ($element === null || $element['datasize'] < 0) {
                break;
            }
            if (!$known && !in_array($element['id'], self::CLUSTER_CHILDREN, true)) {
                // The streaming cluster ends here; hand the position back so the
                // caller reads this element as a Segment child.
                break;
            }
            $payloadend = min($limit, $element['datastart'] + $element['datasize']);
            if ($element['id'] === self::EBML_TIMECODE) {
                $timecode = max($timecode, self::read_uint($handle, $element['datastart'], $element['datasize']));
            } else if ($element['id'] === self::EBML_SIMPLEBLOCK) {
                $blockoffset = max($blockoffset, self::block_offset($handle, $element['datastart'], $element['datasize']));
            } else if ($element['id'] === self::EBML_BLOCKGROUP) {
                $inner = $element['datastart'];
                $innerguard = 0;
                while ($inner < $payloadend && $innerguard++ < 64) {
                    fseek($handle, $inner);
                    $child = self::read_element_header($handle, $payloadend);
                    if ($child === null || $child['datasize'] < 0) {
                        break;
                    }
                    if ($child['id'] === self::EBML_BLOCK) {
                        $blockoffset = max(
                            $blockoffset,
                            self::block_offset($handle, $child['datastart'], $child['datasize'])
                        );
                    }
                    $inner = min($payloadend, $child['datastart'] + $child['datasize']);
                }
            }
            $position = $payloadend > $position ? $payloadend : $position + 1;
        }

        return [$timecode >= 0 ? $timecode + max(0, $blockoffset) : -1, $position];
    }

    /**
     * Signed relative timestamp of one (Simple)Block, in scale units.
     *
     * @param resource $handle Open file handle.
     * @param int $start First byte of the block payload.
     * @param int $length Payload length.
     * @return int
     */
    private static function block_offset($handle, int $start, int $length): int {
        if ($length < 4) {
            return 0;
        }
        fseek($handle, $start);
        $raw = (string) fread($handle, min(12, $length));
        if ($raw === '') {
            return 0;
        }
        // Track number is a VINT; the two bytes after it are a signed offset.
        $first = ord($raw[0]);
        $tracklength = 0;
        for ($bit = 0; $bit < 8; $bit++) {
            if (($first & (0x80 >> $bit)) !== 0) {
                $tracklength = $bit + 1;
                break;
            }
        }
        if ($tracklength === 0 || strlen($raw) < $tracklength + 2) {
            return 0;
        }
        $value = (ord($raw[$tracklength]) << 8) | ord($raw[$tracklength + 1]);
        if ($value >= 0x8000) {
            $value -= 0x10000;
        }
        return max(0, $value);
    }

    /**
     * Read one EBML element header at the current position.
     *
     * @param resource $handle Open file handle.
     * @param int $limit Hard upper bound.
     * @return array{id:int,datastart:int,datasize:int}|null datasize -1 means unknown.
     */
    private static function read_element_header($handle, int $limit): ?array {
        $start = ftell($handle);
        if ($start === false || $start >= $limit) {
            return null;
        }
        $raw = (string) fread($handle, 16);
        if (strlen($raw) < 2) {
            return null;
        }

        $first = ord($raw[0]);
        $idlength = 0;
        for ($bit = 0; $bit < 4; $bit++) {
            if (($first & (0x80 >> $bit)) !== 0) {
                $idlength = $bit + 1;
                break;
            }
        }
        if ($idlength === 0 || strlen($raw) < $idlength + 1) {
            return null;
        }
        $id = 0;
        for ($index = 0; $index < $idlength; $index++) {
            $id = ($id << 8) | ord($raw[$index]);
        }

        $sizefirst = ord($raw[$idlength]);
        $sizelength = 0;
        for ($bit = 0; $bit < 8; $bit++) {
            if (($sizefirst & (0x80 >> $bit)) !== 0) {
                $sizelength = $bit + 1;
                break;
            }
        }
        if ($sizelength === 0 || strlen($raw) < $idlength + $sizelength) {
            return null;
        }
        $size = $sizefirst & (0xFF >> $sizelength);
        $allones = $size === (0xFF >> $sizelength);
        for ($index = 1; $index < $sizelength; $index++) {
            $byte = ord($raw[$idlength + $index]);
            $size = ($size << 8) | $byte;
            $allones = $allones && $byte === 0xFF;
        }

        $datastart = $start + $idlength + $sizelength;
        if ($allones) {
            return ['id' => $id, 'datastart' => $datastart, 'datasize' => -1];
        }
        if ($size < 0 || $datastart + $size > $limit + self::TAIL_BYTES) {
            // A stated size beyond the file is a broken container, not a hint.
            return ['id' => $id, 'datastart' => $datastart, 'datasize' => max(0, min($size, $limit - $datastart))];
        }
        return ['id' => $id, 'datastart' => $datastart, 'datasize' => $size];
    }

    /**
     * Read a big-endian unsigned integer payload.
     *
     * @param resource $handle Open file handle.
     * @param int $start First byte.
     * @param int $length Byte length.
     * @return int Value, or -1 when unusable.
     */
    private static function read_uint($handle, int $start, int $length): int {
        if ($length <= 0 || $length > 8) {
            return -1;
        }
        fseek($handle, $start);
        $raw = (string) fread($handle, $length);
        if (strlen($raw) !== $length) {
            return -1;
        }
        $value = 0;
        for ($index = 0; $index < $length; $index++) {
            $value = ($value << 8) | ord($raw[$index]);
        }
        return $value;
    }

    /**
     * Read a big-endian float payload.
     *
     * @param resource $handle Open file handle.
     * @param int $start First byte.
     * @param int $length 4 or 8.
     * @return float|null
     */
    private static function read_float($handle, int $start, int $length): ?float {
        if ($length !== 4 && $length !== 8) {
            return null;
        }
        fseek($handle, $start);
        $raw = (string) fread($handle, $length);
        if (strlen($raw) !== $length) {
            return null;
        }
        $unpacked = unpack($length === 4 ? 'G' : 'E', $raw);
        if (!is_array($unpacked) || !isset($unpacked[1]) || !is_finite((float) $unpacked[1])) {
            return null;
        }
        return (float) $unpacked[1];
    }

    /**
     * Duration of an Ogg container in milliseconds.
     *
     * The last page's granule position is the sample count; the sample rate
     * comes from the identification header of the first page (Opus reports its
     * granules at a fixed 48 kHz regardless of the input rate).
     *
     * @param resource $handle Open file handle.
     * @param int $size File size.
     * @return int Milliseconds, or -1 when undecidable.
     */
    private static function ogg_duration($handle, int $size): int {
        fseek($handle, 0);
        $head = (string) fread($handle, self::HEAD_BYTES);
        $rate = 0;
        $preskip = 0;
        $opus = strpos($head, 'OpusHead');
        if ($opus !== false) {
            // Fixed granule clock; the pre-skip is part of the stream, not of
            // what the learner said.
            $rate = 48000;
            if (strlen($head) >= $opus + 12) {
                $preskip = ord($head[$opus + 10]) | (ord($head[$opus + 11]) << 8);
            }
        } else {
            $vorbis = strpos($head, "\x01vorbis");
            if ($vorbis !== false && strlen($head) >= $vorbis + 16) {
                $unpacked = unpack('V', substr($head, $vorbis + 12, 4));
                $rate = is_array($unpacked) ? (int) ($unpacked[1] ?? 0) : 0;
            }
        }
        if ($rate <= 0) {
            return -1;
        }

        $tailstart = max(0, $size - self::TAIL_BYTES);
        fseek($handle, $tailstart);
        $tail = (string) fread($handle, self::TAIL_BYTES);
        $offset = strrpos($tail, 'OggS');
        if ($offset === false || strlen($tail) < $offset + 14) {
            return -1;
        }
        $granule = 0;
        for ($index = 13; $index >= 6; $index--) {
            $granule = ($granule << 8) | ord($tail[$offset + $index]);
        }
        $granule -= $preskip;
        if ($granule <= 0) {
            return 0;
        }
        return (int) round($granule * 1000 / $rate);
    }

    /**
     * Duration of an ISO base media (MP4/M4A) container in milliseconds.
     *
     * @param resource $handle Open file handle.
     * @param int $size File size.
     * @return int Milliseconds, or -1 when undecidable.
     */
    private static function mp4_duration($handle, int $size): int {
        $moov = self::mp4_find_box($handle, 0, $size, 'moov', 0);
        if ($moov === null) {
            return -1;
        }
        $mvhd = self::mp4_find_box($handle, $moov[0], $moov[1], 'mvhd', 0);
        if ($mvhd === null) {
            return -1;
        }
        fseek($handle, $mvhd[0]);
        $raw = (string) fread($handle, 32);
        if (strlen($raw) < 20) {
            return -1;
        }
        $version = ord($raw[0]);
        if ($version === 1) {
            if (strlen($raw) < 32) {
                return -1;
            }
            $timescale = self::be_int(substr($raw, 20, 4));
            $duration = self::be_int(substr($raw, 24, 8));
        } else {
            $timescale = self::be_int(substr($raw, 12, 4));
            $duration = self::be_int(substr($raw, 16, 4));
        }
        if ($timescale <= 0 || $duration < 0) {
            return -1;
        }
        return (int) round($duration * 1000 / $timescale);
    }

    /**
     * Find one ISO box inside a byte range.
     *
     * @param resource $handle Open file handle.
     * @param int $start First byte of the range.
     * @param int $end First byte after the range.
     * @param string $type Four-character box type.
     * @param int $depth Recursion depth.
     * @return array{0:int,1:int}|null [payload start, payload end]
     */
    private static function mp4_find_box($handle, int $start, int $end, string $type, int $depth): ?array {
        if ($depth > 4) {
            return null;
        }
        $position = $start;
        $boxes = 0;
        while ($position + 8 <= $end && $boxes++ < 4096) {
            fseek($handle, $position);
            $raw = (string) fread($handle, 16);
            if (strlen($raw) < 8) {
                return null;
            }
            $boxsize = self::be_int(substr($raw, 0, 4));
            $boxtype = substr($raw, 4, 4);
            $payloadstart = $position + 8;
            if ($boxsize === 1) {
                if (strlen($raw) < 16) {
                    return null;
                }
                $boxsize = self::be_int(substr($raw, 8, 8));
                $payloadstart = $position + 16;
            } else if ($boxsize === 0) {
                $boxsize = $end - $position;
            }
            if ($boxsize < 8 || $position + $boxsize > $end) {
                return null;
            }
            if ($boxtype === $type) {
                return [$payloadstart, $position + $boxsize];
            }
            $position += $boxsize;
        }
        return null;
    }

    /**
     * Duration of a RIFF/WAVE container in milliseconds.
     *
     * @param string $head Head bytes of the file.
     * @param int $size File size.
     * @return int Milliseconds, or -1 when undecidable.
     */
    private static function wav_duration(string $head, int $size): int {
        $byterate = 0;
        $position = 12;
        $chunks = 0;
        while ($position + 8 <= strlen($head) && $chunks++ < 64) {
            $id = substr($head, $position, 4);
            $unpacked = unpack('V', substr($head, $position + 4, 4));
            $chunksize = is_array($unpacked) ? (int) ($unpacked[1] ?? 0) : 0;
            if ($chunksize < 0) {
                return -1;
            }
            if ($id === 'fmt ' && $position + 8 + 16 <= strlen($head)) {
                $fmt = unpack('vformat/vchannels/Vrate/Vbyterate', substr($head, $position + 8, 12));
                $byterate = is_array($fmt) ? (int) ($fmt['byterate'] ?? 0) : 0;
            }
            if ($id === 'data') {
                if ($byterate <= 0) {
                    return -1;
                }
                // A stated data size larger than the file is not trusted.
                $datasize = min($chunksize, max(0, $size - ($position + 8)));
                return (int) round($datasize * 1000 / $byterate);
            }
            $position += 8 + $chunksize + ($chunksize % 2);
        }
        return -1;
    }

    /**
     * Read a big-endian integer from raw bytes.
     *
     * @param string $raw Raw bytes.
     * @return int
     */
    private static function be_int(string $raw): int {
        $value = 0;
        $length = strlen($raw);
        for ($index = 0; $index < $length; $index++) {
            $value = ($value << 8) | ord($raw[$index]);
        }
        return $value;
    }
}
