/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './src/**/*.{ts,tsx}',
    '../templates/**/*.mustache',
    '../classes/**/*.php',
    '../*.php',
  ],
  important: '[data-quizgeist-root]',
  corePlugins: {
    container: false,
    preflight: false,
  },
  theme: {
    extend: {
      colors: {
        'mq-canvas': 'var(--mq-color-canvas)',
        'mq-surface': 'var(--mq-color-surface)',
        'mq-ink': 'var(--mq-color-ink-900)',
        'mq-muted': 'var(--mq-color-ink-700)',
        'mq-border': 'var(--mq-color-border)',
        'mq-funke': 'var(--mq-color-funke-600)',
        'mq-tiefsee': 'var(--mq-color-tiefsee-600)',
        'mq-honig': 'var(--mq-color-honig-600)',
        'mq-beere': 'var(--mq-color-beere-600)',
      },
      fontFamily: {
        body: ['var(--mq-font-body)'],
        heading: ['var(--mq-font-heading)'],
        playful: ['var(--mq-font-playful)'],
      },
      boxShadow: {
        'mq-card': 'var(--mq-shadow-md)',
      },
    },
  },
  plugins: [],
};
