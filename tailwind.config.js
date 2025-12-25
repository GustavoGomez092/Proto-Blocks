/** @type {import('tailwindcss').Config} */
module.exports = {
  prefix: 'pb-',
  important: '.proto-blocks-admin-ui',
  content: [
    './includes/Admin/**/*.php',
    './includes/Tailwind/AdminSettings.php',
    './src/admin/**/*.{ts,tsx,js,jsx}',
  ],
  theme: {
    extend: {
      colors: {
        primary: '#002A5C',
        'primary-hover': '#001a3a',
        secondary: '#4ECDC4',
        'secondary-alt': '#00635D',
        accent: '#FF6B6B',
        'background-light': '#F3F4F6',
        'surface-light': '#FFFFFF',
        'border-light': '#E5E7EB',
        'text-main-light': '#111827',
        'text-muted-light': '#6B7280',
      },
      fontFamily: {
        display: ['Inter', 'sans-serif'],
        mono: [
          'ui-monospace',
          'SFMono-Regular',
          'Menlo',
          'Monaco',
          'Consolas',
          'Liberation Mono',
          'Courier New',
          'monospace',
        ],
      },
      borderRadius: {
        DEFAULT: '0.5rem',
      },
    },
  },
  plugins: [],
};
