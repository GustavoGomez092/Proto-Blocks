module.exports = {
  plugins: [
    require('@tailwindcss/postcss'),
    // Runs after Tailwind: scopes the admin utilities to the app root and
    // namespaces their keyframes (see the plugin header).
    require('./scripts/postcss/scope-admin-utilities'),
  ],
};
