const scopeUtilities = require('./scripts/postcss/scope-utilities');

module.exports = {
  plugins: [
    require('@tailwindcss/postcss'),
    // Confine each app's Tailwind build to its roots: no global utilities on shared admin screens.
    scopeUtilities({
      files: {
        'admin/styles/admin.css': ['#proto-blocks-admin', '#proto-blocks-admin-portal'],
        'fields/box/styles.css': ['.proto-blocks-fields', '#proto-blocks-fields-portal'],
      },
    }),
  ],
};
