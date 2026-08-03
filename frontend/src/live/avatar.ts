const SVG_NS = 'http://www.w3.org/2000/svg';

export const AVATAR_KEYS = [
  'kiesel',
  'zweig',
  'federchen',
  'klecks',
  'kubus',
  'wirbel',
  'stern',
  'mondchen',
] as const;

export type AvatarKey = typeof AVATAR_KEYS[number];

export interface AvatarAppearance {
  accessoryKey?: string | null;
  avatarKey?: string | null;
  colorKey?: string | null;
}

const BODY_COLORS: Record<AvatarKey, string> = {
  federchen: 'var(--mq-color-honig-500)',
  kiesel: 'var(--mq-color-tiefsee-500)',
  klecks: 'var(--mq-color-beere-500)',
  kubus: 'var(--mq-color-funke-500)',
  mondchen: 'var(--mq-color-mitternacht-600)',
  stern: 'var(--mq-color-honig-500)',
  wirbel: 'var(--mq-color-tiefsee-400)',
  zweig: 'var(--mq-color-trieb-600)',
};

const COLOR_TOKENS: Record<string, string> = {
  beere: 'var(--mq-color-beere-500)',
  funke: 'var(--mq-color-funke-500)',
  honig: 'var(--mq-color-honig-500)',
  ink: 'var(--mq-color-ink-300)',
  mitternacht: 'var(--mq-color-mitternacht-600)',
  tiefsee: 'var(--mq-color-tiefsee-500)',
  trieb: 'var(--mq-color-trieb-600)',
};

function svgElement<K extends keyof SVGElementTagNameMap>(
  name: K,
  attributes: Record<string, string>,
): SVGElementTagNameMap[K] {
  const element = document.createElementNS(SVG_NS, name);
  Object.entries(attributes).forEach(([attribute, value]) => {
    element.setAttribute(attribute, value);
  });
  return element;
}

function body(key: AvatarKey, requestedColor?: string | null): SVGElement {
  const fill = requestedColor && COLOR_TOKENS[requestedColor]
    ? COLOR_TOKENS[requestedColor]
    : BODY_COLORS[key];
  switch (key) {
    case 'zweig': {
      const group = svgElement('g', {});
      group.append(
        svgElement('ellipse', {cx: '60', cy: '66', fill, rx: '31', ry: '42'}),
        svgElement('polygon', {
          fill: 'var(--mq-color-trieb-700)',
          points: '60,8 70,29 50,29',
        }),
      );
      return group;
    }
    case 'federchen':
      return svgElement('path', {
        d: 'M60 15C91 39 97 78 60 106C23 78 29 39 60 15Z',
        fill,
        transform: 'rotate(8 60 60)',
      });
    case 'klecks':
      return svgElement('path', {
        d: 'M60 14c22-4 46 10 44 34s6 46-18 54-52 2-50-26 2-58 24-62z',
        fill,
      });
    case 'kubus':
      return svgElement('path', {
        d: 'M60 12c34 0 46 12 46 46s-12 46-46 46-46-12-46-46 12-46 46-46z',
        fill,
      });
    case 'wirbel':
      return svgElement('path', {
        d: 'M60 14a46 46 0 1 1-32 78 34 34 0 1 0 22-56 18 18 0 1 1 10 34c-10 0-17-7-17-16 0-8 6-14 14-14 5 0 9 2 12 6',
        fill: 'none',
        stroke: fill,
        'stroke-linecap': 'round',
        'stroke-width': '22',
      });
    case 'stern':
      return svgElement('polygon', {
        fill,
        points: '60,8 72,42 108,43 79,65 89,101 60,80 31,101 41,65 12,43 48,42',
        stroke: fill,
        'stroke-linejoin': 'round',
        'stroke-width': '7',
      });
    case 'mondchen':
      return svgElement('path', {
        d: 'M79 15a46 46 0 1 0 26 72A39 39 0 0 1 79 15Z',
        fill,
      });
    default:
      return svgElement('circle', {cx: '60', cy: '62', fill, r: '46'});
  }
}

