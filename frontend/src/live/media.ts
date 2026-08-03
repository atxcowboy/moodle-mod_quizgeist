import type {LiveMediaMimeType} from './types';

const IMAGE_MIME_TYPES = new Set<LiveMediaMimeType>([
  'image/png',
  'image/jpeg',
  'image/gif',
  'image/webp',
  'image/svg+xml',
]);

const VIDEO_MIME_TYPES = new Set<LiveMediaMimeType>([
  'video/mp4',
  'video/webm',
  'video/ogg',
]);

const AUDIO_MIME_TYPES = new Set<LiveMediaMimeType>([
  'audio/mp4',
  'audio/webm',
  'audio/mp3',
  'audio/mpeg',
  'audio/ogg',
  'audio/wav',
  'audio/x-wav',
  'application/ogg',
]);

interface LiveMediaSource {
  mediaMimeType?: LiveMediaMimeType | null;
  mediaUrl?: string | null;
}

interface LiveMediaOptions {
  allowPlayback?: boolean;
  className: string;
  label: string;
}

type LiveMediaKind = 'image' | 'video' | 'audio';

function mediaKind(mimetype: LiveMediaMimeType): LiveMediaKind | null {
  if (IMAGE_MIME_TYPES.has(mimetype)) {
    return 'image';
  }
  if (VIDEO_MIME_TYPES.has(mimetype)) {
    return 'video';
  }
  if (AUDIO_MIME_TYPES.has(mimetype)) {
    return 'audio';
  }
  return null;
}

function sameOriginUrl(raw: string): string | null {
  try {
    const url = new URL(raw, document.baseURI);
    if ((url.protocol !== 'http:' && url.protocol !== 'https:')
        || url.origin !== window.location.origin) {
      return null;
    }
    return url.href;
  } catch (_error) {
    return null;
  }
}

/**
 * Render only a server-described, same-origin live medium.
 *
 * No filename/extension inference is permitted here: a URL without one of the
 * MIME types emitted from the authoritative Moodle manifest fails closed.
 */
export function createLiveMedia(
  source: LiveMediaSource,
  options: LiveMediaOptions,
): HTMLImageElement | HTMLVideoElement | HTMLAudioElement | null {
  const rawUrl = typeof source.mediaUrl === 'string' ? source.mediaUrl : '';
  const mimetype = source.mediaMimeType;
  if (rawUrl === '' || !mimetype) {
    return null;
  }
  const kind = mediaKind(mimetype);
  const url = sameOriginUrl(rawUrl);
  if (!kind || !url || (kind !== 'image' && options.allowPlayback === false)) {
    return null;
  }

  if (kind === 'image') {
    const image = document.createElement('img');
    image.className = options.className;
    image.src = url;
    image.alt = options.label;
    image.dataset.mediaMimeType = mimetype;
    return image;
  }

  const media = document.createElement(kind);
  media.className = options.className;
  media.controls = true;
  media.preload = 'metadata';
  media.setAttribute('aria-label', options.label);
  media.dataset.mediaMimeType = mimetype;
  if (media instanceof HTMLVideoElement) {
    media.playsInline = true;
  }
  const mediaSource = document.createElement('source');
  mediaSource.src = url;
  mediaSource.type = mimetype;
  media.append(mediaSource);
  return media;
}