function accessory(keyValue: string | null | undefined): SVGElement | null {
  const key = typeof keyValue === 'string' ? keyValue : '';
  if (key === 'partyhut' || key === 'party-hat') {
    const group = svgElement('g', {'data-avatar-accessory': key});
    group.append(
      svgElement('polygon', {
        fill: 'var(--mq-color-honig-300)',
        points: '60,0 75,27 45,27',
      }),
      svgElement('circle', {
        cx: '60',
        cy: '2',
        fill: 'var(--mq-color-beere-600)',
        r: '5',
      }),
    );
    return group;
  }
  if (key === 'brille' || key === 'round-glasses') {
    const group = svgElement('g', {
      'data-avatar-accessory': key,
      fill: 'none',
      stroke: 'var(--mq-color-ink-900)',
      'stroke-width': '3',
    });
    group.append(
      svgElement('circle', {cx: '44', cy: '51', r: '11'}),
      svgElement('circle', {cx: '76', cy: '51', r: '11'}),
      svgElement('line', {x1: '55', x2: '65', y1: '51', y2: '51'}),
    );
    return group;
  }
  if (key === 'krone' || key === 'crown') {
    return svgElement('polygon', {
      'data-avatar-accessory': key,
      fill: 'var(--mq-color-honig-300)',
      points: '39,24 43,5 59,18 74,4 81,25',
      stroke: 'var(--mq-color-honig-700)',
      'stroke-linejoin': 'round',
      'stroke-width': '2',
    });
  }
  if (key === 'medaille' || key === 'medal') {
    const group = svgElement('g', {'data-avatar-accessory': key});
    group.append(
      svgElement('path', {
        d: 'M82 82L90 105L98 82',
        fill: 'var(--mq-color-beere-500)',
      }),
      svgElement('circle', {
        cx: '90',
        cy: '86',
        fill: 'var(--mq-color-honig-300)',
        r: '11',
        stroke: 'var(--mq-color-honig-700)',
        'stroke-width': '2',
      }),
    );
    return group;
  }
  if (key === 'funkenbadge') {
    const group = svgElement('g', {'data-avatar-accessory': key});
    group.append(
      svgElement('circle', {
        cx: '91',
        cy: '84',
        fill: 'var(--mq-color-honig-100)',
        r: '12',
        stroke: 'var(--mq-color-honig-700)',
        'stroke-width': '2',
      }),
      svgElement('path', {
        d: 'M91 75l3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1z',
        fill: 'var(--mq-color-funke-500)',
      }),
    );
    return group;
  }
  if (key === 'flamme') {
    return svgElement('path', {
      'data-avatar-accessory': key,
      d: 'M94 91c-9-7-6-17 1-25 0 7 6 8 6 15 4-3 5-6 5-10 7 8 8 20-1 25-5 3-9 1-11-5z',
      fill: 'var(--mq-color-funke-500)',
      stroke: 'var(--mq-color-honig-700)',
      'stroke-width': '2',
    });
  }
  return null;
}

function appearance(value: string | AvatarAppearance | null | undefined): Required<AvatarAppearance> {
  if (value && typeof value === 'object') {
    const parsed = appearance(value.avatarKey || null);
    return {
      accessoryKey: value.accessoryKey || parsed.accessoryKey,
      avatarKey: parsed.avatarKey,
      colorKey: value.colorKey || parsed.colorKey,
    };
  }
  const parts = typeof value === 'string' ? value.split(':') : [];
  const compactAccessory = parts.length === 2
    && ['flamme', 'funkenbadge', 'partyhut'].includes(parts[1])
    ? parts[1]
    : null;
  return {
    accessoryKey: parts[2] || compactAccessory,
    avatarKey: parts[0] || null,
    colorKey: compactAccessory ? null : parts[1] || null,
  };
}

/**
 * Build one of the local “Funken” avatars without injecting SVG markup.
 */
export function createAvatar(
  appearanceValue: string | AvatarAppearance | null | undefined,
): SVGSVGElement {
  const selected = appearance(appearanceValue);
  const key = AVATAR_KEYS.includes(selected.avatarKey as AvatarKey)
    ? selected.avatarKey as AvatarKey
    : 'kiesel';
  const svg = svgElement('svg', {
    'aria-hidden': 'true',
    class: `quizgeist-avatar quizgeist-avatar--${key}`,
    focusable: 'false',
    viewBox: '0 0 120 120',
  });
  svg.dataset.avatarKey = key;
  if (selected.colorKey) {
    svg.dataset.avatarColor = selected.colorKey;
  }
  if (selected.accessoryKey) {
    svg.dataset.avatarAccessory = selected.accessoryKey;
  }
  const faceColor = 'var(--mq-color-ink-900)';
  svg.append(
    body(key, selected.colorKey),
    svgElement('circle', {
      cx: '44',
      cy: '59',
      fill: 'var(--mq-color-honig-200)',
      opacity: '.45',
      r: '8',
    }),
    svgElement('circle', {
      cx: '76',
      cy: '59',
      fill: 'var(--mq-color-honig-200)',
      opacity: '.45',
      r: '8',
    }),
    svgElement('circle', {cx: '44', cy: '52', fill: faceColor, r: '5'}),
    svgElement('circle', {cx: '76', cy: '52', fill: faceColor, r: '5'}),
    svgElement('path', {
      d: 'M47 69Q60 79 73 69',
      fill: 'none',
      stroke: faceColor,
      'stroke-linecap': 'round',
      'stroke-width': '4',
    }),
  );
  const accessoryLayer = accessory(selected.accessoryKey);
  if (accessoryLayer) {
    svg.append(accessoryLayer);
  }
  return svg;
}
